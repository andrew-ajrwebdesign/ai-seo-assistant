<?php
/**
 * Tests for the final 5.0 review round: one queued run per save, own-site fetches that never follow a
 * redirect, password-protected pages kept out, an honest sitemap and link cache.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Scan;

use AJR\SEOAssistant\Scan\Page_Fetcher;
use AJR\SEOAssistant\Scan\Page_Role;
use AJR\SEOAssistant\Search\Page_Data;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Scan\Scanner;
use AJR\SEOAssistant\Scan\Scheduler;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * Final round.
 */
class FinalRoundTest extends TestCase {
	use Wp_Basics;

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Transients written.
	 *
	 * @var array<string,mixed>
	 */
	protected array $transients = [];

	/**
	 * WP basics, an option store, this site's address.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_basics();
		$this->options    = [];
		$this->transients = [];
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => array_key_exists( $n, $this->options ) ? $this->options[ $n ] : $d );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $n, $v ) {
				$this->options[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'get_transient' )->andReturnUsing( fn( $n ) => $this->transients[ $n ] ?? false );
		\WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			function ( $n, $v ) {
				$this->transients[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'home_url' )->andReturnUsing( fn( $p = '' ) => 'https://site.test' . $p );
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) );
		\WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( fn( $k ) => strtolower( (string) $k ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( fn( $r ) => $r['code'] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( fn( $r ) => $r['body'] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( 'text/html' );
	}

	/**
	 * A WP_Post stand-in (separate process).
	 */
	protected function post_class(): void {
		if ( ! class_exists( '\WP_Post' ) ) {
			eval( 'class WP_Post { public $ID = 0; public $post_type = "page"; public $post_status = "publish"; public $post_password = ""; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a test stand-in for core's class.
		}
	}

	/**
	 * Item 8: saves queue ONE run (no per-post event, so ten saves are not ten site-wide passes); a save
	 * while a run is queued joins it and the site-wide pass runs again after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_saves_join_one_queued_run(): void {
		$this->post_class();
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		$scheduled = [];
		$next      = false;
		\WP_Mock::userFunction( 'wp_next_scheduled' )->andReturnUsing( function () use ( &$next ) {
			return $next;
		} );
		\WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturnUsing(
			function ( $t, $hook ) use ( &$scheduled, &$next ) {
				$scheduled[] = $hook;
				$next        = $t;
				return true;
			}
		);
		foreach ( [ 11, 12, 13, 12 ] as $id ) {
			$post     = new \WP_Post();
			$post->ID = $id;
			Scheduler::on_transition( 'publish', 'publish', $post );
		}
		$this->assertSame( [ Scheduler::RUN_HOOK ], $scheduled, 'Four saves schedule one run, never a per-post event.' );
		$queue = Scheduler::queue();
		$this->assertSame( [ 11, 12, 13 ], $queue['ids'] );
		$this->assertSame( 3, $queue['total'] );

		// A save after the site-wide pass already ran: the pass runs again.
		$queue['finalized']                = true;
		$this->options[ Scheduler::QUEUE ] = $queue;
		$post                              = new \WP_Post();
		$post->ID                          = 14;
		Scheduler::on_transition( 'publish', 'publish', $post );
		$this->assertArrayNotHasKey( 'finalized', Scheduler::queue() );
	}

	/**
	 * Item 11: own-site fetches never follow a redirect (a 301 is reported as itself, not followed off-site).
	 */
	public function test_own_fetch_never_follows_redirects(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $h, $v ) => $v );
		$args = [];
		\WP_Mock::userFunction( 'wp_remote_request' )->andReturnUsing(
			function ( $url, $a ) use ( &$args ) {
				$args = $a;
				return [
					'code' => 301,
					'body' => '',
				];
			}
		);
		$got = Page_Fetcher::fetch( 'https://site.test/old/', 'HEAD' );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 301, $got['status'] );
		$this->assertSame( [ 'ok' => false, 'status' => 0, 'html' => '', 'error' => 'not this site' ], Page_Fetcher::fetch( 'https://elsewhere.test/' ) );
	}

	/**
	 * Item 12: a password-protected page is never scanned; its row is removed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_password_protected_page_is_not_scanned(): void {
		$this->post_class();
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $h, $v ) => $v );
		$post                = new \WP_Post();
		$post->ID            = 7;
		$post->post_password = 'secret';
		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_remote_request' )->never();
		$store = \Mockery::mock( Scan_Store::class );
		$store->shouldReceive( 'delete' )->once()->with( 7 );
		$this->assertSame( 'skipped', ( new Scanner( $store ) )->scan_page( 7 ) );
	}

	/**
	 * Item 24: a child sitemap that fails to load makes the list "unknown" (null, not cached), never a short
	 * list that would call every page in it "not in the sitemap".
	 */
	public function test_sitemap_child_failure_is_unknown(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $h, $v ) => $v );
		$fail = true;
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
			function ( $url ) use ( &$fail ) {
				if ( false !== strpos( $url, 'sitemap_index' ) ) {
					return [
						'code' => 200,
						'body' => '<sitemapindex><loc>https://site.test/page-sitemap.xml</loc><loc>https://site.test/post-sitemap.xml</loc></sitemapindex>',
					];
				}
				if ( false !== strpos( $url, 'post-sitemap' ) && $fail ) {
					return [
						'code' => 500,
						'body' => '',
					];
				}
				return [
					'code' => 200,
					'body' => '<urlset><loc>https://site.test/' . ( false !== strpos( $url, 'post' ) ? 'a-post' : 'a-page' ) . '/</loc></urlset>',
				];
			}
		);
		$scanner = new class() extends Scanner {
			/**
			 * No store needed.
			 */
			public function __construct() {}

			/**
			 * The sitemap, exposed.
			 *
			 * @return array<string,true>|null
			 */
			public function read_sitemap(): ?array {
				return $this->sitemap();
			}
		};
		$this->assertNull( $scanner->read_sitemap() );
		$this->assertArrayNotHasKey( Scanner::SITEMAP_CACHE, $this->transients, 'An incomplete sitemap is not cached.' );

		$fail = false;
		$this->assertSame(
			[
				'site.test/a-page' => true,
				'site.test/a-post' => true,
			],
			$scanner->read_sitemap()
		);
		$this->assertArrayHasKey( Scanner::SITEMAP_CACHE, $this->transients );
	}

	/**
	 * Item 22: every option the plugin reads or writes is removed on uninstall, except the 4.x redirects
	 * options, which go with their table (kept while it holds someone's live redirects).
	 */
	public function test_uninstall_removes_every_option(): void {
		$root      = dirname( __DIR__, 3 );
		$uninstall = (string) file_get_contents( $root . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
		$found     = [];
		$files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) );
		foreach ( $files as $file ) {
			if ( '.php' !== substr( (string) $file, -4 ) ) {
				continue;
			}
			$src = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
			preg_match_all( "/(?:_option\\(\\s*|OPTION\\w*\\s*=\\s*|PENDING\\s*=\\s*|FAILED\\s*=\\s*)'(ai_seo_assistant_[a-z_]+)'/", $src, $m );
			$found = array_merge( $found, $m[1] );
		}
		$kept = [ 'ai_seo_assistant_redirects_db_version', 'ai_seo_assistant_redirect_map', 'ai_seo_assistant_redirects_case_insensitive' ];
		$this->assertGreaterThan( 30, count( array_unique( $found ) ) );
		foreach ( array_diff( array_unique( $found ), $kept ) as $option ) {
			$this->assertStringContainsString( "'" . $option . "'", $uninstall, $option . ' is deleted on uninstall' );
		}
	}

	/**
	 * Item 16: paths keyed lower-case (one wins per case-folded path), none over 191 characters; a failed
	 * insert rolls the whole push back and reports it, leaving the meta untouched.
	 */
	public function test_page_data_keys_and_rollback(): void {
		$keyed = Page_Data::keyed(
			[
				'/About/'                       => [ 'gsc' => [ 'impressions' => 5 ] ],
				'/about/'                       => [ 'gsc' => [ 'impressions' => 50 ] ],
				'/' . str_repeat( 'x', 200 ) => [ 'gsc' => [ 'impressions' => 9 ] ],
			]
		);
		$this->assertSame( [ '/about/' ], array_keys( $keyed ) );
		$this->assertSame( 50, $keyed['/about/']['gsc']['impressions'] );

		$GLOBALS['wpdb'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.
			/** @var string */
			public $prefix = 'wp_';
			/** @var array<int,string> */
			public $queries = [];
			public function prepare( $q, ...$a ) {
				return $q;
			}
			public function query( $q ) {
				$this->queries[] = $q;
				return 0 === strpos( $q, 'INSERT' ) ? false : 1; // The insert fails.
			}
		};
		$this->options[ \AJR\SEOAssistant\Core\Schema::VERSION_OPTION ] = \AJR\SEOAssistant\Core\Schema::VERSION;
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.
		$this->assertFalse( ( new Page_Data() )->replace_all( [ '/a/' => [ 'gsc' => null ] ], [ 'end' => '2026-10-04' ], 1 ) );
		$this->assertSame( 'ROLLBACK', end( $GLOBALS['wpdb']->queries ) );
		$this->assertSame( 'START TRANSACTION', $GLOBALS['wpdb']->queries[0] );
		$this->assertArrayNotHasKey( Page_Data::META_OPTION, $this->options, 'the meta still describes the rows kept' );
	}

	/**
	 * Item 17: an oversized push is refused on rest_pre_dispatch (before WordPress decodes its JSON);
	 * other routes and normal-sized pushes pass through untouched.
	 */
	public function test_oversize_push_refused_before_dispatch(): void {
		\WP_Mock::userFunction( 'untrailingslashit' )->andReturnUsing( fn( $s ) => rtrim( (string) $s, '/' ) );
		$endpoint = new \AJR\SEOAssistant\Report\Push_Endpoint( new \AJR\SEOAssistant\Report\Snapshot_Store() );
		$big      = new \WP_REST_Request( 'POST', '/ai-seo-assistant/v1/report' );
		$big->set_header( 'Content-Length', (string) ( \AJR\SEOAssistant\Report\Snapshot::MAX_BYTES_V2 + 1 ) );
		$got = $endpoint->refuse_oversize( null, null, $big );
		$this->assertInstanceOf( \WP_Error::class, $got );
		$this->assertSame( 'aisa_report_too_large', $got->get_error_code() );

		$other = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$other->set_header( 'Content-Length', (string) ( \AJR\SEOAssistant\Report\Snapshot::MAX_BYTES_V2 + 1 ) );
		$this->assertNull( $endpoint->refuse_oversize( null, null, $other ), 'another route is not ours to judge' );

		$small = new \WP_REST_Request( 'POST', '/ai-seo-assistant/v1/report' );
		$small->set_body( '{}' );
		$this->assertNull( $endpoint->refuse_oversize( null, null, $small ) );
	}

	/**
	 * Item 15: each tools AJAX action checks its own nonce, and the screen hands out one per action.
	 */
	public function test_separate_nonce_per_ajax_action(): void {
		$root  = dirname( __DIR__, 3 );
		$tools = (string) file_get_contents( $root . '/src/Admin/Tools_Actions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
		$this->assertStringNotContainsString( 'Ui::NONCE', $tools );
		$this->assertSame( 0, preg_match( '/this->guard_ajax\(\s*\)/', $tools ), 'every guard names its action' );
		$this->assertSame( 4, preg_match_all( "/guard_ajax\\( (?:'aisa_[a-z_]+'|self::ROLE) \\)/", $tools ) );
		$this->assertSame( \AJR\SEOAssistant\Admin\Tools_Actions::ROLE, \AJR\SEOAssistant\Admin\Ui::AJAX_ACTIONS[3] );
		$js = (string) file_get_contents( $root . '/assets/js/aisa-tools.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
		$this->assertStringContainsString( '( cfg.nonces || {} )[ action ]', $js );
	}

	/**
	 * Item 23: admin-ajax builds the admin stack only for this plugin's own actions.
	 */
	public function test_admin_stack_only_for_own_ajax(): void {
		$ajax = true;
		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturnUsing( function () use ( &$ajax ) {
			return $ajax;
		} );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnArg( 0 );
		$_REQUEST['action'] = 'heartbeat';
		$this->assertFalse( \AJR\SEOAssistant\Core\Plugin::admin_stack_needed() );
		$_REQUEST['action'] = 'aisa_scan_step';
		$this->assertTrue( \AJR\SEOAssistant\Core\Plugin::admin_stack_needed() );
		$_REQUEST['action'] = 'ai_seo_assistant_generate';
		$this->assertTrue( \AJR\SEOAssistant\Core\Plugin::admin_stack_needed() );
		$ajax = false;
		$_REQUEST['action'] = 'heartbeat';
		$this->assertTrue( \AJR\SEOAssistant\Core\Plugin::admin_stack_needed(), 'screens and admin-post always' );
		unset( $_REQUEST['action'] );
	}

	/**
	 * Item 23: role flags are worked out from the content at scan time.
	 */
	public function test_role_flags(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $h, $v ) => $v );
		$this->assertSame( 'none', Page_Role::flags( '<p>Hello</p>' ) );
		$this->assertSame( 'form', Page_Role::flags( '[contact-form-7 id="1"]' ) );
		$this->assertSame( 'listing', Page_Role::flags( '<!-- wp:query {"queryId":1} -->' ) );
	}

	/**
	 * Item 23: the link cache is written only when something new was checked, so its day keeps counting
	 * from when an answer was learned.
	 */
	public function test_link_cache_not_rewritten_without_new_checks(): void {
		$this->transients[ Scanner::LINK_CACHE ] = [ '/gone/' => 404 ];
		$writes                                  = 0;
		\WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			function () use ( &$writes ) {
				++$writes;
				return true;
			}
		);
		$scanner = new class() extends Scanner {
			/**
			 * No store needed.
			 */
			public function __construct() {}

			/**
			 * Link checks, exposed.
			 *
			 * @param array<int,string> $targets Paths.
			 * @param array<string,int> $known   Known pages.
			 * @return array<string,int>
			 */
			public function links( array $targets, array $known ): array {
				return $this->check_unknown_links( $targets, $known, [] );
			}
		};
		$this->assertSame( [ '/gone/' => 404 ], $scanner->links( [ '/gone/', '/about/', '/' ], [ '/about/' => 3 ] ) );
		$this->assertSame( 0, $writes );
	}
}
