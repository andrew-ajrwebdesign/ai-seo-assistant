<?php
/**
 * Page_Role — what a page is for (money, location, info, unclassified), which sets how much its clicks are
 * worth in the opportunity score (Opportunity::ROLE_VALUE).
 *
 * DEFAULTS. A post (post type 'post') is information. A page is a money page when AJR Core's business
 * profile names it as the booking page (business.booking_url), when it holds a form (any form AJR Core's
 * Leads module counts: Gravity Forms, WPForms, Contact Form 7, Fluent Forms, AJR Forms; also Ninja Forms,
 * Formidable and Divi's contact form) or an embed from a known form or booking service (Calendly, HubSpot
 * forms, Typeform…, plus AJR Core's google.booking_domains), or when the main menu links to it at the top
 * level (except the blog index).
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

	/**
	 * Form plugins whose forms AJR Core's Leads module counts (Gravity Forms, WPForms, Contact Form 7, Fluent
	 * Forms, AJR Forms) plus Ninja Forms, Formidable and Divi's contact form: shortcode names and block names.
	 *
	 * @var array<int,string>
	 */
	public const FORM_MARKERS = [
		'[gravityform',
		'[wpforms',
		'[contact-form-7',
		'[fluentform',
		'[ninja_form',
		'[formidable',
		'[et_pb_contact_form',
		'<!-- wp:gravityforms/',
		'<!-- wp:wpforms/',
		'<!-- wp:contact-form-7/',
		'<!-- wp:fluentfom/',
		'<!-- wp:fluentform/',
		'<!-- wp:ninja-forms/',
		'<!-- wp:formidable/',
		'<!-- wp:ajr-forms/form',
	];

	/**
	 * Form and booking services whose embed (iframe, script or link) makes a page a money page, on top of
	 * the booking hosts set in AJR Core (google.booking_domains).
	 *
	 * @var array<int,string>
	 */
	public const SERVICE_HOSTS = [
		'calendly.com',
		'hsforms.net',
		'hsforms.com',
		'meetings.hubspot.com',
		'typeform.com',
		'jotform.com',
		'acuityscheduling.com',
		'as.me',
		'cal.com',
		'tidycal.com',
		'setmore.com',
		'youcanbook.me',
		'squareup.com',
		'formstack.com',
		'cognitoforms.com',
		'paperform.co',
		'tally.so',
	];

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

		$hosts = array_merge( self::SERVICE_HOSTS, self::booking_domains() );
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only; agency screen, once per request.
			foreach ( (array) $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ({$in})", ARRAY_A ) as $row ) {
				if ( self::holds_form( (string) $row['post_content'], $hosts ) ) {
					$money[ (int) $row['ID'] ] = true;
				}
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
	 * Whether content holds a form or a booking embed: a known form plugin's shortcode or block, or an
	 * iframe, script or link to a known form or booking service (or a subdomain of one).
	 *
	 * @param string            $content Post content.
	 * @param array<int,string> $hosts   Service hosts (bare, lower-case).
	 */
	public static function holds_form( string $content, array $hosts ): bool {
		$lower = strtolower( $content );
		foreach ( self::FORM_MARKERS as $marker ) {
			if ( false !== strpos( $lower, $marker ) ) {
				return true;
			}
		}
		if ( ! preg_match_all( '#(?:src|href|data-url)\s*=\s*["\']?(?:https?:)?//([a-z0-9.-]+)#', $lower, $m ) ) {
			return false;
		}
		foreach ( array_unique( $m[1] ) as $host ) {
			foreach ( $hosts as $service ) {
				$service = strtolower( trim( (string) $service ) );
				if ( '' !== $service && ( $host === $service || str_ends_with( $host, '.' . $service ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The booking hosts AJR Core counts as leads (google.booking_domains, through its own filter).
	 *
	 * @return array<int,string>
	 */
	protected static function booking_domains(): array {
		$config = 'AJR\Core\Framework\Config';
		if ( ! class_exists( $config ) ) {
			return [];
		}
		$raw   = preg_split( '/[\r\n,]+/', (string) $config::get( 'google.booking_domains', '' ) );
		$hosts = [];
		foreach ( is_array( $raw ) ? $raw : [] as $line ) {
			$host = (string) preg_replace( '#^[a-z]+://|[/?\#].*$#', '', strtolower( trim( $line ) ) );
			if ( preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $host ) ) {
				$hosts[] = $host;
			}
		}

		/** This filter is documented in AJR Core's src/Google/Leads.php. */
		return array_map( 'strval', (array) apply_filters( 'ajr_core_booking_domains', $hosts ) );
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
