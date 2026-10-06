<?php
/**
 * Page_Role — what a page is for, which sets how much its clicks are worth in the opportunity score
 * (Opportunity::ROLE_VALUE: money 1.5, location 1.2, unclassified 1.0, info 0.6).
 *
 * 5.0 (decision 2026-10-06, round 2): the role comes from the page type AJR Core keeps (post meta
 * `_ajr_page_type`, `\AJR\Core\Schema\Page_Types`), the same setting that decides the page's schema, so the
 * agency sets one thing per page, in one place:
 *
 *   service, contact            → money
 *   area                        → location
 *   article, faq, team_member   → info
 *   other                       → unclassified
 *
 * NOT SET. A post is information; so is a page that is mainly a list of posts (a Divi blog module or a core
 * Query Loop: "Blog and Beyond" is not a money page). A page is money when AJR Core names it as the booking
 * page (business.booking_url), when it holds a form (any form AJR Core's Leads module counts: Gravity Forms,
 * WPForms, Contact Form 7, Fluent Forms, AJR Forms; also Ninja Forms, Formidable and Divi's contact form)
 * or an embed from a known form or booking service (Calendly, HubSpot forms, Typeform…, plus AJR Core's
 * google.booking_domains), or when the main menu links to it at the top level. Everything else is
 * unclassified.
 *
 * WITHOUT AJR CORE 0.22 (no Page_Types), the defaults above apply and the page type controls are hidden.
 * The 4.x/5.0-beta role meta `_aisa_page_role` is migrated into page types once Core has them (migrate()).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

use AJR\SEOAssistant\Content\Business;

defined( 'ABSPATH' ) || exit;

/**
 * Page roles from AJR Core page types, with defaults.
 */
class Page_Role {

	/** AJR Core's page types (contract 2). */
	public const CORE = 'AJR\Core\Schema\Page_Types';

	/** The earlier AISA role meta, migrated into page types and deleted. */
	public const LEGACY_META = '_aisa_page_role';

	/** Option: post IDs whose old role was "money", left without a page type for the agency to choose. */
	public const NOTES_OPTION = 'ai_seo_assistant_role_notes';

	/**
	 * Role by AJR Core page type.
	 *
	 * @var array<string,string>
	 */
	public const TYPE_ROLE = [
		'service'     => 'money',
		'contact'     => 'money',
		'area'        => 'location',
		'article'     => 'info',
		'faq'         => 'info',
		'team_member' => 'info',
		'other'       => 'unclassified',
	];

	/**
	 * Markers of a page that is mainly a list of posts.
	 *
	 * @var array<int,string>
	 */
	public const LISTING_MARKERS = [ '[et_pb_blog', '[et_pb_post_slider', '<!-- wp:query ', '<!-- wp:query-loop', '<!-- wp:latest-posts' ];

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
	 * Whether AJR Core's page types are there (0.22+).
	 */
	public static function core(): bool {
		return class_exists( self::CORE ) && method_exists( self::CORE, 'get' ) && method_exists( self::CORE, 'set' ) && method_exists( self::CORE, 'types' );
	}

	/**
	 * Page type labels: AJR Core's, else plain fallbacks (for display only).
	 *
	 * @return array<string,array{label:string,reads:string,description:string}>
	 */
	public static function types(): array {
		$out = [];
		if ( self::core() ) {
			$core = self::CORE;
			foreach ( (array) $core::types() as $slug => $t ) {
				$t            = array_values( (array) $t );
				$out[ $slug ] = [
					'label'       => (string) ( $t[0] ?? $slug ),
					'reads'       => (string) ( $t[1] ?? '' ),
					'description' => (string) ( $t[2] ?? '' ),
				];
			}
			if ( [] !== $out ) {
				return $out;
			}
		}
		$fallback = [
			'service'     => [ __( 'Service page', 'ai-seo-assistant' ), __( 'the service, the area you cover, your business', 'ai-seo-assistant' ) ],
			'area'        => [ __( 'Area / location page', 'ai-seo-assistant' ), __( 'the town or area, and your business', 'ai-seo-assistant' ) ],
			'article'     => [ __( 'Article', 'ai-seo-assistant' ), __( 'headline, author and date', 'ai-seo-assistant' ) ],
			'faq'         => [ __( 'FAQ page', 'ai-seo-assistant' ), __( 'the questions and answers', 'ai-seo-assistant' ) ],
			'contact'     => [ __( 'Contact / booking page', 'ai-seo-assistant' ), __( 'how to reach or book you', 'ai-seo-assistant' ) ],
			'team_member' => [ __( 'Team member', 'ai-seo-assistant' ), __( 'the person, their role, your business', 'ai-seo-assistant' ) ],
			'other'       => [ __( 'Other', 'ai-seo-assistant' ), __( 'just the page and your business', 'ai-seo-assistant' ) ],
		];
		foreach ( $fallback as $slug => $t ) {
			$out[ $slug ] = [
				'label'       => $t[0],
				'reads'       => $t[1],
				'description' => '',
			];
		}

		return $out;
	}

	/**
	 * A page's AJR Core page type ('' when not set or without Core).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function type_of( int $post_id ): string {
		if ( ! self::core() ) {
			return '';
		}
		$core = self::CORE;

		return (string) $core::get( $post_id );
	}

	/**
	 * AJR Core's suggested page type ('' when none or without Core).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function suggest( int $post_id ): string {
		if ( ! self::core() || ! method_exists( self::CORE, 'suggest' ) ) {
			return '';
		}
		$core = self::CORE;

		return (string) $core::suggest( $post_id );
	}

	/**
	 * Set a page type through AJR Core ('' clears it). Core checks edit_post and validates.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Page type slug, or ''.
	 */
	public static function set_type( int $post_id, string $type ): bool {
		if ( ! self::core() ) {
			return false;
		}
		$core = self::CORE;
		$done = (bool) $core::set( $post_id, $type );
		if ( $done ) {
			Ranking::flush();
		}

		return $done;
	}

	/**
	 * The role a page type gives ('' for no type: defaults apply).
	 *
	 * @param string $type Page type slug.
	 */
	public static function role_of_type( string $type ): string {
		return self::TYPE_ROLE[ $type ] ?? ( '' === $type ? '' : 'unclassified' );
	}

	/**
	 * Every listed page's role: from its page type, else the default.
	 *
	 * @param array<int,string> $types Post type by post ID.
	 * @return array<int,array{role:string,set:bool,type:string}>
	 */
	public static function for_posts( array $types ): array {
		$ids = array_map( 'intval', array_keys( $types ) );
		if ( [] === $ids ) {
			return [];
		}
		update_meta_cache( 'post', $ids );
		[ $money, $listing ] = self::detect( $ids );
		$out                 = [];
		foreach ( $types as $id => $type ) {
			$page_type  = self::type_of( (int) $id );
			$role       = self::role_of_type( $page_type );
			$out[ $id ] = [
				'role' => '' !== $role ? $role : self::default_role( (string) $type, isset( $money[ $id ] ), isset( $listing[ $id ] ) ),
				'set'  => '' !== $page_type,
				'type' => $page_type,
			];
		}

		return $out;
	}

	/**
	 * The default role for a page without a page type.
	 *
	 * @param string $type    Post type.
	 * @param bool   $money   Named as a money page (booking, form, top-level menu).
	 * @param bool   $listing Mainly a list of posts.
	 */
	public static function default_role( string $type, bool $money, bool $listing = false ): string {
		if ( 'post' === $type || $listing ) {
			return 'info';
		}

		return $money ? 'money' : 'unclassified';
	}

	/**
	 * Once AJR Core has page types: move the old AISA roles into them and delete the old meta. location →
	 * area, info → article; money is left unset (service or contact is the agency's call: noted for them);
	 * unclassified was the default anyway. A page that already has a type keeps it.
	 *
	 * @return array{moved:int,noted:int,dropped:int,failed:int}|null Null when there is nothing to do or no Core.
	 */
	public static function migrate(): ?array {
		global $wpdb;
		if ( ! self::core() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off migration, admin only.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::LEGACY_META ), ARRAY_A );
		if ( [] === $rows ) {
			return null;
		}
		$map   = [
			'location' => 'area',
			'info'     => 'article',
		];
		$out   = [
			'moved'   => 0,
			'noted'   => 0,
			'dropped' => 0,
			'failed'  => 0,
		];
		$notes = (array) get_option( self::NOTES_OPTION, [] );
		$core  = self::CORE;
		foreach ( $rows as $row ) {
			$id   = (int) $row['post_id'];
			$old  = (string) $row['meta_value'];
			$type = (string) $core::get( $id );
			if ( isset( $map[ $old ] ) && '' === $type ) {
				// Page_Types::set() checks edit_post and validates: a refusal keeps the old role for a retry.
				if ( ! $core::set( $id, $map[ $old ] ) ) {
					++$out['failed'];
					continue;
				}
				++$out['moved'];
			} elseif ( 'money' === $old && '' === $type ) {
				$notes[] = $id;
				++$out['noted'];
			} else {
				++$out['dropped']; // Already typed, or unclassified (the default anyway).
			}
			delete_post_meta( $id, self::LEGACY_META );
		}
		update_option( self::NOTES_OPTION, array_values( array_unique( array_map( 'intval', $notes ) ) ), false );
		Ranking::flush();

		return $out;
	}

	/**
	 * Of these posts, the ones named as money pages and the ones that are mainly a list of posts.
	 *
	 * @param array<int,int> $ids Post IDs.
	 * @return array{0:array<int,true>,1:array<int,true>}
	 */
	protected static function detect( array $ids ): array {
		global $wpdb;
		$money   = [];
		$listing = [];

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
				$content = (string) $row['post_content'];
				if ( self::is_listing( $content ) ) {
					$listing[ (int) $row['ID'] ] = true;
				}
				if ( self::holds_form( $content, $hosts ) ) {
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

		return [ $money, $listing ];
	}

	/**
	 * Whether content is mainly a list of posts (a blog module, a Query Loop or Latest Posts).
	 *
	 * @param string $content Post content.
	 */
	public static function is_listing( string $content ): bool {
		$lower = strtolower( $content );
		foreach ( self::LISTING_MARKERS as $marker ) {
			if ( false !== strpos( $lower, $marker ) ) {
				return true;
			}
		}

		return false;
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
