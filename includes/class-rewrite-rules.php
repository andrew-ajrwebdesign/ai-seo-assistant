<?php
/**
 * Registers rewrite rules and serves Markdown responses.
 *
 * Handles:
 *  - ?format=markdown on any singular post/page (with per-post transient cache)
 *  - Link HTTP header on all public pages pointing to the Markdown version
 *  - <link rel="alternate"> injected into <head>
 */

namespace AJR\MarkdownForAI;

defined( 'ABSPATH' ) || exit;

class Rewrite_Rules {

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_serve_markdown' ] );
		add_action( 'send_headers', [ $this, 'add_link_header' ] );
		add_action( 'wp_head', [ $this, 'add_link_tag' ] );
	}

	public function add_rules(): void {
		// ?format=markdown works on existing WP URLs — no extra rewrite rules needed.
	}

	/**
	 * Intercepts requests with ?format=markdown and outputs cached Markdown.
	 */
	public function maybe_serve_markdown(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'markdown' !== ( $_GET['format'] ?? '' ) ) {
			return;
		}

		if ( ! Settings::get_option( 'enable_format_param', true ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'This endpoint is disabled.', 'wp-markdown-for-ai' ), 404 );
		}

		if ( ! is_singular() ) {
			status_header( 404 );
			wp_die( esc_html__( 'Markdown is only available for individual posts and pages.', 'wp-markdown-for-ai' ), 404 );
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof \WP_Post ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'Post not found.', 'wp-markdown-for-ai' ), 404 );
		}

		$allowed_types = Settings::get_option( 'post_types', [ 'post', 'page' ] );

		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'This content type is not available as Markdown.', 'wp-markdown-for-ai' ), 403 );
		}

		$excluded = Settings::get_option( 'excluded_ids', [] );

		if ( in_array( $post->ID, array_map( 'absint', $excluded ), true ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'This page is not available as Markdown.', 'wp-markdown-for-ai' ), 403 );
		}

		if ( ! Indexability::is_indexable( $post ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'This page is not available as Markdown.', 'wp-markdown-for-ai' ), 403 );
		}

		// Rate limiting.
		( new Rate_Limiter() )->check();

		// Conditional GET: 304 if content hasn't changed since client's copy.
		$last_modified = strtotime( $post->post_modified_gmt );
		if ( $this->not_modified( $last_modified ) ) {
			status_header( 304 );
			exit;
		}

		$cache_key = Cache::post_key( $post->ID );
		$markdown  = Cache::get( $cache_key );

		if ( false === $markdown ) {
			$converter = new Markdown_Converter();
			$markdown  = $converter->convert( $post );
			$ttl       = (int) Settings::get_option( 'cache_ttl_hours', 12 ) * HOUR_IN_SECONDS;
			Cache::set( $cache_key, $markdown, $ttl );
		}

		$this->send_markdown_headers( $last_modified );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $markdown;
		exit;
	}

	/**
	 * Returns true if the client's If-Modified-Since matches the content's last-modified timestamp.
	 *
	 * @param int $last_modified Unix timestamp of content modification.
	 * @return bool
	 */
	private function not_modified( int $last_modified ): bool {
		$if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $if_modified_since ) ) {
			return false;
		}
		$client_time = strtotime( $if_modified_since );
		return $client_time !== false && $last_modified <= $client_time;
	}

	/**
	 * Sends HTTP headers for a Markdown response.
	 *
	 * @param int $last_modified Unix timestamp of content modification.
	 */
	private function send_markdown_headers( int $last_modified = 0 ): void {
		$ttl = (int) Settings::get_option( 'cache_ttl_hours', 12 ) * HOUR_IN_SECONDS;

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=' . $ttl );

		if ( $last_modified > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT' );
		}
	}

	/**
	 * Adds a Link HTTP header on singular pages pointing to the Markdown version.
	 */
	public function add_link_header(): void {
		if ( ! $this->is_eligible() ) {
			return;
		}

		$markdown_url = $this->get_markdown_url();

		if ( $markdown_url ) {
			header( 'Link: <' . esc_url_raw( $markdown_url ) . '>; rel="alternate"; type="text/markdown"', false );
		}
	}

	/**
	 * Injects a <link rel="alternate"> tag into <head> for singular pages.
	 */
	public function add_link_tag(): void {
		if ( ! $this->is_eligible() ) {
			return;
		}

		$markdown_url = $this->get_markdown_url();

		if ( $markdown_url ) {
			printf(
				'<link rel="alternate" type="text/markdown" href="%s">' . "\n",
				esc_url( $markdown_url )
			);
		}
	}

	/**
	 * Returns true if the current request should advertise a Markdown version.
	 */
	private function is_eligible(): bool {
		if ( ! Settings::get_option( 'enable_format_param', true ) ) {
			return false;
		}

		if ( ! is_singular() ) {
			return false;
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof \WP_Post ) ) {
			return false;
		}

		$allowed_types = Settings::get_option( 'post_types', [ 'post', 'page' ] );

		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return false;
		}

		$excluded = Settings::get_option( 'excluded_ids', [] );

		if ( in_array( $post->ID, array_map( 'absint', $excluded ), true ) ) {
			return false;
		}

		if ( ! Indexability::is_indexable( $post ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns the Markdown URL for the current singular post, or null.
	 */
	private function get_markdown_url(): ?string {
		$post = get_queried_object();

		if ( ! ( $post instanceof \WP_Post ) ) {
			return null;
		}

		return add_query_arg( 'format', 'markdown', get_permalink( $post ) );
	}
}
