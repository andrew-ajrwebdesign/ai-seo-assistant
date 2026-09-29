<?php
/**
 * Format — the words the report puts around its numbers.
 *
 * The owner reads "+3 on last week" or "up 0.7 places", never a raw delta. Every change is written out
 * in words, so colour is never the only signal (WCAG 1.4.1), and each carries a tone the page styles:
 * 'good', 'bad' or 'flat'. Whether "up" is good depends on the figure — more clicks is better, a lower
 * average position or cost per enquiry is better — and that knowledge lives here, in one place.
 *
 * Pure PHP apart from translation (__ / _n, mocked in the unit tests), so the wording rules are tested
 * without WordPress.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Number and change wording for the weekly report.
 */
class Format {

	/** A count ("+3 on last week"). */
	public const COUNT = 'count';

	/** A count shown as a percentage change ("+8% on last week"). */
	public const PERCENT = 'percent';

	/** A rate in percent ("+0.1 points"). */
	public const POINTS = 'points';

	/** A search position, where lower is better ("up 0.7 places"). */
	public const PLACES = 'places';

	/** Money where lower is better, e.g. cost per enquiry ("−$9 on last week"). */
	public const COST = 'cost';

	/**
	 * The change from last week, in words, and whether it is good news.
	 *
	 * @param int|float|null $value    This week.
	 * @param int|float|null $previous Last week (null when unknown).
	 * @param string         $kind     One of the class constants.
	 * @param string         $currency ISO currency code, for COST.
	 * @return array{text:string,tone:string} Empty text when there is no previous week to compare.
	 */
	public static function change( $value, $previous, string $kind = self::COUNT, string $currency = '' ): array {
		if ( null === $value || null === $previous ) {
			return [
				'text' => '',
				'tone' => 'flat',
			];
		}
		$delta = $value - $previous;
		if ( abs( $delta ) < 0.05 ) {
			return [
				'text' => __( 'same as last week', 'ai-seo-assistant' ),
				'tone' => 'flat',
			];
		}

		switch ( $kind ) {
			case self::PERCENT:
				if ( 0 === (int) round( $previous ) ) {
					return [
						'text' => '',
						'tone' => 'flat',
					];
				}
				$pct = (int) round( $delta / $previous * 100 );
				if ( 0 === $pct ) {
					return [
						'text' => __( 'same as last week', 'ai-seo-assistant' ),
						'tone' => 'flat',
					];
				}
				/* translators: %s: signed percentage, e.g. "+8%". */
				$text = sprintf( __( '%s on last week', 'ai-seo-assistant' ), self::signed( $pct ) . '%' );
				$good = $pct > 0;
				break;
			case self::POINTS:
				/* translators: %s: signed number of percentage points, e.g. "+0.1". */
				$text = sprintf( __( '%s points', 'ai-seo-assistant' ), self::signed( round( $delta, 1 ) ) );
				$good = $delta > 0;
				break;
			case self::PLACES:
				$places = number_format_i18n( abs( $delta ), 1 );
				/* translators: %s: number of places, e.g. "0.7". */
				$text = $delta < 0 ? sprintf( __( 'up %s places', 'ai-seo-assistant' ), $places ) : sprintf( __( 'down %s places', 'ai-seo-assistant' ), $places );
				$good = $delta < 0; // Position 1 is the top: a smaller number is better.
				break;
			case self::COST:
				/* translators: %s: signed amount of money, e.g. "−$9". */
				$text = sprintf( __( '%s on last week', 'ai-seo-assistant' ), self::sign( $delta ) . self::money( abs( $delta ), $currency ) );
				$good = $delta < 0; // Paying less for an enquiry is better.
				break;
			default:
				/* translators: %s: signed count, e.g. "+3". */
				$text = sprintf( __( '%s on last week', 'ai-seo-assistant' ), self::signed( (int) round( $delta ) ) );
				$good = $delta > 0;
		}

		return [
			'text' => $text,
			'tone' => $good ? 'good' : 'bad',
		];
	}

	/**
	 * The report's headline, e.g. "23 enquiries this week, 5 more than last week."
	 *
	 * @param int      $total    This week's enquiries.
	 * @param int|null $previous Last week's, when every source has one.
	 */
	public static function headline( int $total, ?int $previous ): string {
		$count = number_format_i18n( $total );
		if ( null === $previous ) {
			/* translators: %s: number of enquiries. */
			return sprintf( _n( '%s enquiry this week.', '%s enquiries this week.', $total, 'ai-seo-assistant' ), $count );
		}
		$delta = $total - $previous;
		if ( 0 === $delta ) {
			/* translators: %s: number of enquiries. */
			return sprintf( _n( '%s enquiry this week, the same as last week.', '%s enquiries this week, the same as last week.', $total, 'ai-seo-assistant' ), $count );
		}
		$pattern = $delta > 0
			/* translators: 1: number of enquiries, 2: how many more than last week. */
			? _n( '%1$s enquiry this week, %2$s more than last week.', '%1$s enquiries this week, %2$s more than last week.', $total, 'ai-seo-assistant' )
			/* translators: 1: number of enquiries, 2: how many fewer than last week. */
			: _n( '%1$s enquiry this week, %2$s fewer than last week.', '%1$s enquiries this week, %2$s fewer than last week.', $total, 'ai-seo-assistant' );

		return sprintf( $pattern, $count, number_format_i18n( abs( $delta ) ) );
	}

	/**
	 * A figure made short for a tile: 18600 → "18.6k", 1284 → "1,284".
	 *
	 * @param int|float $value Value.
	 */
	public static function short( $value ): string {
		if ( $value >= 1000000 ) {
			return self::trim( number_format_i18n( $value / 1000000, 1 ) ) . 'm';
		}
		if ( $value >= 10000 ) {
			return self::trim( number_format_i18n( $value / 1000, 1 ) ) . 'k';
		}

		return is_float( $value ) && floor( $value ) !== $value ? number_format_i18n( $value, 1 ) : number_format_i18n( (float) $value );
	}

	/**
	 * Drop a trailing zero decimal in any locale: "18.0" / "18,0" → "18".
	 *
	 * @param string $number Formatted number with one decimal.
	 */
	protected static function trim( string $number ): string {
		return (string) preg_replace( '/[.,]0$/', '', $number );
	}

	/**
	 * Money, whole units: "$612". Unknown currencies fall back to the ISO code ("612 CHF").
	 *
	 * @param int|float $amount   Amount.
	 * @param string    $currency ISO 4217 code.
	 */
	public static function money( $amount, string $currency ): string {
		$symbols = [
			'USD' => '$',
			'CAD' => '$',
			'AUD' => '$',
			'NZD' => '$',
			'GBP' => '£',
			'EUR' => '€',
		];
		$number  = number_format_i18n( (float) round( $amount ) );

		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $number : trim( $number . ' ' . $currency );
	}

	/**
	 * "+3" / "−2" (a real minus sign, which screen readers announce as minus).
	 *
	 * @param int|float $n Number.
	 */
	protected static function signed( $n ): string {
		return self::sign( $n ) . number_format_i18n( abs( $n ), is_float( $n ) && floor( $n ) !== $n ? 1 : 0 );
	}

	/**
	 * The sign alone.
	 *
	 * @param int|float $n Number.
	 */
	protected static function sign( $n ): string {
		return $n < 0 ? "\u{2212}" : '+';
	}
}
