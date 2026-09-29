<?php
/**
 * Tests for Report\Snapshot — what the site accepts from a push.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Report;

use AJR\SEOAssistant\Report\Snapshot;
use WP_Mock\Tools\TestCase;

/**
 * Snapshot validation.
 */
class SnapshotTest extends TestCase {

	/**
	 * The fixture week (the Figma mockup's figures).
	 *
	 * @return array<string,mixed>
	 */
	protected function week(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-week.json' ), true );
	}

	/**
	 * A complete, valid week is accepted and normalised.
	 */
	public function test_valid_week_is_accepted(): void {
		$errors = [];
		$clean  = Snapshot::from_json( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-week.json' ), $errors );

		$this->assertSame( [], $errors );
		$this->assertSame( 'northfield.example', $clean['site'] );
		$this->assertSame( [ 'start' => '2026-09-21', 'end' => '2026-09-27' ], $clean['week'] );
		$this->assertSame( strtotime( '2026-09-28T07:12:00+00:00' ), $clean['generated_at'] );
		$this->assertCount( 4, $clean['enquiries']['sources'] );
		$this->assertSame( 23, array_sum( array_column( $clean['enquiries']['sources'], 'count' ) ) );
		$this->assertCount( 12, $clean['enquiries']['series_12w'] );
		$this->assertCount( 3, $clean['note']['did'] );
		$this->assertCount( 5, $clean['gsc']['top_queries'] );
		$this->assertSame( -2, $clean['gsc']['top_queries'][2]['change'] );
		$this->assertSame( [ 'value' => 612, 'previous' => 588 ], $clean['ads']['spend'] );
		$this->assertSame( 700.0, $clean['ads']['budget'] );
		$this->assertSame( 'USD', $clean['ads']['currency'] );
		$this->assertSame( [ 'value' => 11.4, 'previous' => 12.1 ], $clean['gsc']['week']['position'] );
		$this->assertSame( 41, $clean['ga4']['week']['key_events']['value'] );
		$this->assertStringStartsWith( 'Calls are those Google Ads', $clean['enquiries']['note'] );
	}

	/**
	 * A headline figure the data does not support is left out, never shown as 0.
	 */
	public function test_missing_or_bad_metrics_are_null(): void {
		$raw                                  = $this->week();
		$raw['gsc']['week']['clicks']         = [ 'value' => -5 ];
		$raw['gsc']['week']['impressions']    = [ 'value' => 12.5 ];
		$raw['gsc']['week']['ctr']            = [ 'value' => 2.2, 'previous' => 'n/a' ];
		unset( $raw['ga4']['week']['visits'] );
		$raw['ads']['budget']                 = 0;
		$raw['ads']['currency']               = 'dollars';
		$errors                               = [];
		$clean                                = Snapshot::clean( $raw, $errors );

		$this->assertNull( $clean['gsc']['week']['clicks'], 'negative' );
		$this->assertNull( $clean['gsc']['week']['impressions'], 'a count cannot be a fraction' );
		$this->assertSame( [ 'value' => 2.2, 'previous' => null ], $clean['gsc']['week']['ctr'] );
		$this->assertNull( $clean['ga4']['week']['visits'] );
		$this->assertNull( $clean['ads']['budget'], 'a zero budget is no budget' );
		$this->assertSame( '', $clean['ads']['currency'] );
	}

	/**
	 * The things that make a week unusable refuse it whole.
	 */
	public function test_required_fields_refuse_the_week(): void {
		$cases = [
			'schema'       => [ 'schema', 2 ],
			'site'         => [ 'site', 'not a host!' ],
			'generated_at' => [ 'generated_at', 'yesterday-ish' ],
		];
		foreach ( $cases as $label => [ $key, $value ] ) {
			$raw         = $this->week();
			$raw[ $key ] = $value;
			$errors      = [];
			$this->assertNull( Snapshot::clean( $raw, $errors ), $label );
			$this->assertNotEmpty( $errors, $label );
		}

		$raw                         = $this->week();
		$raw['enquiries']['sources'] = [ [ 'key' => 'BAD KEY' ] ];
		$errors                      = [];
		$this->assertNull( Snapshot::clean( $raw, $errors ), 'sources sent, none usable' );

		unset( $raw['enquiries']['sources'] );
		$this->assertNull( Snapshot::clean( $raw, $errors ), 'sources missing altogether' );
	}

	/**
	 * An empty source list is "enquiries not counted yet" — accepted, never turned into a 0.
	 */
	public function test_uncounted_enquiries_are_accepted(): void {
		$raw                            = $this->week();
		$raw['enquiries']['sources']    = [];
		$raw['enquiries']['series_12w'] = [];
		$errors                         = [];
		$clean                          = Snapshot::clean( $raw, $errors );

		$this->assertNotNull( $clean, implode( '; ', $errors ) );
		$this->assertSame( [], $clean['enquiries']['sources'] );
	}

	/**
	 * Dates from the future are refused (a wrong clock would otherwise pin a fake "newest" week).
	 */
	public function test_future_dates_are_refused(): void {
		$now    = (int) strtotime( '2026-09-29 12:00:00 UTC' );
		$errors = [];

		$raw         = $this->week();
		$raw['week'] = [ 'start' => '2026-10-12', 'end' => '2026-10-18' ];
		$this->assertNull( Snapshot::clean( $raw, $errors, $now ) );
		$this->assertContains( 'week.start is in the future', $errors );

		$raw                 = $this->week();
		$raw['generated_at'] = '2026-09-29T13:00:00+00:00';
		$this->assertNull( Snapshot::clean( $raw, $errors, $now ) );
		$this->assertContains( 'generated_at is in the future', $errors );

		$raw                 = $this->week();
		$raw['generated_at'] = '2026-09-29T12:05:00+00:00';
		$this->assertNotNull( Snapshot::clean( $raw, $errors, $now ), 'a few minutes of clock drift is fine' );
	}

	/**
	 * The week must run Monday to Sunday.
	 */
	public function test_week_must_be_monday_to_sunday(): void {
		$errors = [];
		$raw    = $this->week();

		$raw['week'] = [ 'start' => '2026-09-22', 'end' => '2026-09-28' ];
		$this->assertNull( Snapshot::clean( $raw, $errors ) );
		$this->assertContains( 'week.start must be a Monday', $errors );

		$raw['week'] = [ 'start' => '2026-09-21', 'end' => '2026-09-26' ];
		$this->assertNull( Snapshot::clean( $raw, $errors ) );
		$this->assertContains( 'week.end must be the Sunday after week.start', $errors );

		$raw['week'] = [ 'start' => '2026-02-30', 'end' => '2026-03-08' ];
		$this->assertNull( Snapshot::clean( $raw, $errors ) );
	}

	/**
	 * Text arrives as plain text: markup stripped, whitespace collapsed, capped.
	 */
	public function test_text_is_plain_and_capped(): void {
		$raw                        = $this->week();
		$raw['note']['did']         = [ '<script>alert(1)</script>Fixed   the <b>form</b>', str_repeat( 'x', 500 ), 42, '' ];
		$raw['gsc']['top_queries']  = [ [ 'query' => '<img src=x onerror=1>plumber', 'clicks' => 3 ] ];
		$raw['enquiries']['sources'][0]['label'] = '<em>Phone</em> calls';
		$errors = [];
		$clean  = Snapshot::clean( $raw, $errors );

		$this->assertSame( 'Fixed the form', $clean['note']['did'][0] );
		$this->assertSame( Snapshot::MAX_TEXT, mb_strlen( $clean['note']['did'][1] ) );
		$this->assertCount( 2, $clean['note']['did'], 'a number and an empty string are dropped' );
		$this->assertSame( 'plumber', $clean['gsc']['top_queries'][0]['query'] );
		$this->assertSame( 'Phone calls', $clean['enquiries']['sources'][0]['label'] );
	}

	/**
	 * Bad rows are dropped, never guessed at; repeated sources count once.
	 */
	public function test_bad_rows_are_dropped(): void {
		$raw                         = $this->week();
		$raw['enquiries']['sources'] = [
			[ 'key' => 'calls', 'label' => 'Phone calls', 'count' => 11 ],
			[ 'key' => 'calls', 'label' => 'Calls again', 'count' => 99 ],
			[ 'key' => 'Form!', 'label' => 'Form', 'count' => 3 ],
			[ 'key' => 'email', 'label' => 'Email', 'count' => -1 ],
			[ 'key' => 'chat', 'label' => 'Chat', 'count' => '4' ],
			'not a row',
		];
		$raw['ga4']['top_pages']     = [
			[ 'title' => 'Home', 'path' => '/', 'visits' => 10 ],
			[ 'title' => 'Evil', 'path' => 'javascript:alert(1)', 'visits' => 5 ],
			[ 'title' => '', 'path' => '/x/', 'visits' => 5 ],
		];
		$errors = [];
		$clean  = Snapshot::clean( $raw, $errors );

		$this->assertSame( [ 'calls' ], array_column( $clean['enquiries']['sources'], 'key' ) );
		$this->assertCount( 2, $clean['ga4']['top_pages'] );
		$this->assertSame( '', $clean['ga4']['top_pages'][1]['path'], 'a non-path is blanked, the row kept' );
	}

	/**
	 * A trend line with any bad point is emptied (a hole would draw a false dip); only 12 points kept.
	 */
	public function test_series_are_all_or_nothing(): void {
		$raw                               = $this->week();
		$raw['gsc']['clicks_12w']          = [ 1, 2, null, 4 ];
		$raw['gsc']['impressions_12w']     = range( 1, 20 );
		$raw['enquiries']['series_12w']    = [ 'a' => 1, 'b' => 2 ];
		$errors                            = [];
		$clean                             = Snapshot::clean( $raw, $errors );

		$this->assertSame( [], $clean['gsc']['clicks_12w'] );
		$this->assertSame( range( 9, 20 ), $clean['gsc']['impressions_12w'], 'the latest 12 points' );
		$this->assertSame( [], $clean['enquiries']['series_12w'], 'not a list' );
	}

	/**
	 * Optional sections may be absent; unknown fields never reach the stored copy.
	 */
	public function test_optional_sections_and_unknown_fields(): void {
		$raw = $this->week();
		unset( $raw['note'], $raw['gsc'], $raw['ga4'], $raw['ads'] );
		$raw['password'] = 'hunter2';
		$errors          = [];
		$clean           = Snapshot::clean( $raw, $errors );

		$this->assertSame( [], $errors );
		$this->assertNull( $clean['note'] );
		$this->assertNull( $clean['gsc'] );
		$this->assertNull( $clean['ads'] );
		$this->assertArrayNotHasKey( 'password', $clean );
	}

	/**
	 * Oversized or non-JSON bodies are refused before decoding matters.
	 */
	public function test_body_limits(): void {
		$errors = [];
		$this->assertNull( Snapshot::from_json( str_repeat( ' ', Snapshot::MAX_BYTES + 1 ), $errors ) );
		$this->assertNull( Snapshot::from_json( '"just a string"', $errors ) );
		$this->assertNull( Snapshot::from_json( '{broken', $errors ) );
	}
}
