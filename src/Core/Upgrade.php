<?php
/**
 * Upgrade — one-time data changes, run once per site per level.
 *
 * The plugin is updated by zip, FTP or WP-CLI as often as through the Plugins screen, and no activation
 * hook fires for any of those. So each one-time change is keyed to a LEVEL recorded in an autoloaded
 * marker option (`ai_seo_assistant_settings_version`, the marker 4.0.0 introduced): once a site is at the
 * current level, every later admin request costs one in-memory comparison and nothing else.
 *
 * Runs on admin_init for an Administrator, on `upgrader_process_complete` for this plugin, and on
 * `activated_plugin` (a core plugin switched on can retire this plugin's empty redirects table). Never on
 * a visitor's page view.
 *
 * Levels:
 * - 4.0.0: remove the OpenAI-era key and model (moved from Admin::remove_legacy_openai_settings()).
 * - 4.4.0: encrypt every plain-text secret in place; create the agency-users option autoloaded so the
 *          capability check never queries for a missing row; drop the redirects table this plugin no
 *          longer uses when a core plugin owns redirects and the table is empty.
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
	public const LEVEL = '4.4.0';

	/** Option that held the OpenAI key before the move to Claude (4.0.0). */
	public const LEGACY_OPENAI_KEY_OPTION = 'ai_seo_assistant_api_key';

	/** This plugin's redirects schema marker (Redirects\Redirect_Store::DB_VERSION_OPTION). */
	public const REDIRECTS_DB_VERSION_OPTION = 'ai_seo_assistant_redirects_db_version';

	/**
	 * Register hooks (admin requests only: the caller builds this class in wp-admin).
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_run' ] );
		add_action( 'upgrader_process_complete', [ $this, 'after_update' ], 10, 2 );
		add_action( 'activated_plugin', [ self::class, 'retire_redirects_table' ] );
	}

	/**
	 * Run the pending steps when an Administrator loads wp-admin.
	 *
	 * The capability is checked here, on admin_init, not at construction: admin-post.php fires admin_init
	 * before it checks the login, and current_user_can() at plugins_loaded runs before authentication.
	 */
	public function maybe_run(): void {
		if ( self::is_current() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::run();
	}

	/**
	 * Run the steps after WordPress updates this plugin through the upgrader.
	 *
	 * (On the update request itself the OLD code is in memory, so this mostly serves the update after;
	 * the admin_init path covers the first one.)
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
		if ( in_array( AI_SEO_ASSISTANT_BASENAME, $plugins, true ) && ! self::is_current() ) {
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
	 * The level is NOT advanced when a secret could not be sealed, so the next admin load tries again
	 * rather than leaving a plain-text key behind for good.
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
			$results = Secret_Store::migrate_all();
			add_option( Access::OPTION, [], '', true ); // No-op when it exists.
			self::retire_redirects_table();

			if ( in_array( 'failed', $results, true ) ) {
				if ( version_compare( $level, '4.0.0', '<' ) ) {
					update_option( self::OPTION, '4.0.0', true );
				}
				return $results;
			}
		}

		update_option( self::OPTION, self::LEVEL, true );

		return $results;
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

	/**
	 * Drop this plugin's redirects table while a core plugin owns redirects and the table is EMPTY.
	 *
	 * A table with rows is left alone: those rows are someone's redirects, and AJR Core's one-time import
	 * reads them. An empty one is dead weight from the activation hook of a version that did not yet know
	 * to stand down.
	 *
	 * @return string 'dropped' | 'kept-rows' | 'absent' | 'not-owned'
	 */
	public static function retire_redirects_table(): string {
		if ( ! Plugin::core_owns_redirects() ) {
			return 'not-owned';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ai_seo_assistant_redirects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-time schema check.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			delete_option( self::REDIRECTS_DB_VERSION_OPTION );
			return 'absent';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name; a one-time check.
		if ( 0 !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) ) {
			return 'kept-rows';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dropping this plugin's own empty table.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		delete_option( self::REDIRECTS_DB_VERSION_OPTION );

		return 'dropped';
	}
}
