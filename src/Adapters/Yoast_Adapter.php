<?php
/**
 * Adapter for Yoast SEO metadata fields.
 */

namespace AJR\SEOAssistant\Adapters;

defined( 'ABSPATH' ) || exit;

class Yoast_Adapter {

	const TITLE_FIELD       = '_yoast_wpseo_title';
	const DESCRIPTION_FIELD = '_yoast_wpseo_metadesc';
	const NOINDEX_FIELD     = '_yoast_wpseo_meta-robots-noindex';
	const KEYPHRASE_FIELD   = '_yoast_wpseo_focuskw';

	public function get_id() {
		return 'yoast';
	}

	public function get_name() {
		return 'Yoast SEO';
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
		return defined( 'WPSEO_VERSION' ) || defined( 'YOAST_SEO_VERSION' ) || class_exists( 'WPSEO_Options' );
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
	 * The page's focus keyphrase.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function get_keyphrase( $post_id ) {
		return (string) get_post_meta( $post_id, self::KEYPHRASE_FIELD, true );
	}

	/**
	 * Save the focus keyphrase ('' removes it).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $keyphrase Keyphrase.
	 */
	public function save_keyphrase( $post_id, $keyphrase ) {
		$keyphrase = sanitize_text_field( $keyphrase );
		if ( '' === $keyphrase ) {
			delete_post_meta( $post_id, self::KEYPHRASE_FIELD );
			return;
		}
		update_post_meta( $post_id, self::KEYPHRASE_FIELD, wp_slash( $keyphrase ) );
	}

	public function is_noindex( $post_id ) {
		return '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
	}
}
