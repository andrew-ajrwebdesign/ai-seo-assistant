<?php
/**
 * AJAX handlers.
 */

namespace AJR\SEOAssistant\Admin;

defined( 'ABSPATH' ) || exit;

class Ajax {

	private $metadata_generator;

	public function __construct( $metadata_generator ) {
		$this->metadata_generator = $metadata_generator;
	}

	public function init() {
		add_action( 'wp_ajax_ai_seo_assistant_generate', [ $this, 'generate_metadata' ] );
		add_action( 'wp_ajax_ai_seo_assistant_generate_and_save', [ $this, 'generate_and_save_metadata' ] );
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

	public function generate_and_save_metadata() {
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

		$result = $this->metadata_generator->generate_and_save( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
				]
			);
		}

		wp_send_json_success( $result );
	}

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

	private function get_valid_post_id() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

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

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'ai_seo_post_not_found',
				'Post not found.'
			);
		}

		return $post_id;
	}

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
