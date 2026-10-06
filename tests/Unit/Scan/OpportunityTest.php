<?php
/**
 * Tests for the opportunity score's four 2026-10-06 changes: per-search missed clicks, the displayed number,
 * the site's own click curve, and business value by page role. Pure PHP.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Scan;

use AJR\SEOAssistant\Scan\Opportunity;
use AJR\SEOAssistant\Scan\Page_Role;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * Opportunity v2.
 */
class OpportunityTest extends TestCase {
	use Wp_Basics;

	/**
	 * The filter passes values through unless a test says otherwise.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_basics();
		Opportunity::use_curve( null ); // WP_Mock's own apply_filters passes values through unless onFilter() says otherwise.
	}

	/**
	 * Back to the built-in curve.
	 */
	public function tearDown(): void {
		Opportunity::use_curve( null );
		parent::tearDown();
	}

	/**
	 * A Search Console block with searches.
	 *
	 * @param array<int,array<string,mixed>> $queries Searches.
	 * @param int                            $shown   Page impressions.
	 * @param int                            $clicks  Page clicks.
	 * @param float                          $pos     Page average position.
	 * @return array<string,mixed>
	 */
	protected function gsc( array $queries, int $shown, int $clicks, float $pos ): array {
		return [
			'impressions' => $shown,
			'clicks'      => $clicks,
			'ctr'         => $shown > 0 ? round( 100 * $clicks / $shown, 1 ) : null,
			'position'    => $pos,
			'queries'     => $queries,
		];
	}

	/**
	 * Per search: each search at its own position, the remainder at the page's average.
	 */
	public function test_per_query_missed_clicks(): void {
		$gsc   = $this->gsc(
			[
				[ 'query' => 'boise realtor', 'impressions' => 1000, 'clicks' => 10, 'ctr' => 1.0, 'position' => 4.0 ],
				[ 'query' => 'idaho homes', 'impressions' => 2000, 'clicks' => 0, 'ctr' => 0.0, 'position' => 40.0 ],
			],
			4000,
			12,
			9.0
		);
		$split = Opportunity::breakdown( $gsc );
		$this->assertSame( 'query', $split['method'] );

		$by = [];
		foreach ( $split['rows'] as $row ) {
			$by[ $row['remain'] ? '(rest)' : $row['query'] ] = $row;
		}
		$this->assertEqualsWithDelta( 1000 * ( 6.3 - 1.0 ) / 100, $by['boise realtor']['missed'], 0.001, 'position 4: expected 6.3%' );
		$this->assertSame( 0.0, $by['idaho homes']['missed'], 'a search at position 40 adds nothing' );
		$this->assertSame( 1000, $by['(rest)']['impressions'], 'remainder = page − searches' );
		$this->assertSame( 2, $by['(rest)']['clicks'] );
		$this->assertEqualsWithDelta( 1000 * ( 2.3 - 0.2 ) / 100, $by['(rest)']['missed'], 0.001, 'remainder at the page average (9.0)' );
		$this->assertEqualsWithDelta( 53.0 + 21.0, $split['missed'], 0.001 );
		$this->assertSame( 'boise realtor', $split['rows'][0]['query'], 'largest first' );

		// The page-level formula would have counted the position-40 search's impressions at position 9.
		$this->assertGreaterThan( $split['missed'], Opportunity::missed_clicks( 4000, null, 9.0, 12 ) );
	}

	/**
	 * Reach: full to 20, nothing from 30, straight line between.
	 */
	public function test_reach_tapers_past_twenty(): void {
		$this->assertSame( 1.0, Opportunity::reach( 3.0 ) );
		$this->assertSame( 1.0, Opportunity::reach( 20.0 ) );
		$this->assertEqualsWithDelta( 0.5, Opportunity::reach( 25.0 ), 0.0001 );
		$this->assertSame( 0.0, Opportunity::reach( 31.0 ) );
	}

	/**
	 * Without searches (snapshot v1 data) it is today's page-level formula; no data is none.
	 */
	public function test_falls_back_to_page_level(): void {
		$split = Opportunity::breakdown( $this->gsc( [], 7310, 44, 8.9 ) );
		$this->assertSame( 'page', $split['method'] );
		$this->assertEqualsWithDelta( Opportunity::missed_clicks( 7310, null, 8.9, 44 ), $split['missed'], 0.001, 'actual CTR from clicks, not the rounded figure' );
		$this->assertSame( 'none', Opportunity::breakdown( null )['method'] );
		$this->assertSame( 0.0, Opportunity::breakdown( null )['missed'] );
	}

	/**
	 * Remainder never negative when the searches add up to more than the page (Search Console rounding).
	 */
	public function test_remainder_never_negative(): void {
		$split = Opportunity::breakdown(
			$this->gsc( [ [ 'query' => 'a', 'impressions' => 500, 'clicks' => 5, 'position' => 2.0 ] ], 480, 5, 2.0 )
		);
		$this->assertCount( 1, $split['rows'], 'no remainder row' );
	}

	/**
	 * Displayed number: "< 5" (null) for tiny, then rounded sensibly.
	 */
	public function test_rounded_extra_clicks(): void {
		$this->assertNull( Opportunity::rounded( 4.9 ) );
		$this->assertSame( 7, Opportunity::rounded( 7.4 ) );
		$this->assertSame( 40, Opportunity::rounded( 41.2 ) );
		$this->assertSame( 350, Opportunity::rounded( 347.0 ) );
		$this->assertSame( 2300, Opportunity::rounded( 2349.0 ) );
	}

	/**
	 * Site curve: a bucket with ≥ 1,000 impressions uses the site's CTR; a thin one keeps the built-in value.
	 */
	public function test_site_curve_buckets(): void {
		$queries = [
			[ 'impressions' => 800, 'clicks' => 80, 'position' => 1.2 ],
			[ 'impressions' => 400, 'clicks' => 40, 'position' => 0.9 ], // Rounds to 1.
			[ 'impressions' => 500, 'clicks' => 5, 'position' => 2.0 ],  // Bucket 2: 500 < 1,000, built-in.
			[ 'impressions' => 3000, 'clicks' => 30, 'position' => 12.4 ], // Bucket 11–15.
			[ 'impressions' => 999, 'clicks' => 0, 'position' => 60.0 ],  // Past 50: ignored.
		];
		$out = Opportunity::site_curve( $queries );
		$this->assertTrue( $out['site'] );
		$this->assertSame( 2, $out['buckets'] );
		$this->assertSame( 4200, $out['searches'] );
		$this->assertSame( 10.0, $out['curve'][1], 'bucket 1: 120 ÷ 1,200' );
		$this->assertSame( 5.36, $out['curve'][2], 'thin bucket 2: built-in 15% × (10 ÷ 28) from the nearest calibrated bucket (1)' );
		$this->assertSame( 1.0, $out['curve'][13], 'bucket 11–15: 30 ÷ 3,000' );
		$this->assertLessThanOrEqual( $out['curve'][40], $out['curve'][50] );

		$values = array_values( $out['curve'] );
		$count  = count( $values );
		for ( $i = 1; $i < $count; $i++ ) {
			$this->assertLessThanOrEqual( $values[ $i - 1 ] + 1e-9, $values[ $i ], 'never rises with position' );
		}
	}

	/**
	 * A thin top bucket is scaled to the site's level, not left at the built-in 28% (decision 2026-10-06):
	 * a site whose position 2 gets 0.91% expects about 28 × 0.91 ÷ 15 ≈ 1.7% at position 1.
	 */
	public function test_uncalibrated_bucket_scaled_by_nearest(): void {
		$out = Opportunity::site_curve(
			[
				[ 'impressions' => 500, 'clicks' => 100, 'position' => 1.0 ],  // Too few: not calibrated.
				[ 'impressions' => 10000, 'clicks' => 91, 'position' => 2.0 ], // 0.91%.
			]
		);
		$this->assertSame( 1, $out['buckets'] );
		$this->assertEqualsWithDelta( 28 * 0.91 / 15, $out['curve'][1], 0.01 );
		$this->assertSame( 0.91, $out['curve'][2] );
		$this->assertEqualsWithDelta( 7.2 * 0.91 / 15, $out['curve'][3], 0.01, 'every thin bucket scaled by the same nearest ratio' );
		$this->assertLessThan( 0.1, $out['curve'][50] );
	}

	/**
	 * Too few searches anywhere: the standard curve.
	 */
	public function test_site_curve_needs_data(): void {
		$out = Opportunity::site_curve( [ [ 'impressions' => 900, 'clicks' => 9, 'position' => 3 ] ] );
		$this->assertFalse( $out['site'] );
		$this->assertSame( Opportunity::CURVE, $out['curve'] );
		$this->assertSame( 0, $out['searches'] );
	}

	/**
	 * Smoothing: weighted pool-adjacent-violators.
	 */
	public function test_non_increasing(): void {
		$this->assertSame( [ 5.0, 3.0, 3.0, 1.0 ], Opportunity::non_increasing( [ 5.0, 2.0, 4.0, 1.0 ], [ 1, 1, 1, 1 ] ) );
		$this->assertEqualsWithDelta( 3.25, Opportunity::non_increasing( [ 2.0, 4.0 ], [ 3, 5 ] )[0], 0.0001, '(2×3 + 4×5) ÷ 8' );
	}

	/**
	 * The calibrated curve is used; the filter still wins over it.
	 */
	public function test_site_curve_in_force_and_filter_wins(): void {
		Opportunity::use_curve(
			[
				1  => 10.0,
				50 => 0.1,
			]
		);
		$this->assertSame( 10.0, Opportunity::expected_ctr( 1.0 ) );

		\WP_Mock::onFilter( 'ai_seo_assistant_expected_ctr' )
			->with(
				[
					1  => 10.0,
					50 => 0.1,
				]
			)
			->reply(
				[
					1  => 40.0,
					10 => 4.0,
				]
			);
		$this->assertSame( 40.0, Opportunity::expected_ctr( 1.0 ) );
	}

	/**
	 * Business value: role weight, one step up for a strong enquiry rate, never on a site with < 10 enquiries.
	 */
	public function test_role_value_and_bump(): void {
		$this->assertSame( 1.5, Opportunity::value( 'money' ) );
		$this->assertSame( 1.2, Opportunity::value( 'location' ) );
		$this->assertSame( 1.0, Opportunity::value( 'unclassified' ) );
		$this->assertSame( 0.6, Opportunity::value( 'info' ) );
		$this->assertSame( 1.0, Opportunity::value( 'nonsense' ) );
		$this->assertSame( 1.0, Opportunity::value( 'info', true ), 'info → unclassified' );
		$this->assertSame( 1.5, Opportunity::value( 'money', true ), 'money stays money' );

		$this->assertTrue( Opportunity::earns_bump( 4, 100, 20, 2000 ), '4% vs 1% site' );
		$this->assertFalse( Opportunity::earns_bump( 1, 100, 20, 2000 ), '1% is the site rate' );
		$this->assertFalse( Opportunity::earns_bump( 4, 100, 9, 900 ), 'fewer than 10 enquiries: the rate is not used' );
		$this->assertFalse( Opportunity::earns_bump( 0, 0, 20, 2000 ) );

		$this->assertEqualsWithDelta( 100 * 1.5, Opportunity::weighted( 100.0, 0, 1.5 ), 0.0001 );
		$this->assertGreaterThan( Opportunity::weighted( 100.0, 0, 0.6 ), Opportunity::weighted( 60.0, 0, 1.5 ), 'a money page with fewer missed clicks outranks a guide' );
	}

	/**
	 * Money-page detection: form plugins' shortcodes and blocks, and form or booking embeds.
	 */
	public function test_holds_form(): void {
		$hosts = array_merge( Page_Role::SERVICE_HOSTS, [ 'book.example-salon.com' ] );
		$this->assertTrue( Page_Role::holds_form( '[gravityform id="1"]', $hosts ) );
		$this->assertTrue( Page_Role::holds_form( '<!-- wp:wpforms/form-selector {"formId":"7"} /-->', $hosts ) );
		$this->assertTrue( Page_Role::holds_form( '[contact-form-7 id="12"]', $hosts ) );
		$this->assertTrue( Page_Role::holds_form( '<!-- wp:ajr-forms/form {"id":3} -->', $hosts ) );
		$this->assertTrue( Page_Role::holds_form( '[et_pb_contact_form email="x"]', $hosts ), 'Divi contact form' );
		$this->assertTrue( Page_Role::holds_form( '<div data-url="https://calendly.com/jen/30min"></div><script src="https://assets.calendly.com/assets/external/widget.js"></script>', $hosts ), 'Calendly embed (Jennifer’s /schedule/ and /home-value/)' );
		$this->assertTrue( Page_Role::holds_form( '<script src="//js.hsforms.net/forms/embed/v2.js"></script>', $hosts ), 'HubSpot form' );
		$this->assertTrue( Page_Role::holds_form( '<a href="https://book.example-salon.com/new">Book</a>', $hosts ), 'AJR Core booking domain' );
		$this->assertFalse( Page_Role::holds_form( '<p>We use calendly.com for bookings.</p>', $hosts ), 'a mention is not an embed' );
		$this->assertFalse( Page_Role::holds_form( '<iframe src="https://notcalendly.com/x"></iframe>', $hosts ), 'look-alike host' );
		$this->assertFalse( Page_Role::holds_form( '[et_pb_text]Hello[/et_pb_text]', $hosts ) );
	}

	/**
	 * Default roles: posts are info, named pages money, the rest unclassified.
	 */
	public function test_default_roles(): void {
		$this->assertSame( 'info', Page_Role::default_role( 'post', false ) );
		$this->assertSame( 'info', Page_Role::default_role( 'post', true ), 'a post is info even if linked from the menu' );
		$this->assertSame( 'money', Page_Role::default_role( 'page', true ) );
		$this->assertSame( 'unclassified', Page_Role::default_role( 'page', false ) );
	}
}
