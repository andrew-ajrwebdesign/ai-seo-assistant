<?php
/**
 * Tests for the final 5.0 review round: one queued run per save, own-site fetches that never follow a
 * redirect, password-protected pages kept out, an honest sitemap and link cache.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Scan;

use AJR\SEOAssistant\Scan\Page_Fetcher;
use AJR\SEOAssistant\Scan\Page_Role;
use AJR\SEOAssistant\Search\Page_Data;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Scan\Scanner;
use AJR\SEOAssistant\Scan\Scheduler;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * Final round.
 */
class FinalRoundTest extends TestCase {
	use Wp_Basics;

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Transients written.
	 *
	 * @var array<string,mixed>
	 */
	protected array $transients = [];

	/**
	 * WP basics, an option store, this site's address.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_basics();
		$this->options    = [];
		$this->transients = [];
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => array_key_exists( $n, $this->options ) ? $this->options[ $n ] : $d );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $n, $v ) {
				$this->options[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'get_transient' )->andReturnUsing( fn( $n ) => $this->transients[ $n ] ?? false );
		\WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			function ( $n, $v ) {
				$this->transients[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'home_url' )->andReturnUsing( fn( $p = '' ) => 'https://site.test' . $p );
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) );
		\WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( fn( $k ) => strtolower( (string) $k ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( fn( $r ) => $r['code'] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( fn( $r ) => $r['body'] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( 'text/html' );
	}

	/**
	 * A WP_Post stand-in (separate process).
	 */
	protected function post_class(): void {
		if ( ! class_exists( '\WP_Post' ) ) {
			eval( 'class WP_Post { public $ID = 0; public $post_type = "page"; public $post_status = "publish"; public $post_password = ""; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a test stand-in for core's class.
		}
	}

	/**
	 * Item 8: saves queue ONE run (no per-post event, so ten saves are not ten site-wide passes); a save
	 * while a run is queued joins it and the site-wide pass runs again after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_saves_join_one_queued_run(): void {
		$this->post_class();
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		$scheduled = [];
		$next      = false;
		\WP_Mock::userFunction( 'wp_next_scheduled' )->andReturnUsing( function () use ( &$next ) {
			return $next;
		} );
		\WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturnUsing(
			function ( $t, $hook ) use ( &$scheduled, &$next ) {
				$scheduled[] = $hook;
				$next        = $t;
				return true;
			}
		);
		foreach ( [ 11, 12, 13, 12 ] as $id ) {
			$post     = new \WP_Post();
			$post->ID = $id;
			Scheduler::on_transition( 'publish', 'publish', $post );
		}
		$this->assertSame( [ Scheduler::RUN_HOOK ], $scheduled, 'Four saves schedule one run, never a per-post event.' );
		$queue = Scheduler::queue();
		$this->assertSame( [ 11, 12, 13 ], $queue['ids'] );
		$this->assertSame( 3, $queue['total'] );

		// A save after the site-wide pass already ran: the pass runs again.
		$queue['finalized']                = true;
		$this->options[ Scheduler::QUEUE ] = $queue;
		$post                              = new \WP_Post();
		$post->ID                          = 14;
		Scheduler::on_transition( 'publish', 'publish', $post );
		$this->assertArrayNotHasKey( 'finalized', Scheduler::queue() );
	}

	/**
	 * Last round A8: a step that finds its run cancelled (by another request, after this one read the
	 * queue) writes nothing back and says "cancelled".
	 */
	public function test_cancelled_run_is_never_written_back(): void {
		\WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		$queue                             = [ 'ids' => [ 5, 6 ], 'total' => 2, 'done' => 0, 'mode' => 'full', 'started' => 1, 'run' => 'scan-abc' ];
		$this->options[ Scheduler::QUEUE ] = $queue;
		$GLOBALS['wpdb'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.
			/** @var string */
			public $options = 'wp_options';
			/** @var string */
			public $flag = 'scan-abc';
			public function prepare( $q, ...$a ) {
				return $q;
			}
			public function get_var( $q ) {
				return $this->flag; // Cancel wrote this run's id after this request read the queue.
			}
		};
		$r = Scheduler::step( 30 );
		$this->assertSame( 'cancelled', $r['state'] );
		$this->assertSame( $queue, $this->options[ Scheduler::QUEUE ], 'the queue is not written back (no page taken, nothing in flight)' );

		$GLOBALS['wpdb']->flag = 'scan-other'; // An earlier run's cancel does not stop this one.
		$write                 = new \ReflectionMethod( Scheduler::class, 'write' );
		$write->setAccessible( true );
		$this->assertTrue( $write->invoke( null, [ 'done' => 1 ] + $queue ) );
		$this->assertSame( 1, $this->options[ Scheduler::QUEUE ]['done'] );
	}

	/**
	 * Last round A13: a content row's summary is written when it is logged; the Changes screen reads the
	 * whole page only for a row from before that, once, and keeps the summary.
	 */
	public function test_content_summary_without_reading_the_page(): void {
		$log = new class() extends \AJR\SEOAssistant\Changes\Change_Log {
			/** @var int */
			public $gets = 0;
			/** @var array<int,string> */
			public $notes = [];
			public function get( int $id ): ?array {
				++$this->gets;
				return [ 'before_value' => '<img alt="">', 'after_value' => '<img alt="A white kitchen">' ];
			}
			public function save_note( int $id, string $note ): bool {
				$this->notes[ $id ] = $note;
				return true;
			}
		};
		$this->assertSame( 'kept note', \AJR\SEOAssistant\Admin\Changes_Page::content_row_summary( $log, 7, 'kept note' ) );
		$this->assertSame( 0, $log->gets, 'the page is not read when the note is there' );
		$this->assertSame( '1 alt attribute written into the page: “A white kitchen”', \AJR\SEOAssistant\Admin\Changes_Page::content_row_summary( $log, 8 ) );
		$this->assertSame( 1, $log->gets );
		$this->assertSame( [ 8 => '1 alt attribute written into the page: “A white kitchen”' ], $log->notes, 'kept for next time' );
	}

	/**
	 * Item 11: own-site fetches never follow a redirect (a 301 is reported as itself, not followed off-site).
	 */
	public function test_own_fetch_never_follows_redirects(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $h, $v ) => $v );
		$args = [];
		\WP_Mock::userFunction( 'wp_remote_request' )->andReturnUsing(
			function ( $url, $a ) use ( &$args ) {
				$args = $a;
				return [
					'code' => 301,
					'body' => '',
				];
			}
		);
		$got = Page_Fetcher::fetch( 'https://site.test/old/', 'HEAD' );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 301, $got['status'] );
		$this->assertSame( [ 'ok' => false, 'status' => 0, 'html' => '', 'error' => 'not this site' ], Page_Fetcher::fetch( 'https://elsewhere.test/' ) );
	}

	/**
	 * Item 12: a password-protected page is never scanned; its row is removed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_password_protected_page_is_not_scanned(): void {
		$this->post_class();
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $h, $v ) => $v );
		$post                = new \WP_Post();
		$post->ID            = 7;
		$post->post_password = 'secret';
		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_remote_request' )->never();
		$store = \Mockery::mock( Scan_Store::class );
		$store->shouldReceive( 'delete' )->once()->with( 7 );
		$this->assertSame( 'skipped', ( new Scanner( $store ) )->scan_page( 7 ) );
	}

	/**
	 * Item 24: a child sitemap that fails to load makes the list "unknown" (null, not cached), never a short
	 * list that would call every page in it "not in the sitemap".
	 */
	public function test_sitemap_child_failure_is_unknown(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $h, $v ) => $v );
		$fail = true;
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
			function ( $url ) use ( &$fail ) {
				if ( false !== strpos( $url, 'sitemap_index' ) ) {
					return [
						'code' => 200,
						'body' => '<sitemapindex><loc>https://site.test/page-sitemap.xml</loc><loc>https://site.test/post-sitemap.xml</loc></sitemapindex>',
					];
				}
				if ( false !== strpos( $url, 'post-sitemap' ) && $fail ) {
					return [
						'code' => 500,
						'body' => '',
					];
				}
				return [
					'code' => 200,
					'body' => '<urlset><loc>https://site.test/' . ( false !== strpos( $url, 'post' ) ? 'a-post' : 'a-page' ) . '/</loc></urlset>',
				];
			}
		);
		$scanner = new class() extends Scanner {
			/**
			 * No store needed.
			 */
			public function __construct() {}

			/**
			 * The sitemap, exposed.
			 *
			 * @return array<string,true>|null
			 */
			public function read_sitemap(): ?array {
				return $this->sitemap();
			}
		};
		$this->assertNull( $scanner->read_sitemap() );
		$this->assertArrayNotHasKey( Scanner::SITEMAP_CACHE, $this->transients, 'An incomplete sitemap is not cached.' );

		$fail = false;
		$this->assertSame(
			[
				'site.test/a-page' => true,
				'site.test/a-post' => true,
			],
			$scanner->read_sitemap()
		);
		$this->assertArrayHasKey( Scanner::SITEMAP_CACHE, $this->transients );
	}

	/**
	 * Item 22: every option the plugin reads or writes is removed on uninstall, except the 4.x redirects
	 * options, which go with their table (kept while it holds someone's live redirects).
	 */
	public function test_uninstall_removes_every_option(): void {
		$root      = dirname( __DIR__, 3 );
		$uninstall = (string) file_get_contents( $root . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
		$found     = [];
		$files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) );
		foreach ( $files as $file ) {
			if ( '.php' !== substr( (string) $file, -4 ) ) {
				continue;
			}
			$src = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
			preg_match_all( "/(?:_option\\(\\s*|OPTION\\w*\\s*=\\s*|PENDING\\s*=\\s*|FAILED\\s*=\\s*)'(ai_seo_assistant_[a-z_]+)'/", $src, $m );
			$found = array_merge( $found, $m[1] );
		}
		$kept = [ 'ai_seo_assistant_redirects_db_version', 'ai_seo_assistant_redirect_map', 'ai_seo_assistant_redirects_case_insensitive' ];
		$this->assertGreaterThan( 30, count( array_unique( $found ) ) );
		foreach ( array_diff( array_unique( $found ), $kept ) as $option ) {
			$this->assertStringContainsString( "'" . $option . "'", $uninstall, $option . ' is deleted on uninstall' );
		}
	}

	/**
	 * Item 16: paths keyed lower-case (one wins per case-folded path), none over 191 characters; a failed
	 * insert rolls the whole push back and reports it, leaving the meta untouched.
	 */
	public function test_page_data_keys_and_rollback(): void {
		$keyed = Page_Data::keyed(
			[
				'/About/'                       => [ 'gsc' => [ 'impressions' => 5 ] ],
				'/about/'                       => [ 'gsc' => [ 'impressions' => 50 ] ],
				'/' . str_repeat( 'x', 200 ) => [ 'gsc' => [ 'impressions' => 9 ] ],
			]
		);
		$this->assertSame( [ '/about/' ], array_keys( $keyed ) );
		$this->assertSame( 50, $keyed['/about/']['gsc']['impressions'] );

		$GLOBALS['wpdb'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.
			/** @var string */
			public $prefix = 'wp_';
			/** @var array<int,string> */
			public $queries = [];
			public function prepare( $q, ...$a ) {
				return $q;
			}
			public function query( $q ) {
				$this->queries[] = $q;
				return 0 === strpos( $q, 'INSERT' ) ? false : 1; // The insert fails.
			}
		};
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] = \AJR\SEOAssistant\Core\Schema::VERSION;
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.
		$this->assertFalse( ( new Page_Data() )->replace_all( [ '/a/' => [ 'gsc' => null ] ], [ 'end' => '2026-10-04' ], 1 ) );
		$this->assertSame( 'ROLLBACK', end( $GLOBALS['wpdb']->queries ) );
		$this->assertSame( 'START TRANSACTION', $GLOBALS['wpdb']->queries[0] );
		$this->assertArrayNotHasKey( Page_Data::META_OPTION, $this->options, 'the meta still describes the rows kept' );
	}

	/**
	 * Item 19: Google's limits are checked server-side (title width with what the SEO plugin appends);
	 * the prompt carries the suffix, the agency's phrases and brand rule, siblings and the new alt rules.
	 */
	public function test_review_prompt_and_limits(): void {
		$this->assertSame( [], \AJR\SEOAssistant\Review\Page_Review::listing_problems( 'Water Heater Installation | Tank & Tankless', str_repeat( 'word ', 25 ) ) );
		$wide = \AJR\SEOAssistant\Review\Page_Review::listing_problems( 'Water Heater Installation and Repair in Northfield | Tank & Tankless', 'Short.' );
		$this->assertCount( 2, $wide, 'too wide and too short' );
		$this->assertCount( 1, \AJR\SEOAssistant\Review\Page_Review::listing_problems( 'Water Heater Installation | Tank & Tankless', str_repeat( 'word ', 25 ), ' | Northfield Plumbing and Heating Company' ), 'the appended site name counts' );

		$prompt = ( new \AJR\SEOAssistant\AI\Prompt_Builder() )->build_review_prompt(
			[
				'title_suffix'  => ' | Northfield Plumbing',
				'avoid_phrases' => 'unlock, nestled',
				'siblings'      => [ 'Boilers — /boilers/ (same title)' ],
				'content'       => 'x',
			]
		);
		$this->assertStringContainsString( 'adds " | Northfield Plumbing" to the end of every title', $prompt );
		$this->assertStringContainsString( 'unlock, nestled', $prompt );
		$this->assertStringContainsString( 'Boilers — /boilers/ (same title)', $prompt );
		$this->assertStringContainsString( 'only when you are certain', $prompt );
		$this->assertStringContainsString( 'A logo or an image of text', $prompt );
		$this->assertStringContainsString( 'empty alt, so screen readers do not read the name twice', $prompt );
		$this->assertStringNotContainsString( 'separated by " | "', $prompt, 'no business name when the plugin adds it' );
	}

	/**
	 * Zero-click searches: Google answers them on the results page (weather at position 2.3 with 0.1% CTR),
	 * so they are weighted 0.1, flagged, and kept out of the site's click curve. Words alone never decide.
	 */
	public function test_zero_click_searches(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $h, $v ) => $v );
		$o = \AJR\SEOAssistant\Scan\Opportunity::class;
		$this->assertTrue( $o::zero_click( 'boise idaho weather', 20000, 20, 2.3 ), 'near the top, seen a lot, barely clicked' );
		$this->assertTrue( $o::zero_click( 'boise population 2026', 500, 3, 2.0 ), 'any search that under-clicks that badly at the top' );
		$this->assertFalse( $o::zero_click( 'boise realtor', 20000, 1500, 2.3 ), 'a normal click rate' );
		$this->assertFalse( $o::zero_click( 'boise realtor', 200, 0, 2.3 ), 'too few impressions to judge' );
		$this->assertFalse( $o::zero_click( 'boise realtor', 1000, 30, 2.0 ), '3% at position 2 under-clicks, but above a sixth of 15%' );
		$this->assertTrue( $o::zero_click( 'boise realtor', 1000, 24, 2.0 ), '2.4%: under a sixth of 15%' );
		$this->assertFalse( $o::zero_click( 'boise realtor', 5000, 2, 7.0 ), 'below position 5, without the words: not judged' );
		$this->assertTrue( $o::zero_click( 'weather in eagle idaho', 150, 0, 7.0 ), 'the words, AND under-clicking' );
		$this->assertFalse( $o::zero_click( 'weather in eagle idaho', 150, 10, 7.0 ), 'the words alone never decide' );
		$this->assertFalse( $o::zero_click( 'weatherby homes', 5000, 2, 7.0 ), 'whole words only' );
		$this->assertTrue( $o::zero_click( 'boise area map', 5000, 10, 9.0 ), 'a map search that under-clicks (0.2% at 9)' );
		$this->assertTrue( $o::zero_click( 'directions to eagle idaho', 300, 0, 6.0 ) );
		$this->assertFalse( $o::zero_click( 'boise area map', 5000, 120, 9.0 ), 'a map search clicked normally (2.4% at 9) is not' );

		$b   = $o::breakdown(
			[
				'impressions' => 25000,
				'clicks'      => 520,
				'position'    => 3.0,
				'queries'     => [
					[ 'query' => 'boise idaho weather', 'impressions' => 20000, 'clicks' => 20, 'position' => 2.3 ],
					[ 'query' => 'moving to boise', 'impressions' => 5000, 'clicks' => 500, 'position' => 3.5 ],
				],
			],
			static fn( $q ) => false !== strpos( $q, 'weather' ) ? 'informational' : 'commercial'
		);
		$by  = array_column( $b['rows'], null, 'query' );
		$this->assertTrue( $by['boise idaho weather']['zero_click'] );
		$this->assertSame( 0.1, $by['boise idaho weather']['weight'] );
		$this->assertFalse( $by['moving to boise']['zero_click'] );
		$this->assertLessThan( 0.25 * $b['missed'], $b['weighted'], 'the unwinnable weather clicks hardly count' );
		// The figure SHOWN counts them at the same tenth, and the page is flagged when they are most of it.
		$this->assertEqualsWithDelta( $by['boise idaho weather']['missed'] * 0.1 + $by['moving to boise']['missed'], $b['winnable'], 0.001 );
		$this->assertEqualsWithDelta( $by['boise idaho weather']['missed'] * 0.1, $by['boise idaho weather']['winnable'], 0.001 );
		$this->assertSame( 0.8, $b['zero_share'], '20,000 of 25,000 impressions' );

		// The unnamed remainder takes the named searches' zero-click share: 80% of it counts at a tenth.
		$with_rest = $o::breakdown(
			[
				'impressions' => 35000,
				'clicks'      => 520,
				'position'    => 3.0,
				'queries'     => [
					[ 'query' => 'boise idaho weather', 'impressions' => 20000, 'clicks' => 20, 'position' => 2.3 ],
					[ 'query' => 'moving to boise', 'impressions' => 5000, 'clicks' => 500, 'position' => 3.5 ],
				],
			],
			static fn( $q ) => 'informational'
		);
		$rest = array_values( array_filter( $with_rest['rows'], static fn( $r ) => $r['remain'] ) )[0];
		$this->assertEqualsWithDelta( $rest['missed'] * ( 0.2 + 0.8 * 0.1 ), $rest['winnable'], 0.001 );
		$this->assertSame( 0.8, $with_rest['zero_share'], 'judged on the named searches only' );
	}

	/**
	 * Item 18: the noise fixes in the rules.
	 */
	public function test_scan_noise(): void {
		$page  = [
			'title'       => 'Water Heater Installation Northfield | Tank & Tankless',
			'description' => 'Tank or tankless water heater installed by licensed Northfield plumbers, usually within the week. Upfront prices and the old unit taken away.',
			'h1'          => [ 'Water heater installation' ],
			'headings'    => [ [ 'l' => 1, 't' => 'Water heater installation' ] ],
			'links'       => [ [ 'p' => '/a/' ], [ 'p' => '/b/' ] ],
			'words'       => 120,
			'og_image'    => 'https://x.test/o.jpg',
			'images'      => [],
		];
		$codes = static fn( array $facts, array $ctx = [] ) => array_column( \AJR\SEOAssistant\Scan\Rules::evaluate( $facts + $page, $ctx + [ 'inbound' => 3 ] ), 'code' );
		$img   = static fn( $alt, $stored = '', $extra = [] ) => [ 'images' => [ [ 'file' => 'van.jpg', 'alt' => $alt, 'id' => 9, 'stored_alt' => $stored ] + $extra ] ];

		// alt="" is its own, softer finding; a missing attribute is "no alt"; decoration owes nothing.
		$this->assertContains( 'alt_empty', $codes( $img( '' ) ) );
		$this->assertNotContains( 'alt', $codes( $img( '' ) ) );
		$this->assertContains( 'alt', $codes( $img( null ) ) );
		$this->assertSame( [], array_intersect( [ 'alt', 'alt_empty' ], $codes( $img( null, '', [ 'decorative' => true ] ) ) ) );
		// A good printed alt is never a mismatch, even when the Media Library says something else.
		$this->assertNotContains( 'alt_mismatch', $codes( $img( 'Call our plumbers today', 'A white van parked outside a house' ) ) );
		// A copy-pasted alt printed over good Media Library alts is.
		$pasted = [
			'images' => [
				[ 'file' => 'kitchen.jpg', 'alt' => 'Our Services', 'id' => 1, 'stored_alt' => 'White kitchen with a gas range' ],
				[ 'file' => 'porch.jpg', 'alt' => 'Our Services', 'id' => 2, 'stored_alt' => 'Covered porch with two chairs' ],
			],
		];
		$this->assertContains( 'alt_mismatch', $codes( $pasted ) );

		// Menu and footer links count as links in.
		$this->assertContains( 'orphan', $codes( [], [ 'inbound' => 0 ] ) );
		$this->assertSame( [], array_intersect( [ 'orphan', 'few_in' ], $codes( [], [ 'inbound' => 0, 'in_menu' => true ] ) ) );

		// Short pages: not for contact, team, other, or a form / calculator page; the copy is softer.
		$this->assertContains( 'thin', $codes( [] ) );
		foreach ( [ 'contact', 'team_member', 'other' ] as $type ) {
			$this->assertNotContains( 'thin', $codes( [], [ 'page_type' => $type ] ), $type );
		}
		$this->assertNotContains( 'thin', $codes( [], [ 'is_tool' => true ] ) );

		// More than half the pages: a template finding, reported once.
		$issues = [];
		foreach ( range( 1, 8 ) as $id ) {
			$issues[ $id ] = \AJR\SEOAssistant\Scan\Rules::evaluate( $page, [ 'inbound' => 3 ] + ( $id <= 5 ? [] : [ 'page_type' => 'contact' ] ) );
		}
		$site = \AJR\SEOAssistant\Scan\Rules::site_wide( $issues );
		$this->assertSame( [ 'thin' ], array_keys( $site ), 'thin on 5 of 8 pages' );
		$this->assertSame( 5, $site['thin']['pages'] );
		$this->assertSame( [], \AJR\SEOAssistant\Scan\Rules::site_wide( array_slice( $issues, 0, 5, true ) ), 'too few pages to tell' );
	}

	/**
	 * Last round A4: an image on most pages (a header photo, the logo) is the template's: never a page
	 * finding, one site-wide finding when its alt is missing or weak.
	 */
	public function test_template_images_reported_once(): void {
		$rows = [];
		foreach ( range( 1, 10 ) as $id ) {
			$images = [ [ 'file' => 'site-logo.png', 'alt' => '' ] ];
			if ( $id <= 7 ) {
				$images[] = [ 'file' => 'owner-profile.png', 'alt' => null ];
			}
			$images[]    = [ 'file' => 'page-' . $id . '.jpg', 'alt' => null ];
			$rows[ $id ] = [ 'facts' => [ 'images' => $images ] ];
		}
		$tpl = \AJR\SEOAssistant\Scan\Rules::template_images( $rows );
		$this->assertSame( [ 'site-logo.png', 'owner-profile.png' ], array_keys( $tpl ) );
		$this->assertSame( 7, $tpl['owner-profile.png']['pages'] );
		$issue = \AJR\SEOAssistant\Scan\Rules::template_alt_issue( $tpl );
		$this->assertSame( 'template_alt', $issue['issue']['code'] );
		$this->assertSame( 10, $issue['pages'] );
		$this->assertStringContainsString( 'Theme Builder template', $issue['issue']['fix'] );

		$page  = [ 'images' => $rows[1]['facts']['images'], 'og_image' => 'x', 'words' => 400, 'links' => [ [ 'p' => '/a/' ], [ 'p' => '/b/' ] ], 'title' => 'T', 'description' => str_repeat( 'd ', 50 ), 'h1' => [ 'T' ], 'headings' => [] ];
		$found = \AJR\SEOAssistant\Scan\Rules::evaluate( $page, [ 'inbound' => 3, 'template_files' => array_keys( $tpl ) ] );
		$alt   = array_values( array_filter( $found, static fn( $i ) => 'alt' === $i['code'] ) );
		$this->assertSame( 1, $alt[0]['data']['missing'], 'only the page\'s own photo, not the logo or profile' );
		$this->assertSame( [], \AJR\SEOAssistant\Scan\Rules::template_images( array_slice( $rows, 0, 5, true ) ), 'too few pages to tell' );
		$this->assertNull( \AJR\SEOAssistant\Scan\Rules::template_alt_issue( [ 'logo.png' => [ 'pages' => 9, 'alt' => 'Northfield Plumbing', 'stored' => '', 'deco' => false ] ] ), 'a good alt: nothing to fix' );
	}

	/**
	 * Item 18: link suggestions come from related pages (the town every title shares does not count), and
	 * none when nothing is related.
	 */
	public function test_link_suggestions_are_related(): void {
		\WP_Mock::userFunction( 'trailingslashit' )->andReturnUsing( fn( $s ) => rtrim( (string) $s, '/' ) . '/' );
		$rows    = [];
		$pages   = [
			'/water-heaters/'        => 'Water Heater Installation Northfield',
			'/tankless-water-heater/' => 'Tankless Water Heaters Northfield',
			'/drain-cleaning/'       => 'Drain Cleaning Northfield',
			'/about/'                => 'About Us Northfield',
			'/blog/'                 => 'Blog Northfield',
		];
		foreach ( array_keys( $pages ) as $i => $path ) {
			$rows[ $i + 1 ] = [
				'path'  => $path,
				'facts' => [ 'title' => $pages[ $path ] ],
			];
		}
		$scanner = new class() extends Scanner {
			/**
			 * No store needed.
			 */
			public function __construct() {}

			/**
			 * Suggestions, exposed.
			 *
			 * @param array<int,array<string,mixed>> $rows    Rows.
			 * @param string                         $path    Path.
			 * @param array<int,string>              $popular Popular paths.
			 * @return array<int,string>
			 */
			public function suggest( array $rows, string $path, array $popular ): array {
				return self::related_from( $path, self::topics( $rows ), $popular, [] );
			}
		};
		$popular = [ '/about/', '/blog/', '/drain-cleaning/', '/tankless-water-heater/' ];
		$this->assertSame( [ '/tankless-water-heater/' ], $scanner->suggest( $rows, '/water-heaters/', $popular ) );
		$this->assertSame( [], $scanner->suggest( $rows, '/about/', [ '/blog/', '/drain-cleaning/' ] ), 'nothing related: no suggestion' );
	}

	/**
	 * Item 18: the content area is the entry content; sidebar, author box and related posts are not the
	 * page's; header, menu and footer links are kept apart as links in.
	 */
	public function test_parser_content_area(): void {
		$html  = '<html><head><title>T</title></head><body><header><nav><a href="/services/">S</a></nav></header>'
			. '<div id="main-content"><article><h1>Post</h1><div class="entry-content"><h2>Real</h2><p>Body <a href="/one/">one</a></p><img src="https://x.test/a.jpg" alt=""><img src="https://x.test/b.jpg" role="presentation"></div>'
			. '<div class="author-box"><h4>About the author</h4><img src="https://x.test/me.jpg"></div><div class="related-posts"><h3>Related</h3><a href="/two/">two</a></div></article>'
			. '<div id="sidebar"><h4>Recent</h4><a href="/three/">three</a></div></div><footer><a href="/privacy/">P</a></footer></body></html>';
		$facts = \AJR\SEOAssistant\Scan\Html_Parser::parse( $html, 'https://x.test/' );
		$this->assertSame( [ 'Post' ], $facts['h1'], 'the theme H1 still counts' );
		$this->assertSame( [ 1, 2 ], array_column( $facts['headings'], 'l' ), 'no H4/H3 from the author box or sidebar' );
		$this->assertSame( [ '/one/' ], array_column( $facts['links'], 'p' ) );
		$this->assertSame( [ '', null ], array_column( $facts['images'], 'alt' ) );
		$this->assertTrue( $facts['images'][1]['decorative'] );
		$this->assertSame( [ '/services/', '/privacy/' ], $facts['nav_links'] );
	}

	/**
	 * Item 17: an oversized push is refused on rest_pre_dispatch (before WordPress decodes its JSON);
	 * other routes and normal-sized pushes pass through untouched.
	 */
	public function test_oversize_push_refused_before_dispatch(): void {
		\WP_Mock::userFunction( 'untrailingslashit' )->andReturnUsing( fn( $s ) => rtrim( (string) $s, '/' ) );
		$endpoint = new \AJR\SEOAssistant\Report\Push_Endpoint( new \AJR\SEOAssistant\Report\Snapshot_Store() );
		$big      = new \WP_REST_Request( 'POST', '/ai-seo-assistant/v1/report' );
		$big->set_header( 'Content-Length', (string) ( \AJR\SEOAssistant\Report\Snapshot::MAX_BYTES_V2 + 1 ) );
		$got = $endpoint->refuse_oversize( null, null, $big );
		$this->assertInstanceOf( \WP_Error::class, $got );
		$this->assertSame( 'aisa_report_too_large', $got->get_error_code() );

		$mixed = new \WP_REST_Request( 'POST', '/AI-SEO-Assistant/v1/Report/' );
		$mixed->set_header( 'Content-Length', (string) ( \AJR\SEOAssistant\Report\Snapshot::MAX_BYTES_V2 + 1 ) );
		$this->assertSame( 413, $endpoint->refuse_oversize( null, null, $mixed )->data['status'], 'the route in another case is still this route' );

		$other = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$other->set_header( 'Content-Length', (string) ( \AJR\SEOAssistant\Report\Snapshot::MAX_BYTES_V2 + 1 ) );
		$this->assertNull( $endpoint->refuse_oversize( null, null, $other ), 'another route is not ours to judge' );

		$small = new \WP_REST_Request( 'POST', '/ai-seo-assistant/v1/report' );
		$small->set_body( '{}' );
		$this->assertNull( $endpoint->refuse_oversize( null, null, $small ) );
	}

	/**
	 * Item 15: each tools AJAX action checks its own nonce, and the screen hands out one per action.
	 */
	public function test_separate_nonce_per_ajax_action(): void {
		$root  = dirname( __DIR__, 3 );
		$tools = (string) file_get_contents( $root . '/src/Admin/Tools_Actions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
		$this->assertStringNotContainsString( 'Ui::NONCE', $tools );
		$this->assertSame( 0, preg_match( '/this->guard_ajax\(\s*\)/', $tools ), 'every guard names its action' );
		$this->assertSame( 5, preg_match_all( "/guard_ajax\\( (?:'aisa_[a-z_]+'|self::ROLE) \\)/", $tools ) );
		$this->assertSame( \AJR\SEOAssistant\Admin\Tools_Actions::ROLE, \AJR\SEOAssistant\Admin\Ui::AJAX_ACTIONS[3] );
		// Each handler checks the nonce of its OWN action, and the guard checks the action it is given.
		foreach ( [ 'ajax_scan_start' => "'aisa_scan_start'", 'ajax_scan_step' => "'aisa_scan_step'", 'ajax_scan_cancel' => "'aisa_scan_cancel'", 'ajax_generate' => "'aisa_generate'", 'ajax_set_role' => 'self::ROLE' ] as $method => $action ) {
			$this->assertMatchesRegularExpression( '/function ' . $method . '\(\): void \{\s+\$this->guard_ajax\( ' . preg_quote( $action, '/' ) . ' \);/', $tools, $method );
		}
		$this->assertStringContainsString( "check_ajax_referer( \$action, 'nonce' );", $tools );
		$js = (string) file_get_contents( $root . '/assets/js/aisa-tools.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
		$this->assertStringContainsString( '( cfg.nonces || {} )[ action ]', $js );
	}

	/**
	 * Item 23: admin-ajax builds the admin stack only for this plugin's own actions.
	 */
	public function test_admin_stack_only_for_own_ajax(): void {
		$ajax = true;
		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturnUsing( function () use ( &$ajax ) {
			return $ajax;
		} );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnArg( 0 );
		$_REQUEST['action'] = 'heartbeat';
		$this->assertFalse( \AJR\SEOAssistant\Core\Plugin::admin_stack_needed() );
		$_REQUEST['action'] = 'aisa_scan_step';
		$this->assertTrue( \AJR\SEOAssistant\Core\Plugin::admin_stack_needed() );
		$_REQUEST['action'] = 'ai_seo_assistant_generate';
		$this->assertTrue( \AJR\SEOAssistant\Core\Plugin::admin_stack_needed() );
		$ajax = false;
		$_REQUEST['action'] = 'heartbeat';
		$this->assertTrue( \AJR\SEOAssistant\Core\Plugin::admin_stack_needed(), 'screens and admin-post always' );
		unset( $_REQUEST['action'] );
	}

	/**
	 * Item 23: role flags are worked out from the content at scan time.
	 */
	public function test_role_flags(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $h, $v ) => $v );
		$this->assertSame( 'none', Page_Role::flags( '<p>Hello</p>' ) );
		$this->assertSame( 'form', Page_Role::flags( '[contact-form-7 id="1"]' ) );
		$this->assertSame( 'listing', Page_Role::flags( '<!-- wp:query {"queryId":1} -->' ) );
	}

	/**
	 * Item 23: the link cache is written only when something new was checked, so its day keeps counting
	 * from when an answer was learned.
	 */
	public function test_link_cache_not_rewritten_without_new_checks(): void {
		$this->transients[ Scanner::LINK_CACHE ] = [ '/gone/' => 404 ];
		$writes                                  = 0;
		\WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			function () use ( &$writes ) {
				++$writes;
				return true;
			}
		);
		$scanner = new class() extends Scanner {
			/**
			 * No store needed.
			 */
			public function __construct() {}

			/**
			 * Link checks, exposed.
			 *
			 * @param array<int,string> $targets Paths.
			 * @param array<string,int> $known   Known pages.
			 * @return array<string,int>
			 */
			public function links( array $targets, array $known ): array {
				return $this->check_unknown_links( $targets, $known, [] );
			}
		};
		$this->assertSame( [ '/gone/' => 404 ], $scanner->links( [ '/gone/', '/about/', '/' ], [ '/about/' => 3 ] ) );
		$this->assertSame( 0, $writes );
	}
}
