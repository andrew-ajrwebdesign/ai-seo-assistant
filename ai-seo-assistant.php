<?php
/**
 * Plugin Name: AI SEO Assistant
 * Description: AI-assisted SEO metadata generation and audit tools for WordPress.
 * Version: 3.2.9
 * Author: AJR Web Design
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AI_SEO_ASSISTANT_VERSION', '3.2.9' );
define( 'AI_SEO_ASSISTANT_PATH', plugin_dir_path( __FILE__ ) );
define( 'AI_SEO_ASSISTANT_URL', plugin_dir_url( __FILE__ ) );

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
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-ajax.php';
require_once AI_SEO_ASSISTANT_PATH . 'includes/class-plugin.php';

add_action(
	'plugins_loaded',
	function () {
		AI_SEO_Assistant_Plugin::instance()->init();
	}
);