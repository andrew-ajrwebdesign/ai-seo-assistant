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
		$this->assertSame( 'ChIJExampleNorthfieldPlumbing00', $snap['listing']['place_id'], 'kept for AJR Core (contract 3)' );
		$this->assertSame( 4.8, $snap['listing']['google']['rating'] );
		$this->assertSame( 112, $snap['listing']['google']['review_count'] );
		$this->assertTrue( $snap['listing']['google']['service_area_only'] );

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
	 * Profile-check suggestions (PR #145): cleaned and capped like the other Google text, bad rows dropped,
	 * at most 20, passed to AJR Core in the listing block.
	 */
	public function test_profile_suggestions(): void {
		$errors = [];
		$week   = self::fixture( 'snapshot-v2.week.example.json' );
		$extra  = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/profile-suggestions.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$extra['suggestions'][5]['why']               = str_repeat( 'w', 300 );
		$week['business_profile_check']['suggestions'] = $extra['suggestions'];
		$snap = self::parse( $week, $errors );
		$this->assertNotNull( $snap, implode( '; ', $errors ) );
		$got = $snap['listing']['suggestions'];
		$this->assertSame( [ 'description-length', 'add-hours', 'long' ], array_column( $got, 'id' ) );
		$this->assertStringNotContainsString( '<', $got[1]['why'] );
		$this->assertSame( '', $got[1]['current'], 'null current is empty' );
		$this->assertSame( 200, mb_strlen( $got[2]['why'] ) );
		$this->assertSame( '', $got[2]['where'], 'unknown where dropped' );
		$this->assertArrayHasKey( 'copy', $got[0] );
		$this->assertLessThanOrEqual( 300, mb_strlen( $got[0]['copy'] ) );
		$this->assertArrayNotHasKey( 'copy', $got[1] );

		$week['business_profile_check']['suggestions'] = array_fill( 0, 30, $extra['suggestions'][0] );
		$this->assertCount( 20, self::parse( $week, $errors )['listing']['suggestions'] );
		unset( $week['business_profile_check']['suggestions'] );
		$this->assertSame( [], self::parse( $week, $errors )['listing']['suggestions'], 'an older push: none' );

		$row                                                     = $extra['suggestions'][1];
		$row['reason']                                           = str_repeat( 'r', 250 );
		$row['copy']                                             = str_repeat( 'c', 400 );
		$week['business_profile_check']['suggestions_unchecked'] = array_fill( 0, 15, $row );
		$un = self::parse( $week, $errors )['listing']['suggestions_unchecked'];
		$this->assertCount( 10, $un );
		$this->assertSame( 200, mb_strlen( $un[0]['reason'] ) );
		$this->assertSame( 300, mb_strlen( $un[0]['copy'] ) );
		$this->assertSame( '', $un[0]['current'], 'current may be null' );
	}

	/**
	 * Country filter (PR #144 cb8aacf): site figures and per-page search lists carry the country; a bad code,
	 * or a snapshot from before the field, means all countries. Labels name the country.
	 */
	public function test_country_filter(): void {
		$errors = [];
		$snap   = self::parse( self::fixture( 'snapshot-v2.week.example.json' ), $errors );
		$this->assertSame( 'usa', $snap['gsc']['country'] );
		$this->assertSame( 'usa', $snap['pages_range']['queries_country'] );

		$week                             = self::fixture( 'snapshot-v2.week.example.json' );
		$week['gsc']['country']           = 'USA<script>';
		$week['pages']['queries_country'] = 'us';
		$snap                             = self::parse( $week, $errors );
		$this->assertNull( $snap['gsc']['country'], 'not three lower-case letters: all countries' );
		$this->assertNull( $snap['pages_range']['queries_country'] );

		$week = self::fixture( 'snapshot-v2.week.example.json' );
		unset( $week['gsc']['country'], $week['pages']['queries_country'] );
		$snap = self::parse( $week, $errors );
		$this->assertNotNull( $snap, 'an older v2 snapshot without the fields still works' );
		$this->assertNull( $snap['gsc']['country'] );

		$this->assertSame( 'Google Search Console · last 12 weeks · from the United States', \AJR\SEOAssistant\Report\Report_View::with_country( 'Google Search Console · last 12 weeks', 'usa' ) );
		$this->assertSame( 'Google Search Console · last 12 weeks', \AJR\SEOAssistant\Report\Report_View::with_country( 'Google Search Console · last 12 weeks', null ) );
		$this->assertSame( 'BRA', \AJR\SEOAssistant\Report\Report_View::country_name( 'bra' ) );
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
