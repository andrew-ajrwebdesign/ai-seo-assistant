<?php
/**
 * Report_View — the Weekly report's markup, from a stored snapshot.
 *
 * Built from the approved Figma mockup (architecture map §12): a dark AJR header with the enquiries
 * headline and week navigation; Search Console as the first card (moved up from third, 2026-09-29);
 * enquiries by source beside the agency's note; Analytics and Google Ads side by side; a footer saying what the figures cover and that the site holds no Google
 * keys. Three states: a normal week, a LATE week (the Monday update did not arrive) and EMPTY (before the
 * first update).
 *
 * Semantics: the headline is the page's h1; each card is a <section> named by its h2; the note is an
 * <aside>; week navigation is a <nav>. Every change is written in words (Format), so colour is never the
 * only signal. Everything printed is escaped here, at output (text in the snapshot is plain text).
 *
 * A figure the snapshot does not carry is left out, not shown as 0 (PRODUCT.md principle 3).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Weekly report.
 */
class Report_View {

	/** A week counts as late this many days after its Monday update was due (matches Stale_Alert). */
	public const LATE_AFTER_DAYS = 8;

	/**
	 * The whole report for one week.
	 *
	 * @param array<string,mixed> $snap      A clean snapshot (Snapshot::clean()).
	 * @param array<string,mixed> $context   business (site name), agency, now (unix), prev_url, next_url,
	 *                                       latest (bool: this is the newest week), alerted (bool).
	 */
	public static function report( array $snap, array $context ): string {
		$html  = self::late_notice( $snap, $context );
		$html .= self::header( $snap, $context );
		// Search Console is the first card under the header (Andrew, 2026-09-29): it is the figure that
		// moves every week, on every site, even while enquiries are few.
		$html  .= self::search( $snap['gsc'] ?? null, $snap );
		$html  .= '<div class="aisa-row">' . self::enquiries( $snap ) . self::note( $snap['note'] ?? null, $context ) . '</div>';
		$visits = self::visits( $snap['ga4'] ?? null );
		$ads    = self::ads( $snap['ads'] ?? null );
		if ( '' !== $visits || '' !== $ads ) {
			$html .= '<div class="aisa-row aisa-row--even">' . $visits . $ads . '</div>';
		}
		$html .= self::footer( $snap );

		// An <article> scopes the report's own header and footer, so they are not read as a second
		// banner / contentinfo beside wp-admin's.
		return '<article class="aisa-report" aria-labelledby="aisa-headline">' . $html . '</article>';
	}

	/**
	 * Before the first update: what will appear, in the owner's words.
	 *
	 * @param array<string,mixed> $context business, agency.
	 */
	public static function empty_state( array $context ): string {
		$cards = [
			[ 'search', __( 'How people find you on Google', 'ai-seo-assistant' ), __( 'Clicks, how often you appeared, and the searches that brought people in, over 12 weeks.', 'ai-seo-assistant' ) ],
			[ 'phone', __( 'Every enquiry, from every source', 'ai-seo-assistant' ), __( 'Calls, form entries and online bookings added together, and compared with the week before.', 'ai-seo-assistant' ) ],
			[ 'chart-line', __( 'Visits to your website', 'ai-seo-assistant' ), __( 'How many people came, which pages they read, and how many got in touch.', 'ai-seo-assistant' ) ],
			[ 'megaphone', __( 'What your Google Ads did', 'ai-seo-assistant' ), __( 'What was spent, the clicks, and the enquiries Google Ads can see.', 'ai-seo-assistant' ) ],
			[ 'edit', __( 'A note each week', 'ai-seo-assistant' ), __( 'What we worked on that week and what comes next, in plain English.', 'ai-seo-assistant' ) ],
		];
		$list  = '';
		foreach ( $cards as [ $icon, $title, $text ] ) {
			$list .= '<li class="aisa-card aisa-card--preview">' . self::icon( $icon ) . '<h2 class="aisa-card__title">' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p></li>';
		}

		return '<article class="aisa-report aisa-report--empty" aria-labelledby="aisa-headline">'
			. '<header class="aisa-hero">' . self::brand( (string) $context['agency'] ) . '<h1 class="aisa-hero__headline" id="aisa-headline">' . esc_html__( 'Waiting for the first update', 'ai-seo-assistant' ) . '</h1>'
			. '<p class="aisa-hero__sub">' . esc_html( (string) $context['business'] ) . ' · ' . esc_html__( 'AI SEO Assistant is installed and ready. There is nothing for you to set up.', 'ai-seo-assistant' ) . '</p></header>'
			. '<ul class="aisa-previews">' . $list . '</ul>'
			/* translators: %s: agency name. */
			. '<footer class="aisa-foot">' . self::icon( 'lock' ) . '<p>' . esc_html( sprintf( __( 'Figures are sent to this site by %s each week. No Google passwords or keys are stored here.', 'ai-seo-assistant' ), (string) $context['agency'] ) ) . '</p></footer>'
			. '</article>';
	}

	/**
	 * Whether a week's report is late: its update was due the Monday after it ended.
	 *
	 * @param array<string,mixed> $snap Latest snapshot.
	 * @param int                 $now  Unix time.
	 */
	public static function is_late( array $snap, int $now ): bool {
		$due = strtotime( $snap['week']['end'] . ' +1 day 00:00:00 UTC' );

		return false !== $due && $now - $due > self::LATE_AFTER_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * The notice above a late report.
	 *
	 * @param array<string,mixed> $snap    Snapshot.
	 * @param array<string,mixed> $context Context.
	 */
	protected static function late_notice( array $snap, array $context ): string {
		if ( empty( $context['latest'] ) || ! self::is_late( $snap, (int) $context['now'] ) ) {
			return '';
		}
		$received = strtotime( $snap['week']['end'] . ' +1 day 00:00:00 UTC' );
		$days     = (int) floor( ( (int) $context['now'] - $received ) / DAY_IN_SECONDS );
		$missed   = [];
		for ( $monday = $received + WEEK_IN_SECONDS; $monday <= (int) $context['now']; $monday += WEEK_IN_SECONDS ) {
			$missed[] = self::date( 'l j F', $monday );
		}

		$body = sprintf(
			/* translators: 1: list of the Mondays whose update is missing, 2: the week the figures are from. */
			_n(
				'The update due on %1$s has not arrived, so everything below is from %2$s. Your website is working normally; only the report is late.',
				'The updates due on %1$s have not arrived, so everything below is from %2$s. Your website is working normally; only the report is late.',
				count( $missed ),
				'ai-seo-assistant'
			),
			wp_sprintf( '%l', $missed ),
			self::range( $snap['week'], false )
		);
		if ( ! empty( $context['alerted'] ) ) {
			/* translators: %s: agency name. */
			$body .= ' ' . sprintf( __( '%s has been sent an alert.', 'ai-seo-assistant' ), (string) $context['agency'] );
		}

		return '<div class="aisa-late" role="status">' . self::icon( 'warning' )
			/* translators: %d: number of days. */
			. '<div><p class="aisa-late__title">' . esc_html( sprintf( _n( 'These figures are %d day old', 'These figures are %d days old', $days, 'ai-seo-assistant' ), $days ) ) . '</p>'
			. '<p>' . esc_html( $body ) . '</p></div></div>';
	}

	/**
	 * The dark header: brand, freshness, headline, week, navigation.
	 *
	 * @param array<string,mixed> $snap    Snapshot.
	 * @param array<string,mixed> $context Context.
	 */
	protected static function header( array $snap, array $context ): string {
		$total    = 0;
		$previous = 0;
		foreach ( $snap['enquiries']['sources'] as $source ) {
			$total   += $source['count'];
			$previous = null === $source['previous'] || null === $previous ? null : $previous + $source['previous'];
		}
		$late  = ! empty( $context['latest'] ) && self::is_late( $snap, (int) $context['now'] );
		$stamp = $late
			/* translators: 1: date and time the figures arrived, 2: how long ago. */
			? sprintf( __( 'Last updated %1$s · %2$s ago', 'ai-seo-assistant' ), self::local( 'D j M', (int) $snap['generated_at'] ), human_time_diff( (int) $snap['generated_at'], (int) $context['now'] ) )
			/* translators: %s: date and time the figures arrived. */
			: sprintf( __( 'Updated %s', 'ai-seo-assistant' ), self::local( 'D j M, g:ia', (int) $snap['generated_at'] ) );

		$nav = '<nav class="aisa-weeks" aria-label="' . esc_attr__( 'Other weeks', 'ai-seo-assistant' ) . '">'
			. self::week_link( (string) ( $context['prev_url'] ?? '' ), __( 'Previous week', 'ai-seo-assistant' ), 'arrow-left-alt2', true )
			. self::week_link( (string) ( $context['next_url'] ?? '' ), __( 'Next week', 'ai-seo-assistant' ), 'arrow-right-alt2', false )
			. '<span class="aisa-weeks__note">' . esc_html__( 'Reports are kept for 12 months', 'ai-seo-assistant' ) . '</span></nav>';

		$sub = esc_html( (string) $context['business'] ) . ' · ' . esc_html( self::range( $snap['week'], true ) );
		if ( $late ) {
			$sub .= ' · ' . esc_html__( 'the latest figures received', 'ai-seo-assistant' );
		}

		return '<header class="aisa-hero' . ( $late ? ' aisa-hero--late' : '' ) . '">'
			. '<div class="aisa-hero__top">' . self::brand( (string) $context['agency'] )
			. '<p class="aisa-fresh' . ( $late ? ' aisa-fresh--late' : '' ) . '"><span class="aisa-fresh__dot" aria-hidden="true"></span>' . esc_html( $stamp ) . '</p></div>'
			. '<h1 class="aisa-hero__headline" id="aisa-headline">' . esc_html( [] === $snap['enquiries']['sources'] ? __( 'Your website this week', 'ai-seo-assistant' ) : Format::headline( $total, $previous ) ) . '</h1>'
			. '<p class="aisa-hero__sub">' . $sub . '</p>'
			. $nav . '</header>';
	}

	/**
	 * Enquiries by source, with the 12-week trend.
	 *
	 * @param array<string,mixed> $snap Snapshot.
	 */
	protected static function enquiries( array $snap ): string {
		$sources = $snap['enquiries']['sources'];
		$title   = __( 'Where this week’s enquiries came from', 'ai-seo-assistant' );
		if ( [] === $sources ) {
			// Nothing counts enquiries yet (no form or call tracking). Say so; never print a 0 nobody measured.
			$note = '' !== $snap['enquiries']['note'] ? '<p class="aisa-small">' . esc_html( $snap['enquiries']['note'] ) . '</p>' : '';
			return '<section class="aisa-card aisa-card--grow aisa-card--pending" aria-labelledby="aisa-enquiries">'
				. self::card_head( 'aisa-enquiries', 'phone', $title, __( 'Not counted yet', 'ai-seo-assistant' ) )
				. '<p class="aisa-pending">' . esc_html__( 'Enquiries are not being counted yet. Once form and call tracking is set up, every enquiry will appear here each week, by where it came from.', 'ai-seo-assistant' ) . '</p>'
				. $note . '</section>';
		}
		$max   = max( 1, max( array_column( $sources, 'count' ) ) );
		$rows  = '';
		$total = 0;
		$prev  = 0;
		foreach ( $sources as $s ) {
			$total += $s['count'];
			$prev   = null === $s['previous'] || null === $prev ? null : $prev + $s['previous'];
			$rows  .= '<li class="aisa-source"><div class="aisa-source__line"><span class="aisa-source__label">' . esc_html( $s['label'] ) . '</span>'
				. '<span class="aisa-source__count">' . esc_html( number_format_i18n( $s['count'] ) ) . '</span>'
				. self::change_html( Format::change( $s['count'], $s['previous'] ) ) . '</div>'
				. '<span class="aisa-meter" aria-hidden="true"><span class="aisa-meter__value" style="inline-size:' . esc_attr( (string) round( $s['count'] / $max * 100, 1 ) ) . '%"></span></span></li>';
		}
		$rows .= '<li class="aisa-source aisa-source--total"><div class="aisa-source__line"><span class="aisa-source__label">' . esc_html__( 'Total', 'ai-seo-assistant' ) . '</span>'
			. '<span class="aisa-source__count">' . esc_html( number_format_i18n( $total ) ) . '</span>' . self::change_html( Format::change( $total, $prev ) ) . '</div></li>';

		$series = $snap['enquiries']['series_12w'];
		$figure = '';
		if ( count( $series ) >= 2 ) {
			$caption = __( 'Enquiries, last 12 weeks', 'ai-seo-assistant' );
			$figure  = '<figure class="aisa-figure"><figcaption>' . esc_html( $caption ) . '</figcaption>'
				. Chart::bars( $series, self::week_labels( $snap['week']['start'], count( $series ) ), $caption ) . '</figure>';
		}
		$note = '' !== $snap['enquiries']['note'] ? '<p class="aisa-small">' . esc_html( $snap['enquiries']['note'] ) . '</p>' : '';

		return '<section class="aisa-card aisa-card--grow" aria-labelledby="aisa-enquiries">'
			. self::card_head( 'aisa-enquiries', 'phone', $title, __( 'All sources, not just Google', 'ai-seo-assistant' ) )
			. '<div class="aisa-split"><ul class="aisa-sources">' . $rows . '</ul>' . $figure . '</div>' . $note . '</section>';
	}

	/**
	 * The agency's note.
	 *
	 * @param array<string,mixed>|null $note    Note.
	 * @param array<string,mixed>      $context Context.
	 */
	protected static function note( ?array $note, array $context ): string {
		if ( null === $note ) {
			return '';
		}
		$author = '' !== $note['author'] ? $note['author'] : (string) $context['agency'];
		// A person is named by first name; with no author the whole agency name is used, never a first word of it.
		$first = '' !== $note['author'] ? strtok( $author, ' ' ) : $author;
		$lists = '';
		foreach ( [
			'did'  => __( 'What we did this week', 'ai-seo-assistant' ),
			'next' => __( 'What’s next', 'ai-seo-assistant' ),
		] as $key => $heading ) {
			if ( [] === $note[ $key ] ) {
				continue;
			}
			$items = '';
			foreach ( $note[ $key ] as $line ) {
				$items .= '<li>' . esc_html( $line ) . '</li>';
			}
			$lists .= '<h3 class="aisa-note__h">' . esc_html( $heading ) . '</h3><ul class="aisa-note__list">' . $items . '</ul>';
		}
		$initials = strtoupper( implode( '', array_map( static fn( $w ) => mb_substr( $w, 0, 1 ), array_slice( preg_split( '/\s+/', $author ), 0, 2 ) ) ) );
		$signed   = (string) $context['agency'] . ( '' !== $note['date'] ? ' · ' . self::date( 'D j M', (int) strtotime( $note['date'] . ' 12:00 UTC' ) ) : '' );

		return '<aside class="aisa-card aisa-note" aria-labelledby="aisa-note">'
			/* translators: %s: first name of the note's author. */
			. '<h2 class="aisa-note__title" id="aisa-note">' . self::icon( 'edit' ) . esc_html( sprintf( __( 'A note from %s', 'ai-seo-assistant' ), (string) $first ) ) . '</h2>'
			. $lists
			. '<p class="aisa-sign"><span class="aisa-sign__mark" aria-hidden="true">' . esc_html( $initials ) . '</span><span><strong>' . esc_html( $author ) . '</strong><br>' . esc_html( $signed ) . '</span></p>'
			. '</aside>';
	}

	/**
	 * Search Console.
	 *
	 * @param array<string,mixed>|null $gsc  GSC block.
	 * @param array<string,mixed>      $snap Snapshot (for week labels).
	 */
	protected static function search( ?array $gsc, array $snap ): string {
		if ( null === $gsc ) {
			return '';
		}
		$w     = $gsc['week'];
		$tiles = self::tile( __( 'Clicks from Google', 'ai-seo-assistant' ), $w['clicks'], Format::PERCENT, 'clicks' )
			. self::tile( __( 'Times you appeared', 'ai-seo-assistant' ), $w['impressions'], Format::PERCENT, 'shown' )
			. self::tile( __( 'Click rate', 'ai-seo-assistant' ), $w['ctr'], Format::POINTS, '', '%' )
			. self::tile( __( 'Average position', 'ai-seo-assistant' ), $w['position'], Format::PLACES );

		$chart = '';
		if ( count( $gsc['clicks_12w'] ) >= 2 ) {
			$title = __( 'Clicks and times you appeared, last 12 weeks', 'ai-seo-assistant' );
			$chart = '<figure class="aisa-figure aisa-figure--wide">'
				. Chart::lines( $gsc['clicks_12w'], count( $gsc['impressions_12w'] ) === count( $gsc['clicks_12w'] ) ? $gsc['impressions_12w'] : [], self::week_labels( $snap['week']['start'], count( $gsc['clicks_12w'] ) ), $title )
				. '<figcaption class="aisa-small">' . esc_html__( 'Solid line: clicks (left scale) · Dashed line: times you appeared (right scale)', 'ai-seo-assistant' ) . '</figcaption></figure>';
		}

		$table = '';
		if ( [] !== $gsc['top_queries'] ) {
			$rows = '';
			foreach ( $gsc['top_queries'] as $q ) {
				$change = null === $q['change'] ? [
					'text' => '',
					'tone' => 'flat',
				] : Format::change( $q['clicks'], $q['clicks'] - $q['change'] );
				$rows  .= '<tr><th scope="row">' . esc_html( $q['query'] ) . '</th><td class="aisa-num aisa-strong">' . esc_html( number_format_i18n( $q['clicks'] ) ) . '</td><td class="aisa-num aisa-tone--' . esc_attr( $change['tone'] ) . '">' . esc_html( null === $q['change'] ? '' : ( $q['change'] > 0 ? '+' : ( $q['change'] < 0 ? "\u{2212}" : '' ) ) . number_format_i18n( abs( $q['change'] ) ) ) . '</td></tr>';
			}
			$table = '<div class="aisa-tablewrap"><table class="aisa-table"><caption>' . esc_html__( 'Top searches that brought clicks', 'ai-seo-assistant' ) . '</caption>'
				. '<thead><tr><th scope="col">' . esc_html__( 'Search', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Clicks', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Change', 'ai-seo-assistant' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		}

		return '<section class="aisa-card" aria-labelledby="aisa-search">'
			. self::card_head( 'aisa-search', 'search', __( 'How people found you on Google', 'ai-seo-assistant' ), __( 'Google Search Console · last 12 weeks', 'ai-seo-assistant' ) )
			. '<div class="aisa-tiles aisa-tiles--search">' . $tiles . '</div>'
			. ( '' !== $chart || '' !== $table ? '<div class="aisa-split aisa-split--chart">' . $chart . $table . '</div>' : '' )
			. '</section>';
	}

	/**
	 * Google Analytics.
	 *
	 * @param array<string,mixed>|null $ga4 GA4 block.
	 */
	protected static function visits( ?array $ga4 ): string {
		if ( null === $ga4 ) {
			return '';
		}
		$w     = $ga4['week'];
		$tiles = self::tile( __( 'Visits', 'ai-seo-assistant' ), $w['visits'], Format::PERCENT )
			. self::tile( __( 'People who got in touch', 'ai-seo-assistant' ), $w['key_events'], Format::COUNT )
			. self::tile( __( 'Stayed and read', 'ai-seo-assistant' ), $w['engaged'], Format::POINTS, '', '%' );
		$list  = '';
		foreach ( $ga4['top_pages'] as $p ) {
			/* translators: %s: number of visits. */
			$list .= '<li><span class="aisa-page">' . esc_html( $p['title'] ) . '</span><span class="aisa-num">' . esc_html( sprintf( _n( '%s visit', '%s visits', $p['visits'], 'ai-seo-assistant' ), number_format_i18n( $p['visits'] ) ) ) . '</span></li>';
		}

		return '<section class="aisa-card" aria-labelledby="aisa-visits">'
			. self::card_head( 'aisa-visits', 'chart-line', __( 'Visits to your website', 'ai-seo-assistant' ), __( 'Google Analytics', 'ai-seo-assistant' ) )
			. '<div class="aisa-tiles aisa-tiles--plain">' . $tiles . '</div>'
			. ( '' !== $list ? '<h3 class="aisa-card__sub">' . esc_html__( 'Most visited pages', 'ai-seo-assistant' ) . '</h3><ul class="aisa-pages">' . $list . '</ul>' : '' )
			. '</section>';
	}

	/**
	 * Google Ads.
	 *
	 * @param array<string,mixed>|null $ads Ads block.
	 */
	protected static function ads( ?array $ads ): string {
		if ( null === $ads ) {
			return '';
		}
		$cur   = $ads['currency'];
		$spend = $ads['spend'];
		$conv  = $ads['conversions'];
		$tiles = '';
		if ( null !== $spend ) {
			/* translators: %s: weekly budget. */
			$budget_text = null !== $ads['budget'] ? sprintf( __( 'budget %s a week', 'ai-seo-assistant' ), Format::money( $ads['budget'], $cur ) ) : '';
			$tiles      .= '<div class="aisa-metric"><p class="aisa-metric__label">' . esc_html__( 'Spent', 'ai-seo-assistant' ) . '</p><p class="aisa-metric__value">' . esc_html( Format::money( $spend['value'], $cur ) ) . '</p><p class="aisa-metric__change aisa-tone--flat">' . esc_html( $budget_text ) . '</p></div>';
		}
		$tiles .= self::tile( __( 'Clicks on your ads', 'ai-seo-assistant' ), $ads['clicks'], Format::PERCENT );
		$tiles .= self::tile( __( 'Enquiries Google Ads can see', 'ai-seo-assistant' ), $conv, Format::COUNT );
		if ( null !== $spend && null !== $conv && $conv['value'] > 0 ) {
			$cpe      = $spend['value'] / $conv['value'];
			$prev_cpe = null !== $spend['previous'] && null !== $conv['previous'] && $conv['previous'] > 0 ? $spend['previous'] / $conv['previous'] : null;
			$change   = Format::change( round( $cpe ), null === $prev_cpe ? null : round( $prev_cpe ), Format::COST, $cur );
			$tiles   .= '<div class="aisa-metric"><p class="aisa-metric__label">' . esc_html__( 'Cost per enquiry', 'ai-seo-assistant' ) . '</p><p class="aisa-metric__value">' . esc_html( Format::money( $cpe, $cur ) ) . '</p>' . self::change_html( $change, 'aisa-metric__change' ) . '</div>';
		}

		$budget = '';
		if ( null !== $spend && null !== $ads['budget'] ) {
			$pct    = (int) round( $spend['value'] / $ads['budget'] * 100 );
			$budget = '<div class="aisa-budget"><p class="aisa-budget__line"><span>' . esc_html__( 'Weekly budget used', 'ai-seo-assistant' ) . '</span><strong>' . esc_html( $pct . '%' ) . '</strong></p>'
				. '<span class="aisa-meter aisa-meter--budget" aria-hidden="true"><span class="aisa-meter__value" style="inline-size:' . esc_attr( (string) min( 100, $pct ) ) . '%"></span></span></div>';
		}

		return '<section class="aisa-card" aria-labelledby="aisa-ads">'
			. self::card_head( 'aisa-ads', 'megaphone', __( 'Your Google Ads', 'ai-seo-assistant' ), __( 'Google Ads', 'ai-seo-assistant' ) )
			. '<div class="aisa-tiles aisa-tiles--plain aisa-tiles--two">' . $tiles . '</div>' . $budget
			. '<p class="aisa-small">' . esc_html__( 'Google Ads can only count people who clicked an ad and then called or used the form. Your full enquiry count is at the top of this page.', 'ai-seo-assistant' ) . '</p>'
			. '</section>';
	}

	/**
	 * The footer line.
	 *
	 * @param array<string,mixed> $snap Snapshot.
	 */
	protected static function footer( array $snap ): string {
		return '<footer class="aisa-foot">' . self::icon( 'lock' ) . '<p>'
			/* translators: %s: the week, e.g. "Monday 21 to Sunday 27 September 2026". */
			. esc_html( sprintf( __( 'Figures cover %s. Sent weekly; this site stores no Google passwords or keys.', 'ai-seo-assistant' ), self::range( $snap['week'], true ) ) )
			. '</p></footer>';
	}

	/**
	 * A headline figure with its change.
	 *
	 * @param string                   $label  Label.
	 * @param array<string,mixed>|null $metric {value, previous}.
	 * @param string                   $kind   Format kind.
	 * @param string                   $series 'clicks' / 'shown' when the tile doubles as the chart key.
	 * @param string                   $suffix Unit after the value ('%').
	 */
	protected static function tile( string $label, ?array $metric, string $kind, string $series = '', string $suffix = '' ): string {
		if ( null === $metric ) {
			return '';
		}
		$value = Format::PLACES === $kind || '%' === $suffix ? number_format_i18n( (float) $metric['value'], floor( (float) $metric['value'] ) === (float) $metric['value'] ? 0 : 1 ) : Format::short( $metric['value'] );

		return '<div class="aisa-metric' . ( '' !== $series ? ' aisa-metric--key aisa-metric--' . esc_attr( $series ) : '' ) . '">'
			. '<p class="aisa-metric__label">' . esc_html( $label ) . '</p>'
			. '<p class="aisa-metric__value">' . esc_html( $value . $suffix ) . '</p>'
			. self::change_html( Format::change( $metric['value'], $metric['previous'], $kind ), 'aisa-metric__change' ) . '</div>';
	}

	/**
	 * A change, styled by tone ('' when there is nothing to compare).
	 *
	 * @param array{text:string,tone:string} $change Change.
	 * @param string                         $css    Extra class.
	 */
	protected static function change_html( array $change, string $css = 'aisa-source__change' ): string {
		return '<span class="' . esc_attr( $css . ' aisa-tone--' . $change['tone'] ) . '">' . esc_html( $change['text'] ) . '</span>';
	}

	/**
	 * A card's heading row.
	 *
	 * @param string $id     Heading id.
	 * @param string $icon   Dashicon name.
	 * @param string $title  Title.
	 * @param string $source Where the figures come from.
	 */
	protected static function card_head( string $id, string $icon, string $title, string $source ): string {
		return '<div class="aisa-card__head"><h2 class="aisa-card__title" id="' . esc_attr( $id ) . '">' . self::icon( $icon ) . esc_html( $title ) . '</h2>'
			. '<p class="aisa-card__source">' . esc_html( $source ) . '</p></div>';
	}

	/**
	 * The AJR mark and name.
	 *
	 * @param string $agency Agency name.
	 */
	protected static function brand( string $agency ): string {
		return '<p class="aisa-brand"><span class="aisa-brand__mark" aria-hidden="true">' . self::icon( 'chart-line' ) . '</span><span><strong>' . esc_html__( 'AI SEO Assistant', 'ai-seo-assistant' ) . '</strong><br>'
			/* translators: %s: agency name. */
			. esc_html( sprintf( __( 'Weekly report by %s', 'ai-seo-assistant' ), $agency ) ) . '</span></p>';
	}

	/**
	 * A previous/next week control: a link, or a disabled stand-in at either end.
	 *
	 * @param string $url    URL, or '' when there is no such week.
	 * @param string $label  Label.
	 * @param string $icon   Dashicon.
	 * @param bool   $before Icon before the label.
	 */
	protected static function week_link( string $url, string $label, string $icon, bool $before ): string {
		$inner = $before ? self::icon( $icon ) . esc_html( $label ) : esc_html( $label ) . self::icon( $icon );

		return '' === $url
			? '<span class="aisa-weekbtn" role="link" aria-disabled="true">' . $inner . '</span>'
			: '<a class="aisa-weekbtn" href="' . esc_url( $url ) . '">' . $inner . '</a>';
	}

	/**
	 * A Dashicon (decorative).
	 *
	 * @param string $name Dashicon name without the prefix.
	 */
	protected static function icon( string $name ): string {
		return '<span class="aisa-icon dashicons dashicons-' . esc_attr( $name ) . '" aria-hidden="true"></span>';
	}

	/**
	 * "Monday 21 to Sunday 27 September 2026" (or without weekdays).
	 *
	 * @param array{start:string,end:string} $week     Week.
	 * @param bool                           $weekdays Include the day names.
	 */
	public static function range( array $week, bool $weekdays ): string {
		$start = (int) strtotime( $week['start'] . ' 12:00 UTC' );
		$end   = (int) strtotime( $week['end'] . ' 12:00 UTC' );
		$same  = self::date( 'n', $start ) === self::date( 'n', $end );
		$from  = self::date( $weekdays ? ( $same ? 'l j' : 'l j F' ) : ( $same ? 'j' : 'j F' ), $start );
		$to    = self::date( $weekdays ? 'l j F Y' : 'j F', $end );

		/* translators: 1: first day, 2: last day. */
		return sprintf( __( '%1$s to %2$s', 'ai-seo-assistant' ), $from, $to );
	}

	/**
	 * Labels for the weeks of a 12-week series ending with this week ("6 Jul" … "21 Sep").
	 *
	 * @param string $start This week's Monday.
	 * @param int    $count Points.
	 * @return array<int,string>
	 */
	public static function week_labels( string $start, int $count ): array {
		$monday = (int) strtotime( $start . ' 12:00 UTC' );
		$labels = [];
		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$labels[] = self::date( 'j M', $monday - $i * WEEK_IN_SECONDS );
		}

		return $labels;
	}

	/**
	 * A translated moment in the site's own timezone (when a report arrived).
	 *
	 * @param string $format PHP date format.
	 * @param int    $time   Unix time.
	 */
	protected static function local( string $format, int $time ): string {
		return (string) wp_date( $format, $time );
	}

	/**
	 * A translated date. Week dates are calendar dates, so they are shown as UTC (no shift).
	 *
	 * @param string $format PHP date format.
	 * @param int    $time   Unix time.
	 */
	protected static function date( string $format, int $time ): string {
		return (string) wp_date( $format, $time, new \DateTimeZone( 'UTC' ) );
	}
}
