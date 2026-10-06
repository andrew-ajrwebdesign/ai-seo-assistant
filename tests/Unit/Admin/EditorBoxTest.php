<?php
/**
 * The editor's "SEO to-do for this page": what it lists, a Done tick collapsing an item until a rescan,
 * an item a later scan still finds opening again, and the tick's guard.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Admin;

use AJR\SEOAssistant\Admin\Editor_Box;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * Editor box.
 */
class EditorBoxTest extends TestCase {
	use Wp_Basics;

	/**
	 * WP basics.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_basics();
	}

	/**
	 * A scan row: two editor findings, one Claude finding, one schema finding, and the review's advice.
	 *
	 * @return array<string,mixed>
	 */
	protected static function row(): array {
		return [
			'scanned_at'  => '2026-10-06 12:00:00',
			'issues'      => [
				[ 'kind' => 'headings', 'code' => 'h_order', 'who' => 'editor', 'title' => 'Heading order skips a level', 'fix' => 'Fix: make it an H3.' ],
				[ 'kind' => 'links', 'code' => 'orphan', 'who' => 'editor', 'title' => 'No other page links here', 'fix' => 'Fix: link to it.' ],
				[ 'kind' => 'title', 'code' => 'title_wide', 'who' => 'claude', 'title' => 'Title is too wide', 'fix' => '' ],
				[ 'kind' => 'weight', 'code' => 'heavy', 'who' => 'editor', 'title' => '1 heavy image', 'fix' => '' ],
			],
			'suggestions' => [
				'editor' => [
					[ 'area' => 'links', 'advice' => 'Link here from the Neighborhoods page with "Boise schools".' ],
					[ 'area' => 'content', 'advice' => 'Add a paragraph on enrolment dates.' ],
				],
			],
		];
	}

	/**
	 * Headings, links and content only (never title, alt or image weight); a finding carries the review's
	 * advice for its area; advice without a finding is its own to-do.
	 */
	public function test_lists_editor_work_only(): void {
		$items = Editor_Box::items( self::row(), [] );
		$this->assertSame( [ 'issue:h_order', 'issue:orphan', 'advice:' . substr( md5( 'thin|Add a paragraph on enrolment dates.' ), 0, 12 ) ], array_column( $items, 'key' ) );
		$this->assertSame( 'Link here from the Neighborhoods page with "Boise schools".', $items[1]['detail'], 'the review\'s advice for the finding' );
		$this->assertSame( 'Fix: make it an H3.', $items[0]['detail'], 'no advice: the scan\'s fix' );
		$this->assertSame( [ null, null, null ], array_column( $items, 'done' ) );
	}

	/**
	 * Done before the last scan and still found: open again with a note. Done after it: collapsed until the
	 * next scan. A finding the scan no longer reports is simply not listed.
	 */
	public function test_done_collapses_until_a_rescan(): void {
		$scan  = (int) strtotime( '2026-10-06 12:00:00 UTC' );
		$items = Editor_Box::items(
			self::row(),
			[
				'issue:h_order' => $scan + 60,  // Ticked after the scan: waiting for the next one.
				'issue:orphan'  => $scan - 600, // Ticked, then scanned, still there: open again.
				'issue:gone'    => $scan - 600, // No longer found: not listed.
			]
		);
		$by    = array_column( $items, null, 'key' );
		$this->assertSame( $scan + 60, $by['issue:h_order']['done'] );
		$this->assertNull( $by['issue:orphan']['done'] );
		$this->assertStringStartsWith( 'Still found by the scan of', $by['issue:orphan']['note'] );
		$this->assertArrayNotHasKey( 'issue:gone', $by );
	}

	/**
	 * The tick: tools capability and edit_post, a known key shape, done ticks for findings the page no
	 * longer has pruned, the page queued for a rescan.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_done_tick(): void {
		$meta  = [ '_aisa_todo_done' => [ 'issue:gone' => 1 ] ];
		$queue = [];
		\WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
		\WP_Mock::userFunction( 'absint' )->andReturnUsing( fn( $v ) => abs( (int) $v ) );
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing( fn( $id, $k ) => $meta[ $k ] ?? '' );
		\WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			function ( $id, $k, $v ) use ( &$meta ) {
				$meta[ $k ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => 'ai_seo_assistant_scan_queue' === $n ? ( [] === $queue ? $d : $queue ) : $d );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $n, $v ) use ( &$queue ) {
				if ( 'ai_seo_assistant_scan_queue' === $n ) {
					$queue = $v;
				}
				return true;
			}
		);
		\WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( 1 );
		\WP_Mock::userFunction( 'wp_send_json_success' )->andReturnUsing(
			function () {
				throw new \RuntimeException( 'success' );
			}
		);
		\WP_Mock::userFunction( 'wp_send_json_error' )->andReturnUsing(
			function ( $d, $code ) {
				throw new \RuntimeException( 'error ' . $code );
			}
		);
		$GLOBALS['wpdb'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.
			/** @var string */
			public $prefix = 'wp_';
			public function prepare( $q, ...$a ) {
				return $q;
			}
			public function get_row( $q, $o ) {
				return [ 'post_id' => 9, 'path' => '/x/', 'facts' => '{}', 'issues' => json_encode( [ [ 'kind' => 'headings', 'code' => 'h_order', 'who' => 'editor', 'title' => 'T', 'fix' => '' ] ] ), 'suggestions' => null, 'issue_count' => 1, 'scanned_at' => '2026-10-06 12:00:00' ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.
			}
		};
		$box = new Editor_Box();

		$_POST = [ 'post' => '9', 'key' => '<script>' ];
		try {
			$box->ajax_done();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'error 400', $e->getMessage(), 'an unknown key shape is refused' );
		}

		$_POST = [ 'post' => '9', 'key' => 'issue:h_order' ];
		try {
			$box->ajax_done();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'success', $e->getMessage() );
		}
		$this->assertSame( [ 'issue:h_order' ], array_keys( $meta['_aisa_todo_done'] ), 'a tick for a finding the page no longer has is pruned' );
		$this->assertSame( [ 9 ], $queue['ids'], 'the page is queued for a rescan' );
	}
}
