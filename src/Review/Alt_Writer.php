<?php
/**
 * Alt_Writer — puts an image's alt text where the page PRINTS it, in the post content.
 *
 * WHY (Andrew, 2026-10-06, post 363): the Media Library alt is not what most pages print. Divi's image,
 * fullwidth-image, blurb and slide modules carry their OWN alt attribute in the shortcode; a core Image
 * block and a classic <img> carry it in the saved markup. Writing only `_wp_attachment_image_alt` reported
 * success while the page kept "Moving To Boise Services" on a kitchen photo.
 *
 * HOW, guarded. The image is found by its file name (size suffixes ignored) in a Divi module's image
 * attribute, or by its `wp-image-{id}` class (or file name) on an <img> tag. EXACTLY ONE place must match;
 * none or several and nothing is written (the caller reports "not on the page" / "ambiguous"). Only that one
 * attribute changes: every other byte of the content round-trips unchanged, which the caller verifies after
 * saving. Text is made safe for its home: in a Divi attribute, straight double quotes become curly ones and
 * square and angle brackets are dropped (they would end the shortcode); in HTML it is attribute-escaped.
 *
 * Pure PHP, unit-tested.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Splices alt text into post content.
 */
class Alt_Writer {

	/** Divi modules that print an image, with the attribute holding the image URL and the one holding its alt. */
	public const DIVI = [
		'et_pb_image'           => [ 'src', 'alt' ],
		'et_pb_fullwidth_image' => [ 'src', 'alt' ],
		'et_pb_blurb'           => [ 'image', 'alt' ],
		'et_pb_slide'           => [ 'image', 'image_alt' ],
	];

	/**
	 * Set one image's alt in the content.
	 *
	 * @param string $content Post content.
	 * @param int    $id      Attachment ID.
	 * @param string $src     The image URL the page prints (or its file name).
	 * @param string $alt     New alt text.
	 * @return array{content:string,matches:int,where:string} where: 'divi' | 'html' | '' (no single match).
	 */
	public static function splice( string $content, int $id, string $src, string $alt ): array {
		$stem  = self::stem( $src );
		$found = [];

		// Divi module opening tags.
		$modules = implode( '|', array_map( 'preg_quote', array_keys( self::DIVI ) ) );
		if ( '' !== $stem && preg_match_all( '/\[(' . $modules . ')\b[^\]]*\]/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $i => $tag ) {
				[ $url_attr ] = self::DIVI[ $m[1][ $i ][0] ];
				$url          = self::attr( $tag[0], $url_attr );
				if ( '' !== $url && self::stem( $url ) === $stem ) {
					$found[] = [ 'divi', $tag[1], $tag[0], $m[1][ $i ][0] ];
				}
			}
		}
		// <img> tags (core Image block markup, classic content).
		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $tag ) {
				$by_id  = $id > 0 && preg_match( '/\bclass\s*=\s*"[^"]*\bwp-image-' . $id . '\b/i', $tag[0] );
				$by_src = '' !== $stem && self::stem( self::attr( $tag[0], 'src' ) ) === $stem;
				if ( $by_id || $by_src ) {
					$found[] = [ 'html', $tag[1], $tag[0], 'img' ];
				}
			}
		}

		if ( 1 !== count( $found ) ) {
			return [
				'content' => $content,
				'matches' => count( $found ),
				'where'   => '',
			];
		}

		[ $where, $offset, $tag, $name ] = $found[0];
		if ( 'divi' === $where ) {
			$new = self::set_attr( $tag, self::DIVI[ $name ][1], self::divi_text( $alt ), ']' );
		} else {
			$new = self::set_attr( $tag, 'alt', htmlspecialchars( $alt, ENT_QUOTES | ENT_HTML5, 'UTF-8', false ), '>' );
		}

		return [
			'content' => substr( $content, 0, $offset ) . $new . substr( $content, $offset + strlen( $tag ) ),
			'matches' => 1,
			'where'   => $where,
		];
	}

	/**
	 * Alt text safe inside a Divi shortcode attribute.
	 *
	 * @param string $alt Alt text.
	 */
	public static function divi_text( string $alt ): string {
		$alt = (string) preg_replace( '/"([^"]*)"/u', "\u{201C}$1\u{201D}", $alt );

		return trim( str_replace( [ '"', '[', ']', '<', '>' ], [ "\u{201D}", '', '', '', '' ], $alt ) );
	}

	/**
	 * A file name without its size suffix and extension: ".../boise-map-300x200.jpg" → "boise-map".
	 *
	 * @param string $url URL or file name.
	 */
	public static function stem( string $url ): string {
		$path = (string) strtok( $url, '?#' );
		$base = strtolower( (string) basename( $path ) );

		return (string) preg_replace( '/(-\d+x\d+)?(-scaled)?\.[a-z0-9]+$/', '', $base );
	}

	/**
	 * An attribute's value in a tag ('' when absent).
	 *
	 * @param string $tag  Tag.
	 * @param string $name Attribute.
	 */
	protected static function attr( string $tag, string $name ): string {
		return preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*"([^"]*)"/i', $tag, $m ) ? $m[1] : '';
	}

	/**
	 * Replace (or add, before the tag's end) one attribute; nothing else in the tag changes.
	 *
	 * @param string $tag   Tag.
	 * @param string $name  Attribute.
	 * @param string $value Safe value.
	 * @param string $end   ']' or '>'.
	 */
	protected static function set_attr( string $tag, string $name, string $value, string $end ): string {
		$pattern = '/(\s' . preg_quote( $name, '/' ) . '\s*=\s*")[^"]*(")/i';
		if ( preg_match( $pattern, $tag ) ) {
			return (string) preg_replace_callback( $pattern, static fn( $m ) => $m[1] . $value . $m[2], $tag, 1 );
		}
		$close = '>' === $end && '/>' === substr( $tag, -2 ) ? strlen( $tag ) - 2 : strlen( $tag ) - 1;
		$space = ' ' === $tag[ $close - 1 ] ? '' : ' ';

		return substr( $tag, 0, $close ) . $space . $name . '="' . $value . '"' . ( '' === $space ? ' ' : '' ) . substr( $tag, $close );
	}
}
