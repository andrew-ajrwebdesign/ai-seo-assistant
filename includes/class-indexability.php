<?php
/**
 * Determines whether a post should be exposed via Markdown endpoints.
 *
 * Respects the site's existing SEO decisions — if a page is noindex,
 * it should not be served to AI agents either.
 */

namespace AJR\MarkdownForAI;

defined( 'ABSPATH' ) || exit;

class Indexability {

	/**
	 * Returns true if the post should be included in Markdown endpoints.
	 *
	 * Checks (in order):
	 *  1. Site is set to discourage search engines
	 *  2. Post is password-protected
	 *  3. SEO plugin noindex meta (TSF, Yoast, RankMath, AIOSEO)
	 *  4. Custom filter for developer overrides
	 *
	 * @param \WP_Post $post Post to check.
	 * @return bool
	 */
	public static function is_indexable( \WP_Post $post ): bool {
		// Site-level: respect "Discourage search engines" WP setting.
		if ( ! get_option( 'blog_public' ) ) {
			return false;
		}

		// Never expose password-protected content.
		if ( ! empty( $post->post_password ) ) {
			return false;
		}

		// Check SEO plugin noindex signals.
		if ( self::is_noindex( $post ) ) {
			return false;
		}

		/**
		 * Filters whether a post is indexable by WP Markdown for AI.
		 *
		 * @param bool     $indexable Whether the post should be included.
		 * @param \WP_Post $post      The post being checked.
		 */
		return (bool) apply_filters( 'wpmai_is_post_indexable', true, $post );
	}

	/**
	 * Returns true if any active SEO plugin has marked this post as noindex.
	 *
	 * @param \WP_Post $post Post to check.
	 * @return bool
	 */
	private static function is_noindex( \WP_Post $post ): bool {
		// The SEO Framework (autodescription).
		if ( self::tsf_is_noindex( $post ) ) {
			return true;
		}

		// Yoast SEO.
		if ( self::yoast_is_noindex( $post ) ) {
			return true;
		}

		// RankMath.
		if ( self::rankmath_is_noindex( $post ) ) {
			return true;
		}

		// All in One SEO.
		if ( self::aioseo_is_noindex( $post ) ) {
			return true;
		}

		return false;
	}

	/**
	 * The SEO Framework noindex check.
	 *
	 * Checks post meta directly — TSF stores noindex as _genesis_noindex = '1'.
	 * Falls back to the TSF API for forward compatibility with future versions.
	 *
	 * @param \WP_Post $post Post to check.
	 * @return bool
	 */
	private static function tsf_is_noindex( \WP_Post $post ): bool {
		if ( ! defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			return false;
		}

		// Check post meta directly — confirmed key used by TSF.
		$noindex = get_post_meta( $post->ID, '_genesis_noindex', true );
		if ( '1' === (string) $noindex ) {
			return true;
		}

		// TSF API fallback for future versions that may change the meta key.
		if ( function_exists( 'the_seo_framework' ) ) {
			$tsf = the_seo_framework();
			if ( method_exists( $tsf, 'is_post_robots_meta_noindex' ) ) {
				return (bool) $tsf->is_post_robots_meta_noindex( $post->ID );
			}
		}

		return false;
	}

	/**
	 * Yoast SEO noindex check.
	 *
	 * @param \WP_Post $post Post to check.
	 * @return bool
	 */
	private static function yoast_is_noindex( \WP_Post $post ): bool {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return false;
		}

		$noindex = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );

		// Yoast stores '1' for noindex, '2' for index (override), '' for default.
		return '1' === (string) $noindex;
	}

	/**
	 * RankMath noindex check.
	 *
	 * @param \WP_Post $post Post to check.
	 * @return bool
	 */
	private static function rankmath_is_noindex( \WP_Post $post ): bool {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return false;
		}

		$robots = get_post_meta( $post->ID, 'rank_math_robots', true );

		if ( ! is_array( $robots ) ) {
			return false;
		}

		return in_array( 'noindex', $robots, true );
	}

	/**
	 * All in One SEO noindex check.
	 *
	 * @param \WP_Post $post Post to check.
	 * @return bool
	 */
	private static function aioseo_is_noindex( \WP_Post $post ): bool {
		if ( ! defined( 'AIOSEO_VERSION' ) ) {
			return false;
		}

		$noindex = get_post_meta( $post->ID, '_aioseo_robots_noindex', true );

		return '1' === (string) $noindex;
	}
}
