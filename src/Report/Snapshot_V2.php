<?php
/**
 * Snapshot_V2 — the 5.0 push: per-page search data, billing-month snapshots, labelled enquiry kinds.
 *
 * ⚠ ISOLATED ON PURPOSE. Every v2 rule lives in this one class and its test, matched to retainer-scan's
 * schema doc (toolkits/retainer-scan/schema/snapshot-v2.md, PR claude-workspace#144) and its two fixtures
 * (copied to tests/fixtures/). v1 (Snapshot) is untouched and still accepted: live sites hold up to 12
 * months of v1 weeks, and retainer-scan writes v2 only for sites switched to it.
 *
 * What v2 adds (decision 2026-10-06 "5.0 product cut"):
 * - `period` "week" (week {start Monday, end Sunday}) or "month" (month {start, end, billing_day, data_end,
 *   complete, compared_with}: the client's billing period, never a sum of weeks).
 * - `enquiries.sources[]` gain `kind` (call | form | booking | email | tap), `counted_by` and `in_total`.
 *   The total is the sum over `in_total: true`. A TAP is shown separately and never added (approved
 *   correction 1): a snapshot whose tap source claims `in_total` is REFUSED, so the error reaches
 *   retainer-scan instead of a wrong headline reaching the client. `history[]` replaces series_12w.
 * - `gsc.totals` / `gsc.series {unit, start, clicks, impressions}`, `ga4.totals`, `ads.calls` (which must
 *   equal the `ads_calls` source, else the snapshot is refused).
 * - `pages` { range, previous_range, series_start, cap, available, truncated, items[] } — per URL path,
 *   Search Console (13 weekly points, top 10 queries) and GA4 (landing visits, Google share, engaged rate,
 *   engagement time, enquiries by source key).
 *
 * Every Google-sourced string (queries, titles, paths) is untrusted: capped here and escaped on output.
 *
 * Output: for a week, v1's clean shape (so Report_View draws it unchanged) plus kinds; for a month, its own
 * shape (Month_View). Either way `pages` / `pages_range` come back normalised for Search\Page_Data, and the
 * store strips them off into their own table.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalises a schema 2 snapshot.
 */
class Snapshot_V2 extends Snapshot {

	/** This format's version. */
	public const SCHEMA_V2 = 2;

	/** Enquiry kinds. */
	public const KINDS = [ 'call', 'form', 'booking', 'email', 'tap' ];

	/** Pages kept from one push (the schema's cap). */
	public const MAX_PAGES = 500;

	/** Weekly points per page (13 weeks = the 91-day window). */
	public const MAX_PAGE_WEEKS = 13;

	/** Daily points in a month's line. */
	public const MAX_DAYS = 31;

	/** Periods in enquiries.history. */
	public const MAX_HISTORY = 12;

	/**
	 * Validate a decoded v2 snapshot.
	 *
	 * @param array<string,mixed> $raw    Decoded snapshot.
	 * @param array<int,string>   $errors Filled with the reasons it was refused.
	 * @param int|null            $now    Unix time.
	 * @return array<string,mixed>|null
	 */
	public static function clean_v2( array $raw, array &$errors, ?int $now = null ): ?array {
		$errors = [];
		$now    = $now ?? time();
		$period = $raw['period'] ?? null;
		if ( ! in_array( $period, [ 'week', 'month' ], true ) ) {
			$errors[] = 'period must be "week" or "month"';
			return null;
		}

		$site = self::text( $raw['site'] ?? null, 253 );
		if ( '' === $site || ! preg_match( '/^[a-z0-9.-]+$/i', $site ) ) {
			$errors[] = 'site must be a host name';
		}
		$generated = is_string( $raw['generated_at'] ?? null ) ? strtotime( $raw['generated_at'] ) : false;
		if ( false === $generated ) {
			$errors[] = 'generated_at must be a date-time';
		} elseif ( $generated > $now + 600 ) {
			$errors[] = 'generated_at is in the future';
		}

		$range = 'week' === $period ? self::week( $raw['week'] ?? null, $errors ) : self::month( $raw['month'] ?? null, $errors );
		if ( '' !== $range['start'] && strtotime( $range['start'] . ' 00:00:00 UTC' ) > $now + 7 * 86400 ) {
			$errors[] = $period . '.start is in the future';
		}

		$enquiries = self::enquiries_v2( $raw['enquiries'] ?? null, $errors );
		$ads       = self::ads_v2( $raw['ads'] ?? null, $enquiries['sources'], $errors );

		if ( [] !== $errors ) {
			return null;
		}

		$pages = self::pages( $raw['pages'] ?? null, $enquiries['sources'] );
		$clean = [
			'schema'       => self::SCHEMA_V2,
			'period'       => $period,
			'site'         => strtolower( $site ),
			$period        => $range,
			'generated_at' => (int) $generated,
			'billing_day'  => 'month' === $period ? $range['billing_day'] : null,
			'enquiries'    => $enquiries,
			'note'         => self::note( $raw['note'] ?? null ),
			'gsc'          => self::gsc_v2( $raw['gsc'] ?? null, $period ),
			'ga4'          => self::ga4_v2( $raw['ga4'] ?? null, $period ),
			'ads'          => $ads,
			'pages'        => $pages['items'],
			'pages_range'  => $pages['range'],
			'listing'      => self::listing( $raw['business_profile_check'] ?? null ),
		];
		if ( 'week' === $period ) {
			// v1's series key, from the in-total history, so the weekly report's 12-week bars draw as before.
			$clean['enquiries']['series_12w'] = array_column( $enquiries['history'], 'total' );
		}

		return $clean;
	}

	/**
	 * The billing month.
	 *
	 * @param mixed             $raw    Raw month.
	 * @param array<int,string> $errors Errors so far.
	 * @return array<string,mixed>
	 */
	protected static function month( $raw, array &$errors ): array {
		$start = is_array( $raw ) ? self::date( $raw['start'] ?? null ) : '';
		$end   = is_array( $raw ) ? self::date( $raw['end'] ?? null ) : '';
		$days  = '' === $start || '' === $end ? -1 : (int) ( ( strtotime( $end . ' UTC' ) - strtotime( $start . ' UTC' ) ) / 86400 );
		if ( $days < 26 || $days > 31 ) {
			$errors[] = 'month.start and month.end must be one billing period';
		}
		$day      = is_array( $raw ) && is_int( $raw['billing_day'] ?? null ) && $raw['billing_day'] >= 1 && $raw['billing_day'] <= 31 ? $raw['billing_day'] : null;
		$data_end = is_array( $raw ) ? self::date( $raw['data_end'] ?? null ) : '';
		$cw       = is_array( $raw['compared_with'] ?? null ) ? $raw['compared_with'] : [];

		return [
			'start'         => $start,
			'end'           => $end,
			'billing_day'   => $day ?? ( '' !== $start ? (int) substr( $start, 8, 2 ) : null ),
			'data_end'      => '' !== $data_end ? $data_end : $end,
			'complete'      => ! is_array( $raw ) || ! array_key_exists( 'complete', $raw ) || true === $raw['complete'],
			'compared_with' => [
				'start' => self::date( $cw['start'] ?? null ),
				'end'   => self::date( $cw['end'] ?? null ),
			],
		];
	}

	/**
	 * Enquiry sources with kind, counted_by and in_total; history of in-total totals.
	 *
	 * @param mixed             $raw    Raw block.
	 * @param array<int,string> $errors Errors so far.
	 * @return array<string,mixed>
	 */
	protected static function enquiries_v2( $raw, array &$errors ): array {
		$base = self::enquiries( $raw, $errors );
		$meta = [];
		foreach ( self::rows( is_array( $raw ) ? ( $raw['sources'] ?? null ) : null, self::MAX_SOURCES ) as $row ) {
			if ( ! is_string( $row['key'] ?? null ) ) {
				continue;
			}
			$kind = is_string( $row['kind'] ?? null ) && in_array( $row['kind'], self::KINDS, true ) ? $row['kind'] : '';
			$in   = true === ( $row['in_total'] ?? null );
			if ( 'tap' === $kind && $in ) {
				$errors[] = 'enquiries source "' . self::text( $row['key'], 20 ) . '" is a tap and cannot be in the total';
			}
			$meta[ $row['key'] ] = [
				'kind'       => '' !== $kind ? $kind : 'call',
				'counted_by' => self::text( $row['counted_by'] ?? null, 60 ),
				'in_total'   => 'tap' !== $kind && $in,
			];
		}
		foreach ( $base['sources'] as $i => $source ) {
			$base['sources'][ $i ] += $meta[ $source['key'] ] ?? [
				'kind'       => 'call',
				'counted_by' => '',
				'in_total'   => false,
			];
		}
		$history = [];
		foreach ( self::rows( is_array( $raw ) ? ( $raw['history'] ?? null ) : null, 100 ) as $row ) {
			$start = self::date( $row['start'] ?? null );
			$total = self::count( $row['total'] ?? null );
			if ( '' === $start || null === $total ) {
				$history = []; // A line with a hole in it would draw a false dip.
				break;
			}
			$history[] = [
				'start' => $start,
				'end'   => self::date( $row['end'] ?? null ),
				'total' => $total,
			];
		}
		$base['history'] = array_slice( $history, -self::MAX_HISTORY );
		unset( $base['series_12w'] );

		return $base;
	}

	/**
	 * Whether a source is added to the enquiry total. v1 sources (no in_total) always were.
	 *
	 * @param array<string,mixed> $source A clean source.
	 */
	public static function counts( array $source ): bool {
		return ! array_key_exists( 'in_total', $source ) || true === $source['in_total'];
	}

	/**
	 * Google Ads: v1's block plus `calls`, which must equal the ads_calls source.
	 *
	 * @param mixed                          $raw     Raw block.
	 * @param array<int,array<string,mixed>> $sources Clean sources.
	 * @param array<int,string>              $errors  Errors so far.
	 * @return array<string,mixed>|null
	 */
	protected static function ads_v2( $raw, array $sources, array &$errors ): ?array {
		$ads = self::ads( $raw );
		if ( null === $ads ) {
			return null;
		}
		$ads['calls'] = self::metric( $raw['calls'] ?? null, 100000 );
		$source       = null;
		foreach ( $sources as $s ) {
			if ( 'ads_calls' === $s['key'] ) {
				$source = $s;
			}
		}
		$sent  = null === $ads['calls'] ? null : $ads['calls']['value'];
		$count = null === $source ? null : $source['count'];
		if ( $sent !== $count ) {
			$errors[] = 'ads.calls must equal the ads_calls enquiry source';
		}

		return $ads;
	}

	/**
	 * Search Console, whole site.
	 *
	 * @param mixed  $raw    Raw block.
	 * @param string $period week | month.
	 * @return array<string,mixed>|null
	 */
	protected static function gsc_v2( $raw, string $period ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$totals = is_array( $raw['totals'] ?? null ) ? $raw['totals'] : [];
		$series = is_array( $raw['series'] ?? null ) ? $raw['series'] : [];
		$max    = 'week' === $period ? self::MAX_SERIES : self::MAX_DAYS;
		$clicks = self::points( $series['clicks'] ?? null, $max );
		$shown  = self::points( $series['impressions'] ?? null, $max );
		$base   = self::gsc( [ 'top_queries' => $raw['top_queries'] ?? [] ] );
		$out    = [
			'week'        => [
				'clicks'      => self::metric( $totals['clicks'] ?? null, 100000000 ),
				'impressions' => self::metric( $totals['impressions'] ?? null, 1000000000 ),
				'ctr'         => self::metric( $totals['ctr'] ?? null, 100, true ),
				'position'    => self::metric( $totals['position'] ?? null, 1000, true ),
			],
			'series_unit' => 'day' === ( $series['unit'] ?? '' ) ? 'day' : 'week',
			'series_from' => self::date( $series['start'] ?? null ),
			'top_queries' => $base['top_queries'],
		];
		// The weekly report reads v1's names; a month reads the same block as daily lines.
		$out['clicks_12w']      = $clicks;
		$out['impressions_12w'] = count( $shown ) === count( $clicks ) ? $shown : [];

		return $out;
	}

	/**
	 * Analytics, whole site (v1's block with `week` renamed `totals`).
	 *
	 * @param mixed  $raw    Raw block.
	 * @param string $period week | month.
	 * @return array<string,mixed>|null
	 */
	protected static function ga4_v2( $raw, string $period ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same shape for both periods.
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$raw['week'] = $raw['totals'] ?? null;

		return self::ga4( $raw );
	}

	/**
	 * Per-page data, normalised for Search\Page_Data and keyed by path.
	 *
	 * @param mixed                          $raw     Raw pages block.
	 * @param array<int,array<string,mixed>> $sources Clean enquiry sources (for in_total and labels).
	 * @return array{items:array<string,array<string,mixed>>,range:array<string,mixed>}
	 */
	protected static function pages( $raw, array $sources ): array {
		$empty = [
			'items' => [],
			'range' => [],
		];
		if ( ! is_array( $raw ) ) {
			return $empty;
		}
		$by_key = [];
		foreach ( $sources as $s ) {
			$by_key[ $s['key'] ] = $s;
		}
		$series_start = self::date( $raw['series_start'] ?? null );
		$items        = [];
		foreach ( self::rows( $raw['items'] ?? null, self::MAX_PAGES ) as $row ) {
			$path = self::path( $row['path'] ?? null );
			$key  = '/' === $path ? '/' : rtrim( $path, '/' );
			if ( '' === $path || isset( $items[ $key ] ) ) {
				continue;
			}
			// The address Search Console reported: the live domain, even on a local copy, so it is kept for
			// reference only (https, capped) and never printed as a link; pages are matched by path.
			$url = is_string( $row['url'] ?? null ) && preg_match( '#^https://[a-z0-9.-]+(/[^\s<>"\']{0,200})?$#i', $row['url'] ) ? $row['url'] : '';
			$items[ $key ] = [
				'path' => $path,
				'url'  => $url,
				'gsc'  => self::page_gsc( $row['gsc'] ?? null, $series_start ),
				'ga4'  => self::page_ga4( $row['ga4'] ?? null, $by_key ),
			];
		}
		$out = [];
		foreach ( $items as $item ) {
			$out[ $item['path'] ] = $item;
		}
		$range = is_array( $raw['range'] ?? null ) ? $raw['range'] : [];
		$prev  = is_array( $raw['previous_range'] ?? null ) ? $raw['previous_range'] : [];

		return [
			'items' => $out,
			'range' => [
				'start'          => self::date( $range['start'] ?? null ),
				'end'            => self::date( $range['end'] ?? null ),
				'previous_start' => self::date( $prev['start'] ?? null ),
				'previous_end'   => self::date( $prev['end'] ?? null ),
				'available'      => self::count( $raw['available'] ?? null ) ?? count( $out ),
				'truncated'      => true === ( $raw['truncated'] ?? false ),
			],
		];
	}

	/**
	 * One page's Search Console block.
	 *
	 * @param mixed  $raw          Raw block (null: Google did not show the page).
	 * @param string $series_start Monday the weekly series starts.
	 * @return array<string,mixed>|null
	 */
	protected static function page_gsc( $raw, string $series_start ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$clicks = self::metric( $raw['clicks'] ?? null, 100000000 );
		$shown  = self::metric( $raw['impressions'] ?? null, 1000000000 );
		$ctr    = self::metric( $raw['ctr'] ?? null, 100, true );
		$pos    = self::metric( $raw['position'] ?? null, 1000, true );
		if ( null === $clicks || null === $shown ) {
			return null;
		}
		$series = is_array( $raw['series'] ?? null ) ? $raw['series'] : [];
		$sc     = self::points( $series['clicks'] ?? null, self::MAX_PAGE_WEEKS );
		$si     = self::points( $series['impressions'] ?? null, self::MAX_PAGE_WEEKS );
		$sp     = is_array( $series['position'] ?? null ) ? array_values( $series['position'] ) : [];
		$weeks  = [];
		if ( '' !== $series_start && count( $sc ) === count( $si ) ) {
			$monday = strtotime( $series_start . ' 00:00:00 UTC' );
			foreach ( $sc as $i => $c ) {
				$weeks[] = [
					'start'       => gmdate( 'Y-m-d', $monday + $i * 7 * 86400 ),
					'clicks'      => $c,
					'impressions' => $si[ $i ],
					'position'    => self::number( $sp[ $i ] ?? null, 1000 ),
				];
			}
		}
		$queries = [];
		foreach ( self::rows( $raw['top_queries'] ?? null, self::MAX_ROWS ) as $q ) {
			$query = self::text( $q['query'] ?? null, 120 );
			$c     = self::count( $q['clicks'] ?? null );
			if ( '' === $query || null === $c ) {
				continue;
			}
			$queries[] = [
				'query'       => $query,
				'clicks'      => $c,
				'impressions' => self::count( $q['impressions'] ?? null ) ?? 0,
				'ctr'         => self::number( $q['ctr'] ?? null, 100 ),
				'position'    => self::number( $q['position'] ?? null, 1000 ),
				'change'      => is_int( $q['change'] ?? null ) ? $q['change'] : null,
			];
		}

		return [
			'clicks'        => $clicks['value'],
			'impressions'   => $shown['value'],
			'ctr'           => null === $ctr ? null : (float) $ctr['value'],
			'position'      => null === $pos ? null : (float) $pos['value'],
			'prev_clicks'   => $clicks['previous'],
			'prev_shown'    => $shown['previous'],
			'prev_position' => null === $pos || null === $pos['previous'] ? null : (float) $pos['previous'],
			'queries_total' => self::count( $raw['query_count'] ?? null ),
			'weeks'         => $weeks,
			'queries'       => $queries,
		];
	}

	/**
	 * One page's GA4 block: visits, Google share, engagement, and enquiries started here (taps apart).
	 *
	 * @param mixed                              $raw    Raw block.
	 * @param array<string,array<string,mixed>> $by_key Sources by key.
	 * @return array<string,mixed>|null
	 */
	protected static function page_ga4( $raw, array $by_key ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$visits  = self::count( $raw['visits'] ?? null );
		$organic = self::count( $raw['google_organic_visits'] ?? null );
		$total   = 0;
		$taps    = 0;
		$parts   = [];
		foreach ( self::rows( $raw['enquiries'] ?? null, self::MAX_SOURCES ) as $e ) {
			$key   = is_string( $e['key'] ?? null ) ? $e['key'] : '';
			$count = self::count( $e['count'] ?? null );
			if ( '' === $key || null === $count || 0 === $count || ! isset( $by_key[ $key ] ) ) {
				continue;
			}
			$source = $by_key[ $key ];
			if ( self::counts( $source ) ) {
				$total += $count;
			} else {
				$taps += $count;
			}
			$parts[] = $count . ' ' . mb_strtolower( $source['label'] );
		}

		return [
			'visits'       => $visits,
			'engaged'      => self::number( $raw['engaged'] ?? null, 100 ),
			'engaged_secs' => self::count( $raw['engagement_time'] ?? null ),
			'search_share' => null !== $visits && $visits > 0 && null !== $organic ? round( $organic / $visits * 100, 1 ) : null,
			'enquiries'    => $total,
			'taps'         => $taps,
			'enquiry_note' => mb_substr( implode( ', ', $parts ), 0, 160 ),
		];
	}

	/**
	 * The Google Business Profile check (Google's listing against the site's own business details), or
	 * null when the push did not carry one. `checked: false` is kept as such: the scan then says "not
	 * checked", never "all match". Every value is Google- or site-sourced text: capped here, escaped on output.
	 *
	 * @param mixed $raw Raw block.
	 * @return array<string,mixed>|null
	 */
	protected static function listing( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$statuses = [ 'match', 'mismatch', 'missing_on_site', 'missing_on_google', 'not_compared' ];
		$levels   = [ 'info', 'low', 'medium', 'high' ];
		$fields   = [];
		foreach ( self::rows( $raw['fields'] ?? null, 40 ) as $f ) {
			$status = is_string( $f['status'] ?? null ) && in_array( $f['status'], $statuses, true ) ? $f['status'] : '';
			$field  = self::text( $f['field'] ?? null, 40 );
			if ( '' === $status || '' === $field ) {
				continue;
			}
			$fix      = is_array( $f['fix'] ?? null ) ? $f['fix'] : null;
			$where    = null === $fix ? '' : ( in_array( $fix['where'] ?? '', [ 'site', 'google', 'site_or_google' ], true ) ? $fix['where'] : '' );
			$fields[] = [
				'field'    => $field,
				'status'   => $status,
				'severity' => is_string( $f['severity'] ?? null ) && in_array( $f['severity'], $levels, true ) ? $f['severity'] : 'info',
				'site'     => self::text( is_scalar( $f['site'] ?? null ) ? (string) $f['site'] : null, 200 ),
				'google'   => self::text( is_scalar( $f['google'] ?? null ) ? (string) $f['google'] : null, 200 ),
				'message'  => self::text( $f['message'] ?? null, 200 ),
				'detail'   => self::text( $f['detail'] ?? null, 400 ),
				'fix'      => null === $fix ? null : [
					'where'  => $where,
					'site'   => self::text( $fix['site'] ?? null, 200 ),
					'google' => self::text( $fix['google'] ?? null, 200 ),
				],
			];
		}
		$google = is_array( $raw['google'] ?? null ) ? $raw['google'] : [];
		$maps   = is_string( $raw['maps_url'] ?? null ) && preg_match( '#^https://(www\.)?google\.[a-z.]+/maps[^\s<>"\']{0,400}$|^https://maps\.google\.[a-z.]+/[^\s<>"\']{0,400}$#', $raw['maps_url'] ) ? $raw['maps_url'] : '';

		return [
			'checked'    => true === ( $raw['checked'] ?? false ),
			'checked_at' => is_string( $raw['checked_at'] ?? null ) ? (int) strtotime( $raw['checked_at'] ) : 0,
			'maps_url'   => $maps,
			'google'     => [
				'name'   => self::text( $google['name'] ?? null, 120 ),
				'phone'  => self::text( $google['phone'] ?? null, 40 ),
				'status' => self::text( $google['status'] ?? null, 40 ),
			],
			'fields'     => $fields,
			'problems'   => count( array_filter( $fields, static fn( $f ) => in_array( $f['status'], [ 'mismatch', 'missing_on_site', 'missing_on_google' ], true ) && 'info' !== $f['severity'] ) ),
		];
	}

	/**
	 * A site-relative path ("/water-heaters/"), as v1 validates paths; '' when it fails.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function path( $value ): string {
		return is_string( $value ) && preg_match( '#^/[^\s<>"\']{0,200}$#', $value ) ? $value : '';
	}

	/**
	 * A non-negative number up to $max (int or float), rounded to 2 places, or null.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Largest allowed.
	 */
	protected static function number( $value, int $max ): ?float {
		return ( is_int( $value ) || is_float( $value ) ) && $value >= 0 && $value <= $max ? round( (float) $value, 2 ) : null;
	}

	/**
	 * A series of counts: up to $max, oldest first; any bad point empties it.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Most points.
	 * @return array<int,int>
	 */
	protected static function points( $value, int $max ): array {
		if ( ! is_array( $value ) || ! self::is_list( $value ) ) {
			return [];
		}
		$points = array_slice( $value, -$max );
		foreach ( $points as $point ) {
			if ( null === self::count( $point ) ) {
				return [];
			}
		}

		return $points;
	}
}
