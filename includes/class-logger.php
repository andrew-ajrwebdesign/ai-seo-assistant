<?php
/**
 * Stores generation logs per post.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Logger {

	const LOG_META_KEY = '_ai_seo_assistant_generation_log';

	public function add_log( $post_id, $entry ) {
		$logs = get_post_meta( $post_id, self::LOG_META_KEY, true );

		if ( ! is_array( $logs ) ) {
			$logs = [];
		}

		$entry = wp_parse_args(
			$entry,
			[
				'timestamp' => time(),
				'source'          => '',
				'model'           => '',
				'old_title'       => '',
				'old_description' => '',
				'new_title'       => '',
				'new_description' => '',
				'content_preview' => '',
				'error'           => '',
			]
		);

		array_unshift( $logs, $entry );

		// Keep the log lightweight.
		$logs = array_slice( $logs, 0, 10 );

		update_post_meta( $post_id, self::LOG_META_KEY, $logs );
	}

	public function get_logs( $post_id ) {
		$logs = get_post_meta( $post_id, self::LOG_META_KEY, true );

		return is_array( $logs ) ? $logs : [];
	}

	public function get_latest_log( $post_id ) {
		$logs = $this->get_logs( $post_id );

		return ! empty( $logs[0] ) ? $logs[0] : [];
	}
}