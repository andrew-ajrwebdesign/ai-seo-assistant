<?php
/**
 * Scan_Store — one row per published page in {prefix}aisa_scan: its facts, issues and Claude's suggestions.
 *
 * Written only by the scan (cron, or the agency's "Rescan now" requests) and by the page review; read only
 * on the agency's screens. Never touched on a visitor's request (see Core\Schema for why it is a table).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

use AJR\SEOAssistant\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes scan rows.
 */
class Scan_Store {

	/** Option: { finished_at, pages, issues, mode, rendered, fallback }. Not autoloaded. */
	public const META_OPTION = 'ai_seo_assistant_scan_meta';

	/**
	 * Save a page's facts (keeps its suggestions).
	 *
	 * @param int                 $post_id   Post ID.
	 * @param string              $path      URL path.
	 * @param string              $post_type Post type.
	 * @param string              $source    'rendered' | 'content'.
	 * @param array<string,mixed> $facts     Facts.
	 */
	public function save_facts( int $post_id, string $path, string $post_type, string $source, array $facts ): void {
		global $wpdb;
		$table = Schema::table( 'scan' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (post_id, path, post_type, scanned_at, source, facts, issues) VALUES (%d, %s, %s, %s, %s, %s, '[]')
				ON DUPLICATE KEY UPDATE path = VALUES(path), post_type = VALUES(post_type), scanned_at = VALUES(scanned_at), source = VALUES(source), facts = VALUES(facts)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				mb_substr( $path, 0, 190 ),
				$post_type,
				gmdate( 'Y-m-d H:i:s' ),
				$source,
				(string) wp_json_encode( $facts )
			)
		);
	}

	/**
	 * Save a page's issues.
	 *
	 * @param int                            $post_id Post ID.
	 * @param array<int,array<string,mixed>> $issues  Issues.
	 */
	public function save_issues( int $post_id, array $issues ): void {
		global $wpdb;
		$kinds = [];
		foreach ( $issues as $issue ) {
			$kinds[ $issue['kind'] ] = ( $kinds[ $issue['kind'] ] ?? 0 ) + 1;
		}
		// How many of them the page review can fix (the rest are "do in the editor"), for the list's wording.
		$kinds['_claude'] = count( array_filter( $issues, static fn( $i ) => 'claude' === ( $i['who'] ?? '' ) ) );
		$csv              = implode( ',', array_map( static fn( $k, $n ) => $k . ':' . $n, array_keys( $kinds ), $kinds ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update(
			Schema::table( 'scan' ),
			[
				'issues'      => (string) wp_json_encode( $issues ),
				'issue_count' => count( $issues ),
				'issue_kinds' => $csv,
			],
			[ 'post_id' => $post_id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Save (or clear, with null) Claude's suggestions for a page.
	 *
	 * @param int                      $post_id     Post ID.
	 * @param array<string,mixed>|null $suggestions Suggestions.
	 */
	public function save_suggestions( int $post_id, ?array $suggestions ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update(
			Schema::table( 'scan' ),
			[
				'suggestions'  => null === $suggestions ? null : (string) wp_json_encode( $suggestions ),
				'suggested_at' => null === $suggestions ? null : gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'post_id' => $post_id ]
		);
	}

	/**
	 * One row, decoded, or null.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function get( int $post_id ): ?array {
		global $wpdb;
		$table = Schema::table( 'scan' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id = %d", $post_id ), ARRAY_A );

		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Every row's list fields (no facts), keyed by post ID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function summaries(): array {
		global $wpdb;
		$table = Schema::table( 'scan' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; an agency screen.
		$rows = (array) $wpdb->get_results( "SELECT post_id, path, post_type, scanned_at, source, issue_count, issue_kinds, suggested_at, (suggestions IS NOT NULL) AS has_suggestions FROM `{$table}`", ARRAY_A );
		$out  = [];
		foreach ( $rows as $row ) {
			$kinds = [];
			foreach ( array_filter( explode( ',', (string) $row['issue_kinds'] ) ) as $pair ) {
				[ $k, $n ]   = array_pad( explode( ':', $pair ), 2, '0' );
				$kinds[ $k ] = (int) $n;
			}
			$row['claude_fixable'] = (int) ( $kinds['_claude'] ?? $row['issue_count'] );
			unset( $kinds['_claude'] );
			$row['kinds']                 = $kinds;
			$row['post_id']               = (int) $row['post_id'];
			$row['issue_count']           = (int) $row['issue_count'];
			$row['has_suggestions']       = (bool) $row['has_suggestions'];
			$out[ (int) $row['post_id'] ] = $row;
		}

		return $out;
	}

	/**
	 * Every row's facts (for the site-wide pass), keyed by post ID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_facts(): array {
		global $wpdb;
		$table = Schema::table( 'scan' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the scan's site-wide pass.
		$rows = (array) $wpdb->get_results( "SELECT post_id, path, post_type, scanned_at, source, facts FROM `{$table}`", ARRAY_A );
		$out  = [];
		foreach ( $rows as $row ) {
			$facts                        = json_decode( (string) $row['facts'], true );
			$out[ (int) $row['post_id'] ] = [
				'path'       => (string) $row['path'],
				'post_type'  => (string) $row['post_type'],
				'scanned_at' => (string) $row['scanned_at'],
				'source'     => (string) $row['source'],
				'facts'      => is_array( $facts ) ? $facts : [],
			];
		}

		return $out;
	}

	/**
	 * When each page was last scanned (GMT), keyed by post ID.
	 *
	 * @return array<int,string>
	 */
	public function scanned_times(): array {
		global $wpdb;
		$table = Schema::table( 'scan' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$rows = (array) $wpdb->get_results( "SELECT post_id, scanned_at FROM `{$table}`", ARRAY_A );

		return array_combine( array_map( 'intval', array_column( $rows, 'post_id' ) ), array_column( $rows, 'scanned_at' ) ) ?: [];
	}

	/**
	 * Delete rows for pages no longer published.
	 *
	 * @param array<int,int> $keep Post IDs to keep.
	 */
	public function prune( array $keep ): void {
		global $wpdb;
		$table = Schema::table( 'scan' );
		$have  = array_keys( $this->scanned_times() );
		$gone  = array_diff( $have, $keep );
		foreach ( array_chunk( $gone, 100 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$wpdb->query( "DELETE FROM `{$table}` WHERE post_id IN ({$in})" );
		}
	}

	/**
	 * Delete one page's row.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete( int $post_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->delete( Schema::table( 'scan' ), [ 'post_id' => $post_id ], [ '%d' ] );
	}

	/**
	 * Decode a row's JSON columns.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	protected static function decode( array $row ): array {
		foreach ( [ 'facts', 'issues', 'suggestions' ] as $col ) {
			$value       = null === $row[ $col ] ? null : json_decode( (string) $row[ $col ], true );
			$row[ $col ] = is_array( $value ) ? $value : ( 'suggestions' === $col ? null : [] );
		}
		$row['post_id']     = (int) $row['post_id'];
		$row['issue_count'] = (int) $row['issue_count'];

		return $row;
	}

	/**
	 * The last finished scan's summary.
	 *
	 * @return array<string,mixed>
	 */
	public static function meta(): array {
		$meta = get_option( self::META_OPTION, [] );

		return is_array( $meta ) ? $meta : [];
	}
}
