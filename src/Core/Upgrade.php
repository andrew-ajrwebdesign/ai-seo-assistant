<?php
/**
 * Upgrade — one-time data changes, run once per site per level.
 *
 * The plugin is updated by zip, FTP or WP-CLI as often as through the Plugins screen, and no activation
 * hook fires for any of those. So each one-time change is keyed to a LEVEL recorded in an autoloaded
 * marker option (`ai_seo_assistant_settings_version`, the marker 4.0.0 introduced): once a site is at the
 * current level, every later admin request costs one in-memory comparison and nothing else.
 *
 * Runs on admin_init for an Administrator and on `upgrader_process_complete` for this plugin. Never on a
 * visitor's page view.
 *
 * Levels:
 * - 4.0.0: remove the OpenAI-era key and model.
 * - 4.4.0: encrypt every plain-text secret in place; create the agency-users option autoloaded.
 * - 5.0.0: create the scan, page-data and change-log tables; REVOKE the stored Google Search Console grant
 *          at Google (best effort, 3 s; a failure never blocks) and delete it with the OAuth client and its
 *          cache (the on-site Search Console sign-in is gone: the site never holds Google credentials);
 *          retire this plugin's own redirects table, but ONLY when it holds no enabled rule (redirects are
 *          AJR Core's job, and 5.0 no longer serves them; enabled rules stay, the redirect part stays
 *          pending, and the agency is told to move them into AJR Core first: see redirects_pending()); and
 *          rebuild the rewrite rules without this plugin's old /llms.txt rules. Markdown for AI's settings
 *          (`wpmai_*`) are shared with AJR Core, which owns that job now, so they are left as they are.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Core;

use AJR\SEOAssistant\Report\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned one-time upgrade steps.
 */
class Upgrade {

	/** Autoloaded marker holding the level reached. */
	public const OPTION = 'ai_seo_assistant_settings_version';

	/** The level this code brings a site to. Moves only when a release adds a step, never with the header. */
	public const LEVEL = '5.0.0';

	/** Option that held the OpenAI key before the move to Claude (4.0.0). */
	public const LEGACY_OPENAI_KEY_OPTION = 'ai_seo_assistant_api_key';

	/** The 4.x redirects table's schema marker. */
	public const REDIRECTS_DB_VERSION_OPTION = 'ai_seo_assistant_redirects_db_version';

	/** The 4.x redirects options (lookup map, matching flag). */
	public const REDIRECTS_OPTIONS = [ 'ai_seo_assistant_redirect_map', 'ai_seo_assistant_redirects_case_insensitive', self::REDIRECTS_DB_VERSION_OPTION ];

	/** Autoloaded only while the 5.0 redirect step is refused: the number of enabled rules still held. */
	public const REDIRECTS_PENDING = 'ai_seo_assistant_redirects_pending';

	/** The removed on-site Search Console connection's options (5.0). */
	public const GSC_OPTIONS = [
		'ai_seo_assistant_gsc_client_id',
		'ai_seo_assistant_gsc_client_secret',
		'ai_seo_assistant_gsc_token_data',
		'ai_seo_assistant_gsc_selected_site',
		'ai_seo_assistant_gsc_cache',
		'ai_seo_assistant_gsc_last_sync',
		'ai_seo_assistant_gsc_last_sync_range',
	];

	/** A failed 4.4.0 attempt (a secret could not be sealed): see waiting(). Exists only while failing. */
	public const ATTEMPT_OPTION = 'ai_seo_assistant_upgrade_attempt';

	/** Seconds between admin-load retries after a failed attempt. */
	public const RETRY_AFTER = 86400;

	/** Set when the 5.0 step held a Google grant that Google did not confirm revoking: one agency notice. */
	public const REVOKE_FAILED = 'ai_seo_assistant_google_revoke_failed';

	/** Google's OAuth revocation endpoint. */
	public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/**
	 * Register hooks (admin requests only: the caller builds this class in wp-admin).
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_run' ] );
		// upgrader_process_complete is hooked by Plugin::init() on every update path (screens, admin-ajax's
		// one-click update, WP-CLI), not only where the admin screens are built.
	}

	/**
	 * Run the pending steps when an Administrator loads wp-admin.
	 *
	 * The capability is checked here, on admin_init, not at construction: admin-post.php fires admin_init
	 * before it checks the login, and current_user_can() at plugins_loaded runs before authentication.
	 */
	public function maybe_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// The custom tables follow their own version: any mismatch (a column change in a point release, a
		// table lost to a restore) re-runs dbDelta, which adds and alters columns without losing rows.
		if ( ! Schema::is_current() ) {
			Schema::install();
		}
		if ( self::is_current() || self::waiting() ) {
			return;
		}
		self::run();
	}

	/**
	 * Whether a failed attempt is recent enough that this admin load should not try again.
	 */
	public static function waiting(): bool {
		$attempt = self::attempt();

		return isset( $attempt['at'] ) && ( time() - (int) $attempt['at'] ) < self::RETRY_AFTER;
	}

	/**
	 * The recorded failed attempt: [ 'at' => timestamp, 'failed' => option names ].
	 *
	 * @return array<string,mixed> [] when none is recorded.
	 */
	public static function attempt(): array {
		$attempt = get_option( self::ATTEMPT_OPTION, [] );

		return is_array( $attempt ) ? $attempt : [];
	}

	/**
	 * Run the steps after WordPress updates this plugin through the upgrader.
	 *
	 * @param mixed               $upgrader Upgrader instance (unused).
	 * @param array<string,mixed> $options  Update details.
	 */
	public function after_update( $upgrader, $options ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- the action's signature.
		$options = (array) $options;
		if ( 'plugin' !== ( $options['type'] ?? '' ) ) {
			return;
		}
		$plugins = (array) ( $options['plugins'] ?? [ $options['plugin'] ?? '' ] );
		if ( ! in_array( AI_SEO_ASSISTANT_BASENAME, $plugins, true ) ) {
			return;
		}
		if ( ! Schema::is_current() ) {
			Schema::install();
		}
		if ( ! self::is_current() ) {
			self::run();
		}
	}

	/**
	 * Whether this site is at the current level.
	 */
	public static function is_current(): bool {
		return version_compare( (string) get_option( self::OPTION, '0' ), self::LEVEL, '>=' );
	}

	/**
	 * Run every step above the site's level, then record the level.
	 *
	 * The level is NOT advanced past 4.0.0 when a secret could not be sealed, so a later run tries again.
	 *
	 * @return array<string,string> Secret migration results (option => result), for WP-CLI and tests.
	 */
	public static function run(): array {
		$level   = (string) get_option( self::OPTION, '0' );
		$results = [];

		if ( version_compare( $level, '4.0.0', '<' ) ) {
			self::remove_openai_settings();
		}

		if ( version_compare( $level, '4.4.0', '<' ) ) {
			$attempt = self::attempt();
			$results = Secret_Store::migrate_all();
			add_option( Access::OPTION, [], '', true ); // No-op when it exists.

			$failed = array_keys( array_filter( $results, static fn( $result ) => 'failed' === $result ) );
			if ( [] !== $failed ) {
				if ( version_compare( $level, '4.0.0', '<' ) ) {
					update_option( self::OPTION, '4.0.0', true );
				}
				update_option(
					self::ATTEMPT_OPTION,
					[
						'at'     => time(),
						'failed' => $failed,
					],
					true
				);
				return $results;
			}
			if ( [] !== $attempt ) {
				delete_option( self::ATTEMPT_OPTION );
			}
		}

		if ( version_compare( $level, '5.0.0', '<' ) ) {
			Schema::install();
			self::retire_search_console();
			self::retire_redirects();
			delete_option( 'rewrite_rules' ); // Rebuilt on the next request without the old /llms.txt rules.
		}

		update_option( self::OPTION, self::LEVEL, true );

		return $results;
	}

	/**
	 * Revoke the stored Google grant (best effort) and delete every Search Console connection option.
	 *
	 * @return bool Whether Google confirmed the revoke (false also when there was nothing to revoke).
	 */
	public static function retire_search_console(): bool {
		$token   = Secret_Store::get_array( 'ai_seo_assistant_gsc_token_data' );
		$revoked = self::revoke_google( $token );
		if ( ! $revoked && ( ! empty( $token['refresh_token'] ) || ! empty( $token['access_token'] ) ) ) {
			// The grant may still be live at Google (a copy in an old backup would still work): the agency is
			// told once to remove it by hand. The local token is deleted either way.
			update_option( self::REVOKE_FAILED, time(), false );
		}
		foreach ( self::GSC_OPTIONS as $option ) {
			delete_option( $option );
		}

		return $revoked;
	}

	/**
	 * Ask Google to revoke a token, so a refresh token copied out of an old backup stops working.
	 *
	 * Three seconds at most, and a failure changes nothing locally: the token is deleted either way.
	 *
	 * @param array<string,mixed> $token_data Stored token data.
	 */
	public static function revoke_google( array $token_data ): bool {
		$token = ! empty( $token_data['refresh_token'] ) ? $token_data['refresh_token'] : ( $token_data['access_token'] ?? '' );
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		$response = wp_remote_post(
			self::REVOKE_URL,
			[
				'timeout' => 3,
				'body'    => [ 'token' => $token ],
			]
		);

		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Retire the 4.x redirects table and options, unless it still holds ENABLED rules.
	 *
	 * 5.0 does not serve redirects (AJR Core does). A table with enabled rules is someone's live redirects,
	 * so nothing is dropped: the count is recorded in REDIRECTS_PENDING, which shows the agency a notice
	 * (Secret_Notices) until the rules are confirmed in AJR Core (confirm_redirects_moved()).
	 *
	 * @return string 'dropped' | 'absent' | 'enabled-rows'
	 */
	public static function retire_redirects(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_seo_assistant_redirects';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-time schema check.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			foreach ( self::REDIRECTS_OPTIONS as $option ) {
				delete_option( $option );
			}
			delete_option( self::REDIRECTS_PENDING );
			return 'absent';
		}
		$enabled = count( self::enabled_redirect_sources() );
		if ( $enabled > 0 ) {
			update_option( self::REDIRECTS_PENDING, $enabled, true );
			return 'enabled-rows';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dropping this plugin's own table, which holds no enabled rule.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		foreach ( self::REDIRECTS_OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_option( self::REDIRECTS_PENDING );

		return 'dropped';
	}

	/**
	 * The source paths of the 4.x table's enabled rules ([] when the table is gone).
	 *
	 * @return array<int,string>
	 */
	public static function enabled_redirect_sources(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_seo_assistant_redirects';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-time schema check.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$rows = (array) $wpdb->get_col( "SELECT source_path FROM `{$table}` WHERE is_enabled = 1" );

		return array_map( 'strval', $rows );
	}

	/**
	 * The agency says the rules are in AJR Core: retire the old table only if every enabled rule's source
	 * really is in AJR Core's redirect map; otherwise return the ones still missing.
	 *
	 * @return array<int,string> Sources not found in AJR Core ([] = retired).
	 */
	public static function confirm_redirects_moved(): array {
		$map   = get_option( 'ajr_core_redirect_map', [] );
		$known = [];
		foreach ( is_array( $map ) ? array_keys( $map ) : [] as $source ) {
			$known[ strtolower( untrailingslashit( (string) $source ) ) ] = true;
		}
		$missing = [];
		foreach ( self::enabled_redirect_sources() as $source ) {
			if ( ! isset( $known[ strtolower( untrailingslashit( $source ) ) ] ) ) {
				$missing[] = $source;
			}
		}
		if ( [] !== $missing ) {
			return $missing;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ai_seo_assistant_redirects';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every enabled rule is confirmed in AJR Core.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		foreach ( self::REDIRECTS_OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_option( self::REDIRECTS_PENDING );

		return [];
	}

	/**
	 * Delete the OpenAI-era key (a credential nothing reads) and reset an OpenAI model name.
	 */
	protected static function remove_openai_settings(): void {
		delete_option( self::LEGACY_OPENAI_KEY_OPTION );

		$model = get_option( \AJR\SEOAssistant\AI\Claude_Client::OPTION_MODEL, false );
		if ( false !== $model && ! \AJR\SEOAssistant\AI\Claude_Client::is_supported_model( $model ) ) {
			update_option( \AJR\SEOAssistant\AI\Claude_Client::OPTION_MODEL, \AJR\SEOAssistant\AI\Claude_Client::DEFAULT_MODEL, false );
		}
	}
}
