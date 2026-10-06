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
 *   tier          = High: the top pages holding half the site's opportunity; Medium: the next quarter
 *                   (floors: High from 24, Medium from 8 weighted visits a year; see tiers())
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
 * the site's real CTR (a bucket needs 1,000 impressions, else the built-in value is scaled by the site's
 * own ÷ built-in ratio at the nearest calibrated bucket), smoothed so the
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

	/**
	 * Tier floors: weighted extra visits a year a page needs to be High (about two a month) or Medium (one
	 * every six weeks or so), whatever its share of the site's opportunity (tiers()).
	 *
	 * @var array{high:float,medium:float}
	 */
	public const FLOORS = [
		'high'   => 24.0,
		'medium' => 8.0,
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

	/** Weight of a search Google answers on the results page itself (filter ai_seo_assistant_zero_click_weight). */
	public const ZERO_CLICK_WEIGHT = 0.1;

	/**
	 * Words of searches Google often answers itself (weather, time, distances, codes). A second signal only:
	 * a search is zero-click by these words alone never, only when it also under-clicks.
	 */
	public const ZERO_CLICK_WORDS = [ 'weather', 'forecast', 'temperature', 'time in', 'distance', 'how far', 'population', 'zip code', 'area code', 'sunrise', 'sunset' ];

	/**
	 * Whether Google answers this search on the results page itself, so its clicks can never be won
	 * (weather, time, conversions, definitions, scores). Decided from the clicks, against the BUILT-IN curve
	 * (the site's own could be dragged down by these very searches):
	 *   - near the top (position ≤ 5), seen enough (≥ 300 impressions in 90 days) and clicked under a sixth
	 *     of what that position earns; or
	 *   - its words say so (ZERO_CLICK_WORDS) and it still under-clicks: on page 1, ≥ 100 impressions, under
	 *     a third of what the position earns. Never by the words alone.
	 *
	 * @param string $query    Search.
	 * @param int    $shown    Impressions (90 days).
	 * @param int    $clicks   Clicks.
	 * @param float  $position Position.
	 */
	public static function zero_click( string $query, int $shown, int $clicks, float $position ): bool {
		if ( $shown <= 0 || $position < 1 ) {
			return false;
		}
		$ctr      = 100 * $clicks / $shown;
		$expected = self::interpolate( self::CURVE, $position );
		if ( $position <= 5 && $shown >= 300 && $ctr < $expected / 6 ) {
			return true;
		}
		$q = ' ' . strtolower( $query ) . ' ';
		foreach ( self::ZERO_CLICK_WORDS as $word ) {
			if ( false !== strpos( $q, ' ' . $word . ' ' ) ) {
				return $position <= 10 && $shown >= 100 && $ctr < $expected / 3;
			}
		}

		return false;
	}

	/**
	 * The zero-click weight (filterable, default 0.1).
	 */
	public static function zero_click_weight(): float {
		$w = self::ZERO_CLICK_WEIGHT;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the weight of a search Google answers itself (few clicks possible).
			 *
			 * @param float $w Weight, 0–1.
			 */
			$w = (float) apply_filters( 'ai_seo_assistant_zero_click_weight', $w );
		}

		return max( 0.0, min( 1.0, $w ) );
	}

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
	 * impressions). Any other bucket takes the built-in value scaled by own ÷ built-in at the nearest
	 * calibrated bucket (decision 2026-10-06: a site whose position 2 gets 0.9%, not 15%, does not get 28% at
	 * position 1 either). The points are then smoothed so the curve never rises as position falls (weighted
	 * pool-adjacent-violators: a bucket out of order is averaged with its neighbour, by impressions). A scaled
	 * point weighs 1, so where it disagrees with the site's own figures it moves to them.
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

		$own      = [];
		$searches = 0;
		foreach ( self::BUCKETS as $i => $bucket ) {
			if ( $shown[ $i ] >= self::MIN_BUCKET_IMPRESSIONS ) {
				$own[ $i ] = 100 * $clicks[ $i ] / $shown[ $i ];
				$searches += $shown[ $i ];
			}
		}
		if ( [] === $own ) {
			return [
				'curve'    => self::CURVE,
				'site'     => false,
				'searches' => 0,
				'buckets'  => 0,
			];
		}

		// A bucket without enough of the site's own searches takes the standard value scaled by the ratio
		// own ÷ standard at the nearest calibrated bucket, so the whole curve sits at the site's level.
		$values  = [];
		$weights = [];
		foreach ( self::BUCKETS as $i => $bucket ) {
			if ( isset( $own[ $i ] ) ) {
				$values[]  = $own[ $i ];
				$weights[] = (float) $shown[ $i ];
			} else {
				$values[]  = self::interpolate( self::CURVE, (float) $bucket[2] ) * self::nearest_ratio( $own, (float) $bucket[2] );
				$weights[] = 1.0;
			}
		}
		$values = self::non_increasing( $values, $weights );
		$curve  = [];
		foreach ( self::BUCKETS as $i => $bucket ) {
			$curve[ $bucket[2] ] = round( $values[ $i ], 2 );
		}
		$curve[50] = min( round( self::CURVE[50] * self::nearest_ratio( $own, 50.0 ), 2 ), $curve[40] );
		$used      = count( $own );

		// Sanity gate: a site curve whose position 1 is below the built-in position 5 says more about what
		// the site's searches are (maps, weather, names) than about how its listings are clicked: built-in.
		if ( $curve[1] < self::CURVE[5] ) {
			return [
				'curve'    => self::CURVE,
				'site'     => false,
				'searches' => $searches,
				'buckets'  => $used,
				'reason'   => 'implausible',
			];
		}

		return [
			'curve'    => $curve,
			'site'     => true,
			'searches' => $searches,
			'buckets'  => $used,
		];
	}

	/**
	 * The ratio own ÷ standard at the calibrated bucket nearest a position (the earlier one on a tie).
	 *
	 * @param array<int,float> $own      Calibrated CTR by bucket index.
	 * @param float            $position Position.
	 */
	protected static function nearest_ratio( array $own, float $position ): float {
		$best = null;
		$gap  = INF;
		foreach ( $own as $i => $value ) {
			$at = (float) self::BUCKETS[ $i ][2];
			if ( abs( $at - $position ) < $gap ) {
				$gap  = abs( $at - $position );
				$best = $i;
			}
		}
		if ( null === $best ) {
			return 1.0;
		}
		$standard = self::interpolate( self::CURVE, (float) self::BUCKETS[ $best ][2] );

		return $standard > 0 ? $own[ $best ] / $standard : 1.0;
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
	 * Where a page's opportunity is: per search, plus the remainder at the page's average position.
	 *
	 * Each row carries its 90-day missed clicks at today's position (the quick win), what it would add at
	 * position 3 (the top-3 prize), its intent and that intent's weight. Totals: `missed` and `prize` are
	 * plain visits; `weighted` and `weighted_prize` are the same × each search's intent weight. Without
	 * searches (v1 data) it is the page-level formula, method 'page', intent unknown.
	 *
	 * @param array<string,mixed>|null $gsc    Page Search Console block (Page_Data): impressions, clicks, ctr,
	 *                                         position, queries[] { query, impressions, clicks, ctr, position }.
	 * @param callable|null            $intent fn( string $query ): string, the search's intent (Intent::of).
	 * @return array{missed:float,prize:float,weighted:float,weighted_prize:float,method:string,mix:array<string,float>,rows:array<int,array<string,mixed>>,winnable:float,winnable_prize:float,zero_share:float}
	 */
	public static function breakdown( ?array $gsc, ?callable $intent = null ): array {
		$out = [
			'missed'         => 0.0,
			'prize'          => 0.0,
			'weighted'       => 0.0,
			'weighted_prize' => 0.0,
			'method'         => 'none',
			'mix'            => [],
			'rows'           => [],
			'winnable'       => 0.0,
			'winnable_prize' => 0.0,
			'zero_share'     => 0.0,
		];
		if ( null === $gsc ) {
			return $out;
		}
		$shown   = (int) ( $gsc['impressions'] ?? 0 );
		$clicks  = (int) ( $gsc['clicks'] ?? 0 );
		$pos     = (float) ( $gsc['position'] ?? 0 );
		$queries = array_values( array_filter( (array) ( $gsc['queries'] ?? [] ), static fn( $q ) => is_array( $q ) && (int) ( $q['impressions'] ?? 0 ) > 0 && is_numeric( $q['position'] ?? null ) ) );
		$rows    = [];
		if ( [] === $queries ) {
			if ( $shown <= 0 || $pos <= 0 ) {
				return $out;
			}
			$out['method'] = 'page';
			$rows[]        = self::row( '', $shown, $clicks, $pos, 'unknown', false ) + [ 'remain' => true ];
		} else {
			$out['method'] = 'query';
			$q_shown       = 0;
			$q_clicks      = 0;
			foreach ( $queries as $q ) {
				$imp       = (int) $q['impressions'];
				$clk       = (int) ( $q['clicks'] ?? 0 );
				$q_shown  += $imp;
				$q_clicks += $clk;
				$text      = (string) ( $q['query'] ?? '' );
				$row       = self::row( $text, $imp, $clk, (float) $q['position'], null !== $intent ? (string) $intent( $text ) : 'unknown', true );
				if ( self::zero_click( $text, $imp, $clk, (float) $q['position'] ) ) {
					// Google answers it on the results page: few of its clicks can ever be won.
					$row['zero_click'] = true;
					$row['weight']     = min( $row['weight'], self::zero_click_weight() );
				}
				$rows[] = $row;
			}
			$rest_shown = max( 0, $shown - $q_shown );
			if ( $rest_shown > 0 && $pos > 0 ) {
				// Searches Google does not name (and, with a country filter on the search list, foreign ones):
				// the named searches' intent mix, never above "comparing", × a low weight (0.25, filterable):
				// they cannot be written for, and many are not the business's customers.
				$named          = array_filter( $rows, static fn( $r ) => 'unknown' !== $r['intent'] );
				$shown_named    = array_sum( array_column( $named, 'impressions' ) );
				$mix_weight     = $shown_named > 0 ? array_sum( array_map( static fn( $r ) => $r['weight'] * $r['impressions'], $named ) ) / $shown_named : Intent::UNKNOWN_WEIGHT;
				$weight         = min( $mix_weight, Intent::WEIGHTS['commercial'] ) * self::unnamed_weight();
				$rest           = self::row( '', $rest_shown, max( 0, min( $rest_shown, $clicks - $q_clicks ) ), $pos, 'unnamed', true );
				$rest['weight'] = $weight;
				// The searches Google does not name are most likely of the same kind as those it does: their
				// zero-click share (by impressions) carries over to what the remainder can win.
				$zero_named        = array_sum( array_map( static fn( $r ) => ! empty( $r['zero_click'] ) ? $r['impressions'] : 0, $rows ) );
				$rest['zero_part'] = $shown_named > 0 ? $zero_named / $shown_named : 0.0;
				$rows[]            = $rest + [ 'remain' => true ];
			}
		}

		$mix        = [];
		$zero_shown = 0;
		$all_shown  = 0;
		foreach ( $rows as $i => $row ) {
			$rows[ $i ] += [
				'remain'     => false,
				'zero_click' => false,
			];
			// What can actually be won: a search Google answers itself counts at the zero-click weight, the
			// same as in the ranking, so the figure shown never promises its unwinnable clicks.
			$part                         = ! empty( $row['zero_click'] ) ? 1.0 : (float) ( $row['zero_part'] ?? 0.0 );
			$keep                         = 1.0 - $part + $part * self::zero_click_weight();
			$rows[ $i ]['winnable']       = $row['missed'] * $keep;
			$rows[ $i ]['winnable_prize'] = $row['prize'] * $keep;
			$out['winnable']             += $row['missed'] * $keep;
			$out['winnable_prize']       += $row['prize'] * $keep;
			if ( empty( $row['remain'] ) ) {
				// The share is judged on the searches Google names (the only ones whose clicks can be read).
				$all_shown  += (int) $row['impressions'];
				$zero_shown += empty( $row['zero_click'] ) ? 0 : (int) $row['impressions'];
			}
			$out['missed']         += $row['missed'];
			$out['prize']          += $row['prize'];
			$out['weighted']       += $row['missed'] * $row['weight'];
			$out['weighted_prize'] += $row['prize'] * $row['weight'];
			if ( empty( $row['remain'] ) ) {
				$mix[ $row['intent'] ] = ( $mix[ $row['intent'] ] ?? 0 ) + $row['impressions']; // The mix of the searches Google names.
			}
		}
		$all = array_sum( $mix );
		foreach ( $mix as $k => $v ) {
			$out['mix'][ $k ] = $all > 0 ? round( $v / $all, 3 ) : 0.0;
		}
		arsort( $out['mix'] );
		$out['zero_share'] = $all_shown > 0 ? round( $zero_shown / $all_shown, 3 ) : 0.0;
		usort( $rows, static fn( $a, $b ) => [ $b['missed'] * $b['weight'], $b['prize'] ] <=> [ $a['missed'] * $a['weight'], $a['prize'] ] );
		$out['rows'] = $rows;

		return $out;
	}

	/**
	 * One search's (or the remainder's) quick win and top-3 prize, 90 days.
	 *
	 * @param string $query    Search ('' for the remainder).
	 * @param int    $shown    Impressions.
	 * @param int    $clicks   Clicks.
	 * @param float  $position Position.
	 * @param string $intent   Intent (Intent::WEIGHTS key or 'unknown').
	 * @param bool   $reach    Fade the quick win past position 20 (per search); off for the v1 page-level row.
	 * @return array<string,mixed>
	 */
	protected static function row( string $query, int $shown, int $clicks, float $position, string $intent, bool $reach ): array {
		$ctr      = $shown > 0 ? 100 * $clicks / $shown : 0.0;
		$expected = self::expected_ctr( $position );
		$at_three = self::expected_ctr( 3.0 );

		return [
			'query'       => $query,
			'impressions' => $shown,
			'clicks'      => $clicks,
			'position'    => $position,
			'ctr'         => round( $ctr, 2 ),
			'expected'    => $expected,
			'missed'      => max( 0.0, $shown * ( $expected - $ctr ) / 100 ) * ( $reach ? self::reach( $position ) : 1.0 ),
			'prize'       => $position > 3 ? max( 0.0, $shown * ( $at_three - $ctr ) / 100 ) * self::feasibility( $position ) : 0.0,
			'intent'      => $intent,
			'weight'      => Intent::weight( $intent ),
		];
	}

	/**
	 * How likely a search can be lifted to position 3 at all: 1 from page 1, 0.5 from 11–20, 0.2 from 21–30,
	 * 0.05 from further down (a page at 45 needs far more than a better listing).
	 *
	 * @param float $position Position.
	 */
	public static function feasibility( float $position ): float {
		if ( $position <= 10 ) {
			return 1.0;
		}
		if ( $position <= 20 ) {
			return 0.5;
		}

		return $position <= 30 ? 0.2 : 0.05;
	}

	/**
	 * The weight of the searches Google does not name (filterable, default 0.25).
	 */
	public static function unnamed_weight(): float {
		$w = 0.25;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the weight of a page's unnamed searches (anonymised, and foreign when the search list is
			 * country-filtered) in the opportunity value.
			 *
			 * @param float $w Weight, 0–1.
			 */
			$w = (float) apply_filters( 'ai_seo_assistant_unnamed_weight', $w );
		}

		return max( 0.0, min( 1.0, $w ) );
	}

	/**
	 * A 90-day figure as a year.
	 *
	 * @param float $ninety Over the 90-day window.
	 */
	public static function yearly( float $ninety ): float {
		return $ninety * 365 / 90;
	}

	/**
	 * Estimated enquiries from extra visits: × the page's enquiry rate (the site's when the page had fewer
	 * than 30 visits). Null (omit, never 0) when the site had fewer than 10 tracked enquiries in 90 days.
	 *
	 * @param float $visits         Extra visits (a year).
	 * @param int   $enquiries      Page enquiries (90 days).
	 * @param int   $page_visits    Page visits (90 days).
	 * @param int   $site_enquiries Site enquiries (90 days).
	 * @param int   $site_visits    Site visits (90 days).
	 */
	public static function enquiries( float $visits, int $enquiries, int $page_visits, int $site_enquiries, int $site_visits ): ?float {
		if ( $site_enquiries < 10 || $site_visits <= 0 ) {
			return null;
		}
		$rate = $page_visits >= 30 ? $enquiries / $page_visits : $site_enquiries / $site_visits;

		return $visits * $rate;
	}

	/**
	 * Tier floors (filterable): a page is High only from FLOORS['high'] weighted visits a year, Medium only
	 * from FLOORS['medium'], so a trivial gain on a quiet site never reads High or Medium.
	 *
	 * @return array{high:float,medium:float}
	 */
	public static function tier_floors(): array {
		$t = self::FLOORS;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the opportunity tier floors: the weighted extra visits a year a page needs to be High or
			 * Medium, whatever its share of the site's opportunity.
			 *
			 * @param array{high:float,medium:float} $t Floors.
			 */
			$f = apply_filters( 'ai_seo_assistant_opportunity_floors', $t );
			if ( is_array( $f ) && isset( $f['high'], $f['medium'] ) && is_numeric( $f['high'] ) && is_numeric( $f['medium'] ) ) {
				$t = [
					'high'   => (float) $f['high'],
					'medium' => (float) $f['medium'],
				];
			}
		}

		return $t;
	}

	/**
	 * Tiers that scale with the site (decision 2026-10-06, round 3): pages sorted by value; High is the
	 * smallest set of top pages that together hold half the site's total opportunity, Medium the pages
	 * holding the next quarter, Low the rest with a measurable value (≥ 1 weighted visit a year), None the
	 * rest. A page below the High floor drops to Medium, below the Medium floor to Low.
	 *
	 * @param array<int|string,float> $values Key => weighted value a year.
	 * @return array<int|string,string> Key => high | medium | low | none.
	 */
	public static function tiers( array $values ): array {
		$floors = self::tier_floors();
		arsort( $values );
		$total = array_sum( array_filter( $values, static fn( $v ) => $v > 0 ) );
		$out   = [];
		$sum   = 0.0;
		foreach ( $values as $key => $value ) {
			if ( $value < 1 || $total <= 0 ) {
				$out[ $key ] = 'none';
				continue;
			}
			$before = $sum;
			$sum   += $value;
			if ( $before < 0.5 * $total ) {
				$tier = 'high';     // Still short of half when this page was added: it is part of the smallest set.
			} elseif ( $before < 0.75 * $total ) {
				$tier = 'medium';
			} else {
				$tier = 'low';
			}
			if ( 'high' === $tier && $value < $floors['high'] ) {
				$tier = 'medium';
			}
			if ( 'medium' === $tier && $value < $floors['medium'] ) {
				$tier = 'low';
			}
			$out[ $key ] = $tier;
		}

		return $out;
	}

	/**
	 * A page's value a year: a weighted figure (quick win or top-3 prize, 90 days) × the page role's value ×
	 * the enquiry lift.
	 *
	 * @param float $weighted  breakdown()['weighted'] or ['weighted_prize'] (90 days).
	 * @param float $role      value() of the page's role.
	 * @param int   $enquiries Page enquiries (90 days).
	 */
	public static function value_of( float $weighted, float $role, int $enquiries ): float {
		return self::weighted( self::yearly( $weighted ), $enquiries, $role );
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
		// The site needs 10 tracked enquiries and the page 2 of its own: one enquiry is not a rate.
		if ( $site_enquiries < 10 || $site_visits <= 0 || $visits <= 0 || $enquiries < 2 ) {
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
