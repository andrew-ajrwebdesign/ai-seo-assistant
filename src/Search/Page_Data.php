<?php
/**
 * Page_Data — the pushed per-page Search Console and GA4 figures (snapshot v2), by URL path.
 *
 * WHY. The SEO scan ranks pages by the clicks they could win, the page review shows each page's real
 * searches, and Claude writes for those searches; all of that needs per-page data, which arrives with the
 * weekly push (the site never holds Google credentials: decision 2026-09-29). Each push replaces the whole
 * set, because it is a 90-day window, not an increment.
 *
 * Stored in {prefix}aisa_pages (Core\Schema), one JSON row per path, read only on the agency's screens and
 * by the scan's cron run. A small option records the window and when it arrived.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Search;

use AJR\SEOAssistant\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Per-page search data store.
 */
class Page_Data {

	/** Option: { start, end, generated_at, count } of the stored window. Not autoloaded. */
	public const META_OPTION = 'ai_seo_assistant_pages_meta';

	/**
	 * Per-request memo of all rows.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	protected static ?array $all = null;

	/**
	 * Replace every row with a push's pages.
	 *
	 * @param array<string,array<string,mixed>> $pages        Clean pages keyed by path (Snapshot_V2).
	 * @param array<string,string>              $range        { start, end } of the window.
	 * @param int                               $generated_at When retainer-scan built it.
	 */
	public function replace_all( array $pages, array $range, int $generated_at ): bool {
		global $wpdb;
		Schema::ensure(); // The tables are current before any write (an update that skipped install()).
		$table = Schema::table( 'pages' );
		$pages = self::keyed( $pages );

		// All or nothing: a failed insert (a lost connection, a full disk) rolls back to the last push's
		// rows instead of leaving the site with part of a push.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a transaction on the plugin's own table.
		$wpdb->query( 'START TRANSACTION' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; replaced whole on each push.
		$ok = false !== $wpdb->query( "DELETE FROM `{$table}`" );

		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $ok ? array_chunk( $pages, 100, true ) : [] as $chunk ) {
			$values = [];
			$args   = [];
			foreach ( $chunk as $path => $page ) {
				$values[] = '(%s, %s, %s)';
				$args[]   = (string) $path;
				$args[]   = (string) wp_json_encode( $page );
				$args[]   = $now;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, one triple per row.
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (path, data, updated_at) VALUES " . implode( ',', $values ), $args ) ) ) {
				$ok = false;
				break;
			}
		}
		if ( $ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$wpdb->query( 'COMMIT' );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$wpdb->query( 'ROLLBACK' );
		}
		if ( ! $ok ) {
			self::$all = null;
			return false; // The meta still describes the rows kept.
		}

		update_option(
			self::META_OPTION,
			[
				'start'        => (string) ( $range['start'] ?? '' ),
				'end'          => (string) ( $range['end'] ?? '' ),
				'generated_at' => $generated_at,
				'count'        => count( $pages ),
				'country'      => is_string( $range['queries_country'] ?? null ) ? (string) $range['queries_country'] : '',
			],
			false
		);
		self::$all = null;

		return true;
	}

	/**
	 * Pages keyed as stored: the path lower-cased (the column's collation ignores case, so "/About/" and
	 * "/about/" are one key: the one with more impressions wins), and no path over the column's 191
	 * characters (it could not be stored whole, and a cut one could match another page).
	 *
	 * @param array<string,array<string,mixed>> $pages Pages keyed by path.
	 * @return array<string,array<string,mixed>>
	 */
	public static function keyed( array $pages ): array {
		$out = [];
		foreach ( $pages as $path => $page ) {
			$key = strtolower( (string) $path );
			if ( '' === $key || strlen( $key ) > 191 ) {
				continue;
			}
			$weight = static fn( $p ) => (int) ( $p['gsc']['impressions'] ?? 0 );
			if ( ! isset( $out[ $key ] ) || $weight( $page ) > $weight( $out[ $key ] ) ) {
				$out[ $key ] = $page;
			}
		}

		return $out;
	}

	/**
	 * The stored window: { start, end, generated_at, count }, or empty values before the first v2 push.
	 *
	 * @return array{start:string,end:string,generated_at:int,count:int}
	 */
	public static function meta(): array {
		$meta = get_option( self::META_OPTION, [] );
		$meta = is_array( $meta ) ? $meta : [];

		return [
			'start'        => (string) ( $meta['start'] ?? '' ),
			'end'          => (string) ( $meta['end'] ?? '' ),
			'generated_at' => (int) ( $meta['generated_at'] ?? 0 ),
			'count'        => (int) ( $meta['count'] ?? 0 ),
			'country'      => (string) ( $meta['country'] ?? '' ), // The search lists' country ('' = all countries).
		];
	}

	/**
	 * Whether any per-page data has arrived.
	 */
	public static function has_data(): bool {
		return self::meta()['count'] > 0;
	}

	/**
	 * Every page's data, keyed by path.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		if ( null !== self::$all ) {
			return self::$all;
		}
		global $wpdb;
		self::$all = [];
		if ( ! self::has_data() ) {
			return self::$all;
		}
		$table = Schema::table( 'pages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table, read on agency screens and the scan's cron run; memoised per request.
		$rows = (array) $wpdb->get_results( "SELECT path, data FROM `{$table}`", ARRAY_A );
		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row['data'], true );
			if ( is_array( $data ) ) {
				self::$all[ (string) $row['path'] ] = $data;
			}
		}

		return self::$all;
	}

	/**
	 * One page's data by path or full URL, or null.
	 *
	 * Google reports the address it showed, which may differ from WordPress's by a trailing slash, so both
	 * spellings are tried.
	 *
	 * @param string $url_or_path Permalink or path.
	 * @return array<string,mixed>|null
	 */
	public function get( string $url_or_path ): ?array {
		$path = strtolower( self::path_of( $url_or_path ) ); // Stored lower-case: see keyed().
		$all  = $this->all();
		foreach ( array_unique( [ $path, trailingslashit( $path ), untrailingslashit( $path ) ] ) as $candidate ) {
			if ( '' !== $candidate && isset( $all[ $candidate ] ) ) {
				return $all[ $candidate ];
			}
		}

		return null;
	}

	/**
	 * The path part of a URL ("/water-heaters/"), "/" for the home page.
	 *
	 * @param string $url URL or path.
	 */
	public static function path_of( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' === $path ? '/' : $path;
	}

	/**
	 * Delete everything (uninstall / tests).
	 */
	public static function forget(): void {
		self::$all = null;
	}
}
