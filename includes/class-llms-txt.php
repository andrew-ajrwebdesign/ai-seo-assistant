<?php
/**
 * Generates and serves /llms.txt and /llms-full.txt endpoints.
 *
 * llms.txt      — index of all public content with titles, URLs, and excerpts.
 * llms-full.txt — same index with full Markdown content inlined (cached; large
 *                 sites should rely on the cached version rather than live generation).
 */

namespace AJR\MarkdownForAI;

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
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );
		add_filter( 'robots_txt', [ $this, 'add_robots_pointer' ], 10, 2 );
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
			wp_die( esc_html__( 'This endpoint is disabled.', 'wp-markdown-for-ai' ), 404 );
		}

		if ( ! $include_content && ! Settings::get_option( 'enable_llms_index', true ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'This endpoint is disabled.', 'wp-markdown-for-ai' ), 404 );
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

		$output  = "# {$site_name}\n\n";
		$output .= "> {$site_desc}\n\n";
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
				'update_post_term_cache' => false,
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

			$output .= "This file lists all public content on this site. Append ?format=markdown to any URL to retrieve its content as Markdown.\n\n";

			if ( $ai_instructions ) {
				$output .= "## Instructions\n\n";
				$output .= $ai_instructions . "\n\n";
			}

			$output .= "---\n\n";

			foreach ( $post_types as $post_type ) {
				$query_args = [
					'post_type'              => sanitize_key( $post_type ),
					'post_status'            => 'publish',
					'posts_per_page'         => 500,
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
					'orderby'                => 'date',
					'order'                  => 'DESC',
				];

				if ( ! empty( $excluded ) ) {
					$query_args['post__not_in'] = array_map( 'absint', $excluded );
				}

				if ( ! empty( $allowed_langs ) && function_exists( 'pll_get_post_language' ) ) {
					$query_args['lang'] = implode( ',', array_map( 'sanitize_key', $allowed_langs ) );
				}

				$posts = new \WP_Query( $query_args );

				if ( ! $posts->have_posts() ) {
					continue;
				}

				$type_obj = get_post_type_object( $post_type );
				$label    = $type_obj ? $type_obj->labels->name : ucfirst( $post_type );
				$section  = '';

				foreach ( $posts->posts as $post ) {
					if ( ! Indexability::is_indexable( $post ) ) {
						continue;
					}

					$title        = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$url          = get_permalink( $post );
					$markdown_url = add_query_arg( 'format', 'markdown', $url );
					$excerpt      = $this->get_excerpt( $post );

					$section .= "- [{$title}]({$markdown_url})";
					if ( $excerpt ) {
						$section .= ': ' . $excerpt;
					}
					$section .= "\n";
				}

				if ( $section ) {
					$output .= "## {$label}\n\n" . $section . "\n";
				}

				wp_reset_postdata();
			}
		}

		return $output;
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

		$output .= "\n# AI Agents\n";
		$output .= 'X-Llms-Txt: ' . esc_url( home_url( '/llms.txt' ) ) . "\n";

		return $output;
	}
}
