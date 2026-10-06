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

	/**
	 * The token of the scan lock this request holds ('' when none): release() deletes only that row.
	 *
	 * @var string
	 */
	protected static string $token = '';

	/** Option: the run Cancel stopped (its queue's 'run'), checked by step() before every queue write. */
	public const CANCELLED = 'ai_seo_assistant_scan_cancelled';

	/** Option row: the scan lock (acquire() / release(); token and expiry, never read through the cache). */
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
				'run'     => uniqid( 'scan', true ), // Cancel names the run it stops (CANCELLED).
				// The issue count before this scan, for the finish line's "N fewer issues than before".
				'before'  => isset( Scan_Store::meta()['issues'] ) ? (int) Scan_Store::meta()['issues'] : null,
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
		if ( ! self::acquire( 2 * $budget + 30 ) ) {
			return [
				'state' => 'busy',
				'done'  => (int) $queue['done'],
				'total' => (int) $queue['total'],
			];
		}

		if ( isset( $queue['current'] ) ) {
			// The last step died inside this page (a fatal, a timeout): it is not tried again in this run,
			// so one broken page cannot stop the scan for ever.
			$queue['skipped'] = array_merge( (array) ( $queue['skipped'] ?? [] ), [ (int) $queue['current'] ] );
			$queue['done']    = (int) $queue['done'] + 1;
			unset( $queue['current'] );
		}

		$scanner = new Scanner();
		$until   = microtime( true ) + $budget;
		while ( [] !== $queue['ids'] && microtime( true ) < $until ) {
			$queue['current'] = (int) array_shift( $queue['ids'] );
			if ( ! self::write( $queue ) ) { // In flight: see above.
				return self::stopped( $queue );
			}
			$scanner->scan_page( $queue['current'] );
			unset( $queue['current'] );
			++$queue['done'];
			if ( ! self::write( $queue ) ) { // Progress survives a fatal or a timeout.
				return self::stopped( $queue );
			}
		}

		if ( [] === $queue['ids'] ) {
			// The site-wide pass once, then the intent pass in its own steps (one Claude batch per step, with
			// the time limit raised for it), so neither shares a request with the other.
			if ( empty( $queue['finalized'] ) ) {
				if ( self::cancelled( $queue ) ) {
					return self::stopped( $queue );
				}
				$queue['result']    = $scanner->finalize();
				$queue['finalized'] = true;
				if ( ! self::write( $queue ) ) {
					return self::stopped( $queue );
				}
				self::release();

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
				self::release();

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
			self::release();

			return [
				'state'  => 'done',
				'done'   => (int) $queue['done'],
				'total'  => (int) $queue['total'],
				'result' => $result,
				'before' => $queue['before'] ?? null,
			];
		}
		self::release();

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
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			Auto_Types::on_publish( (int) $post->ID ); // A new page with an obvious type gets it now.
		}
		self::queue_page( (int) $post->ID );
	}

	/**
	 * Queue one page for a rescan: it joins the queued run, or starts one (any number of saves, or "Done"
	 * ticks in the editor, are one run, not one site-wide pass each).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function queue_page( int $post_id ): void {
		$queue = self::queue();
		if ( null !== $queue ) {
			if ( ! in_array( $post_id, $queue['ids'], true ) ) {
				$queue['ids'][] = $post_id;
				++$queue['total'];
				unset( $queue['finalized'] ); // The site-wide pass runs again after the new page.
				update_option( self::QUEUE, $queue, false );
			}
			return;
		}
		self::start( 'incremental', [ $post_id ] );
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
		$times     = ( new Scan_Store() )->scanned_times();
		$ids       = [];
		$published = Scanner::published_ids();
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $published, false, false ); // One query for every page, not one each.
		}
		foreach ( $published as $id ) {
			$post = get_post( $id );
			if ( ! isset( $times[ $id ] ) || ( $post instanceof \WP_Post && $post->post_modified_gmt > $times[ $id ] ) ) {
				$ids[] = $id;
			}
		}
		self::start( 'incremental', $ids );
	}

	/**
	 * Stop a scan (the progress bar's Cancel). The step in progress finishes first (the screen waits for it);
	 * the pages scanned so far are kept and judged once, without network; the rest are not scanned.
	 *
	 * @return array{state:string,done:int,total:int}
	 */
	public static function cancel(): array {
		$queue = self::queue();
		if ( null === $queue ) {
			return [
				'state' => 'idle',
				'done'  => 0,
				'total' => 0,
			];
		}
		// The flag first: a step running in another request checks it before each queue write, so it cannot
		// put the queue back after it is deleted here.
		update_option( self::CANCELLED, (string) ( $queue['run'] ?? $queue['started'] ?? '' ), false );
		delete_option( self::QUEUE );
		wp_clear_scheduled_hook( self::RUN_HOOK );
		( new Scanner() )->finalize( false );
		Ranking::flush();

		return [
			'state' => 'cancelled',
			'done'  => (int) $queue['done'],
			'total' => (int) $queue['total'],
		];
	}

	/**
	 * Write the queue, unless this run was cancelled meanwhile.
	 *
	 * @param array<string,mixed> $queue Queue.
	 * @return bool False when cancelled (nothing written).
	 */
	protected static function write( array $queue ): bool {
		if ( self::cancelled( $queue ) ) {
			return false;
		}
		update_option( self::QUEUE, $queue, false );

		return true;
	}

	/**
	 * Whether Cancel stopped this run. Read from the database itself: the option cache of a request that
	 * started before the Cancel would still say no.
	 *
	 * @param array<string,mixed> $queue Queue.
	 */
	protected static function cancelled( array $queue ): bool {
		global $wpdb;
		$run = (string) ( $queue['run'] ?? $queue['started'] ?? '' );
		if ( '' === $run || ! isset( $wpdb ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- past the cache on purpose (see above).
		return $run === (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::CANCELLED ) );
	}

	/**
	 * The answer for a step that found its run cancelled (the lock released, nothing written).
	 *
	 * @param array<string,mixed> $queue Queue.
	 * @return array{state:string,done:int,total:int}
	 */
	protected static function stopped( array $queue ): array {
		self::release();

		return [
			'state' => 'cancelled',
			'done'  => (int) $queue['done'],
			'total' => (int) $queue['total'],
		];
	}

	/**
	 * Take the scan lock, atomically: one INSERT IGNORE of the lock row, which only one request can win
	 * (add_option() is not enough: it reads first, then inserts with ON DUPLICATE KEY UPDATE, so two
	 * requests can both "win"). The row holds this request's token and when the lock expires; an expired
	 * lock (a request that died holding it) is removed, only if it is still that same row, and taken.
	 *
	 * @param int $ttl Seconds the lock lasts.
	 * @return bool Whether this request now holds it.
	 */
	public static function acquire( int $ttl ): bool {
		global $wpdb;
		$token = wp_generate_password( 20, false ) . '|' . ( time() + $ttl );
		for ( $try = 0; $try < 2; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic insert-if-absent; the lock row is never read through the option cache.
			$won = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $token ) );
			if ( 1 === $won ) {
				self::$token = $token;
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
			$held    = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
			$expires = (int) substr( (string) strrchr( $held, '|' ), 1 );
			if ( '' === $held || $expires >= time() ) {
				return false; // Held, and not expired.
			}
			// Expired: remove exactly that row (another request may have just replaced it), then try again.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $held ) );
		}

		return false;
	}

	/**
	 * Release the scan lock, only if this request holds it (a lock another request took is left alone).
	 */
	public static function release(): void {
		global $wpdb;
		if ( '' === self::$token ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock row, by its owner's token only.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, self::$token ) );
		self::$token = '';
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
