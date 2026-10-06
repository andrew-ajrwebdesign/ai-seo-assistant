<?php
/**
 * Tests for snapshot v2 (Report\Snapshot_V2) against retainer-scan's own fixtures (copied from
 * toolkits/retainer-scan/schema/, PR claude-workspace#144), the hostile fixture's expected survivors, and
 * v1 still being read.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Report;

use AJR\SEOAssistant\Report\Report_View;
use AJR\SEOAssistant\Report\Snapshot;
use AJR\SEOAssistant\Report\Snapshot_V2;
use WP_Mock\Tools\TestCase;

/**
 * v1 and v2 parsing.
 */
class SnapshotV2Test extends TestCase {

	/** After every fixture's generated_at. */
	protected const NOW = 1793000000; // 2026-10-26.

	/**
	 * A fixture, decoded.
	 *
	 * @param string $name File name in tests/fixtures.
	 * @return array<string,mixed>
	 */
	protected static function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/' . $name ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
	}

	/**
	 * Parse a decoded snapshot through the public entry point.
	 *
	 * @param array<string,mixed> $data   Snapshot.
	 * @param array<int,string>   $errors Errors.
	 * @return array<string,mixed>|null
	 */
	protected static function parse( array $data, array &$errors ): ?array {
		return Snapshot::from_json( (string) json_encode( $data ), $errors, self::NOW ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test input.
	}

	/**
	 * The week fixture: taps kept but out of the total, per-page data normalised, v1 keys for the weekly view.
	 */
	public function test_week_fixture(): void {
		$errors = [];
		$snap   = self::parse( self::fixture( 'snapshot-v2.week.example.json' ), $errors );
		$this->assertNotNull( $snap, implode( '; ', $errors ) );
		$this->assertSame( 'week', $snap['period'] );
		$taps = array_values( array_filter( $snap['enquiries']['sources'], static fn( $s ) => 'tap' === $s['kind'] ) );
		$this->assertNotEmpty( $taps );
		foreach ( $taps as $tap ) {
			$this->assertFalse( Snapshot_V2::counts( $tap ), 'a tap is never added' );
		}
		[ $total ] = Report_View::totals( $snap['enquiries']['sources'] );
		$expected  = array_sum( array_column( array_filter( self::fixture( 'snapshot-v2.week.example.json' )['enquiries']['sources'], static fn( $s ) => true === $s['in_total'] ), 'count' ) );
		$this->assertSame( $expected, $total );
		$this->assertCount( 12, $snap['enquiries']['series_12w'] );
		$this->assertNotNull( $snap['gsc']['week']['clicks'], 'gsc.totals read into the weekly view’s keys' );
		$this->assertCount( 12, $snap['gsc']['clicks_12w'] );
		$page = $snap['pages']['/water-heater-installation/'];
		$this->assertSame( 44, $page['gsc']['clicks'] );
		$this->assertCount( 13, $page['gsc']['weeks'] );
		$this->assertSame( $snap['pages_range']['start'], $page['gsc']['weeks'][0]['start'] );
		$this->assertSame( 64, $page['gsc']['queries_total'] );
		$this->assertNull( $snap['pages']['/book-online/']['gsc'], 'GA4-only page kept, no Google figures' );
		$this->assertSame( '', $snap['pages']['/book-online/']['url'] );
		$this->assertEqualsWithDelta( 58.0, $page['ga4']['search_share'], 0.1 );
		$this->assertSame( 1, $page['ga4']['enquiries'], 'the form is counted; the 2 phone taps are not' );
		$this->assertSame( 2, $page['ga4']['taps'] );
	}

	/**
	 * The month fixture: stored by month.start, billing day, compared_with, daily lines, Ads calls.
	 */
	public function test_month_fixture(): void {
		$errors = [];
		$snap   = self::parse( self::fixture( 'snapshot-v2.month.example.json' ), $errors );
		$this->assertNotNull( $snap, implode( '; ', $errors ) );
		$this->assertSame( 'month', $snap['period'] );
		$this->assertSame( '2026-09-17', $snap['month']['start'] );
		$this->assertSame( 17, $snap['billing_day'] );
		$this->assertSame( '2026-08-17', $snap['month']['compared_with']['start'] );
		$this->assertTrue( $snap['month']['complete'] );
		$this->assertSame( 'day', $snap['gsc']['series_unit'] );
		$this->assertCount( 30, $snap['gsc']['clicks_12w'] );
		$this->assertCount( 12, $snap['enquiries']['history'] );
		$this->assertSame( 21, $snap['ads']['calls']['value'] );
	}

	/**
	 * Refusals: a tap claiming the total; Ads calls disagreeing with the ads_calls source; an unknown period.
	 */
	public function test_v2_refusals(): void {
		$week = self::fixture( 'snapshot-v2.week.example.json' );
		foreach ( $week['enquiries']['sources'] as $i => $s ) {
			if ( 'tap' === $s['kind'] ) {
				$week['enquiries']['sources'][ $i ]['in_total'] = true;
				break;
			}
		}
		$errors = [];
		$this->assertNull( self::parse( $week, $errors ) );
		$this->assertStringContainsString( 'tap', implode( ' ', $errors ) );

		$week                          = self::fixture( 'snapshot-v2.week.example.json' );
		$week['ads']['calls']['value'] = 99;
		$this->assertNull( self::parse( $week, $errors ) );
		$this->assertStringContainsString( 'ads.calls', implode( ' ', $errors ) );

		$week           = self::fixture( 'snapshot-v2.week.example.json' );
		$week['period'] = 'year';
		$this->assertNull( self::parse( $week, $errors ) );
	}

	/**
	 * PR #144 final: gbp_call_taps is a tap (never in the total), `form` is a counted source, `complete:false`
	 * reads "figures still settling", and business_profile_check comes through (checked:false kept as such).
	 */
	public function test_pr144_final_schema(): void {
		$errors = [];
		$snap   = self::parse( self::fixture( 'snapshot-v2.week.example.json' ), $errors );
		$by     = array_column( $snap['enquiries']['sources'], null, 'key' );
		$this->assertSame( 'tap', $by['gbp_call_taps']['kind'] );
		$this->assertFalse( Snapshot_V2::counts( $by['gbp_call_taps'] ) );
		$this->assertSame( 'form', $by['form']['kind'] );
		$this->assertTrue( Snapshot_V2::counts( $by['form'] ) );
		$this->assertTrue( $snap['listing']['checked'], 'the fixture’s business_profile_check comes through' );
		$this->assertGreaterThan( 0, $snap['listing']['problems'] );

		$month                      = self::fixture( 'snapshot-v2.month.example.json' );
		$month['month']['complete'] = false;
		$snap                       = self::parse( $month, $errors );
		$this->assertFalse( $snap['month']['complete'], 'complete:false kept (settling or partial)' );

		$week                           = self::fixture( 'snapshot-v2.week.example.json' );
		$week['business_profile_check'] = [
			'version' => 1,
			'checked' => false,
			'reason'  => 'Places lookup failed <script>',
			'summary' => null,
			'fields'  => [],
		];
		$snap = self::parse( $week, $errors );
		$this->assertNotNull( $snap, implode( '; ', $errors ) );
		$this->assertFalse( $snap['listing']['checked'], 'not checked never reads as a match' );
		$this->assertSame( 0, $snap['listing']['problems'] );
		$this->assertStringNotContainsString( '<', $snap['listing']['reason'] );
	}

	/**
	 * The hostile fixture leaves exactly the schema doc's survivors, with no "<" or ">" anywhere.
	 */
	public function test_hostile_fixture_survivors(): void {
		$errors = [];
		$snap   = self::parse( self::fixture( 'snapshot-v2.hostile.example.json' ), $errors );
		$this->assertNotNull( $snap, implode( '; ', $errors ) );
		$this->assertSame( [ 'emergency plumber northfield', 'northfield plumbing', 'best plumber northfield' ], array_column( $snap['gsc']['top_queries'], 'query' ) );
		$this->assertSame( [ 'Emergency plumbing', 'Home', 'Boiler repair &amp; servicing' ], array_column( $snap['ga4']['top_pages'], 'title' ) );
		$this->assertSame( [ '/water-heater-installation/', '/book-online/', '/hostile-queries/' ], array_keys( $snap['pages'] ) );
		$this->assertSame( [ 'water heater' ], array_column( $snap['pages']['/hostile-queries/']['gsc']['queries'], 'query' ) );
		$this->assertSame( 3, $snap['pages_range']['available'] );
		$this->assertStringNotContainsString( '<', (string) json_encode( $snap ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test.
		$this->assertStringNotContainsString( '>', (string) json_encode( $snap ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test.
	}

	/**
	 * Page URLs: https on the site's or the Search Console property's host only.
	 */
	public function test_page_urls(): void {
		$hosts = [
			'welcometoboise.ddev.site'     => true,
			'welcometoboiseandbeyond.com' => true,
		];
		$this->assertSame( 'https://welcometoboiseandbeyond.com/a/', Snapshot_V2::page_url( 'https://welcometoboiseandbeyond.com/a/', $hosts ) );
		$this->assertSame( '', Snapshot_V2::page_url( 'http://welcometoboiseandbeyond.com/a/', $hosts ) );
		$this->assertSame( '', Snapshot_V2::page_url( 'https://evil.example/a/', $hosts ) );
		$this->assertSame( '', Snapshot_V2::page_url( 'javascript:alert(1)', $hosts ) );
		$this->assertSame( '', Snapshot_V2::page_url( "https://welcometoboiseandbeyond.com/o'brien/", $hosts ) );
	}

	/**
	 * v1 is still read exactly as before (live sites hold a year of v1 weeks).
	 */
	public function test_v1_still_read(): void {
		$errors = [];
		$snap   = Snapshot::from_json( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-week.json' ), $errors, 1790586000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$this->assertNotNull( $snap, implode( '; ', $errors ) );
		$this->assertSame( 1, $snap['schema'] );
		$this->assertArrayNotHasKey( 'pages', $snap );
		[ $total ] = Report_View::totals( $snap['enquiries']['sources'] );
		$this->assertSame( 23, $total, 'v1 sources carry no in_total and are all counted' );
	}

	/**
	 * Size: v2 up to 2 MiB, v1 still 128 KB.
	 */
	public function test_size_limits(): void {
		$errors = [];
		$this->assertNull( Snapshot::from_json( str_repeat( ' ', Snapshot::MAX_BYTES_V2 + 1 ), $errors ) );
		$this->assertStringContainsString( (string) Snapshot::MAX_BYTES_V2, $errors[0] );
		$v1        = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-week.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$v1['pad'] = str_repeat( 'x', Snapshot::MAX_BYTES );
		$this->assertNull( Snapshot::from_json( (string) json_encode( $v1 ), $errors, 1790586000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test input.
	}
}
