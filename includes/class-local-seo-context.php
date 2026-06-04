<?php
/**
 * Handles global and per-page SEO focus context.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Local_SEO_Context {

	const MODE_GENERAL = 'general';
	const MODE_LOCAL   = 'local';

	const META_SERVICE_FOCUS       = '_ai_seo_assistant_service_focus';
	const META_PRIMARY_LOCATION    = '_ai_seo_assistant_primary_location';
	const META_SECONDARY_LOCATIONS = '_ai_seo_assistant_secondary_locations';
	const META_SEARCH_INTENT       = '_ai_seo_assistant_search_intent';
	const META_PRIORITY            = '_ai_seo_assistant_priority';
	const META_PAGE_NOTES          = '_ai_seo_assistant_page_notes';

	public function get_focus_mode() {
		$mode = get_option( 'ai_seo_assistant_focus_mode', self::MODE_GENERAL );

		if ( ! in_array( $mode, [ self::MODE_GENERAL, self::MODE_LOCAL ], true ) ) {
			$mode = self::MODE_GENERAL;
		}

		return $mode;
	}

	public function is_local_mode() {
		return self::MODE_LOCAL === $this->get_focus_mode();
	}

	public function is_general_mode() {
		return self::MODE_GENERAL === $this->get_focus_mode();
	}

	public function get_global_context() {
		$context = [
			'focus_mode'        => $this->get_focus_mode(),
			'priority_services' => get_option( 'ai_seo_assistant_priority_services', '' ),
			'local_notes'       => get_option( 'ai_seo_assistant_local_notes', '' ),
		];

		if ( $this->is_local_mode() ) {
			$context['primary_locations']   = get_option( 'ai_seo_assistant_primary_locations', '' );
			$context['secondary_locations'] = get_option( 'ai_seo_assistant_secondary_locations', '' );
		} else {
			$context['primary_locations']   = '';
			$context['secondary_locations'] = '';
		}

		return $context;
	}

	public function get_page_context( $post_id ) {
		$context = [
			'focus_mode'          => $this->get_focus_mode(),
			'service_focus'       => get_post_meta( $post_id, self::META_SERVICE_FOCUS, true ),
			'primary_location'    => '',
			'secondary_locations' => '',
			'search_intent'       => get_post_meta( $post_id, self::META_SEARCH_INTENT, true ),
			'priority'            => get_post_meta( $post_id, self::META_PRIORITY, true ),
			'page_notes'          => get_post_meta( $post_id, self::META_PAGE_NOTES, true ),
		];

		if ( $this->is_local_mode() ) {
			$context['primary_location']    = get_post_meta( $post_id, self::META_PRIMARY_LOCATION, true );
			$context['secondary_locations'] = get_post_meta( $post_id, self::META_SECONDARY_LOCATIONS, true );
		}

		return $context;
	}

	public function save_page_context( $post_id, $data ) {
		$fields = [
			self::META_SERVICE_FOCUS => 'ai_seo_service_focus',
			self::META_SEARCH_INTENT => 'ai_seo_search_intent',
			self::META_PRIORITY      => 'ai_seo_priority',
			self::META_PAGE_NOTES    => 'ai_seo_page_notes',
		];

		if ( $this->is_local_mode() ) {
			$fields[ self::META_PRIMARY_LOCATION ]    = 'ai_seo_primary_location';
			$fields[ self::META_SECONDARY_LOCATIONS ] = 'ai_seo_secondary_locations';
		}

		foreach ( $fields as $meta_key => $field_name ) {
			if ( ! isset( $data[ $field_name ] ) ) {
				continue;
			}

			update_post_meta(
				$post_id,
				$meta_key,
				sanitize_textarea_field( wp_unslash( $data[ $field_name ] ) )
			);
		}
	}

	public function has_any_context( $post_id ) {
		$page_context   = $this->get_page_context( $post_id );
		$global_context = $this->get_global_context();

		foreach ( array_merge( $page_context, $global_context ) as $value ) {
			if ( ! empty( trim( (string) $value ) ) ) {
				return true;
			}
		}

		return false;
	}
}