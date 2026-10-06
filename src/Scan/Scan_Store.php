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
	 * @param string              $flags     Page_Role::flags() of the content.
	 */
	public function save_facts( int $post_id, string $path, string $post_type, string $source, array $facts, string $flags = '' ): void {
		global $wpdb;
		Schema::ensure(); // The tables are current before any write (an update that skipped install()).
		$table = Schema::table( 'scan' );
		// The page's visible text has its own column: the site-wide pass loads every row's facts, never this.
		$text = mb_substr( (string) ( $facts[ Html_Parser::TEXT ] ?? '' ), 0, Html_Parser::MAX_TEXT );
		unset( $facts[ Html_Parser::TEXT ] );
		$values = [ $post_id, mb_substr( $path, 0, 190 ), $post_type, gmdate( 'Y-m-d H:i:s' ), $source, substr( $flags, 0, 32 ), (string) wp_json_encode( $facts ) ];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$ok = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values are spread from one array.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table name (Schema::table()).
				"INSERT INTO `{$table}` (post_id, path, post_type, scanned_at, source, flags, facts, body_text, issues) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, '[]')
				ON DUPLICATE KEY UPDATE path = VALUES(path), post_type = VALUES(post_type), scanned_at = VALUES(scanned_at), source = VALUES(source), flags = VALUES(flags), facts = VALUES(facts), body_text = VALUES(body_text)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...array_merge( $values, [ $text ] )
			)
		);
		if ( false !== $ok ) {
			return;
		}
		// The write failed (most likely the table update that adds body_text has not run yet): logged, and the
		// facts saved without the text, so a failed ALTER never stops every scan write.
		if ( function_exists( 'error_log' ) ) {
			error_log( 'AI SEO Assistant: saving a page scan with its text failed (' . (string) ( $wpdb->last_error ?? '' ) . '); saved without the text.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a failed write the agency must be able to find.
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values are spread from one array.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table name (Schema::table()).
				"INSERT INTO `{$table}` (post_id, path, post_type, scanned_at, source, flags, facts, issues) VALUES (%d, %s, %s, %s, %s, %s, %s, '[]')
				ON DUPLICATE KEY UPDATE path = VALUES(path), post_type = VALUES(post_type), scanned_at = VALUES(scanned_at), source = VALUES(source), flags = VALUES(flags), facts = VALUES(facts)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$values
			)
		);
	}

	/**
	 * Save the links to a page from the other scanned pages (the site-wide pass, when they changed).
	 *
	 * @param int                                 $post_id Post ID.
	 * @param array<int,array{0:string,1:string}> $inbound [ from path, link text ] each.
	 */
	public function save_inbound( int $post_id, array $inbound ): void {
		global $wpdb;
		Schema::ensure(); // The tables are current before any write (an update that skipped install()).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update(
			Schema::table( 'scan' ),
			[
				'inbound'      => (string) wp_json_encode( $inbound ),
				'inbound_hash' => self::inbound_hash( $inbound ),
			],
			[ 'post_id' => $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * A short hash of an inbound list (scan.inbound_hash): what the site-wide pass compares.
	 *
	 * @param array<int,array{0:string,1:string}> $inbound The list.
	 */
	public static function inbound_hash( array $inbound ): string {
		return substr( md5( (string) wp_json_encode( $inbound ) ), 0, 12 );
	}

	/**
	 * When a page was last scanned (GMT), or null: one column, for the editor's staleness check.
	 *
	 * @param int $post_id Post ID.
	 */
	public function scanned_at( int $post_id ): ?string {
		global $wpdb;
		$table = Schema::table( 'scan' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$at = $wpdb->get_var( $wpdb->prepare( "SELECT scanned_at FROM `{$table}` WHERE post_id = %d", $post_id ) );

		return null === $at ? null : (string) $at;
	}

	/**
	 * Save a page's issues.
	 *
	 * @param int                            $post_id Post ID.
	 * @param array<int,array<string,mixed>> $issues  Issues.
	 */
	public function save_issues( int $post_id, array $issues ): void {
		global $wpdb;
		Schema::ensure(); // The tables are current before any write (an update that skipped install()).
		$kinds = [];
		foreach ( $issues as $issue ) {
			$kinds[ $issue['kind'] ] = ( $kinds[ $issue['kind'] ] ?? 0 ) + 1;
		}
		// How many of them the page review can fix (the rest are "do in the editor"), for the list's wording.
		$kinds['_claude'] = count( array_filter( $issues, static fn( $i ) => 'claude' === ( $i['who'] ?? '' ) ) );
		// Marks a "Page type not set" finding, so the list can drop it the moment a type is set elsewhere.
		if ( in_array( 'page_type_unset', array_column( $issues, 'code' ), true ) ) {
			$kinds['_ptype'] = 1;
		}
		$csv = implode( ',', array_map( static fn( $k, $n ) => $k . ':' . $n, array_keys( $kinds ), $kinds ) );
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
	 * A page type was set or changed outside a scan (AJR Core's ajr_core_page_type_changed): drop the stored
	 * "Page type not set" finding at once. A cleared type waits for the next scan to judge the page again.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    The new type ('' cleared).
	 */
	public function type_changed( int $post_id, string $type ): void {
		if ( '' === $type ) {
			return;
		}
		$row = $this->get( $post_id ); // get() already leaves the finding out once a type is set.
		if ( null !== $row ) {
			$this->save_issues( $post_id, (array) $row['issues'] );
		}
	}

	/**
	 * Save (or clear, with null) Claude's suggestions for a page.
	 *
	 * @param int                      $post_id     Post ID.
	 * @param array<string,mixed>|null $suggestions Suggestions.
	 */
	public function save_suggestions( int $post_id, ?array $suggestions ): void {
		global $wpdb;
		Schema::ensure(); // The tables are current before any write (an update that skipped install()).
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
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row = self::decode( $row );
		// The page type may have been set since the scan (AJR Core's box, another screen): the stored
		// "not set" finding never contradicts the type the review shows.
		if ( '' !== Page_Role::type_of( $post_id ) ) {
			$row['issues'] = array_values( array_filter( (array) $row['issues'], static fn( $i ) => 'page_type_unset' !== ( $i['code'] ?? '' ) ) );
		}

		return $row;
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
		$rows = (array) $wpdb->get_results( "SELECT post_id, path, post_type, scanned_at, source, flags, issue_count, issue_kinds, suggested_at, (suggestions IS NOT NULL) AS has_suggestions FROM `{$table}`", ARRAY_A );
		$out  = [];
		foreach ( $rows as $row ) {
			$kinds = [];
			foreach ( array_filter( explode( ',', (string) $row['issue_kinds'] ) ) as $pair ) {
				[ $k, $n ]   = array_pad( explode( ':', $pair ), 2, '0' );
				$kinds[ $k ] = (int) $n;
			}
			$row['claude_fixable'] = (int) ( $kinds['_claude'] ?? $row['issue_count'] );
			$stale_type            = isset( $kinds['_ptype'] ) && '' !== Page_Role::type_of( (int) $row['post_id'] );
			unset( $kinds['_claude'], $kinds['_ptype'] );
			if ( $stale_type ) {
				// "Page type not set", but a type was set since the scan: not counted.
				$kinds['schema']    = max( 0, (int) ( $kinds['schema'] ?? 0 ) - 1 );
				$row['issue_count'] = max( 0, (int) $row['issue_count'] - 1 );
				if ( 0 === $kinds['schema'] ) {
					unset( $kinds['schema'] );
				}
			}
			$row['kinds']                 = $kinds;
			$row['post_id']               = (int) $row['post_id'];
			$row['issue_count']           = (int) $row['issue_count'];
			$row['has_suggestions']       = (bool) $row['has_suggestions'];
			$out[ (int) $row['post_id'] ] = $row;
		}

		return $out;
	}

	/**
	 * Every row's facts (for the site-wide pass), keyed by post ID, in post ID order.
	 *
	 * @param bool $inbound With each row's inbound_hash (the site-wide pass only; null when the column is
	 *                      not there yet).
	 * @return array<int,array<string,mixed>>
	 */
	public function all_facts( bool $inbound = false ): array {
		global $wpdb;
		$table = Schema::table( 'scan' );
		// The stored inbound links only once the column exists (a failed table update must not empty the pass).
		$inbound = $inbound && Schema::is_current() ? ', inbound_hash' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the scan's site-wide pass.
		$rows = (array) $wpdb->get_results( "SELECT post_id, path, post_type, scanned_at, source, flags, facts, issues{$inbound} FROM `{$table}` ORDER BY post_id", ARRAY_A );
		$out  = [];
		foreach ( $rows as $row ) {
			$facts                        = json_decode( (string) $row['facts'], true );
			$out[ (int) $row['post_id'] ] = [
				'path'         => (string) $row['path'],
				'post_type'    => (string) $row['post_type'],
				'scanned_at'   => (string) $row['scanned_at'],
				'source'       => (string) $row['source'],
				'facts'        => is_array( $facts ) ? $facts : [],
				'issues_json'  => (string) ( $row['issues'] ?? '' ), // As stored, so an unchanged page is not rewritten.
				'flags'        => (string) ( $row['flags'] ?? '' ),
				'inbound_hash' => array_key_exists( 'inbound_hash', $row ) ? (string) $row['inbound_hash'] : null, // As stored ('' never built); null: not asked for, or no column yet.
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

		return array_combine( array_map( 'intval', array_column( $rows, 'post_id' ) ), array_column( $rows, 'scanned_at' ) ); // PHP 8: always an array.
	}

	/**
	 * Delete rows for pages no longer published.
	 *
	 * @param array<int,int> $keep Post IDs to keep.
	 */
	public function prune( array $keep ): void {
		global $wpdb;
		Schema::ensure(); // The tables are current before any write (an update that skipped install()).
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
		Schema::ensure(); // The tables are current before any write (an update that skipped install()).
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
			$value       = null === ( $row[ $col ] ?? null ) ? null : json_decode( (string) $row[ $col ], true );
			$row[ $col ] = is_array( $value ) ? $value : ( 'suggestions' === $col ? null : [] );
		}
		$row['post_id']     = (int) $row['post_id'];
		$row['issue_count'] = (int) $row['issue_count'];
		$row['body_text']   = (string) ( $row['body_text'] ?? '' );
		$inbound            = isset( $row['inbound'] ) ? json_decode( (string) $row['inbound'], true ) : null;
		$row['inbound']     = is_array( $inbound ) ? $inbound : null; // Null: not built yet (the next site-wide pass builds it).

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
