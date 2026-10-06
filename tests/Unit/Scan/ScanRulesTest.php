<?php
/**
 * Tests for the SEO scan's pure parts: title width, the HTML reader, the best-practice rules and the
 * opportunity score.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Scan;

use AJR\SEOAssistant\Scan\Html_Parser;
use AJR\SEOAssistant\Scan\Opportunity;
use AJR\SEOAssistant\Scan\Rules;
use AJR\SEOAssistant\Scan\Title_Width;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * The scan's rules, read off fixtures.
 */
class ScanRulesTest extends TestCase {
	use Wp_Basics;

	/**
	 * WP basics.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_basics();
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $hook, $value ) => $value );
	}

	/**
	 * Codes of the issues for some facts and context.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @param array<string,mixed> $ctx   Context.
	 * @return array<int,string>
	 */
	protected function codes( array $facts, array $ctx = [] ): array {
		return array_column( Rules::evaluate( $facts + self::clean_page(), $ctx + [ 'inbound' => 3 ] ), 'code' );
	}

	/**
	 * A page with no issues.
	 *
	 * @return array<string,mixed>
	 */
	protected static function clean_page(): array {
		return [
			'title'       => 'Water Heater Installation Northfield | Tank & Tankless',
			'description' => 'Tank or tankless water heater installed by licensed Northfield plumbers, usually within the week. Upfront prices and the old unit taken away.',
			'h1'          => [ 'Water heater installation in Northfield' ],
			'headings'    => [
				[
					'l' => 1,
					't' => 'Water heater installation',
				],
				[
					'l' => 2,
					't' => 'Tankless',
				],
			],
			'images'      => [
				[
					'src'  => '/a.jpg',
					'file' => 'a.jpg',
					'alt'  => 'Plumber fitting a tankless heater in a utility room',
					'id'   => 5,
				],
			],
			'links'       => [
				[
					'p' => '/boilers/',
					't' => 'boilers',
				],
				[
					'p' => '/contact/',
					't' => 'contact',
				],
			],
			'words'       => 640,
			'schema'      => [ 'WebPage' ],
			'og_image'    => 'https://x.test/a.jpg',
			'noindex'     => false,
			'canonical'   => '',
		];
	}

	/**
	 * Width in px: the mockup's long title is cut, the suggested one fits; wide letters cost more.
	 */
	public function test_title_width_in_pixels(): void {
		$long  = 'Water Heaters | Northfield Plumbing & Heating – Tank & Tankless Water Heater Installation Services';
		$short = 'Water Heater Installation Northfield | Tank & Tankless';
		$this->assertTrue( Title_Width::too_wide( $long ) );
		$this->assertFalse( Title_Width::too_wide( $short ) );
		$this->assertGreaterThan( Title_Width::px( str_repeat( 'i', 20 ) ), Title_Width::px( str_repeat( 'W', 20 ) ) );
		$this->assertSame( 0, Title_Width::px( '' ) );
		$this->assertStringEndsWith( "\u{2026}", Title_Width::visible( $long ) );
		$this->assertLessThanOrEqual( Title_Width::LIMIT_PX, Title_Width::px( Title_Width::visible( $long ) ) );
	}

	/**
	 * A clean page has no issues; each rule fires on its own fault.
	 */
	public function test_rules_fire_on_their_fault(): void {
		$this->assertSame( [], $this->codes( [] ) );
		$this->assertContains( 'title_missing', $this->codes( [ 'title' => '' ] ) );
		$this->assertContains( 'title_wide', $this->codes( [ 'title' => str_repeat( 'Water Heater Installation ', 4 ) ] ) );
		$this->assertContains( 'title_duplicate', $this->codes( [], [ 'title_dupes' => 2 ] ) );
		$this->assertContains( 'title_no_query', $this->codes( [], [ 'top_query' => 'boiler repair northfield' ] ) );
		$this->assertNotContains( 'title_no_query', $this->codes( [], [ 'top_query' => 'water heaters northfield' ] ), 'plural matches singular' );
		$this->assertContains( 'desc_missing', $this->codes( [ 'description' => '' ] ) );
		$this->assertContains( 'desc_long', $this->codes( [ 'description' => str_repeat( 'Long words here. ', 12 ) ] ) );
		$this->assertContains( 'desc_short', $this->codes( [ 'description' => 'Too short.' ] ) );
		$this->assertContains( 'desc_duplicate', $this->codes( [], [ 'desc_dupes' => 1 ] ) );
		$this->assertContains( 'h1_missing', $this->codes( [ 'h1' => [] ] ) );
		$this->assertContains( 'h1_many', $this->codes( [ 'h1' => [ 'A', 'B' ] ] ) );
		$this->assertContains(
			'h_order',
			$this->codes(
				[
					'headings' => [
						[
							'l' => 1,
							't' => 'A',
						],
						[
							'l' => 3,
							't' => 'B',
						],
					],
				]
			)
		);
		$this->assertContains( 'orphan', $this->codes( [], [ 'inbound' => 0 ] ) );
		$this->assertNotContains( 'orphan', $this->codes( [], [ 'inbound' => 0, 'is_front' => true ] ), 'the home page is never an orphan' );
		$this->assertContains( 'few_out', $this->codes( [ 'links' => [] ] ) );
		$this->assertContains( 'broken', $this->codes( [], [ 'broken' => [ '/gone/' ] ] ) );
		$this->assertContains( 'via_redirect', $this->codes( [], [ 'redirected' => [ [ '/old/', '/new/' ] ] ] ) );
		$this->assertContains( 'thin', $this->codes( [ 'words' => 120 ] ) );
		$this->assertNotContains( 'thin', $this->codes( [ 'words' => 120 ], [ 'is_utility' => true ] ) );
		$this->assertContains( 'og_image', $this->codes( [ 'og_image' => '' ] ) );
		$this->assertContains( 'schema_none', $this->codes( [ 'schema' => [] ] ) );
		$this->assertContains( 'schema_article', $this->codes( [], [ 'post_type' => 'post' ] ) );
		$this->assertContains( 'heavy', $this->codes( [], [ 'heavy' => [ [ 'big.jpg', 900 ] ] ] ) );
	}

	/**
	 * Alt text: missing and one-word alts are Claude's; a good Media Library alt the page does not print is
	 * an editor finding, never a rewrite.
	 */
	public function test_alt_rules(): void {
		$img = static fn( $alt, $stored = '' ) => [
			'images' => [
				[
					'src'        => '/van.jpg',
					'file'       => 'team-van.jpg',
					'alt'        => $alt,
					'id'         => 9,
					'stored_alt' => $stored,
				],
			],
		];
		$this->assertContains( 'alt', $this->codes( $img( null ) ) );
		$this->assertContains( 'alt', $this->codes( $img( 'van' ) ), 'one short word is weak' );
		$this->assertContains( 'alt', $this->codes( $img( 'team van' ) ), 'the file name is weak' );
		$this->assertContains( 'alt_unprinted', $this->codes( $img( '', 'Northfield Plumbing van outside a customer’s home' ) ) );
		$this->assertNotContains( 'alt', $this->codes( $img( '', 'Northfield Plumbing van outside a customer’s home' ) ) );
		$this->assertTrue( Rules::weak_alt( 'IMG_2041', 'IMG_2041.jpg' ) );
		$this->assertFalse( Rules::weak_alt( 'Old water heater removed from a basement', 'x.jpg' ) );
		$issue = Rules::evaluate( $img( null ) + self::clean_page(), [ 'inbound' => 3 ] );
		$this->assertSame( 'claude', $issue[0]['who'] );
	}

	/**
	 * Indexing: noindex in the sitemap is a conflict, except on a site that discourages search engines.
	 */
	public function test_indexing_rules(): void {
		$this->assertContains( 'noindex_in_sitemap', $this->codes( [ 'noindex' => true ], [ 'in_sitemap' => true ] ) );
		$this->assertSame( [], array_intersect( [ 'noindex_in_sitemap' ], $this->codes( [ 'noindex' => true ], [ 'in_sitemap' => true, 'discouraged' => true ] ) ) );
		$this->assertContains( 'not_in_sitemap', $this->codes( [], [ 'in_sitemap' => false ] ) );
		$this->assertContains(
			'canonical_elsewhere',
			$this->codes(
				[ 'canonical' => 'https://x.test/other/' ],
				[
					'in_sitemap' => true,
					'self_url'   => 'https://x.test/page/',
				]
			)
		);
		$this->assertNotContains( 'canonical_elsewhere', $this->codes( [ 'canonical' => 'http://www.x.test/page' ], [ 'in_sitemap' => true, 'self_url' => 'https://x.test/page/' ] ) );
	}

	/**
	 * Service schema advice names AJR Core's Custom schema module as on only when it is (correction 4).
	 */
	public function test_service_schema_advice_depends_on_custom_schema(): void {
		$on  = Rules::evaluate( self::clean_page(), [ 'inbound' => 3, 'is_service' => true, 'custom_schema_on' => true ] );
		$off = Rules::evaluate( self::clean_page(), [ 'inbound' => 3, 'is_service' => true, 'custom_schema_on' => false ] );
		$this->assertSame( 'Fix: add a Service entry in AJR Core › Schema.', $on[0]['fix'] );
		$this->assertSame( 'Fix: turn on Custom schema in AJR Core, then add a Service entry.', $off[0]['fix'] );
		$this->assertSame( [], Rules::evaluate( array_merge( self::clean_page(), [ 'schema' => [ 'Service' ] ] ), [ 'inbound' => 3, 'is_service' => true ] ) );
	}

	/**
	 * The HTML reader: whole-document facts, content-area headings/images/links, chrome left out.
	 */
	public function test_html_parser(): void {
		$html  = '<!doctype html><html lang="en"><head><title>Boilers &amp; Heating | Northfield</title>'
			. '<meta name="description" content="Boiler repair."><meta name="robots" content="noindex, follow">'
			. '<link rel="canonical" href="https://x.test/boilers/"><meta property="og:image" content="https://x.test/o.jpg">'
			. '<script type="application/ld+json">{"@graph":[{"@type":"WebPage"},{"@type":["LocalBusiness","Plumber"]}]}</script></head><body>'
			. '<header><nav><a href="/menu-link/">Menu</a></nav></header><main><h1>Boiler<br>repair</h1><h2>Why</h2>'
			. '<p>Words one two three <a href="/contact/">contact us</a> <a href="https://other.example/">x</a> <a href="tel:123">call</a></p>'
			. '<img src="https://x.test/wp-content/uploads/b.jpg" class="wp-image-42" alt="A boiler"><img src="https://x.test/pixel.gif" width="1" height="1">'
			. '<img src="data:image/gif;base64,R0" data-src="https://x.test/lazy.jpg"></main><footer><a href="/footer/">f</a></footer></body></html>';
		$facts = Html_Parser::parse( $html, 'https://x.test/' );
		$this->assertSame( 'Boilers & Heating | Northfield', $facts['title'] );
		$this->assertTrue( $facts['noindex'] );
		$this->assertSame( 'https://x.test/boilers/', $facts['canonical'] );
		$this->assertSame( [ 'WebPage', 'LocalBusiness', 'Plumber' ], $facts['schema'] );
		$this->assertSame( [ 'Boiler repair' ], $facts['h1'], 'a <br> inside a heading reads as a space' );
		$this->assertSame( [ '/contact/' ], array_column( $facts['links'], 'p' ), 'menu and footer links are not the page’s own' );
		$this->assertSame( 1, $facts['external'] );
		$this->assertCount( 2, $facts['images'], 'tracking pixel dropped, lazy image kept' );
		$this->assertSame( 42, $facts['images'][0]['id'] );
		$this->assertNull( $facts['images'][1]['alt'], 'a missing alt attribute is null, not ""' );
	}

	/**
	 * Expected CTR curve and the score: missed clicks weighted by enquiries, top page 100.
	 */
	public function test_opportunity_score(): void {
		$this->assertSame( 28.0, Opportunity::expected_ctr( 1.0 ) );
		$this->assertSame( 0.1, Opportunity::expected_ctr( 80.0 ) );
		$this->assertEqualsWithDelta( 4.7, Opportunity::expected_ctr( 5.5 ), 0.01, 'linear between points' );
		$this->assertSame( 0.0, Opportunity::missed_clicks( 0, null, 5.0 ) );
		$this->assertSame( 0.0, Opportunity::missed_clicks( 1000, 20.0, 5.0 ), 'never below zero' );
		$missed = Opportunity::missed_clicks( 7310, 0.6, 8.9 );
		$this->assertEqualsWithDelta( 7310 * ( Opportunity::expected_ctr( 8.9 ) - 0.6 ) / 100, $missed, 0.001 );
		$this->assertGreaterThan( Opportunity::weighted( 100.0, 0 ), Opportunity::weighted( 100.0, 3 ), 'enquiries lift a page' );
		$scores = Opportunity::scores(
			[
				'a' => 200.0,
				'b' => 100.0,
				'c' => 0.0,
			]
		);
		$this->assertSame(
			[
				'a' => 100,
				'b' => 50,
				'c' => 0,
			],
			$scores
		);
	}
}
