<?php
/**
 * Ranking — the SEO scan's page list: scan rows joined to their search data and opportunity score.
 *
 * Shared by the SEO scan screen and the page review header ("Opportunity 64, 3rd of 38 pages").
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

use AJR\SEOAssistant\Changes\Change_Log;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the ranked page list.
 */
class Ranking {

	/**
	 * Every scanned page, ranked: opportunity first, then issue count.
	 *
	 * @return array<int,array<string,mixed>> Keyed by post ID, in rank order.
	 */
	public static function rows(): array {
		$scan = ( new Scan_Store() )->summaries();
		if ( [] === $scan ) {
			return [];
		}
		$data   = ( new Page_Data() )->all();
		$titles = self::titles( array_keys( $scan ) );
		$latest = self::latest_applies();

		$rows     = [];
		$weighted = [];
		foreach ( $scan as $id => $row ) {
			$page  = $data[ $row['path'] ] ?? $data[ trailingslashit( $row['path'] ) ] ?? $data[ untrailingslashit( $row['path'] ) ] ?? null;
			$gsc   = $page['gsc'] ?? null;
			$shown = (int) ( $gsc['impressions'] ?? 0 );
			$pos   = (float) ( $gsc['position'] ?? 0 );
			$ctr   = null === $gsc ? null : ( null !== $gsc['ctr'] ? (float) $gsc['ctr'] : ( $shown > 0 ? round( $gsc['clicks'] / $shown * 100, 2 ) : 0.0 ) );
			$enq   = (int) ( $page['ga4']['enquiries'] ?? 0 );
			$miss  = null === $gsc ? 0.0 : Opportunity::missed_clicks( $shown, $ctr, $pos, (int) $gsc['clicks'] );

			$weighted[ $id ] = Opportunity::weighted( $miss, $enq );
			$rows[ $id ]     = $row + [
				'title'       => $titles[ $id ] ?? $row['path'],
				'impressions' => $shown,
				'clicks'      => (int) ( $gsc['clicks'] ?? 0 ),
				'position'    => $pos,
				'ctr'         => $ctr,
				'expected'    => $pos > 0 ? Opportunity::expected_ctr( $pos ) : null,
				'enquiries'   => $enq,
				'missed'      => $miss,
				'seen'        => $shown > 0,
				'applied_at'  => $latest[ $id ] ?? '',
			];
		}
		foreach ( Opportunity::scores( $weighted ) as $id => $score ) {
			$rows[ $id ]['score'] = $score;
		}
		uasort( $rows, static fn( $a, $b ) => [ $b['score'], $b['impressions'], $b['issue_count'] ] <=> [ $a['score'], $a['impressions'], $a['issue_count'] ] );
		$rank = 0;
		foreach ( $rows as $id => $row ) {
			$rows[ $id ]['rank'] = ++$rank;
		}

		return $rows;
	}

	/**
	 * Post titles by ID (one query).
	 *
	 * @param array<int,int> $ids Post IDs.
	 * @return array<int,string>
	 */
	protected static function titles( array $ids ): array {
		global $wpdb;
		$out = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only; titles for an agency screen.
			foreach ( (array) $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$in})", ARRAY_A ) as $row ) {
				$out[ (int) $row['ID'] ] = wp_specialchars_decode( (string) $row['post_title'], ENT_QUOTES );
			}
		}

		return $out;
	}

	/**
	 * When each page last had a change applied (not undone), GMT.
	 *
	 * @return array<int,string>
	 */
	protected static function latest_applies(): array {
		$out = [];
		foreach ( ( new Change_Log() )->find( [ 'limit' => 1000 ] ) as $change ) {
			if ( null === $change['undone_at'] && ! isset( $out[ $change['post_id'] ] ) ) {
				$out[ $change['post_id'] ] = (string) $change['applied_at'];
			}
		}

		return $out;
	}
}
