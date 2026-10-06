<?php
/**
 * Stale_Alert — tells the agency when the weekly update stops arriving.
 *
 * Decided 2026-09-29 (Andrew: "build it"): a daily check compares the last accepted push with the clock.
 * More than LATE_AFTER_DAYS without one → ONE email to the alert address; then silence until a push
 * arrives → ONE "back on track" email. The late notice on the report says the agency "has been sent an
 * alert" only when this class has actually sent it (alerted()).
 *
 * Runs on WP-Cron (daily), never on a visitor's page view. Before the first push nothing is sent: an
 * empty report is expected, not late.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Daily lateness check and alert emails.
 */
class Stale_Alert {

	/** Cron hook. */
	public const HOOK = 'ai_seo_assistant_report_stale_check';

	/** Option recording an alert that has been sent (cleared on recovery). */
	public const STATE = 'ai_seo_assistant_report_alert';

	/** Option holding the alert address (empty = the site admin email). */
	public const ADDRESS = 'ai_seo_assistant_report_alert_email';

	/**
	 * Days after a week's update was due before it counts as late — one rule, shared with the report.
	 *
	 * Defined HERE, and Report_View points at it, not the other way round: this class is built on every
	 * request (for its cron hook), and PHP resolves a constant that names another class's constant the
	 * first time the class is used, so the old direction loaded the whole report renderer on every
	 * visitor's page view (4.4.0, measured with get_included_files()).
	 */
	public const LATE_AFTER_DAYS = 8;

	/**
	 * Storage.
	 *
	 * @var Snapshot_Store
	 */
	protected Snapshot_Store $store;

	/**
	 * Constructor — dependencies only.
	 *
	 * @param Snapshot_Store $store Storage.
	 */
	public function __construct( Snapshot_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Register hooks and make sure the daily check is scheduled.
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'check' ] );
		add_action( 'init', [ $this, 'schedule' ] );
	}

	/**
	 * Schedule the daily check once. Checked on admin and cron requests only — the plugin is updated by
	 * zip or FTP, where no activation hook fires, so it self-heals on the next admin visit instead of
	 * costing every front-end page view a walk of the cron array.
	 */
	public function schedule(): void {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the daily check (plugin deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The daily check.
	 *
	 * @param int|null $now Unix time (tests pass one).
	 * Lateness is measured exactly as the client's late notice measures it (Report_View::is_late(): the
	 * newest WEEK held, not the time of the last push), so a push that keeps re-sending an old week does
	 * not hide the problem: the client would see "these figures are N days old" and so will the agency.
	 *
	 * @return string 'waiting' (no report yet) | 'ok' | 'alerted' | 'already-alerted' | 'recovered'
	 */
	public function check( $now = null ): string {
		$now    = is_int( $now ) ? $now : time();
		$latest = $this->store->latest();
		if ( null === $latest ) {
			return 'waiting';
		}
		$late = Report_View::is_late( $latest, $now );
		$sent = self::alerted();

		if ( $late && $sent ) {
			return 'already-alerted';
		}
		if ( $late ) {
			$due  = (int) strtotime( $latest['week']['end'] . ' +1 day 00:00:00 UTC' );
			$days = (int) floor( ( $now - $due ) / DAY_IN_SECONDS );
			/* translators: 1: site name, 2: number of days. */
			$subject = sprintf( __( '[%1$s] Weekly report is %2$d days late', 'ai-seo-assistant' ), $this->site(), $days );
			$body    = sprintf(
				/* translators: 1: site address, 2: the week the newest figures cover, 3: date of the last push received. */
				__( "The weekly report for %1\$s still shows %2\$s. The last push arrived %3\$s.\n\nCheck the retainer-scan weekly run for this site (key, schedule, or a host blocking POST requests to /wp-json/ai-seo-assistant/v1/report). The client sees a 'these figures are old' notice until a new week arrives.", 'ai-seo-assistant' ),
				home_url( '/' ),
				Report_View::range( $latest['week'], false ),
				$this->store->last_push() > 0 ? wp_date( 'l j F Y, g:ia', $this->store->last_push() ) : __( 'never', 'ai-seo-assistant' )
			);
			if ( wp_mail( $this->address(), $subject, $body ) ) {
				update_option(
					self::STATE,
					[
						'sent' => $now,
						'week' => $latest['week']['start'],
					],
					false
				);
				return 'alerted';
			}
			return 'ok'; // Mail failed: try again tomorrow, and the report does not claim an alert was sent.
		}
		if ( $sent ) {
			delete_option( self::STATE );
			/* translators: %s: site name. */
			wp_mail( $this->address(), sprintf( __( '[%s] Weekly report is back on track', 'ai-seo-assistant' ), $this->site() ), __( 'A new weekly report has arrived. No action needed.', 'ai-seo-assistant' ) );
			return 'recovered';
		}

		return 'ok';
	}

	/**
	 * Whether a lateness alert has been sent and not yet cleared by a new push.
	 */
	public static function alerted(): bool {
		return is_array( get_option( self::STATE, false ) );
	}

	/**
	 * Where alerts go.
	 */
	protected function address(): string {
		$to = (string) get_option( self::ADDRESS, '' );

		return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
	}

	/**
	 * The site's name, for subjects.
	 */
	protected function site(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
