<?php
/**
 * Coordinates metadata generation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Metadata_Generator {

	private $tsf_adapter;
	private $content_extractor;
	private $prompt_builder;
	private $openai_client;
	private $logger;
	private $local_seo_context;
	private $gsc_client;

	public function __construct( $tsf_adapter, $content_extractor, $prompt_builder, $openai_client, $logger, $local_seo_context, $gsc_client = null ) {
		$this->tsf_adapter       = $tsf_adapter;
		$this->content_extractor = $content_extractor;
		$this->prompt_builder    = $prompt_builder;
		$this->openai_client     = $openai_client;
		$this->logger            = $logger;
		$this->local_seo_context = $local_seo_context;
		$this->gsc_client        = $gsc_client;
	}

	public function generate( $post_id ) {
		$content = $this->content_extractor->get_content( $post_id );

		if ( empty( $content ) ) {
			return new WP_Error(
				'ai_seo_no_content',
				'No usable page content found.'
			);
		}

		$api_key = $this->get_openai_api_key();

		if ( empty( $api_key ) ) {
			$result = $this->generate_placeholder_metadata( $post_id, $content );

			$this->log_generation( $post_id, $result, $content, 'placeholder' );

			return $result;
		}

		$result = $this->generate_ai_metadata( $post_id, $content );

		if ( is_wp_error( $result ) ) {
			$error_message = AI_SEO_Assistant_Utils::mask_sensitive_text( $result->get_error_message() );

			$fallback_result = $this->generate_placeholder_metadata( $post_id, $content );

			$fallback_result['source']       = 'placeholder';
			$fallback_result['model']        = '';
			$fallback_result['warning']      = 'OpenAI unavailable. Placeholder metadata was generated instead.';
			$fallback_result['api_error']    = $error_message;
			$fallback_result['generated_at'] = current_time( 'mysql' );

			$this->log_generation(
				$post_id,
				$fallback_result,
				$content,
				'placeholder',
				''
			);

			return $fallback_result;
		}

		$this->log_generation( $post_id, $result, $content, 'ai' );

		return $result;
	}

	public function generate_and_save( $post_id ) {
		$result = $this->generate( $post_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['error'] ) ) {
			return new WP_Error(
				'ai_seo_generation_error',
				$result['error']
			);
		}

		if ( empty( $result['title'] ) || empty( $result['description'] ) ) {
			return new WP_Error(
				'ai_seo_missing_generated_metadata',
				'Generated metadata was missing a title or description.'
			);
		}

		$this->tsf_adapter->save_title( $post_id, $result['title'] );
		$this->tsf_adapter->save_description( $post_id, $result['description'] );

		$result['saved'] = true;

		return $result;
	}

	public function generate_recommendations( $post_id ) {
		$content = $this->content_extractor->get_content( $post_id );

		if ( empty( $content ) ) {
			return new WP_Error(
				'ai_seo_no_content',
				'No usable page content found.'
			);
		}

		$api_key = $this->get_openai_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'ai_seo_missing_api_key',
				'Missing OpenAI API key.'
			);
		}

		$post_title = wp_strip_all_tags( get_the_title( $post_id ) );
		$permalink  = get_permalink( $post_id );

		$current_title       = $this->tsf_adapter->get_title( $post_id );
		$current_description = $this->tsf_adapter->get_description( $post_id );
		$brand_context       = get_option( 'ai_seo_assistant_brand_context', '' );

		$global_local_context  = $this->local_seo_context->get_global_context();
		$page_local_context    = $this->local_seo_context->get_page_context( $post_id );
		$gsc_context           = $this->get_gsc_recommendation_context( $post_id );
		$content_match_context = $this->get_content_match_context( $post_id, $content, $page_local_context, $gsc_context );

		$content_for_prompt = AI_SEO_Assistant_Utils::trim_to_length( $content, 8000 );

		$prompt = $this->prompt_builder->build_recommendations_prompt(
			[
				'post_title'            => $post_title,
				'permalink'             => $permalink,
				'content'               => $content_for_prompt,
				'current_title'         => $current_title,
				'current_description'   => $current_description,
				'brand_context'         => $brand_context,
				'global_local_context'  => $global_local_context,
				'page_local_context'    => $page_local_context,
				'gsc_context'           => $gsc_context,
				'content_match_context' => $content_match_context,
			]
		);

		$recommendations = $this->openai_client->generate_json( $prompt, 2200 );

		if ( is_wp_error( $recommendations ) ) {
			return $recommendations;
		}

		$defaults = [
			'summary'                       => '',
			'priority_actions'              => [],
			'content_gaps'                  => [],
			'suggested_sections'            => [],
			'local_seo_notes'               => [],
			'internal_linking_suggestions'  => [],
			'content_insertion_suggestions' => [],
			'metadata_direction'            => [
				'title_angle'       => '',
				'description_angle' => '',
			],
		];

		$recommendations = wp_parse_args( $recommendations, $defaults );

		$content_insertion_suggestions = $this->sanitize_content_insertion_suggestions(
			$recommendations['content_insertion_suggestions'],
			$content_match_context,
			$content
		);

		$result = [
			'summary'                       => sanitize_textarea_field( $recommendations['summary'] ),
			'priority_actions'              => $this->sanitize_recommendation_list( $recommendations['priority_actions'] ),
			'content_gaps'                  => $this->sanitize_recommendation_list( $recommendations['content_gaps'] ),
			'suggested_sections'            => $this->sanitize_recommendation_list( $recommendations['suggested_sections'] ),
			'local_seo_notes'               => $this->sanitize_recommendation_list( $recommendations['local_seo_notes'] ),
			'internal_linking_suggestions'  => $this->sanitize_recommendation_list( $recommendations['internal_linking_suggestions'] ),
			'content_insertion_suggestions' => $content_insertion_suggestions,
			'metadata_direction'            => [
				'title_angle'       => isset( $recommendations['metadata_direction']['title_angle'] ) ? sanitize_text_field( $recommendations['metadata_direction']['title_angle'] ) : '',
				'description_angle' => isset( $recommendations['metadata_direction']['description_angle'] ) ? sanitize_textarea_field( $recommendations['metadata_direction']['description_angle'] ) : '',
			],
			'generated_at'                  => current_time( 'mysql' ),
			'gsc_context'                   => $gsc_context,
			'content_match_context'         => $content_match_context,
		];

		$result = $this->clean_recommendations_when_content_insertion_exists( $result );

		$this->mark_content_suggestions_as_seen(
			$post_id,
			$content_match_context,
			$result['content_insertion_suggestions']
		);

		return $result;
	}

	public function suggest_focus_fields( $post_id ) {
		$content = $this->content_extractor->get_content( $post_id );

		if ( empty( $content ) ) {
			return new WP_Error(
				'ai_seo_no_content',
				'No usable page content found.'
			);
		}

		$post_title     = wp_strip_all_tags( get_the_title( $post_id ) );
		$global_context = $this->local_seo_context->get_global_context();
		$page_context   = $this->local_seo_context->get_page_context( $post_id );
		$is_local_mode  = isset( $global_context['focus_mode'] ) && 'local' === $global_context['focus_mode'];
		$gsc_context    = $this->get_gsc_recommendation_context( $post_id );
		$source         = 'page_content';
		$top_query      = '';
		$impressions    = 0;
		$position       = 0;

		if ( ! empty( $gsc_context['has_data'] ) && ! empty( $gsc_context['top_queries'][0]['query'] ) ) {
			$top_query   = sanitize_text_field( $gsc_context['top_queries'][0]['query'] );
			$impressions = isset( $gsc_context['top_queries'][0]['impressions'] ) ? (float) $gsc_context['top_queries'][0]['impressions'] : 0;
			$position    = isset( $gsc_context['top_queries'][0]['position'] ) ? (float) $gsc_context['top_queries'][0]['position'] : 0;
			$source      = 'search_console';
		}

		$known_locations   = $this->get_known_locations_from_context( $global_context );
		$detected_location = '';

		if ( $is_local_mode && ! empty( $known_locations ) ) {
			$location_source   = ! empty( $top_query ) ? $top_query : $content;
			$detected_location = $this->detect_location_from_text( $location_source, $known_locations );
		}

		if ( ! empty( $top_query ) ) {
			$service_focus = $this->normalize_focus_phrase( $top_query );
		} else {
			$service_focus = $this->build_focus_from_content( $post_title, $content, $global_context );
		}

		$search_intent = $this->build_search_intent(
			$service_focus,
			$detected_location,
			$is_local_mode,
			$source
		);

		$priority = $this->suggest_priority( $source, $impressions, $position );

		$page_notes = $this->build_focus_page_notes(
			$source,
			$top_query,
			$service_focus,
			$detected_location,
			$impressions,
			$position
		);

		$suggestions = [
			'service_focus'       => $service_focus,
			'primary_location'    => $detected_location,
			'secondary_locations' => '',
			'search_intent'       => $search_intent,
			'priority'            => $priority,
			'page_notes'          => $page_notes,
			'source'              => $source,
			'top_query'           => $top_query,
			'impressions'         => $impressions,
			'position'            => $position,
		];

		if ( ! empty( $page_context['secondary_locations'] ) ) {
			$suggestions['secondary_locations'] = $page_context['secondary_locations'];
		}

		return array_map(
			function ( $value ) {
				return is_string( $value ) ? sanitize_textarea_field( $value ) : $value;
			},
			$suggestions
		);
	}

	private function generate_ai_metadata( $post_id, $content ) {
		$post_title = wp_strip_all_tags( get_the_title( $post_id ) );
		$permalink  = get_permalink( $post_id );

		$current_title       = $this->tsf_adapter->get_title( $post_id );
		$current_description = $this->tsf_adapter->get_description( $post_id );
		$brand_context       = get_option( 'ai_seo_assistant_brand_context', '' );

		$global_local_context = $this->local_seo_context->get_global_context();
		$page_local_context   = $this->local_seo_context->get_page_context( $post_id );

		$content = AI_SEO_Assistant_Utils::trim_to_length( $content, 6000 );

		$prompt = $this->prompt_builder->build_metadata_prompt(
			[
				'post_title'           => $post_title,
				'permalink'            => $permalink,
				'content'              => $content,
				'current_title'        => $current_title,
				'current_description'  => $current_description,
				'brand_context'        => $brand_context,
				'global_local_context' => $global_local_context,
				'page_local_context'   => $page_local_context,
			]
		);

		$metadata = $this->openai_client->generate_json( $prompt, 500 );

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		if ( empty( $metadata['title'] ) || empty( $metadata['description'] ) ) {
			return new WP_Error(
				'ai_seo_missing_fields',
				'API response did not include title and description.'
			);
		}

		$title       = sanitize_text_field( $metadata['title'] );
		$description = sanitize_textarea_field( $metadata['description'] );

		$title_max       = absint( get_option( 'ai_seo_assistant_title_length', 60 ) );
		$description_max = absint( get_option( 'ai_seo_assistant_description_length', 155 ) );

		$title_max       = $title_max > 0 ? $title_max + 5 : 65;
		$description_max = $description_max > 0 ? $description_max + 10 : 165;

		$title       = AI_SEO_Assistant_Utils::trim_to_length( $title, $title_max );
		$description = AI_SEO_Assistant_Utils::trim_to_length( $description, $description_max );

		return [
			'title'              => $title,
			'description'        => $description,
			'title_status'       => AI_SEO_Assistant_Utils::get_title_status( $title ),
			'description_status' => AI_SEO_Assistant_Utils::get_description_status( $description ),
			'extracted_preview'  => AI_SEO_Assistant_Utils::trim_to_length( $content, 800 ),
			'source'             => 'ai',
			'model'              => get_option( 'ai_seo_assistant_model', 'gpt-4o-mini' ),
			'generated_at'       => current_time( 'mysql' ),
		];
	}

	private function generate_placeholder_metadata( $post_id, $content ) {
		$post_title = wp_strip_all_tags( get_the_title( $post_id ) );

		$content = preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		if ( ! empty( $post_title ) ) {
			$content = preg_replace(
				'/^' . preg_quote( $post_title, '/' ) . '\s*/iu',
				'',
				$content
			);
		}

		$sentences     = preg_split( '/(?<=[.!?])\s+/', $content );
		$best_sentence = '';

		if ( ! empty( $sentences ) && is_array( $sentences ) ) {
			foreach ( $sentences as $sentence ) {
				$sentence = trim( wp_strip_all_tags( $sentence ) );
				$length   = strlen( $sentence );

				if ( $length >= 80 && $length <= 180 ) {
					$best_sentence = $sentence;
					break;
				}
			}
		}

		if ( empty( $best_sentence ) ) {
			$best_sentence = wp_trim_words( $content, 28, '' );
		}

		if ( empty( $best_sentence ) ) {
			$best_sentence = 'Key information and next steps for ' . $post_title . '.';
		}

		$title = $post_title;

		if ( strlen( $title ) < 30 && ! empty( $content ) ) {
			$context_words = wp_trim_words( $content, 5, '' );
			$title         = trim( $title . ' | ' . $context_words );
		}

		$title       = AI_SEO_Assistant_Utils::trim_to_length( $title, 60 );
		$description = AI_SEO_Assistant_Utils::trim_to_length( $best_sentence, 155 );

		return [
			'title'              => $title,
			'description'        => $description,
			'title_status'       => AI_SEO_Assistant_Utils::get_title_status( $title ),
			'description_status' => AI_SEO_Assistant_Utils::get_description_status( $description ),
			'extracted_preview'  => AI_SEO_Assistant_Utils::trim_to_length( $content, 800 ),
			'source'             => 'placeholder',
			'model'              => '',
			'generated_at'       => current_time( 'mysql' ),
		];
	}

	private function get_gsc_recommendation_context( $post_id ) {
		if ( empty( $this->gsc_client ) || ! method_exists( $this->gsc_client, 'get_page_data' ) ) {
			return [];
		}

		$url = get_permalink( $post_id );

		if ( empty( $url ) ) {
			return [];
		}

		$urls_to_try = array_unique(
			[
				$url,
				trailingslashit( $url ),
				untrailingslashit( $url ),
			]
		);

		$data = [];

		foreach ( $urls_to_try as $candidate_url ) {
			$data = $this->gsc_client->get_page_data( $candidate_url );

			if ( ! empty( $data ) ) {
				break;
			}
		}

		if ( empty( $data ) || ! is_array( $data ) ) {
			return [
				'has_data' => false,
				'note'     => 'No Search Console search performance data was found for this page in the synced date range.',
			];
		}

		$clicks      = isset( $data['clicks'] ) ? (float) $data['clicks'] : 0;
		$impressions = isset( $data['impressions'] ) ? (float) $data['impressions'] : 0;
		$ctr         = isset( $data['ctr'] ) ? (float) $data['ctr'] : 0;
		$position    = isset( $data['position'] ) ? (float) $data['position'] : 0;
		$queries     = isset( $data['queries'] ) && is_array( $data['queries'] ) ? $data['queries'] : [];

		$top_queries = [];

		foreach ( array_slice( $queries, 0, 5 ) as $query ) {
			if ( empty( $query['query'] ) ) {
				continue;
			}

			$top_queries[] = [
				'query'       => sanitize_text_field( $query['query'] ),
				'clicks'      => isset( $query['clicks'] ) ? (float) $query['clicks'] : 0,
				'impressions' => isset( $query['impressions'] ) ? (float) $query['impressions'] : 0,
				'ctr'         => isset( $query['ctr'] ) ? (float) $query['ctr'] : 0,
				'position'    => isset( $query['position'] ) ? (float) $query['position'] : 0,
			];
		}

		$opportunity = $this->get_gsc_opportunity_label( $clicks, $impressions, $ctr, $position );

		return [
			'has_data'    => true,
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr_percent' => round( $ctr * 100, 1 ),
			'position'    => round( $position, 1 ),
			'opportunity' => $opportunity['label'],
			'top_queries' => $top_queries,
		];
	}

	private function get_gsc_opportunity_label( $clicks, $impressions, $ctr, $position ) {
		if ( $impressions <= 0 ) {
			return [ 'label' => 'No data', 'tone' => 'neutral' ];
		}

		if ( $impressions < 5 || $position > 30 ) {
			return [ 'label' => 'Low visibility', 'tone' => 'neutral' ];
		}

		if ( $impressions >= 20 && $clicks <= 0 ) {
			return [ 'label' => 'Target first', 'tone' => 'bad' ];
		}

		if ( $position >= 8 && $position <= 20 && $impressions >= 5 ) {
			return [ 'label' => 'Page 2 opportunity', 'tone' => 'warning' ];
		}

		if ( $impressions >= 50 && $ctr < 0.02 ) {
			return [ 'label' => 'Low CTR', 'tone' => 'warning' ];
		}

		if ( $clicks > 0 && $ctr >= 0.03 && $position > 0 && $position <= 10 ) {
			return [ 'label' => 'Doing well', 'tone' => 'good' ];
		}

		if ( $clicks > 0 ) {
			return [ 'label' => 'Monitor', 'tone' => 'neutral' ];
		}

		return [ 'label' => 'Needs review', 'tone' => 'warning' ];
	}

	private function get_content_match_context( $post_id, $content, $page_local_context, $gsc_context ) {
		$content = wp_strip_all_tags( (string) $content );

		$primary_term = '';

		if ( ! empty( $page_local_context['service_focus'] ) ) {
			$primary_term = sanitize_text_field( $page_local_context['service_focus'] );
		} elseif ( ! empty( $gsc_context['top_queries'][0]['query'] ) ) {
			$primary_term = sanitize_text_field( $gsc_context['top_queries'][0]['query'] );
		}

		if ( empty( $primary_term ) ) {
			return [
				'missing_terms'     => [],
				'checked_terms'     => [],
				'suppressed_terms'  => [],
				'primary_term'      => '',
				'suggestion_hashes' => [],
			];
		}

		$checked_terms = [ $primary_term ];
		$missing_terms = [];

		if ( ! $this->content_contains_phrase( $content, $primary_term ) ) {
			$missing_terms[] = $primary_term;
		}

		$suppressed_terms  = [];
		$suggestion_hashes = [];

		foreach ( $missing_terms as $term ) {
			$hash = $this->get_content_suggestion_hash( $term, $gsc_context );

			$suggestion_hashes[ $term ] = $hash;

			if ( $this->has_content_suggestion_been_seen( $post_id, $hash ) ) {
				$suppressed_terms[] = $term;
			}
		}

		$active_missing_terms = array_values(
			array_diff( $missing_terms, $suppressed_terms )
		);

		return [
			'missing_terms'     => $active_missing_terms,
			'checked_terms'     => $checked_terms,
			'suppressed_terms'  => $suppressed_terms,
			'primary_term'      => $primary_term,
			'suggestion_hashes' => $suggestion_hashes,
		];
	}

	private function content_contains_phrase( $content, $phrase ) {
		$content_normalized = strtolower( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $content ) ) );
		$phrase_normalized  = strtolower( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $phrase ) ) );

		if ( '' === $phrase_normalized ) {
			return true;
		}

		if ( false !== stripos( $content_normalized, $phrase_normalized ) ) {
			return true;
		}

		$content_concepts = $this->extract_content_match_concepts( $content_normalized );
		$phrase_concepts  = $this->extract_content_match_concepts( $phrase_normalized );

		if ( empty( $phrase_concepts ) ) {
			return false;
		}

		foreach ( $phrase_concepts as $concept ) {
			if ( ! in_array( $concept, $content_concepts, true ) ) {
				return false;
			}
		}

		return true;
	}

	private function extract_content_match_concepts( $text ) {
		$text = strtolower( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );

		$concepts = [];

		if ( false !== strpos( $text, 'wordpress' ) ) {
			$concepts[] = 'wordpress';
		}

		if (
			false !== strpos( $text, 'customization' ) ||
			false !== strpos( $text, 'customisation' ) ||
			false !== strpos( $text, 'customize' ) ||
			false !== strpos( $text, 'customise' ) ||
			false !== strpos( $text, 'anpassung' ) ||
			false !== strpos( $text, 'anpassungen' ) ||
			false !== strpos( $text, 'anpassen' ) ||
			false !== strpos( $text, 'angepasst' )
		) {
			$concepts[] = 'customization';
		}

		if (
			false !== strpos( $text, 'development' ) ||
			false !== strpos( $text, 'developer' ) ||
			false !== strpos( $text, 'entwicklung' ) ||
			false !== strpos( $text, 'entwickler' )
		) {
			$concepts[] = 'development';
		}

		if (
			false !== strpos( $text, 'seo' ) ||
			false !== strpos( $text, 'suchmaschinenoptimierung' )
		) {
			$concepts[] = 'seo';
		}

		if (
			false !== strpos( $text, 'performance' ) ||
			false !== strpos( $text, 'optimierung' ) ||
			false !== strpos( $text, 'geschwindigkeit' ) ||
			false !== strpos( $text, 'core web vitals' )
		) {
			$concepts[] = 'performance';
		}

		if ( false !== strpos( $text, 'freiburg' ) ) {
			$concepts[] = 'freiburg';
		}

		if ( false !== strpos( $text, 'basel' ) ) {
			$concepts[] = 'basel';
		}

		if ( false !== strpos( $text, 'mulhouse' ) ) {
			$concepts[] = 'mulhouse';
		}

		return array_values( array_unique( $concepts ) );
	}

	private function get_content_suggestion_hash( $term, $gsc_context ) {
		$term = strtolower( trim( sanitize_text_field( (string) $term ) ) );

		$top_query   = '';
		$impressions = '';
		$position    = '';

		if ( ! empty( $gsc_context['top_queries'][0]['query'] ) ) {
			$top_query = strtolower( sanitize_text_field( $gsc_context['top_queries'][0]['query'] ) );
		}

		if ( isset( $gsc_context['top_queries'][0]['impressions'] ) ) {
			$impressions = (string) round( (float) $gsc_context['top_queries'][0]['impressions'] );
		}

		if ( isset( $gsc_context['top_queries'][0]['position'] ) ) {
			$position = (string) round( (float) $gsc_context['top_queries'][0]['position'], 1 );
		}

		return md5(
			wp_json_encode(
				[
					'term'        => $term,
					'top_query'   => $top_query,
					'impressions' => $impressions,
					'position'    => $position,
				]
			)
		);
	}

	private function has_content_suggestion_been_seen( $post_id, $hash ) {
		$seen_hashes = get_post_meta( $post_id, '_ai_seo_seen_content_suggestion_hashes', true );

		if ( ! is_array( $seen_hashes ) ) {
			$seen_hashes = [];
		}

		return in_array( $hash, $seen_hashes, true );
	}

	private function mark_content_suggestions_as_seen( $post_id, $content_match_context, $content_insertion_suggestions ) {
		if ( empty( $content_insertion_suggestions ) || empty( $content_match_context['suggestion_hashes'] ) ) {
			return;
		}

		$seen_hashes = get_post_meta( $post_id, '_ai_seo_seen_content_suggestion_hashes', true );

		if ( ! is_array( $seen_hashes ) ) {
			$seen_hashes = [];
		}

		foreach ( $content_insertion_suggestions as $suggestion ) {
			if ( empty( $suggestion['missing_term'] ) ) {
				continue;
			}

			$term = $suggestion['missing_term'];

			if ( ! empty( $content_match_context['suggestion_hashes'][ $term ] ) ) {
				$seen_hashes[] = $content_match_context['suggestion_hashes'][ $term ];
			}
		}

		$seen_hashes = array_values( array_unique( $seen_hashes ) );

		update_post_meta( $post_id, '_ai_seo_seen_content_suggestion_hashes', $seen_hashes );
	}

	private function clean_recommendations_when_content_insertion_exists( $result ) {
		if ( empty( $result['content_insertion_suggestions'] ) || ! is_array( $result['content_insertion_suggestions'] ) ) {
			return $result;
		}

		$suggestion   = $result['content_insertion_suggestions'][0];
		$missing_term = isset( $suggestion['missing_term'] ) ? sanitize_text_field( $suggestion['missing_term'] ) : '';

		if ( empty( $missing_term ) ) {
			return $result;
		}

		$result['priority_actions']  = [];
		$result['content_gaps']      = [];
		$result['suggested_sections'] = [];

		$result['local_seo_notes'] = $this->remove_repeated_missing_term_items(
			isset( $result['local_seo_notes'] ) ? $result['local_seo_notes'] : [],
			$missing_term
		);

		$result['internal_linking_suggestions'] = $this->remove_repeated_missing_term_items(
			isset( $result['internal_linking_suggestions'] ) ? $result['internal_linking_suggestions'] : [],
			$missing_term
		);

		$result['metadata_direction'] = [
			'title_angle'       => '',
			'description_angle' => '',
		];

		$result['summary'] = 'A specific content placement suggestion was found for the primary missing term. Add the suggested sentence to the recommended section, then rescan the page after updating the content.';

		return $result;
	}

	private function remove_repeated_missing_term_items( $items, $missing_term ) {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$missing_term_normalized = strtolower( trim( $missing_term ) );

		return array_values(
			array_filter(
				$items,
				function ( $item ) use ( $missing_term_normalized ) {
					$item_normalized = strtolower( trim( wp_strip_all_tags( (string) $item ) ) );

					if ( '' === $item_normalized ) {
						return false;
					}

					return false === strpos( $item_normalized, $missing_term_normalized );
				}
			)
		);
	}

	private function get_known_locations_from_context( $global_context ) {
		$locations = [];

		$fields = [ 'primary_locations', 'secondary_locations' ];

		foreach ( $fields as $field ) {
			if ( empty( $global_context[ $field ] ) ) {
				continue;
			}

			$parts = preg_split( '/[\r\n,]+/', (string) $global_context[ $field ] );

			foreach ( $parts as $part ) {
				$part = trim( sanitize_text_field( $part ) );

				if ( '' !== $part ) {
					$locations[] = $part;
				}
			}
		}

		$locations = array_values( array_unique( $locations ) );

		usort(
			$locations,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $locations;
	}

	private function detect_location_from_text( $text, $locations ) {
		$text = strtolower( wp_strip_all_tags( (string) $text ) );

		foreach ( $locations as $location ) {
			$location_normalized = strtolower( (string) $location );

			if ( '' !== $location_normalized && false !== stripos( $text, $location_normalized ) ) {
				return $location;
			}
		}

		return '';
	}

	private function normalize_focus_phrase( $phrase ) {
		$phrase = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $phrase ) ) );

		if ( '' === $phrase ) {
			return '';
		}

		return ucwords( strtolower( $phrase ) );
	}

	private function build_focus_from_content( $post_title, $content, $global_context ) {
		$post_title = trim( wp_strip_all_tags( (string) $post_title ) );

		if ( '' !== $post_title ) {
			return $post_title;
		}

		if ( ! empty( $global_context['priority_services'] ) ) {
			$services = preg_split( '/[\r\n,]+/', (string) $global_context['priority_services'] );

			if ( ! empty( $services[0] ) ) {
				return sanitize_text_field( trim( $services[0] ) );
			}
		}

		$content_words = wp_trim_words( wp_strip_all_tags( (string) $content ), 6, '' );

		return sanitize_text_field( $content_words );
	}

	private function build_search_intent( $service_focus, $location, $is_local_mode, $source ) {
		$service_focus = trim( (string) $service_focus );

		if ( '' === $service_focus ) {
			return '';
		}

		$service_lower = strtolower( $service_focus );

		if ( $is_local_mode && ! empty( $location ) ) {
			return 'Users looking for ' . $service_lower . ' in ' . $location . '.';
		}

		if ( 'search_console' === $source ) {
			return 'Users searching for information, services, or solutions related to ' . $service_lower . '.';
		}

		return 'Users looking for information or services related to ' . $service_lower . '.';
	}

	private function suggest_priority( $source, $impressions, $position ) {
		$impressions = (float) $impressions;
		$position    = (float) $position;

		if ( 'search_console' !== $source ) {
			return 'medium';
		}

		if ( $impressions >= 50 || ( $impressions >= 10 && $position > 0 && $position <= 20 ) ) {
			return 'high';
		}

		if ( $impressions >= 10 ) {
			return 'medium';
		}

		if ( $impressions >= 3 && $position >= 20 ) {
			return 'medium';
		}

		return 'low';
	}

	private function build_focus_page_notes( $source, $top_query, $service_focus, $location, $impressions, $position ) {
		$top_query     = sanitize_text_field( (string) $top_query );
		$service_focus = sanitize_text_field( (string) $service_focus );
		$location      = sanitize_text_field( (string) $location );
		$impressions   = (float) $impressions;
		$position      = (float) $position;

		if ( 'search_console' === $source && ! empty( $top_query ) ) {
			$note = 'Search Console shows this page is appearing for "' . $top_query . '".';

			if ( $impressions > 0 ) {
				$note .= ' The query has ' . round( $impressions ) . ' impressions';
			}

			if ( $position > 0 ) {
				$note .= ' with an average position of ' . round( $position, 1 );
			}

			if ( $impressions > 0 || $position > 0 ) {
				$note .= '.';
			}

			$note .= ' Review whether the page should better support this query through visible content, headings, metadata, and internal links.';

			return $note;
		}

		$note = 'Suggested from the page title and visible page content.';

		if ( ! empty( $service_focus ) ) {
			$note .= ' Review whether this page clearly supports "' . $service_focus . '".';
		}

		if ( ! empty( $location ) ) {
			$note .= ' Location detected: ' . $location . '.';
		}

		return $note;
	}

	private function sanitize_recommendation_list( $items ) {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$clean = [];

		foreach ( $items as $item ) {
			$item = sanitize_textarea_field( $item );

			if ( '' !== trim( $item ) ) {
				$clean[] = $item;
			}
		}

		return $clean;
	}

	private function sanitize_content_insertion_suggestions( $items, $content_match_context = [], $content = '' ) {
		if ( empty( $content_match_context['missing_terms'] ) || ! is_array( $content_match_context['missing_terms'] ) ) {
			return [];
		}

		if ( ! is_array( $items ) ) {
			$items = [];
		}

		$clean = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$missing_term = isset( $item['missing_term'] ) ? sanitize_text_field( $item['missing_term'] ) : '';
			$location     = isset( $item['recommended_location'] ) ? sanitize_text_field( $item['recommended_location'] ) : '';
			$copy         = isset( $item['suggested_copy'] ) ? sanitize_textarea_field( $item['suggested_copy'] ) : '';
			$reason       = isset( $item['reason'] ) ? sanitize_textarea_field( $item['reason'] ) : '';

			if ( '' === $missing_term && ! empty( $content_match_context['missing_terms'][0] ) ) {
				$missing_term = sanitize_text_field( $content_match_context['missing_terms'][0] );
			}

			if ( '' === $missing_term ) {
				continue;
			}

			if ( '' === $location ) {
				$location = $this->get_default_content_placement_location( $content );
			}

			if ( $this->is_bad_content_insertion_copy( $copy ) ) {
				$copy = $this->build_safe_content_insertion_copy( $missing_term, $content );
			}

			if ( '' === $reason || $this->is_bad_content_insertion_copy( $reason ) ) {
				$reason = 'This adds the missing topic in a natural way without forcing the exact search query into the page.';
			}

			$clean[] = [
				'missing_term'         => $missing_term,
				'recommended_location' => $location,
				'suggested_copy'       => $copy,
				'reason'               => $reason,
			];
		}

		if ( empty( $clean ) && ! empty( $content_match_context['missing_terms'][0] ) ) {
			$missing_term = sanitize_text_field( $content_match_context['missing_terms'][0] );

			$clean[] = [
				'missing_term'         => $missing_term,
				'recommended_location' => $this->get_default_content_placement_location( $content ),
				'suggested_copy'       => $this->build_safe_content_insertion_copy( $missing_term, $content ),
				'reason'               => 'This adds the missing topic in a natural way without forcing the exact search query into the page.',
			];
		}

		return array_slice( $clean, 0, 1 );
	}

	private function is_bad_content_insertion_copy( $copy ) {
		$copy = strtolower( trim( wp_strip_all_tags( (string) $copy ) ) );

		if ( '' === $copy ) {
			return true;
		}

		$banned_fragments = [
			'unsere dienstleistungen',
			'maßgeschneiderte lösungen',
			'massgeschneiderte lösungen',
			'individuelle lösungen',
			'spezifische anforderungen',
			'speziell auf ihre bedürfnisse zugeschnitten',
			'auf ihre bedürfnisse zugeschnitten',
			'optimal zu gestalten',
			'optimal gestalten',
			'genau nach ihren vorstellungen',
			'ihre website optimal',
			'enhance visibility',
			'improve relevance',
			'tailored solutions',
			'optimize your website',
			'boost your',
			'unlock',
			'discover',
		];

		foreach ( $banned_fragments as $fragment ) {
			if ( false !== strpos( $copy, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_default_content_placement_location( $content ) {
		$content = strtolower( wp_strip_all_tags( (string) $content ) );

		if ( false !== strpos( $content, 'wie ich helfen kann' ) ) {
			return 'In the "Wie ich helfen kann" section, where WordPress support or development is already mentioned.';
		}

		if ( false !== strpos( $content, 'why work with me' ) || false !== strpos( $content, 'warum mit mir arbeiten' ) ) {
			return 'In the "Warum mit mir arbeiten?" section, after the first sentence.';
		}

		return 'In the introduction section, after the first sentence.';
	}

	private function build_safe_content_insertion_copy( $missing_term, $content ) {
		$missing_term = trim( sanitize_text_field( (string) $missing_term ) );
		$content      = wp_strip_all_tags( (string) $content );

		if ( $this->is_likely_german_content( $content ) ) {
			return $this->build_safe_german_content_insertion_copy( $missing_term );
		}

		return $this->build_safe_english_content_insertion_copy( $missing_term );
	}

	private function is_likely_german_content( $content ) {
		$content = strtolower( wp_strip_all_tags( (string) $content ) );

		$signals = [
			'ich ',
			' für ',
			' und ',
			' mit ',
			'unternehmen',
			'freiburg',
			'leistungen',
			'arbeiten',
			'betreuung',
			'entwicklung',
			'anpassung',
			'optimierung',
		];

		$matches = 0;

		foreach ( $signals as $signal ) {
			if ( false !== strpos( $content, $signal ) ) {
				$matches++;
			}
		}

		return $matches >= 3;
	}

	private function build_safe_german_content_insertion_copy( $missing_term ) {
		$term = strtolower( $missing_term );

		$location = '';

		if ( false !== strpos( $term, 'freiburg' ) ) {
			$location = ' in Freiburg';
		}

		if ( false !== strpos( $term, 'wordpress' ) && ( false !== strpos( $term, 'customization' ) || false !== strpos( $term, 'anpass' ) ) ) {
			return 'Ich unterstütze Unternehmen' . $location . ' mit individuellen WordPress Anpassungen, technischer WordPress Entwicklung, SEO Analyse und Performance Optimierung für bestehende Websites.';
		}

		if ( false !== strpos( $term, 'wordpress' ) ) {
			return 'Ich unterstütze Unternehmen' . $location . ' mit technischer WordPress Unterstützung, klarer Umsetzung und praktischen Verbesserungen für bestehende Websites.';
		}

		return 'Ich unterstütze Unternehmen' . $location . ' mit klarer technischer Umsetzung, SEO Analyse und praktischen Verbesserungen für bestehende Websites.';
	}

	private function build_safe_english_content_insertion_copy( $missing_term ) {
		$term = strtolower( $missing_term );

		$location = '';

		if ( false !== strpos( $term, 'freiburg' ) ) {
			$location = ' in Freiburg';
		}

		if ( false !== strpos( $term, 'wordpress' ) && false !== strpos( $term, 'customization' ) ) {
			return 'I help businesses' . $location . ' with practical WordPress customization, technical improvements, SEO analysis, and performance optimization for existing websites.';
		}

		if ( false !== strpos( $term, 'wordpress' ) ) {
			return 'I help businesses' . $location . ' with practical WordPress support, technical improvements, SEO analysis, and performance optimization for existing websites.';
		}

		return 'I help businesses' . $location . ' with practical technical improvements, SEO analysis, and clearer website performance.';
	}

	private function get_openai_api_key() {
		if ( defined( 'AI_SEO_ASSISTANT_OPENAI_API_KEY' ) && AI_SEO_ASSISTANT_OPENAI_API_KEY ) {
			return trim( (string) AI_SEO_ASSISTANT_OPENAI_API_KEY );
		}

		return trim( (string) get_option( 'ai_seo_assistant_api_key', '' ) );
	}

	private function log_generation( $post_id, $result, $content, $source, $error = '' ) {
		$this->logger->add_log(
			$post_id,
			[
				'timestamp'       => time(),
				'source'          => $source,
				'model'           => isset( $result['model'] ) ? $result['model'] : '',
				'old_title'       => $this->tsf_adapter->get_title( $post_id ),
				'old_description' => $this->tsf_adapter->get_description( $post_id ),
				'new_title'       => isset( $result['title'] ) ? $result['title'] : '',
				'new_description' => isset( $result['description'] ) ? $result['description'] : '',
				'content_preview' => AI_SEO_Assistant_Utils::trim_to_length( $content, 500 ),
				'error'           => AI_SEO_Assistant_Utils::mask_sensitive_text( $error ),
			]
		);
	}
}