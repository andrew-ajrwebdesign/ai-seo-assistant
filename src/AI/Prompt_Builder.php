<?php
/**
 * Prompt_Builder — the page review's prompt and reply schema (5.0). The 4.x editor box's metadata and
 * recommendation prompts were removed with the box (the editor now shows the page's to-dos only).
 *
 * @package AJR\SEOAssistant
 */

namespace AJR\SEOAssistant\AI;

defined( 'ABSPATH' ) || exit;

class Prompt_Builder {

	/**
	 * JSON schema for the 5.0 page review: title, description, focus keyphrase and image alt text to apply,
	 * plus "do in the editor" advice for what the plugin never edits (headings, links, content). Never schema: AJR
	 * Core prints it from the page type and the business details, and the scan's rules report it.
	 *
	 * @return array
	 */
	public function review_schema() {
		$field = [
			'type'                 => 'object',
			'properties'           => [
				'value' => [ 'type' => 'string' ],
				'why'   => [ 'type' => 'string' ],
			],
			'required'             => [ 'value', 'why' ],
			'additionalProperties' => false,
		];

		return [
			'type'                 => 'object',
			'properties'           => [
				'title'       => $field,
				'description' => $field,
				'keyphrase'   => $field,
				'alts'        => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [
							'image_id' => [ 'type' => 'integer' ],
							'alt'      => [ 'type' => 'string' ],
							'why'      => [ 'type' => 'string' ],
						],
						'required'             => [ 'image_id', 'alt', 'why' ],
						'additionalProperties' => false,
					],
				],
				'editor'      => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [
							'area'   => [
								'type' => 'string',
								'enum' => [ 'headings', 'links', 'content' ],
							],
							'advice' => [ 'type' => 'string' ],
							// What the plugin checks on the page later (Review\Editor_Check): the new H1 or H2, the
							// phrase to add, the words to link with (several: separated by "|").
							'check'  => [
								'type' => 'string',
								'enum' => [ 'h1', 'h2', 'phrase', 'link', 'none' ],
							],
							'target' => [ 'type' => 'string' ],
							'source' => [ 'type' => 'string' ],
						],
						'required'             => [ 'area', 'advice', 'check', 'target', 'source' ],
						'additionalProperties' => false,
					],
				],
			],
			'required'             => [ 'title', 'description', 'keyphrase', 'alts', 'editor' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * The page review prompt (5.0): content, the page's real searches and Analytics, the business, the
	 * scan's findings and the best-practice rules. Images needing alt text are attached separately (as
	 * image blocks), each introduced by its attachment ID.
	 *
	 * @param array $args post_title, permalink, content, current (title, description, keyphrase),
	 *                    queries (rows), totals (clicks, impressions, ctr, position, expected_ctr), ga4,
	 *                    business (lines), tone, issues (lines), images (id, file, alt), headings (lines),
	 *                    keyphrase_supported (bool), seo_plugin (name), title_suffix (what the SEO plugin
	 *                    appends), include_brand ('yes'|'no'), avoid_phrases, siblings (lines).
	 * @return string
	 */
	public function build_review_prompt( $args ) {
		$p   = [];
		$p[] = 'Review one page of a small business website and write what its search listing and images need.';
		$p[] = 'Return: an SEO title, a meta description, a focus keyphrase, alt text for each attached image, and "editor" advice for headings, links and content, which a person will change by hand.';
		$p[] = 'Never give advice about schema, structured data or JSON-LD: the site prints it from the page type and the business details, and the scan checks it.';
		$p[] = '';
		$p[] = 'Rules for the title:';
		$p[] = '- At most about 55 characters, so it fits Google\'s desktop width (about 580 px in Arial 20px). Never longer than 60 characters.';
		$p[] = '- Lead with the page\'s main search (given below, chosen from the searches that bring this page real traffic and are about what it is for), worded naturally. If no main search is given, lead with what the page offers.';
		$p[] = '- If the top search is not what this page is for (a competitor\'s or another business\'s name, or a topic the page does not cover), say so in the why and write for what the page is for. Never retarget a service, contact or other money page around a competitor or an off-topic search.';
		if ( '' !== (string) ( $args['title_suffix'] ?? '' ) ) {
			$p[] = '- The SEO plugin adds "' . $args['title_suffix'] . '" to the end of every title by itself: do NOT include the business name, and leave room for that ending (the whole title, ending included, must fit).';
		} else {
			$p[] = '- Then a short differentiator or the business name, separated by " | ".';
		}
		$p[] = '- No keyword stuffing, no ALL CAPS.';
		if ( 'yes' === (string) ( $args['include_brand'] ?? '' ) && '' === (string) ( $args['title_suffix'] ?? '' ) ) {
			$p[] = '- The agency wants the business name in the title.';
		}
		if ( ! empty( $args['siblings'] ) ) {
			$p[] = '- Other pages on the site already use the same title or description (below): write one that tells this page apart from them.';
			foreach ( (array) $args['siblings'] as $line ) {
				$p[] = '  - ' . $line;
			}
		}
		$p[] = 'Rules for the meta description:';
		$p[] = '- 120 to 155 characters, one or two complete sentences, ending on a full stop.';
		$p[] = '- Answer what the searcher wants (cost, speed, area, trust) using only facts from the page or the business details. End with the next step when the page supports one (a phone number only if it is in the business details).';
		$p[] = 'Rules for the focus keyphrase:';
		$p[] = '- The main search the page should rank for, lower case, 2 to 5 words: the main search given below when there is one, never a business\'s name or an off-topic search. It must appear in your title.';
		$p[] = 'Rules for alt text:';
		$p[] = '- Look at each attached photo and describe what is actually in it, in plain words, under 125 characters. Name a landmark only when you are certain which one it is (otherwise describe it: "a domed government building"). No "image of" or "photo of", no keyword lists, nothing the photo does not show.';
		$p[] = '- A logo or an image of text: the alt is the text it shows (a logo: the business name as written on it).';
		$p[] = '- A card or teaser image whose link or caption beside it already names the page (a blog card, a service tile): an empty alt, so screen readers do not read the name twice; say so in the why.';
		$p[] = '- If you cannot tell what a photo shows, return an empty alt and say so in the why; never guess from the file name or the page.';
		$p[] = '- Return one entry per attached image, using its image_id. A purely decorative image gets an empty alt and a why that says so.';
		$p[] = 'Rules for editor advice:';
		$p[] = '- Only what the scan findings or the page support; one specific sentence each (which heading to change to what, which page should link here and with what words). Skip an area with nothing useful to say.';
		$p[] = '- Never suggest a change that is already in place: compare with the headings and the links to this page given below. If the page already does it, omit the item.';
		$p[] = '- Give each item a check the plugin can verify later: "h1" or "h2" with target = the exact new heading; "phrase" with target = the exact words to add (several separated by "|"); "link" with target = the link words and source = the path of the page that should link here (several separated by "|"); "none" with target and source empty when nothing can be checked.';
		$p[] = 'General:';
		$p[] = '- Never invent services, prices, guarantees, awards, locations or claims. Write in the language of the page.';
		$p[] = '- Each "why" is one short sentence a business owner understands, citing the search data when it drove the choice (e.g. "1,240 impressions at position 4.1").';
		if ( empty( $args['keyphrase_supported'] ) ) {
			$p[] = '- The site\'s SEO plugin (' . ( $args['seo_plugin'] ?? '' ) . ') has no focus keyphrase field; still return one, as advice.';
		}
		if ( ! empty( $args['tone'] ) ) {
			$p[] = '- Tone and house rules from the agency: ' . $args['tone'];
		}
		if ( ! empty( $args['avoid_phrases'] ) ) {
			$p[] = '- Never use these phrases (the agency\'s list): ' . $args['avoid_phrases'] . '.';
		}
		$p[] = '';
		if ( ! empty( $args['business'] ) ) {
			$p[] = 'Business details (facts you may use):';
			foreach ( (array) $args['business'] as $line ) {
				$p[] = '- ' . $line;
			}
			$p[] = '';
		}
		if ( ! empty( $args['page_notes'] ) ) {
			$p[] = 'The agency\'s notes on this page (facts you may use):';
			foreach ( (array) $args['page_notes'] as $line ) {
				$p[] = '- ' . $line;
			}
		}
		if ( ! empty( $args['page_type'] ) ) {
			$p[] = 'Page type (set by the agency): ' . $args['page_type'] . '. Write for what this kind of page is for.';
		}
		if ( ! empty( $args['business_node'] ) ) {
			$p[] = 'What the site tells Google about the business: ' . $args['business_node'];
		}
		$p[] = 'Page: ' . ( $args['post_title'] ?? '' ) . ' (' . ( $args['permalink'] ?? '' ) . ')';
		$p[] = 'Now: title "' . ( $args['current']['title'] ?? '' ) . '"; description "' . ( $args['current']['description'] ?? '' ) . '"; focus keyphrase "' . ( $args['current']['keyphrase'] ?? '' ) . '".';
		if ( ! empty( $args['headings'] ) ) {
			$p[] = 'Headings now: ' . implode( ' / ', (array) $args['headings'] );
		}
		if ( ! empty( $args['inbound'] ) ) {
			$p[] = 'Links to this page now (from page | link text): ' . implode( ' / ', (array) $args['inbound'] );
		}
		$p[] = '';
		if ( ! empty( $args['totals'] ) ) {
			$t   = $args['totals'];
			$p[] = sprintf( 'Google Search Console, last 90 days: %d clicks, %d impressions, CTR %s%% (expected about %s%% at this position), average position %s.', $t['clicks'], $t['impressions'], $t['ctr'], $t['expected_ctr'], $t['position'] );
		} else {
			$p[] = 'No Search Console data for this page yet: write for what the page offers.';
		}
		if ( ! empty( $args['queries'] ) ) {
			$p[] = 'Top searches that showed this page (query | clicks | impressions | position | CTR % | intent: lead = ready to enquire, commercial = comparing, informational = learning, navigational = looking for a business by name):';
			foreach ( (array) $args['queries'] as $q ) {
				$p[] = sprintf( '- %s | %d | %d | %s | %s | %s', $q['query'], $q['clicks'], $q['impressions'], $q['position'] ?? '', $q['ctr'] ?? '', $q['intent'] ?? 'unknown' );
			}
			$p[] = '' !== (string) ( $args['main_query'] ?? '' ) ? 'Main search for this page: "' . $args['main_query'] . '".' : 'Main search for this page: none qualifies (no search with real traffic is about what this page is for); write for what the page offers.';
		}
		if ( ! empty( $args['ga4'] ) ) {
			$g   = $args['ga4'];
			$p[] = sprintf( 'Google Analytics, 90 days: %s visits, %s%% engaged, %s enquiries started on this page%s.', $g['visits'] ?? '?', $g['engaged'] ?? '?', $g['enquiries'] ?? '0', ! empty( $g['enquiry_note'] ) ? ' (' . $g['enquiry_note'] . ')' : '' );
		}
		$p[] = '';
		if ( ! empty( $args['issues'] ) ) {
			$p[] = 'What the scan found on the rendered page:';
			foreach ( (array) $args['issues'] as $line ) {
				$p[] = '- ' . $line;
			}
			$p[] = '';
		}
		if ( ! empty( $args['images'] ) ) {
			$p[] = 'Attached photos (each introduced by its image_id above):';
			foreach ( (array) $args['images'] as $img ) {
				if ( 'sync' === ( $img['mode'] ?? 'write' ) ) {
					$p[] = sprintf( '- image_id %d (file %s): the page shows the alt "%s" but the Media Library has "%s". Look at the photo: return the one that describes it correctly (unchanged), or a corrected alt if neither does, and say in the why which was wrong.', $img['id'], $img['file'], $img['printed'] ?? '', $img['stored'] ?? '' );
				} elseif ( 'check' === ( $img['mode'] ?? 'write' ) ) {
					$p[] = sprintf( '- image_id %d (file %s) already has the alt "%s". CHECK ONLY: if that alt matches what the photo shows, return it unchanged; only if it is clearly wrong (describes something not in the photo), return a corrected alt and say in the why what is wrong. Never reword a correct alt.', $img['id'], $img['file'], $img['alt'] ?? '' );
				} else {
					$p[] = sprintf( '- image_id %d (file %s) has no useful alt (now "%s"): write one from what the photo shows.', $img['id'], $img['file'], $img['alt'] ?? '' );
				}
			}
			$p[] = '';
		}
		$p[] = 'Page content:';
		$p[] = '<page_content>';
		$p[] = $this->neutralise_wrapper_tags( $args['content'] ?? '' );
		$p[] = '</page_content>';

		return implode( "\n", $p );
	}

	/**
	 * Removes the wrapper's own tags from page text.
	 *
	 * Content is cleaned with html_entity_decode(), so an author who types
	 * "&lt;/page_content&gt;" as visible text would otherwise close the
	 * wrapper early and have the rest of the page read as instructions.
	 *
	 * @param string $content Extracted page text.
	 * @return string
	 */
	protected function neutralise_wrapper_tags( $content ) {
		return str_ireplace( [ '<page_content>', '</page_content>' ], '', (string) $content );
	}
}
