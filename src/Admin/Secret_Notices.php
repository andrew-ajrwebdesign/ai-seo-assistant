<?php
/**
 * Secret_Notices — tells the agency when a stored key cannot be used or is not really protected.
 *
 * Two cases, agency users only (the client has nothing to do about either):
 * 1. A sealed secret no longer opens (the site's salts were rotated, or the row was edited). The plugin
 *    already treats it as absent; this says which one to re-enter.
 * 2. The auth salts are not constants in wp-config.php, so WordPress keeps them in the database beside the
 *    sealed secrets, and anyone with the database can open them.
 *
 * Shown on this plugin's screens, the Dashboard and the Plugins screen only: the check reads the secret
 * options, which are not autoloaded, so it must not run on every admin page.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Core\Secret_Store;
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
