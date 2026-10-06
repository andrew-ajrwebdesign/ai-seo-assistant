<?php
/**
 * Auto_Types — obvious page types set automatically, everything else suggested (5.0, Andrew: "can you not
 * auto set this? it will remove a lot of seo issues" / "and give the option so this can be changed").
 *
 * AJR Core 0.22 judges each page (Page_Types::suggest_with_confidence()):
 *   high    a fact states it (the booking page, a blog post, a title that IS a service…): set here through
 *           Page_Types::set_auto(), logged in Changes (field page_type, user "Automatic") with Undo;
 *   medium  a word points to it: "Page type not set" stays an issue, and the page is in the scan's
 *           "Review and apply all" list;
 *   none    nothing points anywhere: the page is "other" and not an issue.
 *
 * ⛔ A person always wins. Any manual change (the list's dropdown, the review's "Change page type", the
 * bulk bar, AJR Core's own box) or an Undo marks the page manual in AJR Core, and set_auto() never touches a
 * manual page again. The Settings toggle turns auto-apply off site-wide.
 *
 * WHEN. After each scan's site-wide pass (Scanner::finalize()), and when a new page is published. AJR Core
 * checks edit_post, so a pass with no user (cron) cannot set a type: those pages wait in PENDING and are set
 * on the agency's next visit to the SEO scan screen (run_pending()).
 *
 * Older AJR Core (no suggest_with_confidence / set_auto): nothing is set automatically and every page with
 * no type is "not set", as before.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

use AJR\SEOAssistant\Changes\Change_Log;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic page types.
 */
class Auto_Types {

	/** Option: '1' (default) sets obvious page types automatically, '0' never. */
	public const OPTION = 'ai_seo_assistant_auto_page_types';

	/** Option: high-confidence types waiting for a user who may set them (post ID => type). Not autoloaded. */
	public const PENDING = 'ai_seo_assistant_auto_types_pending';

	/** Most waiting page types set on one screen load (run_pending()). */
	public const PENDING_BATCH = 100;

	/** The change log's field for a page type. */
	public const FIELD = 'page_type';

	/**
	 * Whether automatic types are on (and AJR Core can set them).
	 */
	public static function enabled(): bool {
		return Page_Role::can_auto() && '0' !== (string) get_option( self::OPTION, '1' );
	}

	/**
	 * Set these high-confidence types now where the current user may; the rest wait in PENDING.
	 *
	 * @param array<int,string> $types   Post ID => type.
	 * @param Change_Log|null   $log     Change log (tests pass their own).
	 * @param int               $max     Most pages set in this call (0: all); the rest wait in PENDING.
	 * @param float             $seconds Stop after this long (0: no limit); the rest wait in PENDING.
	 * @return array<int,string> The ones set now.
	 */
	public static function apply( array $types, ?Change_Log $log = null, int $max = 0, float $seconds = 0.0 ): array {
		if ( ! self::enabled() || [] === $types ) {
			return [];
		}
		$pending = self::pending();
		$done    = [];
		$batch   = 'auto-' . gmdate( 'YmdHis' );
		$log     = $log ?? new Change_Log();
		$until   = $seconds > 0 ? microtime( true ) + $seconds : 0.0;
		$tried   = 0;
		foreach ( $types as $id => $type ) {
			$id = (int) $id;
			if ( ( $max > 0 && $tried >= $max ) || ( $until > 0 && microtime( true ) > $until ) ) {
				$pending[ $id ] = (string) $type; // Out of time for this request: set on the next.
				continue;
			}
			++$tried;
			if ( '' !== Page_Role::type_of( $id ) || 'manual' === Page_Role::source( $id ) ) {
				unset( $pending[ $id ] ); // Already typed, or a person decided: never touched.
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$pending[ $id ] = (string) $type;
				continue;
			}
			// Logged first, as every change the plugin makes: a type the log cannot hold could not be undone,
			// so it is not set (and waits for the next pass). User 0: "Automatic".
			$row = $log->log( $batch, $id, Page_Data::path_of( (string) get_permalink( $id ) ), self::FIELD, 0, '', (string) $type, 0 );
			if ( 0 === $row ) {
				$pending[ $id ] = (string) $type;
				continue;
			}
			unset( $pending[ $id ] );
			if ( ! Page_Role::set_auto( $id, (string) $type ) ) {
				$log->discard( $row ); // Not set after all (AJR Core refused): nothing to undo.
				continue;
			}
			$done[ $id ] = (string) $type;
		}
		self::save_pending( $pending );
		if ( [] !== $done ) {
			Ranking::flush();
		}

		return $done;
	}

	/**
	 * Set the types that waited for a user (the SEO scan screen calls this for the agency).
	 *
	 * @return array<int,string> The ones set now.
	 */
	public static function run_pending(): array {
		$pending = self::pending();

		// On the agency's screen load: at most 100 pages or 5 seconds, the rest on the next load.
		return [] === $pending ? [] : self::apply( $pending, null, self::PENDING_BATCH, 5.0 );
	}

	/**
	 * Types waiting for a user.
	 *
	 * @return array<int,string>
	 */
	public static function pending(): array {
		$held = get_option( self::PENDING, [] );

		return is_array( $held ) ? array_map( 'strval', $held ) : [];
	}

	/**
	 * Store (or clear) the waiting types.
	 *
	 * @param array<int,string> $pending Post ID => type.
	 */
	protected static function save_pending( array $pending ): void {
		if ( [] === $pending ) {
			delete_option( self::PENDING );
			return;
		}
		update_option( self::PENDING, $pending, false );
	}

	/**
	 * A new page was published: set its type now when it is obvious (the publisher may edit it).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_publish( int $post_id ): void {
		if ( ! self::enabled() || '' !== Page_Role::type_of( $post_id ) ) {
			return;
		}
		$verdict = Page_Role::verdict( $post_id );
		if ( 'high' === $verdict['confidence'] && '' !== $verdict['type'] ) {
			self::apply( [ $post_id => $verdict['type'] ] );
		}
	}
}
