<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes every credential (the Claude key, the Google OAuth client and tokens, the report push key) and
 * the settings that exist only for them, so a deleted plugin leaves no secret behind in wp_options (or in
 * every backup taken afterwards). The Google grant is revoked at Google first, so a refresh token copied
 * out of an older backup stops working too. Content-affecting data (SEO metadata written into the SEO
 * plugin's fields, redirects, Markdown settings) is deliberately left alone: removing it would change the
 * live site, not just tidy up.
 *
 * @package AJR\SEOAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Revoke the Search Console grant before its tokens are deleted (best effort: a failed revoke never
 * blocks the delete). The tokens are sealed, so opening them needs the plugin's own classes.
 */
$ai_seo_assistant_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $ai_seo_assistant_autoload ) ) {
	require_once $ai_seo_assistant_autoload;
	if ( class_exists( '\AJR\SEOAssistant\GSC\GSC_Client' ) ) {
		\AJR\SEOAssistant\GSC\GSC_Client::revoke( \AJR\SEOAssistant\Core\Secret_Store::get_array( 'ai_seo_assistant_gsc_token_data' ) );
	}
}
unset( $ai_seo_assistant_autoload );

$ai_seo_assistant_options = [
	// Secrets (4.4.0: sealed by Core\Secret_Store; the list matches Secret_Store::OPTIONS, checked by a unit test).
	'ai_seo_assistant_anthropic_api_key',
	'ai_seo_assistant_report_key',
	'ai_seo_assistant_gsc_client_id',
	'ai_seo_assistant_gsc_client_secret',
	'ai_seo_assistant_gsc_token_data',
	'ai_seo_assistant_api_key', // Pre-4.0.0 OpenAI key, in case the upgrade clean-up never ran.
	// Settings that exist only for the credentials above.
	'ai_seo_assistant_model',
	'ai_seo_assistant_settings_version',
	// Search Console data fetched with those credentials: not site content.
	'ai_seo_assistant_gsc_selected_site',
	'ai_seo_assistant_gsc_cache',
	'ai_seo_assistant_gsc_last_sync',
	'ai_seo_assistant_gsc_last_sync_range',
	// Weekly report (4.3.0). Report data is not site content, so none of it outlives the plugin:
	// the push key is a credential, the alert address is personal data, and the agency list is autoloaded.
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
