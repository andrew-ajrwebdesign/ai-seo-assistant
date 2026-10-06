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

	/** User meta: the agency user's ranking mode on the SEO scan list (MODES). */
	public const MODE_META = 'aisa_rank_mode';

	/**
	 * Ranking modes: what pages are ranked and tiered by.
	 *
	 * The 'quick' mode ranks by the weighted quick win (a better listing at today's position; the default);
	 * 'prize' by the weighted top-3 prize (pages worth content and link work).
	 *
	 * @var array<int,string>
	 */
	public const MODES = [ 'quick', 'prize' ];

	/**
	 * Per-request memo of rows(), by mode.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	protected static array $rows = [];

	/**
	 * The current user's ranking mode ('quick' unless they chose 'prize').
	 */
	public static function mode(): string {
		$mode = function_exists( 'get_user_meta' ) ? (string) get_user_meta( get_current_user_id(), self::MODE_META, true ) : '';

		return in_array( $mode, self::MODES, true ) ? $mode : 'quick';
	}

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
	 * Every scanned page, ranked by opportunity value a year (the weighted quick win, or the weighted top-3
	 * prize in 'prize' mode), then impressions and issues; tiers scale with the site (Opportunity::tiers()).
	 *
	 * @param string $mode 'quick' | 'prize' ('' = the current user's mode()).
	 * @return array<int,array<string,mixed>> Keyed by post ID, in rank order.
	 */
	public static function rows( string $mode = '' ): array {
		$mode = in_array( $mode, self::MODES, true ) ? $mode : self::mode();
		if ( isset( self::$rows[ $mode ] ) ) {
			return self::$rows[ $mode ];
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
		$intent = static fn( string $q ): string => Intent::of( $q );

		$site_enq    = 0;
		$site_visits = 0;
		foreach ( $data as $page ) {
			$site_enq    += (int) ( $page['ga4']['enquiries'] ?? 0 );
			$site_visits += (int) ( $page['ga4']['visits'] ?? 0 );
		}

		$rows = [];
		foreach ( $scan as $id => $row ) {
			$page   = self::page( $data, (string) $row['path'] );
			$gsc    = is_array( $page['gsc'] ?? null ) ? $page['gsc'] : null;
			$shown  = (int) ( $gsc['impressions'] ?? 0 );
			$pos    = (float) ( $gsc['position'] ?? 0 );
			$ctr    = null === $gsc ? null : ( null !== ( $gsc['ctr'] ?? null ) ? (float) $gsc['ctr'] : ( $shown > 0 ? round( $gsc['clicks'] / $shown * 100, 2 ) : 0.0 ) );
			$enq    = (int) ( $page['ga4']['enquiries'] ?? 0 );
			$visits = (int) ( $page['ga4']['visits'] ?? 0 );
			$split  = Opportunity::breakdown( $gsc, $intent );
			$role   = $roles[ $id ] ?? [
				'role' => 'unclassified',
				'set'  => false,
				'type' => '',
			];
			$bumped = Opportunity::earns_bump( $enq, $visits, $site_enq, $site_visits );
			$value  = Opportunity::value( $role['role'], $bumped );
			$worth  = Opportunity::value_of( $split['weighted'], $value, $enq );
			$worth3 = Opportunity::value_of( $split['weighted_prize'], $value, $enq );
			$quick  = Opportunity::yearly( $split['missed'] );
			$prize  = Opportunity::yearly( $split['prize'] );

			$rows[ $id ] = $row + [
				'title'       => $titles[ $id ] ?? $row['path'],
				'impressions' => $shown,
				'clicks'      => (int) ( $gsc['clicks'] ?? 0 ),
				'position'    => $pos,
				'ctr'         => $ctr,
				'expected'    => $pos > 0 ? Opportunity::expected_ctr( $pos ) : null,
				'enquiries'   => $enq,
				'missed'      => $split['missed'],
				'quick_win'   => $quick,
				'prize'       => $prize,
				'quick_enq'   => Opportunity::enquiries( $quick, $enq, $visits, $site_enq, $site_visits ),
				'prize_enq'   => Opportunity::enquiries( $prize, $enq, $visits, $site_enq, $site_visits ),
				'mix'         => $split['mix'],
				'method'      => $split['method'],
				'breakdown'   => $split['rows'],
				'role'        => $role['role'],
				'role_set'    => $role['set'],
				'page_type'   => $role['type'],
				'bumped'      => $bumped,
				'value'       => $value,
				'worth_quick' => $worth,
				'worth_prize' => $worth3,
				'worth'       => 'prize' === $mode ? $worth3 : $worth,
				'mode'        => $mode,
				'seen'        => $shown > 0,
				'applied_at'  => $latest[ $id ] ?? '',
			];
		}
		$values = array_map( static fn( $r ) => $r['seen'] ? (float) $r['worth'] : 0.0, $rows );
		foreach ( Opportunity::tiers( $values ) as $id => $tier ) {
			$rows[ $id ]['tier'] = $tier;
		}
		$scores = Opportunity::scores( $values );
		foreach ( $scores as $id => $score ) {
			$rows[ $id ]['score'] = $score;
		}
		uasort( $rows, static fn( $a, $b ) => [ $b['worth'], $b['impressions'], $b['issue_count'] ] <=> [ $a['worth'], $a['impressions'], $a['issue_count'] ] );
		$rank = 0;
		foreach ( $rows as $id => $row ) {
			$rows[ $id ]['rank'] = ++$rank;
		}
		self::$rows[ $mode ] = $rows;

		return $rows;
	}

	/**
	 * Forget the memo (after a role change in the same request).
	 */
	public static function flush(): void {
		self::$rows = [];
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
