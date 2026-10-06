<?php
/**
 * Automatic page types (Andrew: "can you not auto set this?" / "give the option so this can be changed"):
 * high-confidence types set and logged, medium ones an issue and in the review list, a person always wins,
 * an Undo is never re-applied, and a type set elsewhere clears a stale "not set" at once.
 *
 * AJR Core's Page_Types is stood in with eval() in separate processes: the contract is its method names.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Scan;

use AJR\SEOAssistant\Changes\Change_Log;
use AJR\SEOAssistant\Review\Page_Review;
use AJR\SEOAssistant\Scan\Auto_Types;
use AJR\SEOAssistant\Scan\Page_Role;
use AJR\SEOAssistant\Scan\Rules;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * Auto types.
 */
class AutoTypesTest extends TestCase {
	use Wp_Basics;

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Whether the current user may edit posts.
	 *
	 * @var bool
	 */
	protected bool $can = true;

	/**
	 * WP basics, options, and AJR Core's Page_Types (0.22 shape) in memory.
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
		\WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $n ) {
				unset( $this->options[ $n ] );
				return true;
			}
		);
		\WP_Mock::userFunction( 'current_user_can' )->andReturnUsing( fn() => $this->can );
		\WP_Mock::userFunction( 'get_permalink' )->andReturnUsing( fn( $id ) => 'https://x.test/p' . $id . '/' );
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) );
		if ( ! class_exists( 'AJR\Core\Schema\Page_Types' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in for AJR Core's class (contract by name).
			eval(
				'namespace AJR\Core\Schema; class Page_Types {
					public static $type = []; public static $source = []; public static $verdict = [];
					public static function types() { return [ "service" => [ "Service page", "", "" ], "contact" => [ "Contact page", "", "" ], "area" => [ "Area page", "", "" ], "other" => [ "Other", "", "" ] ]; }
					public static function get( $id ) { return self::$type[ $id ] ?? ""; }
					public static function set( $id, $type ) { if ( "" === $type ) { unset( self::$type[ $id ] ); } else { self::$type[ $id ] = $type; } self::$source[ $id ] = "manual"; return true; }
					public static function set_auto( $id, $type ) { $s = self::$source[ $id ] ?? ""; if ( "manual" === $s || ( "" === $s && "" !== self::get( $id ) ) ) { return false; } self::$type[ $id ] = $type; self::$source[ $id ] = "auto"; return true; }
					public static function source( $id ) { return self::$source[ $id ] ?? ""; }
					public static function suggest( $id ) { return self::$verdict[ $id ]["type"] ?? ""; }
					public static function suggest_with_confidence( $id ) { return self::$verdict[ $id ] ?? [ "type" => "", "confidence" => "none", "reason" => "" ]; }
				}'
			);
		}
	}

	/**
	 * An in-memory change log.
	 */
	protected function log(): Change_Log {
		return new class() extends Change_Log {
			/** @var array<int,array<string,mixed>> */
			public $rows = [];
			public function log( string $batch, int $post_id, string $path, string $field, int $object_id, string $before, string $after, int $user_id ): int {
				$id                = count( $this->rows ) + 1;
				$this->rows[ $id ] = compact( 'id', 'batch', 'post_id', 'path', 'field', 'object_id', 'user_id' ) + [
					'before_value' => $before,
					'after_value'  => $after,
					'undone_at'    => null,
				];
				return $id;
			}
			public function get( int $id ): ?array {
				return $this->rows[ $id ] ?? null;
			}
			public function mark_undone( int $id, int $user_id ): bool {
				$this->rows[ $id ]['undone_at'] = 'now';
				return true;
			}
		};
	}

	/**
	 * High: set and logged as "Automatic" (user 0); a page a person decided is never touched; the toggle
	 * turns it off; Undo clears the type, marks the page manual, and the next pass leaves it alone.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_auto_set_logged_and_undo_never_reapplies(): void {
		$core = 'AJR\Core\Schema\Page_Types';
		$log  = $this->log();
		$this->assertTrue( Auto_Types::enabled(), 'on by default' );

		$this->assertSame( [ 10 => 'contact' ], Auto_Types::apply( [ 10 => 'contact' ], $log ) );
		$this->assertSame( 'contact', $core::get( 10 ) );
		$this->assertSame( 'auto', Page_Role::source( 10 ) );
		$row = $log->rows[1];
		$this->assertSame( [ 'page_type', '', 'contact', 0 ], [ $row['field'], $row['before_value'], $row['after_value'], $row['user_id'] ] );

		// Undo: back to no type, and the page is now the agency's.
		$review = new class( new \stdClass(), null, $log, new \stdClass() ) extends Page_Review {
			/**
			 * No client needed.
			 *
			 * @param mixed $claude  Unused.
			 * @param mixed $store   Unused.
			 * @param mixed $log     Log.
			 * @param mixed $adapter Unused.
			 */
			public function __construct( $claude, $store, $log, $adapter ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- test double.
				$this->log = $log;
			}
			protected function rescan( int $post_id, array $known = [] ): void {}
			protected function read( string $field, int $post_id ): string {
				return '';
			}
		};
		\WP_Mock::userFunction( 'wp_salt' )->andReturn( 's' );
		$this->assertSame( 1, $review->undo( [ 1 ], 1 )['undone'] );
		$this->assertSame( '', $core::get( 10 ) );
		$this->assertSame( 'manual', Page_Role::source( 10 ) );
		$this->assertSame( [], Auto_Types::apply( [ 10 => 'contact' ], $log ), 'an undone automatic type is never set again' );
		$this->assertSame( '', $core::get( 10 ) );

		// Auto → changed by hand → a later pass keeps the person's type.
		Auto_Types::apply( [ 11 => 'service' ], $log );
		Page_Role::set_type( 11, 'area' );
		$this->assertSame( 'manual', Page_Role::source( 11 ) );
		Auto_Types::apply( [ 11 => 'service' ], $log );
		$this->assertSame( 'area', $core::get( 11 ), 'the manual type stays after a rescan' );

		// The Settings toggle: nothing is set.
		$this->options[ Auto_Types::OPTION ] = '0';
		$this->assertSame( [], Auto_Types::apply( [ 12 => 'service' ], $log ) );
		$this->assertSame( '', $core::get( 12 ) );
	}

	/**
	 * A pass with no user (cron) cannot set a type: it waits, and is set on the agency's next visit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pending_until_a_user_may(): void {
		$core = 'AJR\Core\Schema\Page_Types';
		$this->can = false;
		$this->assertSame( [], Auto_Types::apply( [ 20 => 'area' ], $this->log() ) );
		$this->assertSame( [ 20 => 'area' ], Auto_Types::pending() );
		$this->assertSame( '', $core::get( 20 ) );
	}

	/**
	 * "Page type not set" only for a medium guess; the verdict maps AJR Core's answer (an unknown type is none).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_issue_only_when_unsure(): void {
		$core            = 'AJR\Core\Schema\Page_Types';
		$core::$verdict = [
			1 => [ 'type' => 'contact', 'confidence' => 'high', 'reason' => 'booking page' ],
			2 => [ 'type' => 'service', 'confidence' => 'medium', 'reason' => 'title word' ],
			3 => [ 'type' => 'bogus', 'confidence' => 'high', 'reason' => '' ],
		];
		$this->assertSame( 'high', Page_Role::verdict( 1 )['confidence'] );
		$this->assertSame( 'none', Page_Role::verdict( 3 )['confidence'] );
		$codes = static fn( string $conf ) => array_column( Rules::evaluate( [ 'og_image' => 'x' ], [ 'page_types' => true, 'page_type' => '', 'post_type' => 'page', 'type_confidence' => $conf, 'inbound' => 3 ] ), 'code' );
		$this->assertContains( 'page_type_unset', $codes( 'medium' ) );
		$this->assertNotContains( 'page_type_unset', $codes( 'high' ) );
		$this->assertNotContains( 'page_type_unset', $codes( 'none' ), 'nothing points anywhere: "other", not an issue' );
	}

	/**
	 * A type set in AJR Core directly (after the scan): the stored "not set" never shows, in the review row
	 * or the list's counts, without a rescan.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_type_set_elsewhere_clears_stale_issue(): void {
		$core   = 'AJR\Core\Schema\Page_Types';
		$issues = [
			[ 'kind' => 'schema', 'code' => 'page_type_unset', 'who' => 'click' ],
			[ 'kind' => 'title', 'code' => 'title_wide', 'who' => 'claude' ],
		];
		$GLOBALS['wpdb'] = new class( $issues ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.
			/** @var string */
			public $prefix = 'wp_';
			/** @var array<int,mixed> */
			public $issues;
			public function __construct( $issues ) {
				$this->issues = $issues;
			}
			public function prepare( $q, ...$a ) {
				return $q;
			}
			public function get_row( $q, $o ) {
				return [ 'post_id' => 5721, 'path' => '/x/', 'facts' => '{}', 'issues' => json_encode( $this->issues ), 'suggestions' => null, 'issue_count' => 2 ];
			}
			public function get_results( $q, $o ) {
				return [ [ 'post_id' => 5721, 'path' => '/x/', 'post_type' => 'page', 'scanned_at' => '', 'source' => 'rendered', 'flags' => '', 'issue_count' => 2, 'issue_kinds' => 'schema:1,title:1,_claude:1,_ptype:1', 'suggested_at' => null, 'has_suggestions' => 0 ] ];
			}
		};
		$store = new \AJR\SEOAssistant\Scan\Scan_Store();
		$this->assertContains( 'page_type_unset', array_column( $store->get( 5721 )['issues'], 'code' ), 'no type yet: the finding stands' );
		$this->assertSame( 2, $store->summaries()[5721]['issue_count'] );

		$core::set( 5721, 'area' ); // AJR Core's own box, after the scan.
		$this->assertNotContains( 'page_type_unset', array_column( $store->get( 5721 )['issues'], 'code' ) );
		$row = $store->summaries()[5721];
		$this->assertSame( 1, $row['issue_count'] );
		$this->assertArrayNotHasKey( 'schema', $row['kinds'] );
		$this->assertArrayNotHasKey( '_ptype', $row['kinds'] );
	}
}
