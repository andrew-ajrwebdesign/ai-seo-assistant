<?php
/**
 * Chart — the report's two charts, drawn as inline SVG on the server.
 *
 * WHY SERVER-RENDERED SVG. The report must read without JavaScript and without a chart library (none
 * ships with wp-admin, and one per client site is weight for two small charts). Every shape carries a
 * class, never a colour: the report stylesheet colours them with the AJR brand tokens. Each chart has a
 * <title> and a <desc> giving the numbers in words, so a screen reader hears the data, not "image".
 *
 * Pure PHP apart from escaping and translation.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * SVG charts.
 */
class Chart {

	/**
	 * Weekly bars, the latest highlighted and labelled with its value.
	 *
	 * @param array<int,int>    $values Oldest first (up to 12).
	 * @param array<int,string> $labels Week labels, same length ("6 Jul").
	 * @param string            $title  Accessible title.
	 */
	public static function bars( array $values, array $labels, string $title ): string {
		$count = count( $values );
		if ( 0 === $count || count( $labels ) !== $count ) {
			return '';
		}
		$w      = 330;
		$h      = 168;
		$base   = 142; // Baseline y.
		$top    = 24;  // Room above the tallest bar for its label.
		$max    = max( 1, max( $values ) );
		$gap    = 7;
		$bar_w  = ( $w - $gap * ( $count - 1 ) ) / $count;
		$id     = 'aisa-bars-' . substr( md5( $title . implode( ',', $values ) ), 0, 8 );
		$shapes = '';

		foreach ( [ 0.5, 1.0 ] as $fraction ) {
			$y       = $base - ( $base - $top ) * $fraction;
			$shapes .= sprintf( '<line class="aisa-grid" x1="0" x2="%d" y1="%s" y2="%s"/>', $w, self::n( $y ), self::n( $y ) );
		}
		foreach ( $values as $i => $value ) {
			$height  = ( $base - $top ) * $value / $max;
			$x       = $i * ( $bar_w + $gap );
			$last    = $i === $count - 1;
			$shapes .= sprintf(
				'<rect class="%s" x="%s" y="%s" width="%s" height="%s" rx="3"/>',
				$last ? 'aisa-bar aisa-bar--now' : 'aisa-bar',
				self::n( $x ),
				self::n( $base - $height ),
				self::n( $bar_w ),
				self::n( max( $height, 0.5 ) )
			);
			if ( $last ) {
				$shapes .= sprintf( '<text class="aisa-bar-value" x="%s" y="%s" text-anchor="middle">%s</text>', self::n( $x + $bar_w / 2 ), self::n( $base - $height - 6 ), esc_html( number_format_i18n( $value ) ) );
			}
		}
		$shapes .= sprintf( '<line class="aisa-axis" x1="0" x2="%d" y1="%d" y2="%d"/>', $w, $base, $base );
		foreach ( self::label_points( $count ) as $i ) {
			$anchor  = 0 === $i ? 'start' : ( $count - 1 === $i ? 'end' : 'middle' );
			$x       = 0 === $i ? 0 : ( $count - 1 === $i ? $w : $i * ( $bar_w + $gap ) + $bar_w / 2 );
			$shapes .= sprintf( '<text class="aisa-tick" x="%s" y="%d" text-anchor="%s">%s</text>', self::n( $x ), $base + 18, $anchor, esc_html( $labels[ $i ] ) );
		}

		$desc = self::describe( $values, $labels );

		return sprintf(
			'<svg class="aisa-chart aisa-chart--bars" viewBox="0 0 %1$d %2$d" role="img" aria-labelledby="%3$s-t" aria-describedby="%3$s-d"><title id="%3$s-t">%4$s</title><desc id="%3$s-d">%5$s</desc>%6$s</svg>',
			$w,
			$h,
			esc_attr( $id ),
			esc_html( $title ),
			esc_html( $desc ),
			$shapes // Built above from numbers and esc_html()'d labels.
		);
	}

	/**
	 * Two lines on two scales, like Search Console: clicks (solid, filled, left scale) and times shown
	 * (dashed, right scale).
	 *
	 * @param array<int,int>    $clicks      Oldest first.
	 * @param array<int,int>    $impressions Oldest first, same length (or empty).
	 * @param array<int,string> $labels      Week labels, same length.
	 * @param string            $title       Accessible title.
	 */
	public static function lines( array $clicks, array $impressions, array $labels, string $title ): string {
		$count = count( $clicks );
		if ( $count < 2 || count( $labels ) !== $count || ( [] !== $impressions && count( $impressions ) !== $count ) ) {
			return '';
		}
		$w      = 700;
		$h      = 250;
		$left   = 34;
		$right  = 656;
		$top    = 12;
		$base   = 222;
		$cmax   = self::nice_max( max( $clicks ) );
		$imax   = [] === $impressions ? 0 : self::nice_max( max( $impressions ) );
		$x      = static fn( int $i ): float => $left + ( $right - $left ) * $i / ( $count - 1 );
		$yc     = static fn( $v ): float => $base - ( $base - $top ) * $v / $cmax;
		$yi     = static fn( $v ): float => $base - ( $base - $top ) * $v / max( 1, $imax );
		$id     = 'aisa-lines-' . substr( md5( $title . implode( ',', $clicks ) ), 0, 8 );
		$shapes = '';

		for ( $step = 0; $step <= 3; $step++ ) {
			$y       = $base - ( $base - $top ) * $step / 3;
			$shapes .= sprintf( '<line class="%s" x1="%d" x2="%d" y1="%s" y2="%s"/>', 0 === $step ? 'aisa-axis' : 'aisa-grid', $left, $right, self::n( $y ), self::n( $y ) );
			$shapes .= sprintf( '<text class="aisa-tick aisa-tick--clicks" x="%d" y="%s" text-anchor="end">%s</text>', $left - 6, self::n( $y + 4 ), esc_html( self::tick( $cmax * $step / 3, $cmax ) ) );
			if ( $imax > 0 ) {
				$shapes .= sprintf( '<text class="aisa-tick aisa-tick--shown" x="%d" y="%s">%s</text>', $right + 8, self::n( $y + 4 ), esc_html( self::tick( $imax * $step / 3, $imax ) ) );
			}
		}

		$points = [];
		foreach ( $clicks as $i => $v ) {
			$points[] = self::n( $x( $i ) ) . ',' . self::n( $yc( $v ) );
		}
		$shapes .= sprintf( '<polygon class="aisa-area" points="%s,%d %s %s,%d"/>', self::n( $x( 0 ) ), $base, implode( ' ', $points ), self::n( $x( $count - 1 ) ), $base );
		if ( [] !== $impressions ) {
			$ipoints = [];
			foreach ( $impressions as $i => $v ) {
				$ipoints[] = self::n( $x( $i ) ) . ',' . self::n( $yi( $v ) );
			}
			$shapes .= sprintf( '<polyline class="aisa-line aisa-line--shown" points="%s"/>', implode( ' ', $ipoints ) );
			$shapes .= sprintf( '<circle class="aisa-dot aisa-dot--shown" cx="%s" cy="%s" r="4.5"/>', self::n( $x( $count - 1 ) ), self::n( $yi( end( $impressions ) ) ) );
		}
		$shapes .= sprintf( '<polyline class="aisa-line aisa-line--clicks" points="%s"/>', implode( ' ', $points ) );
		$shapes .= sprintf( '<circle class="aisa-dot aisa-dot--clicks" cx="%s" cy="%s" r="4.5"/>', self::n( $x( $count - 1 ) ), self::n( $yc( end( $clicks ) ) ) );

		foreach ( self::label_points( $count ) as $i ) {
			$shapes .= sprintf( '<text class="aisa-tick" x="%s" y="%d" text-anchor="middle">%s</text>', self::n( $x( $i ) ), $base + 20, esc_html( $labels[ $i ] ) );
		}

		/* translators: %s: weekly times shown, as a comma-separated list. */
		$desc = self::describe( $clicks, $labels ) . ( [] !== $impressions ? ' ' . sprintf( __( 'Times you appeared: %s.', 'ai-seo-assistant' ), implode( ', ', array_map( 'number_format_i18n', $impressions ) ) ) : '' );

		return sprintf(
			'<svg class="aisa-chart aisa-chart--lines" viewBox="0 0 %1$d %2$d" role="img" aria-labelledby="%3$s-t" aria-describedby="%3$s-d"><title id="%3$s-t">%4$s</title><desc id="%3$s-d">%5$s</desc>%6$s</svg>',
			$w,
			$h,
			esc_attr( $id ),
			esc_html( $title ),
			esc_html( $desc ),
			$shapes // Built above from numbers and esc_html()'d labels.
		);
	}

	/**
	 * A round top for an axis with three equal steps: 412 → 450 (0, 150, 300, 450).
	 *
	 * @param int|float $max Largest value.
	 */
	public static function nice_max( $max ): float {
		if ( $max <= 0 ) {
			return 3.0;
		}
		$raw  = $max / 3;
		$pow  = 10 ** floor( log10( $raw ) );
		$step = $pow;
		foreach ( [ 1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10 ] as $m ) {
			if ( $m * $pow >= $raw ) {
				$step = $m * $pow;
				break;
			}
		}

		// Every charted figure is a count, so a step is at least 1: an axis never reads "0 · 1 · 1 · 2".
		return max( 1.0, $step ) * 3;
	}

	/**
	 * An axis tick, in one style per axis: once the axis tops 10,000 every tick is in thousands
	 * ("0 · 8k · 16k · 24k"), never a mix like "7,500 · 15k".
	 *
	 * @param float $value Tick value.
	 * @param float $top   The axis top.
	 */
	protected static function tick( float $value, float $top ): string {
		if ( $top < 10000 ) {
			return Format::short( (int) round( $value ) );
		}
		$k = $value / 1000;

		return ( floor( $k ) === $k ? number_format_i18n( $k ) : number_format_i18n( $k, 1 ) ) . ( $value > 0 ? 'k' : '' );
	}

	/**
	 * Which points get a date label: first, last, and evenly between (at most five).
	 *
	 * @param int $count Points.
	 * @return array<int,int>
	 */
	protected static function label_points( int $count ): array {
		if ( $count <= 5 ) {
			return range( 0, $count - 1 );
		}
		$every = (int) ceil( ( $count - 1 ) / 4 );
		$keep  = range( 0, $count - 1, $every );
		if ( end( $keep ) !== $count - 1 ) {
			if ( $count - 1 - end( $keep ) < $every / 2 ) {
				array_pop( $keep ); // Too close to the last label to fit beside it.
			}
			$keep[] = $count - 1;
		}

		return $keep;
	}

	/**
	 * The numbers in words, for <desc>.
	 *
	 * @param array<int,int>    $values Values.
	 * @param array<int,string> $labels Labels.
	 */
	protected static function describe( array $values, array $labels ): string {
		$pairs = [];
		foreach ( $values as $i => $v ) {
			/* translators: 1: week label, 2: value that week. */
			$pairs[] = sprintf( __( '%1$s: %2$s', 'ai-seo-assistant' ), $labels[ $i ], number_format_i18n( $v ) );
		}

		/* translators: %s: list of "week: value" pairs. */
		return sprintf( __( 'Week by week, %s.', 'ai-seo-assistant' ), implode( _x( '; ', 'list separator between weeks', 'ai-seo-assistant' ), $pairs ) );
	}

	/**
	 * A coordinate with at most two decimals.
	 *
	 * @param float|int $v Value.
	 */
	protected static function n( $v ): string {
		return rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' );
	}
}
