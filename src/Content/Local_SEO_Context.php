<?php
/**
 * Handles global and per-page SEO focus context.
 */

namespace AJR\SEOAssistant\Content;

defined( 'ABSPATH' ) || exit;

class Local_SEO_Context {

	const MODE_GENERAL = 'general';
	const MODE_LOCAL   = 'local';

	const META_SERVICE_FOCUS       = '_ai_seo_assistant_service_focus';
	const META_PRIMARY_LOCATION    = '_ai_seo_assistant_primary_location';
	const META_SECONDARY_LOCATIONS = '_ai_seo_assistant_secondary_locations';
	const META_SEARCH_INTENT       = '_ai_seo_assistant_search_intent';
	const META_PRIORITY            = '_ai_seo_assistant_priority';
	const META_PAGE_NOTES          = '_ai_seo_assistant_page_notes';

	public function get_focus_mode() {
		$mode = get_option( 'ai_seo_assistant_focus_mode', '' );

		// Never chosen here: a local business type in AJR Core's business profile means local mode.
		if ( '' === $mode || false === $mode ) {
			$mode = self::core_is_local_business() ? self::MODE_LOCAL : self::MODE_GENERAL;
		}

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
			'priority_services' => self::own_or_core( 'ai_seo_assistant_priority_services', [ self::class, 'core_services' ] ),
			'local_notes'       => get_option( 'ai_seo_assistant_local_notes', '' ),
		];

		if ( $this->is_local_mode() ) {
			$context['primary_locations']   = self::own_or_core( 'ai_seo_assistant_primary_locations', [ self::class, 'core_locations' ] );
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

	/*
	 * ---- Business facts from AJR Core (4.4.0) ----
	 *
	 * The business's services, the places it serves and whether it is a local business are already in
	 * AJR Core's business profile on every stack site; asking for them again here meant they were either
	 * typed twice or, usually, left empty, and the prompts went without them. So when this plugin's own
	 * setting is EMPTY and AJR Core is active, the prompt context reads AJR Core's value. Read only: AJR
	 * Core's values are never written into this plugin's options, so editing either screen keeps working
	 * and this plugin's own value always wins once it is filled in.
	 */

	/**
	 * This plugin's option, or AJR Core's fact when the option is empty.
	 *
	 * @param string   $option   Option name.
	 * @param callable $fallback Returns AJR Core's value ('' without AJR Core).
	 * @return string
	 */
	protected static function own_or_core( $option, callable $fallback ) {
		$own = trim( (string) get_option( $option, '' ) );

		return '' !== $own ? $own : (string) call_user_func( $fallback );
	}

	/**
	 * An AJR Core business-profile value, or '' when AJR Core is not active (or fails).
	 *
	 * @param string $key Dot-notated AJR Core Config key.
	 * @return string
	 */
	protected static function core_value( $key ) {
		$config = 'AJR\Core\Framework\Config';
		if ( ! class_exists( $config ) || ! method_exists( $config, 'get' ) ) {
			return '';
		}
		try {
			$value = $config::get( $key, '' );
		} catch ( \Throwable $e ) {
			return ''; // A newer or broken AJR Core must never stop metadata generation.
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Whether AJR Core's business type is a local business (schema.org LocalBusiness or a subtype).
	 *
	 * @return bool
	 */
	protected static function core_is_local_business() {
		$type    = self::core_value( 'schema.type' );
		$profile = 'AJR\Core\Schema\Business_Profile';
		if ( '' === $type || ! class_exists( $profile ) || ! method_exists( $profile, 'is_local_type' ) ) {
			return false;
		}

		return (bool) $profile::is_local_type( $type );
	}

	/**
	 * AJR Core's services (names only, one per line) and topics, for "priority services".
	 *
	 * @return string
	 */
	public static function core_services() {
		$lines = [];
		foreach ( preg_split( '/\R/', self::core_value( 'business.services' ) . "\n" . self::core_value( 'business.knows_about' ) ) as $line ) {
			$name = trim( explode( '|', (string) $line, 2 )[0] ); // "Name | description" → Name.
			if ( '' !== $name ) {
				$lines[] = $name;
			}
		}

		return implode( "\n", array_values( array_unique( $lines ) ) );
	}

	/**
	 * AJR Core's areas served (without their "City:" style labels), else its town and region.
	 *
	 * @return string
	 */
	public static function core_locations() {
		$lines = [];
		foreach ( preg_split( '/\R/', self::core_value( 'business.area_served' ) ) as $line ) {
			$place = trim( (string) preg_replace( '/^(City|State|Region|Country|Place)\s*:\s*/i', '', (string) $line ) );
			if ( '' !== $place ) {
				$lines[] = $place;
			}
		}
		if ( [] === $lines ) {
			$town = implode( ', ', array_filter( [ self::core_value( 'business.locality' ), self::core_value( 'business.region' ) ] ) );
			if ( '' !== $town ) {
				$lines[] = $town;
			}
		}

		return implode( "\n", $lines );
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
