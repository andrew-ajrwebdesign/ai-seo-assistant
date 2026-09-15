<?php
/**
 * Data layer for the redirect manager.
 *
 * Owns the custom redirects table (CRUD), path normalization, and the compact
 * autoloaded lookup map consumed by Redirect_Handler on the front end. Storing
 * redirects in a dedicated table keeps the list scalable without bloating the
 * wp_options table, while the denormalized map keeps the front-end hot path to a
 * single in-memory option read — no query and no write on normal page loads.
 */

namespace AJR\SEOAssistant\Redirects;

defined( 'ABSPATH' ) || exit;

class Redirect_Store {

	/**
	 * Option holding the compact front-end lookup map. Autoloaded because it is
	 * consulted on every front-end request.
	 */
	private const MAP_OPTION = 'ai_seo_assistant_redirect_map';

	/**
	 * Option holding the installed schema version.
	 */
	private const DB_VERSION_OPTION = 'ai_seo_assistant_redirects_db_version';

	/**
	 * Current schema version. Bump to trigger a dbDelta on upgrade.
	 */
	private const DB_VERSION = '1';

	/**
	 * HTTP status codes a redirect may use. 410 means "Gone" (no destination).
	 */
	public const STATUS_CODES = [ 301, 302, 307, 410 ];

	/**
	 * Returns the fully-prefixed redirects table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'ai_seo_assistant_redirects';
	}

	/**
	 * Creates or updates the redirects table via dbDelta.
	 *
	 * Safe to call repeatedly. Runs on plugin activation and on demand through
	 * maybe_install() for sites that were already active when this shipped.
	 */
	public static function install(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_path varchar(255) NOT NULL,
			target text NOT NULL,
			status_code smallint(5) unsigned NOT NULL DEFAULT 301,
			is_enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY source_path (source_path)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Installs the table when the stored schema version is missing or stale.
	 *
	 * Lets already-active installs pick up the table without a reactivation.
	 */
	public function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}

		/*
		 * The lookup map must EXIST, empty and autoloaded. Redirect_Handler reads it on every front-end
		 * request; until a redirect was first saved the option was absent, and an absent option is not in
		 * the autoloaded set, so every uncached page view paid a SELECT to learn it was still missing
		 * (performance review, Common Shamans M6). add_option() is a no-op once it exists.
		 */
		add_option( self::MAP_OPTION, [], '', true );
	}

	// -------------------------------------------------------------------------
	// Reads
	// -------------------------------------------------------------------------

	/**
	 * Returns every redirect row, newest first.
	 *
	 * The redirect list is an admin-only dataset expected to stay small, so it
	 * is fetched in full without pagination.
	 *
	 * @return array[]
	 */
	public function all(): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal, no user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC", ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Returns a single redirect row by id, or null.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name interpolated, id is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Returns a redirect row matching a normalized source path, or null.
	 *
	 * @param string $source_path Normalized source path.
	 * @return array|null
	 */
	public function find_by_source( string $source_path ): ?array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name interpolated, value is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_path = %s", $source_path ), ARRAY_A );

		return $row ?: null;
	}

	// -------------------------------------------------------------------------
	// Writes
	// -------------------------------------------------------------------------

	/**
	 * Inserts a new redirect.
	 *
	 * @param array $data Raw form data (source_path, target, status_code, is_enabled).
	 * @return int|\WP_Error New row id, or an error describing why it was rejected.
	 */
	public function insert( array $data ) {
		global $wpdb;

		$clean = $this->prepare_row( $data );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$ok = $wpdb->insert( self::table_name(), $clean, [ '%s', '%s', '%d', '%d' ] );

		if ( false === $ok ) {
			return new \WP_Error( 'db_insert_failed', __( 'Could not save the redirect.', 'ai-seo-assistant' ) );
		}

		$this->rebuild_map();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates an existing redirect.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Raw form data.
	 * @return true|\WP_Error
	 */
	public function update( int $id, array $data ) {
		global $wpdb;

		$clean = $this->prepare_row( $data, $id );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$ok = $wpdb->update( self::table_name(), $clean, [ 'id' => $id ], [ '%s', '%s', '%d', '%d' ], [ '%d' ] );

		if ( false === $ok ) {
			return new \WP_Error( 'db_update_failed', __( 'Could not update the redirect.', 'ai-seo-assistant' ) );
		}

		$this->rebuild_map();

		return true;
	}

	/**
	 * Deletes a redirect.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$ok = $wpdb->delete( self::table_name(), [ 'id' => $id ], [ '%d' ] );

		$this->rebuild_map();

		return (bool) $ok;
	}

	/**
	 * Enables or disables a redirect without touching its other fields.
	 *
	 * @param int  $id      Row id.
	 * @param bool $enabled Whether the redirect should be active.
	 * @return bool
	 */
	public function toggle( int $id, bool $enabled ): bool {
		global $wpdb;

		$ok = $wpdb->update(
			self::table_name(),
			[ 'is_enabled' => $enabled ? 1 : 0 ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		$this->rebuild_map();

		return false !== $ok;
	}

	// -------------------------------------------------------------------------
	// Front-end lookup map
	// -------------------------------------------------------------------------

	/**
	 * Rebuilds the compact autoloaded lookup map from the enabled rows.
	 *
	 * Called after every write so the front-end handler never queries the table.
	 */
	public function rebuild_map(): void {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal, no user input.
		$rows = $wpdb->get_results( "SELECT source_path, target, status_code FROM {$table} WHERE is_enabled = 1", ARRAY_A );

		$map = [];

		foreach ( (array) $rows as $row ) {
			$map[ $row['source_path'] ] = [
				'target' => $row['target'],
				'code'   => (int) $row['status_code'],
			];
		}

		// Autoloaded: needed on every front-end request, kept intentionally small.
		update_option( self::MAP_OPTION, $map, true );
	}

	/**
	 * Returns the front-end lookup map.
	 *
	 * @return array<string, array{target: string, code: int}>
	 */
	public function get_map(): array {
		$map = get_option( self::MAP_OPTION, [] );

		return is_array( $map ) ? $map : [];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalizes a raw source value into a comparable request path.
	 *
	 * Accepts a full URL or a path, strips the query string/fragment, decodes it,
	 * forces a single leading slash with no trailing slash (except root), and
	 * lowercases it when case-insensitive matching is enabled. Used at both save
	 * time and request time so stored sources and incoming requests compare 1:1.
	 *
	 * @param string $raw Raw path or URL.
	 * @return string Normalized path, or '' when empty.
	 */
	public static function normalize_path( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		// If a full URL was supplied, keep only the path component.
		if ( preg_match( '#^https?://#i', $raw ) ) {
			$path = wp_parse_url( $raw, PHP_URL_PATH );
			$raw  = is_string( $path ) ? $path : '';
		}

		// Drop any query string or fragment.
		$raw = (string) preg_replace( '/[?#].*$/', '', $raw );

		$raw = urldecode( $raw );

		// Single leading slash, no trailing slash (except the site root).
		$raw = '/' . trim( $raw, '/' );

		if ( self::is_case_insensitive() ) {
			$raw = strtolower( $raw );
		}

		return $raw;
	}

	/**
	 * Whether source/request paths are compared case-insensitively.
	 *
	 * @return bool
	 */
	public static function is_case_insensitive(): bool {
		return (bool) apply_filters( 'ai_seo_assistant_redirects_case_insensitive', true );
	}

	/**
	 * Validates and shapes raw form data into a storable row.
	 *
	 * @param array $data      Raw form data.
	 * @param int   $ignore_id Row id to ignore during the duplicate-source check (for updates).
	 * @return array|\WP_Error Column-keyed row, or an error explaining the rejection.
	 */
	private function prepare_row( array $data, int $ignore_id = 0 ) {
		$source  = self::normalize_path( (string) ( $data['source_path'] ?? '' ) );
		$status  = (int) ( $data['status_code'] ?? 301 );
		$enabled = ! empty( $data['is_enabled'] );

		if ( '' === $source || '/' === $source ) {
			return new \WP_Error( 'invalid_source', __( 'Enter a valid source path, for example /old-page/.', 'ai-seo-assistant' ) );
		}

		if ( ! in_array( $status, self::STATUS_CODES, true ) ) {
			$status = 301;
		}

		// 410 Gone needs no destination; all other codes require one.
		$target = 410 === $status ? '' : esc_url_raw( trim( (string) ( $data['target'] ?? '' ) ) );

		if ( 410 !== $status && '' === $target ) {
			return new \WP_Error( 'invalid_target', __( 'Enter a destination URL for this redirect.', 'ai-seo-assistant' ) );
		}

		// A rule that points a path at itself would loop.
		if ( 410 !== $status && self::normalize_path( $target ) === $source ) {
			return new \WP_Error( 'self_redirect', __( 'The source and destination resolve to the same path.', 'ai-seo-assistant' ) );
		}

		// Source paths are unique in the table.
		$existing = $this->find_by_source( $source );

		if ( $existing && (int) $existing['id'] !== $ignore_id ) {
			return new \WP_Error( 'duplicate_source', __( 'A redirect for that source path already exists.', 'ai-seo-assistant' ) );
		}

		return [
			'source_path' => $source,
			'target'      => $target,
			'status_code' => $status,
			'is_enabled'  => $enabled ? 1 : 0,
		];
	}
}
