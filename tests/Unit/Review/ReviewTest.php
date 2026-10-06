<?php
/**
 * Tests for the review's money and history rules: the spend cap by billing month, alt text never replacing
 * a good alt, apply/undo with the change log, and the 4-weeks-before/after measurement.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Review;

use AJR\SEOAssistant\AI\Spend;
use AJR\SEOAssistant\Changes\Change_Log;
use AJR\SEOAssistant\Review\Page_Review;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * An SEO plugin adapter held in memory.
 */
class Fake_Adapter {
	const TITLE_FIELD       = '_t';
	const DESCRIPTION_FIELD = '_d';
	/** @var array<string,string> */
	public $meta = [];
	public function get_name() {
		return 'Yoast SEO';
	}
	public function get_title( $id ) {
		return $this->meta['_t'] ?? '';
	}
	public function get_description( $id ) {
		return $this->meta['_d'] ?? '';
	}
	public function get_keyphrase( $id ) {
		return $this->meta['_k'] ?? '';
	}
	public function supports_keyphrase() {
		return true;
	}
	public function save_title( $id, $v ) {
		$this->meta['_t'] = $v;
	}
	public function save_description( $id, $v ) {
		$this->meta['_d'] = $v;
	}
	public function save_keyphrase( $id, $v ) {
		$this->meta['_k'] = $v;
	}
}

/**
 * The scan row held in memory.
 */
class Fake_Store extends Scan_Store {
	/** @var array<string,mixed>|null */
	public $row;
	public function get( int $post_id ): ?array {
		return $this->row;
	}
	public function save_suggestions( int $post_id, ?array $suggestions ): void {
		$this->row['suggestions'] = $suggestions;
	}
}

/**
 * The change log held in memory.
 */
class Fake_Log extends Change_Log {
	/** @var array<int,array<string,mixed>> */
	public $rows = [];
	/** @var array<int,string> Fields whose row the "database" refuses (insert fails). */
	public $refuse = [];
	/** @var int */
	public $next = 0;
	public function log( string $batch, int $post_id, string $path, string $field, int $object_id, string $before, string $after, int $user_id ): int {
		if ( in_array( $field, $this->refuse, true ) ) {
			return 0;
		}
		$id                = ++$this->next;
		$this->rows[ $id ] = compact( 'id', 'batch', 'post_id', 'path', 'field', 'object_id', 'user_id' ) + [
			'before_value' => $before,
			'after_value'  => $after,
			'applied_at'   => '2026-10-06 10:00:00',
			'undone_at'    => null,
			'undone_by'    => 0,
			'effect'       => null,
		];
		return $id;
	}
	public function get( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}
	public function mark_undone( int $id, int $user_id ): bool {
		$this->rows[ $id ]['undone_at'] = '2026-10-06 11:00:00';
		return true;
	}
	public function discard( int $id ): bool {
		unset( $this->rows[ $id ] );
		return true;
	}
}

/**
 * Page_Review without the loopback rescan (Scanner is tested on its own).
 */
class Testable_Review extends Page_Review {
	/** @var int */
	public $rescans = 0;
	protected function rescan( int $post_id, array $known = [] ): void {
		++$this->rescans;
	}
}

/**
 * Review rules.
 */
class ReviewTest extends TestCase {
	use Wp_Basics;

	/**
	 * Attachment alt meta.
	 *
	 * @var array<int,string>
	 */
	protected array $alts = [];

	/**
	 * The page's post content.
	 *
	 * @var string
	 */
	protected string $content = '';

	/**
	 * WP basics and post meta in memory.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_basics();
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $s ) => trim( (string) $s ) );
		\WP_Mock::userFunction( 'sanitize_textarea_field' )->andReturnUsing( fn( $s ) => trim( (string) $s ) );
		\WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturn( '7e3ff683-62c6-4e00-8000-000000000000' );
		\WP_Mock::userFunction( 'get_post_type' )->andReturn( 'attachment' );
		\WP_Mock::userFunction( 'wp_slash' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'clean_post_cache' );
		\WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing( fn( $id ) => $this->alts[ $id ] ?? '' );
		\WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			function ( $id, $key, $value ) {
				$this->alts[ $id ] = $value;
				return true;
			}
		);
	}

	/**
	 * ⛔ A good alt (printed, or in the Media Library) is only CHECKED, never rewritten; missing and poor
	 * ones are written (Andrew, 2026-10-06: "Boise's Capitol Building" replaced by a guess).
	 */
	public function test_alt_text_never_replaces_a_good_alt(): void {
		$facts  = [
			'images' => [
				[
					'id'         => 489,
					'file'       => 'boise_summer_sized.jpg',
					'alt'        => null,
					'stored_alt' => 'Boise\'s Capitol Building',
				],
				[
					'id'         => 9,
					'file'       => 'team-van.jpg',
					'alt'        => 'van',
					'stored_alt' => '',
				],
				[
					'id'         => 10,
					'file'       => 'IMG_2041.jpg',
					'alt'        => null,
					'stored_alt' => 'IMG_2041',
				],
			],
		];
		$facts['images'][] = [
			'id'         => 491,
			'file'       => 'boise_spring_sized.jpg',
			'alt'        => 'Boise Depot clock tower behind spring flowers',
			'stored_alt' => 'Boise Depot clock tower behind spring flowers',
		];
		$facts['images'][] = [
			'id'         => 404,
			'file'       => 'Boise-Mid-Century-Home.jpg',
			'alt'        => 'Moving To Boise Services',
			'stored_alt' => 'White kitchen with a gas range',
		];
		$images = Page_Review::images_needing_alt( $facts );
		$modes  = array_column( $images, 'mode', 'id' );
		$ticks  = array_column( $images, 'tick', 'id' );
		$this->assertSame( 'sync', $modes[489], 'a good library alt the page does not print' );
		$this->assertTrue( $ticks[489], 'the page prints nothing: ticked' );
		$this->assertSame( 'sync', $modes[404], 'the page prints a different alt' );
		$this->assertFalse( $ticks[404], 'a descriptive printed alt is never replaced unticked-by-default' );
		$this->assertSame( 'check', $modes[491], 'page and library agree: only checked against the photo' );
		$this->assertFalse( $ticks[491] );
		$this->assertSame( 'write', $modes[9] );
		$this->assertSame( 'write', $modes[10] );
		$this->assertSame( 'write', $images[0]['mode'], 'missing alts first' );
		$this->assertSame( 'Boise\'s Capitol Building', array_column( $images, 'alt', 'id' )[489], 'the old alt is shown' );
		$this->assertTrue( Page_Review::same_alt( '“Discover Boise” guide', '"discover boise"  GUIDE' ) );
	}

	/**
	 * Apply writes title, description, keyphrase and ticked alt texts, logs each with before/after, rescans
	 * the page; Undo restores "before", but never over a value changed since.
	 */
	public function test_apply_and_undo(): void {
		$adapter       = new Fake_Adapter();
		$adapter->meta = [ '_t' => 'Old title' ];
		$this->alts    = [ 491 => '' ];
		$store         = new Fake_Store();
		$store->row    = [
			'path'        => '/boise-area-weather/',
			'suggestions' => [
				'title'       => [ 'value' => 'Boise Idaho Weather | Snow, Sun & Seasons' ],
				'description' => [ 'value' => 'Boise gets about 18 inches of snow a year and hot dry summers.' ],
				'keyphrase'   => [ 'value' => 'boise idaho weather' ],
				'alts'        => [
					[
						'id'    => 491,
						'value' => 'Boise Depot clock tower behind spring flowers',
					],
				],
				'editor'      => [],
			],
		];
		$store->row['suggestions']['alts'][0]['src'] = 'https://x.test/wp-content/uploads/boise_spring_sized.jpg';
		$this->content = '[et_pb_image src="https://x.test/wp-content/uploads/boise_spring_sized.jpg" alt="Boise Depot - Moving To Boise" _builder_version="4.27"][/et_pb_image]';
		$original      = $this->content;
		\WP_Mock::userFunction( 'get_post_field' )->andReturnUsing( fn() => $this->content );
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			function ( $post ) {
				$this->content = $post['post_content'];
				return 1;
			}
		);
		$log    = new Fake_Log();
		$review = new Testable_Review( new \AJR\SEOAssistant\AI\Claude_Client(), $store, $log, $adapter );

		$result = $review->apply(
			384,
			[
				'title'       => [
					'action' => 'edit',
					'value'  => 'Boise Idaho Weather | Snow, Sun & Seasons',
				],
				'description' => [ 'action' => 'skip' ],
				'keyphrase'   => [
					'action' => 'edit',
					'value'  => 'boise idaho weather',
				],
				'alts'        => [
					491 => [
						'apply' => true,
						'value' => 'Boise Depot clock tower behind spring flowers',
					],
				],
			],
			3
		);
		$this->assertSame( 3, $result['applied'], 'title, keyphrase, one alt; the skipped description untouched' );
		$this->assertSame( 'Boise Idaho Weather | Snow, Sun & Seasons', $adapter->meta['_t'] );
		$this->assertArrayNotHasKey( '_d', $adapter->meta );
		$this->assertSame( 'Boise Depot clock tower behind spring flowers', $this->alts[491] );
		$this->assertSame( [ 'title', 'keyphrase', 'alt', 'content' ], array_column( $log->rows, 'field' ) );
		$this->assertSame( str_replace( 'alt="Boise Depot - Moving To Boise"', 'alt="Boise Depot clock tower behind spring flowers"', $original ), $this->content, 'the alt is written where the page prints it, nothing else' );
		$this->assertSame( $original, $log->rows[4]['before_value'] );
		$this->assertFalse( $store->row['suggestions']['applied']['alts'][491]['visible'], 'not seen on the rendered page (no rescan here): never reported as visible' );
		$this->assertSame( 'Old title', $log->rows[1]['before_value'] );
		$this->assertSame( 491, $log->rows[3]['object_id'] );
		$this->assertSame( 1, $review->rescans, 'rescanned right away' );
		$this->assertSame( $result['batch'], $store->row['suggestions']['applied']['batch'] );

		// Someone edits the title by hand afterwards: undo keeps their work and says so.
		$adapter->meta['_t'] = 'Hand-edited title';
		$undo                = $review->undo( [ 1, 2, 3, 4 ], 3 );
		$this->assertSame( 3, $undo['undone'] );
		$this->assertSame( $original, $this->content, 'post content restored byte for byte' );
		$this->assertSame( [ 'title' ], $undo['kept'] );
		$this->assertSame( 'Hand-edited title', $adapter->meta['_t'] );
		$this->assertSame( '', $adapter->meta['_k'], 'keyphrase back to empty' );
		$this->assertSame( '', $this->alts[491], 'alt back to before' );
		$this->assertNull( $log->rows[1]['undone_at'] );
		$this->assertNotNull( $log->rows[2]['undone_at'] );
	}

	/**
	 * A 200 KB builder page: the content row is logged before the page is written, and Undo puts it back
	 * byte for byte. The change log's columns are longtext (a text column stops at 64 KB).
	 */
	public function test_large_page_apply_and_undo_byte_identical(): void {
		$adapter       = new Fake_Adapter();
		$this->alts    = [ 491 => '' ];
		$filler        = str_repeat( '[et_pb_text]Boise has four real seasons, and every one of them is worth a visit. [/et_pb_text]', 2400 );
		$this->content = $filler . '[et_pb_image src="https://x.test/wp-content/uploads/boise_spring_sized.jpg" alt="old" image_id="491"][/et_pb_image]' . $filler;
		$this->assertGreaterThan( 200 * 1024, strlen( $this->content ) );
		$original = $this->content;
		$store    = new Fake_Store();
		$store->row = [
			'path'        => '/boise-area-weather/',
			'suggestions' => [
				'alts'   => [
					[
						'id'    => 491,
						'value' => 'Boise Depot clock tower behind spring flowers',
						'src'   => 'https://x.test/wp-content/uploads/boise_spring_sized.jpg',
					],
				],
				'editor' => [],
			],
		];
		\WP_Mock::userFunction( 'get_post_field' )->andReturnUsing( fn() => $this->content );
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			function ( $post ) {
				$this->content = $post['post_content'];
				return 1;
			}
		);
		$log    = new Fake_Log();
		$review = new Testable_Review( new \AJR\SEOAssistant\AI\Claude_Client(), $store, $log, $adapter );
		$review->apply( 384, [ 'alts' => [ 491 => [ 'apply' => true ] ] ], 3 );
		$content_row = array_values( array_filter( $log->rows, static fn( $r ) => 'content' === $r['field'] ) )[0];
		$this->assertSame( $original, $content_row['before_value'], 'the whole 200 KB page is in the log' );
		$this->assertNotSame( $original, $this->content );

		$undo = $review->undo( array_keys( $log->rows ), 3 );
		$this->assertSame( [], $undo['kept'] );
		$this->assertSame( md5( $original ), md5( $this->content ), 'byte-identical after Undo' );

		// The schema the log lives in holds it: longtext, not text (64 KB).
		$GLOBALS['aisa_dbdelta'] = [];
		global $wpdb;
		$wpdb = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Charset.
			 */
			public function get_charset_collate() {
				return '';
			}
		};
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );
		\AJR\SEOAssistant\Core\Schema::install();
		$changes = implode( "\n", (array) $GLOBALS['aisa_dbdelta'] );
		$this->assertStringContainsString( 'before_value longtext NOT NULL', $changes );
		$this->assertStringContainsString( 'after_value longtext NOT NULL', $changes );
	}

	/**
	 * A content change the log refuses is never made (it could not be undone).
	 */
	public function test_content_not_written_when_log_fails(): void {
		$adapter       = new Fake_Adapter();
		$this->alts    = [ 491 => '' ];
		$this->content = '[et_pb_image src="https://x.test/wp-content/uploads/boise_spring_sized.jpg" alt="old"][/et_pb_image]';
		$original      = $this->content;
		$store         = new Fake_Store();
		$store->row    = [
			'path'        => '/x/',
			'suggestions' => [
				'alts'   => [
					[
						'id'    => 491,
						'value' => 'Boise Depot',
						'src'   => 'https://x.test/wp-content/uploads/boise_spring_sized.jpg',
					],
				],
				'editor' => [],
			],
		];
		\WP_Mock::userFunction( 'get_post_field' )->andReturnUsing( fn() => $this->content );
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		$log         = new Fake_Log();
		$log->refuse = [ 'content' ];
		$review      = new Testable_Review( new \AJR\SEOAssistant\AI\Claude_Client(), $store, $log, $adapter );
		$review->apply( 384, [ 'alts' => [ 491 => [ 'apply' => true ] ] ], 3 );
		$this->assertSame( $original, $this->content, 'page untouched' );
		$this->assertFalse( $store->row['suggestions']['applied']['alts'][491]['visible'] );
		$this->assertStringContainsString( 'change log', $store->row['suggestions']['applied']['alts'][491]['reason'] );
	}

	/**
	 * Measurement: CTR in the 4 full weeks after against the 4 before; the change's own week left out.
	 */
	public function test_measurement(): void {
		$weeks = [];
		for ( $i = 0; $i < 13; $i++ ) {
			$weeks[] = [
				'start'       => gmdate( 'Y-m-d', strtotime( '2026-07-06 UTC' ) + $i * 7 * 86400 ),
				'clicks'      => $i < 6 ? 5 : 15,
				'impressions' => 1000,
				'position'    => 8.0,
			];
		}
		$applied = (int) strtotime( '2026-08-12 10:00 UTC' ); // Wednesday of the week starting 10 Aug (index 5).
		$e       = Change_Log::measure( $applied, $weeks );
		$this->assertSame( 'measured', $e['state'] );
		$this->assertSame( 0.5, $e['ctr_before'] );
		$this->assertSame( 1.5, $e['ctr_after'] );
		$this->assertSame( 'better', $e['verdict'] );
		$this->assertSame( 40, $e['extra_clicks'] );

		$waiting = Change_Log::measure( (int) strtotime( '2026-09-23 10:00 UTC' ), $weeks );
		$this->assertSame( 'waiting', $waiting['state'] );
		$this->assertSame( '2026-10-21', $waiting['from'] );
		$this->assertSame( 'no_data', Change_Log::measure( (int) strtotime( '2026-07-01 UTC' ), $weeks )['state'] );
	}

	/**
	 * Spend: priced from usage; the billing month runs from the anchor day; the cap check keeps a reserve.
	 */
	public function test_spend_cap_accounting(): void {
		$usage = [
			'input_tokens'                => 7200,
			'output_tokens'               => 1000,
			'cache_read_input_tokens'     => 1000,
			'cache_creation_input_tokens' => 0,
		];
		$this->assertEqualsWithDelta( ( 7200 * 5 + 1000 * 0.5 + 1000 * 25 ) / 1e6, Spend::cost( 'claude-opus-5', $usage ), 1e-9 );
		$this->assertEqualsWithDelta( ( 7200 * 2 + 1000 * 0.2 + 1000 * 10 ) / 1e6, Spend::cost( 'claude-sonnet-5-20260101', $usage ), 1e-9, 'dated IDs priced by family' );
		$this->assertSame( Spend::cost( 'claude-opus-5', $usage ), Spend::cost( 'some-new-model', $usage ), 'unknown models priced as the dearest' );

		$utc = new \DateTimeZone( 'UTC' );
		$this->assertSame( '2026-09-17', Spend::period_start( 17, (int) strtotime( '2026-10-06 UTC' ), $utc )->format( 'Y-m-d' ) );
		$this->assertSame( '2026-10-17', Spend::period_end( 17, (int) strtotime( '2026-10-06 UTC' ), $utc )->format( 'Y-m-d' ) );
		$this->assertSame( '2026-10-17', Spend::period_start( 17, (int) strtotime( '2026-10-17 UTC' ), $utc )->format( 'Y-m-d' ), 'the anchor day starts a new month' );
		$this->assertSame( '2026-09-30', Spend::period_start( 31, (int) strtotime( '2026-10-05 UTC' ), $utc )->format( 'Y-m-d' ), 'a 31st anchor falls on the 30th in September' );

		// The reserve is the call's worst case: max_tokens of output + expected input, at the model's price.
		$worst = ( 16000 * 25 + 14000 * 5 ) / 1e6;
		$this->assertEqualsWithDelta( $worst, Spend::reserve( 'review', 'claude-opus-5' ), 1e-9 );
		$this->assertEqualsWithDelta( ( 8000 * 5 + 6000 * 1 ) / 1e6, Spend::reserve( 'intent' ), 1e-9, 'the intent pass is always Haiku' );
		$this->assertTrue( Spend::allows( 10.0 - $worst - 0.01, 10.0, 'review', 'claude-opus-5' ) );
		$this->assertFalse( Spend::allows( 10.0 - $worst + 0.01, 10.0, 'review', 'claude-opus-5' ), 'no call may start whose worst case would pass the cap' );
		$this->assertFalse( Spend::allows( 0.0, 0.0, 'test' ), 'a zero cap stops everything' );
	}
}
