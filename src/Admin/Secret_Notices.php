<?php
/**
 * Secret_Notices — tells the agency when a stored key cannot be used or is not really protected.
 *
 * Agency users only (the client has nothing to do about any of these):
 * 1. A sealed secret no longer opens (the site's salts were rotated, or the row was edited). The plugin
 *    already treats it as absent; this says which one to re-enter.
 * 2. The 4.4.0 upgrade could not seal a secret on this server (Core\Upgrade::attempt()).
 * 3. A secret was found in plain text after the upgrade and has just been sealed (shown once).
 * 4. The 5.0 upgrade could not get Google to revoke the old Search Console grant (shown once).
 * 5. No Administrator counts as agency, so every Administrator has the tools (and the Claude budget).
 *    Persistent until someone is named: the no-lockout fallback must not go unnoticed.
 * 6. The auth salts are not usable constants in wp-config.php, so WordPress keeps them in the database
 *    beside the sealed secrets, and anyone with the database can open them.
 *
 * Shown on this plugin's screens, the Dashboard and the Plugins screen only: the check reads the secret
 * options, which are not autoloaded, so it must not run on every admin page.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Core\Secret_Store;
use AJR\SEOAssistant\Core\Upgrade;
use AJR\SEOAssistant\Report\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Agency-only secret warnings.
 */
class Secret_Notices {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * Print the notices that apply.
	 */
	public function render(): void {
		if ( ! $this->on_relevant_screen() || ! current_user_can( Access::TOOLS_CAP ) ) {
			return;
		}

		foreach ( Secret_Store::unreadable() as $option ) {
			$label = Secret_Store::OPTIONS[ $option ] ?? $option;
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: %s: name of the stored secret, e.g. "Claude API key". */
					__( 'AI SEO Assistant: the saved %s can no longer be read (the site’s security salts have changed). Re-enter it.', 'ai-seo-assistant' ),
					$label
				)
			) . '</p></div>';
		}

		// The 4.4.0 upgrade could not seal a secret (no libsodium): say so instead of retrying silently.
		if ( ! Upgrade::is_current() ) {
			$attempt = Upgrade::attempt();
			if ( ! empty( $attempt['failed'] ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html(
					sprintf(
						/* translators: %s: comma-separated names of the stored secrets, e.g. "Claude API key". */
						__( 'AI SEO Assistant could not encrypt the saved %s on this server (PHP’s sodium functions are unavailable), so they are still stored in plain text. It tries again once a day; define the keys in wp-config.php instead, or ask the host to enable sodium.', 'ai-seo-assistant' ),
						implode( ', ', $this->labels( (array) $attempt['failed'] ) )
					)
				) . '</p></div>';
			}
		}

		// A secret found in plain text after the upgrade (an update run from cron or WP-CLI, a restored
		// backup) and sealed by Secret_Store::get(): shown once, then forgotten.
		$resealed = get_option( Secret_Store::RESEALED_OPTION, [] );
		if ( is_array( $resealed ) && [] !== $resealed ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: comma-separated names of the stored secrets, e.g. "Claude API key". */
					__( 'AI SEO Assistant: a key was stored in plain text and has been encrypted (%s).', 'ai-seo-assistant' ),
					implode( ', ', $this->labels( $resealed ) )
				)
			) . '</p></div>';
			delete_option( Secret_Store::RESEALED_OPTION );
		}

		// 5.0: Google did not confirm revoking the old on-site Search Console grant. Shown once.
		if ( false !== get_option( Upgrade::REVOKE_FAILED, false ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'AI SEO Assistant 5.0 removed its old Search Console connection, but Google did not confirm revoking the access it held. Remove "AI SEO Assistant" (or the Google Cloud app it used) from the Google account’s third-party access:', 'ai-seo-assistant' ) . ' <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener noreferrer">myaccount.google.com/permissions</a></p></div>';
			delete_option( Upgrade::REVOKE_FAILED );
		}

		// 5.0: the old redirects table still holds enabled rules, which 5.0 no longer serves. Never deleted
		// until every one of them is confirmed in AJR Core (Upgrade::confirm_redirects_moved()).
		$pending = (int) get_option( Upgrade::REDIRECTS_PENDING, 0 );
		if ( $pending > 0 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only list from our own redirect.
			$missing = isset( $_GET['aisa_missing'] ) ? sanitize_text_field( wp_unslash( $_GET['aisa_missing'] ) ) : '';
			echo '<div class="notice notice-error"><p><strong>' . esc_html(
				sprintf(
					/* translators: %d: number of redirect rules. */
					_n( 'AI SEO Assistant 5.0 no longer runs redirects, and its old table still holds %d enabled redirect.', 'AI SEO Assistant 5.0 no longer runs redirects, and its old table still holds %d enabled redirects.', $pending, 'ai-seo-assistant' ),
					$pending
				)
			) . '</strong> ' . esc_html__( 'Move them into AJR Core › Redirects first (its import reads the old table), then confirm here. The old table is kept until every enabled rule is found in AJR Core.', 'ai-seo-assistant' ) . '</p>';
			if ( '' !== $missing ) {
				/* translators: %s: paths. */
				echo '<p>' . esc_html( sprintf( __( 'Not in AJR Core yet: %s', 'ai-seo-assistant' ), $missing ) ) . '</p>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
			wp_nonce_field( Tools_Actions::REDIRECTS );
			echo '<input type="hidden" name="action" value="' . esc_attr( Tools_Actions::REDIRECTS ) . '"><button type="submit" class="button">' . esc_html__( 'They are in AJR Core: retire the old table', 'ai-seo-assistant' ) . '</button></p></form></div>';
		}

		if ( in_array( Access::source(), [ 'fallback', 'none' ], true ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'AI SEO Assistant: no Administrator is marked as agency staff, so every Administrator sees the SEO tools and can spend this site’s Claude budget. Mark the agency login in AJR Core, or tick who sees the tools in Settings.', 'ai-seo-assistant' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Settings_Page::SLUG . '#aisa-s-access' ) ) . '">' . esc_html__( 'Who sees the tools', 'ai-seo-assistant' ) . '</a></p></div>';
		}

		if ( ! Secret_Store::salts_in_config() && $this->any_secret_stored() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'AI SEO Assistant: the security salts are not defined in wp-config.php, so saved keys are only obfuscated, not encrypted. Add the salts to wp-config.php.', 'ai-seo-assistant' ) . '</p></div>';
		}
	}

	/**
	 * Whether the current screen is one where the check is worth its queries.
	 */
	protected function on_relevant_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) || false !== strpos( (string) $screen->id, 'ai-seo-assistant' );
	}

	/**
	 * Human labels for secret option names (unknown names are dropped, never printed raw).
	 *
	 * @param array<mixed> $options Option names (as stored; anything else is skipped).
	 * @return array<int,string>
	 */
	protected function labels( array $options ): array {
		$labels = [];
		foreach ( $options as $option ) {
			if ( is_string( $option ) && isset( Secret_Store::OPTIONS[ $option ] ) ) {
				$labels[] = Secret_Store::OPTIONS[ $option ];
			}
		}

		return $labels;
	}

	/**
	 * Whether any secret is stored in the database at all (no warning on a site that keeps none).
	 */
	protected function any_secret_stored(): bool {
		foreach ( array_keys( Secret_Store::OPTIONS ) as $option ) {
			if ( Secret_Store::has( $option ) ) {
				return true;
			}
		}

		return false;
	}
}
