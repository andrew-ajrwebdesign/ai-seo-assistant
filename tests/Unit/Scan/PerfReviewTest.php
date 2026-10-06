<?php
/**
 * Build-performance review of 4695128..a82d723: inbound links built once per pass and read per page, the
 * lean row and one fetch on the editor screen, the scan write surviving a failed ALTER, a cut-short page's
 * phrase checks, and visible_text() surviving a failed pattern.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Scan;

use AJR\SEOAssistant\Admin\Editor_Box;
use AJR\SEOAssistant\Core\Utils;
use AJR\SEOAssistant\Review\Editor_Check;
use AJR\SEOAssistant\Review\Page_Review;
use AJR\SEOAssistant\Scan\Html_Parser;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Scan\Scanner;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * A store that records the inbound lists written.
 */
class Inbound_Store extends Scan_Store {

	/** @var array<int,array<int,array{0:string,1:string}>> Written lists by post ID. */
	public $written = [];

	/**
	 * Record.
	 *
	 * @param int                                 $post_id Post ID.
	 * @param array<int,array{0:string,1:string}> $inbound List.
	 */
	public function save_inbound( int $post_id, array $inbound ): void {
		$this->written[ $post_id ] = $inbound;
	}
}

/**
 * Performance review fixes.
 */
class PerfReviewTest extends TestCase {
	use Wp_Basics;

	/**
	 * WP basics; a $wpdb that fails the test on any call unless a test sets its own.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_basics();
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the stand-in for core's.
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';

			/**
			 * Any query is a failure here.
			 *
			 * @param string $name Method.
			 * @param array  $args Arguments.
			 * @throws \LogicException Always.
			 */
			public function __call( $name, $args ) {
				throw new \LogicException( 'unexpected database call: ' . $name );
			}
		};
	}

	/**
	 * Clean up.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * (1) The site-wide pass builds who links to whom once; each page's list is written only when it
	 * changed, and never before its column exists. The editor reads that one list: no other page's facts.
	 */
	public function test_inbound_built_once_and_read_per_page(): void {
		$rows = [
			1 => [
				'path'         => '/guide/',
				'facts'        => [ 'links' => [ [ 'p' => '/map/', 't' => 'Boise neighborhoods map' ], [ 'p' => '/guide/', 't' => 'self' ] ] ],
				'inbound_json' => '[]',
			],
			2 => [
				'path'         => '/map/',
				'facts'        => [ 'links' => [ [ 'p' => '/Guide', 't' => 'Back to the guide' ] ] ],
				'inbound_json' => '[["\/guide\/","Boise neighborhoods map"]]',
			],
			3 => [
				'path'         => '/new/',
				'facts'        => [ 'links' => [ [ 'p' => '/map/', 't' => 'Map' ] ] ],
				'inbound_json' => null,
			],
		];
		$map  = Scanner::inbound_map( $rows );
		$this->assertSame( [ [ '/guide/', 'Boise neighborhoods map' ], [ '/new/', 'Map' ] ], $map['/map/'] );
		$this->assertSame( [ [ '/map/', 'Back to the guide' ] ], $map['/guide/'], 'paths compared normalised; a page\'s link to itself left out' );

		$store = new Inbound_Store();
		( new Scanner( $store ) )->save_inbound( $rows );
		$this->assertSame( [ 1, 2 ], array_keys( $store->written ), '/new/ has no column yet: not written' );
		$this->assertSame( [ [ '/map/', 'Back to the guide' ] ], $store->written[1] );

		// Unchanged lists are not rewritten.
		$rows[1]['inbound_json'] = (string) json_encode( $store->written[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$rows[2]['inbound_json'] = (string) json_encode( $store->written[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$store->written          = [];
		( new Scanner( $store ) )->save_inbound( $rows );
		$this->assertSame( [], $store->written );

		// The editor: this page's stored list, no database call (setUp's $wpdb throws on any).
		$context = Page_Review::editor_context(
			2,
			[
				'path'      => '/map/',
				'facts'     => [],
				'body_text' => 'Words',
				'inbound'   => [ [ '/guide/', 'Boise neighborhoods map' ] ],
			]
		);
		$this->assertSame( [ [ '/guide/', 'Boise neighborhoods map' ] ], $context['inbound'] );
		$this->assertTrue( Editor_Check::in_place( [ 'check' => 'link', 'targets' => [ 'Boise neighborhoods map' ], 'sources' => [ '/guide/' ] ], [], '', $context['inbound'] ) );
		$this->assertSame( [], Page_Review::editor_context( 2, [ 'path' => '/map/', 'body_text' => 'Words', 'inbound' => null ] )['inbound'], 'not built yet: empty, the link to-do stays a manual tick' );
	}

	/**
	 * (1) The editor screen's staleness check takes the row's scanned_at: no second fetch. (4) The tick
	 * handler's lean row leaves out the page text and inbound links.
	 */
	public function test_one_fetch_and_lean_row(): void {
		if ( ! class_exists( '\WP_Post' ) ) {
			eval( 'class WP_Post { public $ID = 0; public $post_type = "page"; public $post_status = "publish"; public $post_password = ""; public $post_modified_gmt = ""; public $post_content = ""; public $post_excerpt = ""; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a test stand-in for core's class.
		}
		$post                    = new \WP_Post();
		$post->post_modified_gmt = '2026-10-06 10:00:00';
		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		$this->assertFalse( Scanner::refresh_if_stale( 5, '2026-10-06 12:00:00' ), 'not edited since: no rescan, and no query (setUp\'s $wpdb throws)' );

		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';
			/** @var array<int,string> */
			public $sql = [];

			/**
			 * Capture.
			 *
			 * @param string $q    SQL.
			 * @param mixed  ...$a Values.
			 */
			public function prepare( $q, ...$a ): string {
				return (string) $q;
			}

			/**
			 * Capture.
			 *
			 * @param string $q SQL.
			 * @return null
			 */
			public function get_row( $q ) {
				$this->sql[] = $q;
				return null;
			}
		};
		( new Scan_Store() )->get( 5, false );
		( new Scan_Store() )->get( 5 );
		$this->assertStringNotContainsString( 'body_text', $GLOBALS['wpdb']->sql[0] );
		$this->assertStringNotContainsString( '*', $GLOBALS['wpdb']->sql[0] );
		$this->assertStringContainsString( 'suggestions', $GLOBALS['wpdb']->sql[0], 'the tick handler still needs the advice' );
		$this->assertStringContainsString( 'SELECT *', $GLOBALS['wpdb']->sql[1] );
		$this->assertStringContainsString( "refresh_if_stale( \$post_id, (string) \$row['scanned_at'] )", (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Editor_Box.php' ), 'the editor box passes the row it fetched' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source check.
		$this->assertStringContainsString( '->get( $post_id, false )', (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Editor_Box.php' ), 'the tick handler reads the lean row' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source check.
	}

	/**
	 * (2) A scan write that fails (the column the table update adds is missing) is saved again without the
	 * text: a failed ALTER never stops every scan write.
	 */
	public function test_scan_write_survives_a_missing_column(): void {
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';
			/** @var string */
			public $last_error = '';
			/** @var array<int,array{0:string,1:array<int,mixed>}> */
			public $queries = [];

			/**
			 * Pass through.
			 *
			 * @param string $q    SQL.
			 * @param mixed  ...$a Values.
			 * @return array{0:string,1:array<int,mixed>}
			 */
			public function prepare( $q, ...$a ): array {
				return [ $q, $a ];
			}

			/**
			 * Fails when the SQL names body_text, as a table without the column does.
			 *
			 * @param array{0:string,1:array<int,mixed>} $p Prepared.
			 * @return int|false
			 */
			public function query( $p ) {
				$this->queries[] = $p;
				if ( false !== strpos( $p[0], 'body_text' ) ) {
					$this->last_error = "Unknown column 'body_text' in 'field list'";
					return false;
				}
				return 1;
			}
		};
		( new Scan_Store() )->save_facts( 3, '/a/', 'page', 'rendered', [ Html_Parser::TEXT => 'Words', 'words' => 1 ] );
		$q = $GLOBALS['wpdb']->queries;
		$this->assertCount( 2, $q, 'tried with the text, then without' );
		$this->assertStringNotContainsString( 'body_text', $q[1][0] );
		$this->assertSame( '{"words":1}', $q[1][1][6], 'the facts are saved' );
		$this->assertCount( 7, $q[1][1] );
	}

	/**
	 * (3) A page longer than the scan keeps: flagged; a phrase not in the kept part is unknown (a manual
	 * tick that says why), never "missing"; found is still found.
	 */
	public function test_cut_short_page_phrase_is_unknown(): void {
		$long  = str_repeat( 'filler words here ', (int) ( Html_Parser::MAX_TEXT / 10 ) ) . 'cost of living in boise';
		$facts = Html_Parser::parse( '<html><body><main><p>' . $long . '</p></main></body></html>', 'https://x.test/' );
		$this->assertTrue( $facts[ Html_Parser::TRUNCATED ] );
		$this->assertSame( Html_Parser::MAX_TEXT, mb_strlen( $facts[ Html_Parser::TEXT ] ) );
		$this->assertFalse( Html_Parser::parse( '<html><body><main><p>Short.</p></main></body></html>', 'https://x.test/' )[ Html_Parser::TRUNCATED ] );

		$cost = [ 'check' => 'phrase', 'targets' => [ 'cost of living in boise' ], 'sources' => [] ];
		$this->assertSame( Editor_Check::UNKNOWN, Editor_Check::verdict( $cost, [], $facts[ Html_Parser::TEXT ], [], true ), 'maybe further down: unknown' );
		$this->assertSame( Editor_Check::NO, Editor_Check::verdict( $cost, [], 'Short page.', [], false ), 'a whole page without it: not there' );
		$this->assertSame( Editor_Check::YES, Editor_Check::verdict( $cost, [], 'The cost of living in Boise.', [], true ), 'found in the kept part: there' );
		$this->assertFalse( Editor_Check::in_place( $cost, [], $facts[ Html_Parser::TEXT ], [], true ), 'unknown is never done' );

		$row     = [
			'scanned_at'  => '2026-10-06 12:00:00',
			'issues'      => [],
			'suggestions' => [ 'editor' => [ [ 'area' => 'content', 'advice' => 'Add a section answering "cost of living in boise".' ] ] ],
		];
		$context = [
			'facts'     => [],
			'text'      => $facts[ Html_Parser::TEXT ],
			'truncated' => true,
			'inbound'   => [],
		];
		$item    = Editor_Box::items( $row, [], $context )[0];
		$this->assertNull( $item['done'] );
		$this->assertStringContainsString( 'too long to check automatically', $item['note'] );
		$context['truncated'] = false;
		$this->assertSame( '', Editor_Box::items( $row, [], $context )[0]['note'], 'a whole page: no note' );
	}

	/**
	 * (4) A pattern that fails returns null: visible_text() keeps the step's input (tags stripped) instead
	 * of turning the page into ''.
	 */
	public function test_visible_text_survives_a_failed_pattern(): void {
		$jit   = ini_get( 'pcre.jit' );
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restored below.
		ini_set( 'pcre.backtrack_limit', '1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restored below.
		try {
			$this->assertNull( preg_replace( '@<(script|style)[^>]*>.*?</\1>@si', ' ', '<script>x</script><p>a</p>' ), 'the limit really makes the pattern fail' );
			$text = Utils::visible_text( '<script>var a;</script><p>The cost of living in <strong>Boise</strong>.</p>' );
		} finally {
			ini_set( 'pcre.jit', (string) $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			ini_set( 'pcre.backtrack_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		$this->assertMatchesRegularExpression( '/The cost of living in\s+Boise/', $text, 'the words kept, not an empty page' );
		$this->assertStringNotContainsString( '<p>', $text, 'tags still stripped' );
	}
}
