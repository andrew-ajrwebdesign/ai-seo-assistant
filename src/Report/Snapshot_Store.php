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

	/** Option holding the billing-month snapshots (5.0), keyed by the month's first day. Not autoloaded. */
	public const MONTHS = 'ai_seo_assistant_report_months';

	/** Billing months kept (12 months, as the weeks). */
	public const KEEP_MONTHS = 12;

	/** Option: the last Google Business Profile check (snapshot v2 `business_profile_check`). Not autoloaded. */
	public const LISTING = 'ai_seo_assistant_listing_check';

	/** Action fired after a push or import is stored; the SEO scan listens (Scan\Scheduler::after_push). */
	public const RECEIVED_ACTION = 'ai_seo_assistant_report_received';

	/**
	 * Store whatever a validated push carries: the week or month, the per-page search data (v2), and the
	 * billing day. The single entry point for the push endpoint and the Import form.
	 *
	 * @param array<string,mixed> $snapshot Output of Snapshot::from_json().
	 * @param int                 $now      Current unix time.
	 * @return string put()'s result.
	 */
	public function receive( array $snapshot, int $now ): string {
		$pages   = $snapshot['pages'] ?? [];
		$range   = $snapshot['pages_range'] ?? [];
		$day     = $snapshot['billing_day'] ?? null;
		$listing = $snapshot['listing'] ?? null;
		unset( $snapshot['pages'], $snapshot['pages_range'], $snapshot['billing_day'], $snapshot['listing'] );

		$result = 'month' === ( $snapshot['period'] ?? 'week' ) ? $this->put_month( $snapshot, $now ) : $this->put( $snapshot, $now );
		if ( 'stale' === $result || 'dropped' === $result ) {
			return $result;
		}
		if ( is_int( $day ) ) {
			update_option( \AJR\SEOAssistant\AI\Spend::PUSHED_DAY_OPTION, $day, false );
		}
		if ( is_array( $listing ) ) {
			update_option( self::LISTING, $listing + [ 'received' => $now ], false );
		}
		if ( is_array( $pages ) && [] !== $pages ) {
			( new \AJR\SEOAssistant\Search\Page_Data() )->replace_all( $pages, is_array( $range ) ? $range : [], (int) $snapshot['generated_at'] );
		}

		/**
		 * A report push (or import) was stored.
		 *
		 * @param array<string,mixed> $snapshot The snapshot, without its per-page data.
		 * @param bool                $pages    Whether it carried per-page search data.
		 */
		do_action( self::RECEIVED_ACTION, $snapshot, [] !== $pages );

		return $result;
	}

	/**
	 * Store a billing-month snapshot (same replace / stale rules as a week).
	 *
	 * @param array<string,mixed> $snapshot Clean month snapshot.
	 * @param int                 $now      Current unix time.
	 * @return string 'stored' | 'replaced' | 'unchanged' | 'stale' | 'dropped'
	 */
	public function put_month( array $snapshot, int $now ): string {
		$months = $this->months();
		$key    = (string) $snapshot['month']['start'];
		$was    = $months[ $key ] ?? null;
		if ( is_array( $was ) && (int) $was['generated_at'] > (int) $snapshot['generated_at'] ) {
			return 'stale';
		}
		if ( is_array( $was ) && $was === $snapshot ) {
			$this->touch( $now );
			return 'unchanged';
		}
		$months[ $key ] = $snapshot;
		krsort( $months );
		$months = array_slice( $months, 0, self::KEEP_MONTHS, true );
		if ( ! isset( $months[ $key ] ) ) {
			return 'dropped';
		}
		update_option( self::MONTHS, $months, false );
		$this->touch( $now );

		return is_array( $was ) ? 'replaced' : 'stored';
	}

	/**
	 * All stored billing months, newest first.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function months(): array {
		$months = get_option( self::MONTHS, [] );
		if ( ! is_array( $months ) ) {
			return [];
		}
		krsort( $months );

		return $months;
	}

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
