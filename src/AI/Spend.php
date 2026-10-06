<?php
/**
 * Spend — the per-site Claude spend cap, by the client's billing month.
 *
 * WHY (Andrew, 2026-10-06: "include alt text and the $10 cap"): every Claude call is billed to the agency's
 * key for this site. The Claude Console workspace limit is the outer fence; this is the inner one the
 * agency sees and sets per site: $10 a billing month by default. At the cap, writing suggestions stops (the
 * scan, the issues and already-written suggestions keep working) and the screens say so.
 *
 * HOW IT COUNTS. From the API's own `usage` on every reply (input, output, cache write, cache read tokens),
 * priced by the table below, so the figure is what Anthropic will bill, not a guess. Checked BEFORE every
 * call with a small reserve for the call about to happen, so a site at $9.99 cannot start a 6¢ request.
 * The editor box goes through the same Claude_Client, so it is capped too.
 *
 * BILLING MONTH. The client's own month, anchored on the billing day (billing day 17 → 17 Sep–16 Oct),
 * because the retainer and its monthly report run on that cycle. The day comes from the push (retainer-scan
 * knows it: weekly-sites.json) when one has arrived, else from the Settings field, else the 1st.
 *
 * One small non-autoloaded option holds the current period's total; a new period starts at zero on its
 * first call. Pure arithmetic is static and unit-tested without WordPress.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Billing-month spend tracking and the cap check.
 */
class Spend {

	/** Option: [ period => 'Y-m-d', usd => float, calls => int, capped_at => int ]. Not autoloaded. */
	public const OPTION = 'ai_seo_assistant_spend';

	/** Option: the cap in US dollars (Settings). */
	public const CAP_OPTION = 'ai_seo_assistant_spend_cap';

	/** Option: billing day typed in Settings (1–31). */
	public const DAY_OPTION = 'ai_seo_assistant_billing_day';

	/** Option: billing day from the last push that carried one (wins over the typed one). */
	public const PUSHED_DAY_OPTION = 'ai_seo_assistant_billing_day_pushed';

	/** Default cap, dollars per billing month. */
	public const DEFAULT_CAP = 10.0;

	/**
	 * Dollars per million tokens, Anthropic first-party API rates (checked 2026-10-06).
	 *
	 * Cache writes bill at 1.25× input and cache reads at 0.1× input. A model the table does not know is
	 * priced as Opus 5, the most expensive the plugin offers, so an unknown model can only over-count.
	 */
	public const PRICES = [
		'claude-opus-5'    => [
			'in'  => 5.0,
			'out' => 25.0,
		],
		'claude-sonnet-5'  => [
			'in'  => 2.0,
			'out' => 10.0,
		],
		'claude-haiku-4-5' => [
			'in'  => 1.0,
			'out' => 5.0,
		],
	];

	/**
	 * The most one call of each kind is expected to cost, in dollars, checked against what is left.
	 * From measured Opus 5 calls (2026-09-22: title + description 2–3¢, recommendations about 6¢); a page
	 * review asks for both plus alt text, so it reserves the most.
	 */
	public const RESERVE = [
		'metadata'        => 0.04,
		'recommendations' => 0.08,
		'review'          => 0.12,
		'intent'          => 0.02,
		'test'            => 0.01,
	];

	/**
	 * Price one reply.
	 *
	 * @param string              $model Model that answered.
	 * @param array<string,mixed> $usage The reply's `usage` object.
	 * @return float Dollars.
	 */
	public static function cost( string $model, array $usage ): float {
		$price = self::PRICES[ self::base_model( $model ) ] ?? self::PRICES['claude-opus-5'];
		$in    = (int) ( $usage['input_tokens'] ?? 0 );
		$out   = (int) ( $usage['output_tokens'] ?? 0 );
		$write = (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
		$read  = (int) ( $usage['cache_read_input_tokens'] ?? 0 );

		return ( $in * $price['in'] + $write * $price['in'] * 1.25 + $read * $price['in'] * 0.1 + $out * $price['out'] ) / 1000000;
	}

	/**
	 * A dated model ID ("claude-opus-5-20260101") or a fallback's ID, reduced to the table's key.
	 *
	 * @param string $model Model ID.
	 */
	public static function base_model( string $model ): string {
		foreach ( array_keys( self::PRICES ) as $known ) {
			if ( 0 === strpos( $model, $known ) ) {
				return $known;
			}
		}

		return $model;
	}

	/**
	 * The start of the billing period that contains $now.
	 *
	 * The anchor day is clamped to the month's length (a 31st anchor is the 30th in September).
	 *
	 * @param int           $day Anchor day, 1–31.
	 * @param int           $now Unix time.
	 * @param \DateTimeZone $tz Site timezone.
	 * @return \DateTimeImmutable Midnight on the period's first day.
	 */
	public static function period_start( int $day, int $now, \DateTimeZone $tz ): \DateTimeImmutable {
		$day        = max( 1, min( 31, $day ) );
		$today      = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( 0, 0 );
		$this_month = self::anchor( (int) $today->format( 'Y' ), (int) $today->format( 'n' ), $day, $tz );
		if ( $today >= $this_month ) {
			return $this_month;
		}
		$prev = $today->modify( 'first day of last month' );

		return self::anchor( (int) $prev->format( 'Y' ), (int) $prev->format( 'n' ), $day, $tz );
	}

	/**
	 * The first day of the NEXT period (when the cap resets).
	 *
	 * @param int           $day Anchor day.
	 * @param int           $now Unix time.
	 * @param \DateTimeZone $tz  Site timezone.
	 */
	public static function period_end( int $day, int $now, \DateTimeZone $tz ): \DateTimeImmutable {
		$start = self::period_start( $day, $now, $tz );
		$next  = $start->modify( 'first day of next month' );

		return self::anchor( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), max( 1, min( 31, $day ) ), $tz );
	}

	/**
	 * The anchor day in a given month, clamped to its length.
	 *
	 * @param int           $year  Year.
	 * @param int           $month Month 1–12.
	 * @param int           $day   Anchor day.
	 * @param \DateTimeZone $tz    Timezone.
	 */
	protected static function anchor( int $year, int $month, int $day, \DateTimeZone $tz ): \DateTimeImmutable {
		$first = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $tz );

		return $first->setDate( $year, $month, min( $day, (int) $first->format( 't' ) ) );
	}

	/**
	 * Whether a call of this kind may start: what is spent plus its reserve stays within the cap.
	 *
	 * @param float  $spent Spent this period.
	 * @param float  $cap   Cap.
	 * @param string $task  Key of RESERVE.
	 */
	public static function allows( float $spent, float $cap, string $task ): bool {
		if ( $cap <= 0 ) {
			return false;
		}

		return $spent + ( self::RESERVE[ $task ] ?? self::RESERVE['review'] ) <= $cap + 0.000001;
	}

	/* ---- WordPress side ---------------------------------------------------------------------------- */

	/**
	 * The billing day in force and where it came from.
	 *
	 * @return array{day:int,source:string} source: 'push' | 'settings' | 'default'.
	 */
	public static function billing_day(): array {
		$pushed = (int) get_option( self::PUSHED_DAY_OPTION, 0 );
		if ( $pushed >= 1 && $pushed <= 31 ) {
			return [
				'day'    => $pushed,
				'source' => 'push',
			];
		}
		$typed = (int) get_option( self::DAY_OPTION, 0 );
		if ( $typed >= 1 && $typed <= 31 ) {
			return [
				'day'    => $typed,
				'source' => 'settings',
			];
		}

		return [
			'day'    => 1,
			'source' => 'default',
		];
	}

	/**
	 * The cap in dollars.
	 */
	public static function cap(): float {
		$cap = get_option( self::CAP_OPTION, self::DEFAULT_CAP );

		return is_numeric( $cap ) ? max( 0.0, (float) $cap ) : self::DEFAULT_CAP;
	}

	/**
	 * This period's state, starting a fresh one when the stored period has ended.
	 *
	 * @return array{period:string,usd:float,calls:int,capped_at:int,start:\DateTimeImmutable,end:\DateTimeImmutable}
	 */
	public static function current(): array {
		$tz    = wp_timezone();
		$day   = self::billing_day()['day'];
		$start = self::period_start( $day, time(), $tz );
		$end   = self::period_end( $day, time(), $tz );
		$state = get_option( self::OPTION, [] );
		$state = is_array( $state ) ? $state : [];
		$same  = ( $state['period'] ?? '' ) === $start->format( 'Y-m-d' );

		return [
			'period'    => $start->format( 'Y-m-d' ),
			'usd'       => $same ? (float) ( $state['usd'] ?? 0 ) : 0.0,
			'calls'     => $same ? (int) ( $state['calls'] ?? 0 ) : 0,
			'capped_at' => $same ? (int) ( $state['capped_at'] ?? 0 ) : 0,
			'start'     => $start,
			'end'       => $end,
		];
	}

	/**
	 * Refuse a call that would go past the cap (WP_Error with the B2 wording), or true.
	 *
	 * @param string $task Key of RESERVE.
	 * @return true|\WP_Error
	 */
	public static function check( string $task ) {
		$now = self::current();
		if ( self::allows( $now['usd'], self::cap(), $task ) ) {
			return true;
		}
		if ( 0 === $now['capped_at'] ) {
			self::save( $now, [ 'capped_at' => time() ] );
		}

		return new \WP_Error( 'aisa_spend_cap', self::cap_message( $now ), [ 'status' => 402 ] );
	}

	/**
	 * Add one reply's cost to the period.
	 *
	 * @param string              $model Model that answered.
	 * @param array<string,mixed> $usage The reply's usage.
	 * @return float The reply's cost in dollars.
	 */
	public static function record( string $model, array $usage ): float {
		$cost = self::cost( $model, $usage );
		$now  = self::current();
		self::save(
			$now,
			[
				'usd'   => round( $now['usd'] + $cost, 6 ),
				'calls' => $now['calls'] + 1,
			]
		);

		return $cost;
	}

	/**
	 * "Monthly AI cap reached: $10.00 of $10.00 used this billing month. …" (mockup B2).
	 *
	 * @param array<string,mixed> $now current().
	 */
	public static function cap_message( array $now ): string {
		return sprintf(
			/* translators: 1: dollars spent, 2: the cap, 3: date writing resumes. */
			__( 'Monthly AI cap reached: %1$s of %2$s used this billing month. Writing new suggestions is paused until %3$s. The scan, the issues and the search data keep updating, and suggestions already written can still be reviewed and applied.', 'ai-seo-assistant' ),
			self::money( (float) $now['usd'] ),
			self::money( self::cap() ),
			wp_date( 'j F', $now['end']->getTimestamp(), $now['end']->getTimezone() )
		);
	}

	/**
	 * "$3.84".
	 *
	 * @param float $usd Dollars.
	 */
	public static function money( float $usd ): string {
		return '$' . number_format_i18n( $usd, 2 );
	}

	/**
	 * Write the period's state.
	 *
	 * @param array<string,mixed> $now     current().
	 * @param array<string,mixed> $changes Fields to change.
	 */
	protected static function save( array $now, array $changes ): void {
		update_option(
			self::OPTION,
			array_merge(
				[
					'period'    => $now['period'],
					'usd'       => $now['usd'],
					'calls'     => $now['calls'],
					'capped_at' => $now['capped_at'],
				],
				$changes
			),
			false
		);
	}
}
