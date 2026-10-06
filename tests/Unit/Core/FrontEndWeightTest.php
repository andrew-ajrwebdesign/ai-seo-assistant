<?php
/**
 * Guard for the front-end weight rule (4.4.0, kept in 5.0): a visitor's request loads only the classes that
 * act there, and none of the SEO scan, page review, Search Console, Changes or admin classes.
 *
 * Until 4.3.2 every page view built ~20 admin classes (323 KB of PHP on the first real site measured).
 * This boots Plugin::init() as a front-end request in a fresh process and fails if any class outside the
 * allow-list was loaded, so a tool class wired back into the front-end path (directly, or through a class
 * constant that names it) shows up here instead of in a performance review. 5.0's scan hooks are
 * registered as closures, so they must add nothing to this list.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Core;

use AJR\SEOAssistant\Core\Plugin;
use WP_Mock\Tools\TestCase;

/**
 * Which plugin classes a front-end request loads.
 */
class FrontEndWeightTest extends TestCase {

	/**
	 * The plugin's own classes loaded so far.
	 *
	 * @return array<int,string>
	 */
	protected static function loaded(): array {
		$mine = array_filter( get_declared_classes(), static fn( $c ) => 0 === strpos( $c, 'AJR\SEOAssistant\\' ) && false === strpos( $c, '\Tests\\' ) );
		sort( $mine );

		return array_values( $mine );
	}

	/**
	 * A front-end request loads the allow-listed classes and nothing else; the scan's hooks are in place.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_front_end_loads_only_what_acts_there(): void {
		$allowed = [
			'AJR\SEOAssistant\Core\Plugin',
			'AJR\SEOAssistant\Core\Secret_Guard', // The secret-option write filters: small, and they must run everywhere.
			'AJR\SEOAssistant\Report\Access',
			'AJR\SEOAssistant\Report\Push_Endpoint',
			'AJR\SEOAssistant\Report\Snapshot_Store',
			'AJR\SEOAssistant\Report\Stale_Alert',
		];
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => $d );
		\WP_Mock::expectActionAdded( 'transition_post_status', \WP_Mock\Functions::type( 'callable' ), 10, 3 );
		\WP_Mock::expectActionAdded( 'aisa_scan_run', \WP_Mock\Functions::type( 'callable' ) );
		\WP_Mock::expectActionAdded( 'aisa_scan_post', \WP_Mock\Functions::type( 'callable' ) );
		\WP_Mock::expectActionAdded( 'ai_seo_assistant_report_received', \WP_Mock\Functions::type( 'callable' ) );
		$before = self::loaded();

		Plugin::instance()->init();
		// Stale_Alert's constant is resolved on first use, the way the cron callback would use it.
		$this->assertSame( 8, \AJR\SEOAssistant\Report\Stale_Alert::LATE_AFTER_DAYS );

		$loaded = array_values( array_diff( self::loaded(), $before ) );
		sort( $allowed );
		$this->assertNotEmpty( $loaded, 'init() ran (coverage)' );
		$this->assertSame( [], array_values( array_diff( $loaded, $allowed ) ), 'admin-only classes loaded on a front-end request' );
		foreach ( $loaded as $class ) {
			$this->assertDoesNotMatchRegularExpression( '/\\\\(Scan|Review|Search|Changes|Admin|AI)\\\\/', $class, 'a tool class on a visitor request' );
		}
	}
}
