<?php
/**
 * Business — the business facts Claude may use, read from AJR Core.
 *
 * 5.0 requires AJR Core, whose business profile (Config `business.*`) already holds the name, phone, area
 * served and services on every stack site. This plugin keeps only the agency's tone and house rules
 * (`ai_seo_assistant_brand_context`); every other fact is read from AJR Core at the moment it is used and
 * never copied, so editing it in AJR Core is the one place it changes.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Business facts for prompts and the Settings screen.
 */
class Business {

	/** Option: the agency's tone and house rules (the only brand field this plugin stores). */
	public const TONE_OPTION = 'ai_seo_assistant_brand_context';

	/**
	 * The facts, each with its value ('' when AJR Core has none).
	 *
	 * @return array<string,string> name, area, phone, services, description, booking.
	 */
	public static function facts(): array {
		$name = self::core( 'business.name' );

		return [
			'name'        => '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'area'        => self::list_line( Local_SEO_Context::core_locations() ),
			'phone'       => self::core( 'business.phone' ),
			'services'    => self::list_line( Local_SEO_Context::core_services() ),
			'description' => self::core( 'business.description' ),
			'booking'     => self::core( 'business.booking_url' ),
		];
	}

	/**
	 * The agency's tone and house rules.
	 */
	public static function tone(): string {
		return trim( (string) get_option( self::TONE_OPTION, '' ) );
	}

	/**
	 * The facts as prompt lines ("Business name: …"), empty ones left out.
	 *
	 * @return array<int,string>
	 */
	public static function prompt_lines(): array {
		$labels = [
			'name'        => 'Business name',
			'area'        => 'Area served',
			'phone'       => 'Phone',
			'services'    => 'Services',
			'description' => 'About the business',
			'booking'     => 'Booking page',
		];
		$lines  = [];
		foreach ( self::facts() as $key => $value ) {
			if ( '' !== $value ) {
				$lines[] = $labels[ $key ] . ': ' . $value;
			}
		}

		return $lines;
	}

	/**
	 * Whether AJR Core is active (so the Settings screen can say where facts come from).
	 */
	public static function core_active(): bool {
		return class_exists( 'AJR\Core\Framework\Config' );
	}

	/**
	 * One AJR Core Config value, '' without AJR Core.
	 *
	 * @param string $key Config key.
	 */
	protected static function core( string $key ): string {
		$config = 'AJR\Core\Framework\Config';
		if ( ! class_exists( $config ) ) {
			return '';
		}
		try {
			$value = $config::get( $key, '' );
		} catch ( \Throwable $e ) {
			return '';
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A one-per-line list as one comma-separated line.
	 *
	 * @param string $list List.
	 */
	protected static function list_line( string $list ): string {
		return implode( ', ', array_filter( array_map( 'trim', preg_split( '/\R/', $list ) ) ) );
	}
}
