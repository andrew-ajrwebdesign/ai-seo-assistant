<?php
/**
 * Snapshot_Store — the weekly reports kept on the site.
 *
 * One option holds up to KEEP weeks of already-validated snapshots (Snapshot::clean()), keyed by the
 * week's Monday. It is NOT autoloaded: only the report screen, the push endpoint and the daily stale
 * check read it, never an ordinary page view. The time of the last accepted push lives in its own small
 * option so the daily stale check does not load a year of reports to read one number.
 *
 * Nothing here talks to Google or knows where a snapshot came from — the push endpoint and the import
 * form both validate first and then call put().
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and reads weekly snapshots.
 */
class Snapshot_Store {

	/** Option holding the snapshots, keyed by week start (YYYY-MM-DD). */
	public const OPTION = 'ai_seo_assistant_report_snapshots';

	/** Option holding the unix time of the last accepted push. */
	public const LAST_PUSH = 'ai_seo_assistant_report_last_push';

	/** Weeks kept: the report says "Reports are kept for 12 months". */
	public const KEEP = 52;

	/**
	 * Store a validated snapshot. A later push for the same week replaces it (a corrected report);
	 * an older generated_at for the same week is ignored, so a delayed retry cannot overwrite a fix.
	 *
	 * @param array<string,mixed> $snapshot Output of Snapshot::clean().
	 * @param int                 $now      Current unix time.
	 * @return string 'stored' | 'replaced' | 'unchanged' | 'stale' | 'dropped' (older than the 52 weeks kept)
	 */
	public function put( array $snapshot, int $now ): string {
		$weeks = $this->all();
		$key   = (string) $snapshot['week']['start'];
		$was   = $weeks[ $key ] ?? null;

		if ( is_array( $was ) && (int) $was['generated_at'] > (int) $snapshot['generated_at'] ) {
			return 'stale';
		}
		if ( is_array( $was ) && $was === $snapshot ) {
			$this->touch( $now );
			return 'unchanged';
		}

		$weeks[ $key ] = $snapshot;
		krsort( $weeks );
		$weeks = array_slice( $weeks, 0, self::KEEP, true );
		if ( ! isset( $weeks[ $key ] ) ) {
			return 'dropped'; // A backfill older than a year of kept weeks: nothing changes.
		}

		update_option( self::OPTION, $weeks, false );
		$this->touch( $now );

		return is_array( $was ) ? 'replaced' : 'stored';
	}

	/**
	 * All stored weeks, newest first.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		$weeks = get_option( self::OPTION, [] );
		if ( ! is_array( $weeks ) ) {
			return [];
		}
		krsort( $weeks );

		return $weeks;
	}

	/**
	 * One week, or null.
	 *
	 * @param string $week_start Monday, YYYY-MM-DD.
	 * @return array<string,mixed>|null
	 */
	public function get( string $week_start ): ?array {
		$weeks = $this->all();

		return isset( $weeks[ $week_start ] ) && is_array( $weeks[ $week_start ] ) ? $weeks[ $week_start ] : null;
	}

	/**
	 * The newest week, or null before the first push.
	 *
	 * @return array<string,mixed>|null
	 */
	public function latest(): ?array {
		$weeks = $this->all();
		$first = reset( $weeks );

		return is_array( $first ) ? $first : null;
	}

	/**
	 * Unix time of the last accepted push, 0 before the first.
	 */
	public function last_push(): int {
		return (int) get_option( self::LAST_PUSH, 0 );
	}

	/**
	 * Record that a push arrived (also when it changed nothing: the pipeline is alive).
	 *
	 * @param int $now Current unix time.
	 */
	protected function touch( int $now ): void {
		update_option( self::LAST_PUSH, $now, false );
	}
}
