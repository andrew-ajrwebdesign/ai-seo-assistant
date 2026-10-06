<?php
/**
 * Changes_Page — AI SEO Assistant → Changes (mockup E1). Agency only.
 *
 * Every field the plugin applied, with its before and after, who and when, and what happened to clicks in
 * the 4 weeks after (Changes\Change_Log::effect()). The billing month's summary on top; the log below with
 * Undo per row and an export as CSV. Measured results feed the Monthly report.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\AI\Spend;
use AJR\SEOAssistant\Changes\Change_Log;
use AJR\SEOAssistant\Report\Access;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * The change log screen.
 */
class Changes_Page {

	/** Menu slug. */
	public const SLUG = 'ai-seo-assistant-changes';

	/** CSV export action. */
	public const EXPORT = 'aisa_changes_csv';

	/**
	 * Register the export handler (Menu builds the screen; this is called from Tools_Actions' side).
	 */
	public static function register_export(): void {
		add_action( 'admin_post_' . self::EXPORT, [ self::class, 'export' ] );
	}

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			return;
		}
		$log     = new Change_Log();
		$data    = new Page_Data();
		$rows    = $log->find( [ 'limit' => 500 ] );
		$effects = [];
		foreach ( $rows as $row ) {
			$effects[ $row['id'] ] = $log->effect( $row, $data->get( (string) $row['path'] ) );
		}

		$export = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aisa-inline">' . wp_nonce_field( self::EXPORT, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT ) . '"><button type="submit" class="aisa-btn aisa-btn--dark">' . Ui::icon( 'media-spreadsheet' ) . esc_html__( 'Export as CSV', 'ai-seo-assistant' ) . '</button></form>';
		echo '<div class="wrap aisa-wrap"><hr class="wp-header-end"><div class="aisa-tool">';
		echo Ui::hero( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			[
				/* translators: %s: agency name. */
				'label'   => sprintf( __( 'Changes by %s', 'ai-seo-assistant' ), Ui::agency() ),
				'title'   => __( 'Changes', 'ai-seo-assistant' ),
				'sub'     => __( 'Every change the plugin applied, with its before and after, and what happened to clicks in the 4 weeks after. Measured results feed the monthly report.', 'ai-seo-assistant' ),
				'actions' => $export,
			]
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result code from our own redirect.
		$code = isset( $_GET['aisa'] ) ? sanitize_key( wp_unslash( $_GET['aisa'] ) ) : '';
		if ( 'kept' === $code ) {
			echo Ui::notice( 'warning', '<p>' . esc_html__( 'Not undone: that field was changed again after the plugin applied it, and undo never overwrites later work.', 'ai-seo-assistant' ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		} elseif ( 'undone' === $code ) {
			echo Ui::notice( 'success', '<p>' . esc_html__( 'Undone: the earlier value is back.', 'ai-seo-assistant' ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		}
		$this->summary( $rows, $effects );
		$this->log( $rows, $effects );
		echo '</div></div>';
	}

	/**
	 * "This billing month": applied, measured, waiting, undone.
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param array<int,array<string,mixed>> $effects Effects by row ID.
	 */
	protected function summary( array $rows, array $effects ): void {
		$period   = Spend::current();
		$start    = gmdate( 'Y-m-d H:i:s', $period['start']->getTimestamp() );
		$applied  = 0;
		$pages    = [];
		$measured = 0;
		$better   = 0;
		$worse    = 0;
		$waiting  = 0;
		$undone   = 0;
		$by       = [];
		foreach ( $rows as $row ) {
			if ( $row['applied_at'] < $start ) {
				continue;
			}
			++$applied;
			$pages[ $row['post_id'] ] = true;
			$e                        = $effects[ $row['id'] ];
			if ( 'undone' === $e['state'] ) {
				++$undone;
				$who                                  = get_userdata( (int) $row['undone_by'] );
				$by[ $who ? $who->display_name : '' ] = true;
			} elseif ( 'measured' === $e['state'] ) {
				++$measured;
				$better += 'better' === ( $e['verdict'] ?? '' ) ? 1 : 0;
				$worse  += 'worse' === ( $e['verdict'] ?? '' ) ? 1 : 0;
			} elseif ( in_array( $e['state'], [ 'waiting', 'no_data' ], true ) ) {
				++$waiting;
			}
		}
		$range = wp_date( 'j M', $period['start']->getTimestamp(), $period['start']->getTimezone() ) . ' to ' . wp_date( 'j M', $period['end']->getTimestamp() - DAY_IN_SECONDS, $period['end']->getTimezone() );
		echo '<section class="aisa-card" aria-labelledby="aisa-month">';
		echo Ui::card_head( 'aisa-month', 'backup', __( 'This billing month', 'ai-seo-assistant' ), $range ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<div class="aisa-tiles aisa-tiles--search">';
		/* translators: %d: pages. */
		$this->stat( __( 'Changes applied', 'ai-seo-assistant' ), $applied, sprintf( _n( 'on %d page', 'on %d pages', count( $pages ), 'ai-seo-assistant' ), count( $pages ) ) );
		/* translators: 1: better, 2: worse. */
		$this->stat( __( 'Measured', 'ai-seo-assistant' ), $measured, sprintf( __( '%1$d better, %2$d worse', 'ai-seo-assistant' ), $better, $worse ) );
		$this->stat( __( 'Waiting for data', 'ai-seo-assistant' ), $waiting, __( 'need 4 weeks after the change', 'ai-seo-assistant' ) );
		/* translators: %s: names. */
		$this->stat( __( 'Undone', 'ai-seo-assistant' ), $undone, $undone > 0 ? sprintf( __( 'by %s', 'ai-seo-assistant' ), implode( ', ', array_filter( array_keys( $by ) ) ) ) : '' );
		echo '</div></section>';
	}

	/**
	 * One summary tile.
	 *
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @param string $note  Note.
	 */
	protected function stat( string $label, int $value, string $note ): void {
		echo '<div class="aisa-metric"><p class="aisa-metric__label">' . esc_html( $label ) . '</p><p class="aisa-metric__value">' . esc_html( number_format_i18n( $value ) ) . '</p><p class="aisa-metric__change aisa-tone--flat">' . esc_html( $note ) . '</p></div>';
	}

	/**
	 * The change log table.
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param array<int,array<string,mixed>> $effects Effects by row ID.
	 */
	protected function log( array $rows, array $effects ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$post  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$field = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : '';
		// phpcs:enable
		$counts = [
			''         => count( $rows ),
			'measured' => 0,
			'waiting'  => 0,
			'undone'   => 0,
		];
		$pages  = [];
		foreach ( $rows as $row ) {
			$s                        = $effects[ $row['id'] ]['state'];
			$bucket                   = in_array( $s, [ 'measured', 'undone' ], true ) ? $s : ( 'not_measured' === $s ? 'none' : 'waiting' );
			$counts[ $bucket ]        = ( $counts[ $bucket ] ?? 0 ) + 1;
			$pages[ $row['post_id'] ] = wp_strip_all_tags( (string) get_the_title( $row['post_id'] ) );
		}
		$labels = $this->field_labels();

		echo '<section class="aisa-card" aria-labelledby="aisa-log">';
		echo Ui::card_head( 'aisa-log', 'list-view', __( 'Change log', 'ai-seo-assistant' ), __( 'Newest first · effect from Search Console, 4 weeks before vs 4 weeks after', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<div class="aisa-filters"><ul class="aisa-chips">';
		foreach ( [
			''         => __( 'All', 'ai-seo-assistant' ),
			'measured' => __( 'Measured', 'ai-seo-assistant' ),
			'waiting'  => __( 'Waiting for data', 'ai-seo-assistant' ),
			'undone'   => __( 'Undone', 'ai-seo-assistant' ),
		] as $key => $label ) {
			$url = add_query_arg(
				array_filter(
					[
						'page'  => self::SLUG,
						'state' => $key,
						'post'  => $post,
						'field' => $field,
					]
				),
				admin_url( 'admin.php' )
			);
			echo '<li><a class="aisa-chip' . ( $key === $state ? ' is-current' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $key === $state ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . ' <span>' . esc_html( (string) ( $counts[ $key ] ?? 0 ) ) . '</span></a></li>';
		}
		echo '</ul><form method="get" class="aisa-filters aisa-push"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="state" value="' . esc_attr( $state ) . '">';
		echo '<label class="aisa-field"><span class="screen-reader-text">' . esc_html__( 'Page', 'ai-seo-assistant' ) . '</span><select name="post"><option value="">' . esc_html__( 'All pages', 'ai-seo-assistant' ) . '</option>';
		foreach ( $pages as $id => $title ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $post, $id, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select></label><label class="aisa-field"><span class="screen-reader-text">' . esc_html__( 'Field', 'ai-seo-assistant' ) . '</span><select name="field"><option value="">' . esc_html__( 'All fields', 'ai-seo-assistant' ) . '</option>';
		foreach ( $labels as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $field, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><button type="submit" class="aisa-btn aisa-btn--small">' . esc_html__( 'Filter', 'ai-seo-assistant' ) . '</button></form></div>';

		$shown = array_filter(
			$rows,
			static function ( $row ) use ( $effects, $state, $post, $field ) {
				$s = $effects[ $row['id'] ]['state'];
				if ( 'measured' === $state && 'measured' !== $s ) {
					return false;
				}
				if ( 'undone' === $state && 'undone' !== $s ) {
					return false;
				}
				if ( 'waiting' === $state && ! in_array( $s, [ 'waiting', 'no_data' ], true ) ) {
					return false;
				}
				return ( ! $post || $row['post_id'] === $post ) && ( '' === $field || $row['field'] === $field );
			}
		);
		if ( [] === $rows ) {
			echo '<p class="aisa-pending">' . esc_html__( 'Nothing has been applied yet. Changes appear here when you apply suggestions in a page review.', 'ai-seo-assistant' ) . '</p></section>';
			return;
		}
		echo '<div class="aisa-tablewrap"><table class="aisa-table aisa-table--log"><thead><tr><th scope="col">' . esc_html__( 'Date', 'ai-seo-assistant' ) . '</th><th scope="col">' . esc_html__( 'Page', 'ai-seo-assistant' ) . '</th><th scope="col">' . esc_html__( 'Field', 'ai-seo-assistant' ) . '</th><th scope="col">' . esc_html__( 'Before → after', 'ai-seo-assistant' ) . '</th><th scope="col">' . esc_html__( 'Effect', 'ai-seo-assistant' ) . '</th><td></td></tr></thead><tbody>';
		foreach ( $shown as $row ) {
			$user                   = get_userdata( $row['user_id'] );
			[ $pill, $tone, $text ] = $this->effect_words( $row, $effects[ $row['id'] ] );
			$undo                   = null === $row['undone_at']
				? '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( Tools_Actions::UNDO, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::UNDO ) . '"><input type="hidden" name="ids" value="' . esc_attr( (string) $row['id'] ) . '"><button type="submit" class="aisa-linkbtn">' . esc_html__( 'Undo', 'ai-seo-assistant' ) . '<span class="screen-reader-text"> ' . esc_html( $labels[ $row['field'] ] ?? '' ) . '</span></button></form>'
				: '';
			$title                  = $pages[ $row['post_id'] ] ?? '';
			$edit                   = add_query_arg(
				[
					'page' => Scan_Page::SLUG,
					'post' => $row['post_id'],
				],
				admin_url( 'admin.php' )
			);
			echo '<tr><td><strong>' . esc_html( wp_date( 'D j M', (int) strtotime( $row['applied_at'] . ' UTC' ) ) ) . '</strong><br><span class="aisa-small">' . esc_html( $user ? $user->display_name : '' ) . '</span></td>'
				. '<td><a href="' . esc_url( $edit ) . '"><strong>' . esc_html( '' !== $title ? $title : $row['path'] ) . '</strong></a><br><span class="aisa-path">' . esc_html( $row['path'] ) . '</span></td>'
				. '<td>' . esc_html( $labels[ $row['field'] ] ?? $row['field'] ) . '</td>'
				. '<td>' . ( 'content' === $row['field'] ? '<p class="aisa-small">' . esc_html( self::content_summary( (string) $row['before_value'], (string) $row['after_value'] ) ) . '</p>' : '<dl class="aisa-ba"><dt>' . esc_html__( 'Before', 'ai-seo-assistant' ) . '</dt><dd class="aisa-before">' . esc_html( '' !== $row['before_value'] ? (string) $row['before_value'] : __( '(empty)', 'ai-seo-assistant' ) ) . '</dd><dt>' . esc_html__( 'After', 'ai-seo-assistant' ) . '</dt><dd>' . esc_html( (string) $row['after_value'] ) . '</dd></dl>' ) . '</td>'
				. '<td>' . Ui::pill( $pill, $tone ) . '<p class="aisa-small">' . esc_html( $text ) . '</p></td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
				. '<td>' . $undo . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '</tbody></table></div>';
		echo '<p class="aisa-small">' . esc_html__( 'Effect = click-through rate in the 4 weeks after a change against the 4 weeks before, from the weekly push. Position is shown beside it so a ranking move is not mistaken for a better listing. “Better” or “worse” is always written, not only coloured.', 'ai-seo-assistant' ) . '</p></section>';
	}

	/**
	 * The effect's pill, tone and line.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $e   Effect.
	 * @return array{0:string,1:string,2:string}
	 */
	protected function effect_words( array $row, array $e ): array {
		switch ( $e['state'] ) {
			case 'undone':
				$who = get_userdata( (int) $row['undone_by'] );
				/* translators: 1: date, 2: who. */
				return [ sprintf( __( 'Undone %s', 'ai-seo-assistant' ), wp_date( 'j M', (int) strtotime( (string) $row['undone_at'] . ' UTC' ) ) ), 'muted', sprintf( __( 'Undone by %s. Not measured.', 'ai-seo-assistant' ), $who ? $who->display_name : '' ) ];
			case 'not_measured':
				return [ __( 'Not measured', 'ai-seo-assistant' ), 'muted', in_array( $row['field'], [ 'alt', 'content' ], true ) ? __( 'Alt text has no click-rate measure; logged for the record.', 'ai-seo-assistant' ) : __( 'A setting in the SEO plugin; it does not change what Google shows.', 'ai-seo-assistant' ) ];
			case 'measured':
				$d     = (float) ( $e['delta'] ?? 0 );
				$pts   = number_format_i18n( abs( $d ), 1 );
				$label = 'better' === $e['verdict'] ? sprintf( /* translators: %s: points. */ __( 'Better: up %s points', 'ai-seo-assistant' ), $pts ) : ( 'worse' === $e['verdict'] ? sprintf( /* translators: %s: points. */ __( 'Worse: down %s points', 'ai-seo-assistant' ), $pts ) : __( 'About the same', 'ai-seo-assistant' ) );
				/* translators: 1: CTR before, 2: CTR after, 3: position before, 4: position after. */
				$text = sprintf( __( 'CTR %1$s → %2$s in the 4 weeks after. Position %3$s → %4$s.', 'ai-seo-assistant' ), Ui::pct( $e['ctr_before'] ), Ui::pct( $e['ctr_after'] ), null === $e['pos_before'] ? '–' : number_format_i18n( (float) $e['pos_before'], 1 ), null === $e['pos_after'] ? '–' : number_format_i18n( (float) $e['pos_after'], 1 ) );
				return [ $label, 'better' === $e['verdict'] ? 'good' : ( 'worse' === $e['verdict'] ? 'bad' : 'muted' ), $text ];
			default:
				$from = isset( $e['from'] ) ? wp_date( 'D j M', (int) strtotime( $e['from'] . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) ) : '';
				if ( ! empty( $e['weeks_after'] ) && isset( $e['ctr_before'], $e['ctr_after'] ) ) {
					/* translators: 1: weeks in, 2: CTR before, 3: CTR so far, 4: date. */
					return [ __( 'Waiting for data', 'ai-seo-assistant' ), 'warn', sprintf( __( '%1$d of 4 weeks in: CTR %2$s → %3$s so far. Final result from %4$s.', 'ai-seo-assistant' ), (int) $e['weeks_after'], Ui::pct( $e['ctr_before'] ), Ui::pct( $e['ctr_after'] ), $from ) ];
				}
				/* translators: %s: date. */
				return [ __( 'Waiting for data', 'ai-seo-assistant' ), 'warn', sprintf( __( 'Measured from %s, 4 weeks after the change.', 'ai-seo-assistant' ), $from ) ];
		}
	}

	/**
	 * The page-content change in words: which alt attributes changed (the whole content is kept in the log
	 * for Undo, but never printed).
	 *
	 * @param string $before Content before.
	 * @param string $after  Content after.
	 */
	public static function content_summary( string $before, string $after ): string {
		preg_match_all( '/\b(?:alt|image_alt)="([^"]*)"/', $before, $b );
		preg_match_all( '/\b(?:alt|image_alt)="([^"]*)"/', $after, $a );
		$new = array_values( array_diff( $a[1], $b[1] ) );

		/* translators: 1: count, 2: the new alt texts. */
		return sprintf( _n( '%1$d alt attribute written into the page: %2$s', '%1$d alt attributes written into the page: %2$s', count( $new ), 'ai-seo-assistant' ), count( $new ), '“' . implode( '”, “', array_slice( $new, 0, 4 ) ) . '”' );
	}

	/**
	 * Field names.
	 *
	 * @return array<string,string>
	 */
	protected function field_labels(): array {
		return [
			'title'       => __( 'SEO title', 'ai-seo-assistant' ),
			'description' => __( 'Meta description', 'ai-seo-assistant' ),
			'keyphrase'   => __( 'Focus keyphrase', 'ai-seo-assistant' ),
			'alt'         => __( 'Alt text', 'ai-seo-assistant' ),
			'content'     => __( 'Alt text in the page', 'ai-seo-assistant' ),
		];
	}

	/**
	 * Stream the log as CSV.
	 */
	public static function export(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ai-seo-assistant' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::EXPORT );
		$log  = new Change_Log();
		$data = new Page_Data();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ai-seo-assistant-changes-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download.
		fputcsv( $out, [ 'applied_at_utc', 'user', 'page', 'path', 'field', 'before', 'after', 'effect', 'ctr_before', 'ctr_after', 'undone_at_utc' ] );
		foreach ( $log->find( [ 'limit' => 1000 ] ) as $row ) {
			$user   = get_userdata( $row['user_id'] );
			$effect = $log->effect( $row, $data->get( (string) $row['path'] ) );
			fputcsv(
				$out,
				array_map(
					[ self::class, 'cell' ],
					[ $row['applied_at'], $user ? $user->display_name : '', wp_strip_all_tags( (string) get_the_title( $row['post_id'] ) ), $row['path'], $row['field'], 'content' === $row['field'] ? self::content_summary( (string) $row['before_value'], (string) $row['after_value'] ) : $row['before_value'], 'content' === $row['field'] ? '' : $row['after_value'], $effect['verdict'] ?? $effect['state'], $effect['ctr_before'] ?? '', $effect['ctr_after'] ?? '', (string) $row['undone_at'] ]
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the download stream.
		exit;
	}

	/**
	 * A CSV cell that a spreadsheet will not run as a formula.
	 *
	 * @param mixed $value Value.
	 */
	public static function cell( $value ): string {
		$value = (string) $value;

		return '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ? "'" . $value : $value;
	}
}
