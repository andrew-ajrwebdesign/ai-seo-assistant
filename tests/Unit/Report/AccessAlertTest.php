<?php
/**
 * Tests for Report\Access (who keeps the tools) and Report\Stale_Alert (one alert, one all-clear).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Report;

use AJR\SEOAssistant\Report\Access;
use AJR\SEOAssistant\Report\Snapshot_Store;
use AJR\SEOAssistant\Report\Stale_Alert;
use WP_Mock\Tools\TestCase;

/**
 * Access rules and the alert's state machine, with options and users faked in memory.
 */
class AccessAlertTest extends TestCase {

	/**
	 * The fake options table.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Mail sent.
	 *
	 * @var array<int,array<int,string>>
	 */
	protected array $mail = [];

	/**
	 * Whether wp_mail succeeds.
	 *
	 * @var bool
	 */
	protected bool $mail_works = true;

	/**
	 * Options, users (1 and 2 are Administrators, 3 an Editor) and mail.
	 */
	public function setUp(): void {
		parent::setUp();
		Access::flush();
		$this->options    = [ 'admin_email' => 'owner@example.test' ];
		$this->mail       = [];
		$this->mail_works = true;
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $name, $fallback = false ) => $this->options[ $name ] ?? $fallback );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		\WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		\WP_Mock::userFunction( 'get_userdata' )->andReturnUsing(
			function ( $id ) {
				if ( ! in_array( $id, [ 1, 2, 3 ], true ) ) {
					return false;
				}
				$user        = new \WP_User();
				$user->ID    = $id;
				$user->roles = 3 === $id ? [ 'editor' ] : [ 'administrator' ];
				return $user;
			}
		);
		\WP_Mock::userFunction( 'wp_mail' )->andReturnUsing(
			function ( $to, $subject, $body ) {
				$this->mail[] = [ $to, $subject, $body ];
				return $this->mail_works;
			}
		);
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'is_email' )->andReturnUsing( fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false );
		\WP_Mock::userFunction( 'get_bloginfo' )->andReturn( 'Northfield' );
		\WP_Mock::userFunction( 'wp_specialchars_decode' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'home_url' )->andReturn( 'https://northfield.example/' );
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'Monday 28 September 2026, 9:00am' );
	}

	/**
	 * Ask the filter for the tools capability.
	 *
	 * @param int  $user_id User.
	 * @param bool $admin   Holds manage_options.
	 */
	protected function can( int $user_id, bool $admin = true ): bool {
		$caps = ( new Access() )->grant( [ 'manage_options' => $admin ], [ Access::TOOLS_CAP ], [ Access::TOOLS_CAP, $user_id ] );

		return ! empty( $caps[ Access::TOOLS_CAP ] );
	}

	/**
	 * Nobody named: every Administrator keeps the tools; nobody else gets them.
	 */
	public function test_no_lockout_until_someone_is_named(): void {
		$this->assertTrue( $this->can( 1 ) );
		$this->assertTrue( $this->can( 2 ) );
		$this->assertFalse( $this->can( 3, false ), 'an Editor never gets the tools' );
	}

	/**
	 * Named: only the named Administrators keep the tools.
	 */
	public function test_named_agency_users_only(): void {
		$this->options[ Access::OPTION ] = [ 1 ];
		$this->assertTrue( $this->can( 1 ) );
		$this->assertFalse( $this->can( 2 ), 'the client admin sees the report only' );
	}

	/**
	 * A list naming only deleted or demoted users falls back to every Administrator.
	 */
	public function test_stale_list_falls_back(): void {
		$this->options[ Access::OPTION ] = [ 3, 99 ];
		$this->assertSame( [], Access::named() );
		$this->assertTrue( $this->can( 2 ) );
	}

	/**
	 * AJR Core 0.20's resolver (no arguments, current user only): with no list, AJR Core decides, and a
	 * user other than the current one is refused while an agency user is current. When the current user is
	 * NOT agency, 0.20 cannot say whether any Administrator is, so the no-lockout rule keeps every
	 * Administrator's tools (round 2: the pre-4.4.0 behaviour; AJR Core 0.21 answers properly). A list,
	 * once set, still wins over AJR Core.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ajr_core_decides_when_no_list(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in for AJR Core's class, defined only in this process.
		eval( 'namespace AJR\Core\Admin; class Support { public static $agency = [ 1 ]; public static function is_agency_user(): bool { return in_array( \get_current_user_id(), self::$agency, true ); } }' );
		$current = 1;
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturnUsing( function () use ( &$current ) {
			return $current;
		} );

		$this->assertTrue( $this->can( 1 ), 'agency-domain admin keeps the tools' );
		$this->assertFalse( $this->can( 2 ), 'another admin is not asked about while user 1 is current' );

		$current = 2;
		Access::flush();
		$this->assertTrue( $this->can( 2 ), 'client admin current: 0.20 cannot prove an agency admin exists, so no lockout' );

		$this->options[ Access::OPTION ] = [ 2 ];
		Access::flush();
		$this->assertTrue( $this->can( 2 ), 'the list wins over AJR Core' );
	}

	/**
	 * Round 2, CS 1: AJR Core active, no list, and AJR Core counts NO current Administrator as agency:
	 * every Administrator keeps the tools (the old no-lockout guarantee); an Editor still never does. Once
	 * one Administrator is agency, only that one has them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_agency_admin_falls_back_to_every_admin(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- see above.
		eval( 'namespace AJR\Core\Admin; class Support { public static $agency = []; public static function is_agency_user( ?\WP_User $user = null ): bool { return null !== $user && in_array( $user->ID, self::$agency, true ); } }' );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
		$asked = 0;
		\WP_Mock::userFunction( 'get_users' )->andReturnUsing(
			function ( $args ) use ( &$asked ) {
				++$asked;
				$this->assertSame( 'administrator', $args['role'] );
				$this->assertSame( 100, $args['number'], 'bounded' );
				return [ get_userdata( 1 ), get_userdata( 2 ) ];
			}
		);

		$this->assertTrue( $this->can( 1 ), 'no agency admin: admin 1 keeps the tools' );
		$this->assertTrue( $this->can( 2 ), 'no agency admin: admin 2 keeps the tools' );
		$this->assertFalse( $this->can( 3, false ), 'an Editor never' );
		$this->assertSame( 1, $asked, 'Administrators listed once per request' );

		\AJR\Core\Admin\Support::$agency = [ 2 ];
		Access::flush();
		$this->assertFalse( $this->can( 1 ), 'an agency admin exists: the client admin loses the tools' );
		$this->assertTrue( $this->can( 2 ) );
	}

	/**
	 * The contract's later resolver (takes a user): any user can be answered.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ajr_core_resolver_with_user_argument(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- see above.
		eval( 'namespace AJR\Core\Admin; class Support { public static function is_agency_user( ?\WP_User $user = null ): bool { return null !== $user && 2 === $user->ID; } }' );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
		\WP_Mock::userFunction( 'get_users' )->andReturnUsing( fn() => [ get_userdata( 1 ), get_userdata( 2 ) ] );

		$this->assertFalse( $this->can( 1 ) );
		$this->assertTrue( $this->can( 2 ), 'answered for a user who is not the current one' );
	}

	/**
	 * Other capabilities pass through untouched.
	 */
	public function test_other_caps_untouched(): void {
		$in = [ 'manage_options' => true ];
		$this->assertSame( $in, ( new Access() )->grant( $in, [ 'edit_posts' ], [ 'edit_posts', 1 ] ) );
	}

	/**
	 * Put a stored week in the fake options (newest first, as the store keeps them).
	 *
	 * @param string $monday Week start.
	 */
	protected function store_week( string $monday ): void {
		$weeks            = (array) ( $this->options[ Snapshot_Store::OPTION ] ?? [] );
		$weeks[ $monday ] = [
			'week'         => [
				'start' => $monday,
				'end'   => gmdate( 'Y-m-d', (int) strtotime( $monday . ' +6 days UTC' ) ),
			],
			'generated_at' => (int) strtotime( $monday . ' +8 days UTC' ),
		];
		krsort( $weeks );
		$this->options[ Snapshot_Store::OPTION ] = $weeks;
	}

	/**
	 * Waiting → ok → one alert → silence → one all-clear, timed from the newest WEEK (as the client's
	 * late notice is), with t0 = Monday 28 September 2026, the day week 21–27 September was due.
	 */
	public function test_alert_once_then_recover(): void {
		$alert = new Stale_Alert( new Snapshot_Store() );
		$t0    = 1790586000;

		$this->assertSame( 'waiting', $alert->check( $t0 ), 'no report yet: nothing is late' );

		$this->store_week( '2026-09-21' );
		$this->assertSame( 'ok', $alert->check( $t0 + 7 * DAY_IN_SECONDS ) );
		$this->assertSame( 'alerted', $alert->check( $t0 + 9 * DAY_IN_SECONDS ) );
		$this->assertTrue( Stale_Alert::alerted() );
		$this->assertSame( 'already-alerted', $alert->check( $t0 + 10 * DAY_IN_SECONDS ) );
		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'owner@example.test', $this->mail[0][0], 'falls back to the admin email' );
		$this->assertStringContainsString( '9 days late', $this->mail[0][1] );

		$this->store_week( '2026-09-28' );
		$this->assertSame( 'recovered', $alert->check( $t0 + 11 * DAY_IN_SECONDS ) );
		$this->assertFalse( Stale_Alert::alerted() );
		$this->assertCount( 2, $this->mail );
		$this->assertSame( 'ok', $alert->check( $t0 + 12 * DAY_IN_SECONDS ) );
	}

	/**
	 * Pushes that keep re-sending an OLD week do not hide lateness from the agency.
	 */
	public function test_repushing_an_old_week_still_alerts(): void {
		$this->store_week( '2026-09-21' );
		$this->options[ Snapshot_Store::LAST_PUSH ] = 1790586000 + 9 * DAY_IN_SECONDS; // Pushed today, but old figures.

		$this->assertSame( 'alerted', ( new Stale_Alert( new Snapshot_Store() ) )->check( 1790586000 + 9 * DAY_IN_SECONDS ) );
	}

	/**
	 * A failed send is not recorded, so the report never claims an alert that did not go.
	 */
	public function test_failed_mail_is_not_an_alert(): void {
		$this->store_week( '2026-09-21' );
		$this->options[ Stale_Alert::ADDRESS ] = 'andrew@example.test';
		$this->mail_works                           = false;

		$this->assertSame( 'ok', ( new Stale_Alert( new Snapshot_Store() ) )->check( 1790586000 + 9 * DAY_IN_SECONDS ) );
		$this->assertFalse( Stale_Alert::alerted() );
		$this->assertSame( 'andrew@example.test', $this->mail[0][0] );
	}
}
