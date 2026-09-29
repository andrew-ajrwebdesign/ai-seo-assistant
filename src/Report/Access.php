<?php
/**
 * Access — the report is the owner's; the tools are the agency's.
 *
 * WHY (Andrew, 2026-09-29): the client — usually a WordPress Administrator — sees the Weekly report and
 * none of the plugin's tool screens (Settings, Audit, Recommendations, Search Console, Indexing, Markdown,
 * Redirects, Metadata report). Administrators all hold manage_options, so the tools move behind their own
 * capability, TOOLS_CAP, granted by this filter to the users named in the "Agency users" option.
 *
 * NO LOCKOUT. Until at least one existing Administrator is named, every Administrator keeps the tools —
 * installing the update changes nothing until someone chooses otherwise, and a list that names only
 * deleted or demoted users falls back the same way.
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
	 * Whether a user is on the agency list (true for any admin while the list names no current admin).
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_agency( int $user_id ): bool {
		$named = self::named();

		return [] === $named || in_array( $user_id, $named, true );
	}

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
	}
}
