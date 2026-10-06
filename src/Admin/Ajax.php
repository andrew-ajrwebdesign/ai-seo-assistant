<?php
/**
 * AJAX handlers for the editor box (agency only): metadata, recommendations, focus suggestion.
 *
 * @package AJR\SEOAssistant
 */

namespace AJR\SEOAssistant\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The editor box's AJAX actions. Each checks the editor box nonce first (check_ajax_referer).
 */
class Ajax {

	/**
	 * Writes metadata and recommendations.
	 *
	 * @var \AJR\SEOAssistant\AI\Metadata_Generator
	 */
	private $metadata_generator;

	/**
	 * Build.
	 *
	 * @param \AJR\SEOAssistant\AI\Metadata_Generator $metadata_generator Generator.
	 */
	public function __construct( $metadata_generator ) {
		$this->metadata_generator = $metadata_generator;
	}

	/**
	 * Register the AJAX actions.
	 */
	public function init() {
		add_action( 'wp_ajax_ai_seo_assistant_generate', [ $this, 'generate_metadata' ] );
		add_action( 'wp_ajax_ai_seo_assistant_generate_recommendations', [ $this, 'generate_recommendations' ] );
		add_action( 'wp_ajax_ai_seo_assistant_suggest_focus', [ $this, 'suggest_focus' ] );
	}

	/**
	 * Lets a Claude request run to its own timeout.
	 *
	 * On Linux, time spent waiting on the network does not count toward
	 * max_execution_time, but on Windows hosts and PHP builds with wall-clock
	 * timers it does, and a 30 s limit would kill a 45-90 s request mid-way.
	 * Some hosts disable set_time_limit(), hence the function_exists() guard.
	 */
	private function allow_long_request() {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}
	}

	/**
	 * AJAX: write a title and description for one post.
	 */
	public function generate_metadata() {
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );
		$this->allow_long_request();

		$post_id = $this->get_valid_post_id();

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error(
				[
					'message' => $post_id->get_error_message(),
				]
			);
		}

		$result = $this->metadata_generator->generate( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
				]
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: page recommendations for one post.
	 */
	public function generate_recommendations() {
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );
		$this->allow_long_request();

		$post_id = $this->get_valid_post_id();

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error(
				[
					'message' => $post_id->get_error_message(),
				]
			);
		}

		$result = $this->metadata_generator->generate_recommendations( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
				]
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * The posted post ID; every caller has checked the nonce first.
	 *
	 * @return int|\WP_Error
	 */
	private function get_valid_post_id() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each caller ran check_ajax_referer() first.

		if ( ! $post_id ) {
			return new \WP_Error(
				'ai_seo_missing_post_id',
				'Missing post ID.'
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'ai_seo_permission_denied',
				'You do not have permission to edit this post.'
			);
		}

		// Every call here spends the agency's Claude key, so only agency users may make one
		// (4.3.0). Every AJAX action passes through this check, so one gate covers them all.
		if ( ! current_user_can( \AJR\SEOAssistant\Report\Access::TOOLS_CAP ) ) {
			return new \WP_Error(
				'ai_seo_agency_only',
				'The AI SEO tools are available to agency users only.'
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'ai_seo_post_not_found',
				'Post not found.'
			);
		}

		return $post_id;
	}

	/**
	 * AJAX: suggest the focus keyphrase for one post.
	 */
	public function suggest_focus() {
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		$post_id = $this->get_valid_post_id();

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error(
				[
					'message' => $post_id->get_error_message(),
				]
			);
		}

		if ( ! method_exists( $this->metadata_generator, 'suggest_focus_fields' ) ) {
			wp_send_json_error(
				[
					'message' => 'Focus suggestion generator is not available.',
				]
			);
		}

		$result = $this->metadata_generator->suggest_focus_fields( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
				]
			);
		}

		wp_send_json_success( $result );
	}
}
