<?php
/**
 * Scan_Page — AI SEO Assistant → SEO scan (mockup B1–B3) and its page review (C1–C3).
 *
 * The list: every published page, issues found by the scan, ranked by the clicks it could win once the
 * push has brought per-page search data (B1); the same with the spend cap reached (B2); and, before the
 * first push, the pages with issues and the three steps still to come (B3). The review (?post=ID): the
 * page's searches, trend, Analytics and issues on the left; Claude's suggestions with Accept / Edit /
 * Skip, alt text per image and "Do in the editor" advice on the right; after Apply, what changed with Undo.
 *
 * Agency only (Access::TOOLS_CAP). Writes go through admin-post.php (apply, undo, rescan) and admin-ajax
 * (scan steps, generate) in Tools_Actions, each with a nonce and the capability check.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\AI\Claude_Client;
use AJR\SEOAssistant\AI\Spend;
use AJR\SEOAssistant\Changes\Change_Log;
use AJR\SEOAssistant\Report\Access;
use AJR\SEOAssistant\Report\Chart;
use AJR\SEOAssistant\Review\Page_Review;
use AJR\SEOAssistant\Scan\Opportunity;
use AJR\SEOAssistant\Scan\Ranking;
use AJR\SEOAssistant\Scan\Rules;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Scan\Scanner;
use AJR\SEOAssistant\Scan\Scheduler;
use AJR\SEOAssistant\Scan\Title_Width;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * The SEO scan screen.
 */
class Scan_Page {

	/** Menu slug. */
	public const SLUG = 'ai-seo-assistant-scan';

	/** Rows per page of the list. */
	public const PER_PAGE = 50;

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only choice of page.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		echo '<div class="wrap aisa-wrap"><hr class="wp-header-end"><div class="aisa-tool">';
		if ( $post_id > 0 ) {
			$this->review( $post_id );
		} else {
			$this->overview();
		}
		echo '</div></div>';
	}

	/* ==== The list (B1–B3) ======================================================================== */

	/**
	 * The scan overview.
	 */
	protected function overview(): void {
		$meta   = Scan_Store::meta();
		$rows   = Ranking::rows();
		$ranked = Page_Data::has_data();
		$queue  = Scheduler::queue();
		$pages  = count( $rows );

		if ( [] === $rows ) {
			$sub = __( 'No scan yet. The first scan checks every published page as Google sees it; it takes a few minutes and runs in steps.', 'ai-seo-assistant' );
		} elseif ( $ranked ) {
			/* translators: 1: page count, 2: date and time of the scan. */
			$sub = sprintf( __( '%1$d published pages checked %2$s. Pages are ranked by how many extra clicks they could win.', 'ai-seo-assistant' ), $pages, wp_date( 'D j M, g:ia', (int) ( $meta['finished_at'] ?? time() ) ) );
		} else {
			/* translators: 1: page count, 2: date and time of the scan. */
			$sub = sprintf( __( '%1$d published pages checked %2$s. Search and visitor data have not arrived yet, so pages are not ranked.', 'ai-seo-assistant' ), $pages, wp_date( 'D j M, g:ia', (int) ( $meta['finished_at'] ?? time() ) ) );
		}

		$actions = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aisa-inline" data-aisa-scan>'
			. wp_nonce_field( Tools_Actions::RESCAN, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::RESCAN ) . '">'
			. '<button type="submit" class="aisa-btn aisa-btn--dark">' . Ui::icon( 'update' ) . esc_html__( 'Rescan now', 'ai-seo-assistant' ) . '</button></form>'
			. '<span class="aisa-hero__note" data-aisa-scan-status aria-live="polite">' . esc_html(
				null !== $queue
					/* translators: 1: pages done, 2: pages in all. */
					? sprintf( __( 'Scanning: %1$d of %2$d pages', 'ai-seo-assistant' ), (int) $queue['done'], (int) $queue['total'] )
					: __( 'Also runs on each page when it is saved', 'ai-seo-assistant' )
			) . '</span>';

		echo Ui::hero( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			[
				/* translators: %s: agency name. */
				'label'   => sprintf( __( 'SEO scan by %s', 'ai-seo-assistant' ), Ui::agency() ),
				'title'   => __( 'SEO scan', 'ai-seo-assistant' ),
				'sub'     => $sub,
				'actions' => $actions,
			]
		);
		$this->result_notice();

		$spend = Spend::current();
		$cap   = Spend::cap();
		if ( ! Spend::allows( $spend['usd'], $cap, 'review' ) ) {
			echo Ui::notice( 'warning', '<p><strong>' . esc_html( Spend::cap_message( $spend ) ) . '</strong></p><p><a href="' . esc_url( admin_url( 'admin.php?page=' . Settings_Page::SLUG . '#aisa-cap' ) ) . '">' . esc_html__( 'Raise this site’s cap in Settings', 'ai-seo-assistant' ) . '</a></p>', 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		}

		if ( [] === $rows ) {
			echo '</div>' . Ui::footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			return;
		}

		echo '<div class="aisa-row">';
		$this->issues_card( $rows, $meta );
		$this->spend_card( $spend, $cap );
		echo '</div>';

		if ( $ranked ) {
			$this->ranked_list( $rows );
		} else {
			$this->first_run( $rows );
		}
		$this->listing_card();
		echo Ui::footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
	}

	/**
	 * "Google listing": the pushed Business Profile check, Google's listing against the site's details.
	 */
	protected function listing_card(): void {
		$listing = get_option( \AJR\SEOAssistant\Report\Snapshot_Store::LISTING, null );
		if ( ! is_array( $listing ) ) {
			return;
		}
		echo '<section class="aisa-card" id="aisa-listing" aria-labelledby="aisa-listing-h">';
		$when = (int) ( $listing['checked_at'] ?? 0 );
		/* translators: %s: date. */
		echo Ui::card_head( 'aisa-listing-h', 'location', __( 'Google listing', 'ai-seo-assistant' ), $when > 0 ? sprintf( __( 'Business Profile against this site · checked %s', 'ai-seo-assistant' ), wp_date( 'D j M', $when ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		if ( empty( $listing['checked'] ) ) {
			echo '<p class="aisa-pending">' . esc_html__( 'Google listing not checked: the push could not read the Business Profile this time. Nothing is known about whether it matches.', 'ai-seo-assistant' ) . '</p></section>';
			return;
		}
		$rows = array_filter( (array) $listing['fields'], static fn( $f ) => 'match' !== $f['status'] && 'not_compared' !== $f['status'] );
		if ( [] === $rows ) {
			echo '<p>' . esc_html__( 'Every compared detail matches between Google and this site.', 'ai-seo-assistant' ) . '</p></section>';
			return;
		}
		$where_label = [
			'site'           => __( 'Fix on the site', 'ai-seo-assistant' ),
			'google'         => __( 'Fix on Google', 'ai-seo-assistant' ),
			'site_or_google' => __( 'Fix on the site or on Google', 'ai-seo-assistant' ),
		];
		$tone        = [
			'high'   => 'bad',
			'medium' => 'warn',
			'low'    => 'muted',
			'info'   => 'muted',
		];
		echo '<ul class="aisa-issues">';
		foreach ( $rows as $f ) {
			$where = (string) ( $f['fix']['where'] ?? '' );
			$fix   = '';
			if ( '' !== $where ) {
				$fix = '<p class="aisa-issue__fix">' . esc_html( $where_label[ $where ] ?? '' );
				if ( '' !== (string) ( $f['fix']['site'] ?? '' ) ) {
					$fix .= ': ' . esc_html( (string) $f['fix']['site'] );
				}
				if ( '' !== (string) ( $f['fix']['google'] ?? '' ) ) {
					$fix .= ' ' . esc_html( (string) $f['fix']['google'] );
				}
				if ( false !== strpos( $where, 'site' ) ) {
					$fix .= ' <a href="' . esc_url( admin_url( 'admin.php?page=ajr-core' ) ) . '">' . esc_html__( 'AJR Core › Business details', 'ai-seo-assistant' ) . '</a>';
				}
				$fix .= '</p>';
			}
			echo '<li class="aisa-issue"><p class="aisa-issue__head"><span class="aisa-tag">' . esc_html( ucfirst( str_replace( '_', ' ', (string) $f['field'] ) ) ) . '</span><strong>' . esc_html( (string) $f['message'] ) . '</strong>' . Ui::pill( ucfirst( (string) $f['severity'] ), $tone[ $f['severity'] ] ?? 'muted' ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill escaped in Ui.
				/* translators: 1: value on the site, 2: value on Google. */
				. '<p class="aisa-issue__detail">' . esc_html( sprintf( __( 'Site: %1$s · Google: %2$s', 'ai-seo-assistant' ), '' !== $f['site'] ? $f['site'] : '–', '' !== $f['google'] ? $f['google'] : '–' ) ) . '</p>'
				. ( '' !== (string) $f['detail'] ? '<p class="aisa-issue__detail">' . esc_html( (string) $f['detail'] ) . '</p>' : '' )
				. $fix . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '</ul>';
		if ( '' !== (string) $listing['maps_url'] ) {
			echo '<p class="aisa-small"><a href="' . esc_url( (string) $listing['maps_url'] ) . '" rel="noopener noreferrer" target="_blank">' . esc_html__( 'See the listing on Google Maps', 'ai-seo-assistant' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ai-seo-assistant' ) . '</span></a></p>';
		}
		echo '</section>';
	}

	/**
	 * A result code from our own redirect.
	 */
	protected function result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result code from our own redirect.
		$code     = isset( $_GET['aisa'] ) ? sanitize_key( wp_unslash( $_GET['aisa'] ) ) : '';
		$messages = [
			'rescan'   => [ 'info', __( 'Rescan started. It runs in the background in steps; this screen updates when you reload it.', 'ai-seo-assistant' ) ],
			'kept'     => [ 'warning', __( 'Some fields were not undone: they were changed again after the plugin applied them, and undo never overwrites later work.', 'ai-seo-assistant' ) ],
			'undone'   => [ 'success', __( 'Undone: the earlier values are back.', 'ai-seo-assistant' ) ],
			'nothing'  => [ 'info', __( 'Nothing was applied: every field was skipped or already had that value.', 'ai-seo-assistant' ) ],
			'genfail'  => [ 'error', __( 'Claude could not write suggestions for this page. Try again in a minute.', 'ai-seo-assistant' ) ],
			'capped'   => [ 'warning', __( 'The monthly AI cap is reached, so no new suggestions were written.', 'ai-seo-assistant' ) ],
		];
		if ( isset( $messages[ $code ] ) ) {
			echo Ui::notice( $messages[ $code ][0], '<p>' . esc_html( $messages[ $code ][1] ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		}
	}

	/**
	 * "Issues found" with a chip per kind.
	 *
	 * @param array<int,array<string,mixed>> $rows Ranked rows.
	 * @param array<string,mixed>            $meta Scan meta.
	 */
	protected function issues_card( array $rows, array $meta ): void {
		$counts = array_fill_keys( array_keys( Rules::kinds() ), 0 );
		$total  = 0;
		foreach ( $rows as $row ) {
			foreach ( $row['kinds'] as $kind => $n ) {
				$counts[ $kind ] = ( $counts[ $kind ] ?? 0 ) + $n;
				$total          += $n;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$current = isset( $_GET['issue'] ) ? sanitize_key( wp_unslash( $_GET['issue'] ) ) : '';
		$chips   = '<li><a class="aisa-chip' . ( '' === $current ? ' is-current' : '' ) . '" href="' . esc_url( $this->url( [] ) ) . '"' . ( '' === $current ? ' aria-current="true"' : '' ) . '>' . esc_html__( 'All issues', 'ai-seo-assistant' ) . ' <span>' . esc_html( number_format_i18n( $total ) ) . '</span></a></li>';
		foreach ( Rules::kinds() as $kind => $label ) {
			if ( 0 === $counts[ $kind ] ) {
				continue;
			}
			$chips .= '<li><a class="aisa-chip' . ( $kind === $current ? ' is-current' : '' ) . '" href="' . esc_url( $this->url( [ 'issue' => $kind ] ) ) . '"' . ( $kind === $current ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . ' <span>' . esc_html( number_format_i18n( $counts[ $kind ] ) ) . '</span></a></li>';
		}
		$fallback = (int) ( $meta['fallback'] ?? 0 );
		$listing  = get_option( \AJR\SEOAssistant\Report\Snapshot_Store::LISTING, null );
		if ( is_array( $listing ) && ! empty( $listing['checked'] ) && (int) $listing['problems'] > 0 ) {
			$total += (int) $listing['problems'];
			$chips .= '<li><a class="aisa-chip" href="#aisa-listing">' . esc_html__( 'Google listing', 'ai-seo-assistant' ) . ' <span>' . esc_html( number_format_i18n( (int) $listing['problems'] ) ) . '</span></a></li>';
			$chips  = (string) preg_replace( '#(All issues <span>)[^<]*#', '${1}' . esc_html( number_format_i18n( $total ) ), $chips, 1 );
		}

		echo '<section class="aisa-card aisa-card--grow" aria-labelledby="aisa-issues">';
		/* translators: 1: issue count, 2: page count, 3: scan date. */
		echo Ui::card_head( 'aisa-issues', 'search', __( 'Issues found', 'ai-seo-assistant' ), sprintf( __( '%1$s issues on %2$s pages · scanned %3$s', 'ai-seo-assistant' ), number_format_i18n( $total ), number_format_i18n( count( $rows ) ), wp_date( 'D j M', (int) ( $meta['finished_at'] ?? time() ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<ul class="aisa-chips">' . $chips . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<p class="aisa-small">' . esc_html__( 'Checked against the rendered page: title width in pixels, duplicates, headings, image alt text and weight, internal links in and out, broken links and links through redirects, noindex and canonical against the sitemap, schema for the page type, sharing image, word count.', 'ai-seo-assistant' ) . '</p>';
		if ( $fallback > 0 ) {
			/* translators: %d: number of pages. */
			echo '<p class="aisa-small aisa-tone--bad">' . esc_html( sprintf( _n( '%d page could not be loaded as Google sees it, so it was checked from its post content and SEO fields; it is marked in the list.', '%d pages could not be loaded as Google sees them, so they were checked from their post content and SEO fields; they are marked in the list.', $fallback, 'ai-seo-assistant' ), $fallback ) ) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * "Claude spend" with the cap meter.
	 *
	 * @param array<string,mixed> $spend Spend::current().
	 * @param float               $cap   Cap.
	 */
	protected function spend_card( array $spend, float $cap ): void {
		$used   = (float) $spend['usd'];
		$pct    = $cap > 0 ? min( 100, $used / $cap * 100 ) : 100;
		$capped = ! Spend::allows( $used, $cap, 'review' );
		$per    = Page_Review::estimate( [], ( new Claude_Client() )->get_model() );
		$reset  = wp_date( 'j M', $spend['end']->getTimestamp(), $spend['end']->getTimezone() );
		if ( $capped ) {
			/* translators: 1: date, 2: calls made, 3: reset date. */
			$line = sprintf( __( 'Cap reached %1$s after %2$d suggestions. Paused until %3$s.', 'ai-seo-assistant' ), wp_date( 'D j M', $spend['capped_at'] > 0 ? (int) $spend['capped_at'] : time() ), (int) $spend['calls'], $reset );
		} elseif ( 0 === (int) $spend['calls'] ) {
			/* translators: 1: estimated cost per page, 2: reset date. */
			$line = sprintf( __( 'Nothing spent yet. About %1$s a page; resets %2$s.', 'ai-seo-assistant' ), Spend::money( $per ), $reset );
		} else {
			/* translators: 1: number of calls, 2: average per call, 3: reset date. */
			$line = sprintf( __( '%1$d calls to Claude · about %2$s each · resets %3$s', 'ai-seo-assistant' ), (int) $spend['calls'], Spend::money( $used / max( 1, (int) $spend['calls'] ) ), $reset );
		}

		echo '<section class="aisa-card" aria-labelledby="aisa-spend">';
		echo Ui::card_head( 'aisa-spend', 'money-alt', __( 'Claude spend', 'ai-seo-assistant' ), __( 'This billing month', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		/* translators: %s: the cap. */
		echo '<p class="aisa-spend"><span class="aisa-spend__value">' . esc_html( Spend::money( $used ) ) . '</span> <span>' . esc_html( sprintf( __( 'of %s cap for this site', 'ai-seo-assistant' ), Spend::money( $cap ) ) ) . '</span></p>';
		echo '<span class="aisa-meter aisa-meter--spend' . ( $capped ? ' is-capped' : '' ) . '" role="img" aria-label="' . esc_attr( sprintf( /* translators: %d: percent. */ __( '%d%% of the cap used', 'ai-seo-assistant' ), (int) round( $pct ) ) ) . '"><span class="aisa-meter__value" style="inline-size:' . esc_attr( (string) round( $pct, 1 ) ) . '%"></span></span>';
		echo '<p class="aisa-small' . ( $capped ? ' aisa-tone--warn' : '' ) . '">' . esc_html( $line ) . '</p>';
		echo '</section>';
	}

	/**
	 * The ranked list (B1/B2).
	 *
	 * @param array<int,array<string,mixed>> $rows Ranked rows.
	 */
	protected function ranked_list( array $rows ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$issue  = isset( $_GET['issue'] ) ? sanitize_key( wp_unslash( $_GET['issue'] ) ) : '';
		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$hide   = ! isset( $_GET['filtered'] ) || ! empty( $_GET['hide'] );
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$all      = count( $rows );
		$filtered = array_filter(
			$rows,
			static function ( array $r ) use ( $q, $issue, $type, $status, $hide ): bool {
				if ( '' !== $q && false === stripos( $r['title'] . ' ' . $r['path'], $q ) ) {
					return false;
				}
				if ( '' !== $issue && empty( $r['kinds'][ $issue ] ) ) {
					return false;
				}
				if ( '' !== $type && $r['post_type'] !== $type ) {
					return false;
				}
				if ( 'ready' === $status && ! ( $r['has_suggestions'] && '' === $r['applied_at'] ) ) {
					return false;
				}
				if ( 'applied' === $status && '' === $r['applied_at'] ) {
					return false;
				}
				if ( 'none' === $status && ( $r['has_suggestions'] || '' !== $r['applied_at'] ) ) {
					return false;
				}
				return ! ( $hide && ! $r['seen'] );
			}
		);
		$shown    = array_slice( $filtered, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE, true );
		$meta     = Page_Data::meta();
		$spend    = Spend::current();
		$model    = ( new Claude_Client() )->get_model();
		$per      = Page_Review::estimate( $this->past_costs(), $model );
		$capped   = ! Spend::allows( $spend['usd'], Spend::cap(), 'review' );
		$left     = max( 0.0, Spend::cap() - $spend['usd'] );

		echo '<section class="aisa-card" aria-labelledby="aisa-ranked">';
		/* translators: %s: last day of the 90-day window. */
		echo Ui::card_head( 'aisa-ranked', 'list-view', __( 'Pages ranked by opportunity', 'ai-seo-assistant' ), sprintf( __( 'Search Console + Google Analytics · 90 days to %s', 'ai-seo-assistant' ), Ui::day( $meta['end'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		$this->filters( $q, $issue, $type, $status, $hide );

		// Bulk bar: the JS enables it; without JS each page's Review screen generates one at a time.
		echo '<div class="aisa-bulk" data-aisa-bulk data-per-page="' . esc_attr( (string) round( $per, 4 ) ) . '" data-left="' . esc_attr( (string) round( $left, 2 ) ) . '">';
		echo '<label class="aisa-check"><input type="checkbox" data-aisa-select-all' . disabled( $capped, true, false ) . '> <span data-aisa-selected>' . esc_html__( 'Select pages to generate suggestions', 'ai-seo-assistant' ) . '</span></label>';
		if ( $capped ) {
			echo '<span class="aisa-bulk__note aisa-tone--warn">' . esc_html__( 'Paused: monthly cap reached', 'ai-seo-assistant' ) . '</span><button type="button" class="aisa-btn" disabled>' . Ui::icon( 'admin-customizer' ) . esc_html__( 'Generate for selected', 'ai-seo-assistant' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		} else {
			/* translators: 1: estimated cost per page, 2: what is left of the cap. */
			echo '<span class="aisa-bulk__note" data-aisa-estimate>' . esc_html( sprintf( __( 'About %1$s a page · %2$s left this billing month', 'ai-seo-assistant' ), Spend::money( $per ), Spend::money( $left ) ) ) . '</span>';
			echo '<button type="button" class="aisa-btn aisa-btn--primary" data-aisa-generate disabled>' . Ui::icon( 'admin-customizer' ) . '<span>' . esc_html__( 'Generate for selected', 'ai-seo-assistant' ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		}
		echo '<span class="aisa-bulk__status" data-aisa-bulk-status aria-live="polite"></span></div>';

		echo '<div class="aisa-tablewrap"><table class="aisa-table aisa-table--pages"><caption class="screen-reader-text">' . esc_html__( 'Pages ranked by opportunity', 'ai-seo-assistant' ) . '</caption><thead><tr>'
			. '<td class="aisa-col-check"></td><th scope="col">' . esc_html__( 'Page', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Opportunity', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'Impressions', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'Position', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'CTR vs expected', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'Enquiries', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'Issues', 'ai-seo-assistant' ) . '</th>'
			. '<td></td></tr></thead><tbody>';
		foreach ( $shown as $id => $r ) {
			$badge = '';
			if ( '' !== $r['applied_at'] ) {
				/* translators: %s: date. */
				$badge = Ui::pill( sprintf( __( 'Applied %s', 'ai-seo-assistant' ), wp_date( 'j M', (int) strtotime( $r['applied_at'] . ' UTC' ) ) ), 'good' );
			} elseif ( $r['has_suggestions'] ) {
				$badge = Ui::pill( __( 'Suggestions ready', 'ai-seo-assistant' ), 'accent' );
			}
			if ( 'content' === $r['source'] ) {
				$badge .= ' ' . Ui::pill( __( 'Checked from content', 'ai-seo-assistant' ), 'warn' );
			}
			$below = null !== $r['ctr'] && null !== $r['expected'] ? $r['expected'] - $r['ctr'] : null;
			$label = sprintf( /* translators: %s: page title. */ __( 'Select %s', 'ai-seo-assistant' ), $r['title'] );
			echo '<tr data-aisa-row="' . esc_attr( (string) $id ) . '">'
				. '<td class="aisa-col-check"><input type="checkbox" value="' . esc_attr( (string) $id ) . '" data-aisa-select aria-label="' . esc_attr( $label ) . '"' . disabled( $capped, true, false ) . '></td>'
				. '<th scope="row" class="aisa-pagecell"><a class="aisa-pagecell__title" href="' . esc_url( $this->url( [ 'post' => $id ] ) ) . '">' . esc_html( $r['title'] ) . '</a><span class="aisa-pagecell__meta"><span class="aisa-path">' . esc_html( $r['path'] ) . '</span> ' . $badge . '<span class="aisa-row-status" data-aisa-row-status></span></span></th>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pills escaped in Ui.
				. '<td><span class="aisa-score"><strong>' . esc_html( (string) $r['score'] ) . '</strong><span class="aisa-meter aisa-meter--score' . ( $r['score'] >= 60 ? ' is-high' : '' ) . '" aria-hidden="true"><span class="aisa-meter__value" style="inline-size:' . esc_attr( (string) max( 2, $r['score'] ) ) . '%"></span></span></span></td>'
				. '<td class="aisa-num">' . esc_html( number_format_i18n( $r['impressions'] ) ) . '</td>'
				. '<td class="aisa-num">' . esc_html( $r['position'] > 0 ? number_format_i18n( $r['position'], 1 ) : '–' ) . '</td>'
				. '<td class="aisa-num">' . esc_html( Ui::pct( $r['ctr'] ) . ' / ' . Ui::pct( $r['expected'] ) ) . ( null !== $below ? '<br><span class="aisa-small ' . ( $below >= 1 ? 'aisa-tone--bad' : 'aisa-tone--flat' ) . '">' . esc_html( $below > 0 ? sprintf( /* translators: %s: percentage points. */ __( '%s below', 'ai-seo-assistant' ), number_format_i18n( $below, 1 ) ) : __( 'at or above', 'ai-seo-assistant' ) ) . '</span>' : '' ) . '</td>'
				. '<td class="aisa-num">' . esc_html( number_format_i18n( $r['enquiries'] ) ) . '</td>'
				. '<td class="aisa-num aisa-strong">' . esc_html( (string) $r['issue_count'] ) . '</td>'
				. '<td class="aisa-col-action"><a class="aisa-btn aisa-btn--small" href="' . esc_url( $this->url( [ 'post' => $id ] ) ) . '">' . esc_html__( 'Review', 'ai-seo-assistant' ) . '<span class="screen-reader-text"> ' . esc_html( $r['title'] ) . '</span></a></td>'
				. '</tr>';
		}
		if ( [] === $shown ) {
			echo '<tr><td colspan="9" class="aisa-empty">' . esc_html__( 'No pages match these filters.', 'ai-seo-assistant' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$this->pagination( count( $filtered ), $paged );
		/* translators: 1: pages shown, 2: pages in all. */
		echo '<p class="aisa-small">' . esc_html( sprintf( __( 'Opportunity = impressions × (expected CTR at that position − actual CTR), weighted by enquiries from Google Analytics. Pages Google barely shows rank low even with many issues. Showing %1$d of %2$d pages.', 'ai-seo-assistant' ), count( $shown ), $all ) ) . '</p>';
		echo '</section>';
	}

	/**
	 * The list filters (a GET form, so they work without JavaScript and survive a reload).
	 *
	 * @param string $q      Search.
	 * @param string $issue  Issue kind.
	 * @param string $type   Post type.
	 * @param string $status Status.
	 * @param bool   $hide   Hide pages not seen on Google.
	 */
	protected function filters( string $q, string $issue, string $type, string $status, bool $hide ): void {
		echo '<form method="get" class="aisa-filters" role="search"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="filtered" value="1">';
		echo '<label class="aisa-field aisa-field--search"><span class="screen-reader-text">' . esc_html__( 'Filter pages by title or address', 'ai-seo-assistant' ) . '</span>' . Ui::icon( 'search' ) . '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Filter pages by title or address', 'ai-seo-assistant' ) . '"></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<label class="aisa-field"><span class="screen-reader-text">' . esc_html__( 'Issue', 'ai-seo-assistant' ) . '</span><select name="issue"><option value="">' . esc_html__( 'Issue: any', 'ai-seo-assistant' ) . '</option>';
		foreach ( Rules::kinds() as $kind => $label ) {
			echo '<option value="' . esc_attr( $kind ) . '"' . selected( $issue, $kind, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="aisa-field"><span class="screen-reader-text">' . esc_html__( 'Type', 'ai-seo-assistant' ) . '</span><select name="type"><option value="">' . esc_html__( 'Type: all pages', 'ai-seo-assistant' ) . '</option>';
		foreach ( Scanner::post_types() as $pt ) {
			$obj = get_post_type_object( $pt );
			echo '<option value="' . esc_attr( $pt ) . '"' . selected( $type, $pt, false ) . '>' . esc_html( $obj ? $obj->labels->name : $pt ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="aisa-field"><span class="screen-reader-text">' . esc_html__( 'Status', 'ai-seo-assistant' ) . '</span><select name="status">'
			. '<option value="">' . esc_html__( 'Status: any', 'ai-seo-assistant' ) . '</option>'
			. '<option value="ready"' . selected( $status, 'ready', false ) . '>' . esc_html__( 'Suggestions ready', 'ai-seo-assistant' ) . '</option>'
			. '<option value="applied"' . selected( $status, 'applied', false ) . '>' . esc_html__( 'Applied', 'ai-seo-assistant' ) . '</option>'
			. '<option value="none"' . selected( $status, 'none', false ) . '>' . esc_html__( 'Not reviewed', 'ai-seo-assistant' ) . '</option></select></label>';
		echo '<label class="aisa-check"><input type="checkbox" name="hide" value="1"' . checked( $hide, true, false ) . '> ' . esc_html__( 'Hide pages not seen on Google', 'ai-seo-assistant' ) . '</label>';
		echo '<button type="submit" class="aisa-btn aisa-btn--small">' . esc_html__( 'Filter', 'ai-seo-assistant' ) . '</button></form>';
	}

	/**
	 * Previous / next links when the list is longer than a screen.
	 *
	 * @param int $total Matching rows.
	 * @param int $paged Current page.
	 */
	protected function pagination( int $total, int $paged ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<nav class="aisa-pager" aria-label="' . esc_attr__( 'More pages', 'ai-seo-assistant' ) . '">';
		if ( $paged > 1 ) {
			echo '<a class="aisa-btn aisa-btn--small" href="' . esc_url( add_query_arg( 'paged', $paged - 1 ) ) . '">' . esc_html__( 'Previous', 'ai-seo-assistant' ) . '</a>';
		}
		/* translators: 1: current page, 2: pages. */
		echo '<span class="aisa-small">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'ai-seo-assistant' ), $paged, $pages ) ) . '</span>';
		if ( $paged < $pages ) {
			echo '<a class="aisa-btn aisa-btn--small" href="' . esc_url( add_query_arg( 'paged', $paged + 1 ) ) . '">' . esc_html__( 'Next', 'ai-seo-assistant' ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Before the first push (B3): the three steps and the pages with most issues.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 */
	protected function first_run( array $rows ): void {
		uasort( $rows, static fn( $a, $b ) => $b['issue_count'] <=> $a['issue_count'] );
		$total = array_sum( array_column( $rows, 'issue_count' ) );
		$steps = [
			/* translators: %s: issue count. */
			[ __( '1. Scan the site', 'ai-seo-assistant' ), __( 'Done', 'ai-seo-assistant' ), 'accent', sprintf( __( 'Every published page checked on this site. %s issues found, listed below.', 'ai-seo-assistant' ), number_format_i18n( $total ) ) ],
			/* translators: %s: agency name. */
			[ __( '2. Weekly push arrives', 'ai-seo-assistant' ), __( 'Waiting', 'ai-seo-assistant' ), 'warn', sprintf( __( 'Search Console and Google Analytics per page, sent by %s.', 'ai-seo-assistant' ), Ui::agency() ) ],
			[ __( '3. Rank and write suggestions', 'ai-seo-assistant' ), __( 'After the push', 'ai-seo-assistant' ), 'muted', __( 'Pages are ranked by extra clicks they could win; then Claude can write titles, descriptions and alt text.', 'ai-seo-assistant' ) ],
		];
		echo '<section class="aisa-card" aria-labelledby="aisa-pages">';
		echo Ui::card_head( 'aisa-pages', 'list-view', __( 'Pages with issues', 'ai-seo-assistant' ), __( 'Not ranked until the first push', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<ol class="aisa-steps">';
		foreach ( $steps as [ $title, $state, $tone, $text ] ) {
			echo '<li class="aisa-step"><p class="aisa-step__head"><strong>' . esc_html( $title ) . '</strong>' . Ui::pill( $state, $tone ) . '</p><p class="aisa-small">' . esc_html( $text ) . '</p></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		}
		echo '</ol><div class="aisa-tablewrap"><table class="aisa-table"><thead><tr><th scope="col">' . esc_html__( 'Page', 'ai-seo-assistant' ) . '</th><th scope="col">' . esc_html__( 'Issues', 'ai-seo-assistant' ) . '</th><td></td></tr></thead><tbody>';
		$kinds = Rules::kinds();
		foreach ( array_slice( $rows, 0, self::PER_PAGE, true ) as $id => $r ) {
			if ( 0 === $r['issue_count'] ) {
				continue;
			}
			$names = implode( ', ', array_map( static fn( $k ) => $kinds[ $k ] ?? $k, array_keys( $r['kinds'] ) ) );
			/* translators: %d: issue count. */
			echo '<tr><th scope="row" class="aisa-pagecell"><a class="aisa-pagecell__title" href="' . esc_url( $this->url( [ 'post' => $id ] ) ) . '">' . esc_html( $r['title'] ) . '</a><span class="aisa-pagecell__meta"><span class="aisa-path">' . esc_html( $r['path'] ) . '</span></span></th><td><strong>' . esc_html( sprintf( _n( '%d issue', '%d issues', $r['issue_count'], 'ai-seo-assistant' ), $r['issue_count'] ) ) . '</strong><br><span class="aisa-small">' . esc_html( $names ) . '</span></td><td class="aisa-col-action"><a class="aisa-btn aisa-btn--small" href="' . esc_url( $this->url( [ 'post' => $id ] ) ) . '">' . esc_html__( 'See issues', 'ai-seo-assistant' ) . '<span class="screen-reader-text"> ' . esc_html( $r['title'] ) . '</span></a></td></tr>';
		}
		echo '</tbody></table></div>';
		/* translators: 1: shown, 2: total. */
		echo '<p class="aisa-small">' . esc_html( sprintf( __( 'Showing %1$d of %2$d pages, most issues first. Generating suggestions opens once search data arrives, so Claude writes for the searches people actually use.', 'ai-seo-assistant' ), min( self::PER_PAGE, count( $rows ) ), count( $rows ) ) ) . '</p>';
		echo '</section>';
	}

	/* ==== The page review (C1–C3) ================================================================= */

	/**
	 * One page's review.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function review( int $post_id ): void {
		$row  = ( new Scan_Store() )->get( $post_id );
		$post = get_post( $post_id );
		if ( null === $row || ! $post instanceof \WP_Post ) {
			echo Ui::notice( 'warning', '<p>' . esc_html__( 'This page has not been scanned yet. Run a scan first.', 'ai-seo-assistant' ) . '</p><p><a href="' . esc_url( $this->url( [] ) ) . '">' . esc_html__( 'Back to SEO scan', 'ai-seo-assistant' ) . '</a></p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
			return;
		}
		$ranked = Ranking::rows();
		$r      = $ranked[ $post_id ] ?? null;
		$page   = ( new Page_Data() )->get( (string) $row['path'] );
		$facts  = $row['facts'];
		$sub    = [ $row['path'] ];
		if ( null !== $r && Page_Data::has_data() ) {
			/* translators: 1: score, 2: rank (ordinal number), 3: page count. */
			$sub[] = sprintf( __( 'Opportunity %1$d, %2$s of %3$d pages', 'ai-seo-assistant' ), $r['score'], self::ordinal( (int) $r['rank'] ), count( $ranked ) );
		}
		/* translators: %d: issues. */
		$sub[]   = sprintf( _n( '%d issue', '%d issues', $row['issue_count'], 'ai-seo-assistant' ), $row['issue_count'] );
		$builder = self::builder( $post_id );
		if ( '' !== $builder ) {
			/* translators: %s: page builder name. */
			$sub[] = sprintf( __( 'built with %s', 'ai-seo-assistant' ), $builder );
		}
		$actions = '<a class="aisa-btn aisa-btn--dark" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . Ui::icon( 'external' ) . esc_html__( 'View page', 'ai-seo-assistant' ) . '</a>';
		$edit    = get_edit_post_link( $post_id, 'url' );
		if ( $edit ) {
			$actions .= '<a class="aisa-btn aisa-btn--dark" href="' . esc_url( $edit ) . '">' . Ui::icon( 'edit' ) . esc_html__( 'Open in editor', 'ai-seo-assistant' ) . '</a>';
		}
		echo Ui::hero( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			[
				/* translators: %s: agency name. */
				'label'      => sprintf( __( 'Page review by %s', 'ai-seo-assistant' ), Ui::agency() ),
				'title'      => wp_strip_all_tags( get_the_title( $post ) ),
				'sub'        => implode( ' · ', $sub ),
				'back_url'   => $this->url( [] ),
				'back_label' => __( 'Back to SEO scan', 'ai-seo-assistant' ),
				'actions'    => $actions,
			]
		);
		$this->result_notice();
		$s       = $row['suggestions'];
		$applied = is_array( $s ) && ! empty( $s['applied']['batch'] ) ? ( new Change_Log() )->find( [ 'batch' => (string) $s['applied']['batch'] ] ) : [];
		if ( [] !== $applied ) {
			$this->applied_notice( $s['applied'], $applied );
		}
		if ( 'content' === $row['source'] ) {
			/* translators: %s: why the page could not be loaded. */
			echo Ui::notice( 'warning', '<p>' . esc_html( sprintf( __( 'This page could not be loaded as Google sees it (%s), so it was checked from its post content and SEO fields. Builder output (theme title, Divi modules) was not seen.', 'ai-seo-assistant' ), (string) ( $facts['fetch_error'] ?? '' ) ) ) . '</p>', 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		}

		echo '<div class="aisa-review">';
		echo '<div class="aisa-review__main">';
		$this->searches_card( $page );
		$this->doing_card( $page );
		$this->found_card( $row );
		echo '</div><div class="aisa-review__side">';
		if ( [] !== $applied ) {
			$this->applied_panel( $post_id, $s, $applied );
		} else {
			$this->suggestions_panel( $post_id, $row, $page );
		}
		echo '</div></div>';
		echo Ui::footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
	}

	/**
	 * "What people search for".
	 *
	 * @param array<string,mixed>|null $page Page data.
	 */
	protected function searches_card( ?array $page ): void {
		$meta = Page_Data::meta();
		echo '<section class="aisa-card" aria-labelledby="aisa-searches">';
		/* translators: %s: date. */
		echo Ui::card_head( 'aisa-searches', 'search', __( 'What people search for', 'ai-seo-assistant' ), '' !== $meta['end'] ? sprintf( __( 'Search Console · 90 days to %s', 'ai-seo-assistant' ), Ui::day( $meta['end'] ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		$queries = (array) ( $page['gsc']['queries'] ?? [] );
		if ( [] === $queries ) {
			echo '<p class="aisa-pending">' . esc_html( null === $page ? __( 'No search data for this page yet. It arrives with the weekly push; until then Claude writes from the page content alone.', 'ai-seo-assistant' ) : __( 'Google showed this page for no searches in the last 90 days.', 'ai-seo-assistant' ) ) . '</p></section>';
			return;
		}
		echo '<div class="aisa-tablewrap"><table class="aisa-table"><caption class="screen-reader-text">' . esc_html__( 'Searches that showed this page', 'ai-seo-assistant' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Search', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Clicks', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Impressions', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Position', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'CTR', 'ai-seo-assistant' ) . '</th></tr></thead><tbody>';
		$main = Scanner::main_query( $page );
		foreach ( $queries as $q ) {
			echo '<tr><th scope="row"' . ( $q['query'] === $main ? ' class="aisa-strong"' : '' ) . '>' . esc_html( $q['query'] ) . '</th><td class="aisa-num">' . esc_html( number_format_i18n( $q['clicks'] ) ) . '</td><td class="aisa-num">' . esc_html( number_format_i18n( $q['impressions'] ) ) . '</td><td class="aisa-num">' . esc_html( null === $q['position'] ? '–' : number_format_i18n( $q['position'], 1 ) ) . '</td><td class="aisa-num">' . esc_html( Ui::pct( $q['ctr'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$total = (int) ( $page['gsc']['queries_total'] ?? 0 );
		/* translators: 1: searches shown, 2: searches in all. */
		echo '<p class="aisa-small">' . esc_html( $total > count( $queries ) ? sprintf( __( 'Top %1$d of %2$d searches. Claude writes for the first one: most clicks.', 'ai-seo-assistant' ), count( $queries ), $total ) : __( 'Claude writes for the search with the most clicks (in bold).', 'ai-seo-assistant' ) ) . '</p>';
		echo '</section>';
	}

	/**
	 * "How this page is doing": four tiles, the weekly lines, and GA4.
	 *
	 * @param array<string,mixed>|null $page Page data.
	 */
	protected function doing_card( ?array $page ): void {
		if ( null === $page || ! is_array( $page['gsc'] ?? null ) ) {
			$this->ga4_only( $page );
			return;
		}
		$g     = $page['gsc'];
		$ctr   = null !== $g['ctr'] ? (float) $g['ctr'] : ( $g['impressions'] > 0 ? round( $g['clicks'] / $g['impressions'] * 100, 2 ) : 0.0 );
		$exp   = Opportunity::expected_ctr( (float) $g['position'] );
		$meta  = Page_Data::meta();
		$tiles = $this->tile( __( 'Clicks', 'ai-seo-assistant' ), number_format_i18n( $g['clicks'] ), self::delta( (int) $g['clicks'], $g['prev_clicks'], false ), 'clicks' )
			. $this->tile( __( 'Impressions', 'ai-seo-assistant' ), number_format_i18n( $g['impressions'] ), self::delta( (int) $g['impressions'], $g['prev_shown'], true ), 'shown' )
			/* translators: %s: expected CTR. */
			. $this->tile( __( 'CTR', 'ai-seo-assistant' ), Ui::pct( $ctr ), [ sprintf( __( 'expected %s at this position', 'ai-seo-assistant' ), Ui::pct( $exp ) ), $ctr + 0.1 < $exp ? 'bad' : 'flat' ] )
			. $this->tile( __( 'Position', 'ai-seo-assistant' ), null === $g['position'] ? '–' : number_format_i18n( (float) $g['position'], 1 ), self::places( $g['position'], $g['prev_position'] ) );

		echo '<section class="aisa-card" aria-labelledby="aisa-doing">';
		/* translators: %s: date. */
		echo Ui::card_head( 'aisa-doing', 'chart-line', __( 'How this page is doing', 'ai-seo-assistant' ), sprintf( __( '90 days to %s', 'ai-seo-assistant' ), Ui::day( $meta['end'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<div class="aisa-tiles aisa-tiles--search">' . $tiles . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tile().
		$weeks = (array) $g['weeks'];
		if ( count( $weeks ) >= 2 ) {
			$title = __( 'Clicks and impressions per week', 'ai-seo-assistant' );
			$labels = array_map( static fn( $w ) => (string) wp_date( 'j M', (int) strtotime( $w['start'] . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) ), $weeks );
			echo '<figure class="aisa-figure aisa-figure--wide">' . Chart::lines( array_column( $weeks, 'clicks' ), array_column( $weeks, 'impressions' ), $labels, $title ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart escapes its own output.
				. '<figcaption class="aisa-small">' . esc_html( $ctr + 0.1 < $exp ? __( 'Solid line: clicks per week (left scale). Dashed line: impressions per week (right scale). Shown often, clicked rarely: the listing is the problem, not the ranking.', 'ai-seo-assistant' ) : __( 'Solid line: clicks per week (left scale). Dashed line: impressions per week (right scale).', 'ai-seo-assistant' ) ) . '</figcaption></figure>';
		}
		$ga = $page['ga4'] ?? null;
		if ( is_array( $ga ) && null !== $ga['visits'] ) {
			echo '<h3 class="aisa-card__sub">' . esc_html__( 'From Google Analytics · 90 days', 'ai-seo-assistant' ) . '</h3><div class="aisa-tiles aisa-tiles--plain">';
			/* translators: %s: percent. */
			echo $this->tile( __( 'Visits', 'ai-seo-assistant' ), number_format_i18n( (int) $ga['visits'] ), [ null !== $ga['search_share'] ? sprintf( __( '%s from Google search', 'ai-seo-assistant' ), Ui::pct( $ga['search_share'] ) ) : '', 'flat' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tile().
			/* translators: %s: average engagement time, e.g. "1m 12s". */
			echo $this->tile( __( 'Engaged', 'ai-seo-assistant' ), Ui::pct( $ga['engaged'] ), [ null !== $ga['engaged_secs'] ? sprintf( __( 'stayed and read, %s average', 'ai-seo-assistant' ), self::duration( (int) $ga['engaged_secs'] ) ) : '', 'flat' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tile().
			echo $this->tile( __( 'Enquiries started here', 'ai-seo-assistant' ), number_format_i18n( (int) $ga['enquiries'] ), [ (string) $ga['enquiry_note'], 'flat' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tile().
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * A page Google did not show but Analytics saw: its visitor figures alone.
	 *
	 * @param array<string,mixed>|null $page Page data.
	 */
	protected function ga4_only( ?array $page ): void {
		$ga = is_array( $page ) ? ( $page['ga4'] ?? null ) : null;
		if ( ! is_array( $ga ) || null === $ga['visits'] ) {
			return;
		}
		echo '<section class="aisa-card" aria-labelledby="aisa-doing">';
		echo Ui::card_head( 'aisa-doing', 'chart-line', __( 'How this page is doing', 'ai-seo-assistant' ), __( 'Google Analytics · 90 days', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<p class="aisa-small">' . esc_html__( 'Google showed this page in no searches in the window; visitors reached it another way.', 'ai-seo-assistant' ) . '</p><div class="aisa-tiles aisa-tiles--plain">';
		echo $this->tile( __( 'Visits', 'ai-seo-assistant' ), number_format_i18n( (int) $ga['visits'] ), [ '', 'flat' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tile().
		echo $this->tile( __( 'Engaged', 'ai-seo-assistant' ), Ui::pct( $ga['engaged'] ), [ '', 'flat' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tile().
		echo $this->tile( __( 'Enquiries started here', 'ai-seo-assistant' ), number_format_i18n( (int) $ga['enquiries'] ), [ (string) $ga['enquiry_note'], 'flat' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tile().
		echo '</div></section>';
	}

	/**
	 * "What the scan found".
	 *
	 * @param array<string,mixed> $row Scan row.
	 */
	protected function found_card( array $row ): void {
		echo '<section class="aisa-card" aria-labelledby="aisa-found">';
		/* translators: 1: issue count, 2: date. */
		echo Ui::card_head( 'aisa-found', 'search', __( 'What the scan found', 'ai-seo-assistant' ), sprintf( _n( '%1$d issue · checked %2$s', '%1$d issues · checked %2$s', $row['issue_count'], 'ai-seo-assistant' ), $row['issue_count'], wp_date( 'D j M', (int) strtotime( $row['scanned_at'] . ' UTC' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		if ( [] === $row['issues'] ) {
			echo '<p class="aisa-pending">' . esc_html__( 'No issues on this page.', 'ai-seo-assistant' ) . '</p></section>';
			return;
		}
		echo '<ul class="aisa-issues">';
		foreach ( $row['issues'] as $issue ) {
			$who = 'claude' === $issue['who'] ? Ui::pill( __( 'Claude can fix', 'ai-seo-assistant' ), 'accent' ) : Ui::pill( __( 'Do in the editor', 'ai-seo-assistant' ), 'warn' );
			echo '<li class="aisa-issue"><p class="aisa-issue__head"><span class="aisa-tag">' . esc_html( Rules::label( (string) $issue['kind'] ) ) . '</span><strong>' . esc_html( (string) $issue['title'] ) . '</strong>' . $who . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill escaped in Ui.
				. '<p class="aisa-issue__detail">' . esc_html( (string) $issue['detail'] ) . '</p><p class="aisa-issue__fix">' . esc_html( (string) $issue['fix'] ) . '</p></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Claude's suggestions (C1), the generate button, or the cap message.
	 *
	 * @param int                      $post_id Post ID.
	 * @param array<string,mixed>      $row     Scan row.
	 * @param array<string,mixed>|null $page    Page data.
	 */
	protected function suggestions_panel( int $post_id, array $row, ?array $page ): void {
		$s       = $row['suggestions'];
		$adapter = Scanner::adapter();
		$claude  = new Claude_Client();
		$spend   = Spend::current();
		$capped  = ! Spend::allows( $spend['usd'], Spend::cap(), 'review' );
		$per     = Page_Review::estimate( $this->past_costs(), $claude->get_model() );

		echo '<section class="aisa-card aisa-card--claude" aria-labelledby="aisa-claude" data-aisa-panel data-writing="' . esc_attr__( 'Claude is reading the page, its searches and how visitors use it. Usually about 20 seconds; you can leave this screen.', 'ai-seo-assistant' ) . '">';
		$source = is_array( $s )
			/* translators: 1: date and time, 2: cost. */
			? sprintf( __( 'Written %1$s · cost %2$s', 'ai-seo-assistant' ), wp_date( 'D j M, g:ia', (int) $s['generated_at'] ), Spend::money( (float) $s['cost'] ) )
			: '';
		echo Ui::card_head( 'aisa-claude', 'admin-customizer', __( 'Suggestions from Claude', 'ai-seo-assistant' ), $source ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.

		$generate = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aisa-inline" data-aisa-generate-one="' . esc_attr( (string) $post_id ) . '">'
			. wp_nonce_field( Tools_Actions::GENERATE, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::GENERATE ) . '"><input type="hidden" name="post" value="' . esc_attr( (string) $post_id ) . '">';

		if ( ! is_array( $s ) ) {
			if ( $capped ) {
				echo Ui::notice( 'warning', '<p>' . esc_html( Spend::cap_message( $spend ) ) . '</p>', 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
			} elseif ( ! $claude->has_api_key() ) {
				echo Ui::notice( 'warning', '<p>' . esc_html__( 'Add the Claude API key in Settings to write suggestions.', 'ai-seo-assistant' ) . '</p>', 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
			} else {
				echo '<p>' . esc_html__( 'Claude reads the page, its searches and how visitors use it, then writes a title, a description, a focus keyphrase and alt text for the photos. Nothing changes on the site until you apply.', 'ai-seo-assistant' ) . '</p>';
				/* translators: 1: estimated cost, 2: spent, 3: cap. */
				echo '<div class="aisa-panel-foot"><p class="aisa-small">' . esc_html( sprintf( __( 'Estimated cost about %1$s · %2$s of %3$s used this billing month', 'ai-seo-assistant' ), Spend::money( $per ), Spend::money( (float) $spend['usd'] ), Spend::money( Spend::cap() ) ) ) . '</p>';
				echo $generate . '<button type="submit" class="aisa-btn aisa-btn--primary">' . Ui::icon( 'admin-customizer' ) . esc_html__( 'Write suggestions', 'ai-seo-assistant' ) . '</button></form></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}
			echo '</section>';
			return;
		}

		echo '<p class="aisa-small aisa-lead">' . Ui::icon( 'info-outline' ) . esc_html( sprintf( /* translators: %s: SEO plugin name. */ __( 'Nothing changes on the site until you apply. Title, description and focus keyphrase are saved in %s; alt text in the Media Library.', 'ai-seo-assistant' ), $adapter->get_name() ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon escaped in Ui.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aisa-suggest" data-aisa-apply>';
		wp_nonce_field( Tools_Actions::APPLY );
		echo '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::APPLY ) . '"><input type="hidden" name="post" value="' . esc_attr( (string) $post_id ) . '">';

		$issues = [];
		foreach ( $row['issues'] as $issue ) {
			$issues[ $issue['code'] ] = $issue;
		}
		$title_flag = isset( $issues['title_wide'] ) ? __( 'Too wide', 'ai-seo-assistant' ) : ( isset( $issues['title_duplicate'] ) ? __( 'Duplicate', 'ai-seo-assistant' ) : ( isset( $issues['title_missing'] ) ? __( 'Missing', 'ai-seo-assistant' ) : '' ) );
		$desc_flag  = isset( $issues['desc_duplicate'] ) ? __( 'Duplicate', 'ai-seo-assistant' ) : ( isset( $issues['desc_missing'] ) ? __( 'Missing', 'ai-seo-assistant' ) : ( isset( $issues['desc_long'] ) ? __( 'Too long', 'ai-seo-assistant' ) : ( isset( $issues['desc_short'] ) ? __( 'Too short', 'ai-seo-assistant' ) : '' ) ) );
		$key_flag   = isset( $issues['title_no_query'] ) ? __( 'Missing main search', 'ai-seo-assistant' ) : '';

		$this->field( 'title', __( 'SEO title', 'ai-seo-assistant' ), $title_flag, $s['title'], 'px' );
		$this->field( 'description', __( 'Meta description', 'ai-seo-assistant' ), $desc_flag, $s['description'], 'chars' );
		if ( $adapter->supports_keyphrase() ) {
			$this->field( 'keyphrase', __( 'Focus keyphrase', 'ai-seo-assistant' ), $key_flag, $s['keyphrase'], '' );
		} else {
			/* translators: %s: SEO plugin. */
			echo '<div class="aisa-sfield"><p class="aisa-sfield__head"><strong>' . esc_html__( 'Focus keyphrase', 'ai-seo-assistant' ) . '</strong></p><p class="aisa-small">' . esc_html( sprintf( __( '%s has no focus keyphrase field, so this is advice only:', 'ai-seo-assistant' ), $adapter->get_name() ) ) . ' <strong>' . esc_html( (string) $s['keyphrase']['value'] ) . '</strong></p></div>';
		}
		$this->alts( (array) $s['alts'] );
		$this->editor_box( $post_id, (array) $s['editor'] );

		$ready = 2 + ( $adapter->supports_keyphrase() ? 1 : 0 ) + count( array_filter( (array) $s['alts'], static fn( $a ) => '' !== $a['value'] ) );
		echo '<div class="aisa-panel-foot"><p class="aisa-small"><strong>' . esc_html( sprintf( /* translators: %d: count. */ _n( '%d change ready', '%d changes ready', $ready, 'ai-seo-assistant' ), $ready ) ) . '</strong><br>' . esc_html__( 'Each one is logged with its before and after.', 'ai-seo-assistant' ) . '</p>';
		echo '<span class="aisa-actions">';
		if ( ! $capped ) {
			echo '<button type="submit" form="aisa-regenerate" class="aisa-btn">' . Ui::icon( 'update' ) . esc_html__( 'Regenerate', 'ai-seo-assistant' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		}
		/* translators: %s: SEO plugin. */
		echo '<button type="submit" class="aisa-btn aisa-btn--primary">' . Ui::icon( 'yes' ) . esc_html( sprintf( __( 'Apply to %s', 'ai-seo-assistant' ), $adapter->get_name() ) ) . '</button></span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '</form>';
		if ( ! $capped ) {
			echo str_replace( '<form ', '<form id="aisa-regenerate" ', $generate ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		echo '</section>';
	}

	/**
	 * One suggested field with Accept / Edit / Skip.
	 *
	 * @param string              $key   title | description | keyphrase.
	 * @param string              $label Label.
	 * @param string              $flag  Issue chip ('' for none).
	 * @param array<string,mixed> $f     { now, value, why }.
	 * @param string              $meter 'px' | 'chars' | ''.
	 */
	protected function field( string $key, string $label, string $flag, array $f, string $meter ): void {
		$id = 'aisa-f-' . $key;
		echo '<fieldset class="aisa-sfield" data-aisa-field>';
		echo '<legend class="aisa-sfield__head"><strong>' . esc_html( $label ) . '</strong>' . ( '' !== $flag ? Ui::pill( $flag, 'bad' ) : '' ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<span class="aisa-seg" role="radiogroup" aria-label="' . esc_attr( sprintf( /* translators: %s: field. */ __( 'What to do with the suggested %s', 'ai-seo-assistant' ), mb_strtolower( $label ) ) ) . '">';
		foreach ( [
			'accept' => __( 'Accept', 'ai-seo-assistant' ),
			'edit'   => __( 'Edit', 'ai-seo-assistant' ),
			'skip'   => __( 'Skip', 'ai-seo-assistant' ),
		] as $value => $text ) {
			echo '<label><input type="radio" name="choice[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '"' . checked( 'accept', $value, false ) . '><span>' . esc_html( $text ) . '</span></label>';
		}
		echo '</span>';
		echo '<p class="aisa-sfield__label">' . esc_html__( 'Now', 'ai-seo-assistant' ) . '</p><p class="aisa-sfield__now">' . esc_html( '' !== (string) $f['now'] ? (string) $f['now'] : __( '(empty)', 'ai-seo-assistant' ) ) . '</p>';
		echo $this->meter( (string) $f['now'], $meter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in meter().
		echo '<label class="aisa-sfield__label" for="' . esc_attr( $id ) . '">' . esc_html__( 'Suggested', 'ai-seo-assistant' ) . '</label>';
		echo '<textarea id="' . esc_attr( $id ) . '" name="value[' . esc_attr( $key ) . ']" rows="' . ( 'description' === $key ? 3 : 1 ) . '" data-aisa-meter="' . esc_attr( $meter ) . '">' . esc_textarea( (string) $f['value'] ) . '</textarea>';
		echo $this->meter( (string) $f['value'], $meter, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in meter().
		if ( '' !== (string) $f['why'] ) {
			/* translators: %s: Claude's reason. */
			echo '<p class="aisa-small">' . esc_html( sprintf( __( 'Why: %s', 'ai-seo-assistant' ), (string) $f['why'] ) ) . '</p>';
		}
		echo '</fieldset>';
	}

	/**
	 * A width or length meter under a title or description.
	 *
	 * @param string $text  Text.
	 * @param string $kind  'px' | 'chars' | ''.
	 * @param bool   $live  Updated by the JS as the agency edits.
	 */
	protected function meter( string $text, string $kind, bool $live = false ): string {
		if ( '' === $kind || '' === $text ) {
			return '';
		}
		if ( 'px' === $kind ) {
			$n     = Title_Width::px( $text );
			$limit = Title_Width::LIMIT_PX;
			/* translators: 1: width, 2: limit. */
			$label = $n > $limit ? sprintf( __( '%1$d px of about %2$d px: cut off in Google', 'ai-seo-assistant' ), $n, $limit ) : sprintf( __( '%1$d px of about %2$d px: fits', 'ai-seo-assistant' ), $n, $limit );
			$bad   = $n > $limit;
		} else {
			$n     = mb_strlen( $text );
			$limit = 155;
			$bad   = $n > Rules::DESC_MAX || $n < Rules::DESC_MIN;
			/* translators: 1: length, 2: limit. */
			$label = $bad ? sprintf( __( '%1$d of about %2$d characters: will not show well', 'ai-seo-assistant' ), $n, $limit ) : sprintf( __( '%1$d of about %2$d characters: fits', 'ai-seo-assistant' ), $n, $limit );
		}

		return '<p class="aisa-fit' . ( $bad ? ' is-bad' : ' is-good' ) . '"' . ( $live ? ' data-aisa-fit' : '' ) . '><span class="aisa-fit__bar" aria-hidden="true"><span style="inline-size:' . esc_attr( (string) min( 100, round( $n / $limit * 100 ) ) ) . '%"></span></span><span class="aisa-fit__text">' . esc_html( $label ) . '</span></p>';
	}

	/**
	 * Image alt text suggestions.
	 *
	 * @param array<int,array<string,mixed>> $alts Alts.
	 */
	protected function alts( array $alts ): void {
		if ( [] === $alts ) {
			return;
		}
		echo '<fieldset class="aisa-sfield"><legend class="aisa-sfield__head"><strong>' . esc_html__( 'Image alt text', 'ai-seo-assistant' ) . '</strong>' . Ui::pill( sprintf( /* translators: %d: images. */ _n( '%d image', '%d images', count( $alts ), 'ai-seo-assistant' ), count( $alts ) ), 'bad' ) . '<span class="aisa-small aisa-push">' . esc_html__( 'Tick the ones to apply', 'ai-seo-assistant' ) . '</span></legend><ul class="aisa-alts">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		foreach ( $alts as $alt ) {
			$id    = (int) $alt['id'];
			$thumb = wp_get_attachment_image( $id, [ 56, 56 ], false, [ 'class' => 'aisa-alt__img', 'alt' => '' ] );
			$now   = '' === (string) $alt['now'] ? __( 'Now: no alt text', 'ai-seo-assistant' ) : sprintf( /* translators: %s: current alt. */ __( 'Now: “%s”', 'ai-seo-assistant' ), (string) $alt['now'] );
			echo '<li class="aisa-alt">' . ( $thumb ? $thumb : '<span class="aisa-alt__img" aria-hidden="true"></span>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built image tag.
				. '<div class="aisa-alt__body"><label for="aisa-alt-' . esc_attr( (string) $id ) . '"><strong>' . esc_html( (string) $alt['file'] ) . '</strong> <span class="aisa-tone--bad">' . esc_html( $now ) . '</span></label>'
				. '<textarea id="aisa-alt-' . esc_attr( (string) $id ) . '" name="alt[' . esc_attr( (string) $id ) . '][value]" rows="2">' . esc_textarea( (string) $alt['value'] ) . '</textarea></div>'
				. '<input type="checkbox" class="aisa-alt__tick" name="alt[' . esc_attr( (string) $id ) . '][apply]" value="1"' . checked( '' !== (string) $alt['value'], true, false ) . ' aria-label="' . esc_attr( sprintf( /* translators: %s: file name. */ __( 'Apply the alt text for %s', 'ai-seo-assistant' ), (string) $alt['file'] ) ) . '"></li>';
		}
		echo '</ul></fieldset>';
	}

	/**
	 * "Do in the editor": recommendations the plugin never applies.
	 *
	 * @param int                            $post_id Post ID.
	 * @param array<int,array<string,mixed>> $items   Advice.
	 */
	protected function editor_box( int $post_id, array $items ): void {
		if ( [] === $items ) {
			return;
		}
		$labels = [
			'headings' => __( 'Headings', 'ai-seo-assistant' ),
			'links'    => __( 'Links', 'ai-seo-assistant' ),
			'content'  => __( 'Content', 'ai-seo-assistant' ),
			'schema'   => __( 'Schema', 'ai-seo-assistant' ),
		];
		$edit   = (string) get_edit_post_link( $post_id, 'url' );
		echo '<div class="aisa-editorbox"><p class="aisa-editorbox__head"><strong>' . Ui::icon( 'edit' ) . esc_html__( 'Do in the editor', 'ai-seo-assistant' ) . '</strong><span class="aisa-small">' . esc_html__( 'Not applied by the plugin', 'ai-seo-assistant' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<p class="aisa-small">' . esc_html__( 'Headings, links and content are left to you: changing them automatically is too risky on builder pages.', 'ai-seo-assistant' ) . '</p><dl>';
		foreach ( $items as $item ) {
			$link = 'schema' === $item['area'] ? admin_url( 'admin.php?page=ajr-core' ) : $edit;
			echo '<dt>' . esc_html( $labels[ $item['area'] ] ?? $item['area'] ) . '</dt><dd><span>' . esc_html( (string) $item['advice'] ) . '</span>'
				. ( '' !== $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( 'schema' === $item['area'] ? __( 'Open AJR Core', 'ai-seo-assistant' ) : __( 'Open in editor', 'ai-seo-assistant' ) ) . '</a>' : '' ) . '</dd>';
		}
		echo '</dl></div>';
	}

	/**
	 * The green notice after an apply (C3).
	 *
	 * @param array<string,mixed>            $applied { batch, at, user, count }.
	 * @param array<int,array<string,mixed>> $changes The batch's change rows.
	 */
	protected function applied_notice( array $applied, array $changes ): void {
		$user = get_userdata( (int) $applied['user'] );
		$live = count( array_filter( $changes, static fn( $c ) => null === $c['undone_at'] ) );
		/* translators: 1: number of changes, 2: date and time, 3: who. */
		$head = sprintf( _n( '%1$d change applied · %2$s by %3$s', '%1$d changes applied · %2$s by %3$s', $live, 'ai-seo-assistant' ), $live, wp_date( 'D j M, g:ia', (int) $applied['at'] ), $user ? $user->display_name : '' );
		/* translators: %s: date measuring starts. */
		$body = sprintf( __( 'Each one is logged in Changes with its before and after. The effect on clicks is measured once 4 weeks of search data are in (from %s).', 'ai-seo-assistant' ), wp_date( 'D j M', (int) $applied['at'] + 4 * WEEK_IN_SECONDS ) );
		echo Ui::notice( 'success', '<p><strong>' . esc_html( $head ) . '</strong></p><p>' . esc_html( $body ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=' . Changes_Page::SLUG ) ) . '">' . esc_html__( 'See it in Changes', 'ai-seo-assistant' ) . '</a></p>', 'yes-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
	}

	/**
	 * Applied changes with Undo (C3).
	 *
	 * @param int                            $post_id Post ID.
	 * @param array<string,mixed>            $s       Suggestions.
	 * @param array<int,array<string,mixed>> $changes Change rows of the batch.
	 */
	protected function applied_panel( int $post_id, array $s, array $changes ): void {
		$adapter = Scanner::adapter();
		$labels  = [
			'title'       => __( 'SEO title', 'ai-seo-assistant' ),
			'description' => __( 'Meta description', 'ai-seo-assistant' ),
			'keyphrase'   => __( 'Focus keyphrase', 'ai-seo-assistant' ),
		];
		echo '<section class="aisa-card aisa-card--claude" aria-labelledby="aisa-applied">';
		/* translators: %s: date and time. */
		echo Ui::card_head( 'aisa-applied', 'admin-customizer', __( 'Applied changes', 'ai-seo-assistant' ), sprintf( __( 'Applied %s', 'ai-seo-assistant' ), wp_date( 'D j M, g:ia', (int) $s['applied']['at'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		$alts = array_filter( $changes, static fn( $c ) => 'alt' === $c['field'] );
		foreach ( array_reverse( $changes ) as $c ) {
			if ( 'alt' === $c['field'] ) {
				continue;
			}
			echo '<div class="aisa-sfield"><p class="aisa-sfield__head"><strong>' . esc_html( $labels[ $c['field'] ] ?? $c['field'] ) . '</strong>' . ( null === $c['undone_at'] ? Ui::pill( __( 'Applied', 'ai-seo-assistant' ), 'good' ) : Ui::pill( __( 'Undone', 'ai-seo-assistant' ), 'muted' ) ) . $this->undo_button( [ (int) $c['id'] ], __( 'Undo', 'ai-seo-assistant' ), null !== $c['undone_at'] ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui / undo_button().
				. '<p class="aisa-sfield__label">' . esc_html__( 'Before', 'ai-seo-assistant' ) . '</p><p class="aisa-before">' . esc_html( '' !== $c['before_value'] ? (string) $c['before_value'] : __( '(empty)', 'ai-seo-assistant' ) ) . '</p>'
				/* translators: %s: SEO plugin. */
				. '<p class="aisa-sfield__label aisa-tone--good">' . esc_html( sprintf( __( 'After · now live in %s', 'ai-seo-assistant' ), $adapter->get_name() ) ) . '</p><p class="aisa-after">' . esc_html( (string) $c['after_value'] ) . '</p></div>';
		}
		if ( [] !== $alts ) {
			/* translators: %d: count. */
			echo '<div class="aisa-sfield"><p class="aisa-sfield__head"><strong>' . esc_html__( 'Image alt text', 'ai-seo-assistant' ) . '</strong>' . Ui::pill( sprintf( _n( '%d applied', '%d applied', count( $alts ), 'ai-seo-assistant' ), count( $alts ) ), 'good' ) . '<span class="aisa-small aisa-push">' . esc_html__( 'Saved in the Media Library', 'ai-seo-assistant' ) . '</span></p><ul class="aisa-alts">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			foreach ( $alts as $c ) {
				$thumb = wp_get_attachment_image( (int) $c['object_id'], [ 56, 56 ], false, [ 'class' => 'aisa-alt__img', 'alt' => '' ] );
				$file  = (string) basename( (string) get_attached_file( (int) $c['object_id'] ) );
				/* translators: %s: alt before. */
				$before = '' === $c['before_value'] ? __( 'Before: no alt text', 'ai-seo-assistant' ) : sprintf( __( 'Before: “%s”', 'ai-seo-assistant' ), (string) $c['before_value'] );
				echo '<li class="aisa-alt">' . ( $thumb ? $thumb : '<span class="aisa-alt__img" aria-hidden="true"></span>' ) . '<div class="aisa-alt__body"><p><strong>' . esc_html( $file ) . '</strong> <span class="aisa-small">' . esc_html( $before ) . '</span></p><p class="aisa-after">' . esc_html( (string) $c['after_value'] ) . '</p></div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built image tag.
					. ( null === $c['undone_at'] ? Ui::pill( __( 'Applied', 'ai-seo-assistant' ), 'good' ) : Ui::pill( __( 'Undone', 'ai-seo-assistant' ), 'muted' ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			}
			echo '</ul></div>';
		}
		$this->editor_box( $post_id, (array) $s['editor'] );
		$open = array_map( static fn( $c ) => (int) $c['id'], array_filter( $changes, static fn( $c ) => null === $c['undone_at'] ) );
		/* translators: %s: date. */
		echo '<div class="aisa-panel-foot"><p class="aisa-small">' . esc_html( sprintf( __( 'Logged in Changes with before and after. Effect on clicks measured from %s.', 'ai-seo-assistant' ), wp_date( 'D j M', (int) $s['applied']['at'] + 4 * WEEK_IN_SECONDS ) ) ) . '</p>'
			. $this->undo_button( $open, __( 'Undo all', 'ai-seo-assistant' ), [] === $open, true ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in undo_button().
		if ( [] === $open ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( Tools_Actions::CLEAR );
			echo '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::CLEAR ) . '"><input type="hidden" name="post" value="' . esc_attr( (string) $post_id ) . '"><button type="submit" class="aisa-btn">' . esc_html__( 'Start a new review', 'ai-seo-assistant' ) . '</button></form>';
		}
		echo '</section>';
	}

	/**
	 * An Undo button (an admin-post form).
	 *
	 * @param array<int,int> $ids      Change IDs.
	 * @param string         $label    Label.
	 * @param bool           $disabled Nothing to undo.
	 * @param bool           $danger   Styled as the strong action.
	 */
	protected function undo_button( array $ids, string $label, bool $disabled, bool $danger = false ): string {
		if ( $disabled ) {
			return '';
		}

		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aisa-inline aisa-push">'
			. wp_nonce_field( Tools_Actions::UNDO, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::UNDO ) . '"><input type="hidden" name="ids" value="' . esc_attr( implode( ',', $ids ) ) . '">'
			. '<button type="submit" class="' . ( $danger ? 'aisa-btn aisa-btn--danger' : 'aisa-linkbtn' ) . '">' . ( $danger ? Ui::icon( 'undo' ) : '' ) . esc_html( $label ) . '</button></form>';
	}

	/**
	 * A metric tile.
	 *
	 * @param string            $label  Label.
	 * @param string            $value  Value (formatted).
	 * @param array{0:string,1:string} $change Text and tone.
	 * @param string            $series 'clicks' | 'shown' when it doubles as the chart key.
	 */
	protected function tile( string $label, string $value, array $change, string $series = '' ): string {
		return '<div class="aisa-metric' . ( '' !== $series ? ' aisa-metric--key aisa-metric--' . esc_attr( $series ) : '' ) . '"><p class="aisa-metric__label">' . esc_html( $label ) . '</p><p class="aisa-metric__value">' . esc_html( $value ) . '</p>'
			. ( '' !== $change[0] ? '<p class="aisa-metric__change aisa-tone--' . esc_attr( $change[1] ) . '">' . esc_html( $change[0] ) . '</p>' : '' ) . '</div>';
	}

	/**
	 * "down 6 on the 90 days before" / "up 9%".
	 *
	 * @param int      $now     This window.
	 * @param int|null $before  The window before.
	 * @param bool     $percent Show a percentage.
	 * @return array{0:string,1:string}
	 */
	protected static function delta( int $now, ?int $before, bool $percent ): array {
		if ( null === $before ) {
			return [ '', 'flat' ];
		}
		$d = $now - $before;
		if ( 0 === $d ) {
			return [ __( 'same as the 90 days before', 'ai-seo-assistant' ), 'flat' ];
		}
		if ( $percent && $before > 0 ) {
			$pct = (int) round( $d / $before * 100 );
			/* translators: %d: percent. */
			return [ $pct >= 0 ? sprintf( __( 'up %d%%', 'ai-seo-assistant' ), $pct ) : sprintf( __( 'down %d%%', 'ai-seo-assistant' ), abs( $pct ) ), $pct >= 0 ? 'good' : 'bad' ];
		}

		/* translators: %s: number. */
		return [ $d > 0 ? sprintf( __( 'up %s on the 90 days before', 'ai-seo-assistant' ), number_format_i18n( $d ) ) : sprintf( __( 'down %s on the 90 days before', 'ai-seo-assistant' ), number_format_i18n( abs( $d ) ) ), $d > 0 ? 'good' : 'bad' ];
	}

	/**
	 * "up 0.4 places".
	 *
	 * @param float|null $now    Position now.
	 * @param float|null $before Before.
	 * @return array{0:string,1:string}
	 */
	protected static function places( $now, $before ): array {
		if ( null === $now || null === $before ) {
			return [ '', 'flat' ];
		}
		$d = round( (float) $before - (float) $now, 1 );
		if ( abs( $d ) < 0.1 ) {
			return [ __( 'same place', 'ai-seo-assistant' ), 'flat' ];
		}

		/* translators: %s: places. */
		return [ $d > 0 ? sprintf( __( 'up %s places', 'ai-seo-assistant' ), number_format_i18n( $d, 1 ) ) : sprintf( __( 'down %s places', 'ai-seo-assistant' ), number_format_i18n( abs( $d ), 1 ) ), $d > 0 ? 'good' : 'bad' ];
	}

	/**
	 * "1m 12s".
	 *
	 * @param int $secs Seconds.
	 */
	protected static function duration( int $secs ): string {
		/* translators: 1: minutes, 2: seconds. */
		return $secs >= 60 ? sprintf( __( '%1$dm %2$02ds', 'ai-seo-assistant' ), intdiv( $secs, 60 ), $secs % 60 ) : sprintf( /* translators: %d: seconds. */ __( '%ds', 'ai-seo-assistant' ), $secs );
	}

	/**
	 * "3rd".
	 *
	 * @param int $n Number.
	 */
	protected static function ordinal( int $n ): string {
		$suffix = [ 'th', 'st', 'nd', 'rd' ];
		$v      = $n % 100;

		return $n . ( $suffix[ ( $v - 20 ) % 10 ] ?? $suffix[ $v ] ?? 'th' );
	}

	/**
	 * The page builder a page uses ('' for the block editor).
	 *
	 * @param int $post_id Post ID.
	 */
	protected static function builder( int $post_id ): string {
		if ( 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true ) ) {
			return 'Divi';
		}
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return 'Elementor';
		}

		return '';
	}

	/**
	 * Costs of this site's past reviews (for the estimate).
	 *
	 * @return array<int,float>
	 */
	protected function past_costs(): array {
		global $wpdb;
		$table = \AJR\SEOAssistant\Core\Schema::table( 'scan' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; an agency screen.
		$rows = (array) $wpdb->get_col( "SELECT suggestions FROM `{$table}` WHERE suggestions IS NOT NULL ORDER BY suggested_at DESC LIMIT 20" );

		return array_map( static fn( $json ) => (float) ( json_decode( (string) $json, true )['cost'] ?? 0 ), $rows );
	}

	/**
	 * This screen's URL with arguments.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 */
	public function url( array $args ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}
}
