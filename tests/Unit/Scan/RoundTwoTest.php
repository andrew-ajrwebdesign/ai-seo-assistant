<?php
/**
 * Tests for the 2026-10-06 round two: opportunity v3 (quick win, top-3 prize, intent, enquiry estimate,
 * tiers), page types from AJR Core in place of the AISA role (with the migration), the Google listing group
 * with pins, "What Google reads", the business_profile_check hand-over to AJR Core, and the support card.
 *
 * AJR Core's classes are stood in with eval() in separate processes: the contract is their names.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Scan;

use AJR\SEOAssistant\Admin\Ui;
use AJR\SEOAssistant\Report\Snapshot_Store;
use AJR\SEOAssistant\Scan\Google_Reads;
use AJR\SEOAssistant\Scan\Intent;
use AJR\SEOAssistant\Scan\Listing;
use AJR\SEOAssistant\Scan\Opportunity;
use AJR\SEOAssistant\Scan\Page_Role;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * Round two.
 */
class RoundTwoTest extends TestCase {
	use Wp_Basics;

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * WP basics and an option store.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_basics();
		$this->options = [];
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => array_key_exists( $n, $this->options ) ? $this->options[ $n ] : $d );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $n, $v ) {
				$this->options[ $n ] = $v;
				return true;
			}
		);
		Opportunity::use_curve( null );
	}

	/**
	 * Intent rules: the longest phrase wins, a tie goes to the higher intent, the brand is navigational.
	 */
	public function test_intent_rules(): void {
		$rules = Intent::GENERIC;
		$brand = [ 'Example Realty', 'Jane Example' ];
		$this->assertSame( 'lead', Intent::by_rules( 'boise realtor near me', $rules, $brand ) );
		$this->assertSame( 'commercial', Intent::by_rules( 'best neighborhoods boise', $rules, $brand ) );
		$this->assertSame( 'informational', Intent::by_rules( 'boise idaho weather', $rules, $brand ) );
		$this->assertSame( 'informational', Intent::by_rules( 'cost of living boise', $rules, $brand ), '"cost of living" beats "cost"' );
		$this->assertSame( 'navigational', Intent::by_rules( 'jane example boise', $rules, $brand ) );
		$this->assertSame( '', Intent::by_rules( 'boise idaho', $rules, $brand ), 'no rule: left for Claude' );
		$this->assertSame( '', Intent::by_rules( 'bestow gifts', $rules, $brand ), 'whole words only' );
		$this->assertSame( 3.0, Intent::weight( 'lead' ) );
		$this->assertSame( 0.0, Intent::weight( 'navigational' ), 'looking for a business by name: nothing to win' );
		$this->assertSame( 'navigational', Intent::by_rules( 'zillow boise', array_merge( $rules, [ 'navigational' => Intent::competitors( 'RealEstateAgent' ) ] ), $brand ), 'a competitor wins over everything' );
		$this->assertSame( 'navigational', Intent::by_rules( 'cityhall.gov boise minutes', $rules, $brand ), 'a web address' );
		$this->assertSame( '', Intent::by_rules( 'elementary near me', $rules, $brand ), 'no bare "near me" lead' );
		$this->assertSame( 1.0, Intent::weight( 'unknown' ), 'unsorted counts as learning, never upwards' );
	}

	/**
	 * The Claude pass gets only what the rules and the cache leave, most impressions first, once each.
	 */
	public function test_intent_pending(): void {
		$queries = [
			[ 'query' => 'boise idaho', 'impressions' => 40 ],
			[ 'query' => 'boise weather', 'impressions' => 900 ],
			[ 'query' => 'Eagle Idaho', 'impressions' => 300 ],
			[ 'query' => 'eagle idaho', 'impressions' => 10 ],
			[ 'query' => 'meridian', 'impressions' => 5 ],
		];
		$todo    = Intent::pending( $queries, Intent::GENERIC, [], [ 'meridian' => 'informational' ] );
		$this->assertSame( [ 'eagle idaho', 'boise idaho' ], $todo );
		$schema = Intent::schema();
		$this->assertSame( array_keys( Intent::WEIGHTS ), $schema['properties']['items']['items']['properties']['intent']['enum'] );
	}

	/**
	 * Quick win and top-3 prize, per search, a year; intent-weighted totals and the mix.
	 */
	public function test_quick_win_and_prize(): void {
		$gsc   = [
			'impressions' => 3000,
			'clicks'      => 10,
			'position'    => 8.0,
			'queries'     => [
				[ 'query' => 'boise realtor', 'impressions' => 1000, 'clicks' => 10, 'position' => 4.0 ],
				[ 'query' => 'boise weather', 'impressions' => 2000, 'clicks' => 0, 'position' => 2.0 ],
			],
		];
		$split = Opportunity::breakdown( $gsc, static fn( $q ) => false !== strpos( $q, 'realtor' ) ? 'lead' : 'informational' );
		$by    = array_column( $split['rows'], null, 'query' );
		$this->assertEqualsWithDelta( 1000 * ( 6.3 - 1.0 ) / 100, $by['boise realtor']['missed'], 0.001 );
		$this->assertEqualsWithDelta( 1000 * ( 7.2 - 1.0 ) / 100, $by['boise realtor']['prize'], 0.001, 'at position 3: 7.2%' );
		$this->assertSame( 0.0, $by['boise weather']['prize'], 'already above position 3: no prize' );
		$this->assertEqualsWithDelta( 53.0 * 3 + 300.0 * 1, $split['weighted'], 0.01 );
		$this->assertSame( [ 'informational' => 0.667, 'lead' => 0.333 ], $split['mix'] );
		$this->assertSame( 'boise weather', $split['rows'][0]['query'], 'largest weighted quick win first' );
		$this->assertEqualsWithDelta( 365.0, Opportunity::yearly( 90.0 ), 0.0001 );

		// Searches Google does not name take the named ones' weight, and stay out of the mix.
		$gsc['impressions'] = 4000;
		$split              = Opportunity::breakdown( $gsc, static fn( $q ) => false !== strpos( $q, 'realtor' ) ? 'lead' : 'informational' );
		$rest               = array_values( array_filter( $split['rows'], static fn( $r ) => $r['remain'] ) )[0];
		$this->assertEqualsWithDelta( 0.25 * ( 3 * 1000 + 1 * 2000 ) / 3000, $rest['weight'], 0.0001, 'the named mix × 0.25' );
		$all_lead = Opportunity::breakdown( $gsc, static fn( $q ) => 'lead' );
		$rest     = array_values( array_filter( $all_lead['rows'], static fn( $r ) => $r['remain'] ) )[0];
		$this->assertEqualsWithDelta( 0.25 * 2.0, $rest['weight'], 0.0001, 'never above "comparing"' );
		$this->assertSame( 1.0, Opportunity::feasibility( 9.0 ) );
		$this->assertSame( 0.5, Opportunity::feasibility( 15.0 ) );
		$this->assertSame( 0.2, Opportunity::feasibility( 25.0 ) );
		$this->assertSame( 0.05, Opportunity::feasibility( 45.0 ) );
		$far = Opportunity::breakdown(
			[
				'impressions' => 1000,
				'clicks'      => 0,
				'position'    => 45.0,
				'queries'     => [ [ 'query' => 'x', 'impressions' => 1000, 'clicks' => 0, 'position' => 45.0 ] ],
			]
		);
		$this->assertEqualsWithDelta( 1000 * 7.2 / 100 * 0.05, $far['prize'], 0.0001, 'a search on page 5 is a long shot' );
		$this->assertFalse( Opportunity::earns_bump( 1, 10, 20, 2000 ), 'one enquiry is not a rate' );
		$this->assertTrue( Opportunity::earns_bump( 2, 20, 20, 2000 ) );
		$this->assertSame( 'unnamed', $rest['intent'] );
		$this->assertSame( [ 'informational' => 0.667, 'lead' => 0.333 ], $split['mix'] );
	}

	/**
	 * Enquiry estimate: page rate from 30 visits, else the site's; omitted under 10 site enquiries.
	 */
	public function test_enquiry_estimate(): void {
		$this->assertNull( Opportunity::enquiries( 400.0, 2, 100, 9, 1000 ), 'under 10 tracked enquiries: omitted, never 0' );
		$this->assertEqualsWithDelta( 8.0, Opportunity::enquiries( 400.0, 2, 100, 12, 1000 ), 0.0001, 'page rate 2%' );
		$this->assertEqualsWithDelta( 4.8, Opportunity::enquiries( 400.0, 1, 20, 12, 1000 ), 0.0001, 'thin page: site rate 1.2%' );
	}

	/**
	 * Tiers scale with the site: High = the fewest top pages holding half the total, Medium = the next
	 * quarter, Low = the rest with a value, None = no value; floors keep a trivial gain from reading High.
	 */
	public function test_tiers_scale_with_the_site(): void {
		$values = [
			'a' => 400.0,
			'b' => 100.0,
			'c' => 100.0,
			'd' => 100.0,
			'e' => 50.0,
			'f' => 50.0,
			'g' => 0.0,
		];
		// Total 800: 'a' alone reaches half; b and c (to 600 = 75%) are the next quarter; the rest are Low.
		$this->assertSame(
			[
				'a' => 'high',
				'b' => 'medium',
				'c' => 'medium',
				'd' => 'low',
				'e' => 'low',
				'f' => 'low',
				'g' => 'none',
			],
			Opportunity::tiers( $values )
		);

		// Many small pages: the top set still holds half, but floors stop "High" for a few visits a year.
		$small = [
			'p' => 20.0,
			'q' => 10.0,
			'r' => 6.0,
			's' => 4.0,
		];
		$this->assertSame(
			[
				'p' => 'medium',
				'q' => 'medium',
				'r' => 'low',
				's' => 'low',
			],
			Opportunity::tiers( $small ),
			'p is in the top half but under 24: Medium; r would be Medium but is under 8: Low'
		);

		\WP_Mock::onFilter( 'ai_seo_assistant_opportunity_floors' )->with( Opportunity::FLOORS )->reply(
			[
				'high'   => 1,
				'medium' => 1,
			]
		);
		$this->assertSame( 'high', Opportunity::tiers( $small )['p'], 'floors are filterable' );
		$this->assertSame( [ 'x' => 'none' ], Opportunity::tiers( [ 'x' => 0.0 ] ) );
		$this->assertEqualsWithDelta( 365.0 * 1.5, Opportunity::value_of( 90.0, 1.5, 0 ), 0.001, 'a year × the page type’s value' );
	}

	/**
	 * The agency user's ranking mode: their saved choice, else Quick wins.
	 */
	public function test_ranking_mode(): void {
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		$meta = 'prize';
		\WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			function ( $id, $key ) use ( &$meta ) {
				return 3 === $id && \AJR\SEOAssistant\Scan\Ranking::MODE_META === $key ? $meta : '';
			}
		);
		$this->assertSame( 'prize', \AJR\SEOAssistant\Scan\Ranking::mode() );
		$meta = 'everything';
		$this->assertSame( 'quick', \AJR\SEOAssistant\Scan\Ranking::mode(), 'anything else: the default' );
	}

	/**
	 * The main search: real traffic, not a business's name, a learning search only when on topic; and the
	 * title check ignores stopwords and simple endings.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_main_query_and_title_match(): void {
		$page = [
			'gsc' => [
				'impressions' => 1000,
				'queries'     => [
					[ 'query' => 'zillow idaho', 'clicks' => 9, 'impressions' => 400 ],
					[ 'query' => 'boise weather', 'clicks' => 5, 'impressions' => 300 ],
					[ 'query' => 'boise plumber', 'clicks' => 4, 'impressions' => 100 ],
					[ 'query' => 'tiny search', 'clicks' => 1, 'impressions' => 10 ],
				],
			],
		];
		\WP_Mock::userFunction( 'wp_specialchars_decode' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'get_bloginfo' )->andReturn( 'Northfield Plumbing' );
		\WP_Mock::userFunction( 'home_url' )->andReturn( 'https://northfieldplumbing.example' );
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test double.
		\WP_Mock::onFilter( 'ai_seo_assistant_competitors' )->with( Intent::COMPETITORS[''], '' )->reply( array_merge( Intent::COMPETITORS[''], [ 'zillow' ] ) );
		$this->assertSame( 'boise plumber', \AJR\SEOAssistant\Scan\Scanner::main_query( $page, 'Emergency Plumber in Boise plumbing' ), 'a competitor and an off-topic learning search skipped' );
		$this->assertSame( 'boise weather', \AJR\SEOAssistant\Scan\Scanner::main_query( $page, 'Boise Area Weather' ), 'on topic: kept' );
		$this->assertSame( '', \AJR\SEOAssistant\Scan\Scanner::main_query( [ 'gsc' => [ 'impressions' => 1000, 'queries' => [ [ 'query' => 'tiny search', 'clicks' => 1, 'impressions' => 10 ] ] ] ] ), 'no search with real traffic' );

		$this->assertTrue( \AJR\SEOAssistant\Scan\Rules::contains_query( 'Water Heater Installation in Northfield', 'water heaters installed near me' ) === false, '"installed" is not "installation"' );
		$this->assertTrue( \AJR\SEOAssistant\Scan\Rules::contains_query( 'Water Heaters | Northfield', 'water heater in northfield' ), 'plural and stopword' );
		$this->assertTrue( \AJR\SEOAssistant\Scan\Rules::contains_query( 'Home Selling Guide for Boise', 'boise home sellings' ) );
	}

	/**
	 * Spend: a call lost after sending counts its worst case; two calls add up (the total is read fresh under
	 * a lock, never from this request's stale copy).
	 */
	public function test_spend_counts_lost_calls_and_adds_atomically(): void {
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
		\WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );
		\WP_Mock::userFunction( 'add_option' )->andReturnUsing(
			function ( $n, $v ) {
				if ( array_key_exists( $n, $this->options ) ) {
					return false;
				}
				$this->options[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $n ) {
				unset( $this->options[ $n ] );
				return true;
			}
		);
		$lost = \AJR\SEOAssistant\AI\Spend::record_unknown( 'intent', 'claude-haiku-4-5' );
		$this->assertEqualsWithDelta( \AJR\SEOAssistant\AI\Spend::reserve( 'intent', 'claude-haiku-4-5' ), $lost, 1e-9 );
		\AJR\SEOAssistant\AI\Spend::record(
			'claude-haiku-4-5',
			[
				'input_tokens'  => 1000000,
				'output_tokens' => 0,
			]
		);
		$state = $this->options[ \AJR\SEOAssistant\AI\Spend::OPTION ];
		$this->assertEqualsWithDelta( $lost + 1.0, $state['usd'], 1e-6 );
		$this->assertSame( 2, $state['calls'] );
		$this->assertArrayNotHasKey( \AJR\SEOAssistant\AI\Spend::LOCK_OPTION, $this->options, 'the lock is released' );
	}

	/**
	 * The intent pass: batches of at most 150 sized from the measured output per item; partial answers kept;
	 * a batch whose request never came back (the "started" marker is still there) is not sent again.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_intent_pass_batches(): void {
		$this->assertSame( 150, Intent::batch_size( [] ) );
		$this->assertSame( 60, Intent::batch_size( [ 'tokens_per_item' => 80 ] ), '60% of 8,000 tokens at 80 a search' );

		$this->options[ \AJR\SEOAssistant\Search\Page_Data::META_OPTION ] = [
			'start'        => '2026-07-06',
			'end'          => '2026-10-04',
			'generated_at' => 1,
			'count'        => 1,
		];
		$queries = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$queries[] = [ 'query' => 'zzq' . $i, 'clicks' => 0, 'impressions' => 200 - $i ];
		}
		global $wpdb;
		$wpdb = new class( $queries ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.
			/**
			 * Prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Page rows.
			 *
			 * @var array<int,array<string,string>>
			 */
			public $rows;

			/**
			 * Build.
			 *
			 * @param array<int,array<string,mixed>> $q Queries.
			 */
			public function __construct( $q ) {
				$this->rows = [
					[
						'path' => '/a/',
						'data' => json_encode( [ 'gsc' => [ 'queries' => $q ] ] ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.
					],
				];
			}

			/**
			 * Rows.
			 */
			public function get_results() {
				return $this->rows;
			}
		};
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
		\WP_Mock::userFunction( 'wp_specialchars_decode' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'get_bloginfo' )->andReturn( 'Example Realty' );
		\WP_Mock::userFunction( 'home_url' )->andReturn( 'https://examplerealty.example' );
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test double.
		\WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( fn( $v ) => $v instanceof \WP_Error );
		$client = new class() extends \AJR\SEOAssistant\AI\Claude_Client {
			/**
			 * Batches seen.
			 *
			 * @var array<int,int>
			 */
			public $batches = [];

			/**
			 * Has a key.
			 */
			public function has_api_key() {
				return true;
			}

			/**
			 * Answer every search as informational.
			 *
			 * @param string              $prompt Prompt.
			 * @param array<string,mixed> $schema Schema.
			 * @param string              $task   Task.
			 * @param array<int,mixed>    $images Images.
			 */
			public function generate_json( $prompt, array $schema, $task = 'metadata', array $images = [] ) {
				preg_match_all( '/^zzq\d+$/m', $prompt, $m );
				$this->batches[]  = count( $m[0] );
				$this->last_usage = [ 'output_tokens' => 20 * count( $m[0] ) ];
				return [ 'items' => array_map( static fn( $q ) => [ 'q' => $q, 'intent' => 'informational' ], $m[0] ) ];
			}
		};
		$first = Intent::run_pass( $client );
		$this->assertSame( 'working', $first['state'] );
		$this->assertSame( 150, $first['sent'] );
		$this->assertCount( 150, $this->options[ Intent::CACHE_OPTION ], 'the first batch is kept before the next is sent' );
		$second = Intent::run_pass( $client );
		$this->assertSame( 'done', $second['state'] );
		$this->assertSame( [ 150, 50 ], $client->batches );
		$this->assertSame( 'skipped', Intent::run_pass( $client )['state'], 'done for this push' );

		// A batch that never came back: the marker is there; nothing is sent again for this push.
		$this->options[ Intent::DONE_OPTION ]['started_at'] = time();
		$this->options[ Intent::CACHE_OPTION ]              = [];
		$this->assertSame( 'interrupted', Intent::run_pass( $client )['state'] );
		$this->assertSame( [ 150, 50 ], $client->batches, 'not re-billed' );
	}

	/**
	 * Golden set: 60 real estate-agent searches labelled by hand; rules first, then the cached Haiku
	 * answers for what the rules leave, must agree on at least 90%.
	 */
	public function test_intent_golden_set(): void {
		$golden = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/intent-golden.json' ), true );
		$rules  = Intent::GENERIC;
		foreach ( Intent::BY_TYPE[ $golden['business_type'] ] as $intent => $phrases ) {
			$rules[ $intent ] = array_merge( $rules[ $intent ] ?? [], $phrases );
		}
		$rules['navigational'] = Intent::competitors( $golden['business_type'] );
		$right                 = 0;
		$misses                = [];
		foreach ( $golden['items'] as $item ) {
			$got = Intent::by_rules( $item['q'], $rules, $golden['brand'] );
			$got = '' !== $got ? $got : ( $item['cached'] ?? 'unknown' );
			if ( $got === $item['expect'] ) {
				++$right;
			} else {
				$misses[] = $item['q'] . ' → ' . $got . ' (expected ' . $item['expect'] . ')';
			}
		}
		$accuracy = $right / count( $golden['items'] );
		fwrite( STDERR, sprintf( "\nIntent golden set: %d of %d right (%.0f%%)%s\n", $right, count( $golden['items'] ), 100 * $accuracy, [] === $misses ? '' : ': ' . implode( '; ', $misses ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- test output.
		$this->assertGreaterThanOrEqual( 0.9, $accuracy, implode( '; ', $misses ) );
	}

	/**
	 * Estate agent intent (Andrew, round 3): researching a move is commercial; acting is a lead.
	 */
	public function test_estate_agent_intent(): void {
		$rules = Intent::GENERIC;
		foreach ( Intent::BY_TYPE['RealEstateAgent'] as $intent => $phrases ) {
			$rules[ $intent ] = array_merge( $rules[ $intent ] ?? [], $phrases );
		}
		foreach ( [ 'moving to boise', 'relocating to boise idaho', 'boise relocation', 'idaho real estate', 'living in eagle idaho', 'cost of living boise' ] as $q ) {
			$this->assertSame( 'commercial', Intent::by_rules( $q, $rules ), $q );
		}
		foreach ( [ 'boise realtor', 'best real estate agent in boise', 'sell my house boise', 'selling your home in boise', 'homes for sale meridian', "what's my home worth", 'home valuation boise', 'list my home', "buyer's agent boise", 'mortgage broker' ] as $q ) {
			$this->assertSame( 'lead', Intent::by_rules( $q, $rules ), $q );
		}
		$this->assertSame( [ 'eagle idaho' => 'informational' ], Intent::prune( [ 'eagle idaho' => 'informational', 'moving to boise' => 'lead' ], $rules, [] ), 'a cached answer the rules now decide is dropped' );
	}

	/**
	 * Page type → role, and the default for a page without one (a post listing is information).
	 */
	public function test_page_type_roles(): void {
		$this->assertSame( 'money', Page_Role::role_of_type( 'service' ) );
		$this->assertSame( 'money', Page_Role::role_of_type( 'contact' ) );
		$this->assertSame( 'location', Page_Role::role_of_type( 'area' ) );
		$this->assertSame( 'info', Page_Role::role_of_type( 'faq' ) );
		$this->assertSame( 'info', Page_Role::role_of_type( 'team_member' ) );
		$this->assertSame( 'unclassified', Page_Role::role_of_type( 'other' ) );
		$this->assertSame( '', Page_Role::role_of_type( '' ) );
		$this->assertSame( 'info', Page_Role::default_role( 'page', true, true ), 'Blog and Beyond: a post listing in the menu is information' );
		$this->assertTrue( Page_Role::is_listing( '[et_pb_section][et_pb_blog posts_number="9"][/et_pb_section]' ) );
		$this->assertTrue( Page_Role::is_listing( '<!-- wp:query {"queryId":1} -->' ) );
		$this->assertFalse( Page_Role::is_listing( '[et_pb_text]Blog tips[/et_pb_text]' ) );
		$this->assertFalse( Page_Role::core(), 'no AJR Core 0.22 here: the controls stay hidden' );
		$this->assertSame( '', Page_Role::type_of( 5 ) );
		$this->assertFalse( Page_Role::set_type( 5, 'service' ) );
	}

	/**
	 * With AJR Core's Page_Types: the old roles move into page types (money noted, left unset) and go.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_migrate_roles_into_page_types(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test double for AJR Core 0.22's contract.
		eval( 'namespace AJR\Core\Schema; class Page_Types { public static $t = [ 30 => "faq" ]; public static function types(): array { return [ "service" => [ "Service page", "the service", "" ], "area" => [ "Area page", "the area", "" ], "article" => [ "Article", "headline", "" ], "faq" => [ "FAQ", "q and a", "" ] ]; } public static function get( int $id ): string { return self::$t[ $id ] ?? ""; } public static function set( int $id, string $type ): bool { if ( 50 === $id ) { return false; } self::$t[ $id ] = $type; return true; } public static function suggest( int $id ): string { return "service"; } }' );
		global $wpdb;
		$wpdb = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.
			/**
			 * Postmeta table.
			 *
			 * @var string
			 */
			public $postmeta = 'wp_postmeta';

			/**
			 * Prepare passthrough.
			 *
			 * @param string $q Query.
			 */
			public function prepare( $q ) {
				return $q;
			}

			/**
			 * The old role rows.
			 */
			public function get_results() {
				return [
					[ 'post_id' => 10, 'meta_value' => 'location' ],
					[ 'post_id' => 20, 'meta_value' => 'money' ],
					[ 'post_id' => 30, 'meta_value' => 'info' ],
					[ 'post_id' => 40, 'meta_value' => 'unclassified' ],
					[ 'post_id' => 50, 'meta_value' => 'location' ],
				];
			}
		};
		$deleted = [];
		\WP_Mock::userFunction( 'delete_post_meta' )->andReturnUsing(
			function ( $id ) use ( &$deleted ) {
				$deleted[] = $id;
				return true;
			}
		);
		$this->assertTrue( Page_Role::core() );
		$out = Page_Role::migrate();
		$this->assertSame(
			[
				'moved'   => 1,
				'noted'   => 1,
				'dropped' => 2,
				'failed'  => 1,
			],
			$out
		);
		$this->assertSame( 'area', \AJR\Core\Schema\Page_Types::get( 10 ) );
		$this->assertSame( 'faq', \AJR\Core\Schema\Page_Types::get( 30 ), 'a page type already set is kept' );
		$this->assertSame( '', \AJR\Core\Schema\Page_Types::get( 20 ), 'money: service or contact is the agency’s call' );
		$this->assertSame( [ 20 ], $this->options[ Page_Role::NOTES_OPTION ] );
		$this->assertSame( [ 10, 20, 30, 40 ], $deleted, 'old role meta deleted once dealt with; 50 (AJR Core refused the type) kept for a retry' );
		$this->assertSame( 'Service page', Page_Role::types()['service']['label'] );
		$this->assertSame( 'service', Page_Role::suggest( 1 ) );
	}

	/**
	 * Google listing group: differences counted, pinned ones kept on purpose and not counted; not checked
	 * never reads as a match.
	 */
	public function test_listing_group_and_pins(): void {
		$listing = [
			'checked'    => true,
			'checked_at' => 1791200000,
			'fields'     => [
				[ 'field' => 'name', 'status' => 'match', 'severity' => 'info', 'site' => 'A', 'google' => 'A', 'message' => '', 'detail' => '', 'fix' => null ],
				[ 'field' => 'hours', 'status' => 'mismatch', 'severity' => 'medium', 'site' => '24h', 'google' => '8-6', 'message' => 'Hours differ', 'detail' => '', 'fix' => null ],
				[ 'field' => 'phone', 'status' => 'mismatch', 'severity' => 'high', 'site' => '1', 'google' => '2', 'message' => 'Phone differs', 'detail' => '', 'fix' => null ],
				[ 'field' => 'rating', 'status' => 'missing_on_site', 'severity' => 'info', 'site' => '', 'google' => '4.8', 'message' => '', 'detail' => '', 'fix' => null ],
			],
		];
		$group   = Listing::group( $listing, [ 'phone' => [ 'reason' => 'Old landline; change requested' ] ] );
		$this->assertSame( 'checked', $group['state'] );
		$this->assertSame( [ 'hours' ], array_column( $group['issues'], 'field' ) );
		$this->assertSame( [ 'phone' ], array_column( $group['kept'], 'field' ) );
		$this->assertSame( 'Old landline; change requested', $group['kept'][0]['pin']['reason'] );
		$this->assertSame( 'not_checked', Listing::group( [ 'checked' => false, 'fields' => [] ], [] )['state'] );
		$this->assertSame( 'none', Listing::group( null, [] )['state'] );
		$this->assertSame( [], Listing::pins(), 'no AJR Core 0.22: no pins' );
	}

	/**
	 * "Fix in Business details" opens the Business details row of AJR Core's essentials screen: Core's own
	 * URL when it has one, else the essentials screen with the row's anchor. Never a screen of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_business_details_url(): void {
		\WP_Mock::userFunction( 'admin_url' )->andReturnUsing( fn( $p = '' ) => 'https://x.test/wp-admin/' . $p );
		$this->assertSame( 'https://x.test/wp-admin/admin.php?page=ajr-core#business-details', Listing::core_url() );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test double for AJR Core's contract.
		eval( 'namespace AJR\Core\Admin; class Settings { public static function business_details_url(): string { return "https://x.test/wp-admin/admin.php?page=ajr-core&open=business"; } }' );
		$this->assertSame( 'https://x.test/wp-admin/admin.php?page=ajr-core&open=business', Listing::core_url() );
	}

	/**
	 * Contract 3: the stored check goes back to AJR Core in the snapshot schema's own shape.
	 */
	public function test_listing_block_for_core(): void {
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		$this->assertNull( Snapshot_Store::listing_block() );
		$this->options[ Snapshot_Store::LISTING ] = [
			'checked'    => true,
			'checked_at' => 1791184300,
			'reason'     => '',
			'maps_url'   => 'https://maps.google.com/?cid=1',
			'page'       => 'https://example.com/',
			'place_id'   => 'ChIJabc',
			'google'     => [ 'name' => 'A' ],
			'fields'     => [
				[ 'field' => 'hours', 'status' => 'mismatch', 'severity' => 'medium', 'site' => '24h', 'google' => '', 'message' => 'm', 'detail' => 'd', 'fix' => [ 'where' => 'site', 'site' => 'AJR Core', 'google' => '' ] ],
				[ 'field' => 'name', 'status' => 'match', 'severity' => 'info', 'site' => 'A', 'google' => 'A', 'message' => '', 'detail' => '', 'fix' => null ],
			],
			'problems'   => 1,
		];
		$block                                    = Snapshot_Store::listing_block();
		$this->assertSame( 1, $block['version'] );
		$this->assertSame( '2026-10-05T07:11:40.000Z', $block['checked_at'] );
		$this->assertSame( 1, $block['summary']['problems'] );
		$this->assertSame( 'medium', $block['summary']['worst'] );
		$this->assertSame( 1, $block['summary']['match'] );
		$this->assertNull( $block['fields'][0]['google'], 'empty values are null, as in the schema' );
		$this->assertNull( $block['fields'][0]['fix']['google'] );
		$this->assertSame( 'https://maps.google.com/?cid=1', $block['maps_url'] );

		$this->options[ Snapshot_Store::LISTING ] = [
			'checked'    => false,
			'checked_at' => 0,
			'reason'     => 'Places lookup failed',
			'fields'     => [],
		];
		$block                                    = Snapshot_Store::listing_block();
		$this->assertFalse( $block['checked'] );
		$this->assertNull( $block['summary'], 'not checked: summary null' );
		$this->assertSame( [], $block['fields'] );
		$this->assertArrayNotHasKey( 'maps_url', $block );
		$this->assertSame( 'Places lookup failed', $block['reason'] );
	}

	/**
	 * "What Google reads": plain words, what the page type adds, the business link, the test link.
	 */
	public function test_google_reads(): void {
		$nodes = [
			[ 'type' => 'RealEstateAgent', 'name' => 'Welcome to Boise and Beyond', 'id' => 'https://x/#organization' ],
			[ 'type' => 'BreadcrumbList', 'name' => '', 'id' => '' ],
			[ 'type' => 'WebPage', 'name' => 'Real Estate', 'id' => '' ],
			[ 'type' => 'ImageObject', 'name' => '', 'id' => '' ],
			[ 'type' => 'ListItem', 'name' => 'Home', 'id' => '' ],
			[ 'type' => 'PostalAddress', 'name' => '', 'id' => '' ],
			[ 'type' => 'Service', 'name' => 'Relocation', 'id' => '' ],
		];
		$this->assertSame( [ 'Business', 'Breadcrumb', 'Web page', 'Service: Relocation' ], Google_Reads::chips( $nodes ) );
		$this->assertSame( '', Google_Reads::missing( 'service', $nodes ) );
		$this->assertSame( 'Questions and answers', Google_Reads::missing( 'faq', $nodes ) );
		$this->assertSame( '', Google_Reads::missing( 'other', $nodes ) );
		$this->assertTrue( Google_Reads::has_business( $nodes ) );
		$this->assertFalse( Google_Reads::has_business( [ $nodes[1] ] ) );
		$this->assertSame( 'https://search.google.com/test/rich-results?url=https%3A%2F%2Fx.test%2Fa%2F', Google_Reads::rich_results_url( 'https://x.test/a/' ) );
		$this->assertSame( 'Local Business Thing', Google_Reads::words( 'LocalBusinessThing' ) );
	}

	/**
	 * Without AJR Core 0.22 the layout is full width with no card; with it, the card sits in the side column.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_support_card_layout(): void {
		$this->assertSame( '', Ui::support_card() );
		$this->assertSame( '<div class="aisa-layout"><div class="aisa-layout__main">', Ui::layout_open() );
		$this->assertSame( '</div></div>', Ui::layout_close() );
		$this->assertSame( '', Ui::layout_close(), 'closes once' );

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test double for AJR Core 0.22's contract.
		eval( 'namespace AJR\Core\Admin; class Support { public static function render_card(): void { echo "<section class=\"ajr-support\">Need a hand?</section>"; } }' );
		$this->assertStringContainsString( 'aisa-layout--card', Ui::layout_open() );
		$this->assertStringContainsString( '<aside class="aisa-layout__side" aria-label="Help"><section class="ajr-support">Need a hand?</section></aside>', Ui::layout_close() );
	}
}
