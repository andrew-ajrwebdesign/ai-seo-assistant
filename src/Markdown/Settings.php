<?php
/**
 * Settings data accessor for the Markdown-for-AI module.
 *
 * This class is the single read API for the `wpmai_settings` option and is used
 * by the serving classes (Cache, Rewrite_Rules, Llms_Txt, Sitemap, Rest_Api).
 *
 * The admin UI that writes these settings lives in
 * \AJR\SEOAssistant\Admin\Markdown_Page, which owns the settings screen, the
 * register_setting() call, sanitization, and the cache-clear action. Keeping
 * reads here and writes there avoids the duplicate settings page that existed
 * when this module was merged in from the standalone WP Markdown for AI plugin.
 */

namespace AJR\SEOAssistant\Markdown;

defined( 'ABSPATH' ) || exit;

class Settings {

	/**
	 * Option key under which all module settings are stored.
	 */
	private const OPTION_KEY = 'wpmai_settings';

	/**
	 * Returns a single setting value with a default fallback.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value returned when the key is unset.
	 * @return mixed
	 */
	public static function get_option( string $key, $default = null ) {
		$options = get_option( self::OPTION_KEY, [] );

		return $options[ $key ] ?? $default;
	}

	/**
	 * Returns the list of post types currently enabled for Markdown endpoints.
	 *
	 * @return string[]
	 */
	public static function allowed_post_types(): array {
		return (array) self::get_option( 'post_types', [ 'post', 'page' ] );
	}
}
