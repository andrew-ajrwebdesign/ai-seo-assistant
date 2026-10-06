<?php
/**
 * Opportunity — how many extra clicks a page could win, as the SEO scan's ranking.
 *
 * THE FORMULA (decision 2026-10-06, step 3):
 *
 *   missed clicks = impressions × max( 0, expected CTR at the page's position − actual CTR )
 *   weighted      = missed clicks × ( 1 + ln( 1 + enquiries started on the page ) )
 *   score         = 100 × weighted ÷ the site's largest weighted value      (0–100, rounded)
 *
 * Impressions and CTR are the page's 90-day Search Console figures from the push; enquiries are GA4's for
 * the same page. A page Google barely shows has few impressions, so it ranks low however many issues it
 * has; a page shown often but clicked rarely ranks high, because its listing (title and description) is
 * the cheapest thing to fix. The enquiry weight lifts pages that already turn visitors into enquiries:
 * an extra click there is worth more. The log keeps one enquiry from doubling a page (×1.7) and ten from
 * swamping the impressions term (×3.4).
 *
 * EXPECTED CTR CURVE. Click-through rate by average position, blended desktop and mobile, for a results
 * page with the usual ads and map pack above the organic results. It sits under the headline "position 1
 * gets 28%" studies (Advanced Web Ranking's 2024–26 CTR curves, Backlinko 2023) from position 3 down,
 * because a local service search shows a map pack and ads first; positions 1–2 follow the studies. Points
 * between the listed positions are interpolated in a straight line; past 50 it is 0.1%. Filterable per site
 * (`ai_seo_assistant_expected_ctr`) when Search Console's own site curve says otherwise.
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
	 * Expected CTR in percent at an average position.
	 *
	 * @var array<int|string,float>
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
	 * The curve in force (filterable).
	 *
	 * @return array<int,float>
	 */
	protected static function curve(): array {
		$curve = self::CURVE;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the expected CTR curve (position => percent) used to rank pages.
			 *
			 * @param array<int,float> $curve Curve.
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
	 * Clicks the page missed over the window: impressions × (expected − actual CTR), never below 0.
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
	 * The enquiry-weighted value.
	 *
	 * @param float $missed    missed_clicks().
	 * @param int   $enquiries GA4 enquiries started on the page.
	 */
	public static function weighted( float $missed, int $enquiries ): float {
		return $missed * ( 1 + log( 1 + max( 0, $enquiries ) ) );
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
