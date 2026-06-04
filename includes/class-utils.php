<?php
/**
 * Shared utility helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Utils {

	public static function trim_to_length( $text, $max_length ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );

		if ( mb_strlen( $text ) <= $max_length ) {
			return $text;
		}

		$trimmed = mb_substr( $text, 0, $max_length );
		$trimmed = preg_replace( '/\s+\S*$/u', '', $trimmed );

		return rtrim( $trimmed, " \t\n\r\0\x0B.,;:-" );
	}

	public static function get_title_status( $title ) {
		$length = mb_strlen( trim( (string) $title ) );

		if ( 0 === $length ) {
			return 'Missing';
		}

		if ( $length < 30 ) {
			return 'Possibly too short';
		}

		if ( $length > 60 ) {
			return 'Possibly too long';
		}

		return 'Looks good';
	}

	public static function get_description_status( $description ) {
		$length = mb_strlen( trim( (string) $description ) );

		if ( 0 === $length ) {
			return 'Missing';
		}

		if ( $length < 110 ) {
			return 'Possibly too short';
		}

		if ( $length > 160 ) {
			return 'Possibly too long';
		}

		return 'Looks good';
	}

	public static function clean_plain_text( $content ) {
		$content = strip_shortcodes( (string) $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = preg_replace( '/\s+/', ' ', $content );

		return trim( $content );
	}

	public static function mask_sensitive_text( $text ) {
		$text = (string) $text;

		/*
		* Simplify OpenAI invalid key errors for admin display/logs.
		*/
		if ( false !== stripos( $text, 'Incorrect API key provided' ) ) {
			return 'Incorrect API key provided.';
		}

		/*
		* Mask OpenAI-style keys.
		*/
		$text = preg_replace(
			'/sk-(proj|live|test)?-[A-Za-z0-9_\-]{8,}/',
			'sk-***masked***',
			$text
		);

		/*
		* Mask values shown after common API key phrases.
		*/
		$text = preg_replace(
			'/API key provided:\s*[^.\s]+/i',
			'API key provided: ***masked***',
			$text
		);

		return $text;
	}
}