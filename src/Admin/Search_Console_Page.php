<?php
/**
 * Search_Console_Page — AI SEO Assistant → Search Console (mockup D1), a VIEW of the pushed data.
 *
 * The on-site Google sign-in and its stored refresh token were removed in 5.0 (decision 2026-10-06): this
 * screen reads only what the weekly push brought. Whole site: last week's clicks, impressions, CTR and
 * position against the week before, with the 12-week lines. Pages: each page's 90-day figures and top
 * search; open a page to see its searches, with a link to its review in the SEO scan.
 *
 * Agency only. Every query, title and path came from Google: escaped at output.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Report\Access;
use AJR\SEOAssistant\Report\Chart;
use AJR\SEOAssistant\Report\Format;
use AJR\SEOAssistant\Report\Report_View;
use AJR\SEOAssistant\Report\Snapshot_Store;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Scan\Scanner;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * The Search Console view.
 */
class Search_Console_Page {

	/** Menu slug. */
	public const SLUG = 'ai-seo-assistant-search';

	/** Pages listed per screen. */
	public const PER_PAGE = 100;

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			return;
		}
		$latest = ( new Snapshot_Store() )->latest();
		echo '<div class="wrap aisa-wrap"><hr class="wp-header-end"><div class="aisa-tool">';
		$hero = Ui::hero(
			[
				/* translators: %s: agency name. */
				'label' => sprintf( __( 'Search Console by %s', 'ai-seo-assistant' ), Ui::agency() ),
				'title' => __( 'Search Console', 'ai-seo-assistant' ),
				/* translators: %s: business name. */
				'sub'   => sprintf( __( 'How %s shows up on Google: the whole site, each page and the searches behind it. From the weekly push.', 'ai-seo-assistant' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			]
		);
		echo $hero; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		$this->site( $latest );
		$this->pages();
		$this->freshness();
		echo Ui::layout_close() . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui (the card is AJR Core's own).
	}

	/**
	 * Whole site: last week's tiles and the 12-week lines.
	 *
	 * @param array<string,mixed>|null $snap Latest weekly snapshot.
	 */
	protected function site( ?array $snap ): void {
		$gsc = is_array( $snap ) ? ( $snap['gsc'] ?? null ) : null;
		echo '<section class="aisa-card" aria-labelledby="aisa-site">';
		if ( ! is_array( $gsc ) ) {
			echo Ui::card_head( 'aisa-site', 'search', __( 'Whole site', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			echo '<p class="aisa-pending">' . esc_html__( 'No Search Console figures have arrived yet. They come with the weekly push.', 'ai-seo-assistant' ) . '</p></section>';
			return;
		}
		/* translators: %s: the week. */
		echo Ui::card_head( 'aisa-site', 'search', __( 'Whole site', 'ai-seo-assistant' ), Report_View::with_country( sprintf( __( 'Google Search Console · week of %s · change on the week before', 'ai-seo-assistant' ), Report_View::range( $snap['week'], false ) ), $snap['gsc']['country'] ?? null ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		$w = $gsc['week'];
		echo '<div class="aisa-tiles aisa-tiles--search">';
		foreach ( [
			[ __( 'Clicks', 'ai-seo-assistant' ), $w['clicks'], Format::PERCENT, 'clicks', '' ],
			[ __( 'Impressions', 'ai-seo-assistant' ), $w['impressions'], Format::PERCENT, 'shown', '' ],
			[ __( 'CTR', 'ai-seo-assistant' ), $w['ctr'], Format::POINTS, '', '%' ],
			[ __( 'Average position', 'ai-seo-assistant' ), $w['position'], Format::PLACES, '', '' ],
		] as [ $label, $metric, $kind, $series, $suffix ] ) {
			if ( null === $metric ) {
				continue;
			}
			$value  = Format::PLACES === $kind || '%' === $suffix ? number_format_i18n( (float) $metric['value'], 1 ) : Format::short( $metric['value'] );
			$change = Format::change( $metric['value'], $metric['previous'], $kind );
			echo '<div class="aisa-metric' . ( '' !== $series ? ' aisa-metric--key aisa-metric--' . esc_attr( $series ) : '' ) . '"><p class="aisa-metric__label">' . esc_html( $label ) . '</p><p class="aisa-metric__value">' . esc_html( $value . $suffix ) . '</p><p class="aisa-metric__change aisa-tone--' . esc_attr( $change['tone'] ) . '">' . esc_html( $change['text'] ) . '</p></div>';
		}
		echo '</div>';
		if ( count( $gsc['clicks_12w'] ) >= 2 ) {
			$title = __( 'Clicks and impressions per week, last 12 weeks', 'ai-seo-assistant' );
			echo '<figure class="aisa-figure aisa-figure--wide">' . Chart::lines( $gsc['clicks_12w'], count( $gsc['impressions_12w'] ) === count( $gsc['clicks_12w'] ) ? $gsc['impressions_12w'] : [], Report_View::week_labels( $snap['week']['start'], count( $gsc['clicks_12w'] ) ), $title ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Chart escapes its own output.
				. '<figcaption class="aisa-small">' . esc_html__( 'Solid line: clicks per week (left scale). Dashed line: impressions per week (right scale).', 'ai-seo-assistant' ) . '</figcaption></figure>';
		}
		echo '</section>';
	}

	/**
	 * Pages with their 90-day figures and searches.
	 */
	protected function pages(): void {
		$data = ( new Page_Data() )->all();
		$meta = Page_Data::meta();
		echo '<section class="aisa-card" aria-labelledby="aisa-pagelist">';
		if ( [] === $data ) {
			echo Ui::card_head( 'aisa-pagelist', 'media-document', __( 'Pages', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			echo '<p class="aisa-pending">' . esc_html__( 'Per-page search data has not arrived yet. It comes with the weekly push once this site is switched to snapshot v2.', 'ai-seo-assistant' ) . '</p></section>';
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$sort  = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'clicks';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$posts = [];
		foreach ( ( new Scan_Store() )->summaries() as $id => $row ) {
			$posts[ Scanner::norm_path( (string) $row['path'] ) ] = $id;
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( $posts ), false, false ); // Every title in one query.
		}
		$rows = [];
		foreach ( $data as $path => $page ) {
			$g = $page['gsc'];
			if ( ! is_array( $g ) ) {
				continue; // Seen by Analytics only: nothing for this screen.
			}
			$id    = $posts[ Scanner::norm_path( (string) $path ) ] ?? 0;
			$title = $id ? wp_specialchars_decode( (string) get_the_title( $id ), ENT_QUOTES ) : '';
			if ( '' !== $q && false === stripos( $title . ' ' . $path, $q ) ) {
				continue;
			}
			$rows[] = [
				'path'   => (string) $path,
				'id'     => $id,
				'title'  => '' !== $title ? $title : (string) $path,
				'g'      => $g,
				'change' => null !== $g['prev_clicks'] && $g['prev_clicks'] > 0 ? ( $g['clicks'] - $g['prev_clicks'] ) / $g['prev_clicks'] * 100 : null,
				'top'    => Scanner::main_query( $page ),
			];
		}
		$keys = [
			'clicks'      => static fn( $r ) => -$r['g']['clicks'],
			'impressions' => static fn( $r ) => -$r['g']['impressions'],
			'change'      => static fn( $r ) => -( $r['change'] ?? -1000 ),
			'position'    => static fn( $r ) => $r['g']['position'] ?? 1000,
			'ctr'         => static fn( $r ) => $r['g']['ctr'] ?? 0,
		];
		$key  = $keys[ $sort ] ?? $keys['clicks'];
		usort( $rows, static fn( $a, $b ) => $key( $a ) <=> $key( $b ) );
		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		/* translators: 1: page count, 2: date. */
		echo Ui::card_head( 'aisa-pagelist', 'media-document', __( 'Pages', 'ai-seo-assistant' ), sprintf( __( '%1$d pages · all searches · 90 days to %2$s · change is on the 90 days before', 'ai-seo-assistant' ), $total, Ui::day( $meta['end'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<form method="get" class="aisa-filters" role="search"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label class="aisa-field aisa-field--search"><span class="screen-reader-text">' . esc_html__( 'Filter pages by title or address', 'ai-seo-assistant' ) . '</span>' . Ui::icon( 'search' ) . '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Filter pages by title or address', 'ai-seo-assistant' ) . '"></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<label class="aisa-field"><span class="screen-reader-text">' . esc_html__( 'Sort', 'ai-seo-assistant' ) . '</span><select name="sort">';
		foreach ( [
			'clicks'      => __( 'Sort: clicks', 'ai-seo-assistant' ),
			'impressions' => __( 'Sort: impressions', 'ai-seo-assistant' ),
			'change'      => __( 'Sort: change', 'ai-seo-assistant' ),
			'position'    => __( 'Sort: position', 'ai-seo-assistant' ),
			'ctr'         => __( 'Sort: lowest CTR', 'ai-seo-assistant' ),
		] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $sort, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><button type="submit" class="aisa-btn aisa-btn--small">' . esc_html__( 'Apply', 'ai-seo-assistant' ) . '</button><span class="aisa-small aisa-push">' . esc_html__( 'Open a page to see its searches', 'ai-seo-assistant' ) . '</span></form>';

		echo '<div class="aisa-sc"><div class="aisa-sc__head" aria-hidden="true"><span>' . esc_html__( 'Page', 'ai-seo-assistant' ) . '</span><span class="aisa-num">' . esc_html__( 'Clicks', 'ai-seo-assistant' ) . '</span><span class="aisa-num">' . esc_html__( 'Change', 'ai-seo-assistant' ) . '</span><span class="aisa-num">' . esc_html__( 'Impressions', 'ai-seo-assistant' ) . '</span><span class="aisa-num">' . esc_html__( 'CTR', 'ai-seo-assistant' ) . '</span><span class="aisa-num">' . esc_html__( 'Position', 'ai-seo-assistant' ) . '</span><span>' . esc_html__( 'Top search', 'ai-seo-assistant' ) . '</span></div>';
		foreach ( $rows as $r ) {
			$g      = $r['g'];
			$change = null === $r['change'] ? [ '', 'flat' ] : ( abs( $r['change'] ) < 0.5 ? [ __( 'same', 'ai-seo-assistant' ), 'flat' ] : [ ( $r['change'] > 0 ? __( 'up', 'ai-seo-assistant' ) : __( 'down', 'ai-seo-assistant' ) ) . ' ' . number_format_i18n( abs( $r['change'] ) ) . '%', $r['change'] > 0 ? 'good' : 'bad' ] );
			echo '<details class="aisa-sc__row"><summary>'
				. '<span class="aisa-pagecell"><span class="aisa-pagecell__title">' . esc_html( $r['title'] ) . '</span><span class="aisa-path">' . esc_html( $r['path'] ) . '</span></span>'
				. '<span class="aisa-num" data-label="' . esc_attr__( 'Clicks', 'ai-seo-assistant' ) . '">' . esc_html( number_format_i18n( $g['clicks'] ) ) . '</span>'
				. '<span class="aisa-num aisa-tone--' . esc_attr( $change[1] ) . '" data-label="' . esc_attr__( 'Change', 'ai-seo-assistant' ) . '">' . esc_html( $change[0] ) . '</span>'
				. '<span class="aisa-num" data-label="' . esc_attr__( 'Impressions', 'ai-seo-assistant' ) . '">' . esc_html( number_format_i18n( $g['impressions'] ) ) . '</span>'
				. '<span class="aisa-num" data-label="' . esc_attr__( 'CTR', 'ai-seo-assistant' ) . '">' . esc_html( Ui::pct( $g['ctr'] ) ) . '</span>'
				. '<span class="aisa-num" data-label="' . esc_attr__( 'Position', 'ai-seo-assistant' ) . '">' . esc_html( null === $g['position'] ? '–' : number_format_i18n( (float) $g['position'], 1 ) ) . '</span>'
				. '<span class="aisa-sc__top">' . esc_html( $r['top'] ) . '</span>' . Ui::icon( 'arrow-down-alt2' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			$this->queries( $r );
			echo '</details>';
		}
		echo '</div>';
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<nav class="aisa-pager" aria-label="' . esc_attr__( 'More pages', 'ai-seo-assistant' ) . '">';
			if ( $paged > 1 ) {
				echo '<a class="aisa-btn aisa-btn--small" href="' . esc_url( add_query_arg( 'paged', $paged - 1 ) ) . '">' . esc_html__( 'Previous', 'ai-seo-assistant' ) . '</a>';
			}
			/* translators: 1: page, 2: pages. */
			echo '<span class="aisa-small">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'ai-seo-assistant' ), $paged, $pages ) ) . '</span>';
			if ( $paged < $pages ) {
				echo '<a class="aisa-btn aisa-btn--small" href="' . esc_url( add_query_arg( 'paged', $paged + 1 ) ) . '">' . esc_html__( 'Next', 'ai-seo-assistant' ) . '</a>';
			}
			echo '</nav>';
		}
		echo '<p class="aisa-small">' . esc_html__( 'Pages Google did not show in the 90 days are not listed here; the SEO scan lists every published page.', 'ai-seo-assistant' ) . '</p></section>';
	}

	/**
	 * One page's searches (inside its <details>).
	 *
	 * @param array<string,mixed> $r Row.
	 */
	protected function queries( array $r ): void {
		echo '<div class="aisa-sc__detail"><p class="aisa-sc__detail-head"><strong>' . esc_html( sprintf( /* translators: %s: page title. */ __( 'Searches that showed %s', 'ai-seo-assistant' ), $r['title'] ) ) . '</strong>';
		if ( $r['id'] ) {
			echo '<a href="' . esc_url(
				add_query_arg(
					[
						'page' => Scan_Page::SLUG,
						'post' => $r['id'],
					],
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Review this page in SEO scan', 'ai-seo-assistant' ) . '</a>';
		}
		echo '</p>';
		$queries = (array) $r['g']['queries'];
		if ( [] === $queries ) {
			echo '<p class="aisa-small">' . esc_html__( 'Search Console listed no searches for this page (rare searches are hidden by Google).', 'ai-seo-assistant' ) . '</p></div>';
			return;
		}
		echo '<div class="aisa-tablewrap"><table class="aisa-table aisa-table--stack"><thead><tr><th scope="col">' . esc_html__( 'Search', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Clicks', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Change', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Impressions', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'CTR', 'ai-seo-assistant' ) . '</th><th scope="col" class="aisa-num">' . esc_html__( 'Position', 'ai-seo-assistant' ) . '</th></tr></thead><tbody>';
		foreach ( $queries as $q ) {
			$c    = $q['change'];
			$text = null === $c ? '' : ( 0 === $c ? __( 'same', 'ai-seo-assistant' ) : ( $c > 0 ? sprintf( /* translators: %d: clicks. */ __( 'up %d', 'ai-seo-assistant' ), $c ) : sprintf( /* translators: %d: clicks. */ __( 'down %d', 'ai-seo-assistant' ), abs( $c ) ) ) );
			echo '<tr><th scope="row">' . esc_html( $q['query'] ) . '</th><td data-label="' . esc_attr__( 'Clicks', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( number_format_i18n( $q['clicks'] ) ) . '</td><td data-label="' . esc_attr__( 'Change', 'ai-seo-assistant' ) . '" class="aisa-num aisa-tone--' . esc_attr( null === $c || 0 === $c ? 'flat' : ( $c > 0 ? 'good' : 'bad' ) ) . '">' . esc_html( $text ) . '</td><td data-label="' . esc_attr__( 'Impressions', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( number_format_i18n( $q['impressions'] ) ) . '</td><td data-label="' . esc_attr__( 'CTR', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( Ui::pct( $q['ctr'] ) ) . '</td><td data-label="' . esc_attr__( 'Position', 'ai-seo-assistant' ) . '" class="aisa-num">' . esc_html( null === $q['position'] ? '–' : number_format_i18n( $q['position'], 1 ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$total = (int) ( $r['g']['queries_total'] ?? 0 );
		if ( $total > count( $queries ) ) {
			$from = Report_View::country_name( Page_Data::meta()['country'] );
			/* translators: 1: shown, 2: total. */
			$line = sprintf( __( 'Top %1$d of %2$d searches, 90 days. Change is in clicks on the 90 days before.', 'ai-seo-assistant' ), count( $queries ), $total );
			/* translators: 1: the line above, 2: country name. */
			echo '<p class="aisa-small">' . esc_html( '' === $from ? $line : sprintf( __( '%1$s Searches from %2$s; the page figures above count all searches.', 'ai-seo-assistant' ), $line, $from ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Where the data comes from and how to refresh it.
	 */
	protected function freshness(): void {
		$last = (int) get_option( Snapshot_Store::LAST_PUSH, 0 );
		$meta = Page_Data::meta();
		$head = $last > 0
			/* translators: 1: date and time, 2: last day covered. */
			? sprintf( __( 'Data comes from the weekly push: last received %1$s%2$s.', 'ai-seo-assistant' ), wp_date( 'D j M, g:ia', $last ), '' !== $meta['end'] ? sprintf( /* translators: %s: date. */ __( ', covering to %s', 'ai-seo-assistant' ), Ui::day( $meta['end'] ) ) : '' )
			: __( 'Data comes from the weekly push; none has arrived yet.', 'ai-seo-assistant' );
		/* translators: %s: agency name. */
		$body = sprintf( __( 'Need it fresher? %s can refresh now by running retainer-scan for this site. There is no Google login on this site, so there is no “Connect” button here.', 'ai-seo-assistant' ), Ui::agency() );
		echo Ui::notice( 'info', '<p><strong>' . esc_html( $head ) . '</strong></p><p>' . esc_html( $body ) . '</p>', 'update' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
	}
}
