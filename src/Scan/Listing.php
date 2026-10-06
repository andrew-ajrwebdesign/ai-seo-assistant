<?php
/**
 * Listing — the "Google listing" issue group: the pushed Business Profile check (business_profile_check),
 * read against AJR Core's pins.
 *
 * Google's listing is the source of truth for the business details (decision 2026-10-06). A difference the
 * agency pinned in AJR Core → Business details (contract 4: `\AJR\Core\Business\Pins`) is "Kept on purpose":
 * shown with its reason, never counted as an issue. A check that could not run says "Google listing not
 * checked", never "all match".
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

use AJR\SEOAssistant\Report\Snapshot_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The Google listing group.
 */
class Listing {

	/** AJR Core's pins (contract 4). */
	public const PINS = 'AJR\Core\Business\Pins';

	/** AJR Core's Business details screen slug (0.22). */
	public const CORE_SCREEN = 'ajr-core-business-details';

	/**
	 * AJR Core's pins: field => { reason, pinned_at, user_id }. [] without Core 0.22.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function pins(): array {
		if ( ! class_exists( self::PINS ) || ! method_exists( self::PINS, 'all' ) ) {
			return [];
		}
		$pins = self::PINS;
		$all  = $pins::all();

		return is_array( $all ) ? $all : [];
	}

	/**
	 * The group: state, issues (counted), kept (pinned) and the check's facts.
	 *
	 * @param array<string,mixed>|null          $listing Snapshot_Store::listing().
	 * @param array<string,array<string,mixed>> $pins    pins().
	 * @return array{state:string,issues:array<int,array<string,mixed>>,kept:array<int,array<string,mixed>>,listing:array<string,mixed>|null}
	 */
	public static function group( ?array $listing, array $pins ): array {
		$out = [
			'state'   => 'none',
			'issues'  => [],
			'kept'    => [],
			'listing' => $listing,
		];
		if ( null === $listing ) {
			return $out;
		}
		if ( empty( $listing['checked'] ) ) {
			$out['state'] = 'not_checked';
			return $out;
		}
		$out['state'] = 'checked';
		foreach ( (array) $listing['fields'] as $f ) {
			if ( in_array( $f['status'] ?? '', [ 'match', 'not_compared' ], true ) || 'info' === ( $f['severity'] ?? 'info' ) ) {
				continue;
			}
			if ( isset( $pins[ $f['field'] ] ) ) {
				$out['kept'][] = $f + [ 'pin' => (array) $pins[ $f['field'] ] ];
			} else {
				$out['issues'][] = $f;
			}
		}

		return $out;
	}

	/**
	 * The group for this site now.
	 *
	 * @return array{state:string,issues:array<int,array<string,mixed>>,kept:array<int,array<string,mixed>>,listing:array<string,mixed>|null}
	 */
	public static function current(): array {
		return self::group( Snapshot_Store::listing(), self::pins() );
	}

	/**
	 * Link to AJR Core → Business details ('' before Core 0.22 has the screen).
	 */
	public static function core_url(): string {
		if ( ! class_exists( self::PINS ) ) {
			return '';
		}

		return admin_url( 'admin.php?page=' . self::CORE_SCREEN );
	}
}
