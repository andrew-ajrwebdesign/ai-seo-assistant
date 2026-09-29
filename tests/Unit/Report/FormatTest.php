<?php
/**
 * Tests for Report\Format — the words around the numbers.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Report;

use AJR\SEOAssistant\Report\Format;
use WP_Mock\Tools\TestCase;

/**
 * Change wording, headline, short numbers.
 */
class FormatTest extends TestCase {

	/**
	 * English passthrough for __() and _n().
	 */
	public function setUp(): void {
		parent::setUp();
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturnUsing( fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
		\WP_Mock::userFunction( '_x' )->andReturnArg( 0 );
		\WP_Mock::userFunction( '_n' )->andReturnUsing( fn( $one, $many, $n ) => 1 === $n ? $one : $many );
	}

	/**
	 * Counts and percentages: up is good, down is bad, level is flat.
	 */
	public function test_counts_and_percentages(): void {
		$this->assertSame( [ 'text' => '+3 on last week', 'tone' => 'good' ], Format::change( 11, 8 ) );
		$this->assertSame( [ 'text' => "\u{2212}2 on last week", 'tone' => 'bad' ], Format::change( 36, 38 ) );
		$this->assertSame( [ 'text' => 'same as last week', 'tone' => 'flat' ], Format::change( 2, 2 ) );
		$this->assertSame( [ 'text' => '+8% on last week', 'tone' => 'good' ], Format::change( 412, 381, Format::PERCENT ) );
		$this->assertSame( 'same as last week', Format::change( 1000, 1002, Format::PERCENT )['text'], 'rounds to 0%' );
		$this->assertSame( '', Format::change( 5, 0, Format::PERCENT )['text'], 'no percentage of nothing' );
	}

	/**
	 * No previous week means no comparison at all — not "+23".
	 */
	public function test_no_previous_week(): void {
		$this->assertSame( [ 'text' => '', 'tone' => 'flat' ], Format::change( 23, null ) );
		$this->assertSame( [ 'text' => '', 'tone' => 'flat' ], Format::change( null, 5 ) );
	}

	/**
	 * Rates, positions and costs: the direction that is good news depends on the figure.
	 */
	public function test_direction_depends_on_the_figure(): void {
		$this->assertSame( [ 'text' => '+0.1 points', 'tone' => 'good' ], Format::change( 2.2, 2.1, Format::POINTS ) );
		$this->assertSame( [ 'text' => "\u{2212}2 points", 'tone' => 'bad' ], Format::change( 62, 64, Format::POINTS ) );

		$this->assertSame( [ 'text' => 'up 0.7 places', 'tone' => 'good' ], Format::change( 11.4, 12.1, Format::PLACES ), 'a smaller position is higher on Google' );
		$this->assertSame( [ 'text' => 'down 1.5 places', 'tone' => 'bad' ], Format::change( 9.0, 7.5, Format::PLACES ) );

		$this->assertSame( [ 'text' => "\u{2212}\$9 on last week", 'tone' => 'good' ], Format::change( 68, 77, Format::COST, 'USD' ), 'cheaper enquiries are good news' );
		$this->assertSame( [ 'text' => '+€4 on last week', 'tone' => 'bad' ], Format::change( 40, 36, Format::COST, 'EUR' ) );
	}

	/**
	 * The headline sentence in all its forms.
	 */
	public function test_headline(): void {
		$this->assertSame( '23 enquiries this week, 5 more than last week.', Format::headline( 23, 18 ) );
		$this->assertSame( '14 enquiries this week, 3 fewer than last week.', Format::headline( 14, 17 ) );
		$this->assertSame( '9 enquiries this week, the same as last week.', Format::headline( 9, 9 ) );
		$this->assertSame( '1 enquiry this week.', Format::headline( 1, null ) );
		$this->assertSame( '1,204 enquiries this week.', Format::headline( 1204, null ) );
	}

	/**
	 * Tile numbers and money.
	 */
	public function test_short_numbers_and_money(): void {
		$this->assertSame( '18.6k', Format::short( 18600 ) );
		$this->assertSame( '20k', Format::short( 20000 ) );
		$this->assertSame( '1,284', Format::short( 1284 ) );
		$this->assertSame( '11.4', Format::short( 11.4 ) );
		$this->assertSame( '2.2m', Format::short( 2200000 ) );
		$this->assertSame( '$612', Format::money( 612.4, 'USD' ) );
		$this->assertSame( '£1,300', Format::money( 1300, 'GBP' ) );
		$this->assertSame( '612 CHF', Format::money( 612, 'CHF' ) );
		$this->assertSame( '612', Format::money( 612, '' ) );
	}
}
