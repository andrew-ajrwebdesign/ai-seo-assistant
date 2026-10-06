<?php
/**
 * Alt_Writer — puts an image's alt text where the page PRINTS it, in the post content.
 *
 * WHY (Andrew, 2026-10-06, post 363): the Media Library alt is not what most pages print. Divi's image,
 * fullwidth-image, blurb and slide modules carry their OWN alt attribute in the shortcode; a core Image
 * block and a classic <img> carry it in the saved markup. Writing only `_wp_attachment_image_alt` reported
 * success while the page kept "Our Services" on a kitchen photo.
 *
 * HOW, guarded. The image is found by its uploads-relative path (size suffixes ignored, so
 * 2024/05/kitchen.jpg and 2025/01/kitchen.jpg are different images) in a Divi module's image attribute, or
 * by its `wp-image-{id}` class (or path) on an <img> tag. A module or tag that names ANOTHER attachment
 * (a different wp-image-N, or a Divi image id) is never written, whatever its file. EXACTLY ONE place must match;
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
		$has   = '' !== self::key( $src );
		$found = [];

		// Divi module opening tags.
		$modules = implode( '|', array_map( 'preg_quote', array_keys( self::DIVI ) ) );
		if ( $has && preg_match_all( '/\[(' . $modules . ')\b[^\]]*\]/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $i => $tag ) {
				[ $url_attr ] = self::DIVI[ $m[1][ $i ][0] ];
				$url          = self::attr( $tag[0], $url_attr );
				if ( self::other_attachment( self::divi_id( $tag[0] ), $id ) ) {
					continue; // Another attachment's module, even when the file name is the same.
				}
				if ( '' !== $url && self::same_image( $url, $src ) ) {
					$found[] = [ 'divi', $tag[1], $tag[0], $m[1][ $i ][0] ];
				}
			}
		}
		// <img> tags (core Image block markup, classic content).
		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $tag ) {
				$tag_id = preg_match( '/\bclass\s*=\s*"[^"]*\bwp-image-(\d+)\b/i', $tag[0], $cm ) ? (int) $cm[1] : 0;
				if ( self::other_attachment( $tag_id, $id ) ) {
					continue; // Another attachment's image, even when the file name is the same.
				}
				$by_id  = $id > 0 && $tag_id === $id;
				$by_src = $has && self::same_image( self::attr( $tag[0], 'src' ), $src );
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
	 * How an image URL is matched: its path under uploads without the size suffix and extension
	 * (".../uploads/2024/05/map-300x200.jpg" → "2024/05/map"); just the file stem when the URL is not under
	 * uploads, or when either side is a bare file name.
	 *
	 * @param string $url URL or file name.
	 */
	public static function key( string $url ): string {
		$path = strtolower( (string) strtok( $url, '?#' ) );
		$at   = strpos( $path, '/uploads/' );
		if ( false === $at ) {
			return self::stem( $url );
		}
		$rel = substr( $path, $at + 9 );

		return (string) preg_replace( '/(-\d+x\d+)?(-scaled)?\.[a-z0-9]+$/', '', $rel );
	}

	/**
	 * Whether two URLs are the same image: same uploads-relative path when both have one, else same stem.
	 *
	 * @param string $a URL or file name.
	 * @param string $b URL or file name.
	 */
	public static function same_image( string $a, string $b ): bool {
		if ( '' === $a || '' === $b ) {
			return false;
		}
		$both = false !== stripos( $a, '/uploads/' ) && false !== stripos( $b, '/uploads/' );

		return $both ? self::key( $a ) === self::key( $b ) : self::stem( $a ) === self::stem( $b );
	}

	/**
	 * The attachment id a Divi module names (image_id or attachment_id; 0 when it names none).
	 *
	 * @param string $tag Module opening tag.
	 */
	protected static function divi_id( string $tag ): int {
		foreach ( [ 'image_id', 'attachment_id' ] as $name ) {
			$value = self::attr( $tag, $name );
			if ( ctype_digit( $value ) ) {
				return (int) $value;
			}
		}

		return 0;
	}

	/**
	 * True when a module or tag names a different attachment from the one being written.
	 *
	 * @param int $named The id it names (0 = none).
	 * @param int $id    The attachment being written (0 = unknown).
	 */
	protected static function other_attachment( int $named, int $id ): bool {
		return $named > 0 && $id > 0 && $named !== $id;
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
