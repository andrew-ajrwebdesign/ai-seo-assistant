<?php
/**
 * Tests for Report\Snapshot_Store.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Report;

use AJR\SEOAssistant\Report\Snapshot_Store;
use WP_Mock\Tools\TestCase;

/**
 * Snapshot storage, with the options table faked in memory.
 */
class SnapshotStoreTest extends TestCase {

	/**
	 * The fake options table.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Autoload flags passed to update_option.
	 *
	 * @var array<string,mixed>
	 */
	protected array $autoload = [];

	/**
	 * Wire get_option / update_option to the in-memory table.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->options  = [];
		$this->autoload = [];
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $name, $fallback = false ) => $this->options[ $name ] ?? $fallback );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
	}

	/**
	 * A minimal clean snapshot for a week.
	 *
	 * @param string $start     Monday.
	 * @param int    $generated generated_at.
	 * @param int    $calls     A count, to tell versions apart.
	 * @return array<string,mixed>
	 */
	protected function week( string $start, int $generated = 1000, int $calls = 5 ): array {
		return [
			'schema'       => 1,
			'site'         => 'example.com',
			'week'         => [
				'start' => $start,
				'end'   => gmdate( 'Y-m-d', strtotime( $start . ' +6 days' ) ),
			],
			'generated_at' => $generated,
			'enquiries'    => [
				'sources'    => [
					[
						'key'      => 'calls',
						'label'    => 'Calls',
						'count'    => $calls,
						'previous' => null,
					],
				],
				'series_12w' => [],
			],
			'note'         => null,
			'gsc'          => null,
			'ga4'          => null,
			'ads'          => null,
		];
	}

	/**
	 * Stored, newest first, never autoloaded; the push time is recorded.
	 */
	public function test_put_and_read(): void {
		$store = new Snapshot_Store();
		$this->assertNull( $store->latest() );
		$this->assertSame( 0, $store->last_push() );

		$this->assertSame( 'stored', $store->put( $this->week( '2026-09-14' ), 100 ) );
		$this->assertSame( 'stored', $store->put( $this->week( '2026-09-21' ), 200 ) );

		$this->assertSame( [ '2026-09-21', '2026-09-14' ], array_keys( $store->all() ) );
		$this->assertSame( '2026-09-21', $store->latest()['week']['start'] );
		$this->assertSame( 5, $store->get( '2026-09-14' )['enquiries']['sources'][0]['count'] );
		$this->assertNull( $store->get( '2026-01-05' ) );
		$this->assertSame( 200, $store->last_push() );
		$this->assertFalse( $this->autoload[ Snapshot_Store::OPTION ], 'never autoloaded' );
		$this->assertFalse( $this->autoload[ Snapshot_Store::LAST_PUSH ] );
	}

	/**
	 * A newer report for the same week replaces it; an older retry cannot overwrite the fix.
	 */
	public function test_same_week_replace_and_stale(): void {
		$store = new Snapshot_Store();
		$store->put( $this->week( '2026-09-21', 1000, 5 ), 100 );

		$this->assertSame( 'replaced', $store->put( $this->week( '2026-09-21', 2000, 7 ), 200 ) );
		$this->assertSame( 7, $store->latest()['enquiries']['sources'][0]['count'] );

		$this->assertSame( 'stale', $store->put( $this->week( '2026-09-21', 1500, 1 ), 300 ) );
		$this->assertSame( 7, $store->latest()['enquiries']['sources'][0]['count'] );
		$this->assertSame( 200, $store->last_push(), 'a refused stale retry is not a push' );

		$this->assertSame( 'unchanged', $store->put( $this->week( '2026-09-21', 2000, 7 ), 400 ) );
		$this->assertSame( 400, $store->last_push(), 'an identical re-send still proves the pipeline is alive' );
	}

	/**
	 * Only KEEP weeks are kept; the oldest go first.
	 */
	public function test_keeps_a_year(): void {
		$store  = new Snapshot_Store();
		$monday = new \DateTimeImmutable( '2025-01-06' );
		for ( $i = 0; $i < Snapshot_Store::KEEP + 3; $i++ ) {
			$store->put( $this->week( $monday->modify( "+{$i} weeks" )->format( 'Y-m-d' ) ), $i );
		}
		$weeks = $store->all();

		$this->assertCount( Snapshot_Store::KEEP, $weeks );
		$this->assertArrayNotHasKey( '2025-01-06', $weeks );
		$this->assertArrayNotHasKey( '2025-01-20', $weeks );
		$this->assertArrayHasKey( '2025-01-27', $weeks );
	}

	/**
	 * A corrupted option reads as empty rather than breaking the screen.
	 */
	public function test_corrupt_option_reads_empty(): void {
		$this->options[ Snapshot_Store::OPTION ] = 'garbage';
		$this->assertSame( [], ( new Snapshot_Store() )->all() );
	}
}
