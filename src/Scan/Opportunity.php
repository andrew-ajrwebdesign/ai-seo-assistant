<?php
/**
 * Opportunity — how many extra clicks a page could win, as the SEO scan's ranking.
 *
 * THE FORMULA (decision 2026-10-06, step 3; per-query, site curve and business value 2026-10-06 later):
 *
 *   missed clicks = Σ over the page's top searches of
 *                     impressions × max( 0, expected CTR at that search's position − its CTR ) × reach
 *                   + the REMAINDER (page impressions and clicks minus the top searches' own) at the page's
 *                     average position, the same way
 *   weighted      = missed clicks × value of the page's role × ( 1 + ln( 1 + enquiries started on the page ) )
 *   score         = 100 × weighted ÷ the site's largest weighted value      (0–100, rounded)
 *
 * WHY PER SEARCH. A page's average position mixes searches it wins (position 2, clicked) with searches it is
 * barely shown for (position 40, never clicked): averaged, those look like one search at position 9 that
 * nobody clicks, which overstates what a better title could win. Counted per search, a search at position 40
 * adds nothing, because a listing on page 3 is not clicked whatever its title says. `reach` makes that
 * explicit: 1 up to position 20, falling in a straight line to 0 at position 30. Search Console hides rare
 * searches and the push carries the top ten, so the rest of the page's impressions (the remainder) count
 * at the page's average position. A page pushed without searches (snapshot v1, or a page Google showed for
 * none it would name) falls back to the page-level formula: impressions × (expected − actual CTR).
 *
 * The missed clicks are also what the scan shows: "≈ 40 extra clicks / 90 days" beside the score.
 *
 * VALUE. The page's role (Page_Role): money pages (services, booking, contact, home value, landing) 1.5,
 * location / area pages 1.2, unclassified 1.0, information (blog, news, guides) 0.6. A click on a booking page
 * is worth more than a click on a weather guide. The enquiry weight lifts pages that already turn visitors
 * into enquiries; the log keeps one enquiry from doubling a page (×1.7) and ten from swamping the clicks
 * term (×3.4).
 *
 * EXPECTED CTR CURVE. Built in (CURVE): click-through rate by average position, blended desktop and mobile,
 * for a results page with the usual ads and map pack above the organic results. It sits under the headline
 * "position 1 gets 28%" studies (Advanced Web Ranking's 2024–26 CTR curves, Backlinko 2023) from position 3
 * down, because a local service search shows a map pack and ads first; positions 1–2 follow the studies.
 * When the push carries enough of the site's own searches, site_curve() replaces it bucket by bucket with
 * the site's real CTR (a bucket needs 1,000 impressions, else the built-in value stays), smoothed so the
 * curve never rises as position falls. Points between positions are interpolated in a straight line; past
 * 50 it is the last point. The `ai_seo_assistant_expected_ctr` filter still has the last word.
 *
 * Pure PHP, unit-tested.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Expected CTR and opportunity score.
 */
class Opportunity {

	/**
	 * Built-in expected CTR in percent at an average position.
	 *
	 * @var array<int,float>
	 */
	public const CURVE = [
		1  => 28.0,
		2  => 15.0,
		3  => 7.2,
		4  => 6.3,
		5  => 5.3,
		6  => 4.1,
		7  => 3.4,
		8  => 2.8,
		9  => 2.3,
		10 => 1.8,
		11 => 1.3,
		12 => 1.15,
		15 => 0.88,
		20 => 0.6,
		30 => 0.3,
		50 => 0.1,
	];

	/**
	 * Site-curve buckets: [ first position, last position, the curve point it sets ]. Positions are rounded.
	 *
	 * @var array<int,array{0:int,1:int,2:int}>
	 */
	public const BUCKETS = [
		[ 1, 1, 1 ],
		[ 2, 2, 2 ],
		[ 3, 3, 3 ],
		[ 4, 4, 4 ],
		[ 5, 5, 5 ],
		[ 6, 6, 6 ],
		[ 7, 7, 7 ],
		[ 8, 8, 8 ],
		[ 9, 9, 9 ],
		[ 10, 10, 10 ],
		[ 11, 15, 13 ],
		[ 16, 20, 18 ],
		[ 21, 30, 25 ],
		[ 31, 50, 40 ],
	];

	/** Impressions a bucket needs before the site's own CTR replaces the built-in value. */
	public const MIN_BUCKET_IMPRESSIONS = 1000;

	/**
	 * What a click is worth by page role (Page_Role).
	 *
	 * @var array<string,float>
	 */
	public const ROLE_VALUE = [
		'money'        => 1.5,
		'location'     => 1.2,
		'unclassified' => 1.0,
		'info'         => 0.6,
	];

	/**
	 * Roles from least to most valuable (a high enquiry rate moves a page one step up).
	 *
	 * @var array<int,string>
	 */
	public const ROLE_ORDER = [ 'info', 'unclassified', 'location', 'money' ];

	/**
	 * The site's own curve when calibrated (use_curve()), else null for the built-in one.
	 *
	 * @var array<int,float>|null
	 */
	protected static ?array $base = null;

	/**
	 * Use a calibrated curve (site_curve()['curve']) in place of the built-in one; null restores it.
	 *
	 * @param array<int,float>|null $curve Position => percent.
	 */
	public static function use_curve( ?array $curve ): void {
		self::$base = null !== $curve && count( $curve ) >= 2 ? $curve : null;
	}

	/**
	 * Expected CTR (percent) at a position.
	 *
	 * @param float $position Average position (1 = top).
	 */
	public static function expected_ctr( float $position ): float {
		$curve = self::curve();
		$keys  = array_keys( $curve );
		if ( $position <= $keys[0] ) {
			return (float) $curve[ $keys[0] ];
		}
		$last = $keys[ count( $keys ) - 1 ];
		if ( $position >= $last ) {
			return (float) $curve[ $last ];
		}
		$counted = count( $keys );
		for ( $i = 1; $i < $counted; $i++ ) {
			$hi = $keys[ $i ];
			if ( $position <= $hi ) {
				$lo = $keys[ $i - 1 ];
				$t  = ( $position - $lo ) / ( $hi - $lo );

				return round( $curve[ $lo ] + ( $curve[ $hi ] - $curve[ $lo ] ) * $t, 2 );
			}
		}

		return (float) $curve[ $last ];
	}

	/**
	 * The curve in force: the site's own when calibrated, else the built-in one; the filter wins.
	 *
	 * @return array<int,float>
	 */
	protected static function curve(): array {
		$curve = self::$base ?? self::CURVE;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the expected CTR curve (position => percent) used to rank pages.
			 *
			 * @param array<int,float> $curve Curve (the site's own when calibrated from its searches).
			 */
			$filtered = apply_filters( 'ai_seo_assistant_expected_ctr', $curve );
			if ( is_array( $filtered ) && count( $filtered ) >= 2 ) {
				ksort( $filtered );
				$curve = array_map( 'floatval', $filtered );
			}
		}

		return $curve;
	}

	/**
	 * The site's own expected CTR curve from its searches (every page's top searches in the push).
	 *
	 * Searches are bucketed by rounded position (1 to 10 each, then 11–15, 16–20, 21–30, 31–50); a bucket with
	 * at least MIN_BUCKET_IMPRESSIONS impressions sets its point to the impressions-weighted CTR (clicks ÷
	 * impressions), any other keeps the built-in value. The points are then smoothed so the curve never rises
	 * as position falls (weighted pool-adjacent-violators: a bucket out of order is averaged with its
	 * neighbour, by impressions). A built-in point weighs 1, so where it disagrees with the site's own figures
	 * it moves to them, never the other way round.
	 *
	 * @param array<int,array<string,mixed>> $queries Rows with impressions, clicks (or ctr) and position.
	 * @return array{curve:array<int,float>,site:bool,searches:int,buckets:int}
	 */
	public static function site_curve( array $queries ): array {
		$shown  = array_fill( 0, count( self::BUCKETS ), 0 );
		$clicks = array_fill( 0, count( self::BUCKETS ), 0.0 );
		foreach ( $queries as $q ) {
			$imp = (int) ( $q['impressions'] ?? 0 );
			$pos = isset( $q['position'] ) && is_numeric( $q['position'] ) ? (int) round( (float) $q['position'] ) : 0;
			if ( $imp <= 0 || $pos < 1 ) {
				continue;
			}
			foreach ( self::BUCKETS as $i => $bucket ) {
				if ( $pos >= $bucket[0] && $pos <= $bucket[1] ) {
					$shown[ $i ]  += $imp;
					$clicks[ $i ] += isset( $q['clicks'] ) && is_numeric( $q['clicks'] ) ? (float) $q['clicks'] : $imp * (float) ( $q['ctr'] ?? 0 ) / 100;
					break;
				}
			}
		}

		$values   = [];
		$weights  = [];
		$used     = 0;
		$searches = 0;
		foreach ( self::BUCKETS as $i => $bucket ) {
			if ( $shown[ $i ] >= self::MIN_BUCKET_IMPRESSIONS ) {
				$values[]  = 100 * $clicks[ $i ] / $shown[ $i ];
				$weights[] = (float) $shown[ $i ];
				++$used;
				$searches += $shown[ $i ];
			} else {
				$values[]  = self::interpolate( self::CURVE, (float) $bucket[2] );
				$weights[] = 1.0;
			}
		}
		if ( 0 === $used ) {
			return [
				'curve'    => self::CURVE,
				'site'     => false,
				'searches' => 0,
				'buckets'  => 0,
			];
		}
		$values = self::non_increasing( $values, $weights );
		$curve  = [];
		foreach ( self::BUCKETS as $i => $bucket ) {
			$curve[ $bucket[2] ] = round( $values[ $i ], 2 );
		}
		$curve[50] = min( self::CURVE[50], $curve[40] );

		return [
			'curve'    => $curve,
			'site'     => true,
			'searches' => $searches,
			'buckets'  => $used,
		];
	}

	/**
	 * Weighted isotonic regression (pool-adjacent-violators) to a non-increasing sequence.
	 *
	 * @param array<int,float> $values  Values in position order.
	 * @param array<int,float> $weights Weights.
	 * @return array<int,float>
	 */
	public static function non_increasing( array $values, array $weights ): array {
		$blocks = [];
		foreach ( $values as $i => $v ) {
			$blocks[] = [
				'v' => (float) $v,
				'w' => max( 1e-9, (float) ( $weights[ $i ] ?? 1 ) ),
				'n' => 1,
			];
			$last     = count( $blocks ) - 1;
			while ( $last > 0 && $blocks[ $last - 1 ]['v'] < $blocks[ $last ]['v'] ) {
				$a                   = $blocks[ $last - 1 ];
				$b                   = $blocks[ $last ];
				$w                   = $a['w'] + $b['w'];
				$blocks[ $last - 1 ] = [
					'v' => ( $a['v'] * $a['w'] + $b['v'] * $b['w'] ) / $w,
					'w' => $w,
					'n' => $a['n'] + $b['n'],
				];
				array_pop( $blocks );
				$last = count( $blocks ) - 1;
			}
		}
		$out = [];
		foreach ( $blocks as $block ) {
			for ( $k = 0; $k < $block['n']; $k++ ) {
				$out[] = $block['v'];
			}
		}

		return $out;
	}

	/**
	 * Straight-line value of a curve at a position.
	 *
	 * @param array<int,float> $curve    Curve.
	 * @param float            $position Position.
	 */
	protected static function interpolate( array $curve, float $position ): float {
		$keys    = array_keys( $curve );
		$value   = (float) $curve[ $keys[0] ];
		$counted = count( $keys );
		for ( $i = 1; $i < $counted; $i++ ) {
			if ( $position <= $keys[ $i ] ) {
				$lo    = $keys[ $i - 1 ];
				$hi    = $keys[ $i ];
				$value = $curve[ $lo ] + ( $curve[ $hi ] - $curve[ $lo ] ) * ( $position - $lo ) / ( $hi - $lo );
				break;
			}
			$value = (float) $curve[ $keys[ $i ] ];
		}
		return $value;
	}

	/**
	 * How much of a search's missed clicks a better listing could win: 1 to position 20, 0 from 30.
	 *
	 * @param float $position Position.
	 */
	public static function reach( float $position ): float {
		return max( 0.0, min( 1.0, ( 30 - $position ) / 10 ) );
	}

	/**
	 * Clicks the page missed over the window, page level: impressions × (expected − actual CTR), never below 0.
	 *
	 * @param int        $impressions Impressions.
	 * @param float|null $ctr         Actual CTR in percent (null: worked out from clicks).
	 * @param float      $position    Average position.
	 * @param int        $clicks      Clicks (used when $ctr is null).
	 */
	public static function missed_clicks( int $impressions, ?float $ctr, float $position, int $clicks = 0 ): float {
		if ( $impressions <= 0 || $position <= 0 ) {
			return 0.0;
		}
		$actual = null !== $ctr ? $ctr : $clicks / $impressions * 100;

		return max( 0.0, $impressions * ( self::expected_ctr( $position ) - $actual ) / 100 );
	}

	/**
	 * Where a page's missed clicks are: per search, plus the remainder at the page's average position.
	 *
	 * Without searches (v1 data) it is the page-level formula, method 'page'.
	 *
	 * @param array<string,mixed>|null $gsc Page Search Console block (Page_Data): impressions, clicks, ctr,
	 *                                      position, queries[] { query, impressions, clicks, ctr, position }.
	 * @return array{missed:float,method:string,rows:array<int,array<string,mixed>>}
	 */
	public static function breakdown( ?array $gsc ): array {
		$none = [
			'missed' => 0.0,
			'method' => 'none',
			'rows'   => [],
		];
		if ( null === $gsc ) {
			return $none;
		}
		$shown   = (int) ( $gsc['impressions'] ?? 0 );
		$clicks  = (int) ( $gsc['clicks'] ?? 0 );
		$pos     = (float) ( $gsc['position'] ?? 0 );
		$queries = array_values( array_filter( (array) ( $gsc['queries'] ?? [] ), static fn( $q ) => is_array( $q ) && (int) ( $q['impressions'] ?? 0 ) > 0 && is_numeric( $q['position'] ?? null ) ) );
		if ( [] === $queries ) {
			$ctr = isset( $gsc['ctr'] ) && is_numeric( $gsc['ctr'] ) ? (float) $gsc['ctr'] : null;

			return [
				'missed' => self::missed_clicks( $shown, $ctr, $pos, $clicks ),
				'method' => 'page',
				'rows'   => [],
			];
		}

		$rows     = [];
		$total    = 0.0;
		$q_shown  = 0;
		$q_clicks = 0;
		foreach ( $queries as $q ) {
			$imp       = (int) $q['impressions'];
			$clk       = (int) ( $q['clicks'] ?? 0 );
			$q_shown  += $imp;
			$q_clicks += $clk;
			$row       = self::row( (string) ( $q['query'] ?? '' ), $imp, $clk, (float) $q['position'] );
			$total    += $row['missed'];
			$rows[]    = $row;
		}
		$rest_shown = max( 0, $shown - $q_shown );
		if ( $rest_shown > 0 && $pos > 0 ) {
			$row           = self::row( '', $rest_shown, max( 0, min( $rest_shown, $clicks - $q_clicks ) ), $pos );
			$row['remain'] = true;
			$total        += $row['missed'];
			$rows[]        = $row;
		}
		usort( $rows, static fn( $a, $b ) => $b['missed'] <=> $a['missed'] );

		return [
			'missed' => $total,
			'method' => 'query',
			'rows'   => $rows,
		];
	}

	/**
	 * One search's (or the remainder's) missed clicks.
	 *
	 * @param string $query    Search ('' for the remainder).
	 * @param int    $shown    Impressions.
	 * @param int    $clicks   Clicks.
	 * @param float  $position Position.
	 * @return array<string,mixed>
	 */
	protected static function row( string $query, int $shown, int $clicks, float $position ): array {
		$ctr      = $shown > 0 ? 100 * $clicks / $shown : 0.0;
		$expected = self::expected_ctr( $position );

		return [
			'query'       => $query,
			'impressions' => $shown,
			'clicks'      => $clicks,
			'position'    => $position,
			'ctr'         => round( $ctr, 2 ),
			'expected'    => $expected,
			'missed'      => max( 0.0, $shown * ( $expected - $ctr ) / 100 ) * self::reach( $position ),
			'remain'      => false,
		];
	}

	/**
	 * The value of a page's clicks by role, moved one step up when its enquiry rate earns it.
	 *
	 * @param string $role   Role key (ROLE_VALUE).
	 * @param bool   $bumped True: one step up (enquiry rate at least twice the site's).
	 */
	public static function value( string $role, bool $bumped = false ): float {
		if ( ! isset( self::ROLE_VALUE[ $role ] ) ) {
			$role = 'unclassified';
		}
		if ( $bumped ) {
			$at   = (int) array_search( $role, self::ROLE_ORDER, true );
			$role = self::ROLE_ORDER[ min( count( self::ROLE_ORDER ) - 1, $at + 1 ) ];
		}

		return self::ROLE_VALUE[ $role ];
	}

	/**
	 * Whether a page's enquiry rate earns a step up: at least twice the site's, and only when the site had
	 * 10 or more enquiries in the window (fewer is noise).
	 *
	 * @param int $enquiries      Page enquiries.
	 * @param int $visits         Page visits.
	 * @param int $site_enquiries Site enquiries (all pages).
	 * @param int $site_visits    Site visits (all pages).
	 */
	public static function earns_bump( int $enquiries, int $visits, int $site_enquiries, int $site_visits ): bool {
		if ( $site_enquiries < 10 || $site_visits <= 0 || $visits <= 0 || $enquiries <= 0 ) {
			return false;
		}

		return $enquiries / $visits >= 2 * $site_enquiries / $site_visits;
	}

	/**
	 * The weighted value.
	 *
	 * @param float $missed    Missed clicks (breakdown()['missed']).
	 * @param int   $enquiries GA4 enquiries started on the page.
	 * @param float $value     value() of the page's role.
	 */
	public static function weighted( float $missed, int $enquiries, float $value = 1.0 ): float {
		return $missed * $value * ( 1 + log( 1 + max( 0, $enquiries ) ) );
	}

	/**
	 * Missed clicks rounded for display: null under 5 ("< 5"), whole under 20, then to 5, 10 and 100.
	 *
	 * @param float $missed Missed clicks.
	 */
	public static function rounded( float $missed ): ?int {
		if ( $missed < 5 ) {
			return null;
		}
		if ( $missed < 20 ) {
			return (int) round( $missed );
		}
		if ( $missed < 100 ) {
			return (int) ( 5 * round( $missed / 5 ) );
		}
		if ( $missed < 1000 ) {
			return (int) ( 10 * round( $missed / 10 ) );
		}

		return (int) ( 100 * round( $missed / 100 ) );
	}

	/**
	 * 0–100 scores for a set of weighted values (the largest is 100).
	 *
	 * @param array<int|string,float> $weighted Key => weighted value.
	 * @return array<int|string,int>
	 */
	public static function scores( array $weighted ): array {
		$max = [] === $weighted ? 0.0 : max( $weighted );
		$out = [];
		foreach ( $weighted as $key => $value ) {
			$out[ $key ] = $max > 0 ? (int) round( 100 * $value / $max ) : 0;
		}

		return $out;
	}
}
