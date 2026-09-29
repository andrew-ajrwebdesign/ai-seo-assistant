<?php
/**
 * Tests for Report\Report_View — the markup the client reads.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Report;

use AJR\SEOAssistant\Report\Report_View;
use AJR\SEOAssistant\Report\Snapshot;
use WP_Mock\Tools\TestCase;

/**
 * States, semantics, escaping and omissions.
 */
class ReportViewTest extends TestCase {

	/** Monday 28 September 2026, 09:00 UTC: the fixture's update arrived that morning. */
	protected const FRESH = 1790586000;

	/**
	 * English passthrough for translation, real escaping, UTC dates.
	 */
	public function setUp(): void {
		parent::setUp();
		$esc = static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( '_x' )->andReturnArg( 0 );
		\WP_Mock::userFunction( '_n' )->andReturnUsing( fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'esc_attr__' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturnUsing( fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
		\WP_Mock::userFunction( 'wp_sprintf' )->andReturnUsing( fn( $f, $list ) => implode( ' and ', (array) $list ) );
		\WP_Mock::userFunction( 'human_time_diff' )->andReturnUsing( fn( $a, $b ) => (int) round( abs( $b - $a ) / 86400 ) . ' days' );
		\WP_Mock::userFunction( 'wp_date' )->andReturnUsing(
			fn( $format, $time, $tz = null ) => ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
	}

	/**
	 * The fixture, cleaned as a push would be.
	 *
	 * @return array<string,mixed>
	 */
	protected function snapshot(): array {
		$errors = [];
		$snap   = Snapshot::from_json( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-week.json' ), $errors );
		$this->assertNotNull( $snap, implode( '; ', $errors ) );

		return $snap;
	}

	/**
	 * Context as Report_Page builds it for the newest week.
	 *
	 * @param int  $now     Unix time.
	 * @param bool $alerted Alert sent.
	 * @return array<string,mixed>
	 */
	protected function context( int $now = self::FRESH, bool $alerted = false ): array {
		return [
			'business' => 'Northfield Plumbing & Heating',
			'agency'   => 'AJR Web Design',
			'now'      => $now,
			'latest'   => true,
			'prev_url' => 'https://example.test/wp-admin/admin.php?page=ai-seo-assistant-weekly&week=2026-09-14',
			'next_url' => '',
			'alerted'  => $alerted,
		];
	}

	/**
	 * A normal week: one h1 with the enquiries headline, every card named, no late notice.
	 */
	public function test_normal_week(): void {
		$html = Report_View::report( $this->snapshot(), $this->context() );

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertMatchesRegularExpression( '#<h1 class="aisa-hero__headline" id="aisa-headline">23 enquiries this week, 5 more than last week\.</h1>#', $html );
		foreach ( [ 'aisa-enquiries', 'aisa-note', 'aisa-search', 'aisa-visits', 'aisa-ads' ] as $id ) {
			$this->assertStringContainsString( 'aria-labelledby="' . $id . '"', $html, $id );
		}
		$this->assertStringContainsString( '<aside class="aisa-card aisa-note"', $html );
		$this->assertStringContainsString( 'A note from Andrew', $html );
		$this->assertStringContainsString( 'id="aisa-enquiries"><span class="aisa-icon dashicons dashicons-phone" aria-hidden="true"></span>Where this week’s enquiries came from</h2>', $html, 'the card keeps its own heading' );
		$this->assertStringNotContainsString( 'aisa-late', $html );
		$this->assertStringContainsString( 'Northfield Plumbing &amp; Heating', $html, 'business name escaped' );
		$this->assertStringContainsString( '<span class="aisa-weekbtn" role="link" aria-disabled="true">', $html, 'no next week' );
		$this->assertStringContainsString( 'week=2026-09-14', $html );
	}

	/**
	 * No colour is typed into the markup: the stylesheet owns every colour.
	 */
	public function test_no_colours_in_markup(): void {
		$html = Report_View::report( $this->snapshot(), $this->context() );

		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b|rgb\(|hsl\(|\bfill="|\bstroke="/i', $html );
		preg_match_all( '/style="([^"]*)"/', $html, $styles );
		foreach ( $styles[1] as $style ) {
			$this->assertMatchesRegularExpression( '/^inline-size:[0-9.]+%$/', $style );
		}
	}

	/**
	 * Late: the notice appears on the newest week, and says an alert went only when one did.
	 */
	public function test_late_week(): void {
		$late = self::FRESH + 16 * DAY_IN_SECONDS;
		$html = Report_View::report( $this->snapshot(), $this->context( $late ) );

		$this->assertStringContainsString( '<div class="aisa-late" role="status">', $html );
		$this->assertStringContainsString( 'aisa-hero--late', $html );
		$this->assertStringNotContainsString( 'has been sent an alert', $html );
		$this->assertStringContainsString( 'has been sent an alert', Report_View::report( $this->snapshot(), $this->context( $late, true ) ) );

		$older           = $this->context( $late );
		$older['latest'] = false;
		$this->assertStringNotContainsString( 'aisa-late', Report_View::report( $this->snapshot(), $older ), 'an older week is history, not late' );
	}

	/**
	 * A source the snapshot does not carry is left out, not shown as zero.
	 */
	public function test_missing_blocks_are_omitted(): void {
		$snap = $this->snapshot();
		unset( $snap['ga4'], $snap['ads'] );
		$snap['note'] = null;
		$html         = Report_View::report( $snap, $this->context() );

		$this->assertStringNotContainsString( 'aisa-visits', $html );
		$this->assertStringNotContainsString( 'aisa-ads', $html );
		$this->assertStringNotContainsString( 'aisa-row--even', $html );
		$this->assertStringNotContainsString( '<aside', $html );
		$this->assertStringContainsString( 'aria-labelledby="aisa-search"', $html );
	}

	/**
	 * Enquiries not counted yet: the report says so, and nowhere claims "0 enquiries".
	 */
	public function test_uncounted_enquiries_never_show_zero(): void {
		$snap                                = $this->snapshot();
		$snap['enquiries']['sources']        = [];
		$snap['enquiries']['series_12w']     = [];
		$html                                = Report_View::report( $snap, $this->context() );

		$this->assertStringContainsString( '>Your website this week</h1>', $html );
		$this->assertStringContainsString( 'Enquiries are not being counted yet.', $html );
		$this->assertStringNotContainsString( '0 enquiries', $html );
		$this->assertStringNotContainsString( 'aisa-sources', $html );
	}

	/**
	 * Text from the snapshot is escaped at output.
	 */
	public function test_snapshot_text_is_escaped(): void {
		$snap                                   = $this->snapshot();
		$snap['gsc']['top_queries'][0]['query'] = 'pipes & "drains" <b>';
		$html                                   = Report_View::report( $snap, $this->context() );

		$this->assertStringContainsString( 'pipes &amp; &quot;drains&quot; &lt;b&gt;', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	/**
	 * Before the first update: a headline, the five previews, and the no-keys footer.
	 */
	public function test_empty_state(): void {
		$html = Report_View::empty_state( $this->context() );

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringContainsString( 'Waiting for the first update', $html );
		$this->assertSame( 5, substr_count( $html, 'aisa-card--preview' ) );
		$this->assertStringContainsString( 'No Google passwords or keys are stored here.', $html );
	}

	/**
	 * Week ranges and chart labels read as calendar dates.
	 */
	public function test_dates(): void {
		$this->assertSame( 'Monday 21 to Sunday 27 September 2026', Report_View::range( [ 'start' => '2026-09-21', 'end' => '2026-09-27' ], true ) );
		$this->assertSame( '28 September to 4 October', Report_View::range( [ 'start' => '2026-09-28', 'end' => '2026-10-04' ], false ) );
		$this->assertSame( [ '7 Sep', '14 Sep', '21 Sep' ], Report_View::week_labels( '2026-09-21', 3 ) );
	}
}
