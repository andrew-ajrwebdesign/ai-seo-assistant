<?php
/**
 * Registers rewrite rules and serves Markdown responses.
 *
 * Handles:
 *  - ?format=markdown on any singular post/page (with per-post transient cache)
 *  - Link HTTP header on all public pages pointing to the Markdown version
 *  - <link rel="alternate"> injected into <head>
 */

namespace AJR\SEOAssistant\Markdown;

defined( 'ABSPATH' ) || exit;

class Rewrite_Rules {

	public function register(): void {
		// Priority 1 at init: before page-cache plugins open their output buffers (Newfold/
		// Endurance starts its file-cache buffer at init 10), so a request asking for
		// Markdown is marked uncacheable before any cache can decide to store it — whatever
		// that request later turns out to return (Markdown, HTML fallback, or an error).
		add_action( 'init', [ $this, 'mark_markdown_request_uncacheable_early' ], 1 );
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
			$this->maybe_serve_negotiated();
			return;
		}

		if ( ! Settings::get_option( 'enable_format_param', true ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'This endpoint is disabled.', 'ai-seo-assistant' ), 404 );
		}

		if ( ! is_singular() ) {
			status_header( 404 );
			wp_die( esc_html__( 'Markdown is only available for individual posts and pages.', 'ai-seo-assistant' ), 404 );
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof \WP_Post ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'Post not found.', 'ai-seo-assistant' ), 404 );
		}

		$allowed_types = Settings::get_option( 'post_types', [ 'post', 'page' ] );

		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'This content type is not available as Markdown.', 'ai-seo-assistant' ), 403 );
		}

		$excluded = Settings::get_option( 'excluded_ids', [] );

		if ( in_array( $post->ID, array_map( 'absint', $excluded ), true ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'This page is not available as Markdown.', 'ai-seo-assistant' ), 403 );
		}

		if ( ! Indexability::is_indexable( $post ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'This page is not available as Markdown.', 'ai-seo-assistant' ), 403 );
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
	 * Serves Markdown at the page's own URL when the client asks for it with
	 * `Accept: text/markdown` — "Markdown for Agents" content negotiation.
	 *
	 * HTML stays the default: Markdown is served only when text/markdown is named
	 * explicitly and ranks above text/html. Browsers never send that.
	 *
	 * ⛔ Every branch that cannot serve Markdown RETURNS, so WordPress renders the normal
	 * HTML page. Negotiation must never turn a working page into a 403/404, unlike the
	 * explicit ?format=markdown endpoint above.
	 *
	 * ⛔ Page caches key on the URL, and this response shares the URL with the HTML page.
	 * If a cache stored it, browsers would be served Markdown. The protection that matters
	 * is EARLY: mark_markdown_request_uncacheable_early() defines DONOTCACHEPAGE and tells
	 * LiteSpeed not to cache at init priority 1, before Newfold/Endurance (init 10) decides
	 * whether to open its file-cache buffer — whatever this request goes on to return. Then:
	 *   - over the rate limit it falls back to HTML instead of emitting a 429 at this URL,
	 *   - it discards buffered output (bounded loop). Closing buffers is NOT a cache defence
	 *     on its own: ob_end_clean() still hands the buffer to a cache's write callback,
	 *   - it sends `Vary: Accept` and `Cache-Control: private` so shared caches keep it apart.
	 *
	 * It also sends no X-Robots-Tag: this is the canonical URL, and a noindex header here
	 * would deindex the page for any crawler that happened to ask for Markdown.
	 */
	private function maybe_serve_negotiated(): void {
		if ( ! Settings::get_option( 'enable_accept_negotiation', false ) || ! $this->wants_markdown() ) {
			return;
		}

		// is_eligible() applies the same gates as ?format=markdown: enabled, singular,
		// allowed type, not excluded, indexable.
		if ( ! $this->is_eligible() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof \WP_Post ) ) {
			return;
		}

		// Over the limit: serve the normal HTML page rather than a 429 at this URL.
		if ( ( new Rate_Limiter() )->is_limited() ) {
			return;
		}

		$markdown = Cache::get( Cache::post_key( $post->ID ) );
		if ( false === $markdown ) {
			$markdown = ( new Markdown_Converter() )->convert( $post );
			Cache::set( Cache::post_key( $post->ID ), $markdown, (int) Settings::get_option( 'cache_ttl_hours', 12 ) * HOUR_IN_SECONDS );
		}

		if ( ! is_string( $markdown ) || '' === trim( $markdown ) ) {
			return;   // nothing to serve: fall back to HTML
		}

		// DONOTCACHEPAGE and LiteSpeed no-cache were already set at init by
		// mark_markdown_request_uncacheable(); repeated here in case init ran without us.
		$this->mark_markdown_request_uncacheable();

		// Discard anything buffered. Bounded by ob_end_clean()'s own result: a buffer opened
		// without PHP_OUTPUT_HANDLER_REMOVABLE cannot be closed, and an unbounded loop would
		// spin until max_execution_time.
		while ( ob_get_level() > 0 && ob_end_clean() ) {
			continue;
		}

		$last_modified = strtotime( $post->post_modified_gmt );

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Vary: Accept' );
		header( 'Cache-Control: private, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Markdown-Tokens: ' . (int) ceil( strlen( $markdown ) / 4 ) );
		header( 'Link: <' . esc_url_raw( get_permalink( $post ) ) . '>; rel="canonical"', false );
		if ( $last_modified ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/markdown body, not HTML.
		echo $markdown;
		exit;
	}

	/**
	 * Init-time guard: when negotiation is on and the client asks for Markdown, keep every
	 * page cache away from this request. Reads only the Accept header, so it is valid at init.
	 */
	public function mark_markdown_request_uncacheable_early(): void {
		if ( Settings::get_option( 'enable_accept_negotiation', false ) && $this->wants_markdown() ) {
			$this->mark_markdown_request_uncacheable();
		}
	}

	/**
	 * Define DONOTCACHEPAGE and tell LiteSpeed Cache not to store this response.
	 */
	private function mark_markdown_request_uncacheable(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the de facto page-cache contract.
		}
		do_action( 'litespeed_control_set_nocache', 'ai-seo-assistant markdown negotiation' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
	}

	/**
	 * Whether the Accept header asks for Markdown ahead of HTML.
	 *
	 * text/markdown must be listed explicitly (a bare *\/* never counts) with a quality
	 * strictly higher than text/html's. "text/markdown, text/html;q=0.8" qualifies;
	 * "text/html, text/markdown" and "*\/*" do not.
	 *
	 * @return bool
	 */
	private function wants_markdown(): bool {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) ) : '';
		if ( false === strpos( $accept, 'text/markdown' ) ) {
			return false;
		}

		$q = [
			'text/markdown' => -1.0,
			'text/html'     => -1.0,
		];
		foreach ( explode( ',', $accept ) as $range ) {
			$parts = array_map( 'trim', explode( ';', $range ) );
			$type  = array_shift( $parts );
			if ( ! array_key_exists( $type, $q ) ) {
				continue;
			}
			$quality = 1.0;
			foreach ( $parts as $param ) {
				if ( 0 === strpos( $param, 'q=' ) ) {
					$quality = (float) substr( $param, 2 );
				}
			}
			$q[ $type ] = max( $q[ $type ], $quality );
		}

		return $q['text/markdown'] > 0 && $q['text/markdown'] > $q['text/html'];
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

		// This URL answers differently by Accept header, so every cache must key on it too.
		if ( Settings::get_option( 'enable_accept_negotiation', false ) ) {
			header( 'Vary: Accept', false );
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
