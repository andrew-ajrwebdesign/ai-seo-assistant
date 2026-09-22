<?php
/**
 * Shared utility helpers.
 */

namespace AJR\SEOAssistant\Core;

defined( 'ABSPATH' ) || exit;

class Utils {

	public static function trim_to_length( $text, $max_length ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );

		if ( mb_strlen( $text ) <= $max_length ) {
			return $text;
		}

		$trimmed = mb_substr( $text, 0, $max_length );
		$trimmed = preg_replace( '/\s+\S*$/u', '', $trimmed );

		return rtrim( $trimmed, " \t\n\r\0\x0B.,;:-" );
	}

	/**
	 * Shortens text to $max_length, preferring the end of a sentence.
	 *
	 * A meta description cut at a word boundary can still stop mid-phrase
	 * ("Flat fee, month to"), which reads as broken in search results. When a
	 * full sentence ends within the limit and keeps at least 60% of it, the
	 * text is cut there instead; otherwise this falls back to the word cut.
	 *
	 * @param string $text       Text to shorten.
	 * @param int    $max_length Maximum length in characters.
	 * @return string
	 */
	public static function trim_to_sentence( $text, $max_length ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );

		if ( mb_strlen( $text ) <= $max_length ) {
			return $text;
		}

		$window = mb_substr( $text, 0, $max_length + 1 );

		if ( preg_match_all( '/[.!?](?=\s|$)/u', $window, $matches, PREG_OFFSET_CAPTURE ) ) {
			$last = end( $matches[0] );
			$cut  = mb_strlen( substr( $window, 0, $last[1] + strlen( $last[0] ) ) );

			if ( $cut <= $max_length && $cut >= (int) floor( $max_length * 0.6 ) ) {
				return mb_substr( $text, 0, $cut );
			}
		}

		return self::trim_to_length( $text, $max_length );
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

		if ( false !== stripos( $text, 'Incorrect API key provided' ) ) {
			return 'Incorrect API key provided.';
		}

		// Anthropic keys (sk-ant-api03-…) first: the OpenAI pattern below
		// cannot match them, so they would otherwise reach logs unmasked.
		$text = preg_replace(
			'/sk-ant-[A-Za-z0-9_\-]{8,}/',
			'sk-ant-***masked***',
			$text
		);

		$text = preg_replace(
			'/sk-(proj|live|test)?-[A-Za-z0-9_\-]{8,}/',
			'sk-***masked***',
			$text
		);

		$text = preg_replace(
			'/GOCSPX-[A-Za-z0-9_\-]+/',
			'GOCSPX-***masked***',
			$text
		);

		$text = preg_replace(
			'/API key provided:\s*[^.\s]+/i',
			'API key provided: ***masked***',
			$text
		);

		return $text;
	}
}
