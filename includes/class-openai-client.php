<?php
/**
 * OpenAI API client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_OpenAI_Client {

	const API_URL = 'https://api.openai.com/v1/chat/completions';

	public function generate_json( $prompt, $max_tokens = 500 ) {
		$api_key = $this->get_api_key();
		$model   = get_option( 'ai_seo_assistant_model', 'gpt-4o-mini' );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'ai_seo_missing_api_key',
				'Missing OpenAI API key.'
			);
		}

		$response = $this->request(
			$api_key,
			$model,
			[
				[
					'role'    => 'system',
					'content' => 'Return only valid JSON. Do not include markdown, explanations, comments, or extra text.',
				],
				[
					'role'    => 'user',
					'content' => $prompt,
				],
			],
			$max_tokens,
			true
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = $this->extract_message_content( $response );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$data = json_decode( $content, true );

		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error(
				'ai_seo_invalid_json',
				'API response was not valid JSON. Raw response: ' . AI_SEO_Assistant_Utils::mask_sensitive_text( $content )
			);
		}

		return $data;
	}

	public function test_connection() {
		$api_key = $this->get_api_key();
		$model   = get_option( 'ai_seo_assistant_model', 'gpt-4o-mini' );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'ai_seo_missing_api_key',
				'Missing OpenAI API key.'
			);
		}

		$response = $this->request(
			$api_key,
			$model,
			[
				[
					'role'    => 'user',
					'content' => 'Reply with OK only.',
				],
			],
			5,
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = $this->extract_message_content( $response );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'ai_seo_empty_openai_test',
				'OpenAI returned an empty response.'
			);
		}

		return true;
	}

	public function get_api_key() {
		if ( defined( 'AI_SEO_ASSISTANT_OPENAI_API_KEY' ) && AI_SEO_ASSISTANT_OPENAI_API_KEY ) {
			return trim( (string) AI_SEO_ASSISTANT_OPENAI_API_KEY );
		}

		return trim( (string) get_option( 'ai_seo_assistant_api_key', '' ) );
	}

	public function has_config_key() {
		return defined( 'AI_SEO_ASSISTANT_OPENAI_API_KEY' ) && AI_SEO_ASSISTANT_OPENAI_API_KEY;
	}

	private function request( $api_key, $model, $messages, $max_tokens = 500, $json_mode = false ) {
		$body = [
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => absint( $max_tokens ),
			'temperature' => 0.2,
		];

		if ( $json_mode ) {
			$body['response_format'] = [
				'type' => 'json_object',
			];
		}

		$response = wp_remote_post(
			self::API_URL,
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = 'OpenAI API request failed.';

			if ( ! empty( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			} elseif ( ! empty( $raw_body ) ) {
				$message = $raw_body;
			}

			$message = AI_SEO_Assistant_Utils::mask_sensitive_text( $message );

			return new WP_Error(
				'ai_seo_openai_request_failed',
				$message
			);
		}

		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_Error(
				'ai_seo_openai_invalid_response',
				'OpenAI returned an invalid response.'
			);
		}

		return $data;
	}

	private function extract_message_content( $data ) {
		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'ai_seo_openai_missing_content',
				'OpenAI response did not include message content.'
			);
		}

		return trim( $data['choices'][0]['message']['content'] );
	}
}