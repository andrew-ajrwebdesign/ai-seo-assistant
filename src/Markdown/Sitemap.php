<?php
/**
 * Registers a Markdown sitemap index entry and per-post Markdown URLs
 * inside WP core sitemaps, Yoast SEO, and The SEO Framework.
 *
 * Also adds a <loc>…/llms.txt</loc> link inside the existing sitemaps
 * rather than replacing them, so search engines are not disrupted.
 */

namespace AJR\SEOAssistant\Markdown;

defined( 'ABSPATH' ) || exit;

class Sitemap {

	public function register(): void {
		// Serve a standalone /llms-sitemap.xml for AI crawlers.
		// We intentionally do NOT hook into WP core sitemaps or Yoast — injecting
		// extra XML into Yoast sitemaps without the xmlns:xhtml namespace declaration
		// produces invalid XML that Google rejects. AI crawlers discover Markdown
		// URLs from /llms.txt directly; a separate /llms-sitemap.xml is sufficient.
		add_action( 'init', [ $this, 'register_rewrite' ] );
		// Priority 5 so core redirect_canonical (10) cannot 301 it to /llms-sitemap.xml/.
		add_action( 'template_redirect', [ $this, 'maybe_serve_sitemap' ], 5 );
		add_filter(
			'redirect_canonical',
			static function ( $redirect_url ) {
				return get_query_var( 'wpmai_llms_sitemap' ) ? false : $redirect_url;
			}
		);
	}

	/**
	 * Registers the /llms-sitemap.xml rewrite rule.
	 */
	public function register_rewrite(): void {
		add_rewrite_rule( '^llms-sitemap\.xml$', 'index.php?wpmai_llms_sitemap=1', 'top' );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'wpmai_llms_sitemap' ] ) );
	}

	/**
	 * Serves the /llms-sitemap.xml feed.
	 */
	public function maybe_serve_sitemap(): void {
		if ( ! get_query_var( 'wpmai_llms_sitemap' ) ) {
			return;
		}

		// Every <loc> below is a ?format=markdown URL, so the format toggle alone decides
		// whether this sitemap may exist - with it off, each listed URL 404s. Defaults ON,
		// like the endpoint itself. Previously this also read 'enable_llms_txt', a key
		// sanitize() never writes, which made the check depend on a setting that never exists.
		$settings  = get_option( 'wpmai_settings', [] );
		$format_on = (bool) ( $settings['enable_format_param'] ?? true );
		if ( ! $format_on ) {
			wp_die( esc_html__( 'WP Markdown for AI endpoints are disabled.', 'ai-seo-assistant' ), '', 403 );
		}

		$post_types   = Settings::allowed_post_types();
		$excluded_ids = array_map( 'absint', (array) ( $settings['excluded_ids'] ?? [] ) );

		$query = new \WP_Query(
			[
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'post__not_in'           => $excluded_ids, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true, // true: wpmai_is_post_indexable filters may read terms (e.g. hidden products); one batch query, not one per post.
				'ignore_sticky_posts'    => true,
			]
		);

		$urls = [];
		foreach ( $query->posts as $post ) {
			if ( ! Indexability::is_indexable( $post ) ) {
				continue;
			}
			$urls[] = [
				'loc'      => add_query_arg( 'format', 'markdown', get_permalink( $post ) ),
				'lastmod'  => gmdate( 'Y-m-d', strtotime( $post->post_modified_gmt ) ),
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			];
		}

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		echo '        xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";

		foreach ( $urls as $url ) {
			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $url['loc'] ) . "</loc>\n";
			echo "\t\t<lastmod>" . esc_html( $url['lastmod'] ) . "</lastmod>\n";
			echo "\t\t<dc:title>" . esc_html( $url['title'] ) . "</dc:title>\n";
			echo "\t</url>\n";
		}

		echo '</urlset>';
		exit;
	}
}
