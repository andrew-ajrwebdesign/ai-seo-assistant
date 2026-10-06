<?php
/**
 * Plugin Name:       AI SEO Assistant
 * Plugin URI:        https://github.com/andrew-ajrwebdesign/ai-seo-assistant
 * Description:       The retainer report and the agency's SEO scan: every page checked, ranked by the clicks it could win, with Claude-written titles, descriptions and alt text applied only after review.
 * Version:           5.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  ajr-core
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

/*
 * PSR-4 autoloader for AJR\SEOAssistant\ → src/. 5.0 has no runtime dependency (Markdown for AI and its
 * league/html-to-markdown went to AJR Core), so there is no vendor/ in the release zip and no Composer
 * autoloader to fail; Composer stays for the dev tools (PHPUnit, PHPCS) only.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AJR\\SEOAssistant\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = AI_SEO_ASSISTANT_PATH . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * Boot once every plugin has loaded (AJR Core's classes are then known).
 */
add_action(
	'plugins_loaded',
	static function () {
		\AJR\SEOAssistant\Core\Plugin::instance()->init();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		\AJR\SEOAssistant\Core\Schema::install();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		\AJR\SEOAssistant\Report\Stale_Alert::unschedule();
		\AJR\SEOAssistant\Scan\Scheduler::unschedule();
	}
);
