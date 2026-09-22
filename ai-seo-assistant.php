<?php
/**
 * Plugin Name:       AI SEO Assistant
 * Plugin URI:        https://github.com/andrew-ajrwebdesign/ai-seo-assistant
 * Description:       AI-assisted SEO metadata generation, audit tools, and AI-agent content endpoints for WordPress.
 * Version:           4.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            AJR Web Design
 * Author URI:        https://ajrwebdesign.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-seo-assistant
 * Domain Path:       /languages
 *
 * @package AJR\SEOAssistant
 */

defined( 'ABSPATH' ) || exit;

// Version is READ FROM THE HEADER above, never typed twice: a hand-typed constant is
// how stale asset versions ship when only the header gets bumped.
$ai_seo_assistant_meta = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
define( 'AI_SEO_ASSISTANT_VERSION', '' !== $ai_seo_assistant_meta['Version'] ? $ai_seo_assistant_meta['Version'] : '0.0.0' );
unset( $ai_seo_assistant_meta );
define( 'AI_SEO_ASSISTANT_FILE', __FILE__ );
define( 'AI_SEO_ASSISTANT_PATH', plugin_dir_path( __FILE__ ) );
define( 'AI_SEO_ASSISTANT_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_SEO_ASSISTANT_BASENAME', plugin_basename( __FILE__ ) );

// Composer PSR-4 autoloader — all classes live under AJR\SEOAssistant\
// (including the Markdown module) plus league/html-to-markdown. The packaged
// release zip bundles vendor/; when working from a source checkout, run
// `composer install` to generate it.
$ai_seo_assistant_autoload = AI_SEO_ASSISTANT_PATH . 'vendor/autoload.php';

if ( ! is_readable( $ai_seo_assistant_autoload ) ) {
	// Dependencies are missing (e.g. a partial copy without vendor/). Fail with a
	// clear admin notice instead of a fatal error on activation.
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p><strong>AI SEO Assistant</strong> could not start because its dependencies are missing. Install the packaged plugin zip, or run <code>composer install</code> in the plugin folder to generate <code>vendor/</code>.</p></div>';
		}
	);

	return;
}

require_once $ai_seo_assistant_autoload;

/**
 * Boot the plugin once all plugins are loaded.
 *
 * The core assistant wires up the admin UI, AI generation, and Search
 * Console integration; the Markdown module wires up the AI-discovery
 * endpoints. Both are booted here to keep this file a thin entry point.
 */
add_action(
	'plugins_loaded',
	static function () {
		\AJR\SEOAssistant\Core\Plugin::instance()->init();
		\AJR\SEOAssistant\Markdown\Module::boot();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		\AJR\SEOAssistant\Markdown\Module::activate();
		\AJR\SEOAssistant\Redirects\Redirect_Store::install();
	}
);
register_deactivation_hook( __FILE__, [ \AJR\SEOAssistant\Markdown\Module::class, 'deactivate' ] );
