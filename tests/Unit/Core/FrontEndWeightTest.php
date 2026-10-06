<?php
/**
 * Guard for 4.4.0's front-end weight fix: a visitor's request loads only the classes that act there.
 *
 * Until 4.3.2 every page view built ~20 admin classes (323 KB of PHP on the first real site measured).
 * This boots Plugin::init() as a front-end request in a fresh process and fails if any class outside the
 * allow-list was loaded, so an admin class wired back into the front-end path (directly, or through a
 * class constant that names it) shows up here instead of in a performance review.
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
	 * With and without a core plugin owning redirects.
	 *
	 * @return array<string,array{0:bool,1:array<int,string>}>
	 */
	public function provide_sites(): array {
		$always = [
			'AJR\SEOAssistant\Core\Plugin',
			'AJR\SEOAssistant\Core\Secret_Guard', // The secret-option write filters: small, and they must run everywhere.
			'AJR\SEOAssistant\Report\Access',
			'AJR\SEOAssistant\Report\Push_Endpoint',
			'AJR\SEOAssistant\Report\Snapshot_Store',
			'AJR\SEOAssistant\Report\Stale_Alert',
		];

		return [
			'a core plugin owns redirects' => [ true, $always ],
			'this plugin runs redirects'   => [ false, array_merge( $always, [ 'AJR\SEOAssistant\Redirects\Redirect_Handler', 'AJR\SEOAssistant\Redirects\Redirect_Store' ] ) ],
		];
	}

	/**
	 * A front-end request loads the allow-listed classes and nothing else.
	 *
	 * @dataProvider provide_sites
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param bool               $core_owns Whether a site core answers the redirects filter.
	 * @param array<int,string> $allowed   Classes a visitor's request may load.
	 */
	public function test_front_end_loads_only_what_acts_there( bool $core_owns, array $allowed ): void {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => $d );
		\WP_Mock::onFilter( 'ai_seo_assistant_core_owns_redirects' )->with( false )->reply( $core_owns );
		$before = self::loaded();

		Plugin::instance()->init();
		// Stale_Alert's constant is resolved on first use, the way the cron callback would use it.
		$this->assertSame( 8, \AJR\SEOAssistant\Report\Stale_Alert::LATE_AFTER_DAYS );

		$loaded = array_values( array_diff( self::loaded(), $before ) );
		sort( $allowed );
		$this->assertNotEmpty( $loaded, 'init() ran (coverage)' );
		$this->assertSame( [], array_values( array_diff( $loaded, $allowed ) ), 'admin-only classes loaded on a front-end request' );
	}
}
