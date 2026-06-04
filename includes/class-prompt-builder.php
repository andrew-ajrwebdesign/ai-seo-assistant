<?php
/**
 * Builds AI prompts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Prompt_Builder {

	public function build_metadata_prompt( $args ) {
		$post_title           = isset( $args['post_title'] ) ? $args['post_title'] : '';
		$permalink            = isset( $args['permalink'] ) ? $args['permalink'] : '';
		$content              = isset( $args['content'] ) ? $args['content'] : '';
		$current_title        = isset( $args['current_title'] ) ? $args['current_title'] : '';
		$current_description  = isset( $args['current_description'] ) ? $args['current_description'] : '';
		$brand_context        = isset( $args['brand_context'] ) ? $args['brand_context'] : '';
		$global_local_context = isset( $args['global_local_context'] ) && is_array( $args['global_local_context'] ) ? $args['global_local_context'] : [];
		$page_local_context   = isset( $args['page_local_context'] ) && is_array( $args['page_local_context'] ) ? $args['page_local_context'] : [];

		$focus_mode         = isset( $global_local_context['focus_mode'] ) ? $global_local_context['focus_mode'] : 'general';
		$tone               = get_option( 'ai_seo_assistant_tone', 'clear, practical, direct, and not overly salesy' );
		$title_length       = absint( get_option( 'ai_seo_assistant_title_length', 60 ) );
		$description_length = absint( get_option( 'ai_seo_assistant_description_length', 155 ) );
		$avoid_phrases      = get_option( 'ai_seo_assistant_avoid_phrases', 'discover, explore, learn more, unlock, dive into, welcome to, comprehensive guide, ultimate guide, in today’s digital world' );
		$include_brand      = get_option( 'ai_seo_assistant_include_brand', 'no' );
		$metadata_guidance  = get_option( 'ai_seo_assistant_metadata_guidance', '' );

		$title_length       = $title_length > 0 ? $title_length : 60;
		$description_length = $description_length > 0 ? $description_length : 155;

		$prompt = [];

		$prompt[] = 'Create a search-friendly SEO title and meta description for the provided WordPress page.';
		$prompt[] = '';
		$prompt[] = 'Default strategy framework:';
		$prompt[] = '- Business Profile: use only the supplied brand/site context, SEO focus context, and page content as factual truth.';
		$prompt[] = '- Ideal Customer / Audience: use page search intent and page notes when provided; otherwise infer cautiously from the page content.';
		$prompt[] = '- Voice DNA: follow the tone setting and avoid banned phrases/jargon.';
		$prompt[] = '- Page Goal: infer from the page type and visible content unless explicitly provided.';
		$prompt[] = '- Real-World Signals: use Search Console or other supplied data only when present. Do not invent market demand.';
		$prompt[] = '';
		$prompt[] = 'Universal metadata rules:';
		$prompt[] = '- Use only the supplied page content, business context, SEO focus context, and client-specific guidance.';
		$prompt[] = '- Do not invent services, products, offers, discounts, guarantees, pricing, credentials, awards, locations, availability, or claims.';
		$prompt[] = '- Do not invent calls to action unless clearly supported by the page content or client guidance.';
		$prompt[] = '- Do not force a call to action into metadata. Use a next-step only when it is clearly supported by the page content.';
		$prompt[] = '- Do not use exaggerated or generic marketing wording such as transform, discover, unlock, boost, enhance, supercharge, revolutionize, ultimate, best, expert, guaranteed, business growth, tailored to your needs, drive more traffic, boost your rankings, or enhance your online presence unless client guidance specifically asks for that style.';
		$prompt[] = '- Avoid these additional unwanted phrases: ' . $avoid_phrases . '.';
		$prompt[] = '- Prefer clear, specific, practical wording over broad promotional language.';
		$prompt[] = '- Include important search terms only when they fit naturally and are supported by the page.';
		$prompt[] = '- Do not keyword stuff.';
		$prompt[] = '- Desired default tone: ' . $tone . '.';
		$prompt[] = '- SEO title should ideally be under ' . $title_length . ' characters.';
		$prompt[] = '- Meta description should ideally be under ' . $description_length . ' characters.';
		$prompt[] = '- Include the brand/site name in the SEO title only if this setting says yes: ' . $include_brand . '.';

		if ( 'local' === $focus_mode ) {
			$prompt[] = '- This site is using Local SEO mode. Use location context only when relevant and supported by the page focus/content.';
			$prompt[] = '- Do not force every location into the title or description.';
		} else {
			$prompt[] = '- This site is using General / Online mode. Do not force local SEO wording unless the page content clearly targets a location.';
		}

		$prompt[] = '';
		$prompt[] = 'Output rules:';
		$prompt[] = '- Return only valid JSON.';
		$prompt[] = '- Return exactly these keys: "title" and "description".';
		$prompt[] = '- Do not include markdown, explanations, notes, or extra keys.';
		$prompt[] = '';

		$this->append_business_context( $prompt, $brand_context, $metadata_guidance, '', '' );
		$this->append_focus_context( $prompt, $global_local_context, $page_local_context, $focus_mode );

		$prompt[] = 'Page title:';
		$prompt[] = $post_title;
		$prompt[] = '';
		$prompt[] = 'Page URL:';
		$prompt[] = $permalink;
		$prompt[] = '';

		if ( ! empty( $current_title ) || ! empty( $current_description ) ) {
			$prompt[] = 'Current SEO metadata:';
			$prompt[] = 'Current title: ' . $current_title;
			$prompt[] = 'Current description: ' . $current_description;
			$prompt[] = '';
		}

		$prompt[] = 'Extracted page content:';
		$prompt[] = $content;

		return implode( "\n", $prompt );
	}

	public function build_recommendations_prompt( $args ) {
		$post_title            = isset( $args['post_title'] ) ? $args['post_title'] : '';
		$permalink             = isset( $args['permalink'] ) ? $args['permalink'] : '';
		$content               = isset( $args['content'] ) ? $args['content'] : '';
		$current_title         = isset( $args['current_title'] ) ? $args['current_title'] : '';
		$current_description   = isset( $args['current_description'] ) ? $args['current_description'] : '';
		$brand_context         = isset( $args['brand_context'] ) ? $args['brand_context'] : '';
		$global_local_context  = isset( $args['global_local_context'] ) && is_array( $args['global_local_context'] ) ? $args['global_local_context'] : [];
		$page_local_context    = isset( $args['page_local_context'] ) && is_array( $args['page_local_context'] ) ? $args['page_local_context'] : [];
		$gsc_context           = isset( $args['gsc_context'] ) && is_array( $args['gsc_context'] ) ? $args['gsc_context'] : [];
		$content_match_context = isset( $args['content_match_context'] ) && is_array( $args['content_match_context'] ) ? $args['content_match_context'] : [];

		$focus_mode              = isset( $global_local_context['focus_mode'] ) ? $global_local_context['focus_mode'] : 'general';
		$recommendation_guidance = get_option( 'ai_seo_assistant_recommendation_guidance', '' );
		$gsc_guidance            = get_option( 'ai_seo_assistant_gsc_guidance', '' );
		$tone                    = get_option( 'ai_seo_assistant_tone', 'clear, practical, direct, and not overly salesy' );
		$avoid_phrases           = get_option( 'ai_seo_assistant_avoid_phrases', 'discover, explore, learn more, unlock, dive into, welcome to, comprehensive guide, ultimate guide, in today’s digital world' );

		$has_missing_terms = ! empty( $content_match_context['missing_terms'] ) && is_array( $content_match_context['missing_terms'] );
		$has_gsc_data      = ! empty( $gsc_context['has_data'] );

		$prompt = [];

		$prompt[] = 'You are an expert WordPress SEO strategist reviewing a real page for practical improvement.';
		$prompt[] = 'Create page-level SEO recommendations based only on the provided page content, SEO context, client guidance, Search Console data, and content match context.';
		$prompt[] = '';
		$prompt[] = 'Default strategy framework:';
		$prompt[] = '- Business Profile: use supplied brand/site context, priority services, page content, and SEO focus fields as the factual boundary.';
		$prompt[] = '- Do not invent anything outside the Business Profile or page content.';
		$prompt[] = '- Ideal Customer / Audience: use page search intent, page notes, page content, and visible CTA to infer who the page is for.';
		$prompt[] = '- Voice DNA: use the tone setting and banned phrase guidance to keep recommendations practical, specific, and non-generic.';
		$prompt[] = '- Page Goal / Content Intent: infer the likely goal from the page type, CTA, and content. Common goals include enquiry, booking, purchase, donation, signup, education, trust building, product discovery, or support.';
		$prompt[] = '- Real-World Signals: use Search Console data when available. If unavailable, run a page-quality review and do not invent external demand.';
		$prompt[] = '';
		$prompt[] = 'Voice DNA:';
		$prompt[] = '- Default tone: ' . $tone . '.';
		$prompt[] = '- Avoid these phrases and similar generic filler: ' . $avoid_phrases . '.';
		$prompt[] = '- Prefer direct, practical recommendations over broad marketing language.';
		$prompt[] = '- Do not use vague phrases like improve visibility, enhance relevance, boost rankings, tailored solutions, specific needs, unlock potential, comprehensive, seamless, robust, or cutting-edge unless the page itself uses and supports that language.';
		$prompt[] = '';
		$prompt[] = 'Universal review rules:';
		$prompt[] = '- Be specific to the actual page content.';
		$prompt[] = '- Do not give generic SEO checklist advice.';
		$prompt[] = '- Do not invent services, products, offers, discounts, guarantees, pricing, credentials, awards, locations, availability, or claims.';
		$prompt[] = '- Do not recommend invented calls to action unless clearly supported by the page content or client guidance.';
		$prompt[] = '- Do not recommend adding a call to action unless the page currently lacks a clear next step. If a CTA already exists, suggest improving its wording, placement, or connection to the page topic.';
		$prompt[] = '- Focus on practical content, structure, metadata, search intent, conversion clarity, internal linking, and page usefulness.';
		$prompt[] = '- If the page is already strong, return only small practical improvements instead of forcing unnecessary SEO work.';
		$prompt[] = '';

		$prompt[] = 'Content placement rules:';
		$prompt[] = '- If Content match context includes missing terms, return exactly 1 practical content placement suggestion unless the term was previously suggested.';
		$prompt[] = '- The content placement suggestion must be the primary actionable recommendation.';
		$prompt[] = '- Do not create a loop of additional keyword ideas. Do not suggest related terms, secondary terms, agency terms, or new keyword targets.';
		$prompt[] = '- Do not repeat the same missing-term issue across Priority Actions, Content Gaps, Suggested Sections, Local SEO Notes, Internal Linking Suggestions, and Metadata Direction.';
		$prompt[] = '- If "content_insertion_suggestions" contains an item, do not repeat that same missing-term recommendation in "summary", "priority_actions", "content_gaps", or "metadata_direction".';
		$prompt[] = '- In that case, "priority_actions" should focus only on one next practical step, or return an empty array if the content placement suggestion already covers the action.';
		$prompt[] = '- Do not recommend title or meta description changes just because a missing term exists. Only recommend metadata changes if the current metadata is missing, inaccurate, too generic, or clearly mismatched.';
		$prompt[] = '- If a content placement suggestion is returned, keep Priority Actions, Content Gaps, Suggested Sections, and Metadata Direction very short and do not repeat the same issue in different words.';
		$prompt[] = '- Prefer adding one natural sentence to an existing section. Do not recommend a new section unless no existing section fits.';
		$prompt[] = '- The suggested copy must be one sentence only.';
		$prompt[] = '- Admin-facing recommendation text must be written in English.';
		$prompt[] = '- Only the "suggested_copy" field should match the page language, because it may be pasted directly into the page.';
		$prompt[] = '- The "recommended_location" and "reason" fields must be written in English.';
		$prompt[] = '- If the Search Console query is English but the page is German, translate the concept naturally into German instead of forcing the English phrase.';
		$prompt[] = '- For German pages, prefer natural German wording such as "WordPress Anpassungen", "WordPress Entwicklung", "technische WordPress Unterstützung", or "Performance Optimierung" where relevant.';
		$prompt[] = '- Avoid generic wording such as "maßgeschneiderte Lösungen", "spezifische Anforderungen", "tailored solutions", "enhance visibility", "improve relevance", "optimal gestalten", "genau nach Ihren Vorstellungen", "individuelle Lösungen", or "Ihre Website optimal".';
		$prompt[] = '- Match the business voice. If the brand/site context describes an individual, freelancer, or direct consultant, do not call the business an agency and do not use "we/our/unsere/wir" language unless the page clearly uses that voice.';
		$prompt[] = '- Suggested copy should sound like it can be pasted directly into the existing page without sounding like SEO filler.';
		$prompt[] = '- If the missing term is already addressed conceptually in the page, suggest a small wording adjustment instead of broad content expansion.';
		$prompt[] = '- Do not recommend metadata changes purely because of the missing term. Metadata recommendations should only appear if the existing metadata is clearly poor, missing, or mismatched.';
		$prompt[] = '';

		if ( $has_missing_terms ) {
			$prompt[] = 'Missing-term response discipline:';
			$prompt[] = '- Because a missing content-match term exists, avoid broad SEO strategy output.';
			$prompt[] = '- The main output should be one precise Content Placement Suggestion.';
			$prompt[] = '- In priority_actions, include at most 1 short item and do not repeat the suggested copy.';
			$prompt[] = '- In content_gaps, include at most 1 short item or return an empty array.';
			$prompt[] = '- In suggested_sections, return an empty array unless a truly missing section is required.';
			$prompt[] = '- In metadata_direction, only mention metadata if current metadata is missing, too generic, or clearly mismatched.';
			$prompt[] = '';
		}

		$prompt[] = 'Existing-content awareness rules:';
		$prompt[] = '- Before recommending a new section, check whether a similar section already exists in the extracted page content.';
		$prompt[] = '- If something already exists, recommend how to improve, reposition, expand, clarify, or internally link it instead of treating it as missing.';
		$prompt[] = '- Mention existing page sections by name when useful.';
		$prompt[] = '';

		$prompt[] = 'Page quality review rules:';
		$prompt[] = '- Check whether the page clearly explains what is offered, who it is for, and what outcome or benefit the user can expect.';
		$prompt[] = '- Check whether repeated headings, repeated eyebrow labels, or repeated section names create confusion.';
		$prompt[] = '- If the same heading or section label appears more than once for different sections, recommend renaming, consolidating, or clarifying one of them.';
		$prompt[] = '- When recommending a heading/section rename, provide a specific replacement heading and, if useful, a short replacement intro sentence.';
		$prompt[] = '- Check whether nearby sections overlap in purpose. If they do, recommend making each section’s role clearer rather than adding more content.';
		$prompt[] = '- Check whether service cards are too generic and need one concrete example each.';
		$prompt[] = '- Check whether process steps have enough visible detail to be useful.';
		$prompt[] = '- Check whether testimonials or proof points are connected to the page topic.';
		$prompt[] = '- Check whether the CTA matches the page intent.';
		$prompt[] = '- Check whether there are natural internal linking opportunities to related service pages, case studies, contact pages, booking pages, product categories, resources, or relevant supporting content.';
		$prompt[] = '- These rules apply to any website type: service business, ecommerce, nonprofit, blog, portfolio, local business, or informational site.';
		$prompt[] = '';

		$prompt[] = 'Search Console interpretation rules:';
		$prompt[] = '- Use Search Console data to prioritize recommendations, not to invent unsupported content.';
		$prompt[] = '- Treat low-volume Search Console data as a weak signal.';
		$prompt[] = '- If impressions are below 20, do not describe CTR, ranking, or search demand as a major issue.';
		$prompt[] = '- If the page has very low impressions, recommend improving content depth, metadata relevance, internal links, and page clarity before treating CTR as the main issue.';
		$prompt[] = '- If Search Console query terms do not match the visible page focus, call out the mismatch and recommend whether to support the query or stay focused.';
		$prompt[] = '';

		$prompt[] = 'No Search Console data behavior:';
		$prompt[] = '- If this page has no Search Console data, do not assume the page is fully optimized.';
		$prompt[] = '- Run a general page-quality review based on the extracted page content.';
		$prompt[] = '- Look for practical issues such as duplicate headings, repeated section labels, vague section copy, thin service descriptions, unclear process steps, weak internal linking opportunities, unclear CTA alignment, unsupported claims, repeated sections, or metadata that does not match the page focus.';
		$prompt[] = '- Pay special attention to repeated headings, duplicate section labels, thin section intros, and unclear section hierarchy.';
		$prompt[] = '- Recommendations must still be specific to the actual page content.';
		$prompt[] = '- Do not invent search demand, rankings, CTR problems, or keyword opportunities when Search Console data is unavailable.';
		$prompt[] = '- If the page is already strong, return only small editorial or structural improvements rather than forcing unnecessary SEO work.';
		$prompt[] = '';

		if ( ! $has_gsc_data && ! $has_missing_terms ) {
			$prompt[] = 'Recommendation mode for this page:';
			$prompt[] = '- No active content-match issue and no Search Console data were provided.';
			$prompt[] = '- Do not return an empty recommendation set unless the page has no meaningful issues.';
			$prompt[] = '- Provide 1 to 3 practical page-quality recommendations if the content shows clear opportunities.';
			$prompt[] = '- Prioritize clarity, section structure, specificity, internal linking, CTA alignment, and metadata/content alignment.';
			$prompt[] = '- Do not frame these as urgent SEO problems. Frame them as practical improvements.';
			$prompt[] = '';
		}

		if ( 'local' === $focus_mode ) {
			$prompt[] = 'Mode behavior:';
			$prompt[] = '- This site is using Local SEO mode.';
			$prompt[] = '- Review whether the visible page content supports the service and location focus.';
			$prompt[] = '- Local SEO recommendations are appropriate when supported by the page focus.';
		} else {
			$prompt[] = 'Mode behavior:';
			$prompt[] = '- This site is using General / Online mode.';
			$prompt[] = '- Do not force local SEO recommendations unless the page content clearly targets a location.';
			$prompt[] = '- The local_seo_notes array must be empty if local SEO is not relevant.';
		}

		$prompt[] = '';
		$prompt[] = 'Return only valid JSON with exactly these keys:';
		$prompt[] = '"summary", "priority_actions", "content_gaps", "suggested_sections", "local_seo_notes", "internal_linking_suggestions", "content_insertion_suggestions", "metadata_direction".';
		$prompt[] = '';
		$prompt[] = 'JSON value rules:';
		$prompt[] = '- "summary" must be a concise paragraph.';
		$prompt[] = '- "priority_actions" must be an array of specific action items. If no GSC data exists, include 1 to 3 practical page-quality improvements when clearly supported by the content.';
		$prompt[] = '- "content_gaps" must be an array. If a section exists but needs improvement, say that clearly. Do not call something missing if it already exists.';
		$prompt[] = '- "suggested_sections" must be an array. Only suggest genuinely missing sections. Prefer improving existing sections over adding new ones.';
		$prompt[] = '- "local_seo_notes" must be an array. Return an empty array if local SEO is not relevant.';
		$prompt[] = '- "internal_linking_suggestions" must be an array of practical internal linking improvements.';
		$prompt[] = '- "content_insertion_suggestions" must be an array with 0 or 1 object only. The object must include "missing_term", "recommended_location", "suggested_copy", and "reason". The suggested_copy must be one natural sentence only. Return an empty array if no useful one-sentence placement suggestion is needed.';
		$prompt[] = '- "metadata_direction" must be an object with "title_angle" and "description_angle".';
		$prompt[] = '- If current metadata is already relevant and clear, leave metadata_direction title_angle and description_angle empty.';
		$prompt[] = '- Do not provide generic metadata_direction advice. Only return metadata_direction values when the current title or description is missing, too vague, too long, too short, or mismatched with the page content.';
		$prompt[] = '- All explanation fields should be in English except "suggested_copy", which should match the page language.';
		$prompt[] = '- Do not include markdown, explanations, notes, or extra keys.';
		$prompt[] = '';

		$this->append_business_context( $prompt, $brand_context, '', $recommendation_guidance, $gsc_guidance );
		$this->append_focus_context( $prompt, $global_local_context, $page_local_context, $focus_mode );
		$this->append_content_match_context( $prompt, $content_match_context );
		$this->append_gsc_context( $prompt, $gsc_context );

		$prompt[] = 'Page title:';
		$prompt[] = $post_title;
		$prompt[] = '';
		$prompt[] = 'Page URL:';
		$prompt[] = $permalink;
		$prompt[] = '';
		$prompt[] = 'Current SEO metadata:';
		$prompt[] = 'Current title: ' . $current_title;
		$prompt[] = 'Current description: ' . $current_description;
		$prompt[] = '';
		$prompt[] = 'Extracted page content:';
		$prompt[] = $content;

		return implode( "\n", $prompt );
	}

	private function append_business_context( &$prompt, $brand_context, $metadata_guidance = '', $recommendation_guidance = '', $gsc_guidance = '' ) {
		if ( ! empty( $brand_context ) ) {
			$prompt[] = 'Business Profile / Brand Context:';
			$prompt[] = $brand_context;
			$prompt[] = '';
		}

		if ( ! empty( $metadata_guidance ) ) {
			$prompt[] = 'Client-specific metadata guidance:';
			$prompt[] = $metadata_guidance;
			$prompt[] = '';
		}

		if ( ! empty( $recommendation_guidance ) ) {
			$prompt[] = 'Client-specific recommendation guidance:';
			$prompt[] = $recommendation_guidance;
			$prompt[] = '';
		}

		if ( ! empty( $gsc_guidance ) ) {
			$prompt[] = 'Client-specific Search Console guidance:';
			$prompt[] = $gsc_guidance;
			$prompt[] = '';
		}
	}

	private function append_focus_context( &$prompt, $global_local_context, $page_local_context, $focus_mode ) {
		if ( empty( array_filter( $global_local_context ) ) && empty( array_filter( $page_local_context ) ) ) {
			return;
		}

		$prompt[] = 'SEO Focus / Strategic Directives Context:';
		$prompt[] = 'Mode: ' . ( 'local' === $focus_mode ? 'Local SEO' : 'General / Online' );
		$prompt[] = '';

		if ( ! empty( $global_local_context['priority_services'] ) ) {
			$prompt[] = 'Priority services/topics:';
			$prompt[] = $global_local_context['priority_services'];
			$prompt[] = '';
		}

		if ( 'local' === $focus_mode ) {
			if ( ! empty( $global_local_context['primary_locations'] ) ) {
				$prompt[] = 'Primary local SEO locations:';
				$prompt[] = $global_local_context['primary_locations'];
				$prompt[] = '';
			}

			if ( ! empty( $global_local_context['secondary_locations'] ) ) {
				$prompt[] = 'Secondary local SEO locations:';
				$prompt[] = $global_local_context['secondary_locations'];
				$prompt[] = '';
			}
		}

		if ( ! empty( $global_local_context['local_notes'] ) ) {
			$prompt[] = 'SEO strategy notes:';
			$prompt[] = $global_local_context['local_notes'];
			$prompt[] = '';
		}

		if ( ! empty( array_filter( $page_local_context ) ) ) {
			$prompt[] = 'Page-specific SEO focus:';

			if ( ! empty( $page_local_context['service_focus'] ) ) {
				$prompt[] = 'Primary service/topic focus: ' . $page_local_context['service_focus'];
			}

			if ( 'local' === $focus_mode && ! empty( $page_local_context['primary_location'] ) ) {
				$prompt[] = 'Primary location focus: ' . $page_local_context['primary_location'];
			}

			if ( 'local' === $focus_mode && ! empty( $page_local_context['secondary_locations'] ) ) {
				$prompt[] = 'Secondary locations: ' . $page_local_context['secondary_locations'];
			}

			if ( ! empty( $page_local_context['search_intent'] ) ) {
				$prompt[] = 'Ideal Customer / Search intent: ' . $page_local_context['search_intent'];
			}

			if ( ! empty( $page_local_context['priority'] ) ) {
				$prompt[] = 'Page priority: ' . $page_local_context['priority'];
			}

			if ( ! empty( $page_local_context['page_notes'] ) ) {
				$prompt[] = 'Page notes: ' . $page_local_context['page_notes'];
			}

			$prompt[] = '';
		}
	}

	private function append_content_match_context( &$prompt, $content_match_context ) {
		if ( empty( $content_match_context ) || ! is_array( $content_match_context ) ) {
			return;
		}

		$missing_terms    = isset( $content_match_context['missing_terms'] ) && is_array( $content_match_context['missing_terms'] ) ? $content_match_context['missing_terms'] : [];
		$checked_terms    = isset( $content_match_context['checked_terms'] ) && is_array( $content_match_context['checked_terms'] ) ? $content_match_context['checked_terms'] : [];
		$suppressed_terms = isset( $content_match_context['suppressed_terms'] ) && is_array( $content_match_context['suppressed_terms'] ) ? $content_match_context['suppressed_terms'] : [];
		$primary_term     = isset( $content_match_context['primary_term'] ) ? sanitize_text_field( $content_match_context['primary_term'] ) : '';

		if ( empty( $missing_terms ) && empty( $checked_terms ) && empty( $suppressed_terms ) ) {
			return;
		}

		$prompt[] = 'Content match context:';

		if ( ! empty( $primary_term ) ) {
			$prompt[] = 'Primary term/topic being checked:';
			$prompt[] = '- ' . $primary_term;
		}

		if ( ! empty( $checked_terms ) ) {
			$prompt[] = 'Checked focus/search terms:';

			foreach ( array_slice( $checked_terms, 0, 5 ) as $term ) {
				$prompt[] = '- ' . sanitize_text_field( $term );
			}
		}

		if ( ! empty( $missing_terms ) ) {
			$prompt[] = 'Missing terms/topics from visible content:';

			foreach ( array_slice( $missing_terms, 0, 1 ) as $term ) {
				$prompt[] = '- ' . sanitize_text_field( $term );
			}

			$prompt[] = 'Create no more than one content_insertion_suggestions item for the missing term above. Recommend the best existing section or position where one natural sentence can be added.';
		} else {
			$prompt[] = 'No active missing focus terms were detected.';
		}

		if ( ! empty( $suppressed_terms ) ) {
			$prompt[] = 'Previously suggested terms/topics:';

			foreach ( array_slice( $suppressed_terms, 0, 5 ) as $term ) {
				$prompt[] = '- ' . sanitize_text_field( $term );
			}

			$prompt[] = 'Do not create content_insertion_suggestions for previously suggested terms unless new Search Console data changes the opportunity.';
		}

		$prompt[] = '';
	}

	private function append_gsc_context( &$prompt, $gsc_context ) {
		if ( empty( $gsc_context ) || ! is_array( $gsc_context ) ) {
			return;
		}

		$prompt[] = 'Real-World Signals / Google Search Console context:';

		if ( empty( $gsc_context['has_data'] ) ) {
			$prompt[] = '- No Search Console search performance data was found for this page in the synced date range.';
			$prompt[] = '- Do not treat this as an indexing issue.';
			$prompt[] = '';
			return;
		}

		$impressions = isset( $gsc_context['impressions'] ) ? (float) $gsc_context['impressions'] : 0;

		$prompt[] = '- Opportunity label: ' . ( isset( $gsc_context['opportunity'] ) ? $gsc_context['opportunity'] : '' );
		$prompt[] = '- Clicks: ' . ( isset( $gsc_context['clicks'] ) ? $gsc_context['clicks'] : 0 );
		$prompt[] = '- Impressions: ' . $impressions;
		$prompt[] = '- CTR: ' . ( isset( $gsc_context['ctr_percent'] ) ? $gsc_context['ctr_percent'] : 0 ) . '%';
		$prompt[] = '- Average position: ' . ( isset( $gsc_context['position'] ) ? $gsc_context['position'] : 0 );

		if ( $impressions < 20 ) {
			$prompt[] = '- Important: impressions are below 20, so this Search Console data is a weak signal. Do not overstate CTR, ranking, or demand issues.';
		}

		if ( ! empty( $gsc_context['top_queries'] ) && is_array( $gsc_context['top_queries'] ) ) {
			$prompt[] = 'Top queries:';

			foreach ( $gsc_context['top_queries'] as $query ) {
				if ( empty( $query['query'] ) ) {
					continue;
				}

				$prompt[] = sprintf(
					'- %s | clicks: %s | impressions: %s | CTR: %s%% | position: %s',
					$query['query'],
					isset( $query['clicks'] ) ? $query['clicks'] : 0,
					isset( $query['impressions'] ) ? $query['impressions'] : 0,
					isset( $query['ctr'] ) ? round( $query['ctr'] * 100, 1 ) : 0,
					isset( $query['position'] ) ? round( $query['position'], 1 ) : 0
				);
			}
		}

		$prompt[] = '';
	}
}