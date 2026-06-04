<?php
/**
 * Adapter for The SEO Framework metadata fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_TSF_Adapter {

	const TITLE_FIELD       = '_genesis_title';
	const DESCRIPTION_FIELD = '_genesis_description';
	const NOINDEX_FIELD     = '_genesis_noindex';

	public function get_id() {
		return 'the_seo_framework';
	}

	public function get_name() {
		return 'The SEO Framework';
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

	public function get_noindex_value( $post_id ) {
		return get_post_meta( $post_id, self::NOINDEX_FIELD, true );
	}

	public function get_indexing_status( $post_id ) {
		$post_status = get_post_status( $post_id );

		if ( ! in_array( $post_status, [ 'publish' ], true ) ) {
			return 'Not public';
		}

		$noindex = $this->get_noindex_value( $post_id );

		if ( $this->is_noindex_enabled( $noindex ) ) {
			return 'Noindex';
		}

		return 'Indexable';
	}

	private function is_noindex_enabled( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : $value;

		$enabled_values = [
			'1',
			1,
			true,
			'true',
			'yes',
			'on',
			'enabled',
			'noindex',
		];

		return in_array( $value, $enabled_values, true );
	}

	public function is_available() {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || class_exists( 'The_SEO_Framework\Load' );
	}

	public function save_noindex( $post_id, $noindex = true ) {
		if ( $noindex ) {
			update_post_meta( $post_id, '_genesis_noindex', 1 );
		} else {
			delete_post_meta( $post_id, '_genesis_noindex' );
		}
	}

	public function is_noindex( $post_id ) {
		return (bool) get_post_meta( $post_id, '_genesis_noindex', true );
	}
}