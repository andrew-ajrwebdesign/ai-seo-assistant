<?php
/**
 * Front-end redirect handler.
 *
 * Runs early on template_redirect and, when the current request path matches an
 * enabled redirect, issues the configured HTTP status before WordPress renders a
 * 404. Matching reads the compact autoloaded map from Redirect_Store, so the hot
 * path performs no database query and no write.
 */

namespace AJR\SEOAssistant\Redirects;

defined( 'ABSPATH' ) || exit;

class Redirect_Handler {

	/**
	 * Redirect data store.
	 *
	 * @var Redirect_Store
	 */
	private Redirect_Store $store;

	public function __construct( Redirect_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Hooks the handler in early so a match short-circuits template loading.
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
	}

	/**
	 * Redirects the current request when its path matches an enabled rule.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || headers_sent() ) {
			return;
		}

		$map = $this->store->get_map();

		if ( empty( $map ) ) {
			return;
		}

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_path = Redirect_Store::normalize_path( $request_uri );

		if ( '' === $request_path || ! isset( $map[ $request_path ] ) ) {
			return;
		}

		$rule   = $map[ $request_path ];
		$status = (int) $rule['code'];

		// 410 Gone: signal removal with no destination.
		if ( 410 === $status ) {
			status_header( 410 );
			nocache_headers();
			exit;
		}

		$target = (string) $rule['target'];

		// Loop guard: never redirect a path to itself.
		if ( '' === $target || Redirect_Store::normalize_path( $target ) === $request_path ) {
			return;
		}

		// wp_redirect (not wp_safe_redirect) so admin-defined external targets work.
		wp_redirect( $target, $status );
		exit;
	}
}
