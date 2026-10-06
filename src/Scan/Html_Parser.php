<?php
/**
 * Html_Parser — the SEO facts of one page, read from its HTML.
 *
 * WHY THE RENDERED PAGE. On builder sites (Divi, Elementor) the title tag, the H1 and half the images are
 * produced by the theme and the builder, not stored in post content; only the page as served shows what
 * Google sees. Scanner fetches it over loopback and hands the HTML here; when the fetch fails it hands the
 * post content instead and says so per page.
 *
 * WHAT IT READS. From the whole document: the title tag, meta description, canonical, robots, og:image,
 * html lang and every JSON-LD @type. From the CONTENT AREA only (main / article, else the body without its
 * header, footer, nav, aside and builder header/footer areas): headings, images, links and the word count,
 * so the menu's 40 links and the footer logo do not count as the page's own.
 *
 * Pure PHP on DOMDocument (no WordPress), unit-tested.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts page facts from HTML.
 */
class Html_Parser {

	/** Most headings, images and links kept per page (a facts row stays a few KB). */
	public const MAX_HEADINGS = 60;
	public const MAX_IMAGES   = 60;
	public const MAX_LINKS    = 300;

	/** Class or id fragments of site chrome that is not the page's own content. */
	protected const CHROME = '/(^|[\s_-])(main-header|main-footer|site-header|site-footer|et-l--header|et-l--footer|elementor-location-header|elementor-location-footer|wp-block-template-part|menu|navbar|cookie|skip-link)([\s_-]|$)/i';

	/**
	 * Read a page's facts.
	 *
	 * @param string $html Full HTML document (or a content fragment for the fallback).
	 * @param string $home Site home URL, to tell internal links from external ones.
	 * @return array<string,mixed>
	 */
	public static function parse( string $html, string $home ): array {
		$dom = new \DOMDocument();
		$old = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		$xp = new \DOMXPath( $dom );

		$facts = [
			'title'       => self::text_of( $xp->query( '//head/title' ) ),
			'description' => self::meta( $xp, 'name', 'description' ),
			'robots'      => strtolower( self::meta( $xp, 'name', 'robots' ) ),
			'canonical'   => self::attr( $xp->query( '//link[translate(@rel,"CANONIL","canonil")="canonical"]' ), 'href' ),
			'og_image'    => self::meta( $xp, 'property', 'og:image' ),
			'lang'        => self::attr( $xp->query( '//html' ), 'lang' ),
			'schema'      => self::schema_types( $xp ),
		];
		$facts['noindex'] = false !== strpos( $facts['robots'], 'noindex' );

		$content = self::content_root( $xp );
		self::drop_chrome( $xp, $content );

		$facts['headings'] = [];
		$facts['h1']       = [];
		foreach ( $xp->query( './/h1|.//h2|.//h3|.//h4|.//h5|.//h6', $content ) as $h ) {
			$level = (int) substr( strtolower( $h->nodeName ), 1 );
			$text  = self::clean( self::visible_text( $h ) );
			if ( 1 === $level ) {
				$facts['h1'][] = $text;
			}
			if ( count( $facts['headings'] ) < self::MAX_HEADINGS ) {
				$facts['headings'][] = [
					'l' => $level,
					't' => mb_substr( $text, 0, 120 ),
				];
			}
		}
		// An H1 printed by the theme outside the content area (a page-title band) still counts.
		if ( [] === $facts['h1'] ) {
			foreach ( $xp->query( '//body//h1' ) as $h ) {
				$facts['h1'][] = self::clean( self::visible_text( $h ) );
			}
			if ( [] !== $facts['h1'] ) {
				array_unshift(
					$facts['headings'],
					[
						'l' => 1,
						't' => mb_substr( $facts['h1'][0], 0, 120 ),
					]
				);
			}
		}

		$facts['images'] = self::images( $xp, $content );
		[ $facts['links'], $facts['external'] ] = self::links( $xp, $content, $home );
		$text           = self::clean( self::visible_text( $content ) );
		$facts['words'] = '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );

		return $facts;
	}

	/**
	 * The content area: <main>, else the single <article>, else <body>.
	 *
	 * @param \DOMXPath $xp XPath.
	 */
	protected static function content_root( \DOMXPath $xp ): \DOMNode {
		foreach ( [ '//main', '//*[@id="main-content"]', '//article' ] as $query ) {
			$found = $xp->query( $query );
			if ( $found && 1 === $found->length ) {
				return $found->item( 0 );
			}
		}
		$body = $xp->query( '//body' );

		return $body && $body->length ? $body->item( 0 ) : $xp->document;
	}

	/**
	 * Remove site chrome (header, footer, nav, aside, builder header/footer, scripts) from the content area.
	 *
	 * @param \DOMXPath $xp   XPath.
	 * @param \DOMNode  $root Content root.
	 */
	protected static function drop_chrome( \DOMXPath $xp, \DOMNode $root ): void {
		$remove = [];
		foreach ( $xp->query( './/header|.//footer|.//nav|.//aside|.//script|.//style|.//noscript|.//template|.//svg|.//form', $root ) as $node ) {
			$remove[] = $node;
		}
		foreach ( $xp->query( './/*[@id or @class]', $root ) as $node ) {
			if ( $node instanceof \DOMElement && preg_match( self::CHROME, $node->getAttribute( 'id' ) . ' ' . $node->getAttribute( 'class' ) ) ) {
				$remove[] = $node;
			}
		}
		foreach ( $remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Content images: src, file name, alt (null when the attribute is missing) and attachment ID if known.
	 *
	 * @param \DOMXPath $xp   XPath.
	 * @param \DOMNode  $root Content root.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function images( \DOMXPath $xp, \DOMNode $root ): array {
		$out  = [];
		$seen = [];
		foreach ( $xp->query( './/img', $root ) as $img ) {
			if ( ! $img instanceof \DOMElement ) {
				continue;
			}
			$src = trim( $img->getAttribute( 'src' ) );
			if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
				$src = trim( $img->getAttribute( 'data-src' ) ); // Lazy-loaders park the real address here.
			}
			if ( '' === $src || 0 === strpos( $src, 'data:' ) || preg_match( '/\.svg(\?|$)/i', $src ) ) {
				continue;
			}
			$w = (int) $img->getAttribute( 'width' );
			$h = (int) $img->getAttribute( 'height' );
			if ( ( $w > 0 && $w <= 2 ) || ( $h > 0 && $h <= 2 ) || isset( $seen[ $src ] ) ) {
				continue; // Tracking pixels and repeats.
			}
			$seen[ $src ] = true;
			$id           = preg_match( '/\bwp-image-(\d+)\b/', $img->getAttribute( 'class' ), $m ) ? (int) $m[1] : (int) $img->getAttribute( 'data-id' );
			$out[]        = [
				'src'  => mb_substr( $src, 0, 300 ),
				'file' => mb_substr( (string) basename( (string) parse_url( $src, PHP_URL_PATH ) ), 0, 120 ), // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure PHP class (no WordPress).
				'alt'  => $img->hasAttribute( 'alt' ) ? self::clean( $img->getAttribute( 'alt' ) ) : null,
				'id'   => $id,
			];
			if ( count( $out ) >= self::MAX_IMAGES ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Internal links (path + anchor text) and the number of external ones.
	 *
	 * @param \DOMXPath $xp   XPath.
	 * @param \DOMNode  $root Content root.
	 * @param string    $home Home URL.
	 * @return array{0:array<int,array<string,string>>,1:int}
	 */
	protected static function links( \DOMXPath $xp, \DOMNode $root, string $home ): array {
		$host     = strtolower( (string) parse_url( $home, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure PHP class.
		$internal = [];
		$external = 0;
		foreach ( $xp->query( './/a[@href]', $root ) as $a ) {
			if ( ! $a instanceof \DOMElement ) {
				continue;
			}
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|javascript|sms|data):/i', $href ) ) {
				continue;
			}
			$parts = parse_url( $href ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure PHP class.
			if ( false === $parts ) {
				continue;
			}
			$link_host = strtolower( (string) ( $parts['host'] ?? '' ) );
			if ( '' !== $link_host && preg_replace( '/^www\./', '', $link_host ) !== preg_replace( '/^www\./', '', $host ) ) {
				++$external;
				continue;
			}
			$path = (string) ( $parts['path'] ?? '' );
			if ( '' === $path || '/' !== $path[0] ) {
				continue; // Relative links without a leading slash are rare in WordPress output; skipped, not guessed.
			}
			if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|feed)\b|\.(jpe?g|png|gif|webp|avif|pdf|zip|docx?|xlsx?|mp4|mp3)$#i', $path ) ) {
				continue;
			}
			if ( count( $internal ) < self::MAX_LINKS ) {
				$internal[] = [
					'p' => mb_substr( $path, 0, 190 ),
					't' => mb_substr( self::clean( $a->textContent ), 0, 80 ),
				];
			}
		}

		return [ $internal, $external ];
	}

	/**
	 * Every JSON-LD @type on the page, flattened (@graph included), unique.
	 *
	 * @param \DOMXPath $xp XPath.
	 * @return array<int,string>
	 */
	protected static function schema_types( \DOMXPath $xp ): array {
		$types = [];
		foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $script ) {
			$data = json_decode( trim( $script->textContent ), true );
			if ( is_array( $data ) ) {
				self::collect_types( $data, $types );
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Walk JSON-LD for @type values.
	 *
	 * @param array<mixed>      $node  JSON-LD node.
	 * @param array<int,string> $types Found so far.
	 * @param int               $depth Recursion guard.
	 */
	protected static function collect_types( array $node, array &$types, int $depth = 0 ): void {
		if ( $depth > 6 ) {
			return;
		}
		if ( isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $type ) {
				if ( is_string( $type ) && '' !== $type ) {
					$types[] = mb_substr( $type, 0, 60 );
				}
			}
		}
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				self::collect_types( $value, $types, $depth + 1 );
			}
		}
	}

	/**
	 * A <meta> tag's content by name or property.
	 *
	 * @param \DOMXPath $xp    XPath.
	 * @param string    $attr  'name' | 'property'.
	 * @param string    $value Name.
	 */
	protected static function meta( \DOMXPath $xp, string $attr, string $value ): string {
		return self::clean( self::attr( $xp->query( '//meta[translate(@' . $attr . ',"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="' . $value . '"]' ), 'content' ) );
	}

	/**
	 * An attribute of the first node in a list, or ''.
	 *
	 * @param \DOMNodeList|false $nodes Nodes.
	 * @param string             $attr  Attribute.
	 */
	protected static function attr( $nodes, string $attr ): string {
		if ( ! $nodes || 0 === $nodes->length ) {
			return '';
		}
		$node = $nodes->item( 0 );

		return $node instanceof \DOMElement ? trim( $node->getAttribute( $attr ) ) : '';
	}

	/**
	 * The text of the first node in a list, or ''.
	 *
	 * @param \DOMNodeList|false $nodes Nodes.
	 */
	protected static function text_of( $nodes ): string {
		return $nodes && $nodes->length ? self::clean( (string) $nodes->item( 0 )->textContent ) : '';
	}

	/**
	 * A node's visible text with block boundaries kept as spaces.
	 *
	 * @param \DOMNode $node Node.
	 */
	protected static function visible_text( \DOMNode $node ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$out .= $child->textContent;
			} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
				$out .= ' ' . self::visible_text( $child ) . ' ';
			}
		}

		return $out;
	}

	/**
	 * Collapse whitespace and decode entities.
	 *
	 * @param string $text Text.
	 */
	public static function clean( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}
