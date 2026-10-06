<?php
/**
 * Adapter for Rank Math SEO metadata fields.
 */

namespace AJR\SEOAssistant\Adapters;

defined( 'ABSPATH' ) || exit;

class RankMath_Adapter {

	const TITLE_FIELD       = 'rank_math_title';
	const DESCRIPTION_FIELD = 'rank_math_description';
	const ROBOTS_FIELD      = 'rank_math_robots';
	const KEYPHRASE_FIELD   = 'rank_math_focus_keyword';

	public function get_id() {
		return 'rank_math';
	}

	public function get_name() {
		return 'Rank Math';
	}

	public function get_title( $post_id ) {
		return get_post_meta( $post_id, self::TITLE_FIELD, true );
	}

	public function get_description( $post_id ) {
		return get_post_meta( $post_id, self::DESCRIPTION_FIELD, true );
	}

	public function save_title( $post_id, $title ) {
		update_post_meta(
			$post_id,
			self::TITLE_FIELD,
			wp_slash( sanitize_text_field( $title ) )
		);
	}

	public function save_description( $post_id, $description ) {
		update_post_meta(
			$post_id,
			self::DESCRIPTION_FIELD,
			wp_slash( sanitize_textarea_field( $description ) )
		);
	}

	public function get_robots_value( $post_id ) {
		return get_post_meta( $post_id, self::ROBOTS_FIELD, true );
	}

	public function get_indexing_status( $post_id ) {
		$post_status = get_post_status( $post_id );

		if ( ! in_array( $post_status, [ 'publish' ], true ) ) {
			return 'Not public';
		}

		$robots = $this->get_robots_value( $post_id );

		if ( $this->is_noindex_enabled( $robots ) ) {
			return 'Noindex';
		}

		return 'Indexable';
	}

	private function is_noindex_enabled( $value ) {
		if ( is_array( $value ) ) {
			$value = array_map( 'strtolower', array_map( 'trim', $value ) );

			return in_array( 'noindex', $value, true );
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );

			if ( false !== strpos( $value, 'noindex' ) ) {
				return true;
			}
		}

		return false;
	}

	public function is_available() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) || class_exists( '\RankMath\Runner' );
	}

	/**
	 * Whether this SEO plugin has a focus keyphrase field (5.0 page review).
	 *
	 * @return bool
	 */
	public function supports_keyphrase() {
		return true;
	}

	/**
	 * The primary focus keyword (Rank Math keeps a comma-separated list; the first is the primary).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function get_keyphrase( $post_id ) {
		$list = explode( ',', (string) get_post_meta( $post_id, self::KEYPHRASE_FIELD, true ) );

		return trim( (string) $list[0] );
	}

	/**
	 * Replace the primary focus keyword and keep any secondary ones.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $keyphrase Keyphrase ('' removes the primary).
	 */
	public function save_keyphrase( $post_id, $keyphrase ) {
		$keyphrase = str_replace( ',', ' ', sanitize_text_field( $keyphrase ) );
		$list      = array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $post_id, self::KEYPHRASE_FIELD, true ) ) ) );
		$rest      = array_slice( array_values( $list ), 1 );
		$value     = implode( ',', array_filter( array_merge( [ trim( $keyphrase ) ], $rest ) ) );
		if ( '' === $value ) {
			delete_post_meta( $post_id, self::KEYPHRASE_FIELD );
			return;
		}
		update_post_meta( $post_id, self::KEYPHRASE_FIELD, wp_slash( $value ) );
	}

	public function is_noindex( $post_id ) {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );

		if ( ! is_array( $robots ) ) {
			return false;
		}

		return in_array( 'noindex', $robots, true );
	}
}
