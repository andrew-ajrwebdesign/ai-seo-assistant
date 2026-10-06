<?php
/**
 * Tests for Review\Alt_Writer: alt text written where the page prints it, and nothing else touched.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Review;

use AJR\SEOAssistant\Review\Alt_Writer;
use WP_Mock\Tools\TestCase;

/**
 * Splices into Divi shortcodes, Image blocks and classic <img>.
 */
class AltWriterTest extends TestCase {

	/** Post 363's shape: two blurbs sharing a copy-pasted alt, an image module without alt. */
	protected const DIVI = '[et_pb_section][et_pb_row][et_pb_column type="1_2"]'
		. '[et_pb_blurb title="Relocation" image="https://x.test/wp-content/uploads/2021/01/boise-outdoor-activities.jpg" alt="Moving To Boise Services" image_icon_width="180px" header_font="PT Sans Narrow|700|||||||"]Text[/et_pb_blurb]'
		. '[et_pb_blurb title="Home Buying" image="https://x.test/wp-content/uploads/2021/01/Boise-Mid-Century-Home.jpg" alt="Moving To Boise Services" _builder_version="4.27.6"]Text[/et_pb_blurb]'
		. '[et_pb_image src="https://x.test/wp-content/uploads/2022/12/EBoookFeaturedImage.jpg" title_text="EBoookFeaturedImage" force_fullwidth="on" global_colors_info="{}"][/et_pb_image]'
		. '[/et_pb_column][/et_pb_row][/et_pb_section]';

	/**
	 * Only the matched module's alt changes; the content outside it is byte-identical.
	 */
	public function test_divi_blurb_alt_replaced_in_place(): void {
		$r = Alt_Writer::splice( self::DIVI, 404, 'https://x.test/wp-content/uploads/2021/01/Boise-Mid-Century-Home.jpg', 'White kitchen with a gas range and subway tile' );
		$this->assertSame( 1, $r['matches'] );
		$this->assertSame( 'divi', $r['where'] );
		$this->assertSame( str_replace( 'Boise-Mid-Century-Home.jpg" alt="Moving To Boise Services"', 'Boise-Mid-Century-Home.jpg" alt="White kitchen with a gas range and subway tile"', self::DIVI ), $r['content'] );
		$this->assertStringContainsString( 'boise-outdoor-activities.jpg" alt="Moving To Boise Services"', $r['content'], 'the other blurb untouched' );
	}

	/**
	 * A module without an alt gets one; quotes become curly so the shortcode cannot break.
	 */
	public function test_divi_image_alt_added_safely(): void {
		$r = Alt_Writer::splice( self::DIVI, 3253, 'https://x.test/wp-content/uploads/2022/12/EBoookFeaturedImage-300x300.jpg', '"Discover Boise Like a Local" guide [cover]' );
		$this->assertSame( 1, $r['matches'] );
		$this->assertStringContainsString( 'global_colors_info="{}" alt="“Discover Boise Like a Local” guide cover"][/et_pb_image]', $r['content'] );
		$this->assertSame( strlen( self::DIVI ) + strlen( ' alt="“Discover Boise Like a Local” guide cover"' ), strlen( $r['content'] ) );
	}

	/**
	 * Core Image block markup and classic <img>: matched by wp-image-{id}; the alt is attribute-escaped.
	 */
	public function test_block_and_classic_img(): void {
		$block = "<!-- wp:image {\"id\":42} -->\n<figure class=\"wp-block-image\"><img src=\"https://x.test/a.jpg\" alt=\"\" class=\"wp-image-42\"/></figure>\n<!-- /wp:image -->";
		$r     = Alt_Writer::splice( $block, 42, 'https://x.test/a.jpg', 'Plumber & apprentice at a <boiler>' );
		$this->assertSame( 'html', $r['where'] );
		$this->assertStringContainsString( 'alt="Plumber &amp; apprentice at a &lt;boiler&gt;" class="wp-image-42"', $r['content'] );
		$classic = '<p><img class="alignleft wp-image-7" src="https://x.test/b-300x200.jpg" /></p>';
		$this->assertStringContainsString( '<img class="alignleft wp-image-7" src="https://x.test/b-300x200.jpg" alt="A van" />', Alt_Writer::splice( $classic, 7, 'b.jpg', 'A van' )['content'] );
	}

	/**
	 * None or several matches: nothing is written.
	 */
	public function test_ambiguous_or_missing_writes_nothing(): void {
		$twice = self::DIVI . '[et_pb_image src="https://x.test/wp-content/uploads/2022/12/EBoookFeaturedImage.jpg"][/et_pb_image]';
		$r     = Alt_Writer::splice( $twice, 3253, 'EBoookFeaturedImage.jpg', 'Guide cover' );
		$this->assertSame( 2, $r['matches'] );
		$this->assertSame( $twice, $r['content'] );
		$r = Alt_Writer::splice( self::DIVI, 999, 'not-here.jpg', 'x' );
		$this->assertSame( 0, $r['matches'] );
		$this->assertSame( self::DIVI, $r['content'] );
		$this->assertSame( 'boise-map', Alt_Writer::stem( 'https://x.test/u/boise-map-1024x683.jpg?ver=2' ) );
	}

	/**
	 * Same file name in another month, or a module / tag naming another attachment: never written.
	 */
	public function test_other_attachment_never_written(): void {
		$content = '[et_pb_image src="https://x.test/wp-content/uploads/2023/04/kitchen.jpg"][/et_pb_image]'
			. '[et_pb_image src="https://x.test/wp-content/uploads/2024/05/kitchen-1024x683.jpg"][/et_pb_image]';
		$r       = Alt_Writer::splice( $content, 0, 'https://x.test/wp-content/uploads/2024/05/kitchen.jpg', 'A kitchen' );
		$this->assertSame( 1, $r['matches'], 'the 2023 kitchen.jpg is a different image' );
		$this->assertStringContainsString( '2024/05/kitchen-1024x683.jpg" alt="A kitchen"', $r['content'] );
		$this->assertStringNotContainsString( '2023/04/kitchen.jpg" alt=', $r['content'] );

		$divi = '[et_pb_image src="https://x.test/wp-content/uploads/2024/05/kitchen.jpg" image_id="88"][/et_pb_image]';
		$this->assertSame( 0, Alt_Writer::splice( $divi, 12, 'https://x.test/wp-content/uploads/2024/05/kitchen.jpg', 'x' )['matches'] );
		$this->assertSame( 1, Alt_Writer::splice( $divi, 88, 'https://x.test/wp-content/uploads/2024/05/kitchen.jpg', 'x' )['matches'] );

		$img = '<img class="wp-image-90" src="https://x.test/wp-content/uploads/2024/05/kitchen.jpg" alt="">';
		$r   = Alt_Writer::splice( $img, 12, 'https://x.test/wp-content/uploads/2024/05/kitchen.jpg', 'x' );
		$this->assertSame( 0, $r['matches'], 'wp-image-90 is another attachment' );
		$this->assertSame( $img, $r['content'] );
		$this->assertSame( '2024/05/kitchen', Alt_Writer::key( 'https://x.test/wp-content/uploads/2024/05/kitchen-300x200.jpg' ) );
	}
}
