<?php
/**
 * Ranking — the SEO scan's page list: scan rows joined to their search data and opportunity score.
 *
 * Shared by the SEO scan screen and the page review header ("Opportunity 64, 3rd of 38 pages"). The formula
 * is Opportunity's; this class feeds it: each page's searches (per-search missed clicks), the site's own CTR
 * curve (cached per push), the page's role and whether its enquiry rate earns a step up.
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

	/** Option: the site's own CTR curve, keyed to the push it came from. Not autoloaded. */
	public const CURVE_OPTION = 'ai_seo_assistant_ctr_curve';

	/**
	 * Per-request memo of rows().
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	protected static ?array $rows = null;

	/**
	 * The expected-CTR curve for the stored push, put in force for Opportunity. Calibrated from every page's
	 * top searches once per push (cached in CURVE_OPTION, keyed by the window's end and push time).
	 *
	 * @return array{curve:array<int,float>,site:bool,searches:int,buckets:int}
	 */
	public static function curve(): array {
		$meta = Page_Data::meta();
		$key  = $meta['end'] . '|' . $meta['generated_at'];
		$held = get_option( self::CURVE_OPTION, [] );
		if ( is_array( $held ) && ( $held['key'] ?? '' ) === $key && is_array( $held['curve'] ?? null ) ) {
			$info = $held['info'];
		} else {
			$queries = [];
			foreach ( ( new Page_Data() )->all() as $page ) {
				foreach ( (array) ( $page['gsc']['queries'] ?? [] ) as $q ) {
					$queries[] = $q;
				}
			}
			$info = Opportunity::site_curve( $queries );
			update_option(
				self::CURVE_OPTION,
				[
					'key'   => $key,
					'curve' => $info['curve'],
					'info'  => $info,
				],
				false
			);
		}
		$info['curve'] = array_map( 'floatval', (array) $info['curve'] );
		Opportunity::use_curve( $info['site'] ? $info['curve'] : null );

		return $info;
	}

	/**
	 * Every scanned page, ranked: opportunity first, then impressions and issue count.
	 *
	 * @return array<int,array<string,mixed>> Keyed by post ID, in rank order.
	 */
	public static function rows(): array {
		if ( null !== self::$rows ) {
			return self::$rows;
		}
		$scan = ( new Scan_Store() )->summaries();
		if ( [] === $scan ) {
			return [];
		}
		self::curve();
		$data   = ( new Page_Data() )->all();
		$titles = self::titles( array_keys( $scan ) );
		$latest = self::latest_applies();
		$roles  = Page_Role::for_posts( array_map( static fn( $r ) => (string) $r['post_type'], $scan ) );

		$site_enq    = 0;
		$site_visits = 0;
		foreach ( $data as $page ) {
			$site_enq    += (int) ( $page['ga4']['enquiries'] ?? 0 );
			$site_visits += (int) ( $page['ga4']['visits'] ?? 0 );
		}

		$rows     = [];
		$weighted = [];
		foreach ( $scan as $id => $row ) {
			$page   = self::page( $data, (string) $row['path'] );
			$gsc    = is_array( $page['gsc'] ?? null ) ? $page['gsc'] : null;
			$shown  = (int) ( $gsc['impressions'] ?? 0 );
			$pos    = (float) ( $gsc['position'] ?? 0 );
			$ctr    = null === $gsc ? null : ( null !== ( $gsc['ctr'] ?? null ) ? (float) $gsc['ctr'] : ( $shown > 0 ? round( $gsc['clicks'] / $shown * 100, 2 ) : 0.0 ) );
			$enq    = (int) ( $page['ga4']['enquiries'] ?? 0 );
			$visits = (int) ( $page['ga4']['visits'] ?? 0 );
			$split  = Opportunity::breakdown( $gsc );
			$role   = $roles[ $id ] ?? [
				'role' => 'unclassified',
				'set'  => false,
			];
			$bumped = Opportunity::earns_bump( $enq, $visits, $site_enq, $site_visits );
			$value  = Opportunity::value( $role['role'], $bumped );

			$weighted[ $id ] = Opportunity::weighted( $split['missed'], $enq, $value );
			$rows[ $id ]     = $row + [
				'title'       => $titles[ $id ] ?? $row['path'],
				'impressions' => $shown,
				'clicks'      => (int) ( $gsc['clicks'] ?? 0 ),
				'position'    => $pos,
				'ctr'         => $ctr,
				'expected'    => $pos > 0 ? Opportunity::expected_ctr( $pos ) : null,
				'enquiries'   => $enq,
				'missed'      => $split['missed'],
				'method'      => $split['method'],
				'breakdown'   => $split['rows'],
				'role'        => $role['role'],
				'role_set'    => $role['set'],
				'bumped'      => $bumped,
				'value'       => $value,
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
		self::$rows = $rows;

		return $rows;
	}

	/**
	 * Forget the memo (after a role change in the same request).
	 */
	public static function flush(): void {
		self::$rows = null;
	}

	/**
	 * One path's pushed data (trailing slash ignored).
	 *
	 * @param array<string,array<string,mixed>> $data All pages.
	 * @param string                            $path Path.
	 * @return array<string,mixed>|null
	 */
	protected static function page( array $data, string $path ): ?array {
		return $data[ $path ] ?? $data[ trailingslashit( $path ) ] ?? $data[ untrailingslashit( $path ) ] ?? null;
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
