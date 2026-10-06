<?php
/**
 * Title_Width — how wide a title is in Google's desktop results, in pixels.
 *
 * WHY PIXELS, NOT CHARACTERS. Google cuts a title by the width it draws, not by a character count: 60
 * capital W's are cut long before 60 lower-case i's. Desktop results set titles in Arial at about 20px and
 * show roughly 580px before the ellipsis (the mockup's "634 px of about 580 px: cut off in Google").
 *
 * HOW. Arial's advance widths (units per 1,000 em, the font's own metrics, identical to Helvetica's AFM) for
 * the printable ASCII range and the punctuation titles use, scaled to 20px. A character not in the table
 * (accented and non-Latin letters) counts as a typical lower-case letter (556), which is close for accented
 * Latin and errs wide for narrow scripts, so a borderline title is flagged rather than missed.
 *
 * Pure PHP, unit-tested.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Pixel width of a title in Google's desktop results.
 */
class Title_Width {

	/** Font size Google draws desktop titles at, in px. */
	public const FONT_PX = 20;

	/** Width Google shows before it cuts the title off, in px (about). */
	public const LIMIT_PX = 580;

	/** Width for a character the table does not hold. */
	public const DEFAULT_UNITS = 556;

	/**
	 * Arial advance widths, units per 1,000 em.
	 *
	 * @var array<string,int>
	 */
	public const UNITS = [
		' '        => 278,
		'!'        => 278,
		'"'        => 355,
		'#'        => 556,
		'$'        => 556,
		'%'        => 889,
		'&'        => 667,
		"'"        => 191,
		'('        => 333,
		')'        => 333,
		'*'        => 389,
		'+'        => 584,
		','        => 278,
		'-'        => 333,
		'.'        => 278,
		'/'        => 278,
		'0'        => 556,
		'1'        => 556,
		'2'        => 556,
		'3'        => 556,
		'4'        => 556,
		'5'        => 556,
		'6'        => 556,
		'7'        => 556,
		'8'        => 556,
		'9'        => 556,
		':'        => 278,
		';'        => 278,
		'<'        => 584,
		'='        => 584,
		'>'        => 584,
		'?'        => 556,
		'@'        => 1015,
		'A'        => 667,
		'B'        => 667,
		'C'        => 722,
		'D'        => 722,
		'E'        => 667,
		'F'        => 611,
		'G'        => 778,
		'H'        => 722,
		'I'        => 278,
		'J'        => 500,
		'K'        => 667,
		'L'        => 556,
		'M'        => 833,
		'N'        => 722,
		'O'        => 778,
		'P'        => 667,
		'Q'        => 778,
		'R'        => 722,
		'S'        => 667,
		'T'        => 611,
		'U'        => 722,
		'V'        => 667,
		'W'        => 944,
		'X'        => 667,
		'Y'        => 667,
		'Z'        => 611,
		'['        => 278,
		'\\'       => 278,
		']'        => 278,
		'^'        => 469,
		'_'        => 556,
		'`'        => 333,
		'a'        => 556,
		'b'        => 556,
		'c'        => 500,
		'd'        => 556,
		'e'        => 556,
		'f'        => 278,
		'g'        => 556,
		'h'        => 556,
		'i'        => 222,
		'j'        => 222,
		'k'        => 500,
		'l'        => 222,
		'm'        => 833,
		'n'        => 556,
		'o'        => 556,
		'p'        => 556,
		'q'        => 556,
		'r'        => 333,
		's'        => 500,
		't'        => 278,
		'u'        => 556,
		'v'        => 500,
		'w'        => 722,
		'x'        => 500,
		'y'        => 500,
		'z'        => 500,
		'{'        => 334,
		'|'        => 260,
		'}'        => 334,
		'~'        => 584,
		"\u{2013}" => 556,  // En dash.
		"\u{2014}" => 1000, // Em dash.
		"\u{2018}" => 222,
		"\u{2019}" => 222,
		"\u{201C}" => 333,
		"\u{201D}" => 333,
		"\u{2022}" => 350,  // Bullet.
		"\u{2026}" => 1000, // Ellipsis.
		"\u{00B7}" => 278,  // Middle dot.
		"\u{00A0}" => 278,  // No-break space.
		"\u{00AE}" => 737,
		"\u{2122}" => 1000,
		"\u{00A9}" => 737,
	];

	/**
	 * Width in px (rounded) of a title as Google draws it on desktop.
	 *
	 * @param string $title Plain-text title (entities already decoded).
	 */
	public static function px( string $title ): int {
		$units = 0;
		foreach ( self::chars( $title ) as $char ) {
			$units += self::UNITS[ $char ] ?? self::DEFAULT_UNITS;
		}

		return (int) round( $units * self::FONT_PX / 1000 );
	}

	/**
	 * Whether the title is cut off in Google's desktop results.
	 *
	 * @param string $title Title.
	 */
	public static function too_wide( string $title ): bool {
		return self::px( $title ) > self::LIMIT_PX;
	}

	/**
	 * The part of a title Google would show before cutting it ("Water Heaters | Northfield … Tank & Tankless").
	 *
	 * @param string $title Title.
	 */
	public static function visible( string $title ): string {
		$units = 0;
		$out   = '';
		$max   = ( self::LIMIT_PX - 20 ) * 1000 / self::FONT_PX; // Room for the ellipsis.
		foreach ( self::chars( $title ) as $char ) {
			$units += self::UNITS[ $char ] ?? self::DEFAULT_UNITS;
			if ( $units > $max ) {
				return rtrim( $out ) . "\u{2026}";
			}
			$out .= $char;
		}

		return $out;
	}

	/**
	 * A string's characters (multibyte-safe).
	 *
	 * @param string $text Text.
	 * @return array<int,string>
	 */
	protected static function chars( string $text ): array {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $chars ) ? $chars : str_split( $text );
	}
}
