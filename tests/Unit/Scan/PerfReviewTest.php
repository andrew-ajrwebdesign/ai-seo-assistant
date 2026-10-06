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
	 * (1) The site-wide pass builds who links to whom once; each page's list is written only when its hash
	 * changed, and never before its column exists. The editor reads that one list: no other page's facts.
	 * Verify A2: the same link twice counts once, before the cap.
	 */
	public function test_inbound_built_once_and_read_per_page(): void {
		$rows = [
			1 => [
				'path'         => '/guide/',
				'facts'        => [ 'links' => [ [ 'p' => '/map/', 't' => 'Boise neighborhoods map' ], [ 'p' => '/map/', 't' => 'Boise neighborhoods map' ], [ 'p' => '/guide/', 't' => 'self' ] ] ],
				'inbound_hash' => '',
			],
			2 => [
				'path'         => '/map/',
				'facts'        => [ 'links' => [ [ 'p' => '/Guide', 't' => 'Back to the guide' ] ] ],
				'inbound_hash' => 'stale0000000',
			],
			3 => [
				'path'         => '/new/',
				'facts'        => [ 'links' => [ [ 'p' => '/map/', 't' => 'Map' ] ] ],
				'inbound_hash' => null,
			],
		];
		$map  = Scanner::inbound_map( $rows );
		$this->assertSame( [ [ '/guide/', 'Boise neighborhoods map' ], [ '/new/', 'Map' ] ], $map['/map/'], 'the repeated link counted once' );
		$this->assertSame( [ [ '/map/', 'Back to the guide' ] ], $map['/guide/'], 'paths compared normalised; a page\'s link to itself left out' );

		$store = new Inbound_Store();
		( new Scanner( $store ) )->save_inbound( $rows );
		$this->assertSame( [ 1, 2 ], array_keys( $store->written ), '/new/ has no column yet: not written' );
		$this->assertSame( [ [ '/map/', 'Back to the guide' ] ], $store->written[1] );

		// Unchanged lists (same hash) are not rewritten.
		$rows[1]['inbound_hash'] = Scan_Store::inbound_hash( $store->written[1] );
		$rows[2]['inbound_hash'] = Scan_Store::inbound_hash( $store->written[2] );
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
		$this->assertTrue( $context['inbound_complete'] );
		$link = [
			'area'   => 'links',
			'advice' => 'Link from the guide with "Boise neighborhoods map".',
			'check'  => 'link',
			'target' => 'Boise neighborhoods map',
			'source' => '/guide/',
		];
		$this->assertSame( Editor_Check::YES, Page_Review::advice_verdict( $link, $context ) );
		$other = [ 'target' => 'Some other words' ] + $link;
		$this->assertSame( Editor_Check::NO, Page_Review::advice_verdict( $other, $context ), 'every link known: not there' );
	}

	/**
	 * Verify A2: a link check on a page whose list is not built yet, or past the cap, cannot be told; the
	 * panel says why and the to-do stays a manual tick.
	 */
	public function test_link_check_unknown_when_the_list_is_incomplete(): void {
		$link    = [
			'area'   => 'links',
			'advice' => 'Link from the guide with "Boise neighborhoods map".',
			'check'  => 'link',
			'target' => 'Boise neighborhoods map',
			'source' => '/guide/',
		];
		$unbuilt = Page_Review::editor_context(
			2,
			[
				'path'      => '/map/',
				'body_text' => 'Words',
				'inbound'   => null,
			]
		);
		$this->assertFalse( $unbuilt['inbound_complete'] );
		$this->assertSame( Editor_Check::UNKNOWN, Page_Review::advice_verdict( $link, $unbuilt ) );
		$this->assertStringContainsString( 'Not every link to this page could be checked', Page_Review::unknown_note( $link, $unbuilt ) );

		$many = [];
		for ( $i = 0; $i <= Scanner::MAX_INBOUND; $i++ ) {
			$many[] = [ '/p' . $i . '/', 'Other words' ];
		}
		$full = Page_Review::editor_context(
			2,
			[
				'path'      => '/map/',
				'body_text' => 'Words',
				'inbound'   => $many,
			]
		);
		$this->assertFalse( $full['inbound_complete'], 'one past the cap: the list is not complete' );
		$this->assertCount( Scanner::MAX_INBOUND, $full['inbound'] );
		$this->assertSame( Editor_Check::UNKNOWN, Page_Review::advice_verdict( $link, $full ) );

		$rows = [];
		for ( $i = 0; $i <= Scanner::MAX_INBOUND + 5; $i++ ) {
			$rows[ $i + 10 ] = [
				'path'  => '/p' . $i . '/',
				'facts' => [ 'links' => [ [ 'p' => '/map/', 't' => 'x' ] ] ],
			];
		}
		$this->assertCount( Scanner::MAX_INBOUND + 1, Scanner::inbound_map( $rows )['/map/'], 'the pass keeps one past the cap to say so' );

		// The review's editor box shows the same note.
		$html = \AJR\SEOAssistant\Admin\Scan_Page::editor_box_item( $link, $unbuilt, 'Links', '6 Oct', '' );
		$this->assertStringContainsString( 'Not every link to this page could be checked', $html );
	}

	/**
	 * (1) The editor panel reads the scan row once: the staleness check takes its scanned_at, so it is not
	 * fetched again (a behaviour test: render() against a counting $wpdb).
	 */
	public function test_editor_panel_fetches_the_row_once(): void {
		if ( ! class_exists( '\WP_Post' ) ) {
			eval( 'class WP_Post { public $ID = 0; public $post_type = "page"; public $post_status = "publish"; public $post_password = ""; public $post_modified_gmt = ""; public $post_content = ""; public $post_excerpt = ""; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a test stand-in for core's class.
		}
		$post                    = new \WP_Post();
		$post->ID                = 5;
		$post->post_modified_gmt = '2026-10-06 10:00:00';
		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'https://x.test/wp-admin/admin.php?page=x' );
		\WP_Mock::userFunction( 'admin_url' )->andReturn( 'https://x.test/wp-admin/admin.php' );
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => $d );
		\WP_Mock::userFunction( 'get_post_meta' )->andReturn( '' );
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';
			/** @var int */
			public $rows = 0;
			/** @var int */
			public $vars = 0;

			/**
			 * Pass through.
			 *
			 * @param string $q    SQL.
			 * @param mixed  ...$a Values.
			 */
			public function prepare( $q, ...$a ): string {
				return (string) $q;
			}

			/**
			 * The row, counted.
			 *
			 * @return array<string,mixed>
			 */
			public function get_row() {
				++$this->rows;
				return [
					'post_id'     => 5,
					'path'        => '/x/',
					'scanned_at'  => '2026-10-06 12:00:00',
					'issue_count' => 0,
					'facts'       => '{}',
					'issues'      => '[]',
					'suggestions' => null,
					'body_text'   => 'Words',
					'inbound'     => '[]',
				];
			}

			/**
			 * Counted.
			 */
			public function get_var() {
				++$this->vars;
				return null;
			}
		};
		ob_start();
		( new Editor_Box() )->render( $post );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-aisa-todo-box', $html, 'rendered' );
		$this->assertSame( 1, $GLOBALS['wpdb']->rows, 'one row read' );
		$this->assertSame( 0, $GLOBALS['wpdb']->vars, 'no second read for the staleness check' );
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
		\WP_Mock::userFunction( 'get_option' )->andReturn( \AJR\SEOAssistant\Core\Schema::VERSION ); // Marked current, the column still missing.
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
