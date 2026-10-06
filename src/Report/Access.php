<?php
/**
 * Access — the report is the owner's; the tools are the agency's.
 *
 * WHY (Andrew, 2026-09-29): the client — usually a WordPress Administrator — sees the Weekly report and
 * none of the plugin's tool screens (Settings, Audit, Recommendations, Search Console, Indexing, Markdown,
 * Redirects, Metadata report). Administrators all hold manage_options, so the tools move behind their own
 * capability, TOOLS_CAP, granted by this filter to the users named in the "Agency users" option.
 *
 * NO LIST. Until at least one existing Administrator is named (a list that names only deleted or demoted
 * users counts as none): with AJR Core active, AJR Core decides who is agency (4.4.0: one resolver for the
 * whole stack; Administrators whose login email is on the agency's domain), unless it counts NO current
 * Administrator as agency, when every Administrator keeps the tools (no lockout); without AJR Core, every
 * Administrator keeps the tools, as before.
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
	 * In order: a list that names a current Administrator wins. With no list, AJR Core (when active) is
	 * the stack's single agency resolver: its Support::is_agency_user() (login email on the agency's
	 * domain, filter `ajr_core_is_agency_user`), falling back to every Administrator when it names none of
	 * them. Without AJR Core, every Administrator, as before 4.4.0.
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_agency( int $user_id ): bool {
		$named = self::named();
		if ( [] !== $named ) {
			return in_array( $user_id, $named, true );
		}

		$core = self::core_says_agency( $user_id );
		if ( null === $core || $core ) {
			return true;
		}

		// ⛔ No lockout. AJR Core said "not agency"; if it says that about EVERY current Administrator (no
		// agency login on this site yet, or the agency domain changed), nobody could reach the tool screens,
		// including the one that names agency users. Then every Administrator keeps them, as before 4.4.0.
		return ! self::core_has_agency_admin();
	}

	/**
	 * Whether AJR Core counts at least one current Administrator as agency.
	 *
	 * With an AJR Core whose is_agency_user() takes a user (0.21+), every Administrator is asked, once per
	 * request (at most 100: a site with more is answered "none found", the no-lockout side). An older AJR
	 * Core can only answer for the current user, so only a "yes" for the current user proves one exists;
	 * anything else counts as none found, i.e. the pre-4.4.0 behaviour.
	 */
	protected static function core_has_agency_admin(): bool {
		if ( null !== self::$any_agency ) {
			return self::$any_agency;
		}
		$class = 'AJR\Core\Admin\Support';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'is_agency_user' ) ) {
			self::$any_agency = false;
			return false;
		}

		if ( ( new \ReflectionMethod( $class, 'is_agency_user' ) )->getNumberOfParameters() > 0 ) {
			$found = false;
			$admins = get_users(
				[
					'role'   => 'administrator',
					'number' => 100,
				]
			);
			foreach ( $admins as $admin ) {
				if ( $admin instanceof \WP_User && $class::is_agency_user( $admin ) ) {
					$found = true;
					break;
				}
			}
		} else {
			$found = (bool) $class::is_agency_user();
		}
		self::$any_agency = $found;

		return $found;
	}

	/**
	 * Per-request memo for core_has_agency_admin().
	 *
	 * @var bool|null
	 */
	protected static ?bool $any_agency = null;

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
		self::$named      = [];
		self::$core       = [];
		self::$any_agency = null;
	}
}
