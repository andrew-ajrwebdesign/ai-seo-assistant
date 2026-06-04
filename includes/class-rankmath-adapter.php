<?php
/**
 * Adapter for Rank Math SEO metadata fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_RankMath_Adapter {

	const TITLE_FIELD       = 'rank_math_title';
	const DESCRIPTION_FIELD = 'rank_math_description';
	const ROBOTS_FIELD      = 'rank_math_robots';

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
			sanitize_text_field( $title )
		);
	}

	public function save_description( $post_id, $description ) {
		update_post_meta(
			$post_id,
			self::DESCRIPTION_FIELD,
			sanitize_textarea_field( $description )
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

	public function save_noindex( $post_id, $noindex = true ) {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );

		if ( ! is_array( $robots ) ) {
			$robots = [];
		}

		if ( $noindex ) {
			$robots[] = 'noindex';
			$robots   = array_values( array_unique( $robots ) );

			update_post_meta( $post_id, 'rank_math_robots', $robots );
			return;
		}

		$robots = array_values(
			array_filter(
				$robots,
				function ( $robot ) {
					return 'noindex' !== $robot;
				}
			)
		);

		if ( empty( $robots ) ) {
			delete_post_meta( $post_id, 'rank_math_robots' );
		} else {
			update_post_meta( $post_id, 'rank_math_robots', $robots );
		}
	}

	public function is_noindex( $post_id ) {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );

		if ( ! is_array( $robots ) ) {
			return false;
		}

		return in_array( 'noindex', $robots, true );
	}
}