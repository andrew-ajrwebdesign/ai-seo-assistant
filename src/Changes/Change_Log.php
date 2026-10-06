<?php
/**
 * Change_Log — every field the plugin applied, with its before and after, who, when, and what it achieved.
 *
 * WHY (decision 2026-10-06, step 4): "every apply logged before/after; the report later shows the effect
 * (CTR before/after), which feeds the monthly view's 'what we did and what it achieved'". Undo restores
 * the "before" value. Rows live in {prefix}aisa_changes (Core\Schema).
 *
 * MEASUREMENT. A title or description changes how often the listing is clicked, so its effect is the
 * page's click-through rate in the 4 full weeks after the change against the 4 full weeks before it, from
 * the pushed weekly per-page figures (the week the change went live is left out: it is half of each).
 * Position is shown beside it so a ranking move is not mistaken for a better listing. A focus keyphrase is
 * a setting in the SEO plugin and alt text has no click-rate measure, so both are "Not measured". Once 4
 * weeks after are in, the result is frozen on the row, so a later 90-day window can never rewrite it.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Changes;

use AJR\SEOAssistant\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The change log.
 */
class Change_Log {

	/** Fields whose effect on clicks is measured. */
	public const MEASURED = [ 'title', 'description' ];

	/** Weeks compared on each side of a change. */
	public const WEEKS = 4;

	/** A CTR move smaller than this many percentage points is "about the same". */
	public const SAME_WITHIN = 0.1;

	/**
	 * Log one applied field.
	 *
	 * @param string $batch     Apply batch (one review's Apply).
	 * @param int    $post_id   Page.
	 * @param string $path      Page path.
	 * @param string $field     'title' | 'description' | 'keyphrase' | 'alt'.
	 * @param int    $object_id Post ID, or the attachment ID for alt text.
	 * @param string $before    Value before.
	 * @param string $after     Value after.
	 * @param int    $user_id   Who applied it.
	 * @return int Row ID.
	 */
	public function log( string $batch, int $post_id, string $path, string $field, int $object_id, string $before, string $after, int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$ok = $wpdb->insert(
			Schema::table( 'changes' ),
			[
				'batch'        => $batch,
				'post_id'      => $post_id,
				'path'         => mb_substr( $path, 0, 190 ),
				'field'        => $field,
				'object_id'    => $object_id,
				'before_value' => $before,
				'after_value'  => $after,
				'user_id'      => $user_id,
				'applied_at'   => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ]
		);

		// 0 when the row was not stored (too big for the column, a lost table): the caller must not make a
		// change it cannot undo.
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Remove a row that never took effect (its write was refused after it was logged).
	 *
	 * @param int $id Row ID.
	 */
	public function discard( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		return false !== $wpdb->delete( Schema::table( 'changes' ), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * One row, or null.
	 *
	 * @param int $id Row ID.
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Rows, newest first, optionally for one page, one batch or since a date.
	 *
	 * @param array<string,mixed> $where post_id, batch, since (Y-m-d H:i:s GMT), until, limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function find( array $where = [] ): array {
		global $wpdb;
		$table = Schema::table( 'changes' );
		// Lean by default: a content row's before/after is the whole page (often 100 KB+), so lists read it
		// as '' and only get(), Undo and the CSV export ('full') load it.
		$cols = ! empty( $where['full'] )
			? '*'
			: "id, batch, post_id, path, field, object_id, user_id, applied_at, undone_at, undone_by, effect, IF( field = 'content', '', before_value ) AS before_value, IF( field = 'content', '', after_value ) AS after_value";
		$sql  = "SELECT {$cols} FROM `{$table}` WHERE 1=1";
		$args = [];
		if ( ! empty( $where['post_id'] ) ) {
			$sql   .= ' AND post_id = %d';
			$args[] = (int) $where['post_id'];
		}
		if ( ! empty( $where['batch'] ) ) {
			$sql   .= ' AND batch = %s';
			$args[] = (string) $where['batch'];
		}
		if ( ! empty( $where['since'] ) ) {
			$sql   .= ' AND applied_at >= %s';
			$args[] = (string) $where['since'];
		}
		if ( ! empty( $where['until'] ) ) {
			$sql   .= ' AND applied_at < %s';
			$args[] = (string) $where['until'];
		}
		$sql   .= ' ORDER BY applied_at DESC, id DESC LIMIT %d';
		$args[] = max( 1, min( 1000, (int) ( $where['limit'] ?? 500 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- built from fixed fragments with placeholders.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return array_map( [ self::class, 'decode' ], $rows );
	}

	/**
	 * Mark a row undone.
	 *
	 * @param int $id      Row ID.
	 * @param int $user_id Who undid it.
	 */
	public function mark_undone( int $id, int $user_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$ok = $wpdb->update(
			Schema::table( 'changes' ),
			[
				'undone_at' => gmdate( 'Y-m-d H:i:s' ),
				'undone_by' => $user_id,
			],
			[ 'id' => $id ],
			[ '%s', '%d' ],
			[ '%d' ]
		);

		return false !== $ok;
	}

	/**
	 * Freeze a measured effect on a row.
	 *
	 * @param int                 $id     Row ID.
	 * @param array<string,mixed> $effect measure().
	 */
	public function save_effect( int $id, array $effect ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		return false !== $wpdb->update( Schema::table( 'changes' ), [ 'effect' => (string) wp_json_encode( $effect ) ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
	}

	/**
	 * The effect of a change: frozen when measured, else worked out now from the pushed weekly figures.
	 *
	 * @param array<string,mixed>      $row  A change row.
	 * @param array<string,mixed>|null $page The page's pushed data (Search\Page_Data), or null.
	 * @return array<string,mixed> state: 'measured' | 'waiting' | 'not_measured' | 'undone' | 'no_data'
	 */
	public function effect( array $row, ?array $page ): array {
		if ( null !== $row['undone_at'] ) {
			return [ 'state' => 'undone' ];
		}
		if ( ! in_array( $row['field'], self::MEASURED, true ) ) {
			return [ 'state' => 'not_measured' ];
		}
		if ( is_array( $row['effect'] ) && 'measured' === ( $row['effect']['state'] ?? '' ) ) {
			return $row['effect'];
		}
		$effect = self::measure( (int) strtotime( $row['applied_at'] . ' UTC' ), (array) ( $page['gsc']['weeks'] ?? [] ) );
		if ( 'measured' === $effect['state'] ) {
			$this->save_effect( (int) $row['id'], $effect );
		}

		return $effect;
	}

	/**
	 * CTR in the 4 full weeks before a change against the 4 full weeks after it.
	 *
	 * @param int                            $applied Unix time the change went live.
	 * @param array<int,array<string,mixed>> $weeks   Weekly points { start (Monday), clicks, impressions, position }.
	 * @return array<string,mixed>
	 */
	public static function measure( int $applied, array $weeks ): array {
		$before = [];
		$after  = [];
		foreach ( $weeks as $week ) {
			$start = strtotime( $week['start'] . ' 00:00:00 UTC' );
			if ( false === $start ) {
				continue;
			}
			$end = $start + 7 * DAY_IN_SECONDS;
			if ( $end <= $applied ) {
				$before[ $start ] = $week;
			} elseif ( $start >= $applied ) {
				$after[ $start ] = $week;
			}
		}
		ksort( $before );
		ksort( $after );
		$before   = array_slice( $before, -self::WEEKS );
		$after    = array_slice( $after, 0, self::WEEKS );
		$measured = count( $after ) >= self::WEEKS;
		$first    = gmdate( 'Y-m-d', $applied + self::WEEKS * WEEK_IN_SECONDS ); // "Measured from Tue 3 Nov".

		if ( [] === $before ) {
			return [
				'state'       => 'no_data',
				'weeks_after' => count( $after ),
				'from'        => $first,
			];
		}
		$b = self::totals( $before );
		$a = self::totals( $after );

		$out = [
			'state'       => $measured ? 'measured' : 'waiting',
			'weeks_after' => count( $after ),
			'from'        => $first,
			'ctr_before'  => $b['ctr'],
			'ctr_after'   => [] === $after ? null : $a['ctr'],
			'pos_before'  => $b['position'],
			'pos_after'   => [] === $after ? null : $a['position'],
			'shown_after' => $a['impressions'],
		];
		if ( null !== $out['ctr_after'] && null !== $out['ctr_before'] ) {
			$delta               = round( $out['ctr_after'] - $out['ctr_before'], 2 );
			$out['delta']        = $delta;
			$out['verdict']      = $delta >= self::SAME_WITHIN ? 'better' : ( $delta <= -self::SAME_WITHIN ? 'worse' : 'same' );
			$out['extra_clicks'] = (int) round( $delta / 100 * $a['impressions'] );
		}

		return $out;
	}

	/**
	 * Clicks, impressions, CTR (percent) and impression-weighted position of some weeks.
	 *
	 * @param array<int,array<string,mixed>> $weeks Weeks.
	 * @return array{clicks:int,impressions:int,ctr:float|null,position:float|null}
	 */
	protected static function totals( array $weeks ): array {
		$clicks = 0;
		$shown  = 0;
		$pos    = 0.0;
		foreach ( $weeks as $week ) {
			$clicks += (int) $week['clicks'];
			$shown  += (int) $week['impressions'];
			$pos    += (float) ( $week['position'] ?? 0 ) * (int) $week['impressions'];
		}

		return [
			'clicks'      => $clicks,
			'impressions' => $shown,
			'ctr'         => $shown > 0 ? round( $clicks / $shown * 100, 2 ) : null,
			'position'    => $shown > 0 ? round( $pos / $shown, 1 ) : null,
		];
	}

	/**
	 * Decode a row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public static function decode( array $row ): array {
		foreach ( [ 'id', 'post_id', 'object_id', 'user_id', 'undone_by' ] as $int ) {
			$row[ $int ] = (int) ( $row[ $int ] ?? 0 );
		}
		$effect        = null === ( $row['effect'] ?? null ) ? null : json_decode( (string) $row['effect'], true );
		$row['effect'] = is_array( $effect ) ? $effect : null;

		return $row;
	}
}
