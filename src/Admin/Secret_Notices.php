<?php
/**
 * Secret_Notices — tells the agency when a stored key cannot be used or is not really protected.
 *
 * Agency users only (the client has nothing to do about any of these):
 * 1. A sealed secret no longer opens (the site's salts were rotated, or the row was edited). The plugin
 *    already treats it as absent; this says which one to re-enter.
 * 2. The 4.4.0 upgrade could not seal a secret on this server (Core\Upgrade::attempt()).
 * 3. A secret was found in plain text after the upgrade and has just been sealed (shown once).
 * 4. The auth salts are not usable constants in wp-config.php, so WordPress keeps them in the database
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
	 * @param array<int,mixed> $options Option names.
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
