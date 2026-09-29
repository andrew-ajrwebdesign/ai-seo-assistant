<?php
/**
 * Tests for Report\Chart — valid, accessible SVG from numbers.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Report;

use AJR\SEOAssistant\Report\Chart;
use WP_Mock\Tools\TestCase;

/**
 * SVG charts.
 */
class ChartTest extends TestCase {

	/**
	 * Passthrough escaping and translation.
	 */
	public function setUp(): void {
		parent::setUp();
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturnUsing( fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
		\WP_Mock::userFunction( '_x' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
	}

	/**
	 * Twelve week labels.
	 *
	 * @return array<int,string>
	 */
	protected function labels(): array {
		return [ '6 Jul', '13 Jul', '20 Jul', '27 Jul', '3 Aug', '10 Aug', '17 Aug', '24 Aug', '31 Aug', '7 Sep', '14 Sep', '21 Sep' ];
	}

	/**
	 * Parse SVG as XML — it must be well-formed.
	 *
	 * @param string $svg SVG.
	 */
	protected function xml( string $svg ): \SimpleXMLElement {
		$doc = simplexml_load_string( $svg );
		$this->assertNotFalse( $doc, 'well-formed SVG' );

		return $doc;
	}

	/**
	 * Bars: one per week, the last highlighted and labelled; accessible title and description.
	 */
	public function test_bars(): void {
		$values = [ 12, 15, 13, 16, 14, 17, 15, 18, 16, 19, 18, 23 ];
		$svg    = Chart::bars( $values, $this->labels(), 'Enquiries, last 12 weeks' );
		$doc    = $this->xml( $svg );

		$this->assertSame( 'img', (string) $doc['role'] );
		$this->assertSame( 'Enquiries, last 12 weeks', (string) $doc->title );
		$this->assertStringContainsString( '21 Sep: 23', (string) $doc->desc );
		$this->assertCount( 12, $doc->rect );
		$this->assertSame( 1, substr_count( $svg, 'aisa-bar--now' ) );
		$this->assertStringContainsString( '>23</text>', $svg );
		$this->assertStringNotContainsString( '#', preg_replace( '/id="[^"]*"|aria-labelledby="[^"]*"/', '', $svg ), 'no colours in markup: CSS owns them' );
	}

	/**
	 * Lines: two scales, round axis tops, both series drawn, labelled first and last week.
	 */
	public function test_lines(): void {
		$clicks = [ 290, 300, 296, 322, 318, 338, 332, 350, 348, 366, 380, 412 ];
		$shown  = [ 11200, 11900, 12400, 13100, 13800, 13300, 14500, 15200, 15900, 16600, 17800, 18600 ];
		$svg    = Chart::lines( $clicks, $shown, $this->labels(), 'Clicks and times you appeared' );
		$doc    = $this->xml( $svg );

		$this->assertCount( 2, $doc->polyline );
		$this->assertStringContainsString( '>450</text>', $svg, 'left scale tops at 450' );
		$this->assertStringContainsString( '>24k</text>', $svg, 'right scale tops at 24k' );
		$this->assertStringContainsString( '>8k</text>', $svg, 'every right tick in thousands' );
		$this->assertStringNotContainsString( '>8,000<', $svg );
		$this->assertStringContainsString( '>6 Jul</text>', $svg );
		$this->assertStringContainsString( '>21 Sep</text>', $svg );
		$this->assertStringContainsString( 'Times you appeared: 11,200', (string) $doc->desc );
	}

	/**
	 * Nothing is drawn from data that cannot make a chart.
	 */
	public function test_refuses_bad_input(): void {
		$this->assertSame( '', Chart::bars( [], [], 't' ) );
		$this->assertSame( '', Chart::bars( [ 1, 2 ], [ 'a' ], 't' ), 'labels must match' );
		$this->assertSame( '', Chart::lines( [ 5 ], [], [ 'a' ], 't' ), 'one point is not a line' );
		$this->assertSame( '', Chart::lines( [ 1, 2 ], [ 1 ], [ 'a', 'b' ], 't' ), 'series must match' );
	}

	/**
	 * Labels are escaped; all-zero data still draws.
	 */
	public function test_escaping_and_zeroes(): void {
		$svg = Chart::bars( [ 0, 0 ], [ '<b>a</b>', 'b&c' ], '<x>' );
		$this->xml( $svg );
		$this->assertStringNotContainsString( '<b>', $svg );
		$this->assertStringContainsString( 'b&amp;c', $svg );
	}

	/**
	 * Round axis tops.
	 */
	public function test_nice_max(): void {
		$this->assertSame( 450.0, Chart::nice_max( 412 ) );
		$this->assertSame( 24000.0, Chart::nice_max( 18600 ) );
		$this->assertSame( 24.0, Chart::nice_max( 23 ) );
		$this->assertSame( 3.0, Chart::nice_max( 0 ) );
	}
}
