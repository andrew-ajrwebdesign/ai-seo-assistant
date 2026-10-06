<?php
/**
 * PHPUnit bootstrap — Composer autoloader + WP_Mock.
 *
 * Unit tests, not WordPress integration tests: no WordPress is loaded and no database exists.
 * Every WP function a class under test calls must be declared through WP_Mock, so an untested
 * call shows up as an error instead of silently working against a real install.
 *
 * Run with: composer test
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

$aisa_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $aisa_autoload ) ) {
	fwrite( STDERR, "Run `composer install` before the test suite.\n" );
	exit( 1 );
}

require_once $aisa_autoload;

WP_Mock::bootstrap();

require_once __DIR__ . '/Unit/wp-class-stubs.php';
require_once __DIR__ . '/wp-admin/includes/upgrade.php'; // dbDelta() stand-in for Core\Schema::install().

// WordPress's time constants (wp-includes/default-constants.php): values, not behaviour.
foreach ( [ 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800 ] as $aisa_name => $aisa_value ) {
	if ( ! defined( $aisa_name ) ) {
		define( $aisa_name, $aisa_value );
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
unset( $aisa_autoload, $aisa_name, $aisa_value );
