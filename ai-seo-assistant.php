<?php
/**
 * Plugin Name: AI SEO Assistant
 * Description: AI-assisted SEO metadata generation, audit tools, and AI-agent content endpoints for WordPress.
 * Version: 3.3.0
 * Author: AJR Web Design
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AI_SEO_ASSISTANT_VERSION', '3.3.0' );
define( 'AI_SEO_ASSISTANT_PATH', plugin_dir_path( __FILE__ ) );
define( 'AI_SEO_ASSISTANT_URL', plugin_dir_url( __FILE__ ) );

// Constants required by the Markdown-for-AI module.
if ( ! defined( 'WPMAI_VERSION' ) ) {
	define( 'WPMAI_VERSION', AI_SEO_ASSISTANT_VERSION );
	define( 'WPMAI_PLUGIN_DIR', AI_SEO_ASSISTANT_PATH );
	define( 'WPMAI_PLUGIN_URL', AI_SEO_ASSISTANT_URL );
	define( 'WPMAI_TEXT_DOMAIN', 'ai-seo-assistant' );
}

// Composer autoloader — provides league/html-to-markdown and the AJR\MarkdownForAI classmap.
if ( file_exists( AI_SEO_ASSISTANT_PATH . 'vendor/autoload.php' ) ) {
	require_once AI_SEO_ASSISTANT_PATH . 'vendor/autoload.php';
} else {
	// Fallback manual requires when vendor is absent.
	require_once AI_SEO_ASSISTANT_PATH . 'includes/class-cache.php';
	require_once AI_SEO_ASSISTANT_PATH . 'includes/class-indexability.php';
	require_once AI_SEO_ASSISTANT_PATH . 'includes/class-markdown-converter.php';
	require_once AI_SEO_ASSISTANT_PATH . 'includes/class-llms-txt.php';
	require_once AI_SEO_ASSISTANT_PATH . 'includes/class-rewrite-rules.php';
	require_once AI_SEO_ASSISTANT_PATH . 'includes/class-markdown-settings.php';
	require_once AI_SEO_ASSISTANT_PATH . 'includes/class-markdown-rest-api.php';
	require_once AI_SEO_ASSISTANT_PATH . 'includes/class-markdown-sitemap.php';
	require_once AI_SEO_ASSISTANT_PATH . 'includes/class-rate-limiter.php';
}

require_once AI_SEO_ASSISTANT_PATH . 'includes/class-utils.php';

require_once AI_SEO_ASSISTANT_PATH . 'includes/class-tsf-adapter.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-yoast-adapter.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-rankmath-adapter.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-seo-adapter-resolver.php';

require_once AI_SEO_ASSISTANT_PATH . 'includes/class-content-extractor.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-prompt-builder.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-openai-client.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-logger.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-local-seo-context.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-metadata-generator.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-admin.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-audit-page.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-report-page.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-gsc-client.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-gsc-page.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-indexing-tools-page.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-markdown-page.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-ajax.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-plugin.php';

add_action(
	'plugins_loaded',
	function () {
		AI_SEO_Assistant_Plugin::instance()->init();
	}
);

// Boot the Markdown-for-AI module (llms.txt, ?format=markdown, REST API, sitemap).
add_action(
	'plugins_loaded',
	function () {
		// Cache invalidation hooks always run so Markdown caches stay consistent.
		( new AJR\MarkdownForAI\Cache() )->register();

		// Serving classes only boot when the feature is enabled.
		// Default to enabled=true when the settings have never been saved.
		$wpmai   = get_option( 'wpmai_settings', [] );
		$enabled = isset( $wpmai['enabled'] ) ? (bool) $wpmai['enabled'] : true;

		if ( $enabled ) {
			( new AJR\MarkdownForAI\Rewrite_Rules() )->register();
			( new AJR\MarkdownForAI\Llms_Txt() )->register();
			( new AJR\MarkdownForAI\Rest_Api() )->register();
			( new AJR\MarkdownForAI\Sitemap() )->register();
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		( new AJR\MarkdownForAI\Rewrite_Rules() )->add_rules();
		( new AJR\MarkdownForAI\Llms_Txt() )->add_rewrite_rules();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		AJR\MarkdownForAI\Cache::flush_all();
		flush_rewrite_rules();
	}
);