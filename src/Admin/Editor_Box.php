<?php
/**
 * Editor_Box — "SEO to-do for this page" in the post editor (block and classic), agency only.
 *
 * WHY (Andrew, 2026-10-06, on the 4.x box: "all of this is in the single post or page view but all we
 * really need to see is the do this in editor"). The 4.x box repeated Yoast's own fields (title,
 * description), carried a "Local SEO Focus" form and wrote the SEO plugin's fields on every save. Titles,
 * descriptions and alt text now live in the page review, with Apply and Undo; page types in AJR Core's own
 * box. What is left for the person in the editor is what the plugin never changes itself: headings, links
 * and content. So the box shows only that:
 *   - one line: the page's tier, how many to-dos, when it was last scanned, and a link to the full review;
 *   - the scan's editor findings for headings, links and content (each with the review's advice for it
 *     when there is some), and any other editor advice from the latest review;
 *   - a "Done" tick per item: it marks the item done (post meta) and queues the page for a rescan. A done
 *     scan finding collapses to "Done <date>" and is gone once a rescan no longer finds it; if a later scan
 *     still finds it, it opens again.
 * Nothing is generated from the editor and nothing is written on save: no save handler at all.
 *
 * The page's 4.x "Local SEO Focus" notes (post meta `_ai_seo_assistant_*`) are no longer shown or saved,
 * but are still read as context for the review prompt until someone clears them (Page_Review): what a
 * person typed is never deleted.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Report\Access;
use AJR\SEOAssistant\Scan\Ranking;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Scan\Scanner;
use AJR\SEOAssistant\Scan\Scheduler;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * The editor's SEO to-do panel.
 */
class Editor_Box {

	/** Post meta: to-dos marked done, key => unix time. */
	public const DONE_META = '_aisa_todo_done';

	/** AJAX action (and its nonce action) for a "Done" tick. */
	public const DONE = 'aisa_todo_done';

	/** Script handle. */
	public const SCRIPT = 'ai-seo-assistant-editor';

	/** Scan finding kinds that are editor work. */
	public const KINDS = [ 'headings', 'links', 'thin' ];

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_' . self::DONE, [ $this, 'ajax_done' ] );
	}

	/**
	 * Add the box to the editor of every scanned post type (agency users only).
	 */
	public function add_box(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			return;
		}
		foreach ( Scanner::post_types() as $type ) {
			add_meta_box( 'ai-seo-assistant', __( 'SEO to-do for this page', 'ai-seo-assistant' ), [ $this, 'render' ], $type, 'side', 'default' );
		}
	}

	/**
	 * The box's script and style, on the editor only, for the agency only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || ! current_user_can( Access::TOOLS_CAP ) ) {
			return;
		}
		wp_enqueue_style( self::SCRIPT, AI_SEO_ASSISTANT_URL . 'assets/css/aisa-editor.css', [], AI_SEO_ASSISTANT_VERSION );
		wp_enqueue_script(
			self::SCRIPT,
			AI_SEO_ASSISTANT_URL . 'assets/js/aisa-editor.js',
			[],
			AI_SEO_ASSISTANT_VERSION,
			[
				'strategy'  => 'defer',
				'in_footer' => true,
			]
		);
		wp_localize_script(
			self::SCRIPT,
			'aisaEditor',
			[
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::DONE ),
				'i18n'  => [
					'saving'     => __( 'Saving…', 'ai-seo-assistant' ),
					'failed'     => __( 'Not saved. Try again.', 'ai-seo-assistant' ),
					'copied'     => __( 'Copied', 'ai-seo-assistant' ),
					'copyFailed' => __( 'Select and copy', 'ai-seo-assistant' ),
				],
			]
		);
	}

	/**
	 * Print the panel.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render( $post ): void {
		$post_id = (int) $post->ID;
		$review  = add_query_arg(
			[
				'page' => Scan_Page::SLUG,
				'post' => $post_id,
			],
			admin_url( 'admin.php' )
		);
		$row     = ( new Scan_Store() )->get( $post_id );
		if ( null === $row ) {
			echo '<p class="aisa-todo__none">' . esc_html__( 'Not scanned yet. It is scanned after it is published or saved.', 'ai-seo-assistant' ) . '</p>';
			return;
		}
		$items = self::items( $row, self::done( $post_id ) );
		echo '<div class="aisa-todo" data-aisa-todo-box data-post="' . esc_attr( (string) $post_id ) . '">';
		echo '<p class="aisa-todo__status"><span data-aisa-todo-line>' . esc_html( self::line( $post_id, $row, $items ) ) . '</span> <a href="' . esc_url( $review ) . '">' . esc_html__( 'Open full review →', 'ai-seo-assistant' ) . '</a></p>';

		if ( ! is_array( $row['suggestions'] ) ) {
			// Never reviewed: say so (nothing is generated from the editor); the scan's own to-dos still follow.
			echo '<p class="aisa-todo__none">' . esc_html__( 'No review yet —', 'ai-seo-assistant' ) . ' <a href="' . esc_url( $review ) . '">' . esc_html__( 'Open the page review', 'ai-seo-assistant' ) . '</a></p>';
			if ( [] === $items ) {
				echo '</div>';
				return;
			}
		}
		if ( [] === $items ) {
			echo '<p class="aisa-todo__none">' . esc_html__( 'Nothing to do in the editor.', 'ai-seo-assistant' ) . '</p></div>';
			return;
		}
		echo '<ul class="aisa-todo__list">';
		foreach ( $items as $item ) {
			echo '<li class="aisa-todo__item' . ( null !== $item['done'] ? ' is-done' : '' ) . '" data-key="' . esc_attr( $item['key'] ) . '">';
			echo '<span class="aisa-todo__area">' . esc_html( $item['area'] ) . '</span> ';
			if ( null !== $item['done'] ) {
				/* translators: %s: date. */
				echo '<span class="aisa-todo__text">' . esc_html( $item['text'] ) . '</span> <span class="aisa-todo__when">' . esc_html( sprintf( __( 'Done %s', 'ai-seo-assistant' ), wp_date( 'j M', $item['done'] ) ) ) . '</span>';
			} else {
				echo '<span class="aisa-todo__text">' . esc_html( $item['text'] ) . '</span>';
				if ( '' !== $item['detail'] ) {
					echo '<span class="aisa-todo__detail">' . esc_html( $item['detail'] ) . '</span>';
				}
				if ( '' !== $item['note'] ) {
					echo '<span class="aisa-todo__detail">' . esc_html( $item['note'] ) . '</span>';
				}
				$copy = self::copy_text( $item );
				/* translators: %s: the text copied. */
				echo '<span class="aisa-todo__actions"><button type="button" class="button button-small" data-aisa-todo-copy="' . esc_attr( $copy ) . '" aria-label="' . esc_attr( sprintf( __( 'Copy: %s', 'ai-seo-assistant' ), $copy ) ) . '">' . esc_html__( 'Copy', 'ai-seo-assistant' ) . '</button> ';
				/* translators: %s: the to-do. */
				echo '<button type="button" class="button button-small" data-aisa-todo-done aria-label="' . esc_attr( sprintf( __( 'Mark done: %s', 'ai-seo-assistant' ), $item['text'] ) ) . '">' . esc_html__( 'Done', 'ai-seo-assistant' ) . '</button></span>';
			}
			echo '</li>';
		}
		echo '</ul><p class="screen-reader-text" aria-live="polite" data-aisa-todo-live></p></div>';
	}

	/**
	 * The page's to-dos: its scan findings for headings, links and content (with the review's advice for
	 * the same area), then any other editor advice from the latest review.
	 *
	 * A done scan finding stays (collapsed, "Done <date>") until a rescan: gone when that scan no longer
	 * finds it (it is not in the row), open again when a scan AFTER the tick still finds it.
	 *
	 * @param array<string,mixed> $row  Scan_Store::get() row.
	 * @param array<string,int>   $done DONE_META.
	 * @return array<int,array{key:string,area:string,text:string,detail:string,note:string,done:?int}>
	 */
	public static function items( array $row, array $done ): array {
		$labels  = [
			'headings' => __( 'Headings', 'ai-seo-assistant' ),
			'links'    => __( 'Links', 'ai-seo-assistant' ),
			'thin'     => __( 'Content', 'ai-seo-assistant' ),
			'content'  => __( 'Content', 'ai-seo-assistant' ),
		];
		$areas   = [
			'headings' => 'headings',
			'links'    => 'links',
			'content'  => 'thin',
		];
		$scanned = (int) strtotime( (string) ( $row['scanned_at'] ?? '' ) . ' UTC' );
		$advice  = [];
		foreach ( (array) ( $row['suggestions']['editor'] ?? [] ) as $a ) {
			$kind = $areas[ (string) ( $a['area'] ?? '' ) ] ?? '';
			if ( '' !== $kind && '' !== trim( (string) ( $a['advice'] ?? '' ) ) ) {
				$advice[ $kind ][] = (string) $a['advice'];
			}
		}
		$out  = [];
		$used = [];
		foreach ( (array) ( $row['issues'] ?? [] ) as $issue ) {
			$kind = (string) ( $issue['kind'] ?? '' );
			if ( 'editor' !== ( $issue['who'] ?? '' ) || ! in_array( $kind, self::KINDS, true ) ) {
				continue;
			}
			$key  = 'issue:' . (string) $issue['code'];
			$when = isset( $done[ $key ] ) ? (int) $done[ $key ] : null;
			$note = '';
			if ( null !== $when && $scanned > $when ) {
				// Scanned after the tick and still there: open again.
				/* translators: %s: date. */
				$note = sprintf( __( 'Still found by the scan of %s.', 'ai-seo-assistant' ), wp_date( 'j M', $scanned ) );
				$when = null;
			}
			$tip           = isset( $advice[ $kind ] ) ? implode( ' ', $advice[ $kind ] ) : (string) ( $issue['fix'] ?? '' );
			$used[ $kind ] = true;
			$out[]         = [
				'key'    => $key,
				'area'   => $labels[ $kind ],
				'text'   => (string) ( $issue['title'] ?? '' ),
				'detail' => $tip,
				'note'   => $note,
				'done'   => $when,
			];
		}
		foreach ( $advice as $kind => $list ) {
			if ( isset( $used[ $kind ] ) ) {
				continue; // Already shown with the finding it is about.
			}
			foreach ( $list as $text ) {
				$key   = 'advice:' . substr( md5( $kind . '|' . $text ), 0, 12 );
				$out[] = [
					'key'    => $key,
					'area'   => $labels[ $kind ],
					'text'   => $text,
					'detail' => '',
					'note'   => '',
					'done'   => isset( $done[ $key ] ) ? (int) $done[ $key ] : null,
				];
			}
		}

		return $out;
	}

	/**
	 * What a to-do's "Copy" button copies: the text it quotes (the heading to change, the words to link
	 * with), else the whole instruction.
	 *
	 * @param array<string,mixed> $item items() entry.
	 */
	public static function copy_text( array $item ): string {
		$text = '' !== (string) $item['detail'] ? (string) $item['detail'] : (string) $item['text'];
		if ( preg_match( '/[“"]([^”"]{2,200})[”"]/u', $text, $m ) ) {
			return trim( $m[1] );
		}

		return trim( (string) preg_replace( '/^Fix:\s*/', '', $text ) );
	}

	/**
	 * "High quick win · 3 to-dos · last scanned 6 Oct".
	 *
	 * @param int                            $post_id Post ID.
	 * @param array<string,mixed>            $row     Scan row.
	 * @param array<int,array<string,mixed>> $items   items().
	 */
	public static function line( int $post_id, array $row, array $items ): string {
		$open = count( array_filter( $items, static fn( $i ) => null === $i['done'] ) );
		$line = array_filter( [ self::tier( $post_id ) ] );
		/* translators: %d: number of to-dos. */
		$line[] = sprintf( _n( '%d to-do', '%d to-dos', $open, 'ai-seo-assistant' ), $open );
		/* translators: %s: date. */
		$line[] = sprintf( __( 'last scanned %s', 'ai-seo-assistant' ), wp_date( 'j M', (int) strtotime( (string) $row['scanned_at'] . ' UTC' ) ) );

		return implode( ' · ', $line );
	}

	/**
	 * The page's done ticks.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,int>
	 */
	protected static function done( int $post_id ): array {
		$done = get_post_meta( $post_id, self::DONE_META, true );

		return is_array( $done ) ? array_map( 'intval', $done ) : [];
	}

	/**
	 * "High quick win" for the page, '' without search data.
	 *
	 * @param int $post_id Post ID.
	 */
	protected static function tier( int $post_id ): string {
		if ( ! Page_Data::has_data() ) {
			return '';
		}
		$row   = Ranking::rows()[ $post_id ] ?? null;
		$names = [
			'high'   => __( 'High', 'ai-seo-assistant' ),
			'medium' => __( 'Medium', 'ai-seo-assistant' ),
			'low'    => __( 'Low', 'ai-seo-assistant' ),
		];
		if ( null === $row || ! isset( $names[ $row['tier'] ?? '' ] ) ) {
			return '';
		}

		/* translators: %s: High, Medium or Low. */
		return sprintf( 'prize' === ( $row['mode'] ?? '' ) ? __( '%s top-3 prize', 'ai-seo-assistant' ) : __( '%s quick win', 'ai-seo-assistant' ), $names[ $row['tier'] ] );
	}

	/**
	 * AJAX: mark one to-do done, and queue the page for a rescan.
	 */
	public function ajax_done(): void {
		check_ajax_referer( self::DONE, 'nonce' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$key     = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( $post_id <= 0 || ! current_user_can( Access::TOOLS_CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do that.', 'ai-seo-assistant' ) ], 403 );
		}
		if ( ! preg_match( '/^(issue:[a-z0-9_]{1,40}|advice:[a-f0-9]{12})$/', $key ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown to-do.', 'ai-seo-assistant' ) ], 400 );
		}
		$row  = ( new Scan_Store() )->get( $post_id );
		$keep = null === $row ? [] : array_column( self::items( $row, [] ), 'key' );
		// Only ticks for to-dos the page still has: a finding the scan no longer reports needs none.
		$done         = array_intersect_key( self::done( $post_id ), array_flip( $keep ) );
		$done[ $key ] = time();
		update_post_meta( $post_id, self::DONE_META, $done );
		Scheduler::queue_page( $post_id ); // The next scan step looks at the page again.

		wp_send_json_success(
			[
				/* translators: %s: date. */
				'label' => sprintf( __( 'Done %s', 'ai-seo-assistant' ), wp_date( 'j M' ) ),
				'line'  => null === $row ? '' : self::line( $post_id, $row, self::items( $row, $done ) ),
			]
		);
	}
}
