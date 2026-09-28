<?php
/**
 * Generates and serves /llms.txt and /llms-full.txt endpoints.
 *
 * llms.txt      — index of all public content with titles, URLs, and excerpts.
 * llms-full.txt — same index with full Markdown content inlined (cached; large
 *                 sites should rely on the cached version rather than live generation).
 */

namespace AJR\SEOAssistant\Markdown;

defined( 'ABSPATH' ) || exit;

class Llms_Txt {

	/**
	 * Maximum number of posts processed per post type for llms-full.txt.
	 * Prevents memory exhaustion on very large sites.
	 */
	private const FULL_POST_LIMIT = 200;

	public function register(): void {
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		// Priority 5: after Redirect_Handler (1), before core redirect_canonical (10).
		// At the default 10, canonical ran first and 301'd /llms.txt to /llms.txt/ on
		// every /%postname%/ site - agents and Lighthouse request the exact path.
		add_action( 'template_redirect', [ $this, 'maybe_serve' ], 5 );
		add_filter( 'redirect_canonical', [ $this, 'keep_exact_path' ] );
		// Priority 20: The SEO Framework's robots_txt filter runs at 10 (registered later, on
		// init) and rebuilds the file from an empty string, discarding this pointer on every TSF
		// site (4.2.0; preflight `robots_txt priority`).
		add_filter( 'robots_txt', [ $this, 'add_robots_pointer' ], 20, 2 );
	}

	/**
	 * Stops WordPress adding a trailing slash to /llms.txt and /llms-full*.txt.
	 *
	 * Belt and braces with the priority-5 serve: if another plugin moves
	 * redirect_canonical earlier, the endpoint still answers at its exact path.
	 *
	 * @param string|false $redirect_url Canonical URL WordPress intends to redirect to.
	 * @return string|false
	 */
	public function keep_exact_path( $redirect_url ) {
		return get_query_var( 'wpmai_llms' ) ? false : $redirect_url;
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'wpmai_llms';
		$vars[] = 'wpmai_page';
		return $vars;
	}

	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^llms-full-([0-9]+)\.txt$', 'index.php?wpmai_llms=full&wpmai_page=$matches[1]', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?wpmai_llms=full', 'top' );
		add_rewrite_rule( '^llms\.txt$', 'index.php?wpmai_llms=index', 'top' );
	}

	public function maybe_serve(): void {
		$llms = get_query_var( 'wpmai_llms' );

		if ( ! $llms ) {
			return;
		}

		$include_content = ( 'full' === $llms );

		// Respect endpoint toggles.
		if ( $include_content && ! Settings::get_option( 'enable_llms_full', true ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'This endpoint is disabled.', 'ai-seo-assistant' ), 404 );
		}

		if ( ! $include_content && ! Settings::get_option( 'enable_llms_index', true ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'This endpoint is disabled.', 'ai-seo-assistant' ), 404 );
		}

		// Rate limiting.
		( new Rate_Limiter() )->check();

		$page      = $include_content ? max( 1, (int) get_query_var( 'wpmai_page', 1 ) ) : 1;
		$cache_key = $include_content ? 'llms_full_p' . $page : 'llms_index';
		$ttl       = $this->get_ttl();

		// Last-Modified: use the most recently modified post across all tracked types.
		$last_modified = $this->get_last_modified_timestamp();

		// Conditional GET — 304 if client has current version.
		$if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $if_modified_since && $last_modified ) {
			$client_time = strtotime( $if_modified_since );
			if ( $client_time !== false && $last_modified <= $client_time ) {
				status_header( 304 );
				exit;
			}
		}

		$cached = Cache::get( $cache_key );

		if ( false === $cached ) {
			$cached = $this->build( $include_content, $page );
			Cache::set( $cache_key, $cached, $ttl );
		}

		$this->send_headers( $ttl, $last_modified );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $cached;
		exit;
	}

	/**
	 * Returns the Unix timestamp of the most recently modified indexable post.
	 *
	 * Uses a lightweight query (no post content) for speed.
	 *
	 * @return int Unix timestamp, or 0 if none found.
	 */
	private function get_last_modified_timestamp(): int {
		$post_types = Settings::get_option( 'post_types', [ 'post', 'page' ] );

		$query = new \WP_Query(
			[
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			]
		);

		if ( empty( $query->posts ) ) {
			return 0;
		}

		return (int) strtotime( $query->posts[0]->post_modified_gmt );
	}

	/**
	 * Sends appropriate HTTP headers for the plain-text response.
	 *
	 * @param int $ttl           Cache TTL in seconds, used for Cache-Control max-age.
	 * @param int $last_modified Unix timestamp of last content modification.
	 */
	private function send_headers( int $ttl, int $last_modified = 0 ): void {
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=' . $ttl );

		if ( $last_modified > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT' );
		}
	}

	/**
	 * Returns the configured cache TTL in seconds.
	 *
	 * @return int
	 */
	private function get_ttl(): int {
		$hours = (int) Settings::get_option( 'cache_ttl_hours', 12 );
		return max( 1, $hours ) * HOUR_IN_SECONDS;
	}

	/**
	 * Builds the llms.txt content string.
	 *
	 * For full-content mode, $page drives pagination: llms-full.txt = page 1,
	 * llms-full-2.txt = page 2, etc. Each page links to the next/previous.
	 *
	 * @param bool $include_content Whether to inline full Markdown content.
	 * @param int  $page            Page number for full-content pagination (1-based).
	 * @return string
	 */
	private function build( bool $include_content = false, int $page = 1 ): string {
		$site_name       = get_bloginfo( 'name' );
		$site_desc       = get_bloginfo( 'description' );
		$site_url        = home_url();
		$post_types      = Settings::get_option( 'post_types', [ 'post', 'page' ] );
		$excluded        = Settings::get_option( 'excluded_ids', [] );
		$ai_instructions = trim( (string) Settings::get_option( 'ai_instructions', '' ) );
		$allowed_langs   = Settings::get_option( 'polylang_languages', [] );

		$summary = $this->get_summary( (string) $site_desc );

		// llmstxt.org shape: H1 name, then a blockquote summary. An empty "> " line is
		// worse than none, so the blockquote is only written when there is a summary.
		$output = "# {$site_name}\n\n";
		if ( '' !== $summary ) {
			$output .= "> {$summary}\n\n";
		}
		$output .= "Site: {$site_url}\n";
		$output .= 'Generated: ' . gmdate( 'Y-m-d\TH:i:s\Z' ) . "\n\n";

		if ( $include_content ) {
			// --- Full-content mode: single paginated query across all post types ---

			$per_page = max( 1, (int) Settings::get_option( 'full_post_limit', self::FULL_POST_LIMIT ) );
			$page     = max( 1, $page );

			$query_args = [
				'post_type'              => array_map( 'sanitize_key', (array) $post_types ),
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true, // true: wpmai_is_post_indexable filters may read terms (e.g. hidden products); one batch query, not one per post.
				'orderby'                => 'date',
				'order'                  => 'DESC',
			];

			if ( ! empty( $excluded ) ) {
				$query_args['post__not_in'] = array_map( 'absint', $excluded );
			}

			if ( ! empty( $allowed_langs ) && function_exists( 'pll_get_post_language' ) ) {
				$query_args['lang'] = implode( ',', array_map( 'sanitize_key', $allowed_langs ) );
			}

			$posts       = new \WP_Query( $query_args );
			$total_pages = (int) $posts->max_num_pages;

			// Navigation header block.
			$output .= "This file contains the full content of all public pages and posts on this site in Markdown format.\n\n";

			if ( $ai_instructions ) {
				$output .= "## Instructions\n\n";
				$output .= $ai_instructions . "\n\n";
			}

			$output .= $this->build_page_nav( $page, $total_pages );
			$output .= "---\n\n";

			$converter = new Markdown_Converter();

			// Group posts by type so they stay under section headings.
			$by_type = [];
			foreach ( $posts->posts as $post ) {
				if ( Indexability::is_indexable( $post ) ) {
					$by_type[ $post->post_type ][] = $post;
				}
			}

			foreach ( $by_type as $post_type => $type_posts ) {
				$type_obj = get_post_type_object( $post_type );
				$label    = $type_obj ? $type_obj->labels->name : ucfirst( $post_type );
				$section  = '';

				foreach ( $type_posts as $post ) {
					$title     = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$url       = get_permalink( $post );
					$cached_md = Cache::get( Cache::post_key( $post->ID ) );

					if ( false === $cached_md ) {
						$cached_md = $converter->convert( $post );
						Cache::set( Cache::post_key( $post->ID ), $cached_md, $this->get_ttl() );
					}

					$section .= "### {$title}\n\n";
					$section .= "URL: {$url}\n\n";
					$section .= $cached_md . "\n\n";
					$section .= "---\n\n";
				}

				if ( $section ) {
					$output .= "## {$label}\n\n" . $section . "\n";
				}
			}

			wp_reset_postdata();

			// Navigation footer block.
			$output .= $this->build_page_nav( $page, $total_pages );

		} else {
			// --- Index mode: list of links with excerpts, grouped by post type ---

			$output .= Settings::get_option( 'enable_format_param', true )
				? "This file lists all public content on this site. Append ?format=markdown to any URL to retrieve its content as Markdown.\n\n"
				: "This file lists all public content on this site.\n\n";

			if ( $ai_instructions ) {
				$output .= "## Instructions\n\n";
				$output .= $ai_instructions . "\n\n";
			}

			$output .= "---\n\n";

			// Key pages first: the handful an agent should read before anything else.
			$key_ids = array_values( array_filter( array_map( 'absint', (array) Settings::get_option( 'llms_key_page_ids', [] ) ) ) );
			$listed  = [];

			if ( $key_ids ) {
				// One query for the posts and one for their meta, instead of two per key page.
				_prime_post_caches( $key_ids, false, true );

				$section = '';
				foreach ( $key_ids as $key_id ) {
					$key_post = get_post( $key_id );
					// Same gates as every other list: an enabled, viewable post type, not excluded.
					// Without them a mistyped ID could publish e.g. a WooCommerce coupon title here.
					if ( ! $key_post
						|| 'publish' !== $key_post->post_status
						|| ! in_array( $key_post->post_type, array_map( 'sanitize_key', (array) $post_types ), true )
						|| ! is_post_type_viewable( $key_post->post_type )
						|| in_array( $key_id, array_map( 'absint', (array) $excluded ), true )
						|| ! Indexability::is_indexable( $key_post )
					) {
						continue;
					}
					$section          .= $this->link_line( $key_post );
					$listed[ $key_id ] = true;
				}
				if ( $section ) {
					$output .= "## Key pages\n\n" . $section . "\n";
				}
			}

			// Types marked optional go under llmstxt.org's "## Optional" heading, which tells
			// agents the links can be skipped when context is short.
			$optional_types = array_map( 'sanitize_key', (array) Settings::get_option( 'llms_optional_post_types', [] ) );
			$primary_types  = array_values( array_diff( array_map( 'sanitize_key', (array) $post_types ), $optional_types ) );
			$optional_types = array_values( array_intersect( $optional_types, array_map( 'sanitize_key', (array) $post_types ) ) );

			foreach ( $primary_types as $post_type ) {
				$section = $this->type_section( $post_type, $excluded, $allowed_langs, $listed );
				if ( $section ) {
					$type_obj = get_post_type_object( $post_type );
					$label    = $type_obj ? $type_obj->labels->name : ucfirst( $post_type );
					$output  .= "## {$label}\n\n" . $section . "\n";
				}
			}

			$optional = '';
			foreach ( $optional_types as $post_type ) {
				$optional .= $this->type_section( $post_type, $excluded, $allowed_langs, $listed );
			}
			if ( $optional ) {
				$output .= "## Optional\n\n" . $optional . "\n";
			}
		}

		return $output;
	}

	/**
	 * Builds the Markdown link lines for one post type in index mode.
	 *
	 * Posts already listed (e.g. under "Key pages") are skipped so no link appears twice.
	 * Exclusions are filtered in PHP rather than with post__not_in, which is cheaper and
	 * cache-friendlier on the query.
	 *
	 * @param string $post_type     Post type slug.
	 * @param array  $excluded      Post IDs excluded in settings.
	 * @param array  $allowed_langs Polylang language slugs, if restricted.
	 * @param array  $listed        Map of post ID => true already written; updated in place.
	 * @return string Link lines, or '' when the type has nothing indexable.
	 */
	private function type_section( string $post_type, array $excluded, array $allowed_langs, array &$listed ): string {
		$query_args = [
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => 500,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true, // true: wpmai_is_post_indexable filters may read terms (e.g. hidden products); one batch query, not one per post.
			'orderby'                => 'date',
			'order'                  => 'DESC',
		];

		if ( ! empty( $allowed_langs ) && function_exists( 'pll_get_post_language' ) ) {
			$query_args['lang'] = implode( ',', array_map( 'sanitize_key', $allowed_langs ) );
		}

		$skip  = array_fill_keys( array_map( 'absint', $excluded ), true );
		$posts = new \WP_Query( $query_args );
		$lines = '';

		foreach ( $posts->posts as $post ) {
			if ( isset( $skip[ $post->ID ] ) || isset( $listed[ $post->ID ] ) || ! Indexability::is_indexable( $post ) ) {
				continue;
			}
			$lines              .= $this->link_line( $post );
			$listed[ $post->ID ] = true;
		}

		wp_reset_postdata();

		return $lines;
	}

	/**
	 * One llms.txt list item: "- [Title](url): description".
	 *
	 * The link must be Markdown link syntax - Lighthouse's llms-txt audit counts only
	 * [text](url) links. The URL is the page itself or its ?format=markdown twin,
	 * per the llms_link_target setting (default markdown, the historic behaviour).
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private function link_line( \WP_Post $post ): string {
		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = str_replace( [ '[', ']' ], [ '(', ')' ], $title );
		$url   = get_permalink( $post );

		// Markdown links only while the ?format endpoint is on; otherwise they would all 404.
		if ( 'page' !== Settings::get_option( 'llms_link_target', 'markdown' ) && Settings::get_option( 'enable_format_param', true ) ) {
			$url = add_query_arg( 'format', 'markdown', $url );
		}

		$line    = "- [{$title}]({$url})";
		$excerpt = $this->get_excerpt( $post );

		return $line . ( $excerpt ? ': ' . $excerpt : '' ) . "\n";
	}

	/**
	 * The blockquote summary under the H1.
	 *
	 * Priority: the llms_summary setting, then the SEO plugin's homepage description,
	 * then the WordPress tagline. The tagline is last because block themes often leave
	 * it blank or as a slogan, which describes nothing to an agent.
	 *
	 * @param string $tagline WordPress site tagline.
	 * @return string Single-line summary, or '' when nothing usable exists.
	 */
	private function get_summary( string $tagline ): string {
		$summary = trim( (string) Settings::get_option( 'llms_summary', '' ) );

		if ( '' === $summary ) {
			$tsf     = get_option( 'autodescription-site-settings' );
			$summary = is_array( $tsf ) ? trim( (string) ( $tsf['homepage_description'] ?? '' ) ) : '';
		}

		if ( '' === $summary && 'page' === get_option( 'show_on_front' ) ) {
			$front = get_post( (int) get_option( 'page_on_front' ) );
			// Only a published, indexable front page may lend its description to a public file.
			if ( $front && 'publish' === $front->post_status && Indexability::is_indexable( $front ) ) {
				$summary = $this->get_seo_description( (int) $front->ID );
			}
		}

		if ( '' === $summary ) {
			$summary = trim( $tagline );
		}

		// A blockquote is one line in llms.txt; collapse any newlines from a textarea.
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( $summary, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * The page's hand-written SEO meta description, if the active SEO plugin has one.
	 *
	 * Reads the stored field directly for TSF, Yoast and Rank Math. Templated values
	 * (Yoast %%title%%, Rank Math %title%) are ignored rather than shown unexpanded.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_seo_description( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		foreach ( [ '_genesis_description', '_yoast_wpseo_metadesc', 'rank_math_description' ] as $meta_key ) {
			$value = trim( (string) get_post_meta( $post_id, $meta_key, true ) );
			if ( '' !== $value && ! preg_match( '/%%?[a-z_]+%%?/i', $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Returns a plain-text navigation block for paginated llms-full pages.
	 *
	 * @param int $page        Current page number (1-based).
	 * @param int $total_pages Total number of pages.
	 * @return string
	 */
	private function build_page_nav( int $page, int $total_pages ): string {
		if ( $total_pages <= 1 ) {
			return '';
		}

		$nav = "Page {$page} of {$total_pages}\n";

		if ( $page > 1 ) {
			$prev_file = 1 === ( $page - 1 ) ? 'llms-full.txt' : 'llms-full-' . ( $page - 1 ) . '.txt';
			$nav      .= 'Previous page: ' . home_url( '/' . $prev_file ) . "\n";
		}

		if ( $page < $total_pages ) {
			$next_file = 'llms-full-' . ( $page + 1 ) . '.txt';
			$nav      .= 'Next page: ' . home_url( '/' . $next_file ) . "\n";
		}

		return $nav . "\n";
	}

	/**
	 * Returns a short plain-text excerpt for a post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private function get_excerpt( \WP_Post $post ): string {
		$length = (int) Settings::get_option( 'excerpt_length', 20 );

		// A written meta description beats an excerpt stripped out of block markup.
		$seo = $this->get_seo_description( (int) $post->ID );
		if ( '' !== $seo ) {
			// One line, no tags: a newline in a meta value must not add its own "## " or "- [..]"
			// line to the file agents read first.
			return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $seo, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
		}

		if ( $post->post_excerpt ) {
			$text = wp_strip_all_tags( $post->post_excerpt );
		} else {
			$text = wp_strip_all_tags( $post->post_content );
			$text = preg_replace( '/\s+/', ' ', $text );
		}

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return wp_trim_words( $text, $length, '...' );
	}

	/**
	 * Appends an llms.txt pointer to the WordPress-generated robots.txt.
	 *
	 * @param string $output  Current robots.txt content.
	 * @param bool   $public  Whether the site is public.
	 * @return string
	 */
	public function add_robots_pointer( string $output, bool $public ): string {
		if ( ! $public ) {
			return $output;
		}

		// A comment, not a directive: robots.txt parsers (Lighthouse's SEO audit among them)
		// reject unknown directives, and "X-Llms-Txt:" is not one. Agents still find the pointer.
		$output .= "\n# AI agents\n";
		$output .= '# llms.txt: ' . esc_url( home_url( '/llms.txt' ) ) . "\n";

		return $output;
	}
}
