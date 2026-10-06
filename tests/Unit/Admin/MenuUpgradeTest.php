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
		$this->new_request();
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
		$this->assertArrayHasKey( Upgrade::REVOKE_FAILED, $this->options, 'Google unreachable: the agency is told to revoke by hand' );
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
	 * A site already at plugin level 5.0.0 whose tables are older (5.0.0's text columns) gets them altered
	 * on the next admin load: the tables follow their own version, not the plugin level.
	 */
	public function test_schema_mismatch_reinstalls_tables(): void {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true ); // wp-admin: the tables may change.
		$this->options[ Upgrade::OPTION ]                                     = Upgrade::LEVEL;
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ]       = '5.0.0';
		$GLOBALS['aisa_dbdelta']                                              = [];
		$db = $this->fake_db( null, 0 );
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		// Performance review (2): the ALTER that adds the newest columns failed. The version is not stored, so
		// the next admin load tries again.
		ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the expected log line would read as output.
		$db->columns = [ 'post_id', 'facts', 'note' ];
		( new Upgrade() )->maybe_run();
		$this->assertSame( '5.0.0', $this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ], 'not marked current while body_text and inbound are missing' );
		$this->assertSame( [ 'scan.body_text', 'scan.inbound', 'scan.inbound_hash' ], \AJR\SEOAssistant\Core\Schema::missing_columns() );
		$this->assertSame( [ 'scan.body_text', 'scan.inbound', 'scan.inbound_hash' ], $this->options[ \AJR\SEOAssistant\Core\Schema::FAILED_OPTION ]['missing'], 'the failure is stored for the notice' );

		// Verify review B2: the same request's writers do not try again; nor does the next admin load within
		// the hour.
		$GLOBALS['aisa_dbdelta'] = [];
		( new \AJR\SEOAssistant\Changes\Change_Log() )->save_note( 9, 'x' );
		$this->new_request();
		( new Upgrade() )->maybe_run();
		$this->assertSame( [], $GLOBALS['aisa_dbdelta'], 'not retried within the hour' );

		// An hour later, with the ALTER working: installed, current, the failure forgotten.
		$this->options[ \AJR\SEOAssistant\Core\Schema::FAILED_OPTION ]['at'] = time() - \AJR\SEOAssistant\Core\Schema::RETRY_AFTER - 1;
		$db->columns = [ 'post_id', 'facts', 'body_text', 'inbound', 'inbound_hash', 'note' ];
		$this->new_request();
		( new Upgrade() )->maybe_run();
		$this->assertStringContainsString( 'before_value longtext', implode( "\n", $GLOBALS['aisa_dbdelta'] ) );
		$this->assertSame( \AJR\SEOAssistant\Core\Schema::VERSION, $this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] );
		$this->assertArrayNotHasKey( \AJR\SEOAssistant\Core\Schema::FAILED_OPTION, $this->options );

		// Current tables: nothing to do.
		$GLOBALS['aisa_dbdelta'] = [];
		$this->new_request();
		( new Upgrade() )->maybe_run();
		$this->assertSame( [], $GLOBALS['aisa_dbdelta'] );
	}

	/**
	 * Verify review A5: a visitor's page render never changes the tables; the write still goes ahead (and
	 * falls back to the columns that exist).
	 */
	public function test_visitor_render_never_installs(): void {
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] = '4';
		$GLOBALS['aisa_dbdelta']                                        = [];
		$db = $this->fake_db( null, 0 );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		( new \AJR\SEOAssistant\Changes\Change_Log() )->save_note( 9, 'x' );
		$this->assertSame( [], $GLOBALS['aisa_dbdelta'], 'no install on the front end' );
		$this->assertNotEmpty( $db->updates, 'the write still runs' );
	}

	/**
	 * Verify review A5: a cron request may bring the tables up to date.
	 */
	public function test_cron_installs(): void {
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] = '4';
		$GLOBALS['aisa_dbdelta']                                        = [];
		$this->fake_db( null, 0 );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		( new \AJR\SEOAssistant\Changes\Change_Log() )->save_note( 9, 'x' );
		$this->assertNotEmpty( $GLOBALS['aisa_dbdelta'] );
		$this->assertSame( \AJR\SEOAssistant\Core\Schema::VERSION, $this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] );
	}

	/**
	 * Verify review B2: one agency notice while a table update has not taken, none once current.
	 */
	public function test_failed_table_update_notice(): void {
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( (object) [ 'id' => 'dashboard' ] );
		\WP_Mock::userFunction( 'admin_url' )->andReturnUsing( fn( $p = '' ) => 'https://x.test/wp-admin/' . $p );
		\WP_Mock::userFunction( 'get_users' )->andReturn( [] );
		\WP_Mock::userFunction( 'get_user_meta' )->andReturn( '' );
		\WP_Mock::userFunction( 'wp_salt' )->andReturn( 'salt' );
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		$this->fake_db( null, 0 );
		$this->options[ Upgrade::OPTION ]                                    = Upgrade::LEVEL;
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ]      = '5';
		$this->options[ \AJR\SEOAssistant\Core\Schema::FAILED_OPTION ]       = [
			'at'      => time(),
			'missing' => [ 'scan.inbound' ],
		];
		ob_start();
		( new \AJR\SEOAssistant\Admin\Secret_Notices() )->render();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, 'could not update its database tables' ) );
		$this->assertStringContainsString( 'missing: scan.inbound', $html );

		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] = \AJR\SEOAssistant\Core\Schema::VERSION;
		ob_start();
		( new \AJR\SEOAssistant\Admin\Secret_Notices() )->render();
		$this->assertStringNotContainsString( 'database tables', (string) ob_get_clean() );
	}

	/**
	 * A new request: Schema::ensure()'s once-per-request flag cleared.
	 */
	protected function new_request(): void {
		$tried = new \ReflectionProperty( \AJR\SEOAssistant\Core\Schema::class, 'tried' );
		$tried->setAccessible( true );
		$tried->setValue( null, false );
	}

	/**
	 * Code-standards B1: after an update that never ran install() (a zip uploaded over the plugin, SFTP), the
	 * first write to a custom table brings the tables up to date first, so the new column is there. A failing
	 * ALTER is tried once per request, not on every write, and the write still lands without the new column.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_writers_ensure_the_tables_first(): void {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true ); // wp-admin: the tables may change.
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] = '4';
		$GLOBALS['aisa_dbdelta']                                        = [];
		$db = $this->fake_db( null, 0 );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- a separate process: the expected log lines would read as output.
		$db->columns = [ 'post_id', 'facts', 'note' ]; // The ALTER fails: body_text and inbound never appear.
		$store       = new \AJR\SEOAssistant\Scan\Scan_Store();
		$store->save_facts( 3, '/a/', 'page', 'rendered', [ 'body_text' => 'Words' ] );
		$store->save_facts( 4, '/b/', 'page', 'rendered', [ 'body_text' => 'Words' ] );
		$this->assertCount( 3, $GLOBALS['aisa_dbdelta'], 'install() tried once (three tables) for two writes' );
		$this->assertSame( '4', $this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] );
	}

	/**
	 * Code-standards B1: the same, with a working ALTER: installed before the write, then marked current.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_writer_installs_before_writing(): void {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true ); // wp-admin: the tables may change.
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] = '4';
		$GLOBALS['aisa_dbdelta']                                        = [];
		$db = $this->fake_db( null, 0 );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		( new \AJR\SEOAssistant\Changes\Change_Log() )->save_note( 9, 'x' );
		$this->assertStringContainsString( 'body_text mediumtext', implode( "\n", $GLOBALS['aisa_dbdelta'] ) );
		$this->assertSame( \AJR\SEOAssistant\Core\Schema::VERSION, $this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] );
		$this->assertNotEmpty( $db->updates, 'then written' );
	}

	/**
	 * Code-standards B1: a zip uploaded over the installed copy is an "install" with no plugin list; the
	 * upgrader names the plugin, and the tables and upgrade steps run. Another plugin's zip does nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_zip_overwrite_runs_the_upgrade(): void {
		if ( ! defined( 'AI_SEO_ASSISTANT_BASENAME' ) ) {
			define( 'AI_SEO_ASSISTANT_BASENAME', 'ai-seo-assistant/ai-seo-assistant.php' );
		}
		$this->options[ Upgrade::OPTION ]                               = Upgrade::LEVEL;
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] = '4';
		$this->fake_db( null, 0 );
		$options = [
			'action'    => 'install',
			'type'      => 'plugin',
			'overwrite' => 'update-plugin',
		];

		$GLOBALS['aisa_dbdelta'] = [];
		( new Upgrade() )->after_update( new class() {
			/** @var array<string,string> */
			public $result = [ 'destination_name' => 'some-other-plugin' ];

			/**
			 * Another plugin.
			 */
			public function plugin_info() {
				return 'some-other-plugin/some-other-plugin.php';
			}
		}, $options );
		$this->assertSame( [], $GLOBALS['aisa_dbdelta'], 'another plugin\'s zip: nothing' );

		( new Upgrade() )->after_update( new class() {
			/** @var array<string,string> */
			public $result = [ 'destination_name' => 'ai-seo-assistant' ];

			/**
			 * This plugin.
			 */
			public function plugin_info() {
				return AI_SEO_ASSISTANT_BASENAME;
			}
		}, $options );
		$this->assertNotEmpty( $GLOBALS['aisa_dbdelta'], 'this plugin\'s zip: the tables are updated' );
		$this->assertSame( \AJR\SEOAssistant\Core\Schema::VERSION, $this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] );

		// Without plugin_info() (an older upgrader), the folder it wrote is enough.
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] = '4';
		$GLOBALS['aisa_dbdelta']                                        = [];
		( new Upgrade() )->after_update( (object) [ 'result' => [ 'destination_name' => 'ai-seo-assistant' ] ], $options );
		$this->assertNotEmpty( $GLOBALS['aisa_dbdelta'] );
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
			/** @var array<int,string> The tables' columns, as SHOW COLUMNS lists them. */
			public $columns = [ 'post_id', 'facts', 'body_text', 'inbound', 'inbound_hash', 'note' ];
			public function get_col( $q ) {
				if ( false !== strpos( $q, 'SHOW COLUMNS' ) ) {
					return $this->columns;
				}
				return array_fill( 0, $this->enabled, '/old/' );
			}
			/** @var array<int,array<string,mixed>> */
			public $updates = [];
			public function update( $table, $data ) {
				$this->updates[] = $data;
				return 1;
			}
			public function query( $q ) {
				$this->queries[] = is_array( $q ) ? (string) $q[0] : (string) $q;
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
