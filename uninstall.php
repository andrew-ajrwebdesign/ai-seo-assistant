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
	// Weekly report (4.3.0). Report data is not site content, so none of it outlives the plugin:
	// the push key is a credential, the alert address is personal data, and the agency list is autoloaded.
	'ai_seo_assistant_report_key',
	'ai_seo_assistant_report_alert',
	'ai_seo_assistant_report_alert_email',
	'ai_seo_assistant_report_snapshots',
	'ai_seo_assistant_report_last_push',
	'ai_seo_assistant_agency_users',
];

foreach ( $ai_seo_assistant_options as $ai_seo_assistant_option ) {
	delete_option( $ai_seo_assistant_option );
}
wp_clear_scheduled_hook( 'ai_seo_assistant_report_stale_check' );
foreach ( get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] ) as $ai_seo_assistant_user ) {
	delete_transient( 'aisa_report_new_key_' . (int) $ai_seo_assistant_user ); // A key made but never viewed.
}
unset( $ai_seo_assistant_user );

unset( $ai_seo_assistant_options, $ai_seo_assistant_option );
