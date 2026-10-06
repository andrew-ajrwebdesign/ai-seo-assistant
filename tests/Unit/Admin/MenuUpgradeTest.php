<?php
/**
 * Tests for the 5.0 menu (who sees what) and the 4.x → 5.0 upgrade step.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Admin;

use AJR\SEOAssistant\Admin\Menu;
use AJR\SEOAssistant\Core\Upgrade;
use AJR\SEOAssistant\Report\Access;
use AJR\SEOAssistant\Report\Snapshot_Store;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * Menu and upgrade.
 */
class MenuUpgradeTest extends TestCase {
	use Wp_Basics;

	/**
	 * Options in memory.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Options and basics.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_basics();
		$this->options = [];
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => array_key_exists( $n, $this->options ) ? $this->options[ $n ] : $d );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $n, $v ) {
				$this->options[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'add_option' )->andReturnUsing(
			function ( $n, $v ) {
				$this->options[ $n ] = $this->options[ $n ] ?? $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $n ) {
				unset( $this->options[ $n ] );
				return true;
			}
		);
	}

	/**
	 * The menu: Report (Weekly | Monthly) first for every Administrator, then SEO scan, Search Console,
	 * Changes, Settings behind the tools capability, in that order (mockup A1/A2).
	 */
	public function test_menu_order_and_capabilities(): void {
		$items = [];
		\WP_Mock::userFunction( 'add_menu_page' )->andReturnUsing(
			function ( $page, $menu, $cap, $slug ) use ( &$items ) {
				$items[] = [ 'top', $menu, $cap, $slug ];
				return 'toplevel_page_' . $slug;
			}
		);
		\WP_Mock::userFunction( 'add_submenu_page' )->andReturnUsing(
			function ( $parent, $page, $menu, $cap, $slug ) use ( &$items ) {
				$items[] = [ $parent, $menu, $cap, $slug ];
				return 'ai-seo-assistant_page_' . $slug;
			}
		);
		( new Menu( new Snapshot_Store() ) )->add();

		$this->assertSame( [ 'top', 'AI SEO Assistant', 'manage_options', 'ai-seo-assistant-weekly' ], $items[0] );
		$this->assertSame( [ 'Report', 'SEO scan', 'Search Console', 'Changes', 'Settings' ], array_column( array_slice( $items, 1 ), 1 ) );
		$this->assertSame( 'manage_options', $items[1][2], 'the Report is every Administrator’s' );
		foreach ( array_slice( $items, 2 ) as $item ) {
			$this->assertSame( Access::TOOLS_CAP, $item[2], $item[1] . ' is agency-only' );
		}
	}

	/**
	 * Who gets the tools: AJR Core's agency user does, the client Administrator does not; the override list,
	 * once it names a current Administrator, decides alone.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_menu_visibility_by_user(): void {
		// A stand-in for AJR Core 0.21's resolver: agency = an @ajrwebdesign.com login.
		eval( 'namespace AJR\Core\Admin; class Support { public static function is_agency_user( ?\WP_User $u = null ): bool { return $u && str_ends_with( $u->user_email, "@ajrwebdesign.com" ); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test double for another plugin's class.
		$users = [
			3 => 'andrew@ajrwebdesign.com',
			2 => 'jennlouis@welcometoboiseandbeyond.com',
		];
		\WP_Mock::userFunction( 'get_userdata' )->andReturnUsing(
			function ( $id ) use ( $users ) {
				$u             = new \WP_User();
				$u->ID         = $id;
				$u->user_email = $users[ $id ] ?? '';
				$u->roles      = [ 'administrator' ];
				return $u;
			}
		);
		\WP_Mock::userFunction( 'get_users' )->andReturnUsing( fn() => [ get_userdata( 3 ), get_userdata( 2 ) ] );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		$access = new Access();
		$caps   = [ 'manage_options' => true ];
		$asked  = [ Access::TOOLS_CAP ];

		Access::flush();
		$this->assertTrue( $access->grant( $caps, $asked, [ Access::TOOLS_CAP, 3 ] )[ Access::TOOLS_CAP ], 'agency user sees the tools' );
		$this->assertFalse( $access->grant( $caps, $asked, [ Access::TOOLS_CAP, 2 ] )[ Access::TOOLS_CAP ], 'client Administrator sees the Report only' );
		$this->assertFalse( $access->grant( [], $asked, [ Access::TOOLS_CAP, 3 ] )[ Access::TOOLS_CAP ], 'never without manage_options' );

		$this->options[ Access::OPTION ] = [ 2 ];
		Access::flush();
		$this->assertTrue( $access->grant( $caps, $asked, [ Access::TOOLS_CAP, 2 ] )[ Access::TOOLS_CAP ], 'the override list decides alone' );
		$this->assertSame( 'override', Access::source() );
	}

	/**
	 * 5.0 upgrade: tables created, the Google grant revoked (3 s, failure never blocks) and every Search
	 * Console option deleted; an EMPTY 4.x redirects table dropped; one with enabled rules KEPT and the
	 * redirect part left pending (5.0 no longer serves them).
	 */
	public function test_upgrade_to_5(): void {
		foreach ( Upgrade::GSC_OPTIONS as $option ) {
			$this->options[ $option ] = 'x';
		}
		$this->options[ Upgrade::OPTION ]               = '4.4.0';
		$this->options['ai_seo_assistant_redirect_map'] = [];
		$revokes                                        = [];
		\WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing(
			function ( $url, $args ) use ( &$revokes ) {
				$revokes[] = $args;
				return new \WP_Error( 'http', 'timed out' ); // Google unreachable: the upgrade still finishes.
			}
		);
		\WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( fn( $v ) => $v instanceof \WP_Error );
		\WP_Mock::userFunction( 'wp_salt' )->andReturn( 'salt' );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'untrailingslashit' )->andReturnUsing( fn( $s ) => rtrim( (string) $s, '/' ) );
		$this->options['ai_seo_assistant_gsc_token_data'] = [ 'refresh_token' => '1//r' ]; // A pre-4.4 plain row: still revoked.

		$db = $this->fake_db( 'wp_ai_seo_assistant_redirects', 0 );
		Upgrade::run();
		$this->assertSame( '5.0.0', $this->options[ Upgrade::OPTION ] );
		$this->assertSame( '1//r', $revokes[0]['body']['token'] );
		$this->assertSame( 3, $revokes[0]['timeout'] );
		foreach ( Upgrade::GSC_OPTIONS as $option ) {
			$this->assertArrayNotHasKey( $option, $this->options, $option . ' deleted' );
		}
		$this->assertNotEmpty( array_filter( $db->queries, static fn( $q ) => false !== strpos( $q, 'DROP TABLE' ) ), 'empty table dropped' );
		$this->assertArrayNotHasKey( 'ai_seo_assistant_redirect_map', $this->options );
		$this->assertNotEmpty( $GLOBALS['aisa_dbdelta'] ?? [], 'tables created' );

		// A site still holding enabled rules: nothing dropped, the agency is told.
		$this->options[ Upgrade::OPTION ] = '4.4.0';
		$db                               = $this->fake_db( 'wp_ai_seo_assistant_redirects', 2 );
		Upgrade::run();
		$this->assertSame( [], array_filter( $db->queries, static fn( $q ) => false !== strpos( $q, 'DROP TABLE' ) ) );
		$this->assertSame( 2, $this->options[ Upgrade::REDIRECTS_PENDING ] );

		// Confirmed in AJR Core only when every enabled source is in its map.
		$this->options['ajr_core_redirect_map'] = [ '/other' => [ 'target' => '/x/' ] ];
		$this->assertSame( [ '/old/', '/old/' ], Upgrade::confirm_redirects_moved(), 'not in AJR Core: kept, and named' );
		$this->assertSame( [], array_filter( $db->queries, static fn( $q ) => false !== strpos( $q, 'DROP TABLE' ) ) );
		$this->options['ajr_core_redirect_map'] = [ '/old' => [ 'target' => '/new/' ] ];
		$this->assertSame( [], Upgrade::confirm_redirects_moved() );
		$this->assertNotEmpty( array_filter( $db->queries, static fn( $q ) => false !== strpos( $q, 'DROP TABLE' ) ), 'dropped once confirmed' );
		$this->assertArrayNotHasKey( Upgrade::REDIRECTS_PENDING, $this->options );
	}

	/**
	 * A fake $wpdb: SHOW TABLES answers $table; $enabled enabled rules, all with source /old/.
	 *
	 * @param string|null $table   Table.
	 * @param int         $enabled Enabled rules.
	 */
	protected function fake_db( ?string $table, int $enabled ): object {
		$db = new class( $table, $enabled ) {
			/** @var string */
			public $prefix = 'wp_';
			/** @var array<int,string> */
			public $queries = [];
			/** @var string|null */
			public $table;
			/** @var int */
			public $enabled;
			public function __construct( $table, $enabled ) {
				$this->table   = $table;
				$this->enabled = $enabled;
			}
			public function esc_like( $s ) {
				return $s;
			}
			public function prepare( $q, ...$a ) {
				return $q;
			}
			public function get_charset_collate() {
				return '';
			}
			public function get_var( $q ) {
				return $this->table;
			}
			public function get_col( $q ) {
				return array_fill( 0, $this->enabled, '/old/' );
			}
			public function query( $q ) {
				$this->queries[] = $q;
				if ( false !== strpos( $q, 'DROP TABLE' ) ) {
					$this->table = null;
				}
				return true;
			}
		};
		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.

		return $db;
	}
}
