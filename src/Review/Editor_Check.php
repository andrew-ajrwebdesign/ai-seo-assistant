<?php
/**
 * Editor_Check — whether a "Do in the editor" recommendation is already in place on the page.
 *
 * WHY (Andrew, 2026-10-06, /boise-area-map/): he changed the H1 and added the line in Divi; the scan saw
 * it, yet the review and the editor's to-do box still told him to do both, because Claude's advice is text
 * and was never checked again. So each recommendation carries a target the plugin can check against the
 * latest scan of the rendered page:
 *   h1      the page's H1 equals the target (case and punctuation ignored);
 *   h2      one of its H2s equals the target;
 *   phrase  every target phrase is in the page's VISIBLE text, as a whole phrase (case, spacing and
 *           punctuation ignored; shortcodes, tags and their attributes are not text);
 *   link    another page links here with the target words in the link text (from the named pages, when
 *           the advice names them);
 *   none    nothing checkable: the person ticks Done.
 * Claude is asked for the target (review_schema()); advice written before that has its target worked out
 * from its own words (infer()): the quoted text, and which heading it talks about.
 *
 * Used three ways: a reply's advice that is already true is dropped before it is shown; the review and the
 * editor's to-do box show a checkable item as "Done — found on the page <date>" once the scan sees it.
 *
 * Pure PHP, unit-tested.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Checks editor advice against the page.
 */
class Editor_Check {

	/** What can be checked. */
	public const CHECKS = [ 'h1', 'h2', 'phrase', 'link', 'none' ];

	/**
	 * The check for one piece of advice: Claude's own when it gave one, else worked out from its words.
	 *
	 * @param array<string,mixed> $item { area, advice, check?, target?, source? }.
	 * @return array{check:string,targets:array<int,string>,sources:array<int,string>}
	 */
	public static function of( array $item ): array {
		$check = (string) ( $item['check'] ?? '' );
		if ( in_array( $check, self::CHECKS, true ) && 'none' !== $check && '' !== trim( (string) ( $item['target'] ?? '' ) ) ) {
			return [
				'check'   => $check,
				'targets' => array_values( array_filter( array_map( 'trim', explode( '|', (string) $item['target'] ) ) ) ),
				'sources' => array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $item['source'] ?? '' ) ) ) ) ),
			];
		}
		if ( 'none' === $check ) {
			return [
				'check'   => 'none',
				'targets' => [],
				'sources' => [],
			];
		}

		return self::infer( (string) ( $item['area'] ?? '' ), (string) ( $item['advice'] ?? '' ) );
	}

	/**
	 * The check advice implies by its words: headings naming H1 or H2 with a quoted new heading; content
	 * quoting the phrases to add; links quoting the words to link with.
	 *
	 * @param string $area   headings | content | links.
	 * @param string $advice The advice.
	 * @return array{check:string,targets:array<int,string>,sources:array<int,string>}
	 */
	public static function infer( string $area, string $advice ): array {
		$none = [
			'check'   => 'none',
			'targets' => [],
			'sources' => [],
		];
		preg_match_all( '/[“"]([^”"]{2,200})[”"]/u', $advice, $m );
		$quotes = array_values( array_filter( array_map( 'trim', $m[1] ) ) );
		if ( [] === $quotes ) {
			return $none;
		}
		if ( 'headings' === $area ) {
			if ( preg_match( '/\bH1\b/i', $advice ) ) {
				$check = 'h1';
			} elseif ( preg_match( '/\bH2\b/i', $advice ) ) {
				$check = 'h2';
			} else {
				return $none;
			}

			return [
				'check'   => $check,
				'targets' => [ end( $quotes ) ], // "change X to Y": the last quote is the new heading.
				'sources' => [],
			];
		}
		if ( 'content' === $area ) {
			// The quotes are the phrases to add (often a search): done only when each is on the page as a
			// whole phrase. Never word by word: "cost of living in boise" is not done by a page that says
			// "living" in one place and "cost" in another.
			return [
				'check'   => 'phrase',
				'targets' => $quotes,
				'sources' => [],
			];
		}
		if ( 'links' === $area ) {
			return [
				'check'   => 'link',
				'targets' => [ $quotes[0] ],
				'sources' => [],
			];
		}

		return $none;
	}

	/**
	 * Whether the advice is in place on the page.
	 *
	 * @param array<string,mixed>                 $check   of().
	 * @param array<string,mixed>                 $facts   The page's latest facts (Html_Parser).
	 * @param string                              $text    The page's visible text, plain (Page_Review::page_text()).
	 * @param array<int,array{0:string,1:string}> $inbound Links to the page: [ from path, link text ].
	 */
	public static function in_place( array $check, array $facts, string $text = '', array $inbound = [] ): bool {
		$targets = array_map( [ self::class, 'norm' ], $check['targets'] );
		if ( [] === $targets || 'none' === $check['check'] ) {
			return false;
		}
		switch ( $check['check'] ) {
			case 'h1':
				return in_array( $targets[0], array_map( [ self::class, 'norm' ], (array) ( $facts['h1'] ?? [] ) ), true );
			case 'h2':
				$h2 = [];
				foreach ( (array) ( $facts['headings'] ?? [] ) as $h ) {
					if ( 2 === (int) ( $h['l'] ?? 0 ) ) {
						$h2[] = self::norm( (string) ( $h['t'] ?? '' ) );
					}
				}
				return in_array( $targets[0], $h2, true );
			case 'phrase':
				$body = ' ' . self::norm( $text ) . ' ';
				foreach ( $targets as $t ) {
					if ( false === strpos( $body, ' ' . $t . ' ' ) ) {
						return false;
					}
				}
				return true;
			case 'link':
				$sources = array_map( static fn( $s ) => strtolower( trim( $s, '/' ) ), $check['sources'] );
				foreach ( $inbound as [ $from, $anchor ] ) {
					if ( [] !== $sources && ! in_array( strtolower( trim( (string) $from, '/' ) ), $sources, true ) ) {
						continue;
					}
					if ( false !== strpos( ' ' . self::norm( (string) $anchor ) . ' ', ' ' . $targets[0] . ' ' ) ) {
						return true;
					}
				}
				return false;
		}

		return false;
	}

	/**
	 * Text for comparison: lower case, letters and digits only, single spaces. Its input is already plain
	 * text (decoded once, by Utils::visible_text() or the HTML parser): never decoded again here.
	 *
	 * @param string $text Text.
	 */
	public static function norm( string $text ): string {
		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $text ) ) );
	}
}
