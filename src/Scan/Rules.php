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
 * image alt missing or a single word; image weight; internal links in (orphans) and out; broken internal
 * links; links through a redirect; noindex or a canonical elsewhere while in the sitemap, or an indexable
 * page missing from it; thin content; OG image; schema for the page type (the Service advice only names
 * AJR Core's Custom schema module as on when it is).
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
			'thin'        => __( 'Thin content', 'ai-seo-assistant' ),
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
		$images  = (array) ( $facts['images'] ?? [] );
		$missing = 0;
		$weak    = [];
		foreach ( $images as $img ) {
			$alt = $img['alt'] ?? null;
			if ( null === $alt || '' === trim( (string) $alt ) ) {
				++$missing;
			} elseif ( self::weak_alt( (string) $alt, (string) ( $img['file'] ?? '' ) ) ) {
				$weak[] = (string) $alt;
			}
		}
		$out = [];
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
		$inbound = (int) ( $ctx['inbound'] ?? 0 );
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
	 * @param array<string,mixed> $ctx   Context (is_front, is_utility).
	 * @return array<int,array<string,mixed>>
	 */
	protected static function thin( array $facts, array $ctx ): array {
		$words = (int) ( $facts['words'] ?? 0 );
		if ( ! empty( $ctx['is_front'] ) || ! empty( $ctx['is_utility'] ) || $words >= self::THIN_WORDS ) {
			return [];
		}

		/* translators: %d: number of words. */
		return [ self::issue( 'thin', 'thin', sprintf( _n( 'Thin content: %d word', 'Thin content: %d words', $words, 'ai-seo-assistant' ), $words ), __( 'Google rarely ranks a page that says little about its subject.', 'ai-seo-assistant' ), __( 'Fix: answer the questions people search for, in your own words.', 'ai-seo-assistant' ), 'editor', [ 'words' => $words ] ) ];
	}

	/**
	 * Schema for the page type, and the sharing image.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @param array<string,mixed> $ctx   Context (post_type, is_service, custom_schema_on).
	 * @return array<int,array<string,mixed>>
	 */
	protected static function schema( array $facts, array $ctx ): array {
		$types = array_map( 'strtolower', (array) ( $facts['schema'] ?? [] ) );
		$out   = [];
		if ( [] === $types ) {
			$out[] = self::issue( 'schema', 'schema_none', __( 'No structured data', 'ai-seo-assistant' ), __( 'Google reads nothing machine-readable about this page.', 'ai-seo-assistant' ), __( 'Fix: check the SEO plugin’s schema settings for this post type.', 'ai-seo-assistant' ), 'editor' );
		} elseif ( 'post' === ( $ctx['post_type'] ?? '' ) && [] === array_intersect( $types, [ 'article', 'blogposting', 'newsarticle' ] ) ) {
			$out[] = self::issue( 'schema', 'schema_article', __( 'No Article schema', 'ai-seo-assistant' ), __( 'Google cannot read the author and dates of this post.', 'ai-seo-assistant' ), __( 'Fix: set the post type’s schema to Article in the SEO plugin.', 'ai-seo-assistant' ), 'editor' );
		} elseif ( ! empty( $ctx['is_service'] ) && ! in_array( 'service', $types, true ) ) {
			$out[] = self::issue(
				'schema',
				'schema_service',
				__( 'No Service schema', 'ai-seo-assistant' ),
				__( 'Google cannot read the service, the area served or the price range.', 'ai-seo-assistant' ),
				! empty( $ctx['custom_schema_on'] )
					? __( 'Fix: add a Service entry in AJR Core › Schema.', 'ai-seo-assistant' )
					: __( 'Fix: turn on Custom schema in AJR Core, then add a Service entry.', 'ai-seo-assistant' ),
				'editor'
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
		$title = ' ' . self::normalise( $title ) . ' ';
		foreach ( preg_split( '/\s+/u', self::normalise( $query ) ) as $word ) {
			if ( mb_strlen( $word ) >= 3 && false === strpos( $title, $word ) ) {
				// Plurals: "heaters" in the title covers "heater" in the search, and the reverse.
				if ( false === strpos( $title, rtrim( $word, 's' ) ) ) {
					return false;
				}
			}
		}

		return true;
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
