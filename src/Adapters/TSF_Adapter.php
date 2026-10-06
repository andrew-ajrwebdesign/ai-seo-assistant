<?php
/**
 * Adapter for The SEO Framework metadata fields.
 */

namespace AJR\SEOAssistant\Adapters;

defined( 'ABSPATH' ) || exit;

class TSF_Adapter {

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
		return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || class_exists( 'The_SEO_Framework\Load' );
	}

	/**
	 * The SEO Framework has no focus keyphrase field (only its paid Focus extension does), so the page
	 * review shows the keyphrase suggestion as advice and never writes it.
	 *
	 * @return bool
	 */
	public function supports_keyphrase() {
		return false;
	}

	/**
	 * No keyphrase field.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function get_keyphrase( $post_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- adapter interface.
		return '';
	}

	/**
	 * No keyphrase field: nothing is written.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $keyphrase Keyphrase.
	 */
	public function save_keyphrase( $post_id, $keyphrase ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- adapter interface.
	}

	public function is_noindex( $post_id ) {
		return (bool) get_post_meta( $post_id, '_genesis_noindex', true );
	}
}
