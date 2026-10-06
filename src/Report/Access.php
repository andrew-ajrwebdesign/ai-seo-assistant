<?php
/**
 * Access — the report is the owner's; the tools are the agency's.
 *
 * WHY (Andrew, 2026-09-29): the client — usually a WordPress Administrator — sees the Report and none of
 * the plugin's tool screens (SEO scan, Search Console, Changes, Settings, and the editor box). Every one of
 * them can spend the agency's Claude key or change the site's SEO fields. Administrators all hold
 * manage_options, so the tools sit behind their own capability, TOOLS_CAP, granted by this filter.
 *
 * WHO (5.0): AJR Core is required, and its Support::is_agency_user() is the stack's single agency resolver
 * (Administrators whose login email is on the agency's domain). The older "Agency users" list is honoured
 * only as an explicit override: when it names at least one current Administrator, it alone decides. Should
 * AJR Core ever be missing, nobody holds the tools (the client still sees the Report): failing closed on a
 * capability that spends money is the safe direction.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Tool-screen access.
 */
class Access {

	/** The capability every tool screen requires. */
	public const TOOLS_CAP = 'aisa_manage_tools';

	/** Option: user IDs allowed to use the tool screens (autoload on: read on every admin page's menu). */
	public const OPTION = 'ai_seo_assistant_agency_users';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'user_has_cap', [ $this, 'grant' ], 10, 4 );
		add_action( 'set_user_role', [ self::class, 'flush' ] );
		add_action( 'deleted_user', [ self::class, 'flush' ] );
		add_action( 'set_current_user', [ self::class, 'flush' ] ); // AJR Core's answer is about the current user.
	}

	/**
	 * Grant TOOLS_CAP to agency users (or to every Administrator while none are named).
	 *
	 * @param array<string,bool> $allcaps All the user's capabilities.
	 * @param array<int,string>  $caps    Primitive capabilities being checked.
	 * @param array<int,mixed>   $args    [ requested capability, user ID, … ].
	 * @param \WP_User|null      $user    The user.
	 * @return array<string,bool>
	 */
	public function grant( $allcaps, $caps, $args, $user = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the filter's signature; the ID comes from $args.
		$allcaps = (array) $allcaps;
		if ( ! in_array( self::TOOLS_CAP, (array) $caps, true ) ) {
			return $allcaps;
		}
		$user_id                    = (int) ( $args[1] ?? 0 );
		$allcaps[ self::TOOLS_CAP ] = ! empty( $allcaps['manage_options'] ) && self::is_agency( $user_id );

		return $allcaps;
	}

	/**
	 * Whether a user is an agency user.
	 *
	 * In order: an override list that names a current Administrator wins. Otherwise AJR Core's
	 * Support::is_agency_user() (login email on the agency's domain, filter `ajr_core_is_agency_user`).
	 * Without AJR Core: nobody (5.0 requires it; see the class comment).
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_agency( int $user_id ): bool {
		$named = self::named();
		if ( [] !== $named ) {
			return in_array( $user_id, $named, true );
		}

		return true === self::core_says_agency( $user_id );
	}

	/**
	 * Where the answer comes from, for the Settings screen: 'override', 'ajr-core' or 'none'.
	 */
	public static function source(): string {
		if ( [] !== self::named() ) {
			return 'override';
		}

		return class_exists( 'AJR\Core\Admin\Support' ) ? 'ajr-core' : 'none';
	}

	/**
	 * AJR Core's answer for a user, or null when AJR Core is not active.
	 *
	 * AJR Core 0.20 asks about the CURRENT user only (`is_agency_user(): bool`); the stack contract allows
	 * a later `is_agency_user( ?WP_User $user )`. Both are handled: a user other than the current one is
	 * only answered when the method takes a user, and is otherwise refused (the safe answer for a tools
	 * capability; capability checks are almost always about the current user).
	 *
	 * @param int $user_id User ID.
	 */
	protected static function core_says_agency( int $user_id ): ?bool {
		$class = 'AJR\Core\Admin\Support';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'is_agency_user' ) ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, self::$core ) ) {
			$takes_user = ( new \ReflectionMethod( $class, 'is_agency_user' ) )->getNumberOfParameters() > 0;
			if ( $takes_user ) {
				$user                   = get_userdata( $user_id );
				self::$core[ $user_id ] = $user instanceof \WP_User && (bool) $class::is_agency_user( $user );
			} else {
				self::$core[ $user_id ] = get_current_user_id() === $user_id && (bool) $class::is_agency_user();
			}
		}

		return self::$core[ $user_id ];
	}

	/**
	 * Per-request memo for core_says_agency(), keyed by user ID.
	 *
	 * @var array<int,bool>
	 */
	protected static array $core = [];

	/**
	 * The named agency users who are still Administrators.
	 *
	 * @return array<int,int>
	 */
	public static function named(): array {
		$ids = array_values( array_filter( array_map( 'intval', (array) get_option( self::OPTION, [] ) ) ) );
		if ( [] === $ids ) {
			return [];
		}
		// Worked out once per request per list: the menu alone asks for TOOLS_CAP twenty-odd times on
		// every admin page, and each answer would otherwise rebuild a WP_User for every named ID.
		$memo = implode( ',', $ids );
		if ( ! isset( self::$named[ $memo ] ) ) {
			self::$named[ $memo ] = array_values(
				array_filter(
					$ids,
					static function ( int $id ): bool {
						$user = get_userdata( $id );
						return $user instanceof \WP_User && in_array( 'administrator', (array) $user->roles, true );
					}
				)
			);
		}

		return self::$named[ $memo ];
	}

	/**
	 * Per-request memo for named(), keyed by the stored list.
	 *
	 * @var array<string,array<int,int>>
	 */
	protected static array $named = [];

	/**
	 * Forget the memo (tests, and after a user's role changes mid-request).
	 */
	public static function flush(): void {
		self::$named = [];
		self::$core  = [];
	}
}
