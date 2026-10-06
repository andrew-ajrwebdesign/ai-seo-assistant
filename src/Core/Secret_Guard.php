<?php
/**
 * Secret_Guard — every write to a secret option passes Secret_Store's rules, on every request.
 *
 * WHY (stack review round 2, 2026-10-06). Sealing on our own settings screens was not enough: WordPress's
 * generic options.php form (`option_page=options&page_options=<name>`) writes any option for anyone with
 * manage_options, so a client Administrator, or any plugin calling update_option(), could replace the
 * agency's Claude key or report push key with a plain-text value of their own. The push key has no
 * registered setting at all, so no settings-group capability ever covered it.
 *
 * So the filters sit on the options themselves, for every option in Secret_Store::OPTIONS, and on every
 * request (cron and REST write too, not only wp-admin). The callbacks live in Secret_Store; this class only
 * registers them, so a visitor's page view loads this file and not the cipher code.
 *
 * WHAT IT IS NOT. It keeps secrets sealed and stops the ordinary routes (options.php, another plugin's
 * update_option()) from swapping them. It is not a boundary against a hostile Administrator: anyone who can
 * install a plugin, edit wp-config.php or run code can remove these filters or read the salts. The tools
 * menu is the same: who sees it is tidiness for the client, not security.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Secret_Store's write filters.
 */
class Secret_Guard {

	/**
	 * Every option that holds a secret, keyed by option name => human label (used in notices).
	 *
	 * Defined here and re-exported as Secret_Store::OPTIONS: this class loads on every request, and reading
	 * the list from Secret_Store would load the cipher code on every page view too. One list, so the guard,
	 * migration, the decrypt-failure notice and uninstall can never disagree about what is secret. The
	 * Google client ID is not strictly a secret, but it is half of a credential pair and has no reason to be
	 * readable either.
	 */
	public const OPTIONS = [
		'ai_seo_assistant_anthropic_api_key' => 'Claude API key',
		'ai_seo_assistant_report_key'        => 'Report push key',
		'ai_seo_assistant_gsc_client_id'     => 'Google client ID',
		'ai_seo_assistant_gsc_client_secret' => 'Google client secret',
		'ai_seo_assistant_gsc_token_data'    => 'Google Search Console connection',
	];

	/**
	 * Hook the write filters for every secret option.
	 *
	 * Priority 99: after any sanitize callback a register_setting() call adds (priority 10), so the guard
	 * sees the value that is really about to be stored.
	 */
	public function register(): void {
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			add_filter( 'sanitize_option_' . $option, [ self::class, 'on_sanitize' ], 99, 2 );
			add_filter( 'pre_update_option_' . $option, [ self::class, 'on_update' ], 99, 2 );
		}
	}

	/**
	 * `sanitize_option_{$option}`: hand the write to Secret_Store::guard_sanitize().
	 *
	 * The callbacks are this class's own methods so that registering them never loads Secret_Store (a hook
	 * inspector that checks is_callable() on every callback would otherwise autoload it on every page).
	 *
	 * @param mixed  $value  The value about to be stored.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public static function on_sanitize( $value, $option = '' ) {
		return Secret_Store::guard_sanitize( $value, (string) $option );
	}

	/**
	 * `pre_update_option_{$option}`: hand the write to Secret_Store::guard_update().
	 *
	 * @param mixed $value     The value about to be stored.
	 * @param mixed $old_value The stored value.
	 * @return mixed
	 */
	public static function on_update( $value, $old_value ) {
		return Secret_Store::guard_update( $value, $old_value );
	}
}
