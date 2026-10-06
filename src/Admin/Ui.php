<?php
/**
 * Ui — the pieces every agency screen shares: the dark header, card headings, chips, icons, money.
 *
 * Built from the approved 5.0 mockup (Figma cWMjcUjT9snr4r3cYhTsx1): the same dark AJR header and white
 * cards as the Weekly report, so the tools and the report read as one product. Markup only; the colours
 * are tokens in assets/css/weekly-report.css (declared once) and the components in assets/css/aisa-tools.css.
 * Everything printed is escaped here.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Report\Snapshot_Store;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Shared admin markup.
 */
class Ui {

	/** Tools stylesheet handle. */
	public const STYLE = 'ai-seo-assistant-tools';

	/** Tools script handle. */
	public const SCRIPT = 'ai-seo-assistant-tools';

	/** Report stylesheet handle (tokens, cards, tiles). */
	public const BASE_STYLE = 'ai-seo-assistant-weekly-report';

	/** AJAX nonce action for the tool screens. */
	public const NONCE = 'aisa_tools';

	/**
	 * Enqueue the tool screens' CSS and JS.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::BASE_STYLE, AI_SEO_ASSISTANT_URL . 'assets/css/weekly-report.css', [ 'dashicons' ], AI_SEO_ASSISTANT_VERSION );
		wp_enqueue_style( self::STYLE, AI_SEO_ASSISTANT_URL . 'assets/css/aisa-tools.css', [ self::BASE_STYLE ], AI_SEO_ASSISTANT_VERSION );
		wp_enqueue_script(
			self::SCRIPT,
			AI_SEO_ASSISTANT_URL . 'assets/js/aisa-tools.js',
			[],
			AI_SEO_ASSISTANT_VERSION,
			[
				'strategy'  => 'defer',
				'in_footer' => true,
			]
		);
		wp_localize_script(
			self::SCRIPT,
			'aisaTools',
			[
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE ),
				'i18n'  => [
					/* translators: 1: pages done, 2: pages in all. */
					'scanning'     => __( 'Scanning: %1$d of %2$d pages', 'ai-seo-assistant' ),
					'finishing'    => __( 'Checking links, duplicates and the sitemap…', 'ai-seo-assistant' ),
					'done'         => __( 'Scan finished. Reloading…', 'ai-seo-assistant' ),
					'failed'       => __( 'The scan stopped. Try again, or wait: it carries on in the background.', 'ai-seo-assistant' ),
					/* translators: 1: page number, 2: pages in all. */
					'writing'      => __( 'Writing suggestions: page %1$d of %2$d', 'ai-seo-assistant' ),
					'written'      => __( 'Suggestions written. Reloading…', 'ai-seo-assistant' ),
					'writingField' => __( 'Writing…', 'ai-seo-assistant' ),
					/* translators: 1: page count, 2: estimated cost, e.g. "$0.09". */
					'generateN'    => __( 'Generate for selected (%1$d), about %2$s', 'ai-seo-assistant' ),
					/* translators: %d: page count. */
					'selected'     => __( '%d pages selected', 'ai-seo-assistant' ),
					'selectHint'   => __( 'Select pages to generate suggestions or set their role', 'ai-seo-assistant' ),
					'savingRole'   => __( 'Saving the role…', 'ai-seo-assistant' ),
					'roleFailed'   => __( 'The role was not changed.', 'ai-seo-assistant' ),
					/* translators: 1: width in px, 2: limit in px. */
					'fitPx'        => __( '%1$d px of about %2$d px', 'ai-seo-assistant' ),
					/* translators: 1: characters, 2: limit. */
					'fitChars'     => __( '%1$d of about %2$d characters', 'ai-seo-assistant' ),
				],
			]
		);
	}

	/**
	 * The dark header.
	 *
	 * @param array<string,mixed> $a label (e.g. "SEO scan by AJR Web Design"), title, sub, back_url, back_label,
	 *                               actions (HTML, already escaped), fresh (bool: show the data pill).
	 */
	public static function hero( array $a ): string {
		$back = '';
		if ( ! empty( $a['back_url'] ) ) {
			$back = '<a class="aisa-back" href="' . esc_url( (string) $a['back_url'] ) . '">' . self::icon( 'arrow-left-alt2' ) . esc_html( (string) $a['back_label'] ) . '</a>';
		}

		return '<header class="aisa-hero aisa-hero--tool">'
			. '<div class="aisa-hero__top">' . self::brand( (string) $a['label'] ) . ( ( $a['fresh'] ?? true ) ? self::fresh() : '' ) . '</div>'
			. $back
			. '<h1 class="aisa-hero__title">' . esc_html( (string) $a['title'] ) . '</h1>'
			. ( ! empty( $a['sub'] ) ? '<p class="aisa-hero__sub">' . esc_html( (string) $a['sub'] ) . '</p>' : '' )
			. ( ! empty( $a['actions'] ) ? '<div class="aisa-hero__actions">' . $a['actions'] . '</div>' : '' ) // Escaped by the caller.
			. '</header>';
	}

	/**
	 * The brand block ("AI SEO Assistant / SEO scan by AJR Web Design").
	 *
	 * @param string $label Second line.
	 */
	public static function brand( string $label ): string {
		return '<p class="aisa-brand"><span class="aisa-brand__mark" aria-hidden="true">' . self::icon( 'chart-line' ) . '</span><span><strong>' . esc_html__( 'AI SEO Assistant', 'ai-seo-assistant' ) . '</strong><br>' . esc_html( $label ) . '</span></p>';
	}

	/**
	 * The data-freshness pill: "Search data to Sun 4 Oct, pushed Mon 5 Oct", or "Waiting for the first push".
	 */
	public static function fresh(): string {
		$meta = Page_Data::meta();
		$last = (int) get_option( Snapshot_Store::LAST_PUSH, 0 );
		if ( '' !== $meta['end'] ) {
			/* translators: 1: last day the search data covers, 2: day it arrived. */
			$text = sprintf( __( 'Search data to %1$s, pushed %2$s', 'ai-seo-assistant' ), self::day( $meta['end'] ), wp_date( 'D j M', $meta['generated_at'] ) );
			$late = time() - $meta['generated_at'] > 8 * DAY_IN_SECONDS;
		} elseif ( $last > 0 ) {
			/* translators: %s: date the last weekly report arrived. */
			$text = sprintf( __( 'Weekly report received %s; no per-page search data yet', 'ai-seo-assistant' ), wp_date( 'D j M', $last ) );
			$late = true;
		} else {
			$text = __( 'Waiting for the first push', 'ai-seo-assistant' );
			$late = true;
		}

		return '<p class="aisa-fresh' . ( $late ? ' aisa-fresh--late' : '' ) . '"><span class="aisa-fresh__dot" aria-hidden="true"></span>' . esc_html( $text ) . '</p>';
	}

	/**
	 * A card's heading row.
	 *
	 * @param string $id     Heading ID.
	 * @param string $icon   Dashicon.
	 * @param string $title  Title.
	 * @param string $source Right-hand note.
	 * @param int    $level  Heading level.
	 */
	public static function card_head( string $id, string $icon, string $title, string $source = '', int $level = 2 ): string {
		return '<div class="aisa-card__head"><h' . $level . ' class="aisa-card__title" id="' . esc_attr( $id ) . '">' . self::icon( $icon ) . esc_html( $title ) . '</h' . $level . '>'
			. ( '' !== $source ? '<p class="aisa-card__source">' . esc_html( $source ) . '</p>' : '' ) . '</div>';
	}

	/**
	 * A small status pill.
	 *
	 * @param string $text Text.
	 * @param string $tone good | warn | bad | info | muted | accent.
	 */
	public static function pill( string $text, string $tone = 'muted' ): string {
		return '<span class="aisa-pill aisa-pill--' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * A Dashicon (decorative).
	 *
	 * @param string $name Dashicon name without the prefix.
	 */
	public static function icon( string $name ): string {
		return '<span class="aisa-icon dashicons dashicons-' . esc_attr( $name ) . '" aria-hidden="true"></span>';
	}

	/**
	 * The footer line every tool screen ends with.
	 */
	public static function footer(): string {
		/* translators: %s: agency name. */
		return '<footer class="aisa-foot">' . self::icon( 'lock' ) . '<p>' . esc_html( sprintf( __( 'Search and visitor data arrive with the weekly push from %s; this site holds no Google login. Only agency users see this screen.', 'ai-seo-assistant' ), self::agency() ) ) . '</p></footer>';
	}

	/**
	 * The agency's name.
	 */
	public static function agency(): string {
		/** This filter is documented in src/Report/Report_Page.php. */
		return (string) apply_filters( 'ai_seo_assistant_report_agency', 'AJR Web Design' );
	}

	/**
	 * "Sun 4 Oct" for a Y-m-d calendar date.
	 *
	 * @param string $date Y-m-d.
	 */
	public static function day( string $date ): string {
		$ts = strtotime( $date . ' 12:00 UTC' );

		return false === $ts ? '' : (string) wp_date( 'D j M', $ts, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * A percent with one decimal ("1.1%").
	 *
	 * @param float|null $value Percent.
	 */
	public static function pct( $value ): string {
		return null === $value ? '–' : number_format_i18n( (float) $value, 1 ) . '%';
	}

	/**
	 * A notice inside the screen.
	 *
	 * @param string $tone success | warning | error | info.
	 * @param string $html Message HTML (escaped by the caller).
	 * @param string $icon Dashicon.
	 */
	public static function notice( string $tone, string $html, string $icon = 'info-outline' ): string {
		return '<div class="aisa-notice aisa-notice--' . esc_attr( $tone ) . '" role="' . ( 'error' === $tone ? 'alert' : 'status' ) . '">' . self::icon( $icon ) . '<div>' . $html . '</div></div>';
	}
}
