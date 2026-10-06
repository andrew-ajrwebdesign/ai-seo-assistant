<?php
/**
 * Schema — the plugin's three custom tables.
 *
 * WHY TABLES, NOT POST META. The scan keeps a few KB of facts, issues and Claude's suggestions per page.
 * As post meta that would ride along in the meta cache WordPress primes for every post a visitor's page
 * lists or shows, so every page view would carry it; in its own table it is read only on the agency's
 * screens. The same goes for the pushed per-page search data (keyed by URL path, not post, because Google
 * reports addresses) and the change log, which is a history and grows.
 *
 * - {prefix}aisa_scan     one row per published page: facts, issues, suggestions.
 * - {prefix}aisa_pages    one row per URL path: the pushed Search Console + GA4 figures (snapshot v2).
 * - {prefix}aisa_changes  one row per applied field: before, after, who, when, and the measured effect.
 *
 * Installed on activation and by Core\Upgrade (an update by zip or WP-CLI fires no activation hook).
 * Nothing here runs on a visitor's request.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and names the custom tables.
 */
class Schema {

	/** Option holding the installed table version (autoloaded: compared on admin requests). */
	public const VERSION_OPTION = 'ai_seo_assistant_db_version';

	/** Current table version. */
	public const VERSION = '6'; // The tables' own version, not the plugin's. 2: the change log's before/after are longtext (a builder page's content is often over 64 KB). 3: scan.flags (listing / form, found at scan time). 4: changes.note (a content row's one-line summary, written when logged). 5: scan.body_text (the page's rendered visible text, for the editor advice's phrase check and the review). 6: scan.inbound (the links to the page from the other scanned pages, built once per site-wide pass).

	/**
	 * The newest column of each table: the version is stored only once each really exists, so a failed
	 * ALTER (no ALTER privilege, a full disk) is tried again on the next admin load instead of being taken
	 * as done.
	 *
	 * @var array<string,array<int,string>>
	 */
	public const NEWEST = [
		'scan'    => [ 'body_text', 'inbound' ],
		'changes' => [ 'note' ],
	];

	/**
	 * A table's full name.
	 *
	 * @param string $name 'scan' | 'pages' | 'changes'.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'aisa_' . $name;
	}

	/**
	 * Whether the tables are at the current version.
	 */
	public static function is_current(): bool {
		return self::VERSION === (string) get_option( self::VERSION_OPTION, '' );
	}

	/** Whether ensure() already tried install() in this request (a failing ALTER is not retried per write). */
	protected static bool $tried = false;

	/**
	 * Bring the tables up to date before writing to them: every custom-table writer calls this first. An
	 * update that never ran install() (a zip uploaded over the plugin, SFTP, a request with no admin_init)
	 * would otherwise write a column that is not there yet and fail silently. Tried once per request.
	 *
	 * @return bool Whether the tables are current.
	 */
	public static function ensure(): bool {
		if ( self::is_current() ) {
			return true;
		}
		if ( ! self::$tried ) {
			self::$tried = true;
			self::install();
		}

		return self::is_current();
	}

	/**
	 * Create or update the tables (dbDelta is idempotent).
	 */
	public static function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$scan    = self::table( 'scan' );
		$pages   = self::table( 'pages' );
		$changes = self::table( 'changes' );

		// dbDelta's format: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$scan} (
post_id bigint(20) unsigned NOT NULL,
path varchar(191) NOT NULL DEFAULT '',
post_type varchar(20) NOT NULL DEFAULT '',
scanned_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
source varchar(10) NOT NULL DEFAULT '',
issue_count smallint(5) unsigned NOT NULL DEFAULT 0,
issue_kinds varchar(255) NOT NULL DEFAULT '',
flags varchar(32) NOT NULL DEFAULT '',
facts longtext NOT NULL,
body_text mediumtext NULL,
inbound mediumtext NULL,
issues longtext NOT NULL,
suggestions longtext NULL,
suggested_at datetime NULL DEFAULT NULL,
PRIMARY KEY  (post_id),
KEY path (path)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$pages} (
path varchar(191) NOT NULL,
data longtext NOT NULL,
updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
PRIMARY KEY  (path)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$changes} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
batch varchar(32) NOT NULL DEFAULT '',
post_id bigint(20) unsigned NOT NULL DEFAULT 0,
path varchar(191) NOT NULL DEFAULT '',
field varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
before_value longtext NOT NULL,
after_value longtext NOT NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
applied_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
undone_at datetime NULL DEFAULT NULL,
undone_by bigint(20) unsigned NOT NULL DEFAULT 0,
effect longtext NULL,
note varchar(255) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
KEY post_id (post_id),
KEY applied_at (applied_at)
) {$charset};"
		);

		$missing = self::missing_columns();
		if ( [] !== $missing ) {
			// Not stored: the next admin load tries again, and the scan writes without the missing column meanwhile.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'AI SEO Assistant: the table update did not add ' . implode( ', ', $missing ) . ( '' !== (string) ( $wpdb->last_error ?? '' ) ? ' (' . $wpdb->last_error . ')' : '' ) . '; it is tried again on the next admin page load.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a failed table update the agency must be able to find.
			}
			return;
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * The newest columns (NEWEST) that are not in the tables, as "table.column".
	 *
	 * @return array<int,string>
	 */
	public static function missing_columns(): array {
		global $wpdb;
		$missing = [];
		foreach ( self::NEWEST as $name => $columns ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table, read after dbDelta.
			$have = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ) );
			foreach ( $columns as $column ) {
				if ( ! in_array( $column, $have, true ) ) {
					$missing[] = $name . '.' . $column;
				}
			}
		}

		return $missing;
	}

	/**
	 * Drop the tables (uninstall only).
	 */
	public static function drop(): void {
		global $wpdb;
		foreach ( [ 'scan', 'pages', 'changes' ] as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables, on uninstall.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		delete_option( self::VERSION_OPTION );
	}
}
