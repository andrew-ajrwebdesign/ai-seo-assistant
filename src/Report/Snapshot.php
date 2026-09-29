<?php
/**
 * Snapshot — the one weekly report, validated.
 *
 * WHY THIS CLASS EXISTS. The weekly report is built on Andrew's machine by `retainer-scan` (which holds
 * the Google access) and pushed to the site; the site never holds Google credentials (architecture map
 * §12). Whatever arrives is untrusted until this class has checked it: every field is type-checked,
 * length-capped and copied into a fresh array, anything unknown is dropped, and text is kept as plain
 * text (escaped only when the report is printed). A snapshot that fails a required check is refused
 * whole — the report never shows half a week.
 *
 * Format v1 (one week, Monday to Sunday):
 *   schema 1 · site · week {start, end} · generated_at · enquiries {sources[], series_12w[], note} ·
 *   note {did[], next[], author, date}? ·
 *   gsc {week {clicks, impressions, ctr, position}, clicks_12w[], impressions_12w[], top_queries[]}? ·
 *   ga4 {week {visits, key_events, engaged}, top_pages[]}? ·
 *   ads {currency, budget, spend, clicks, conversions}?
 *   Each headline figure is {value, previous}; derived figures (changes, budget used, cost per enquiry)
 *   are worked out by the report, never sent.
 *
 * Pure PHP on purpose (no WordPress calls), so every rule is unit-tested without a site.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalises a weekly report snapshot.
 */
class Snapshot {

	/** The only format version this build reads. */
	public const SCHEMA = 1;

	/** Largest accepted JSON body, in bytes. A real week is a few KB. */
	public const MAX_BYTES = 131072;

	/** Points in a trend line (12 weeks). */
	public const MAX_SERIES = 12;

	/** Rows in a table (top searches, top pages). */
	public const MAX_ROWS = 10;

	/** Enquiry sources (calls, form, bookings, email, …). */
	public const MAX_SOURCES = 8;

	/** Bullets in each half of the note. */
	public const MAX_BULLETS = 5;

	/** Longest text field, in characters. */
	public const MAX_TEXT = 300;

	/**
	 * Decode and validate a JSON body.
	 *
	 * @param string            $json   Raw body.
	 * @param array<int,string> $errors Filled with the reasons a snapshot was refused.
	 * @param int|null          $now    Unix time (tests pass one).
	 * @return array<string,mixed>|null The clean snapshot, or null when refused.
	 */
	public static function from_json( string $json, array &$errors, ?int $now = null ): ?array {
		$errors = [];
		if ( strlen( $json ) > self::MAX_BYTES ) {
			$errors[] = 'body is larger than ' . self::MAX_BYTES . ' bytes';
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			$errors[] = 'body is not a JSON object';
			return null;
		}

		return self::clean( $data, $errors, $now );
	}

	/**
	 * Validate a decoded snapshot and return a fresh, normalised copy.
	 *
	 * Dates from the future are refused: a week starting more than 7 days ahead, or a generated_at more
	 * than 10 minutes ahead. One wrong-clock push would otherwise become the "newest" week for good and
	 * lock its week against every later correction.
	 *
	 * @param array<string,mixed> $raw    Decoded snapshot.
	 * @param array<int,string>   $errors Filled with the reasons a snapshot was refused.
	 * @param int|null            $now    Unix time (tests pass one).
	 * @return array<string,mixed>|null
	 */
	public static function clean( array $raw, array &$errors, ?int $now = null ): ?array {
		$errors = [];
		$now    = $now ?? time();

		if ( self::SCHEMA !== ( $raw['schema'] ?? null ) ) {
			$errors[] = 'schema must be ' . self::SCHEMA;
		}

		$site = self::text( $raw['site'] ?? null, 253 );
		if ( '' === $site || ! preg_match( '/^[a-z0-9.-]+$/i', $site ) ) {
			$errors[] = 'site must be a host name';
		}

		$week = self::week( $raw['week'] ?? null, $errors );

		$generated = is_string( $raw['generated_at'] ?? null ) ? strtotime( $raw['generated_at'] ) : false;
		if ( false === $generated ) {
			$errors[] = 'generated_at must be a date-time';
		} elseif ( $generated > $now + 600 ) {
			$errors[] = 'generated_at is in the future';
		}
		if ( '' !== $week['start'] && strtotime( $week['start'] . ' 00:00:00 UTC' ) > $now + 7 * 86400 ) {
			$errors[] = 'week.start is in the future';
		}

		$enquiries = self::enquiries( $raw['enquiries'] ?? null, $errors );

		if ( [] !== $errors ) {
			return null;
		}

		return [
			'schema'       => self::SCHEMA,
			'site'         => strtolower( $site ),
			'week'         => $week,
			'generated_at' => (int) $generated,
			'enquiries'    => $enquiries,
			'note'         => self::note( $raw['note'] ?? null ),
			'gsc'          => self::gsc( $raw['gsc'] ?? null ),
			'ga4'          => self::ga4( $raw['ga4'] ?? null ),
			'ads'          => self::ads( $raw['ads'] ?? null ),
		];
	}

	/**
	 * The week: a Monday and the Sunday six days later.
	 *
	 * @param mixed             $week   Raw value.
	 * @param array<int,string> $errors Errors so far.
	 * @return array{start:string,end:string}
	 */
	protected static function week( $week, array &$errors ): array {
		$start = is_array( $week ) ? self::date( $week['start'] ?? null ) : '';
		$end   = is_array( $week ) ? self::date( $week['end'] ?? null ) : '';
		if ( '' === $start || '' === $end ) {
			$errors[] = 'week.start and week.end must be YYYY-MM-DD dates';
			return [
				'start' => '',
				'end'   => '',
			];
		}
		$monday = new \DateTimeImmutable( $start . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		if ( '1' !== $monday->format( 'N' ) ) {
			$errors[] = 'week.start must be a Monday';
		}
		if ( $monday->modify( '+6 days' )->format( 'Y-m-d' ) !== $end ) {
			$errors[] = 'week.end must be the Sunday after week.start';
		}

		return [
			'start' => $start,
			'end'   => $end,
		];
	}

	/**
	 * Enquiries: every source counted separately, plus the 12-week total trend.
	 *
	 * @param mixed             $raw    Raw value.
	 * @param array<int,string> $errors Errors so far.
	 * @return array{sources:array<int,array<string,mixed>>,series_12w:array<int,int>}
	 */
	protected static function enquiries( $raw, array &$errors ): array {
		$sources = [];
		$seen    = [];
		foreach ( self::rows( $raw['sources'] ?? null, self::MAX_SOURCES ) as $row ) {
			$key   = is_string( $row['key'] ?? null ) && preg_match( '/^[a-z][a-z0-9_]{0,19}$/', $row['key'] ) ? $row['key'] : '';
			$label = self::text( $row['label'] ?? null, 60 );
			$count = self::count( $row['count'] ?? null );
			if ( '' === $key || '' === $label || null === $count || isset( $seen[ $key ] ) ) {
				continue; // A malformed or repeated source is dropped, never guessed.
			}
			$seen[ $key ] = true;
			$sources[]    = [
				'key'      => $key,
				'label'    => $label,
				'count'    => $count,
				'previous' => self::count( $row['previous'] ?? null ),
			];
		}
		// An EMPTY list is allowed and means "enquiries are not being counted yet" (no tracking set up):
		// the report says exactly that instead of printing a 0 nobody measured. A list that was sent but
		// held only malformed rows is still refused.
		if ( [] === $sources && [] !== self::rows( $raw['sources'] ?? null, self::MAX_SOURCES ) ) {
			$errors[] = 'enquiries.sources must list sources with a key, label and count';
		}
		if ( ! is_array( $raw ) || ! is_array( $raw['sources'] ?? null ) ) {
			$errors[] = 'enquiries.sources must be a list (empty when enquiries are not counted yet)';
		}

		return [
			'sources'    => $sources,
			'series_12w' => self::series( $raw['series_12w'] ?? null ),
			// Where the counts came from, in the owner's words (e.g. "Calls are those Google Ads and your
			// Business Profile recorded"). Written by retainer-scan, because only it knows the sources.
			'note'       => self::text( $raw['note'] ?? null ),
		];
	}

	/**
	 * Andrew's note: what was done this week and what is next.
	 *
	 * @param mixed $raw Raw value.
	 * @return array{did:array<int,string>,next:array<int,string>,author:string,date:string}|null
	 */
	protected static function note( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$note = [
			'did'    => self::bullets( $raw['did'] ?? null ),
			'next'   => self::bullets( $raw['next'] ?? null ),
			'author' => self::text( $raw['author'] ?? null, 80 ),
			'date'   => self::date( $raw['date'] ?? null ),
		];

		return [] === $note['did'] && [] === $note['next'] ? null : $note;
	}

	/**
	 * Search Console: 12-week clicks and impressions, and the top searches.
	 *
	 * @param mixed $raw Raw value.
	 * @return array<string,mixed>|null
	 */
	protected static function gsc( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$queries = [];
		foreach ( self::rows( $raw['top_queries'] ?? null, self::MAX_ROWS ) as $row ) {
			$query  = self::text( $row['query'] ?? null, 120 );
			$clicks = self::count( $row['clicks'] ?? null );
			if ( '' === $query || null === $clicks ) {
				continue;
			}
			$queries[] = [
				'query'  => $query,
				'clicks' => $clicks,
				'change' => is_int( $row['change'] ?? null ) ? $row['change'] : null,
			];
		}

		$week = is_array( $raw['week'] ?? null ) ? $raw['week'] : [];

		return [
			'week'            => [
				'clicks'      => self::metric( $week['clicks'] ?? null, 100000000 ),
				'impressions' => self::metric( $week['impressions'] ?? null, 1000000000 ),
				'ctr'         => self::metric( $week['ctr'] ?? null, 100, true ),
				'position'    => self::metric( $week['position'] ?? null, 1000, true ),
			],
			'clicks_12w'      => self::series( $raw['clicks_12w'] ?? null ),
			'impressions_12w' => self::series( $raw['impressions_12w'] ?? null ),
			'top_queries'     => $queries,
		];
	}

	/**
	 * Analytics: the most-visited pages.
	 *
	 * @param mixed $raw Raw value.
	 * @return array<string,mixed>|null
	 */
	protected static function ga4( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$pages = [];
		foreach ( self::rows( $raw['top_pages'] ?? null, self::MAX_ROWS ) as $row ) {
			$title  = self::text( $row['title'] ?? null, 120 );
			$path   = is_string( $row['path'] ?? null ) && preg_match( '#^/[^\s<>"\']{0,200}$#', $row['path'] ) ? $row['path'] : '';
			$visits = self::count( $row['visits'] ?? null );
			if ( '' === $title || null === $visits ) {
				continue;
			}
			$pages[] = [
				'title'  => $title,
				'path'   => $path,
				'visits' => $visits,
			];
		}

		$week = is_array( $raw['week'] ?? null ) ? $raw['week'] : [];

		return [
			'week'      => [
				'visits'     => self::metric( $week['visits'] ?? null, 100000000 ),
				'key_events' => self::metric( $week['key_events'] ?? null, 100000000 ),
				'engaged'    => self::metric( $week['engaged'] ?? null, 100, true ),
			],
			'top_pages' => $pages,
		];
	}

	/**
	 * Google Ads for the week: spend against the weekly budget, clicks, and the enquiries Ads can see.
	 * Budget used and cost per enquiry are NOT sent: the report works them out, so they can never
	 * disagree with the figures they come from.
	 *
	 * @param mixed $raw Raw value.
	 * @return array<string,mixed>|null
	 */
	protected static function ads( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$currency = is_string( $raw['currency'] ?? null ) && preg_match( '/^[A-Z]{3}$/', $raw['currency'] ) ? $raw['currency'] : '';
		$budget   = $raw['budget'] ?? null;

		return [
			'currency'    => $currency,
			'budget'      => ( is_int( $budget ) || is_float( $budget ) ) && $budget > 0 && $budget <= 10000000 ? round( (float) $budget, 2 ) : null,
			'spend'       => self::metric( $raw['spend'] ?? null, 10000000, true ),
			'clicks'      => self::metric( $raw['clicks'] ?? null, 100000000 ),
			'conversions' => self::metric( $raw['conversions'] ?? null, 100000, true ), // Data-driven attribution gives fractions (3.5).
		];
	}

	/**
	 * One headline figure and last week's, as {value, previous}. Null when the value is missing — a
	 * figure the data does not support is left out, never shown as 0.
	 *
	 * @param mixed $raw     Raw {value, previous}.
	 * @param int   $max     Largest believable value.
	 * @param bool  $decimal Whether fractions are allowed (rates, positions, money).
	 * @return array{value:int|float,previous:int|float|null}|null
	 */
	protected static function metric( $raw, int $max, bool $decimal = false ): ?array {
		$number = static function ( $v ) use ( $max, $decimal ) {
			if ( is_int( $v ) && $v >= 0 && $v <= $max ) {
				return $v;
			}
			if ( $decimal && is_float( $v ) && $v >= 0 && $v <= $max ) {
				return round( $v, 2 );
			}
			return null;
		};
		$value  = is_array( $raw ) ? $number( $raw['value'] ?? null ) : null;
		if ( null === $value ) {
			return null;
		}

		return [
			'value'    => $value,
			'previous' => $number( $raw['previous'] ?? null ),
		];
	}

	/**
	 * Plain text: tags removed, whitespace collapsed, capped. '' when not a string.
	 *
	 * Does what wp_strip_all_tags() does — <script>/<style> blocks go with their contents, not just
	 * their tags — without calling WordPress, so this class stays testable on its own.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Most characters kept.
	 */
	public static function text( $value, int $max = self::MAX_TEXT ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $value );
		$text  = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- script/style contents removed on the line above, as wp_strip_all_tags() does.

		return mb_substr( $text, 0, $max );
	}

	/**
	 * A YYYY-MM-DD calendar date, or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	protected static function date( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}

	/**
	 * A whole number of things (0 or more), or null.
	 *
	 * @param mixed $value Raw value.
	 */
	protected static function count( $value ): ?int {
		return is_int( $value ) && $value >= 0 && $value <= 100000000 ? $value : null;
	}

	/**
	 * A trend line: up to MAX_SERIES counts, oldest first; any bad point empties it.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,int>
	 */
	protected static function series( $value ): array {
		if ( ! is_array( $value ) || ! self::is_list( $value ) ) {
			return [];
		}
		$points = array_slice( $value, -self::MAX_SERIES );
		foreach ( $points as $point ) {
			if ( null === self::count( $point ) ) {
				return []; // A line with a hole in it would draw a false dip.
			}
		}

		return $points;
	}

	/**
	 * Up to $max array rows from a list.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Most rows kept.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function rows( $value, int $max ): array {
		if ( ! is_array( $value ) || ! self::is_list( $value ) ) {
			return [];
		}

		return array_values( array_filter( array_slice( $value, 0, $max ), 'is_array' ) );
	}

	/**
	 * Whether an array is a plain list (keys 0, 1, 2 …). array_is_list() is PHP 8.1+; the plugin
	 * supports 8.0, so this is the same test written out.
	 *
	 * @param array<mixed> $value Array.
	 */
	protected static function is_list( array $value ): bool {
		return array_values( $value ) === $value;
	}

	/**
	 * Up to MAX_BULLETS non-empty text lines.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,string>
	 */
	protected static function bullets( $value ): array {
		if ( ! is_array( $value ) || ! self::is_list( $value ) ) {
			return [];
		}

		return array_values( array_filter( array_map( [ self::class, 'text' ], array_slice( $value, 0, self::MAX_BULLETS ) ) ) );
	}
}
