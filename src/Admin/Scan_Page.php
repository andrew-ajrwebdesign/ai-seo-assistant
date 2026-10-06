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
use AJR\SEOAssistant\Content\Business as Business_Facts;
use AJR\SEOAssistant\Review\Page_Review;
use AJR\SEOAssistant\Scan\Google_Reads;
use AJR\SEOAssistant\Scan\Listing;
use AJR\SEOAssistant\Scan\Opportunity;
use AJR\SEOAssistant\Scan\Page_Role;
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
		$this->save_mode();
		\AJR\SEOAssistant\Scan\Auto_Types::run_pending(); // Obvious page types a cron pass could not set (no user then).
		echo '<div class="wrap aisa-wrap"><hr class="wp-header-end"><div class="aisa-tool">';
		if ( $post_id > 0 ) {
			$this->review( $post_id );
		} else {
			$this->overview();
		}
		echo Ui::layout_close() . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui (the card is AJR Core's own).
	}

	/**
	 * "Quick wins | Biggest prizes": the toggle's links carry a nonce; the choice is the user's own (user meta).
	 */
	protected function save_mode(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified on the next line.
		if ( ! isset( $_GET['mode'], $_GET['_aisa_mode'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_aisa_mode'] ) ), 'aisa_rank_mode' ) ) {
			return;
		}
		$mode = sanitize_key( wp_unslash( $_GET['mode'] ) );
		// phpcs:enable
		if ( in_array( $mode, Ranking::MODES, true ) ) {
			update_user_meta( get_current_user_id(), Ranking::MODE_META, $mode );
		}
	}

	/**
	 * The ranking toggle: Quick wins (default) or Biggest prizes.
	 *
	 * @param string $mode Current mode.
	 */
	protected function mode_toggle( string $mode ): string {
		$labels = [
			'quick' => __( 'Quick wins', 'ai-seo-assistant' ),
			'prize' => __( 'Biggest prizes', 'ai-seo-assistant' ),
		];
		$out    = '<nav class="aisa-modes" aria-label="' . esc_attr__( 'Rank pages by', 'ai-seo-assistant' ) . '">';
		foreach ( $labels as $key => $label ) {
			$url  = wp_nonce_url( $this->url( [ 'mode' => $key ] ), 'aisa_rank_mode', '_aisa_mode' );
			$out .= '<a class="aisa-modes__item' . ( $key === $mode ? ' is-current' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $key === $mode ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}

		return $out . '</nav>';
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

		$actions = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aisa-inline" data-aisa-scan' . ( null !== $queue ? ' data-running="1"' : '' ) . '>'
			. wp_nonce_field( Tools_Actions::RESCAN, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::RESCAN ) . '">'
			. '<button type="submit" class="aisa-btn aisa-btn--dark">' . Ui::icon( 'update' ) . esc_html__( 'Rescan now', 'ai-seo-assistant' ) . '</button></form>'
			. '<span class="aisa-hero__note" data-aisa-scan-status aria-live="polite">' . esc_html(
				null !== $queue
					/* translators: 1: pages done, 2: pages in all. */
					? sprintf( __( 'Scanning: %1$d of %2$d pages', 'ai-seo-assistant' ), (int) $queue['done'], (int) $queue['total'] )
					: __( 'Also runs on each page when it is saved', 'ai-seo-assistant' )
			) . '</span>';

		$hero = Ui::hero(
			[
				/* translators: %s: agency name. */
				'label'   => sprintf( __( 'SEO scan by %s', 'ai-seo-assistant' ), Ui::agency() ),
				'title'   => __( 'SEO scan', 'ai-seo-assistant' ),
				'sub'     => $sub,
				'actions' => $actions,
			]
		);
		echo $hero; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		$this->result_notice();

		$spend = Spend::current();
		$cap   = Spend::cap();
		if ( ! Spend::allows( $spend['usd'], $cap, 'review' ) ) {
			echo Ui::notice( 'warning', '<p><strong>' . esc_html( Spend::cap_message( $spend ) ) . '</strong></p><p><a href="' . esc_url( admin_url( 'admin.php?page=' . Settings_Page::SLUG . '#aisa-cap' ) ) . '">' . esc_html__( 'Raise this site’s cap in Settings', 'ai-seo-assistant' ) . '</a></p>', 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		}

		if ( [] === $rows ) {
			echo Ui::footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			return;
		}

		echo '<div class="aisa-row">';
		$this->issues_card( $rows, $meta );
		$this->spend_card( $spend, $cap );
		echo '</div>';

		$this->listing_card();
		if ( $ranked ) {
			$this->ranked_list( $rows );
		} else {
			$this->first_run( $rows );
		}
		echo Ui::footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
	}

	/**
	 * "Google listing" (J1): the pushed Business Profile check against the site's own business details.
	 * Each difference shows both values and one way to fix it (the Business details row of AJR Core → Your essentials); a difference
	 * the agency pinned in AJR Core is "Kept on purpose", with its reason, and never counted.
	 */
	protected function listing_card(): void {
		$group = Listing::current();
		if ( 'none' === $group['state'] ) {
			return;
		}
		$listing = (array) $group['listing'];
		$when    = (int) ( $listing['checked_at'] ?? 0 );
		$core    = Listing::core_url();
		echo '<section class="aisa-card" id="aisa-listing" aria-labelledby="aisa-listing-h">';
		$head = Ui::card_head(
			'aisa-listing-h',
			'location',
			__( 'Google listing', 'ai-seo-assistant' ),
			/* translators: %s: date. */
			$when > 0 ? sprintf( __( 'Site-wide · compared with your Google listing %s', 'ai-seo-assistant' ), wp_date( 'D j M', $when ) ) : __( 'Site-wide', 'ai-seo-assistant' )
		);
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		if ( 'not_checked' === $group['state'] ) {
			echo '<p class="aisa-pending"><strong>' . esc_html__( 'Google listing not checked.', 'ai-seo-assistant' ) . '</strong> '
				. esc_html__( 'The weekly push could not read the Business Profile this time, so nothing is known about whether it matches.', 'ai-seo-assistant' )
				. ( '' !== (string) ( $listing['reason'] ?? '' ) ? ' ' . esc_html( (string) $listing['reason'] ) : '' ) . '</p></section>';
			return;
		}
		$suggested = count( (array) ( $listing['suggestions'] ?? [] ) );
		if ( $suggested > 0 ) {
			// One line only: AJR Core lists them, on Business details.
			/* translators: %d: number of suggested edits. */
			$line = sprintf( _n( '%d suggested edit for your Google listing', '%d suggested edits for your Google listing', $suggested, 'ai-seo-assistant' ), $suggested );
			echo '<p class="aisa-small">' . ( '' !== $core ? '<a href="' . esc_url( $core ) . '">' . esc_html( $line ) . ' → ' . esc_html__( 'Business details', 'ai-seo-assistant' ) . '</a>' : esc_html( $line ) ) . '</p>';
		}
		if ( [] === $group['issues'] && [] === $group['kept'] ) {
			echo '<p class="aisa-tone--good">' . Ui::icon( 'yes-alt' ) . esc_html__( 'Your website matches your Google listing on every detail compared.', 'ai-seo-assistant' ) . '</p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			return;
		}
		foreach ( $group['issues'] as $f ) {
			$html = '<div class="aisa-listing">'
				. '<p class="aisa-issue__head"><span class="aisa-tag">' . esc_html__( 'Google listing', 'ai-seo-assistant' ) . '</span><strong>' . esc_html( self::listing_title( $f ) ) . '</strong>' . Ui::pill( __( '1 issue', 'ai-seo-assistant' ), 'warn' ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill escaped in Ui.
				. '<p>' . esc_html( (string) $f['message'] ) . '</p>'
				. '<div class="aisa-listing__pair">'
				. '<p class="aisa-listing__value"><span>' . esc_html__( 'Your Google listing', 'ai-seo-assistant' ) . '</span><strong>' . esc_html( '' !== (string) $f['google'] ? (string) $f['google'] : '–' ) . '</strong></p>'
				. '<p class="aisa-listing__value"><span>' . esc_html__( 'Your website', 'ai-seo-assistant' ) . '</span><strong>' . esc_html( '' !== (string) $f['site'] ? (string) $f['site'] : '–' ) . '</strong></p>'
				. '</div>'
				. ( '' !== (string) $f['detail'] ? '<p class="aisa-small">' . esc_html( (string) $f['detail'] ) . '</p>' : '' )
				. '<p class="aisa-listing__fix">'
				. ( '' !== $core
					? '<a class="aisa-btn aisa-btn--small" href="' . esc_url( $core ) . '">' . Ui::icon( 'external' ) . esc_html__( 'Fix in Business details', 'ai-seo-assistant' ) . '</a> <span class="aisa-small">' . esc_html__( 'Opens AJR Core. Google’s listing is the source of truth; the fix is one click there.', 'ai-seo-assistant' ) . '</span>'
					: '<span class="aisa-small">' . esc_html( self::listing_fix( $f ) ) . '</span>' )
				. '</p></div>';
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise above.
		}
		foreach ( $group['kept'] as $f ) {
			$reason = trim( (string) ( $f['pin']['reason'] ?? '' ) );
			echo '<div class="aisa-listing aisa-listing--kept">'
				. '<p class="aisa-issue__head">' . Ui::icon( 'admin-post' ) . '<strong>' . esc_html( self::field_label( (string) $f['field'] ) ) . '</strong>' . Ui::pill( __( 'Kept on purpose', 'ai-seo-assistant' ), 'info' ) . '<span class="aisa-small">' . esc_html__( 'Not counted as an issue', 'ai-seo-assistant' ) . '</span></p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
				/* translators: 1: the agency's reason, 2: Google's value, 3: the website's value. */
				. '<p class="aisa-small">' . esc_html( trim( ( '' !== $reason ? '“' . $reason . '” ' : '' ) . sprintf( __( 'Google: %1$s · Website: %2$s.', 'ai-seo-assistant' ), '' !== (string) $f['google'] ? (string) $f['google'] : '–', '' !== (string) $f['site'] ? (string) $f['site'] : '–' ) . ' ' . self::listing_fix( $f ) ) ) . '</p>'
				. '</div>';
		}
		if ( '' !== (string) ( $listing['maps_url'] ?? '' ) ) {
			echo '<p class="aisa-small"><a href="' . esc_url( (string) $listing['maps_url'] ) . '" rel="noopener noreferrer" target="_blank">' . esc_html__( 'See the listing on Google Maps', 'ai-seo-assistant' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ai-seo-assistant' ) . '</span></a></p>';
		}
		echo '</section>';
	}

	/**
	 * A listing field's name in words.
	 *
	 * @param string $field Field key from the check.
	 */
	protected static function field_label( string $field ): string {
		$labels = [
			'name'           => __( 'Name', 'ai-seo-assistant' ),
			'phone'          => __( 'Phone', 'ai-seo-assistant' ),
			'address'        => __( 'Address', 'ai-seo-assistant' ),
			'service_area'   => __( 'Service area', 'ai-seo-assistant' ),
			'hours'          => __( 'Hours', 'ai-seo-assistant' ),
			'website'        => __( 'Website', 'ai-seo-assistant' ),
			'url'            => __( 'Website', 'ai-seo-assistant' ),
			'type'           => __( 'Business type', 'ai-seo-assistant' ),
			'same_as'        => __( 'Link to your Google listing', 'ai-seo-assistant' ),
			'status'         => __( 'Open or closed', 'ai-seo-assistant' ),
			'listing'        => __( 'Google listing', 'ai-seo-assistant' ),
			'rating'         => __( 'Rating', 'ai-seo-assistant' ),
			'business_node'  => __( 'Business details on the site', 'ai-seo-assistant' ),
			'business_nodes' => __( 'Business details on the site', 'ai-seo-assistant' ),
			'json_ld'        => __( 'Structured data', 'ai-seo-assistant' ),
			'publisher_name' => __( 'Publisher name', 'ai-seo-assistant' ),
		];

		return $labels[ $field ] ?? ucfirst( str_replace( '_', ' ', $field ) );
	}

	/**
	 * A difference's headline ("Hours differ from your Google listing").
	 *
	 * @param array<string,mixed> $f Field row.
	 */
	protected static function listing_title( array $f ): string {
		$label = self::field_label( (string) $f['field'] );
		switch ( (string) $f['status'] ) {
			case 'missing_on_site':
				/* translators: %s: detail, e.g. "Hours". */
				return sprintf( __( '%s missing on your website', 'ai-seo-assistant' ), $label );
			case 'missing_on_google':
				/* translators: %s: detail. */
				return sprintf( __( '%s not on your Google listing', 'ai-seo-assistant' ), $label );
		}

		if ( 'hours' === (string) $f['field'] ) {
			return __( 'Hours differ from your Google listing', 'ai-seo-assistant' );
		}

		/* translators: %s: detail. */
		return sprintf( __( '%s differs from your Google listing', 'ai-seo-assistant' ), $label );
	}

	/**
	 * Where to fix a difference, in words (from the check's own `fix`).
	 *
	 * @param array<string,mixed> $f Field row.
	 */
	protected static function listing_fix( array $f ): string {
		$fix = is_array( $f['fix'] ?? null ) ? $f['fix'] : [];
		$out = [];
		if ( '' !== (string) ( $fix['google'] ?? '' ) ) {
			/* translators: %s: where on Google. */
			$out[] = sprintf( __( 'Fix on Google: %s.', 'ai-seo-assistant' ), (string) $fix['google'] );
		}
		if ( '' !== (string) ( $fix['site'] ?? '' ) ) {
			/* translators: %s: where on the site. */
			$out[] = sprintf( __( 'On the site: %s.', 'ai-seo-assistant' ), (string) $fix['site'] );
		}

		return implode( ' ', $out );
	}

	/**
	 * A result code from our own redirect.
	 */
	protected function result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result code from our own redirect.
		$code     = isset( $_GET['aisa'] ) ? sanitize_key( wp_unslash( $_GET['aisa'] ) ) : '';
		$messages = [
			'rescan'         => [ 'info', __( 'Rescan started. It runs in the background in steps; this screen updates when you reload it.', 'ai-seo-assistant' ) ],
			'kept'           => [ 'warning', __( 'Some fields were not undone: they were changed again after the plugin applied them, and undo never overwrites later work.', 'ai-seo-assistant' ) ],
			'restore_failed' => [ 'error', __( 'The page content could not be put back as it was, and putting it back could not be confirmed: check the page, and restore it from Revisions in the editor if needed.', 'ai-seo-assistant' ) ],
			'undone'         => [ 'success', __( 'Undone: the earlier values are back.', 'ai-seo-assistant' ) ],
			'nothing'        => [ 'info', __( 'Nothing was applied: every field was skipped or already had that value.', 'ai-seo-assistant' ) ],
			'genfail'        => [ 'error', __( 'Claude could not write suggestions for this page. Try again in a minute.', 'ai-seo-assistant' ) ],
			'capped'         => [ 'warning', __( 'The monthly AI cap is reached, so no new suggestions were written.', 'ai-seo-assistant' ) ],
			'type'           => [ 'success', __( 'Page type saved in AJR Core. Google reads it from the next visit; the opportunity score counts it now.', 'ai-seo-assistant' ) ],
			'type_failed'    => [ 'error', __( 'The page type was not changed.', 'ai-seo-assistant' ) ],
			'types'          => [ 'success', __( 'Page types saved in AJR Core and logged in Changes (one Undo for the lot).', 'ai-seo-assistant' ) ],
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
		$group    = Listing::current();
		if ( 'checked' === $group['state'] && [] !== $group['issues'] ) {
			$total += count( $group['issues'] ); // Pinned (kept on purpose) differences are not counted.
			$chips .= '<li><a class="aisa-chip aisa-chip--listing" href="#aisa-listing">' . esc_html__( 'Google listing', 'ai-seo-assistant' ) . ' <span>' . esc_html( number_format_i18n( count( $group['issues'] ) ) ) . '</span></a></li>';
			$chips  = (string) preg_replace( '#(All issues <span>)[^<]*#', '${1}' . esc_html( number_format_i18n( $total ) ), $chips, 1 );
		} elseif ( 'not_checked' === $group['state'] ) {
			$chips .= '<li><a class="aisa-chip aisa-chip--listing" href="#aisa-listing">' . esc_html__( 'Google listing not checked', 'ai-seo-assistant' ) . '</a></li>';
		}

		echo '<section class="aisa-card aisa-card--grow" aria-labelledby="aisa-issues">';
		/* translators: 1: issue count, 2: page count, 3: scan date. */
		echo Ui::card_head( 'aisa-issues', 'search', __( 'Issues found', 'ai-seo-assistant' ), sprintf( __( '%1$s issues on %2$s pages · scanned %3$s', 'ai-seo-assistant' ), number_format_i18n( $total ), number_format_i18n( count( $rows ) ), wp_date( 'D j M', (int) ( $meta['finished_at'] ?? time() ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<ul class="aisa-chips">' . $chips . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<p class="aisa-small">' . esc_html__( 'Checked against the rendered page: title width in pixels, duplicates, headings, image alt text and weight, internal links in and out, broken links and links through redirects, noindex and canonical against the sitemap, schema for the page type, sharing image, word count.', 'ai-seo-assistant' ) . '</p>';
		if ( ! empty( $meta['discouraged'] ) ) {
			echo '<p class="aisa-small aisa-tone--warn">' . esc_html__( 'Search engines are discouraged on this site (Settings › Reading), as on a staging or local copy, so every page says noindex: indexing and sitemap checks are skipped.', 'ai-seo-assistant' ) . '</p>';
		}
		$site = (array) ( $meta['site_issues'] ?? [] );
		if ( [] !== $site ) {
			echo '<h3 class="aisa-small"><strong>' . esc_html__( 'Across the site', 'ai-seo-assistant' ) . '</strong></h3>';
			echo '<p class="aisa-small">' . esc_html__( 'On most pages, so it comes from the theme or a template: fixed once there, not page by page. Not counted in the issues above.', 'ai-seo-assistant' ) . '</p><ul class="aisa-small">';
			foreach ( $site as $issue ) {
				/* translators: 1: finding, 2: pages it is on, 3: what to do. */
				echo '<li>' . esc_html( sprintf( _n( '%1$s (on %2$d page). %3$s', '%1$s (on %2$d pages). %3$s', (int) $issue['pages'], 'ai-seo-assistant' ), (string) $issue['title'], (int) $issue['pages'], (string) $issue['fix'] ) ) . '</li>';
			}
			echo '</ul>';
		}
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
		$mode = Ranking::mode();
		echo '<div class="aisa-modes-row">' . $this->mode_toggle( $mode ) . '<p class="aisa-small">' . esc_html( 'prize' === $mode ? __( 'Ranked by the top-3 prize: pages worth content and link work.', 'ai-seo-assistant' ) : __( 'Ranked by the quick win: what a better title and description bring at today’s position.', 'ai-seo-assistant' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in mode_toggle().
		$this->type_review_bar();
		$this->filters( $q, $issue, $type, $status, $hide );

		// Bulk bar: the JS enables it; without JS each page's Review screen generates one at a time.
		echo '<div class="aisa-bulk" data-aisa-bulk data-per-page="' . esc_attr( (string) round( $per, 4 ) ) . '" data-left="' . esc_attr( (string) round( $left, 2 ) ) . '">';
		$types = Page_Role::core();
		echo '<label class="aisa-check"><input type="checkbox" data-aisa-select-all> <span data-aisa-selected>' . esc_html( $types ? __( 'Select pages to generate suggestions or set their page type', 'ai-seo-assistant' ) : __( 'Select pages to generate suggestions', 'ai-seo-assistant' ) ) . '</span></label>';
		if ( $types ) {
			// Page types live in AJR Core (they also decide the page's schema); setting one here writes there.
			$html = '<span class="aisa-bulk__role"><label class="screen-reader-text" for="aisa-bulk-type">' . esc_html__( 'Page type for the selected pages', 'ai-seo-assistant' ) . '</label>'
				. '<select id="aisa-bulk-type" data-aisa-bulk-role>' . self::type_options( '', true ) . '</select>'
				. '<button type="button" class="aisa-btn" data-aisa-set-role disabled>' . esc_html__( 'Set page type', 'ai-seo-assistant' ) . '</button></span>';
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options escaped in type_options().
		}
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
			. '<th scope="col" class="aisa-num">' . esc_html__( 'Shown · position', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'CTR vs expected', 'ai-seo-assistant' ) . '</th>'
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
				. '<td class="aisa-col-check"><input type="checkbox" value="' . esc_attr( (string) $id ) . '" data-aisa-select aria-label="' . esc_attr( $label ) . '"></td>'
				. '<th scope="row" class="aisa-pagecell"><a class="aisa-pagecell__title" href="' . esc_url( $this->url( [ 'post' => $id ] ) ) . '">' . esc_html( $r['title'] ) . '</a><span class="aisa-pagecell__meta"><span class="aisa-path">' . esc_html( $r['path'] ) . '</span> ' . $this->role_tag( (int) $id, $r ) . ' ' . $badge . '<span class="aisa-row-status" data-aisa-row-status></span></span></th>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pills escaped in Ui; role_tag escapes.
				. '<td class="aisa-col-opp">' . $this->opportunity_cell( $r ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in opportunity_cell().
				. '<td class="aisa-num aisa-col-shown" data-label="' . esc_attr__( 'Shown', 'ai-seo-assistant' ) . '">' . esc_html( number_format_i18n( $r['impressions'] ) ) . '<br><span class="aisa-small aisa-tone--flat">' . esc_html( $r['position'] > 0 ? sprintf( /* translators: %s: average position. */ __( 'position %s', 'ai-seo-assistant' ), number_format_i18n( $r['position'], 1 ) ) : '–' ) . '</span>'
				/* translators: %s: enquiries. */
				. ( $r['enquiries'] > 0 ? '<br><span class="aisa-small">' . esc_html( sprintf( _n( '%s enquiry', '%s enquiries', $r['enquiries'], 'ai-seo-assistant' ), number_format_i18n( $r['enquiries'] ) ) ) . '</span>' : '' ) . '</td>'
				. '<td class="aisa-num aisa-col-ctr" data-label="' . esc_attr__( 'CTR vs expected', 'ai-seo-assistant' ) . '">' . esc_html( Ui::pct( $r['ctr'] ) . ' / ' . Ui::pct( $r['expected'] ) ) . ( null !== $below ? '<br><span class="aisa-small ' . ( $below >= 1 ? 'aisa-tone--bad' : 'aisa-tone--flat' ) . '">' . esc_html( $below > 0 ? sprintf( /* translators: %s: percentage points. */ __( '%s below', 'ai-seo-assistant' ), number_format_i18n( $below, 1 ) ) : __( 'at or above', 'ai-seo-assistant' ) ) . '</span>' : '' ) . '</td>'
				. '<td class="aisa-num aisa-col-issues" data-label="' . esc_attr__( 'Issues', 'ai-seo-assistant' ) . '"><strong>' . esc_html( (string) $r['issue_count'] ) . '</strong>' . ( $r['issue_count'] > 0 && 0 === $r['claude_fixable'] ? '<br><span class="aisa-small aisa-tone--flat">' . esc_html__( 'do in the editor', 'ai-seo-assistant' ) . '</span>' : '' ) . '</td>'
				. '<td class="aisa-col-action"><a class="aisa-btn aisa-btn--small" href="' . esc_url( $this->url( [ 'post' => $id ] ) ) . '">' . esc_html__( 'Review', 'ai-seo-assistant' ) . '<span class="screen-reader-text"> ' . esc_html( $r['title'] ) . '</span></a></td>'
				. '</tr>';
		}
		if ( [] === $shown ) {
			echo '<tr><td colspan="7" class="aisa-empty">' . esc_html__( 'No pages match these filters.', 'ai-seo-assistant' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$this->pagination( count( $filtered ), $paged );
		$curve = Ranking::curve();
		$used  = $curve['site']
			/* translators: %s: number of searches (impressions). */
			? sprintf( __( 'click curve: this site’s own (from %s searches)', 'ai-seo-assistant' ), number_format_i18n( $curve['searches'] ) )
			: ( 'implausible' === ( $curve['reason'] ?? '' ) ? __( 'click curve: standard (this site’s own searches gave an implausible one)', 'ai-seo-assistant' ) : __( 'click curve: standard', 'ai-seo-assistant' ) );
		/* translators: 1: which click curve, 2: pages shown, 3: pages in all. */
		echo '<p class="aisa-small">' . esc_html( sprintf( __( 'Tiers scale with this site: High is the few top pages that together hold half of its opportunity, Medium the pages holding the next quarter, Low the rest. Estimates, a year. Quick win = the extra visits a better title and description could bring at today’s position: for each search, impressions × (expected CTR at that position − actual CTR); searches past position 20 add almost nothing. Top-3 prize = the extra visits if each search reached position 3. Each search is weighted by intent (ready to enquire ×3, comparing ×2, learning ×1, looking for a business by name ×0; searches Google does not name ×0.25) and the page by its type (service and contact ×1.5, area ×1.2, articles ×0.6). The prize counts a search on page 2 at half, page 3 at a fifth, further down at a twentieth. These are estimates, not promises. %1$s. Showing %2$d of %3$d pages.', 'ai-seo-assistant' ), ucfirst( $used ), count( $shown ), $all ) ) . '</p>';
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
		// After an apply or undo, the page is rescanned here, in a fresh request (see Page_Review::rescan()).
		if ( false !== get_transient( Page_Review::RESCAN_FLAG . $post_id ) ) {
			( new Page_Review( new Claude_Client() ) )->rescan_if_flagged( $post_id );
		}
		$row = ( new Scan_Store() )->get( $post_id );
		// Edited since its last scan (the on-save cron event may not have run yet): rescan it now, one page.
		$edited = get_post( $post_id );
		if ( null !== $row && $edited instanceof \WP_Post && $edited->post_modified_gmt > (string) $row['scanned_at'] ) {
			$scanner = new Scanner();
			$scanner->scan_page( $post_id );
			$scanner->finalize( false );
			$row = ( new Scan_Store() )->get( $post_id );
		}
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
			$tiers = self::tiers();
			/* translators: 1: tier (High, Medium, Low), 2: rank (ordinal number), 3: page count. */
			$sub[] = sprintf( 'prize' === $r['mode'] ? __( '%1$s top-3 prize, %2$s of %3$d pages', 'ai-seo-assistant' ) : __( '%1$s quick win, %2$s of %3$d pages', 'ai-seo-assistant' ), $tiers[ $r['tier'] ] ?? '', self::ordinal( (int) $r['rank'] ), count( $ranked ) );
			if ( $r['seen'] ) {
				/* translators: %s: "≈ 390 visits a year". */
				$sub[] = sprintf( __( 'quick win %s', 'ai-seo-assistant' ), self::visits_year( (float) $r['quick_win'] ) );
				/* translators: %s: "≈ 1,200 visits a year". */
				$sub[] = sprintf( __( 'top-3 prize %s', 'ai-seo-assistant' ), self::visits_year( (float) $r['prize'] ) );
			}
		}
		$fixable = count( array_filter( (array) $row['issues'], static fn( $i ) => 'claude' === ( $i['who'] ?? '' ) ) );
		$applied = is_array( $row['suggestions'] ) && ! empty( $row['suggestions']['applied']['batch'] );
		if ( $applied && 0 === $fixable && $row['issue_count'] > 0 ) {
			/* translators: %d: issues left. */
			$sub[] = sprintf( _n( 'Applied · %d left, do in the editor', 'Applied · %d left, do in the editor', $row['issue_count'], 'ai-seo-assistant' ), $row['issue_count'] );
		} elseif ( $applied && 0 === $row['issue_count'] ) {
			$sub[] = __( 'Applied · no issues left', 'ai-seo-assistant' );
		} else {
			/* translators: %d: issues. */
			$sub[] = sprintf( _n( '%d issue', '%d issues', $row['issue_count'], 'ai-seo-assistant' ), $row['issue_count'] );
		}
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
		$hero = Ui::hero(
			[
				/* translators: %s: agency name. */
				'label'      => sprintf( __( 'Page review by %s', 'ai-seo-assistant' ), Ui::agency() ),
				'title'      => wp_strip_all_tags( get_the_title( $post ) ),
				'sub'        => implode( ' · ', $sub ),
				'back_url'   => $this->url( [] ),
				'back_label' => __( 'Back to SEO scan', 'ai-seo-assistant' ),
				'actions'    => $actions,
				'own_column' => true,
			]
		);
		echo $hero; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
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
		$this->searches_card( $page, $r );
		$this->doing_card( $page );
		$this->found_card( $row );
		echo '</div><div class="aisa-review__side">';
		if ( [] !== $applied ) {
			$this->applied_panel( $post_id, $s, $applied );
		} else {
			$this->suggestions_panel( $post_id, $row );
		}
		$this->google_reads( $post_id, $row, $post );
		echo Ui::support_card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AJR Core's own markup, escaped there.
		echo '</div></div>';
		echo Ui::footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
	}

	/**
	 * "What people search for", with where the opportunity is: each search's intent, its quick win (a better
	 * listing at today's position) and its top-3 prize, a year; the rest of the page's impressions on the
	 * last row at its average position.
	 *
	 * @param array<string,mixed>|null $page Page data.
	 * @param array<string,mixed>|null $r    Ranking row.
	 */
	protected function searches_card( ?array $page, ?array $r = null ): void {
		$meta = Page_Data::meta();
		echo '<section class="aisa-card" aria-labelledby="aisa-searches">';
		/* translators: %s: date. */
		echo Ui::card_head( 'aisa-searches', 'search', __( 'What people search for', 'ai-seo-assistant' ), '' !== $meta['end'] ? self::searches_from( sprintf( __( 'Search Console · 90 days to %s', 'ai-seo-assistant' ), Ui::day( $meta['end'] ) ), $meta['country'] ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		$queries = (array) ( $page['gsc']['queries'] ?? [] );
		if ( [] === $queries ) {
			echo '<p class="aisa-pending">' . esc_html( null === $page ? __( 'No search data for this page yet. It arrives with the weekly push; until then Claude writes from the page content alone.', 'ai-seo-assistant' ) : __( 'Google showed this page for no searches in the last 90 days.', 'ai-seo-assistant' ) ) . '</p></section>';
			return;
		}
		$by   = [];
		$rest = null;
		foreach ( (array) ( $r['breakdown'] ?? [] ) as $b ) {
			if ( $b['remain'] ) {
				$rest = $b;
			} else {
				$by[ $b['query'] ] = $b;
			}
		}
		$extra = null !== $r;
		echo '<div class="aisa-tablewrap"><table class="aisa-table aisa-table--searches aisa-table--stack"><caption class="screen-reader-text">' . esc_html__( 'Searches that showed this page', 'ai-seo-assistant' ) . '</caption><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Search', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'Clicks', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'Impressions', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'Position', 'ai-seo-assistant' ) . '</th>'
			. '<th scope="col" class="aisa-num">' . esc_html__( 'CTR', 'ai-seo-assistant' ) . '</th>'
			. ( $extra ? '<th scope="col" class="aisa-num"><abbr title="' . esc_attr__( 'Quick win: extra visits a year from a better listing at today’s position', 'ai-seo-assistant' ) . '">' . esc_html__( 'Quick win', 'ai-seo-assistant' ) . '</abbr></th><th scope="col" class="aisa-num"><abbr title="' . esc_attr__( 'Top-3 prize: extra visits a year at position 3', 'ai-seo-assistant' ) . '">' . esc_html__( 'Top 3', 'ai-seo-assistant' ) . '</abbr></th>' : '' )
			. '</tr></thead><tbody>';
		$main = Scanner::main_query( $page, (string) ( $r['title'] ?? '' ) . ' ' . str_replace( [ '/', '-' ], ' ', (string) ( $r['path'] ?? '' ) ) );
		foreach ( $queries as $q ) {
			$b = $by[ $q['query'] ] ?? null;
			echo '<tr><th scope="row"' . ( $q['query'] === $main ? ' class="aisa-strong"' : '' ) . '>' . esc_html( $q['query'] ) . ( $extra ? ' ' . self::intent_tag( (string) ( $b['intent'] ?? 'unknown' ) ) : '' ) . '</th>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in intent_tag().
				. '<td data-label="' . esc_attr__( 'Clicks', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( number_format_i18n( $q['clicks'] ) ) . '</td><td data-label="' . esc_attr__( 'Impressions', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( number_format_i18n( $q['impressions'] ) ) . '</td><td data-label="' . esc_attr__( 'Position', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( null === $q['position'] ? '–' : number_format_i18n( $q['position'], 1 ) ) . '</td><td data-label="' . esc_attr__( 'CTR', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( Ui::pct( $q['ctr'] ) ) . '</td>'
				. ( $extra ? '<td data-label="' . esc_attr__( 'Quick win / yr', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( self::missed_cell( Opportunity::yearly( (float) ( $b['missed'] ?? 0 ) ) ) ) . '</td><td data-label="' . esc_attr__( 'Top 3 / yr', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( self::missed_cell( Opportunity::yearly( (float) ( $b['prize'] ?? 0 ) ) ) ) . '</td>' : '' )
				. '</tr>';
		}
		if ( null !== $rest ) {
			echo '<tr class="aisa-table__rest"><th scope="row">' . esc_html__( 'Other searches (not named by Google)', 'ai-seo-assistant' ) . ' ' . self::intent_tag( 'unnamed' ) . '</th><td data-label="' . esc_attr__( 'Clicks', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( number_format_i18n( $rest['clicks'] ) ) . '</td><td data-label="' . esc_attr__( 'Impressions', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( number_format_i18n( $rest['impressions'] ) ) . '</td><td data-label="' . esc_attr__( 'Position', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( number_format_i18n( $rest['position'], 1 ) ) . '</td><td data-label="' . esc_attr__( 'CTR', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( Ui::pct( $rest['ctr'] ) ) . '</td><td data-label="' . esc_attr__( 'Quick win / yr', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( self::missed_cell( Opportunity::yearly( (float) $rest['missed'] ) ) ) . '</td><td data-label="' . esc_attr__( 'Top 3 / yr', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( self::missed_cell( Opportunity::yearly( (float) $rest['prize'] ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in intent_tag().
		}
		echo '</tbody></table></div>';
		if ( $extra && 'none' !== $r['method'] ) {
			$enq   = self::enquiries_year( $r['quick_enq'] );
			$prize = self::enquiries_year( $r['prize_enq'] );
			/* translators: 1: quick win, 2: top-3 prize (both "≈ N visits a year"). */
			echo '<p class="aisa-opp-sum"><strong>' . esc_html( sprintf( __( 'Quick win %1$s · Top-3 prize %2$s', 'ai-seo-assistant' ), self::visits_year( (float) $r['quick_win'] ), self::visits_year( (float) $r['prize'] ) ) ) . '</strong>'
				/* translators: 1: enquiries from the quick win, 2: from the prize. */
				. ( '' !== $enq ? '<br><span class="aisa-small">' . esc_html( sprintf( __( 'At this page’s enquiry rate: quick win %1$s, top-3 prize %2$s.', 'ai-seo-assistant' ), $enq, $prize ) ) . '</span>' : '' ) . '</p>';
			echo '<p class="aisa-small">' . esc_html__( 'Estimates, extra visits a year. Quick win = impressions × (expected CTR at today’s position − actual CTR): what a better title and description could bring; searches past position 20 add almost nothing. Top 3 = the same if each search reached position 3. Ranking also weighs each search by its intent (the tag under it).', 'ai-seo-assistant' ) . '</p>';
		}
		$total = (int) ( $page['gsc']['queries_total'] ?? 0 );
		/* translators: 1: searches shown, 2: searches in all. */
		echo '<p class="aisa-small">' . esc_html( $total > count( $queries ) ? sprintf( __( 'Top %1$d of %2$d searches. Claude writes for the first one: most clicks.', 'ai-seo-assistant' ), count( $queries ), $total ) : __( 'Claude writes for the search with the most clicks (in bold).', 'ai-seo-assistant' ) ) . '</p>';
		echo '</section>';
	}

	/**
	 * A search list's source line with its country ("… · searches from the United States").
	 *
	 * @param string $source  Source line.
	 * @param string $country Country code ('' = all countries).
	 */
	protected static function searches_from( string $source, string $country ): string {
		$name = \AJR\SEOAssistant\Report\Report_View::country_name( $country );

		/* translators: 1: source line, 2: country name. */
		return '' === $name ? $source : sprintf( __( '%1$s · searches from %2$s', 'ai-seo-assistant' ), $source, $name );
	}

	/**
	 * A search intent as a small tag.
	 *
	 * @param string $intent Intent.
	 */
	protected static function intent_tag( string $intent ): string {
		$labels = [
			'lead'          => __( 'Ready to enquire', 'ai-seo-assistant' ),
			'commercial'    => __( 'Comparing', 'ai-seo-assistant' ),
			'informational' => __( 'Learning', 'ai-seo-assistant' ),
			'navigational'  => __( 'Looking for you', 'ai-seo-assistant' ),
			'unnamed'       => __( 'Like the ones above', 'ai-seo-assistant' ),
			'unknown'       => __( 'Not sorted', 'ai-seo-assistant' ),
		];

		return '<span class="aisa-intent aisa-intent--' . esc_attr( $intent ) . '">' . esc_html( $labels[ $intent ] ?? $labels['unknown'] ) . '</span>';
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
		echo Ui::card_head( 'aisa-doing', 'chart-line', __( 'How this page is doing', 'ai-seo-assistant' ), sprintf( __( 'All searches · 90 days to %s', 'ai-seo-assistant' ), Ui::day( $meta['end'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<div class="aisa-tiles aisa-tiles--search">' . $tiles . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tile().
		$weeks = (array) $g['weeks'];
		if ( count( $weeks ) >= 2 ) {
			$title  = __( 'Clicks and impressions per week', 'ai-seo-assistant' );
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
			$who = [
				'claude' => Ui::pill( __( 'Claude can fix', 'ai-seo-assistant' ), 'accent' ),
				'click'  => Ui::pill( __( 'One click', 'ai-seo-assistant' ), 'accent' ),
			][ $issue['who'] ] ?? Ui::pill( __( 'Do in the editor', 'ai-seo-assistant' ), 'warn' );
			echo '<li class="aisa-issue"><p class="aisa-issue__head"><span class="aisa-tag">' . esc_html( Rules::label( (string) $issue['kind'] ) ) . '</span><strong>' . esc_html( (string) $issue['title'] ) . '</strong>' . $who . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill escaped in Ui.
				. '<p class="aisa-issue__detail">' . esc_html( (string) $issue['detail'] ) . '</p><p class="aisa-issue__fix">' . esc_html( (string) $issue['fix'] ) . '</p></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Claude's suggestions (C1), the generate button, or the cap message.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $row     Scan row.
	 */
	protected function suggestions_panel( int $post_id, array $row ): void {
		$s       = $row['suggestions'];
		$adapter = Scanner::adapter();
		$claude  = new Claude_Client();
		$spend   = Spend::current();
		$capped  = ! Spend::allows( $spend['usd'], Spend::cap(), 'review' );
		$per     = Page_Review::estimate( $this->past_costs(), $claude->get_model() );

		echo '<section class="aisa-card aisa-card--claude" aria-labelledby="aisa-claude" data-aisa-panel data-writing="' . esc_attr__( 'Claude is reading the page, its searches and how visitors use it. Usually about 20 seconds; you can leave this screen.', 'ai-seo-assistant' ) . '" data-fields="' . esc_attr( implode( '|', [ __( 'SEO title', 'ai-seo-assistant' ), __( 'Meta description', 'ai-seo-assistant' ), __( 'Focus keyphrase', 'ai-seo-assistant' ), __( 'Image alt text', 'ai-seo-assistant' ) ] ) ) . '">';
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

		echo '<p class="aisa-small aisa-lead">' . Ui::icon( 'info-outline' ) . esc_html( sprintf( /* translators: %s: SEO plugin name. */ __( 'Nothing changes on the site until you apply. Title, description and focus keyphrase are saved in %s; alt text in the Media Library and where the page prints it (Divi module, Image block or image tag), checked on the page afterwards.', 'ai-seo-assistant' ), $adapter->get_name() ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon escaped in Ui.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aisa-suggest" data-aisa-apply>';
		wp_nonce_field( Tools_Actions::APPLY );
		echo '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::APPLY ) . '"><input type="hidden" name="post" value="' . esc_attr( (string) $post_id ) . '">';

		$issues = [];
		foreach ( $row['issues'] as $issue ) {
			$issues[ $issue['code'] ] = $issue;
		}
		$title_flag = isset( $issues['title_wide'] ) ? __( 'Too wide', 'ai-seo-assistant' ) : ( isset( $issues['title_duplicate'] ) ? __( 'Duplicate', 'ai-seo-assistant' ) : ( isset( $issues['title_missing'] ) ? __( 'Missing', 'ai-seo-assistant' ) : ( isset( $issues['title_no_query'] ) ? __( 'Misses the main search', 'ai-seo-assistant' ) : '' ) ) );
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
			$id      = (int) $alt['id'];
			$thumb   = wp_get_attachment_image(
				$id,
				[ 56, 56 ],
				false,
				[
					'class' => 'aisa-alt__img',
					'alt'   => '',
				]
			);
			$mode    = (string) ( $alt['mode'] ?? 'write' );
			$check   = 'write' !== $mode;
			$printed = (string) ( $alt['printed'] ?? $alt['now'] );
			$stored  = (string) ( $alt['stored'] ?? '' );
			echo '<li class="aisa-alt' . ( $check ? ' aisa-alt--check' : '' ) . '">' . ( $thumb ? $thumb : '<span class="aisa-alt__img" aria-hidden="true"></span>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built image tag.
				. '<div class="aisa-alt__body"><p><strong>' . esc_html( (string) $alt['file'] ) . '</strong></p>'
				. self::alt_rows( $printed, $stored ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in alt_rows().
				. '<label class="aisa-alt__label" for="aisa-alt-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Suggested:', 'ai-seo-assistant' ) . '</label>'
				. ( 'check' === $mode ? '<p class="aisa-small aisa-tone--warn">' . esc_html__( 'Claude looked at the photo and thinks the current alt is wrong. Not ticked: keep the current one unless you agree.', 'ai-seo-assistant' ) . '</p>' : '' )
				. ( 'sync' === $mode ? '<p class="aisa-small aisa-tone--warn">' . esc_html__( 'The page prints a different alt than the Media Library. Claude looked at the photo; applying writes this alt to both.', 'ai-seo-assistant' ) . '</p>' : '' )
				. '<textarea id="aisa-alt-' . esc_attr( (string) $id ) . '" name="alt[' . esc_attr( (string) $id ) . '][value]" rows="2">' . esc_textarea( (string) $alt['value'] ) . '</textarea>'
				. ( '' !== (string) ( $alt['why'] ?? '' ) ? '<p class="aisa-small">' . esc_html( sprintf( /* translators: %s: reason. */ __( 'Why: %s', 'ai-seo-assistant' ), (string) $alt['why'] ) ) . '</p>' : '' ) . '</div>'
				. '<input type="checkbox" class="aisa-alt__tick" name="alt[' . esc_attr( (string) $id ) . '][apply]" value="1"' . checked( ( $alt['tick'] ?? ! $check ) && '' !== (string) $alt['value'], true, false ) . ' aria-label="' . esc_attr( sprintf( /* translators: %s: file name. */ __( 'Apply the alt text for %s', 'ai-seo-assistant' ), (string) $alt['file'] ) ) . '"></li>';
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
		$items = array_filter( $items, static fn( $i ) => 'schema' !== ( $i['area'] ?? '' ) );
		if ( [] === $items ) {
			return;
		}
		$labels = [
			'headings' => __( 'Headings', 'ai-seo-assistant' ),
			'links'    => __( 'Links', 'ai-seo-assistant' ),
			'content'  => __( 'Content', 'ai-seo-assistant' ),
		];
		$edit   = (string) get_edit_post_link( $post_id, 'url' );
		echo '<div class="aisa-editorbox"><p class="aisa-editorbox__head"><strong>' . Ui::icon( 'edit' ) . esc_html__( 'Do in the editor', 'ai-seo-assistant' ) . '</strong><span class="aisa-small">' . esc_html__( 'Not applied by the plugin', 'ai-seo-assistant' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<p class="aisa-small">' . esc_html__( 'Headings, links and content are left to you: changing them automatically is too risky on builder pages.', 'ai-seo-assistant' ) . '</p><dl>';
		foreach ( $items as $item ) {
			echo '<dt>' . esc_html( $labels[ $item['area'] ] ?? $item['area'] ) . '</dt><dd><span>' . esc_html( (string) $item['advice'] ) . '</span>'
				. ( '' !== $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Open in editor', 'ai-seo-assistant' ) . '</a>' : '' ) . '</dd>';
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
		$live = count( array_filter( $changes, static fn( $c ) => null === $c['undone_at'] && 'content' !== $c['field'] ) );
		if ( 0 === $live ) {
			return; // All undone: the panel says so, with "Start a new review".
		}
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
		$alts    = array_filter( $changes, static fn( $c ) => 'alt' === $c['field'] );
		$printed = [];
		foreach ( (array) ( $s['alts'] ?? [] ) as $a ) {
			$printed[ (int) ( $a['id'] ?? 0 ) ] = (string) ( $a['printed'] ?? $a['now'] ?? '' );
		}
		foreach ( array_reverse( $changes ) as $c ) {
			if ( in_array( $c['field'], [ 'alt', 'content' ], true ) ) {
				continue; // Alt rows are listed below; the content row is the page-side copy of them.
			}
			echo '<div class="aisa-sfield"><div class="aisa-sfield__head"><strong>' . esc_html( $labels[ $c['field'] ] ?? $c['field'] ) . '</strong>' . ( null === $c['undone_at'] ? Ui::pill( __( 'Applied', 'ai-seo-assistant' ), 'good' ) : Ui::pill( __( 'Undone', 'ai-seo-assistant' ), 'muted' ) ) . $this->undo_button( [ (int) $c['id'] ], __( 'Undo', 'ai-seo-assistant' ), null !== $c['undone_at'] ) . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui / undo_button().
				. '<p class="aisa-sfield__label">' . esc_html__( 'Before', 'ai-seo-assistant' ) . '</p><p class="aisa-before">' . esc_html( '' !== $c['before_value'] ? (string) $c['before_value'] : __( '(empty)', 'ai-seo-assistant' ) ) . '</p>'
				/* translators: %s: SEO plugin. */
				. '<p class="aisa-sfield__label aisa-tone--good">' . esc_html( sprintf( __( 'After · now live in %s', 'ai-seo-assistant' ), $adapter->get_name() ) ) . '</p><p class="aisa-after">' . esc_html( (string) $c['after_value'] ) . '</p></div>';
		}
		if ( [] !== $alts ) {
			/* translators: %d: count. */
			echo '<div class="aisa-sfield"><p class="aisa-sfield__head"><strong>' . esc_html__( 'Image alt text', 'ai-seo-assistant' ) . '</strong>' . Ui::pill( sprintf( _n( '%d applied', '%d applied', count( $alts ), 'ai-seo-assistant' ), count( $alts ) ), 'good' ) . '<span class="aisa-small aisa-push">' . esc_html__( 'Written where the page shows it and in the Media Library', 'ai-seo-assistant' ) . '</span></p><ul class="aisa-alts">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			foreach ( $alts as $c ) {
				$thumb = wp_get_attachment_image(
					(int) $c['object_id'],
					[ 56, 56 ],
					false,
					[
						'class' => 'aisa-alt__img',
						'alt'   => '',
					]
				);
				$file  = (string) basename( (string) get_attached_file( (int) $c['object_id'] ) );
				echo '<li class="aisa-alt">' . ( $thumb ? $thumb : '<span class="aisa-alt__img" aria-hidden="true"></span>' ) . '<div class="aisa-alt__body"><p><strong>' . esc_html( $file ) . '</strong></p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built image tag.
					. self::alt_rows( $printed[ (int) $c['object_id'] ] ?? (string) $c['before_value'], (string) $c['before_value'], __( 'before', 'ai-seo-assistant' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in alt_rows().
					. '<p class="aisa-alt__label aisa-tone--good">' . esc_html( null === $c['undone_at'] ? __( 'Now, on the page and in the Media Library:', 'ai-seo-assistant' ) : __( 'Was applied:', 'ai-seo-assistant' ) ) . '</p><p class="aisa-after">' . esc_html( (string) $c['after_value'] ) . '</p></div>'
					. $this->alt_state( $c, (array) ( $s['applied']['alts'][ (int) $c['object_id'] ] ?? [] ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in alt_state().
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
	 * An image's alt where the page shows it and in the Media Library, one per line.
	 *
	 * @param string $printed The alt the page prints.
	 * @param string $stored  The Media Library's alt.
	 * @param string $when    '' now, or e.g. "before".
	 */
	protected static function alt_rows( string $printed, string $stored, string $when = '' ): string {
		$none = '<span class="aisa-tone--bad">' . esc_html__( 'No alt text', 'ai-seo-assistant' ) . '</span>';
		$page = '' !== $when
			/* translators: %s: "before". */
			? sprintf( __( 'Shown on the page (%s)', 'ai-seo-assistant' ), $when )
			: __( 'Shown on the page', 'ai-seo-assistant' );
		$lib = '' !== $when
			/* translators: %s: "before". */
			? sprintf( __( 'Media Library (%s)', 'ai-seo-assistant' ), $when )
			: __( 'Media Library', 'ai-seo-assistant' );

		return '<dl class="aisa-alt__rows">'
			. '<dt>' . esc_html( $page ) . '</dt><dd>' . ( '' === $printed ? $none : esc_html( '“' . $printed . '”' ) ) . '</dd>'
			. '<dt>' . esc_html( $lib ) . '</dt><dd>' . ( '' === $stored ? $none : esc_html( '“' . $stored . '”' ) ) . '</dd>'
			. '</dl>';
	}

	/**
	 * "What Google reads on this page" (I1/I1b): the page type (AJR Core) with a one-click suggestion, whether
	 * the page is linked to the business and the business matches Google, and the structured data found on
	 * the rendered page in plain words. Never Claude-written: schema is never an editor job.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $row     Scan row.
	 * @param \WP_Post            $post    Post.
	 */
	protected function google_reads( int $post_id, array $row, \WP_Post $post ): void {
		$nodes   = (array) ( $row['facts']['schema_nodes'] ?? array_map(
			static fn( $t ) => [
				'type' => (string) $t,
				'name' => '',
				'id'   => '',
			],
			(array) ( $row['facts']['schema'] ?? [] )
		) ); // Older scans have only the @type list; the next scan reads the nodes.
		$core    = Page_Role::core();
		$type    = Page_Role::type_of( $post_id );
		$types   = Page_Role::types();
		$suggest = '' === $type ? Page_Role::suggest( $post_id ) : '';
		$group   = Listing::current();
		$fix_url = Listing::core_url();
		$chips   = Google_Reads::chips( $nodes );
		$missing = Google_Reads::missing( $type, $nodes );

		echo '<section class="aisa-card aisa-greads" id="aisa-greads" aria-labelledby="aisa-greads-h">';
		echo Ui::card_head( 'aisa-greads-h', 'search', __( 'What Google reads on this page', 'ai-seo-assistant' ), $core ? __( 'Set in AJR Core · read by the scan', 'ai-seo-assistant' ) : __( 'Read by the scan', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.

		if ( $core ) {
			$auto = '' !== $type && 'auto' === Page_Role::source( $post_id )
				? ' ' . Ui::pill( __( 'Set automatically', 'ai-seo-assistant' ), 'info' ) . ' <a href="#aisa-page-type" class="aisa-linkbtn">' . esc_html__( 'Change', 'ai-seo-assistant' ) . '</a>'
				: '';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pills escaped in Ui; $auto built from escaped parts above.
			echo '<div class="aisa-greads__type"><p class="aisa-greads__label"><strong>' . esc_html__( 'Page type', 'ai-seo-assistant' ) . '</strong> ' . ( '' !== $type ? Ui::pill( $types[ $type ]['label'] ?? $type, 'good' ) : Ui::pill( __( 'Not set', 'ai-seo-assistant' ), 'warn' ) ) . $auto . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aisa-greads__form">';
			wp_nonce_field( Tools_Actions::ROLE );
			echo '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::ROLE ) . '"><input type="hidden" name="post" value="' . esc_attr( (string) $post_id ) . '">'
				. '<label class="screen-reader-text" for="aisa-page-type">' . esc_html__( 'Page type', 'ai-seo-assistant' ) . '</label>'
				. '<select id="aisa-page-type" name="type">' . self::type_options( $type ) . '</select>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in type_options().
				. '<button type="submit" class="aisa-btn">' . esc_html__( 'Change page type', 'ai-seo-assistant' ) . '</button></form>';
			if ( '' !== $suggest && isset( $types[ $suggest ] ) ) {
				echo '<div class="aisa-greads__suggest"><p>' . Ui::icon( 'admin-customizer' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
					/* translators: %s: page type. */
					. esc_html( sprintf( __( 'Suggested: %s.', 'ai-seo-assistant' ), $types[ $suggest ]['label'] ) ) . ( '' !== $types[ $suggest ]['description'] ? ' ' . esc_html( $types[ $suggest ]['description'] ) : '' ) . '</p>'
					. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( Tools_Actions::ROLE, '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built nonce field.
					. '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::ROLE ) . '"><input type="hidden" name="post" value="' . esc_attr( (string) $post_id ) . '"><input type="hidden" name="type" value="' . esc_attr( $suggest ) . '">'
					/* translators: %s: page type. */
					. '<button type="submit" class="aisa-btn aisa-btn--primary">' . Ui::icon( 'yes' ) . esc_html( sprintf( __( 'Set as %s', 'ai-seo-assistant' ), $types[ $suggest ]['label'] ) ) . '</button>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
					. '<span class="aisa-small">' . esc_html( '' !== $types[ $suggest ]['reads'] ? sprintf( /* translators: %s: what Google then reads. */ __( 'One click. Google then reads %s.', 'ai-seo-assistant' ), $types[ $suggest ]['reads'] ) : __( 'One click.', 'ai-seo-assistant' ) ) . '</span></form></div>';
			}
			if ( '' === $type ) {
				echo '<details class="aisa-greads__types"><summary>' . esc_html__( 'What each page type adds', 'ai-seo-assistant' ) . '</summary><dl>';
				foreach ( $types as $t ) {
					echo '<dt>' . esc_html( $t['label'] ) . '</dt><dd>' . esc_html( $t['reads'] ) . '</dd>';
				}
				echo '</dl></details>';
			}
			echo '</div>';
		} else {
			echo '<p class="aisa-small">' . esc_html__( 'Page types arrive with AJR Core 0.22: they decide what Google reads about each page.', 'ai-seo-assistant' ) . '</p>';
		}

		// Linked to the business, and whether the business matches Google.
		$name = (string) ( Business_Facts::facts()['name'] ?? '' );
		echo '<div class="aisa-greads__biz">';
		if ( Google_Reads::has_business( $nodes ) ) {
			/* translators: %s: business name. */
			echo '<p>' . Ui::icon( 'admin-home' ) . '<strong>' . esc_html( sprintf( __( 'Linked to your business: %s', 'ai-seo-assistant' ), $name ) ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		} else {
			echo '<p class="aisa-tone--warn">' . Ui::icon( 'warning' ) . esc_html__( 'This page does not tell Google which business it belongs to.', 'ai-seo-assistant' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		}
		if ( 'checked' === $group['state'] && [] === $group['issues'] ) {
			echo '<p class="aisa-tone--good">' . Ui::icon( 'yes-alt' ) . esc_html__( 'Matches your Google listing', 'ai-seo-assistant' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		} elseif ( 'checked' === $group['state'] ) {
			/* translators: %s: e.g. "Hours differs from your Google listing". */
			echo '<p class="aisa-tone--warn">' . Ui::icon( 'warning' ) . esc_html( self::listing_title( $group['issues'][0] ) ) . ( count( $group['issues'] ) > 1 ? esc_html( sprintf( _n( ' (and %d more)', ' (and %d more)', count( $group['issues'] ) - 1, 'ai-seo-assistant' ), count( $group['issues'] ) - 1 ) ) : '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		} elseif ( 'not_checked' === $group['state'] ) {
			echo '<p class="aisa-small">' . esc_html__( 'Google listing not checked this week.', 'ai-seo-assistant' ) . '</p>';
		}
		echo '</div>';

		// What Google can read now.
		echo '<div class="aisa-greads__found"><p><strong>' . esc_html( [] !== $chips && '' === $missing ? __( 'Google can read this page', 'ai-seo-assistant' ) : __( 'What Google can read now', 'ai-seo-assistant' ) ) . '</strong></p>';
		if ( [] === $chips ) {
			echo '<p class="aisa-small">' . esc_html__( 'No structured data was found on this page.', 'ai-seo-assistant' ) . '</p>';
		} else {
			echo '<ul class="aisa-chips aisa-chips--plain">';
			foreach ( $chips as $chip ) {
				echo '<li><span class="aisa-chip">' . esc_html( $chip ) . '</span></li>';
			}
			echo '</ul>';
		}
		if ( '' !== $missing ) {
			/* translators: %s: e.g. "Service". */
			echo '<p class="aisa-small">' . esc_html( sprintf( __( 'Missing: %s. AJR Core adds it for this page type.', 'ai-seo-assistant' ), $missing ) ) . '</p>';
		} elseif ( $core && '' === $type ) {
			echo '<p class="aisa-small">' . esc_html__( 'More is added when the page type is set.', 'ai-seo-assistant' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( Google_Reads::rich_results_url( (string) get_permalink( $post ) ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Check in Google’s Rich Results Test', 'ai-seo-assistant' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ai-seo-assistant' ) . '</span></a></p></div>';

		if ( '' !== $fix_url ) {
			echo '<p class="aisa-greads__actions">' . ( $core ? '<a class="aisa-btn aisa-btn--small" href="#aisa-page-type">' . esc_html__( 'Change page type', 'ai-seo-assistant' ) . '</a> ' : '' )
				. '<a class="aisa-btn aisa-btn--small" href="' . esc_url( $fix_url ) . '">' . Ui::icon( 'external' ) . esc_html__( 'Fix in Business details', 'ai-seo-assistant' ) . '</a><span class="aisa-small aisa-push">' . esc_html__( 'Opens AJR Core', 'ai-seo-assistant' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		}
		echo '</section>';
	}

	/**
	 * An applied alt's state: Applied (and shown on the page), Not visible on the page (and why), Undone.
	 *
	 * @param array<string,mixed> $c     Change row.
	 * @param array<string,mixed> $check verify_alts() result for the image.
	 */
	protected function alt_state( array $c, array $check ): string {
		if ( null !== $c['undone_at'] ) {
			return Ui::pill( __( 'Undone', 'ai-seo-assistant' ), 'muted' );
		}
		if ( isset( $check['visible'] ) && ! $check['visible'] ) {
			return '<span class="aisa-altstate">' . Ui::pill( __( 'Not visible on the page', 'ai-seo-assistant' ), 'warn' ) . '<span class="aisa-small">' . esc_html( (string) $check['reason'] ) . '</span></span>';
		}

		return Ui::pill( __( 'Applied', 'ai-seo-assistant' ), 'good' );
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
	 * @param string                   $label  Label.
	 * @param string                   $value  Value (formatted).
	 * @param array{0:string,1:string} $change Text and tone.
	 * @param string                   $series 'clicks' | 'shown' when it doubles as the chart key.
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
	 * A search's missed clicks: "–" for none, one decimal under 10, whole above.
	 *
	 * @param float $missed Missed clicks.
	 */
	protected static function missed_cell( float $missed ): string {
		if ( $missed < 0.05 ) {
			return '–';
		}

		return number_format_i18n( $missed, $missed < 10 ? 1 : 0 );
	}

	/**
	 * "≈ 390 visits a year" ("< 5 visits a year" for a handful).
	 *
	 * @param float $visits Visits a year.
	 */
	protected static function visits_year( float $visits ): string {
		$n = Opportunity::rounded( $visits );
		if ( null === $n ) {
			return __( '< 5 visits a year (est.)', 'ai-seo-assistant' );
		}

		// Always labelled an estimate: a model of what a better listing could bring, never a promise.
		/* translators: %s: number of visits. */
		return sprintf( _n( '≈ %s visit a year (est.)', '≈ %s visits a year (est.)', $n, 'ai-seo-assistant' ), number_format_i18n( $n ) );
	}

	/**
	 * "≈ 6 enquiries a year", or '' when not estimated (fewer than 10 tracked enquiries: never 0).
	 *
	 * @param float|null $enquiries Opportunity::enquiries().
	 */
	protected static function enquiries_year( ?float $enquiries ): string {
		if ( null === $enquiries ) {
			return '';
		}
		if ( $enquiries < 1 ) {
			return __( '< 1 enquiry a year', 'ai-seo-assistant' );
		}
		$n = (int) round( $enquiries );

		/* translators: %s: number of enquiries. */
		return sprintf( _n( '≈ %s enquiry a year', '≈ %s enquiries a year', $n, 'ai-seo-assistant' ), number_format_i18n( $n ) );
	}

	/**
	 * Tier labels.
	 *
	 * @return array<string,string>
	 */
	protected static function tiers(): array {
		return [
			'high'   => __( 'High', 'ai-seo-assistant' ),
			'medium' => __( 'Medium', 'ai-seo-assistant' ),
			'low'    => __( 'Low', 'ai-seo-assistant' ),
			'none'   => __( 'None', 'ai-seo-assistant' ),
		];
	}

	/**
	 * The list's Opportunity cell: tier chip, quick win, top-3 prize (all estimates, a year).
	 *
	 * @param array<string,mixed> $r Ranking row.
	 */
	protected function opportunity_cell( array $r ): string {
		if ( ! $r['seen'] ) {
			return '<span class="aisa-small aisa-tone--flat">' . esc_html__( 'Not seen on Google', 'ai-seo-assistant' ) . '</span>';
		}
		$tiers = self::tiers();
		$enq   = self::enquiries_year( $r['quick_enq'] );
		$enq3  = self::enquiries_year( $r['prize_enq'] );
		/* translators: %s: "≈ 390 visits a year". */
		$quick = esc_html( sprintf( __( 'Quick win %s', 'ai-seo-assistant' ), self::visits_year( (float) $r['quick_win'] ) ) ) . ( '' !== $enq ? ' · ' . esc_html( $enq ) : '' );
		/* translators: %s: "≈ 1,200 visits a year". */
		$prize = esc_html( sprintf( __( 'Top-3 prize %s', 'ai-seo-assistant' ), self::visits_year( (float) $r['prize'] ) ) ) . ( '' !== $enq3 ? ' · ' . esc_html( $enq3 ) : '' );
		// The figure the list is ranked by comes first; the other underneath, smaller.
		$lines = 'prize' === $r['mode']
			? '<span class="aisa-opp__line">' . $prize . '</span><span class="aisa-opp__line aisa-small">' . $quick . '</span>'
			: '<span class="aisa-opp__line">' . $quick . '</span>' . ( (float) $r['prize'] >= 5 ? '<span class="aisa-opp__line aisa-small">' . $prize . '</span>' : '' );

		return '<span class="aisa-opp"><span class="aisa-tier aisa-tier--' . esc_attr( (string) $r['tier'] ) . '">' . esc_html( $tiers[ $r['tier'] ] ?? '' ) . '</span>' . $lines . '</span>';
	}

	/**
	 * The page type options for a select (AJR Core's types).
	 *
	 * @param string $current Selected type ('' none).
	 * @param bool   $bulk    For the bulk bar (a "Page type…" prompt first).
	 */
	protected static function type_options( string $current, bool $bulk = false ): string {
		$out = $bulk
			? '<option value="">' . esc_html__( 'Page type…', 'ai-seo-assistant' ) . '</option>'
			: '<option value=""' . selected( $current, '', false ) . '>' . esc_html__( 'Not set', 'ai-seo-assistant' ) . '</option>';
		foreach ( Page_Role::types() as $key => $t ) {
			$out .= '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( $t['label'] ) . '</option>';
		}
		if ( $bulk ) {
			$out .= '<option value="clear">' . esc_html__( 'Clear (not set)', 'ai-seo-assistant' ) . '</option>';
		}

		return $out;
	}

	/**
	 * "N pages have a suggested page type → Review and apply all": AJR Core's medium-confidence guesses for
	 * pages with no type. Opened, it lists each page, the type and why, all ticked, applied in one request.
	 */
	protected function type_review_bar(): void {
		if ( ! Page_Role::core() ) {
			return;
		}
		$types   = Page_Role::types();
		$waiting = array_filter(
			(array) ( Scan_Store::meta()['type_review'] ?? [] ),
			static fn( $s, $id ) => isset( $types[ $s['type'] ?? '' ] ) && '' === Page_Role::type_of( (int) $id ),
			ARRAY_FILTER_USE_BOTH
		);
		if ( [] === $waiting ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$open = isset( $_GET['types'] ) && 'review' === sanitize_key( wp_unslash( $_GET['types'] ) );
		/* translators: %d: number of pages. */
		$line = sprintf( _n( '%d page has a suggested page type', '%d pages have a suggested page type', count( $waiting ), 'ai-seo-assistant' ), count( $waiting ) );
		if ( ! $open ) {
			echo '<p class="aisa-typebar">' . Ui::icon( 'admin-customizer' ) . '<strong>' . esc_html( $line ) . '</strong> <a class="aisa-btn aisa-btn--small" href="' . esc_url( $this->url( [ 'types' => 'review' ] ) . '#aisa-typereview' ) . '">' . esc_html__( 'Review and apply all', 'ai-seo-assistant' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aisa-typereview" id="aisa-typereview">';
		wp_nonce_field( Tools_Actions::TYPES );
		echo '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::TYPES ) . '">';
		echo '<fieldset><legend><strong>' . esc_html( $line ) . '</strong> <span class="aisa-small">' . esc_html__( 'Untick any you disagree with. Each one can be changed later, and the lot can be undone in Changes.', 'ai-seo-assistant' ) . '</span></legend><ul>';
		foreach ( $waiting as $id => $s ) {
			$field = 'aisa-type-' . (int) $id;
			echo '<li><input type="checkbox" id="' . esc_attr( $field ) . '" name="ids[]" value="' . esc_attr( (string) $id ) . '" checked> <label for="' . esc_attr( $field ) . '"><strong>' . esc_html( wp_strip_all_tags( (string) get_the_title( (int) $id ) ) ) . '</strong> → ' . esc_html( (string) $types[ $s['type'] ]['label'] ) . ( '' !== (string) ( $s['reason'] ?? '' ) ? ' <span class="aisa-small">' . esc_html( (string) $s['reason'] ) . '</span>' : '' ) . '</label></li>';
		}
		echo '</ul></fieldset><p><button type="submit" class="aisa-btn aisa-btn--primary">' . esc_html__( 'Apply the ticked page types', 'ai-seo-assistant' ) . '</button> <a href="' . esc_url( $this->url( [] ) ) . '">' . esc_html__( 'Cancel', 'ai-seo-assistant' ) . '</a></p></form>';
	}

	/**
	 * The page type tag on a list row: a small select writing to AJR Core (one click, via the script);
	 * without AJR Core's page types, the derived role as a plain tag.
	 *
	 * @param int                 $id Post ID.
	 * @param array<string,mixed> $r  Ranking row.
	 */
	protected function role_tag( int $id, array $r ): string {
		$roles = [
			'money'        => __( 'counts as a money page', 'ai-seo-assistant' ),
			'location'     => __( 'counts as an area page', 'ai-seo-assistant' ),
			'info'         => __( 'counts as information', 'ai-seo-assistant' ),
			'unclassified' => __( 'counts as unclassified', 'ai-seo-assistant' ),
		];
		$title = $roles[ $r['role'] ] ?? '';
		if ( $r['bumped'] ) {
			$title .= ' · ' . __( 'one step higher: enquiry rate twice the site’s', 'ai-seo-assistant' );
		}
		if ( ! Page_Role::core() ) {
			return '<span class="aisa-roletag aisa-roletag--' . esc_attr( (string) $r['role'] ) . '" title="' . esc_attr( $title ) . '">' . esc_html( ucfirst( (string) $r['role'] ) ) . '</span>';
		}
		/* translators: %s: page title. */
		$aria = sprintf( __( 'Page type of %s', 'ai-seo-assistant' ), $r['title'] );

		return '<select class="aisa-roletag aisa-roletag--' . esc_attr( (string) $r['role'] ) . ( $r['role_set'] ? ' is-set' : '' ) . '" data-aisa-role="' . esc_attr( (string) $id ) . '" aria-label="' . esc_attr( $aria ) . '" title="' . esc_attr( ( $r['role_set'] ? '' : __( 'Not set: ', 'ai-seo-assistant' ) ) . $title ) . '">'
			. self::type_options( (string) $r['page_type'] ) . '</select>'
			. ( 'auto' === ( $r['type_source'] ?? '' ) ? ' <span class="aisa-pill aisa-pill--info" title="' . esc_attr__( 'Set automatically: change it in the list, it then stays as you set it', 'ai-seo-assistant' ) . '">' . esc_html__( 'auto', 'ai-seo-assistant' ) . '</span>' : '' )
			. ( $r['bumped'] ? '<span class="aisa-small aisa-tone--good" title="' . esc_attr( $title ) . '">↑</span>' : '' );
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
