<?php
/**
 * The WordPress basics most 5.0 unit tests need: translation passthrough, real escaping, number and date
 * formatting. A trait, so each test still declares every other WP function it relies on.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit;

/**
 * Shared WP_Mock setup.
 */
trait Wp_Basics {

	/**
	 * Mock translation, escaping and formatting.
	 */
	protected function wp_basics(): void {
		$esc = static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( '_x' )->andReturnArg( 0 );
		\WP_Mock::userFunction( '_n' )->andReturnUsing( fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'esc_attr__' )->andReturnUsing( $esc );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturnUsing( fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
		\WP_Mock::userFunction( 'wp_date' )->andReturnUsing(
			fn( $format, $time = null, $tz = null ) => ( new \DateTimeImmutable( '@' . ( $time ?? time() ) ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		\WP_Mock::userFunction( 'trailingslashit' )->andReturnUsing( fn( $s ) => rtrim( (string) $s, '/' ) . '/' );
		\WP_Mock::userFunction( 'untrailingslashit' )->andReturnUsing( fn( $s ) => rtrim( (string) $s, '/' ) );
	}
}
