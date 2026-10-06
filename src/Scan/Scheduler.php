<?php
/**
 * Scheduler — when the scan runs, in batches small enough for any host.
 *
 * Three triggers (decision 2026-10-06):
 * - After each push: the pages changed since they were last scanned (or never scanned) are queued, then
 *   the site-wide pass re-ranks everything against the new search data. (after_push)
 * - When a page is saved: one deferred cron event for that page, a minute later, never inline in the save
 *   request, so the editor stays fast and the SEO plugin has written its fields by then. (queue_post)
 * - "Rescan now": every published page is queued; the screen then calls step() in turn over AJAX, so a
 *   500-page site never hits a time limit and the scan runs even where WP-Cron is off. Cron drives the same
 *   queue in the background when nobody is watching.
 *
 * One queue (an option), one lock (a transient), so the screen and cron never scan the same page twice.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Scan queue and cron.
 */
class Scheduler {

	/** Cron hook that works through the queue. */
	public const RUN_HOOK = 'aisa_scan_run';

	/** Cron hook that rescans one saved page. */
	public const POST_HOOK = 'aisa_scan_post';

	/** Option: the queue { ids[], total, done, mode, started }. Not autoloaded. */
	public const QUEUE = 'ai_seo_assistant_scan_queue';

	/** Transient lock while a batch runs. */
	public const LOCK = 'aisa_scan_lock';

	/** Seconds one batch may run. */
	public const BUDGET = 20;

	/**
	 * Queue a scan.
	 *
	 * @param string         $mode 'full' | 'incremental'.
	 * @param array<int,int> $ids  Pages to scan (may be empty: only the site-wide pass runs).
	 */
	public static function start( string $mode, array $ids ): void {
		update_option(
			self::QUEUE,
			[
				'ids'     => array_values( array_unique( array_map( 'intval', $ids ) ) ),
				'total'   => count( $ids ),
				'done'    => 0,
				'mode'    => $mode,
				'started' => time(),
			],
			false
		);
		if ( ! wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::RUN_HOOK );
		}
	}

	/**
	 * Queue every published page ("Rescan now").
	 */
	public static function start_full(): void {
		$ids = Scanner::published_ids();
		( new Scan_Store() )->prune( $ids );
		self::start( 'full', $ids );
	}

	/**
	 * The queue, or null when idle.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function queue(): ?array {
		$queue = get_option( self::QUEUE, null );

		return is_array( $queue ) && isset( $queue['ids'] ) ? $queue : null;
	}

	/**
	 * Scan pages until the time budget is used, then (when the queue is empty) run the site-wide pass.
	 *
	 * @param int $budget Seconds.
	 * @return array{state:string,done:int,total:int,result?:array<string,int>} state: 'working' | 'done' | 'busy' | 'idle'
	 */
	public static function step( int $budget = self::BUDGET ): array {
		$queue = self::queue();
		if ( null === $queue ) {
			return [
				'state' => 'idle',
				'done'  => 0,
				'total' => 0,
			];
		}
		if ( false !== get_transient( self::LOCK ) ) {
			return [
				'state' => 'busy',
				'done'  => (int) $queue['done'],
				'total' => (int) $queue['total'],
			];
		}
		set_transient( self::LOCK, time(), 2 * $budget + 30 );

		$scanner = new Scanner();
		$until   = microtime( true ) + $budget;
		while ( [] !== $queue['ids'] && microtime( true ) < $until ) {
			$scanner->scan_page( (int) array_shift( $queue['ids'] ) );
			++$queue['done'];
			update_option( self::QUEUE, $queue, false ); // Progress survives a fatal or a timeout.
		}

		if ( [] === $queue['ids'] ) {
			// The site-wide pass once, then the intent pass in its own steps (one Claude batch per step, with
			// the time limit raised for it), so neither shares a request with the other.
			if ( empty( $queue['finalized'] ) ) {
				$queue['result']    = $scanner->finalize();
				$queue['finalized'] = true;
				update_option( self::QUEUE, $queue, false );
				delete_transient( self::LOCK );

				return [
					'state' => 'working',
					'done'  => (int) $queue['done'],
					'total' => (int) $queue['total'],
				];
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts disable it; the call's own timeout is 60 s.
			}
			$intent = Intent::run_pass();
			if ( 'working' === $intent['state'] ) {
				delete_transient( self::LOCK );

				return [
					'state' => 'working',
					'done'  => (int) $queue['done'],
					'total' => (int) $queue['total'],
				];
			}
			$result           = (array) ( $queue['result'] ?? [] );
			$result['intent'] = $intent;
			delete_option( self::QUEUE );
			Ranking::flush();
			delete_transient( self::LOCK );

			return [
				'state'  => 'done',
				'done'   => (int) $queue['done'],
				'total'  => (int) $queue['total'],
				'result' => $result,
			];
		}
		delete_transient( self::LOCK );

		return [
			'state' => 'working',
			'done'  => (int) $queue['done'],
			'total' => (int) $queue['total'],
		];
	}

	/**
	 * Cron: one batch, and another event while work remains.
	 */
	public static function run(): void {
		$state = self::step();
		if ( in_array( $state['state'], [ 'working', 'busy' ], true ) && ! wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::RUN_HOOK );
		}
	}

	/**
	 * A page was saved or changed status: rescan it a minute later (deferred, never inline).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, Scanner::post_types(), true ) ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return; // Drafts are not scanned.
		}
		$queue = self::queue();
		if ( null !== $queue ) {
			if ( ! in_array( $post->ID, $queue['ids'], true ) ) {
				$queue['ids'][] = (int) $post->ID;
				++$queue['total'];
				unset( $queue['finalized'] ); // The site-wide pass runs again after the new page.
				update_option( self::QUEUE, $queue, false );
			}
			return;
		}
		// One queued run for any number of saves (ten saves are not ten site-wide passes).
		self::start( 'incremental', [ (int) $post->ID ] );
	}

	/**
	 * Cron: rescan one page and re-run the site-wide pass (cheap: no fetching except new link targets).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function run_post( $post_id ): void {
		if ( null !== self::queue() ) {
			return; // A queued scan will reach it.
		}
		$scanner = new Scanner();
		$scanner->scan_page( (int) $post_id );
		$scanner->finalize();
	}

	/**
	 * A push arrived: rescan what changed since its last scan, then re-rank everything.
	 */
	public static function after_push(): void {
		if ( null !== self::queue() ) {
			return;
		}
		$times = ( new Scan_Store() )->scanned_times();
		$ids   = [];
		foreach ( Scanner::published_ids() as $id ) {
			$post = get_post( $id );
			if ( ! isset( $times[ $id ] ) || ( $post instanceof \WP_Post && $post->post_modified_gmt > $times[ $id ] ) ) {
				$ids[] = $id;
			}
		}
		self::start( 'incremental', $ids );
	}

	/**
	 * Remove the scan's cron events (deactivation, uninstall).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::RUN_HOOK );
		wp_unschedule_hook( self::POST_HOOK );
		delete_option( self::QUEUE );
	}
}
