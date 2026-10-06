<?php
/**
 * Page_Role — what a page is for (money, location, info, unclassified), which sets how much its clicks are
 * worth in the opportunity score (Opportunity::ROLE_VALUE).
 *
 * DEFAULTS. A post (post type 'post') is information. A page is a money page when AJR Core's business
 * profile names it as the booking page (business.booking_url), when it holds a Gravity Forms form (contact,
 * home-value and quote pages), or when the main menu links to it at the top level (except the blog index).
 * Everything else is unclassified. The agency can set any page's role on the SEO scan list or the review
 * header; a set role (post meta `_aisa_page_role`) always wins. The role is an agency tool: it is never shown
 * on the client's Report, and the meta key starts with an underscore so the editor's custom fields hide it.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

use AJR\SEOAssistant\Content\Business;

defined( 'ABSPATH' ) || exit;

/**
 * Page roles: defaults and the agency's choice.
 */
class Page_Role {

	/** Post meta holding a role the agency set. */
	public const META = '_aisa_page_role';

	/** Menu locations tried, in order, for "the main menu". */
	public const MENU_LOCATIONS = [ 'primary', 'primary-menu', 'main', 'main-menu', 'header', 'header-menu', 'menu-1' ];

	/**
	 * Role labels.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return [
			'money'        => __( 'Money', 'ai-seo-assistant' ),
			'location'     => __( 'Location', 'ai-seo-assistant' ),
			'info'         => __( 'Info', 'ai-seo-assistant' ),
			'unclassified' => __( 'Unclassified', 'ai-seo-assistant' ),
		];
	}

	/**
	 * Every listed page's role: the set one, else the default.
	 *
	 * @param array<int,string> $types Post type by post ID.
	 * @return array<int,array{role:string,set:bool}>
	 */
	public static function for_posts( array $types ): array {
		$ids = array_map( 'intval', array_keys( $types ) );
		if ( [] === $ids ) {
			return [];
		}
		update_meta_cache( 'post', $ids );
		$money = self::money_ids( $ids );
		$out   = [];
		foreach ( $types as $id => $type ) {
			$set = (string) get_post_meta( (int) $id, self::META, true );
			if ( isset( Opportunity::ROLE_VALUE[ $set ] ) ) {
				$out[ $id ] = [
					'role' => $set,
					'set'  => true,
				];
				continue;
			}
			$out[ $id ] = [
				'role' => self::default_role( (string) $type, isset( $money[ $id ] ) ),
				'set'  => false,
			];
		}

		return $out;
	}

	/**
	 * The default role.
	 *
	 * @param string $type  Post type.
	 * @param bool   $money Named as a money page (booking, form, top-level menu).
	 */
	public static function default_role( string $type, bool $money ): string {
		if ( 'post' === $type ) {
			return 'info';
		}

		return $money ? 'money' : 'unclassified';
	}

	/**
	 * Set (or, with 'auto', clear) a page's role.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $role    Role key or 'auto'.
	 */
	public static function set( int $post_id, string $role ): bool {
		if ( 'auto' === $role ) {
			delete_post_meta( $post_id, self::META );
			return true;
		}
		if ( ! isset( Opportunity::ROLE_VALUE[ $role ] ) ) {
			return false;
		}
		update_post_meta( $post_id, self::META, $role );

		return true;
	}

	/**
	 * Of these posts, the ones named as money pages.
	 *
	 * @param array<int,int> $ids Post IDs.
	 * @return array<int,true>
	 */
	protected static function money_ids( array $ids ): array {
		global $wpdb;
		$money = [];

		$booking = Business::facts()['booking'] ?? '';
		if ( '' !== $booking ) {
			$id = url_to_postid( $booking );
			if ( $id > 0 ) {
				$money[ $id ] = true;
			}
		}

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only; agency screen.
			foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in}) AND ( post_content LIKE '%[gravityform%' OR post_content LIKE '%<!-- wp:gravityforms/form%' )" ) as $id ) {
				$money[ (int) $id ] = true;
			}
		}

		$blog = (int) get_option( 'page_for_posts' );
		foreach ( self::menu_top_ids() as $id ) {
			if ( $id !== $blog ) {
				$money[ $id ] = true;
			}
		}

		return $money;
	}

	/**
	 * Posts the main menu links to at its top level.
	 *
	 * @return array<int,int>
	 */
	protected static function menu_top_ids(): array {
		$locations = (array) get_nav_menu_locations();
		$menu      = 0;
		foreach ( self::MENU_LOCATIONS as $location ) {
			if ( ! empty( $locations[ $location ] ) ) {
				$menu = (int) $locations[ $location ];
				break;
			}
		}
		if ( 0 === $menu && [] !== $locations ) {
			$menu = (int) reset( $locations );
		}
		if ( 0 === $menu ) {
			return [];
		}
		$out = [];
		foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
			if ( is_object( $item ) && 0 === (int) $item->menu_item_parent && 'post_type' === $item->type ) {
				$out[] = (int) $item->object_id;
			}
		}

		return $out;
	}
}
