<?php
/**
 * Rules — the SEO best-practice checks the scan runs on one page's facts.
 *
 * Each rule reads the page's facts (Html_Parser) and what is known about the rest of the site (Scanner
 * builds the context: duplicates, inbound links, the sitemap, the redirect map, the page's real searches),
 * and returns plain-English issues the agency can act on. Every issue says who fixes it: 'claude' when the
 * page review can write the fix (title, description, focus keyphrase, alt text), 'editor' when it is a
 * recommendation only (headings, links, content, schema), because auto-editing those on builder sites is
 * too risky (decision 2026-10-06).
 *
 * The rules (decision 2026-10-06, step 2): title missing / too wide in px / duplicate / missing the page's
 * main search; description missing / too long / too short / duplicate; H1 missing / several / heading order;
 * image alt missing, empty (alt="", reported apart: right for decoration) or a single word; image weight;
 * internal links in (orphans; menu and footer links count) and out; broken internal links; links through a
 * redirect; noindex or a canonical elsewhere while in the sitemap, or an indexable page missing from it;
 * a short page (not for contact, team, "other" pages, forms or calculators); OG image; the page type.
 *
 * An editor finding on more than half the pages is a template's, not a page's: Scanner::finalize() takes
 * it off the pages and reports it once for the site (site_wide()).
 *
 * Pure PHP apart from translation, unit-tested.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates the best-practice rules.
 */
class Rules {

	/** Description length Google shows on desktop before cutting it, in characters (about). */
	public const DESC_MAX = 160;

	/** A description shorter than this leaves Google to write its own. */
	public const DESC_MIN = 70;

	/** Fewer words than this is thin for a service page or post. */
	public const THIN_WORDS = 300;

	/** An image heavier than this many KB slows the page. */
	public const HEAVY_KB = 300;

	/** Internal links out of the content below this count are too few. */
	public const MIN_LINKS_OUT = 2;

	/**
	 * Issue kinds in the order the screens show them, with their filter-chip labels.
	 *
	 * @return array<string,string>
	 */
	public static function kinds(): array {
		return [
			'title'       => __( 'Titles', 'ai-seo-assistant' ),
			'description' => __( 'Descriptions', 'ai-seo-assistant' ),
			'headings'    => __( 'Headings', 'ai-seo-assistant' ),
			'alt'         => __( 'Alt text', 'ai-seo-assistant' ),
			'weight'      => __( 'Image weight', 'ai-seo-assistant' ),
			'links'       => __( 'Links', 'ai-seo-assistant' ),
			'indexing'    => __( 'Indexing', 'ai-seo-assistant' ),
			'schema'      => __( 'Schema', 'ai-seo-assistant' ),
			'thin'        => __( 'Short pages', 'ai-seo-assistant' ),
		];
	}

	/**
	 * Short label for one kind ("Title", "Alt text") used on the issue rows.
	 *
	 * @param string $kind Kind.
	 */
	public static function label( string $kind ): string {
		$labels = [
			'title'       => __( 'Title', 'ai-seo-assistant' ),
			'description' => __( 'Description', 'ai-seo-assistant' ),
			'headings'    => __( 'Headings', 'ai-seo-assistant' ),
			'alt'         => __( 'Alt text', 'ai-seo-assistant' ),
			'weight'      => __( 'Images', 'ai-seo-assistant' ),
			'links'       => __( 'Links', 'ai-seo-assistant' ),
			'indexing'    => __( 'Indexing', 'ai-seo-assistant' ),
			'schema'      => __( 'Schema', 'ai-seo-assistant' ),
			'thin'        => __( 'Content', 'ai-seo-assistant' ),
		];

		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * All issues for one page.
	 *
	 * @param array<string,mixed> $facts Page facts.
	 * @param array<string,mixed> $ctx   Site context for this page (see Scanner::context_for()).
	 * @return array<int,array<string,mixed>>
	 */
	public static function evaluate( array $facts, array $ctx ): array {
		return array_values(
			array_filter(
				array_merge(
					self::title( $facts, $ctx ),
					self::description( $facts, $ctx ),
					self::headings( $facts ),
					self::images( $facts, $ctx ),
					self::links( $facts, $ctx ),
					self::indexing( $facts, $ctx ),
					self::thin( $facts, $ctx ),
					self::schema( $facts, $ctx )
				)
			)
		);
	}

	/**
	 * One issue.
	 *
	 * @param string              $kind   Kind.
	 * @param string              $code   Machine code.
	 * @param string              $title  Headline.
	 * @param string              $detail Why it matters.
	 * @param string              $fix    What to do.
	 * @param string              $who    'claude' | 'editor'.
	 * @param array<string,mixed> $data   Extra data.
	 * @return array<string,mixed>
	 */
	protected static function issue( string $kind, string $code, string $title, string $detail, string $fix, string $who, array $data = [] ): array {
		return [
			'kind'   => $kind,
			'code'   => $code,
			'title'  => $title,
			'detail' => $detail,
			'fix'    => $fix,
			'who'    => $who,
			'data'   => $data,
		];
	}

	/**
	 * Title rules.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @param array<string,mixed> $ctx   Context.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function title( array $facts, array $ctx ): array {
		$title = (string) ( $facts['title'] ?? '' );
		$query = (string) ( $ctx['top_query'] ?? '' );
		$out   = [];
		if ( '' === $title ) {
			return [ self::issue( 'title', 'title_missing', __( 'No title tag', 'ai-seo-assistant' ), __( 'Google writes its own title from the page, usually badly.', 'ai-seo-assistant' ), __( 'Fix: a title that says what the page offers and where.', 'ai-seo-assistant' ), 'claude' ) ];
		}
		$px = Title_Width::px( $title );
		if ( $px > Title_Width::LIMIT_PX ) {
			$visible = Title_Width::visible( $title );
			$cut     = mb_substr( rtrim( $visible, "\u{2026}" ), -24 );
			$out[]   = self::issue(
				'title',
				'title_wide',
				/* translators: 1: width in px, 2: width Google shows in px. */
				sprintf( __( 'Title is too wide for Google: %1$d px, about %2$d px shows', 'ai-seo-assistant' ), $px, Title_Width::LIMIT_PX ),
				/* translators: %s: the last words Google shows before cutting the title. */
				sprintf( __( 'Google cuts it off after “…%s”, so the words after it never show.', 'ai-seo-assistant' ), trim( $cut ) ),
				'' !== $query
					/* translators: %s: the page's main search. */
					? sprintf( __( 'Fix: a shorter title that leads with “%s”.', 'ai-seo-assistant' ), $query )
					: __( 'Fix: a shorter title that leads with what the page offers.', 'ai-seo-assistant' ),
				'claude',
				[ 'px' => $px ]
			);
		}
		$dupes = (int) ( $ctx['title_dupes'] ?? 0 );
		if ( $dupes > 0 ) {
			$out[] = self::issue(
				'title',
				'title_duplicate',
				/* translators: %d: number of other pages. */
				sprintf( _n( 'Same title as %d other page', 'Same title as %d other pages', $dupes, 'ai-seo-assistant' ), $dupes ),
				__( 'Google cannot tell the pages apart and may show the wrong one.', 'ai-seo-assistant' ),
				__( 'Fix: a title written for this page alone.', 'ai-seo-assistant' ),
				'claude'
			);
		}
		if ( '' !== $query && ! self::contains_query( $title, $query ) ) {
			$out[] = self::issue(
				'title',
				'title_no_query',
				__( 'Title misses the main search', 'ai-seo-assistant' ),
				/* translators: %s: the search that brings this page the most clicks. */
				sprintf( __( '“%s” brings this page the most traffic from Google, but the title does not say it.', 'ai-seo-assistant' ), $query ),
				__( 'Fix: a title, and a focus keyphrase, built around that search.', 'ai-seo-assistant' ),
				'claude',
				[ 'query' => $query ]
			);
		}

		return $out;
	}

	/**
	 * Description rules.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @param array<string,mixed> $ctx   Context.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function description( array $facts, array $ctx ): array {
		$desc = (string) ( $facts['description'] ?? '' );
		$len  = mb_strlen( $desc );
		$out  = [];
		if ( '' === $desc ) {
			return [ self::issue( 'description', 'desc_missing', __( 'No meta description', 'ai-seo-assistant' ), __( 'Google picks random text from the page for the listing.', 'ai-seo-assistant' ), __( 'Fix: a description written for this page and its searches.', 'ai-seo-assistant' ), 'claude' ) ];
		}
		if ( $len > self::DESC_MAX ) {
			/* translators: 1: length, 2: about how many Google shows. */
			$out[] = self::issue( 'description', 'desc_long', sprintf( __( 'Description is too long: %1$d characters, about %2$d show', 'ai-seo-assistant' ), $len, self::DESC_MAX ), __( 'Google cuts it mid-sentence, often before the call to action.', 'ai-seo-assistant' ), __( 'Fix: one or two complete sentences that fit.', 'ai-seo-assistant' ), 'claude', [ 'length' => $len ] );
		} elseif ( $len < self::DESC_MIN ) {
			/* translators: %d: length in characters. */
			$out[] = self::issue( 'description', 'desc_short', sprintf( __( 'Description is short: %d characters', 'ai-seo-assistant' ), $len ), __( 'A short description leaves Google to write its own from the page.', 'ai-seo-assistant' ), __( 'Fix: say what the page offers, for whom, and the next step.', 'ai-seo-assistant' ), 'claude', [ 'length' => $len ] );
		}
		$dupes = (int) ( $ctx['desc_dupes'] ?? 0 );
		if ( $dupes > 0 ) {
			/* translators: %d: number of other pages. */
			$out[] = self::issue( 'description', 'desc_duplicate', sprintf( _n( 'Same description as %d other page', 'Same description as %d other pages', $dupes, 'ai-seo-assistant' ), $dupes ), __( 'Google often swaps duplicate descriptions for random text from the page.', 'ai-seo-assistant' ), __( 'Fix: a description written for this page and its searches.', 'ai-seo-assistant' ), 'claude' );
		}

		return $out;
	}

	/**
	 * Heading rules.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function headings( array $facts ): array {
		$h1  = (array) ( $facts['h1'] ?? [] );
		$out = [];
		if ( [] === $h1 ) {
			$out[] = self::issue( 'headings', 'h1_missing', __( 'No H1 heading', 'ai-seo-assistant' ), __( 'The main heading tells Google and screen readers what the page is about.', 'ai-seo-assistant' ), __( 'Fix: make the page’s main heading an H1.', 'ai-seo-assistant' ), 'editor' );
		} elseif ( count( $h1 ) > 1 ) {
			$out[] = self::issue(
				'headings',
				'h1_many',
				/* translators: %d: number of H1 headings. */
				sprintf( __( '%d H1 headings', 'ai-seo-assistant' ), count( $h1 ) ),
				/* translators: %s: the H1 headings, quoted. */
				sprintf( __( 'These are all H1: %s.', 'ai-seo-assistant' ), '“' . implode( '”, “', array_map( static fn( $t ) => mb_substr( (string) $t, 0, 50 ), array_slice( $h1, 0, 3 ) ) ) . '”' ),
				/* translators: %s: the first H1. */
				sprintf( __( 'Fix: keep one H1, “%s”, and make the others H2.', 'ai-seo-assistant' ), mb_substr( (string) $h1[0], 0, 60 ) ),
				'editor'
			);
		}
		$prev = 0;
		foreach ( (array) ( $facts['headings'] ?? [] ) as $h ) {
			$level = (int) ( $h['l'] ?? 0 );
			if ( $prev > 0 && $level > $prev + 1 ) {
				$out[] = self::issue(
					'headings',
					'h_order',
					/* translators: 1: heading level, 2: the level it follows. */
					sprintf( __( 'Heading order skips a level: H%1$d after H%2$d', 'ai-seo-assistant' ), $level, $prev ),
					/* translators: %s: the heading text. */
					sprintf( __( '“%s” jumps a level, which breaks the page outline for screen readers.', 'ai-seo-assistant' ), mb_substr( (string) ( $h['t'] ?? '' ), 0, 60 ) ),
					/* translators: %d: the heading level to use. */
					sprintf( __( 'Fix: make it an H%d.', 'ai-seo-assistant' ), $prev + 1 ),
					'editor'
				);
				break;
			}
			$prev = $level;
		}

		return $out;
	}

	/**
	 * Image rules: alt text and weight.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @param array<string,mixed> $ctx   Context (heavy => [ [file, kb] ]).
	 * @return array<int,array<string,mixed>>
	 */
	protected static function images( array $facts, array $ctx ): array {
		// Decoration owes no alt; an image on most pages (the logo, a header photo) is the template's, reported
		// once for the site (template_images()), never on each page.
		$template  = array_flip( (array) ( $ctx['template_files'] ?? [] ) );
		$images    = array_values( array_filter( (array) ( $facts['images'] ?? [] ), static fn( $i ) => empty( $i['decorative'] ) && ! isset( $template[ (string) ( $i['file'] ?? '' ) ] ) ) );
		$missing   = 0;
		$empty     = 0;
		$weak      = [];
		$unprinted = [];
		$mismatch  = [];
		$shared    = self::shared_alts( $images );
		foreach ( $images as $img ) {
			$alt    = $img['alt'] ?? null;
			$file   = (string) ( $img['file'] ?? '' );
			$stored = trim( (string) ( $img['stored_alt'] ?? '' ) );
			$good_s = '' !== $stored && ! self::weak_alt( $stored, $file ) && ! isset( $shared[ self::alt_key( $stored ) ] );
			if ( null !== $alt && isset( $shared[ self::alt_key( (string) $alt ) ] ) ) {
				if ( $good_s ) {
					$mismatch[] = $file; // A copy-pasted alt printed over the Media Library's good one.
				} else {
					$weak[] = (string) $alt; // The same alt on different photos.
				}
				continue;
			}
			$poor = null === $alt || '' === trim( (string) $alt ) || self::weak_alt( (string) $alt, $file );
			if ( $poor && $good_s ) {
				$unprinted[] = $file; // The Media Library has a good alt the page does not print.
				continue;
			}
			// A good printed alt is left alone, even when the Media Library says something else: a builder
			// module's alt is often the right label for its place on the page.
			if ( null === $alt ) {
				++$missing;
			} elseif ( '' === trim( (string) $alt ) ) {
				++$empty;
			} elseif ( self::weak_alt( (string) $alt, $file ) ) {
				$weak[] = (string) $alt;
			}
		}
		$out = [];
		if ( [] !== $unprinted ) {
			$out[] = self::issue(
				'alt',
				'alt_unprinted',
				/* translators: %d: number of images. */
				sprintf( _n( '%d image has good alt text the page does not show', '%d images have good alt text the page does not show', count( $unprinted ), 'ai-seo-assistant' ), count( $unprinted ) ),
				/* translators: %s: file names. */
				sprintf( __( 'The Media Library describes %s, but the page prints the image without it (a builder module with its own empty alt field).', 'ai-seo-assistant' ), implode( ', ', array_slice( $unprinted, 0, 3 ) ) ),
				__( 'Fix: the page review writes the right alt into the page as well as the Media Library.', 'ai-seo-assistant' ),
				'claude',
				[ 'files' => $unprinted ]
			);
		}
		if ( [] !== $mismatch ) {
			$out[] = self::issue(
				'alt',
				'alt_mismatch',
				/* translators: %d: number of images. */
				sprintf( _n( '%d image shows copied alt text over a good Media Library one', '%d images show copied alt text over good Media Library ones', count( $mismatch ), 'ai-seo-assistant' ), count( $mismatch ) ),
				/* translators: %s: file names. */
				sprintf( __( 'The page prints the same alt on different photos (%s), so it describes at most one of them; the Media Library has a better one.', 'ai-seo-assistant' ), implode( ', ', array_slice( $mismatch, 0, 3 ) ) ),
				__( 'Fix: the page review looks at each photo and writes the right alt where the page prints it.', 'ai-seo-assistant' ),
				'claude',
				[ 'files' => $mismatch ]
			);
		}
		if ( $missing > 0 || [] !== $weak ) {
			$total = count( $images );
			/* translators: 1: images without alt text, 2: images on the page. */
			$title = $missing > 0 ? sprintf( _n( '%1$d of %2$d image has no alt text', '%1$d of %2$d images have no alt text', $total, 'ai-seo-assistant' ), $missing, $total ) : '';
			if ( [] !== $weak ) {
				/* translators: 1: count, 2: the weak alt text. */
				$weak_text = sprintf( _n( '%1$d says only “%2$s”', '%1$d say only “%2$s”', count( $weak ), 'ai-seo-assistant' ), count( $weak ), mb_substr( $weak[0], 0, 30 ) );
				$title     = '' === $title ? ucfirst( $weak_text ) : $title . '; ' . $weak_text;
			}
			$out[] = self::issue( 'alt', 'alt', $title, __( 'Screen readers and Google Images cannot tell what the photos show.', 'ai-seo-assistant' ), __( 'Fix: describe each photo in plain words.', 'ai-seo-assistant' ), 'claude', [ 'missing' => $missing ] );
		}
		if ( $empty > 0 ) {
			$out[] = self::issue(
				'alt',
				'alt_empty',
				/* translators: %d: number of images. */
				sprintf( _n( '%d image is marked as decoration (empty alt)', '%d images are marked as decoration (empty alt)', $empty, 'ai-seo-assistant' ), $empty ),
				__( 'An empty alt is right for a pattern, an icon, or a photo whose link or caption already names it; not for a photo that carries meaning.', 'ai-seo-assistant' ),
				__( 'Fix: check them in the page review; it describes the ones that carry meaning and leaves the rest empty.', 'ai-seo-assistant' ),
				'claude',
				[ 'empty' => $empty ]
			);
		}
		$heavy = (array) ( $ctx['heavy'] ?? [] );
		if ( [] !== $heavy ) {
			$first = $heavy[0];
			$out[] = self::issue(
				'weight',
				'heavy',
				/* translators: %d: number of images. */
				sprintf( _n( '%d heavy image', '%d heavy images', count( $heavy ), 'ai-seo-assistant' ), count( $heavy ) ),
				/* translators: 1: file name, 2: size in KB. */
				sprintf( __( '%1$s is %2$d KB; heavy images slow the page on phones.', 'ai-seo-assistant' ), (string) $first[0], (int) $first[1] ),
				/* translators: %d: KB limit. */
				sprintf( __( 'Fix: resize and save as WebP under %d KB in the Media Library.', 'ai-seo-assistant' ), self::HEAVY_KB ),
				'editor'
			);
		}

		return $out;
	}

	/**
	 * Alt texts the page prints on two or more DIFFERENT images (a builder module's copy-pasted alt, e.g.
	 * "Our Services" on a kitchen and a porch): such an alt describes at most one of them.
	 *
	 * @param array<int,array<string,mixed>> $images Page images.
	 * @return array<string,true> alt_key() => true.
	 */
	public static function shared_alts( array $images ): array {
		$files = [];
		foreach ( $images as $img ) {
			$key = self::alt_key( (string) ( $img['alt'] ?? '' ) );
			if ( '' !== $key ) {
				$files[ $key ][ (string) ( $img['file'] ?? '' ) ] = true;
			}
		}

		return array_map( static fn() => true, array_filter( $files, static fn( $f ) => count( $f ) > 1 ) );
	}

	/**
	 * Alt text for comparison: entities decoded, quotes straightened, lower case, spaces collapsed.
	 *
	 * @param string $alt Alt text.
	 */
	public static function alt_key( string $alt ): string {
		$alt = html_entity_decode( $alt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$alt = str_replace( [ "\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}" ], [ '"', '"', "'", "'" ], $alt );

		return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $alt ) ) );
	}

	/**
	 * Whether alt text says nothing useful: one short word, or the file name.
	 *
	 * @param string $alt  Alt text.
	 * @param string $file File name.
	 */
	public static function weak_alt( string $alt, string $file ): bool {
		$alt  = strtolower( trim( $alt ) );
		$stem = strtolower( (string) preg_replace( '/\.[a-z0-9]+$/i', '', $file ) );
		if ( '' !== $stem && ( $alt === $stem || str_replace( [ '-', '_' ], ' ', $stem ) === $alt ) ) {
			return true;
		}

		return 1 === count( preg_split( '/\s+/u', $alt ) ) && mb_strlen( $alt ) <= 12;
	}

	/**
	 * Internal link rules.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @param array<string,mixed> $ctx   Context (inbound, inbound_from, suggest_from, broken, redirected, is_front).
	 * @return array<int,array<string,mixed>>
	 */
	protected static function links( array $facts, array $ctx ): array {
		$out     = [];
		$inbound = (int) ( $ctx['inbound'] ?? 0 ) + ( empty( $ctx['in_menu'] ) ? 0 : 2 ); // In the menu or footer: linked from every page.
		$from    = array_slice( (array) ( $ctx['suggest_from'] ?? [] ), 0, 2 );
		$fix     = [] === $from ? __( 'Fix: link to this page from related pages.', 'ai-seo-assistant' )
			/* translators: %s: pages to link from. */
			: sprintf( __( 'Fix: link from %s.', 'ai-seo-assistant' ), implode( __( ' and ', 'ai-seo-assistant' ), $from ) );
		if ( empty( $ctx['is_front'] ) ) {
			if ( 0 === $inbound ) {
				$out[] = self::issue( 'links', 'orphan', __( 'No other page links here', 'ai-seo-assistant' ), __( 'An orphan page is hard for Google and visitors to find.', 'ai-seo-assistant' ), $fix, 'editor' );
			} elseif ( $inbound < 2 ) {
				$out[] = self::issue( 'links', 'few_in', __( 'Only 1 page links here', 'ai-seo-assistant' ), __( 'Pages with few internal links get less attention from Google and visitors.', 'ai-seo-assistant' ), $fix, 'editor' );
			}
		}
		$links_out = count( array_unique( array_column( (array) ( $facts['links'] ?? [] ), 'p' ) ) );
		if ( $links_out < self::MIN_LINKS_OUT ) {
			/* translators: %d: number of links. */
			$out[] = self::issue( 'links', 'few_out', sprintf( _n( 'The content links to %d other page', 'The content links to %d other pages', $links_out, 'ai-seo-assistant' ), $links_out ), __( 'Links from the text help visitors to the next step and tell Google what is related.', 'ai-seo-assistant' ), __( 'Fix: link the services and pages this one mentions.', 'ai-seo-assistant' ), 'editor' );
		}
		$broken = array_values( array_unique( (array) ( $ctx['broken'] ?? [] ) ) );
		if ( [] !== $broken ) {
			/* translators: %d: number of links. */
			$out[] = self::issue( 'links', 'broken', sprintf( _n( '%d broken internal link', '%d broken internal links', count( $broken ), 'ai-seo-assistant' ), count( $broken ) ), sprintf( /* translators: %s: link targets. */ __( 'Links to %s, which is not found.', 'ai-seo-assistant' ), implode( ', ', array_slice( $broken, 0, 3 ) ) ), __( 'Fix: point the link at the right page, or add a redirect in AJR Core.', 'ai-seo-assistant' ), 'editor', [ 'paths' => $broken ] );
		}
		$redirected = (array) ( $ctx['redirected'] ?? [] );
		if ( [] !== $redirected ) {
			$first = $redirected[0];
			/* translators: %d: number of links. */
			$out[] = self::issue( 'links', 'via_redirect', sprintf( _n( '%d link goes through a redirect', '%d links go through a redirect', count( $redirected ), 'ai-seo-assistant' ), count( $redirected ) ), sprintf( /* translators: 1: old address, 2: where it redirects. */ __( '%1$s redirects to %2$s; every visit takes an extra hop.', 'ai-seo-assistant' ), (string) $first[0], (string) $first[1] ), __( 'Fix: link straight to the final address.', 'ai-seo-assistant' ), 'editor' );
		}

		return $out;
	}

	/**
	 * Indexing rules: noindex / canonical against the sitemap.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @param array<string,mixed> $ctx   Context (in_sitemap: bool|null, self_url, impressions).
	 * @return array<int,array<string,mixed>>
	 */
	protected static function indexing( array $facts, array $ctx ): array {
		if ( ! empty( $ctx['discouraged'] ) ) {
			return []; // "Discourage search engines" is on (a staging or local copy): every page says noindex.
		}
		$in_map    = $ctx['in_sitemap'] ?? null;
		$noindex   = ! empty( $facts['noindex'] );
		$canonical = (string) ( $facts['canonical'] ?? '' );
		$self      = (string) ( $ctx['self_url'] ?? '' );
		$elsewhere = '' !== $canonical && '' !== $self && self::bare_url( $canonical ) !== self::bare_url( $self );
		$out       = [];
		if ( $noindex && true === $in_map ) {
			$out[] = self::issue( 'indexing', 'noindex_in_sitemap', __( 'Set to noindex but listed in the sitemap', 'ai-seo-assistant' ), __( 'The sitemap asks Google to index a page the page itself says not to.', 'ai-seo-assistant' ), __( 'Fix: decide which is right; remove it from the sitemap or allow indexing.', 'ai-seo-assistant' ), 'editor' );
		} elseif ( $noindex && (int) ( $ctx['impressions'] ?? 0 ) > 0 ) {
			$out[] = self::issue( 'indexing', 'noindex_seen', __( 'Set to noindex but Google still shows it', 'ai-seo-assistant' ), __( 'It will drop out of search; check that is intended.', 'ai-seo-assistant' ), __( 'Fix: allow indexing in the SEO plugin if this page should be found.', 'ai-seo-assistant' ), 'editor' );
		} elseif ( ! $noindex && false === $in_map && ! $elsewhere ) {
			$out[] = self::issue( 'indexing', 'not_in_sitemap', __( 'Not in the sitemap', 'ai-seo-assistant' ), __( 'Google finds pages fastest through the sitemap.', 'ai-seo-assistant' ), __( 'Fix: include this post type in the SEO plugin’s sitemap settings.', 'ai-seo-assistant' ), 'editor' );
		}
		if ( $elsewhere && true === $in_map ) {
			/* translators: %s: canonical URL. */
			$out[] = self::issue( 'indexing', 'canonical_elsewhere', __( 'Canonical points to another page', 'ai-seo-assistant' ), sprintf( __( 'It names %s as the original, yet the sitemap lists this page.', 'ai-seo-assistant' ), $canonical ), __( 'Fix: canonical to itself, or remove it from the sitemap.', 'ai-seo-assistant' ), 'editor' );
		}

		return $out;
	}

	/**
	 * Thin content.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @param array<string,mixed> $ctx   Context (is_front, is_utility, page_type, is_tool).
	 * @return array<int,array<string,mixed>>
	 */
	protected static function thin( array $facts, array $ctx ): array {
		$words = (int) ( $facts['words'] ?? 0 );
		if ( ! empty( $ctx['is_front'] ) || ! empty( $ctx['is_utility'] ) || ! empty( $ctx['is_tool'] ) || $words >= self::THIN_WORDS ) {
			return [];
		}
		// A contact page, a team member's page or an "other" page is short by nature: not a finding.
		if ( in_array( (string) ( $ctx['page_type'] ?? '' ), [ 'contact', 'team_member', 'other' ], true ) ) {
			return [];
		}

		/* translators: %d: number of words. */
		return [ self::issue( 'thin', 'thin', sprintf( _n( 'Short page: %d word', 'Short page: %d words', $words, 'ai-seo-assistant' ), $words ), __( 'If this page should rank, more of what visitors ask about its subject usually helps.', 'ai-seo-assistant' ), __( 'Fix (if it should rank): answer the questions people search for, in your own words.', 'ai-seo-assistant' ), 'editor', [ 'words' => $words ] ) ];
	}

	/**
	 * Images that appear on more than half the pages (at least 6 scanned): the theme's or a builder
	 * template's (a logo, a profile photo in the header or footer), whatever their alt.
	 *
	 * @param array<int,array<string,mixed>> $rows Facts rows (all_facts()).
	 * @return array<string,array{pages:int,alt:?string,stored:string}> file => pages it is on, its alt.
	 */
	public static function template_images( array $rows ): array {
		$pages = count( $rows );
		if ( $pages < 6 ) {
			return [];
		}
		$seen = [];
		foreach ( $rows as $row ) {
			$files = [];
			foreach ( (array) ( $row['facts']['images'] ?? [] ) as $img ) {
				$file = (string) ( $img['file'] ?? '' );
				if ( '' === $file || isset( $files[ $file ] ) ) {
					continue;
				}
				$files[ $file ] = true;
				if ( ! isset( $seen[ $file ] ) ) {
					$seen[ $file ] = [
						'pages'  => 0,
						'alt'    => $img['alt'] ?? null,
						'stored' => (string) ( $img['stored_alt'] ?? '' ),
						'deco'   => ! empty( $img['decorative'] ),
					];
				}
				++$seen[ $file ]['pages'];
			}
		}

		return array_filter( $seen, static fn( $s ) => $s['pages'] * 2 > $pages );
	}

	/**
	 * The one finding for template images whose alt is missing or weak (null when none is): set it once, in
	 * the template.
	 *
	 * @param array<string,array<string,mixed>> $template template_images().
	 * @return array<string,mixed>|null An issue (site-wide), or null.
	 */
	public static function template_alt_issue( array $template ): ?array {
		$poor = [];
		$max  = 0;
		foreach ( $template as $file => $t ) {
			$alt = $t['alt'];
			if ( ! empty( $t['deco'] ) ) {
				continue;
			}
			if ( null === $alt || '' === trim( (string) $alt ) || self::weak_alt( (string) $alt, (string) $file ) ) {
				$poor[] = (string) $file;
				$max    = max( $max, (int) $t['pages'] );
			}
		}
		if ( [] === $poor ) {
			return null;
		}
		$issue = self::issue(
			'alt',
			'template_alt',
			/* translators: %d: number of images. */
			sprintf( _n( '%d image on almost every page has no useful alt text', '%d images on almost every page have no useful alt text', count( $poor ), 'ai-seo-assistant' ), count( $poor ) ),
			/* translators: %s: file names. */
			sprintf( __( '%s sits in the header, footer or a builder template, so the same missing alt repeats on every page.', 'ai-seo-assistant' ), implode( ', ', array_slice( $poor, 0, 3 ) ) ),
			__( 'Fix: set the alt once, in the Theme Builder template, header or footer (a logo’s alt is the business name).', 'ai-seo-assistant' ),
			'editor',
			[ 'files' => $poor ]
		);
		return [
			'issue' => $issue,
			'pages' => $max,
		];
	}

	/**
	 * Editor findings that are on more than half the pages (at least 6 pages scanned): a template's, not a
	 * page's. Claude and one-click findings stay per page (each page is its own fix).
	 *
	 * @param array<int,array<int,array<string,mixed>>> $issues Issues by post ID.
	 * @return array<string,array{issue:array<string,mixed>,pages:int}> code => one example issue and its count.
	 */
	public static function site_wide( array $issues ): array {
		$pages = count( $issues );
		if ( $pages < 6 ) {
			return [];
		}
		$seen = [];
		foreach ( $issues as $list ) {
			foreach ( $list as $issue ) {
				if ( 'editor' !== ( $issue['who'] ?? '' ) ) {
					continue;
				}
				$code = (string) $issue['code'];
				if ( ! isset( $seen[ $code ] ) ) {
					$seen[ $code ] = [
						'issue' => $issue,
						'pages' => 0,
					];
				}
				++$seen[ $code ]['pages'];
			}
		}

		return array_filter( $seen, static fn( $s ) => $s['pages'] * 2 > $pages );
	}

	/**
	 * Schema for the page type, and the sharing image.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @param array<string,mixed> $ctx   Context (post_type, is_service, custom_schema_on).
	 * @return array<int,array<string,mixed>>
	 */
	protected static function schema( array $facts, array $ctx ): array {
		$out = [];
		// Schema is never an editor job (decision 2026-10-06): AJR Core prints it from the page type and the
		// business details. The one page-level finding is a missing page type, fixed in one click.
		// Posts are left out: the SEO plugin already marks them up as articles, so 100 blog posts do not
		// become 100 findings.
		// Only a page AJR Core is unsure about: an obvious type is set automatically (Auto_Types), and a page
		// nothing points anywhere is "other", which is not a problem.
		if ( ! empty( $ctx['page_types'] ) && '' === (string) ( $ctx['page_type'] ?? '' ) && 'post' !== ( $ctx['post_type'] ?? '' ) && 'medium' === ( $ctx['type_confidence'] ?? 'medium' ) ) {
			$labels  = (array) ( $ctx['type_labels'] ?? [] );
			$suggest = (string) ( $ctx['suggested_type'] ?? '' );
			$out[]   = self::issue(
				'schema',
				'page_type_unset',
				__( 'Page type not set', 'ai-seo-assistant' ),
				'' !== $suggest && isset( $labels[ $suggest ] )
					/* translators: %s: page type, e.g. "Service page". */
					? sprintf( __( 'Google can’t tell this is a %s, so it reads it as a plain web page.', 'ai-seo-assistant' ), mb_strtolower( (string) $labels[ $suggest ] ) )
					: __( 'Google can’t tell what this page is for, so it reads it as a plain web page.', 'ai-seo-assistant' ),
				'' !== $suggest && isset( $labels[ $suggest ] )
					/* translators: %s: page type. */
					? sprintf( __( 'Fix: set the page type to %s (one click, in “What Google reads on this page”).', 'ai-seo-assistant' ), (string) $labels[ $suggest ] )
					: __( 'Fix: choose the page type (one click, in “What Google reads on this page”).', 'ai-seo-assistant' ),
				'click'
			);
		}
		if ( '' === (string) ( $facts['og_image'] ?? '' ) ) {
			$out[] = self::issue( 'schema', 'og_image', __( 'No sharing image', 'ai-seo-assistant' ), __( 'A link shared on Facebook, LinkedIn or in a message shows no picture.', 'ai-seo-assistant' ), __( 'Fix: set a featured image or a social image in the SEO plugin.', 'ai-seo-assistant' ), 'editor' );
		}

		return $out;
	}

	/**
	 * Whether a title contains a search: every word of three letters or more appears in it.
	 *
	 * @param string $title Title.
	 * @param string $query Search.
	 */
	public static function contains_query( string $title, string $query ): bool {
		$have = array_flip( self::stems( $title ) );
		foreach ( self::stems( $query ) as $stem ) {
			if ( ! isset( $have[ $stem ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a search is about the page's topic: more than half its meaningful words (stemmed) are in the
	 * page's title or path. A shared town name alone ("boise weather" on a Boise plumber's page) is not
	 * enough: an informational search is only the page's main search when it is about what the page is about.
	 *
	 * @param string            $query  Search.
	 * @param string            $topic  The page's title and path.
	 * @param array<int,string> $common Stems most pages share (the town, the state), set aside on both sides.
	 * @param bool              $most   More than half the search's words (a learning search), else any one.
	 */
	public static function shares_topic( string $query, string $topic, array $common = [], bool $most = true ): bool {
		$words = array_values( array_diff( self::stems( $query ), $common ) );
		$have  = count( array_intersect( $words, array_diff( self::stems( $topic ), $common ) ) );

		return [] !== $words && ( $most ? $have * 2 > count( $words ) : $have > 0 );
	}

	/**
	 * A text's meaningful words, simply stemmed: lower case, stopwords out (in, the, near, me…), and
	 * "heaters", "heating", "sellings" matched as "heater", "heat", "sell".
	 *
	 * @param string $text Text.
	 * @return array<int,string>
	 */
	public static function stems( string $text ): array {
		static $stop = [ 'a', 'an', 'the', 'in', 'on', 'of', 'for', 'to', 'and', 'or', 'at', 'by', 'with', 'from', 'near', 'me', 'my', 'is', 'are', 'it', 'its', 'id', 'how', 'what', 'do', 'does', 'i', 'you', 'your', 'vs' ];
		$out         = [];
		foreach ( preg_split( '/\s+/u', self::normalise( $text ) ) as $word ) {
			if ( '' === $word || mb_strlen( $word ) < 2 || in_array( $word, $stop, true ) ) {
				continue;
			}
			// Plural first ("sellings" → "selling"), then -ing ("selling" → "sell").
			if ( mb_strlen( $word ) > 3 && str_ends_with( $word, 's' ) && ! str_ends_with( $word, 'ss' ) ) {
				$word = mb_substr( $word, 0, -1 );
			}
			if ( mb_strlen( $word ) > 5 && str_ends_with( $word, 'ing' ) ) {
				$word = mb_substr( $word, 0, -3 );
			}
			$out[] = $word;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Lower-case words only.
	 *
	 * @param string $text Text.
	 */
	protected static function normalise( string $text ): string {
		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $text ) ) );
	}

	/**
	 * A URL for comparison: no scheme, no "www.", no trailing slash, lower case.
	 *
	 * @param string $url URL.
	 */
	public static function bare_url( string $url ): string {
		return rtrim( (string) preg_replace( '#^https?://(www\.)?#i', '', strtolower( $url ) ), '/' );
	}
}
