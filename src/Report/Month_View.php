<?php
/**
 * Month_View — the Monthly report for the client's billing period (mockup F1–F4, approved 2026-10-06).
 *
 * WHY. It replaces the hand-written monthly retainer report: the figures arrive with a `period: "month"`
 * push for the billing period (Jennifer: the 17th → 17 Sep–16 Oct, never a sum of Monday weeks), the
 * plugin adds what it changed and what that achieved (Changes\Change_Log), and Andrew writes only the note.
 * Printable: "Print or save as PDF" uses the print stylesheet (A4, two pages, no wp-admin chrome).
 *
 * Order (F1): header with the enquiries headline · enquiries by source (taps listed apart, never added,
 * approved correction 1) beside the note · what we did and what it achieved · how people found you on
 * Google · visits and Google Ads · footer. A lane the push did not carry is left out, never shown as 0.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Monthly report.
 */
class Month_View extends Report_View {

	/**
	 * The whole month.
	 *
	 * @param array<string,mixed>            $snap    Clean month snapshot (Snapshot_V2).
	 * @param array<string,mixed>            $context business, agency, now, prev_url, next_url, tabs, print (bool).
	 * @param array<int,array<string,mixed>> $done    "What we did" rows: date, text, result, detail.
	 */
	public static function month( array $snap, array $context, array $done ): string {
		$since = __( 'on last month', 'ai-seo-assistant' );
		$html  = self::month_header( $snap, $context );
		$html .= '<div class="aisa-row">' . self::month_enquiries( $snap, $since ) . self::month_note( $snap['note'] ?? null, $context ) . '</div>';
		$html .= self::achieved( $done );
		$html .= self::month_search( $snap, $since );
		$visits = self::month_visits( $snap['ga4'] ?? null, $snap, $since );
		$ads    = self::month_ads( $snap['ads'] ?? null, $snap, $since );
		if ( '' !== $visits || '' !== $ads ) {
			$html .= '<div class="aisa-row aisa-row--even aisa-print-page">' . $visits . $ads . '</div>';
		}
		$html .= '<footer class="aisa-foot">' . self::icon( 'lock' ) . '<p>'
			/* translators: 1: the billing period, 2: agency name. */
			. esc_html( sprintf( __( 'Figures cover %1$s, your billing month. Sent by %2$s; this site stores no Google passwords or keys.', 'ai-seo-assistant' ), self::range_long( $snap['month'] ), (string) $context['agency'] ) )
			. '</p></footer>';

		return '<article class="aisa-report aisa-report--month" aria-labelledby="aisa-headline">' . $html . '</article>';
	}

	/**
	 * Before the first month arrives.
	 *
	 * @param array<string,mixed> $context Context.
	 */
	public static function month_empty( array $context ): string {
		return '<article class="aisa-report aisa-report--empty" aria-labelledby="aisa-headline">'
			. '<header class="aisa-hero"><div class="aisa-hero__top">' . self::month_brand( (string) $context['agency'] ) . ( $context['tabs'] ?? '' ) . '</div>' // Tabs escaped by Report_Page.
			. '<h1 class="aisa-hero__headline" id="aisa-headline">' . esc_html__( 'The first monthly report is on its way', 'ai-seo-assistant' ) . '</h1>'
			/* translators: %s: agency name. */
			. '<p class="aisa-hero__sub">' . esc_html( sprintf( __( 'It covers your billing month and arrives from %s after the month ends. The weekly reports carry on as before.', 'ai-seo-assistant' ), (string) $context['agency'] ) ) . '</p></header>'
			. '</article>';
	}

	/**
	 * Dark header: brand, tabs, freshness, headline, period, month navigation, print.
	 *
	 * @param array<string,mixed> $snap    Snapshot.
	 * @param array<string,mixed> $context Context.
	 */
	protected static function month_header( array $snap, array $context ): string {
		[ $total, $previous ] = self::totals( $snap['enquiries']['sources'] );
		$headline             = [] === $snap['enquiries']['sources'] ? __( 'Your website this month', 'ai-seo-assistant' ) : self::month_headline( $total, $previous );
		$m                    = $snap['month'];
		$sub                  = esc_html( (string) $context['business'] ) . ' · ' . esc_html( self::range_long( $m ) )
			. ' ' . esc_html( $m['complete'] ? __( '(your billing month)', 'ai-seo-assistant' ) : sprintf( /* translators: %s: last day included. */ __( '(your billing month, figures to %s so far)', 'ai-seo-assistant' ), self::date( 'j F', (int) strtotime( $m['data_end'] . ' 12:00 UTC' ) ) ) );
		$nav                  = '<nav class="aisa-weeks" aria-label="' . esc_attr__( 'Other months', 'ai-seo-assistant' ) . '">'
			. self::week_link( (string) ( $context['prev_url'] ?? '' ), __( 'Previous month', 'ai-seo-assistant' ), 'arrow-left-alt2', true )
			. self::week_link( (string) ( $context['next_url'] ?? '' ), __( 'Next month', 'ai-seo-assistant' ), 'arrow-right-alt2', false )
			. '<span class="aisa-weeks__note">' . esc_html__( 'Reports are kept for 12 months', 'ai-seo-assistant' ) . '</span>'
			. '<button type="button" class="aisa-weekbtn aisa-print" data-aisa-print>' . self::icon( 'printer' ) . esc_html__( 'Print or save as PDF', 'ai-seo-assistant' ) . '</button></nav>';

		return '<header class="aisa-hero">'
			. '<div class="aisa-hero__top">' . self::month_brand( (string) $context['agency'] ) . ( $context['tabs'] ?? '' ) // Tabs escaped by Report_Page.
			/* translators: %s: date and time the figures arrived. */
			. '<p class="aisa-fresh"><span class="aisa-fresh__dot" aria-hidden="true"></span>' . esc_html( sprintf( __( 'Updated %s', 'ai-seo-assistant' ), self::local( 'D j M, g:ia', (int) $snap['generated_at'] ) ) ) . '</p></div>'
			. '<p class="aisa-print-only aisa-print-head"><strong>' . esc_html( (string) $context['agency'] ) . '</strong> ' . esc_html__( 'Monthly report', 'ai-seo-assistant' ) . '<span>' . esc_html( (string) $context['business'] ) . '<br>' . esc_html( self::range_short( $m ) ) . '</span></p>'
			. '<h1 class="aisa-hero__headline" id="aisa-headline">' . esc_html( $headline ) . '</h1>'
			. '<p class="aisa-hero__sub">' . $sub . '</p>'
			. $nav . '</header>';
	}

	/**
	 * "73 enquiries this month, 11 more than last month."
	 *
	 * @param int      $total    This month.
	 * @param int|null $previous Last month.
	 */
	public static function month_headline( int $total, ?int $previous ): string {
		$count = number_format_i18n( $total );
		if ( null === $previous ) {
			/* translators: %s: number of enquiries. */
			return sprintf( _n( '%s enquiry this month.', '%s enquiries this month.', $total, 'ai-seo-assistant' ), $count );
		}
		$delta = $total - $previous;
		if ( 0 === $delta ) {
			/* translators: %s: number of enquiries. */
			return sprintf( _n( '%s enquiry this month, the same as last month.', '%s enquiries this month, the same as last month.', $total, 'ai-seo-assistant' ), $count );
		}
		$pattern = $delta > 0
			/* translators: 1: enquiries, 2: how many more. */
			? _n( '%1$s enquiry this month, %2$s more than last month.', '%1$s enquiries this month, %2$s more than last month.', $total, 'ai-seo-assistant' )
			/* translators: 1: enquiries, 2: how many fewer. */
			: _n( '%1$s enquiry this month, %2$s fewer than last month.', '%1$s enquiries this month, %2$s fewer than last month.', $total, 'ai-seo-assistant' );

		return sprintf( $pattern, $count, number_format_i18n( abs( $delta ) ) );
	}

	/**
	 * Enquiries by source with the 12 billing months.
	 *
	 * @param array<string,mixed> $snap  Snapshot.
	 * @param string              $since Change wording.
	 */
	protected static function month_enquiries( array $snap, string $since ): string {
		$sources = $snap['enquiries']['sources'];
		$title   = __( 'Where this month’s enquiries came from', 'ai-seo-assistant' );
		$note    = '' !== $snap['enquiries']['note'] ? '<p class="aisa-small">' . esc_html( $snap['enquiries']['note'] ) . '</p>' : '';
		if ( [] === $sources ) {
			return '<section class="aisa-card aisa-card--grow aisa-card--pending" aria-labelledby="aisa-enquiries">'
				. self::card_head( 'aisa-enquiries', 'phone', $title, __( 'Not counted yet', 'ai-seo-assistant' ) )
				. '<p class="aisa-pending">' . esc_html__( 'Enquiries are not being counted yet. Once form and call tracking is set up, every enquiry will appear here each month, by where it came from.', 'ai-seo-assistant' ) . '</p>' . $note . '</section>';
		}
		$history = (array) $snap['enquiries']['history'];
		$figure  = '';
		if ( count( $history ) >= 2 ) {
			$caption = __( 'Enquiries, last 12 billing months', 'ai-seo-assistant' );
			$labels  = [];
			foreach ( $history as $i => $h ) {
				$labels[] = count( $history ) - 1 === $i ? __( 'Now', 'ai-seo-assistant' ) : self::date( 'M', (int) strtotime( $h['start'] . ' 12:00 UTC' ) );
			}
			$figure = '<figure class="aisa-figure"><figcaption>' . esc_html( $caption ) . '</figcaption>' . Chart::bars( array_column( $history, 'total' ), $labels, $caption ) . '</figure>';
		}

		return '<section class="aisa-card aisa-card--grow" aria-labelledby="aisa-enquiries">'
			. self::card_head( 'aisa-enquiries', 'phone', $title, __( 'All sources, not just Google', 'ai-seo-assistant' ) )
			. '<div class="aisa-split"><ul class="aisa-sources">' . self::source_rows( $sources, $since ) . '</ul>' . $figure . '</div>' . $note . '</section>';
	}

	/**
	 * The month's note: a paragraph, next steps, signature.
	 *
	 * @param array<string,mixed>|null $note    Note.
	 * @param array<string,mixed>      $context Context.
	 */
	protected static function month_note( ?array $note, array $context ): string {
		if ( null === $note ) {
			return '';
		}
		$author   = '' !== $note['author'] ? $note['author'] : (string) $context['agency'];
		$first    = '' !== $note['author'] ? strtok( $author, ' ' ) : $author;
		$body     = '' !== (string) ( $note['text'] ?? '' ) ? '<p>' . esc_html( (string) $note['text'] ) . '</p>' : '';
		$body    .= [] !== $note['did'] ? '<ul class="aisa-note__list">' . implode( '', array_map( static fn( $l ) => '<li>' . esc_html( $l ) . '</li>', $note['did'] ) ) . '</ul>' : '';
		$next     = [] !== $note['next'] ? '<h3 class="aisa-note__h">' . esc_html__( 'Next month', 'ai-seo-assistant' ) . '</h3><ul class="aisa-note__list">' . implode( '', array_map( static fn( $l ) => '<li>' . esc_html( $l ) . '</li>', $note['next'] ) ) . '</ul>' : '';
		$initials = strtoupper( implode( '', array_map( static fn( $w ) => mb_substr( $w, 0, 1 ), array_slice( preg_split( '/\s+/', $author ), 0, 2 ) ) ) );
		$signed   = (string) $context['agency'] . ( '' !== $note['date'] ? ' · ' . self::date( 'D j M', (int) strtotime( $note['date'] . ' 12:00 UTC' ) ) : '' );

		return '<aside class="aisa-card aisa-note" aria-labelledby="aisa-note">'
			/* translators: %s: first name of the note's author. */
			. '<h2 class="aisa-note__title" id="aisa-note">' . self::icon( 'edit' ) . esc_html( sprintf( __( 'A note from %s', 'ai-seo-assistant' ), (string) $first ) ) . '</h2>'
			. $body . $next
			. '<p class="aisa-sign"><span class="aisa-sign__mark" aria-hidden="true">' . esc_html( $initials ) . '</span><span><strong>' . esc_html( $author ) . '</strong><br>' . esc_html( $signed ) . '</span></p></aside>';
	}

	/**
	 * "What we did this month and what it achieved".
	 *
	 * @param array<int,array<string,mixed>> $done Rows: date (Y-m-d), text, result (better|worse|same|nothing|early|done), detail.
	 */
	protected static function achieved( array $done ): string {
		if ( [] === $done ) {
			return '';
		}
		$chips = [
			'better'  => [ __( 'Better', 'ai-seo-assistant' ), 'good' ],
			'worse'   => [ __( 'Worse', 'ai-seo-assistant' ), 'bad' ],
			'same'    => [ __( 'About the same', 'ai-seo-assistant' ), 'muted' ],
			'nothing' => [ __( 'Nothing to measure', 'ai-seo-assistant' ), 'muted' ],
			'early'   => [ __( 'Too early to tell', 'ai-seo-assistant' ), 'warn' ],
			'done'    => [ __( 'Done', 'ai-seo-assistant' ), 'muted' ],
		];
		$rows  = '';
		foreach ( $done as $row ) {
			$chip  = $chips[ $row['result'] ] ?? $chips['done'];
			$rows .= '<tr><th scope="row" class="aisa-done__date">' . esc_html( '' !== $row['date'] ? self::date( 'j M', (int) strtotime( $row['date'] . ' 12:00 UTC' ) ) : '' ) . '</th>'
				. '<td>' . esc_html( (string) $row['text'] ) . '</td>'
				. '<td><span class="aisa-pill aisa-pill--' . esc_attr( $chip[1] ) . '">' . esc_html( $chip[0] ) . '</span></td>'
				. '<td>' . esc_html( (string) $row['detail'] ) . '</td></tr>';
		}

		return '<section class="aisa-card" aria-labelledby="aisa-achieved">'
			. self::card_head( 'aisa-achieved', 'yes-alt', __( 'What we did this month and what it achieved', 'ai-seo-assistant' ), __( 'Each change is measured 4 weeks after it went live', 'ai-seo-assistant' ) )
			. '<div class="aisa-tablewrap"><table class="aisa-table aisa-done"><thead><tr><td></td><th scope="col">' . esc_html__( 'What we did', 'ai-seo-assistant' ) . '</th><th scope="col" colspan="2">' . esc_html__( 'What it achieved', 'ai-seo-assistant' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
	}

	/**
	 * Search Console for the month: tiles, daily lines, top searches.
	 *
	 * @param array<string,mixed> $snap  Snapshot.
	 * @param string              $since Change wording.
	 */
	protected static function month_search( array $snap, string $since ): string {
		$gsc = $snap['gsc'] ?? null;
		if ( null === $gsc ) {
			return '';
		}
		$w     = $gsc['week'];
		$tiles = self::tile( __( 'Clicks from Google', 'ai-seo-assistant' ), $w['clicks'], Format::PERCENT, 'clicks', '', '', $since )
			. self::tile( __( 'Times you appeared', 'ai-seo-assistant' ), $w['impressions'], Format::PERCENT, 'shown', '', '', $since )
			. self::tile( __( 'Click rate', 'ai-seo-assistant' ), $w['ctr'], Format::POINTS, '', '%', '', $since )
			. self::tile( __( 'Average position', 'ai-seo-assistant' ), $w['position'], Format::PLACES, '', '', '', $since );
		$chart = '';
		$days  = count( $gsc['clicks_12w'] );
		if ( $days >= 2 ) {
			$start  = (int) strtotime( ( '' !== $gsc['series_from'] ? $gsc['series_from'] : $snap['month']['start'] ) . ' 12:00 UTC' );
			$labels = [];
			for ( $i = 0; $i < $days; $i++ ) {
				$labels[] = self::date( 'j M', $start + $i * DAY_IN_SECONDS );
			}
			$title = __( 'Clicks and times you appeared, each day of the month', 'ai-seo-assistant' );
			$chart = '<figure class="aisa-figure aisa-figure--wide">' . Chart::lines( $gsc['clicks_12w'], $gsc['impressions_12w'], $labels, $title )
				. '<figcaption class="aisa-small">' . esc_html__( 'Solid line: clicks per day (left scale) · Dashed line: times you appeared (right scale).', 'ai-seo-assistant' ) . '</figcaption></figure>';
		}
		$table = '';
		if ( [] !== $gsc['top_queries'] ) {
			$rows = '';
			foreach ( $gsc['top_queries'] as $q ) {
				$c     = $q['change'];
				$text  = null === $c ? '' : ( 0 === $c ? __( 'same', 'ai-seo-assistant' ) : ( $c > 0 ? sprintf( /* translators: %s: clicks. */ __( 'up %s', 'ai-seo-assistant' ), number_format_i18n( $c ) ) : sprintf( /* translators: %s: clicks. */ __( 'down %s', 'ai-seo-assistant' ), number_format_i18n( abs( $c ) ) ) ) );
				$rows .= '<tr><th scope="row">' . esc_html( $q['query'] ) . '</th><td class="aisa-num aisa-strong">' . esc_html( number_format_i18n( $q['clicks'] ) ) . '</td><td class="aisa-num aisa-tone--' . esc_attr( null === $c || 0 === $c ? 'flat' : ( $c > 0 ? 'good' : 'bad' ) ) . '">' . esc_html( $text ) . '</td></tr>';
			}
			$table = '<div class="aisa-tablewrap"><table class="aisa-table"><caption>' . esc_html__( 'Top searches that brought clicks', 'ai-seo-assistant' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Search', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Clicks', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Change', 'ai-seo-assistant' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		}

		return '<section class="aisa-card aisa-print-page" aria-labelledby="aisa-search">'
			/* translators: %s: short period. */
			. self::card_head( 'aisa-search', 'search', __( 'How people found you on Google', 'ai-seo-assistant' ), sprintf( __( 'Google Search Console · %s', 'ai-seo-assistant' ), self::range_short( $snap['month'] ) ) )
			. '<div class="aisa-tiles aisa-tiles--search aisa-tiles--feature">' . $tiles . '</div>'
			. ( '' !== $chart || '' !== $table ? '<div class="aisa-split aisa-split--chart">' . $chart . $table . '</div>' : '' )
			. '</section>';
	}

	/**
	 * Visits for the month.
	 *
	 * @param array<string,mixed>|null $ga4   GA4 block.
	 * @param array<string,mixed>      $snap  Snapshot.
	 * @param string                   $since Change wording.
	 */
	protected static function month_visits( ?array $ga4, array $snap, string $since ): string {
		if ( null === $ga4 ) {
			return '';
		}
		$w     = $ga4['week'];
		$tiles = self::tile( __( 'Visits', 'ai-seo-assistant' ), $w['visits'], Format::PERCENT, '', '', '', $since )
			. self::tile( __( 'Actions on your website', 'ai-seo-assistant' ), $w['key_events'], Format::COUNT, '', '', __( 'Google Analytics key events, incl. taps', 'ai-seo-assistant' ), $since )
			. self::tile( __( 'Stayed and read', 'ai-seo-assistant' ), $w['engaged'], Format::POINTS, '', '%', '', $since );
		$rows  = '';
		foreach ( $ga4['top_pages'] as $p ) {
			$rows .= '<tr><th scope="row">' . esc_html( $p['title'] ) . '</th><td class="aisa-num">' . esc_html( number_format_i18n( $p['visits'] ) ) . '</td></tr>';
		}

		return '<section class="aisa-card" aria-labelledby="aisa-visits">'
			/* translators: %s: short period. */
			. self::card_head( 'aisa-visits', 'chart-line', __( 'Visits to your website', 'ai-seo-assistant' ), sprintf( __( 'Google Analytics · %s', 'ai-seo-assistant' ), self::range_short( $snap['month'] ) ) )
			. '<div class="aisa-tiles aisa-tiles--plain">' . $tiles . '</div>'
			. ( '' !== $rows ? '<div class="aisa-tablewrap"><table class="aisa-table"><caption>' . esc_html__( 'Most visited pages', 'ai-seo-assistant' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Page', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Visits', 'ai-seo-assistant' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '' )
			. '</section>';
	}

	/**
	 * Google Ads for the month.
	 *
	 * @param array<string,mixed>|null $ads   Ads block.
	 * @param array<string,mixed>      $snap  Snapshot.
	 * @param string                   $since Change wording.
	 */
	protected static function month_ads( ?array $ads, array $snap, string $since ): string {
		if ( null === $ads ) {
			return '';
		}
		$cur   = $ads['currency'];
		$spend = $ads['spend'];
		$conv  = $ads['conversions'];
		$tiles = '';
		if ( null !== $spend ) {
			/* translators: %s: budget. */
			$budget = null !== $ads['budget'] ? sprintf( __( 'budget %s a month', 'ai-seo-assistant' ), Format::money( $ads['budget'], $cur ) ) : '';
			$tiles .= '<div class="aisa-metric"><p class="aisa-metric__label">' . esc_html__( 'Spent', 'ai-seo-assistant' ) . '</p><p class="aisa-metric__value">' . esc_html( Format::money( $spend['value'], $cur ) ) . '</p><p class="aisa-metric__change aisa-tone--flat">' . esc_html( $budget ) . '</p></div>';
		}
		$tiles .= self::tile( __( 'Clicks on your ads', 'ai-seo-assistant' ), $ads['clicks'], Format::PERCENT, '', '', '', $since );
		$calls  = $ads['calls'] ?? null;
		$tiles .= self::tile( __( 'Enquiries Google Ads can see', 'ai-seo-assistant' ), $conv, Format::COUNT, '', '', null !== $calls ? sprintf( /* translators: %s: calls. */ _n( 'including %s call', 'including %s calls', (int) $calls['value'], 'ai-seo-assistant' ), number_format_i18n( (int) $calls['value'] ) ) : '', $since );
		if ( null !== $spend && null !== $conv && $conv['value'] > 0 ) {
			$cpe    = $spend['value'] / $conv['value'];
			$prev   = null !== $spend['previous'] && null !== $conv['previous'] && $conv['previous'] > 0 ? $spend['previous'] / $conv['previous'] : null;
			$change = self::since( Format::change( round( $cpe ), null === $prev ? null : round( $prev ), Format::COST, $cur ), $since );
			$tiles .= '<div class="aisa-metric"><p class="aisa-metric__label">' . esc_html__( 'Cost per enquiry', 'ai-seo-assistant' ) . '</p><p class="aisa-metric__value">' . esc_html( Format::money( $cpe, $cur ) ) . '</p>' . self::change_html( $change, 'aisa-metric__change' ) . '</div>';
		}
		$budget = '';
		if ( null !== $spend && null !== $ads['budget'] ) {
			$pct    = (int) round( $spend['value'] / $ads['budget'] * 100 );
			$budget = '<div class="aisa-budget"><p class="aisa-budget__line"><span>' . esc_html__( 'Monthly budget used', 'ai-seo-assistant' ) . '</span><strong>' . esc_html( $pct . '%' ) . '</strong></p><span class="aisa-meter aisa-meter--budget" aria-hidden="true"><span class="aisa-meter__value" style="inline-size:' . esc_attr( (string) min( 100, $pct ) ) . '%"></span></span></div>';
		}

		return '<section class="aisa-card" aria-labelledby="aisa-ads">'
			/* translators: %s: short period. */
			. self::card_head( 'aisa-ads', 'megaphone', __( 'Your Google Ads', 'ai-seo-assistant' ), sprintf( __( 'Google Ads · %s', 'ai-seo-assistant' ), self::range_short( $snap['month'] ) ) )
			. '<div class="aisa-tiles aisa-tiles--plain aisa-tiles--two">' . $tiles . '</div>' . $budget
			. '<p class="aisa-small">' . esc_html__( 'Google Ads can only count people who clicked an ad and then called or used the form. Your full enquiry count is at the top of this page.', 'ai-seo-assistant' ) . '</p></section>';
	}

	/**
	 * "AI SEO Assistant / Monthly report by AJR Web Design".
	 *
	 * @param string $agency Agency name.
	 */
	protected static function month_brand( string $agency ): string {
		return '<p class="aisa-brand"><span class="aisa-brand__mark" aria-hidden="true">' . self::icon( 'chart-line' ) . '</span><span><strong>' . esc_html__( 'AI SEO Assistant', 'ai-seo-assistant' ) . '</strong><br>'
			/* translators: %s: agency name. */
			. esc_html( sprintf( __( 'Monthly report by %s', 'ai-seo-assistant' ), $agency ) ) . '</span></p>';
	}

	/**
	 * "Thursday 17 September to Friday 16 October 2026".
	 *
	 * @param array<string,mixed> $m Month.
	 */
	public static function range_long( array $m ): string {
		$start = (int) strtotime( $m['start'] . ' 12:00 UTC' );
		$end   = (int) strtotime( $m['end'] . ' 12:00 UTC' );

		/* translators: 1: first day, 2: last day. */
		return sprintf( __( '%1$s to %2$s', 'ai-seo-assistant' ), self::date( 'l j F', $start ), self::date( 'l j F Y', $end ) );
	}

	/**
	 * "17 Sep to 16 Oct".
	 *
	 * @param array<string,mixed> $m Month.
	 */
	public static function range_short( array $m ): string {
		/* translators: 1: first day, 2: last day. */
		return sprintf( __( '%1$s to %2$s', 'ai-seo-assistant' ), self::date( 'j M', (int) strtotime( $m['start'] . ' 12:00 UTC' ) ), self::date( 'j M', (int) strtotime( $m['end'] . ' 12:00 UTC' ) ) );
	}
}
