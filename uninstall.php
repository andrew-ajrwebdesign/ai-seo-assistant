<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes every credential (the Claude key, the report push key, and any Google OAuth client and tokens a
 * 4.x install left behind) and the settings and data that exist only for this plugin: the scan, the pushed
 * search data, the change log, the reports and the spend record. A Google grant still stored is revoked at
 * Google first (best effort, 3 s), so a refresh token copied out of an older backup stops working too.
 *
 * Content-affecting data is deliberately left alone: SEO titles, descriptions, keyphrases and alt text the
 * plugin applied live in the SEO plugin's fields and the Media Library, and stay as they are; a 4.x
 * redirects table that still holds enabled rules is someone's redirects and stays too. Removing either
 * would change the live site, not just tidy up.
 *
 * @package AJR\SEOAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AJR\\SEOAssistant\\';
		if ( 0 === strpos( $class, $prefix ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

// Revoke a leftover Search Console grant before its tokens are deleted (sealed, so the plugin's own classes open it).
\AJR\SEOAssistant\Core\Upgrade::revoke_google( \AJR\SEOAssistant\Core\Secret_Store::get_array( 'ai_seo_assistant_gsc_token_data' ) );

$ai_seo_assistant_options = [
	// Secrets (sealed by Core\Secret_Store; the list matches Secret_Store::OPTIONS, checked by a unit test).
	'ai_seo_assistant_anthropic_api_key',
	'ai_seo_assistant_report_key',
	'ai_seo_assistant_gsc_client_id',
	'ai_seo_assistant_gsc_client_secret',
	'ai_seo_assistant_gsc_token_data',
	'ai_seo_assistant_api_key', // Pre-4.0.0 OpenAI key, in case the upgrade clean-up never ran.
	// Settings that exist only for the credentials above.
	'ai_seo_assistant_model',
	'ai_seo_assistant_settings_version',
	'ai_seo_assistant_upgrade_attempt', // Core\Upgrade::ATTEMPT_OPTION (a failed sealing attempt).
	'ai_seo_assistant_secrets_resealed', // Core\Secret_Store::RESEALED_OPTION (a pending notice).
	// 4.x Search Console data fetched with those credentials: not site content.
	'ai_seo_assistant_gsc_selected_site',
	'ai_seo_assistant_gsc_cache',
	'ai_seo_assistant_gsc_last_sync',
	'ai_seo_assistant_gsc_last_sync_range',
	// Weekly and monthly report (4.3.0 / 5.0). Report data is not site content, so none of it outlives the
	// plugin: the push key is a credential, the alert address is personal data, the agency list is autoloaded.
	'ai_seo_assistant_report_alert',
	'ai_seo_assistant_report_alert_email',
	'ai_seo_assistant_report_snapshots',
	'ai_seo_assistant_report_months',
	'ai_seo_assistant_report_last_push',
	'ai_seo_assistant_listing_check',
	'ai_seo_assistant_agency_users',
	// 5.0: the scan, the pushed per-page data, the spend cap and its billing month.
	'ai_seo_assistant_db_version',
	'ai_seo_assistant_scan_meta',
	'ai_seo_assistant_scan_queue',
	'ai_seo_assistant_pages_meta',
	'ai_seo_assistant_ctr_curve', // Scan/Ranking::CURVE_OPTION (the site's own click curve).
	'ai_seo_assistant_intent_cache', // Scan/Intent::CACHE_OPTION (Claude's intent per search).
	'ai_seo_assistant_intent_done', // Scan/Intent::DONE_OPTION (the push the intent pass last ran for).
	'ai_seo_assistant_role_notes', // Scan/Page_Role::NOTES_OPTION (old "money" roles left for the agency).
	'ai_seo_assistant_roles_migrated', // The one-off move of old roles into AJR Core page types.
	'ai_seo_assistant_spend',
	'ai_seo_assistant_spend_cap',
	'ai_seo_assistant_billing_day',
	'ai_seo_assistant_billing_day_pushed',
	'ai_seo_assistant_redirects_pending',
];

foreach ( $ai_seo_assistant_options as $ai_seo_assistant_option ) {
	delete_option( $ai_seo_assistant_option );
}
\AJR\SEOAssistant\Core\Schema::drop();
delete_metadata( 'user', 0, 'aisa_rank_mode', '', true ); // Scan/Ranking::MODE_META (each agency user's list mode).
delete_post_meta_by_key( '_aisa_page_role' ); // Scan/Page_Role::LEGACY_META (page types belong to AJR Core and stay).
wp_clear_scheduled_hook( 'ai_seo_assistant_report_stale_check' );
wp_clear_scheduled_hook( 'aisa_scan_run' );
wp_unschedule_hook( 'aisa_scan_post' );
delete_transient( 'aisa_scan_lock' );
delete_transient( 'aisa_scan_link_status' );
delete_transient( 'aisa_scan_sitemap' );
foreach ( get_users(
	[
		'role'   => 'administrator',
		'fields' => 'ID',
	]
) as $ai_seo_assistant_user ) {
	delete_transient( 'aisa_report_new_key_' . (int) $ai_seo_assistant_user ); // A key made but never viewed.
}
unset( $ai_seo_assistant_user, $ai_seo_assistant_options, $ai_seo_assistant_option );
