<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the Claude API key and the AI settings that exist only for it, so a
 * deleted plugin leaves no credential behind in wp_options (or in every
 * backup taken afterwards). Content-affecting data (SEO metadata written into
 * the SEO plugin's fields, redirects, Markdown settings) is deliberately left
 * alone: removing it would change the live site, not just tidy up.
 *
 * @package AJR\SEOAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ai_seo_assistant_options = [
	'ai_seo_assistant_anthropic_api_key',
	'ai_seo_assistant_model',
	'ai_seo_assistant_settings_version',
	'ai_seo_assistant_api_key', // Pre-4.0.0 OpenAI key, in case the upgrade clean-up never ran.
];

foreach ( $ai_seo_assistant_options as $ai_seo_assistant_option ) {
	delete_option( $ai_seo_assistant_option );
}

unset( $ai_seo_assistant_options, $ai_seo_assistant_option );
