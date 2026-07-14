<?php
/**
 * Bootstrap for the Markdown-for-AI module.
 *
 * Encapsulates the wiring that previously lived inline in the main plugin
 * file: cache invalidation, the settings screen, and the AI-discovery
 * endpoints (llms.txt, ?format=markdown, REST API, sitemap). Keeping it in a
 * dedicated class keeps the plugin bootstrap thin and lets the module be
 * booted or tested in isolation.
 */

namespace AJR\SEOAssistant\Markdown;

defined( 'ABSPATH' ) || exit;

class Module {

	/**
	 * Boot the module on every request.
	 *
	 * Cache invalidation always loads so caches stay consistent. The public
	 * serving endpoints only load when the feature is enabled (which it is by
	 * default). The settings UI is owned by \AJR\SEOAssistant\Admin\Markdown_Page,
	 * which is registered separately by the core plugin.
	 */
	public static function boot(): void {
		( new Cache() )->register();

		if ( self::is_enabled() ) {
			( new Rewrite_Rules() )->register();
			( new Llms_Txt() )->register();
			( new Rest_Api() )->register();
			( new Sitemap() )->register();
		}
	}

	/**
	 * Register rewrite rules on plugin activation, then flush.
	 */
	public static function activate(): void {
		( new Rewrite_Rules() )->add_rules();
		( new Llms_Txt() )->add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Clear module caches and rewrite rules on plugin deactivation.
	 */
	public static function deactivate(): void {
		Cache::flush_all();
		flush_rewrite_rules();
	}

	/**
	 * Whether the public Markdown endpoints are enabled.
	 *
	 * Defaults to true when the settings have never been saved, so a fresh
	 * install exposes the endpoints without requiring a visit to Settings.
	 *
	 * @return bool
	 */
	private static function is_enabled(): bool {
		$settings = get_option( 'wpmai_settings', [] );

		return isset( $settings['enabled'] ) ? (bool) $settings['enabled'] : true;
	}
}
