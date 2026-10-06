<?php
/**
 * Editor advice checked against the page (Andrew's /boise-area-map/: the H1 and the line were done, the
 * panel still said to do them): each check, the targets inferred from older advice, and the box marking
 * what is already there as done.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Review;

use AJR\SEOAssistant\Admin\Editor_Box;
use AJR\SEOAssistant\Core\Utils;
use AJR\SEOAssistant\Review\Editor_Check;
use AJR\SEOAssistant\Tests\Unit\Wp_Basics;
use WP_Mock\Tools\TestCase;

/**
 * Editor_Check.
 */
class EditorCheckTest extends TestCase {
	use Wp_Basics;

	/** The page as the scan saw it after Andrew's edit. */
	protected const FACTS = [
		'h1'       => [ 'Boise Neighborhoods Map: Treasure Valley Areas at a Glance' ],
		'headings' => [
			[ 'l' => 1, 't' => 'Boise Neighborhoods Map: Treasure Valley Areas at a Glance' ],
			[ 'l' => 2, 't' => 'Treasure Valley communities at a glance' ],
		],
	];

	/** Its text, with the line he added. */
	protected const TEXT = 'See the communities in relation to one another. This map of Boise and surrounding areas shows the Treasure Valley cities.';

	/** The three pieces of advice the review gave before the edit (no targets: written before 5.0's checks). */
	protected const ADVICE = [
		[ 'area' => 'headings', 'advice' => 'Keep the H1 but tighten it to "Boise Neighborhoods Map: Treasure Valley Areas at a Glance" so the exact search phrase sits at the front of the heading.' ],
		[ 'area' => 'content', 'advice' => 'Add a short line under the map, since "treasure valley map" and "boise map" bring thousands of impressions with almost no clicks.' ],
		[ 'area' => 'links', 'advice' => 'Link from the Boise Neighborhoods Guide back to this page using the words "Boise neighborhoods map".' ],
	];

	/**
	 * Each check, both ways.
	 */
	public function test_checks(): void {
		$h1 = [ 'check' => 'h1', 'targets' => [ 'boise neighborhoods map — treasure valley areas at a glance!' ], 'sources' => [] ];
		$this->assertTrue( Editor_Check::in_place( $h1, self::FACTS ), 'case and punctuation ignored' );
		$this->assertFalse( Editor_Check::in_place( [ 'targets' => [ 'Boise Map' ] ] + $h1, self::FACTS ) );
		$this->assertTrue( Editor_Check::in_place( [ 'check' => 'h2', 'targets' => [ 'Treasure Valley Communities at a Glance' ], 'sources' => [] ], self::FACTS ) );
		$this->assertFalse( Editor_Check::in_place( [ 'check' => 'h2', 'targets' => [ 'Boise Neighborhoods Map: Treasure Valley Areas at a Glance' ], 'sources' => [] ], self::FACTS ), 'the H1 is not an H2' );

		$exact = [ 'check' => 'phrase', 'targets' => [ 'Treasure Valley cities' ], 'sources' => [] ];
		$this->assertTrue( Editor_Check::in_place( $exact, [], self::TEXT ) );
		$this->assertFalse( Editor_Check::in_place( [ 'targets' => [ 'treasure valley map' ] ] + $exact, [], self::TEXT ), 'exact: the words must be together' );
		$this->assertTrue( Editor_Check::in_place( [ 'targets' => [ 'treasure valley cities' ] ] + $exact, [], Utils::visible_text( 'shows the <strong>Treasure  Valley</strong> cities.' ) ), 'tags, spacing and case ignored' );

		// Code-standards re-review: never word by word, never from shortcode attributes or markup.
		$cost = Editor_Check::of( [ 'area' => 'content', 'advice' => 'Add a short section answering "cost of living in boise".' ] );
		$this->assertSame( 'phrase', $cost['check'] );
		$this->assertFalse( Editor_Check::in_place( $cost, [], 'Is Boise Idaho Affordable? Living here, the cost of it in short: in Boise it depends.' ), 'scattered words are not the phrase' );
		$this->assertFalse( Editor_Check::in_place( $cost, [], Utils::visible_text( '[et_pb_text title="cost of living in boise" admin_label="cost of living in boise"]Moving here soon?[/et_pb_text]' ) ), 'a shortcode attribute is not visible text' );
		$this->assertFalse( Editor_Check::in_place( $cost, [], Utils::visible_text( '<img alt="cost of living in boise" src="x.jpg"><p>Hello</p>' ) ), 'an HTML attribute is not visible text' );
		$this->assertTrue( Editor_Check::in_place( $cost, [], Utils::visible_text( '[et_pb_text admin_label="Intro"]<p>The <em>cost of living</em> in Boise, in short.</p>[/et_pb_text]' ) ), 'the phrase in visible text counts' );

		$link = [ 'check' => 'link', 'targets' => [ 'Boise neighborhoods map' ], 'sources' => [ '/boise-neighborhoods/' ] ];
		$this->assertTrue( Editor_Check::in_place( $link, [], '', [ [ '/boise-neighborhoods/', 'See our Boise Neighborhoods Map' ] ] ) );
		$this->assertFalse( Editor_Check::in_place( $link, [], '', [ [ '/blog/', 'See our Boise Neighborhoods Map' ] ] ), 'from the page named' );
		$this->assertFalse( Editor_Check::in_place( $link, [], '', [ [ '/boise-neighborhoods/', 'Click here' ] ] ), 'with those words' );
		$this->assertFalse( Editor_Check::in_place( [ 'check' => 'none', 'targets' => [], 'sources' => [] ], self::FACTS, self::TEXT ), 'nothing checkable: never done by itself' );
	}

	/**
	 * Targets from older advice's own words; Claude's own target wins when given.
	 */
	public function test_infer(): void {
		$this->assertSame( [ 'check' => 'h1', 'targets' => [ 'Boise Neighborhoods Map: Treasure Valley Areas at a Glance' ], 'sources' => [] ], Editor_Check::of( self::ADVICE[0] ) );
		$this->assertSame( 'h1', Editor_Check::infer( 'headings', 'Change the H1 from "Old" to "New heading"' )['check'] );
		$this->assertSame( [ 'New heading' ], Editor_Check::infer( 'headings', 'Change the H1 from "Old" to "New heading"' )['targets'], 'the new heading is the last quote' );
		$this->assertSame( 'none', Editor_Check::infer( 'headings', 'Use clearer headings.' )['check'] );
		$this->assertSame( [ 'treasure valley map', 'boise map' ], Editor_Check::of( self::ADVICE[1] )['targets'] );
		$this->assertSame( 'link', Editor_Check::of( self::ADVICE[2] )['check'] );
		$this->assertSame( [ 'Exact words' ], Editor_Check::of( [ 'area' => 'content', 'advice' => 'Add "something else".', 'check' => 'phrase', 'target' => 'Exact words', 'source' => '' ] )['targets'], 'Claude\'s target wins' );
	}

	/**
	 * A reply's advice that is already true is dropped before it is stored (Claude suggested the H1 the
	 * page had); schema advice is never kept.
	 */
	public function test_reply_drops_what_is_already_there(): void {
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnArg( 0 );
		$context = [
			'facts'   => self::FACTS,
			'text'    => self::TEXT,
			'inbound' => [],
		];
		$kept    = \AJR\SEOAssistant\Review\Page_Review::fresh_advice(
			[
				[ 'area' => 'headings', 'advice' => 'Make the H1 "Boise Neighborhoods Map: Treasure Valley Areas at a Glance".', 'check' => 'h1', 'target' => 'Boise Neighborhoods Map: Treasure Valley Areas at a Glance', 'source' => '' ],
				[ 'area' => 'links', 'advice' => 'Link from the guide with "Boise neighborhoods map".', 'check' => 'link', 'target' => 'Boise neighborhoods map', 'source' => '/guide/' ],
				[ 'area' => 'schema', 'advice' => 'Add schema.', 'check' => 'none', 'target' => '', 'source' => '' ],
			],
			$context
		);
		$this->assertSame( [ 'links' ], array_column( $kept, 'area' ) );
		$this->assertSame( '/guide/', $kept[0]['source'] );
	}

	/**
	 * The prompt shows Claude what is already there and forbids suggesting it, and asks for a check.
	 */
	public function test_prompt_shows_current_state(): void {
		$prompt = ( new \AJR\SEOAssistant\AI\Prompt_Builder() )->build_review_prompt(
			[
				'headings' => [ 'H1 Boise Neighborhoods Map: Treasure Valley Areas at a Glance' ],
				'inbound'  => [ '/boise-neighborhoods/ | See our Boise Neighborhoods Map' ],
				'content'  => 'x',
			]
		);
		$this->assertStringContainsString( 'Headings now: H1 Boise Neighborhoods Map', $prompt );
		$this->assertStringContainsString( 'Links to this page now (from page | link text): /boise-neighborhoods/ | See our Boise Neighborhoods Map', $prompt );
		$this->assertStringContainsString( 'Never suggest a change that is already in place', $prompt );
		$schema = ( new \AJR\SEOAssistant\AI\Prompt_Builder() )->review_schema();
		$this->assertSame( [ 'area', 'advice', 'check', 'target', 'source' ], $schema['properties']['editor']['items']['required'] );
	}

	/**
	 * The editor's to-do box on Andrew's page after his edit: the H1 shows done without a click and is not
	 * counted; the content line (its quoted searches are not on the page as phrases) and the link (not
	 * there yet) are still to-dos.
	 */
	public function test_box_marks_what_is_there(): void {
		$row     = [
			'scanned_at'  => '2026-10-06 14:28:31',
			'issues'      => [],
			'suggestions' => [ 'editor' => self::ADVICE ],
		];
		$context = [
			'facts'   => self::FACTS,
			'text'    => self::TEXT,
			'inbound' => [],
		];
		$items   = array_column( Editor_Box::items( $row, [], $context ), null, 'area' );
		$this->assertTrue( $items['Headings']['found'] );
		$this->assertSame( (int) strtotime( '2026-10-06 14:28:31 UTC' ), $items['Headings']['done'] );
		$this->assertFalse( $items['Content']['found'], '"treasure valley map" is not on the page as a phrase: the person ticks Done' );
		$this->assertNull( $items['Content']['done'] );
		$this->assertNull( $items['Links']['done'], 'not there yet: still to do' );
	}

	/**
	 * Code-standards re-review (1): on a Divi page the phrase check read nothing, because the old text came
	 * through strip_shortcodes(), which deletes the enclosed body of every registered shortcode. Checked
	 * through Page_Review::editor_context() with et_pb_text registered and strip_shortcodes() behaving as
	 * core's: a row scanned before 5.0 kept no rendered text, so the content is the fallback.
	 */
	public function test_editor_context_reads_divi_text(): void {
		$this->wp_basics();
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';

			/**
			 * No other scanned pages.
			 *
			 * @return array<int,mixed>
			 */
			public function get_results(): array {
				return [];
			}
		};
		$registered = [];
		\WP_Mock::userFunction( 'add_shortcode' )->andReturnUsing(
			function ( $tag ) use ( &$registered ) {
				$registered[] = $tag;
			}
		);
		// Core's strip_shortcodes(): a registered shortcode goes, the text it encloses with it.
		\WP_Mock::userFunction( 'strip_shortcodes' )->andReturnUsing(
			function ( $content ) use ( &$registered ) {
				foreach ( $registered as $tag ) {
					$content = (string) preg_replace( '/\[' . $tag . '\b[^\]]*\].*?\[\/' . $tag . '\]/s', '', (string) $content );
				}
				return $content;
			}
		);
		\WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( fn( $s ) => trim( strip_tags( (string) $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- the stand-in for core's.
		\WP_Mock::userFunction( 'get_bloginfo' )->andReturn( 'UTF-8' );
		\WP_Mock::userFunction( 'has_blocks' )->andReturn( false );
		\WP_Mock::userFunction( 'get_the_title' )->andReturn( 'Living here' );
		add_shortcode( 'et_pb_section', '__return_empty_string' );
		add_shortcode( 'et_pb_text', '__return_empty_string' );
		$this->assertSame( '', strip_shortcodes( '[et_pb_text]Words[/et_pb_text]' ), 'the stand-in deletes the body, as core does' );

		$content = '[et_pb_section][et_pb_text admin_label="Intro"]<p>The cost of living in Boise, in short. Fix the &lt;title&gt; tag first.</p>[/et_pb_text][/et_pb_section]';
		if ( ! class_exists( '\WP_Post' ) ) {
			eval( 'class WP_Post { public $ID = 0; public $post_type = "page"; public $post_status = "publish"; public $post_password = ""; public $post_modified_gmt = ""; public $post_content = ""; public $post_excerpt = ""; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a test stand-in for core's class.
		}
		$post               = new \WP_Post();
		$post->ID           = 7;
		$post->post_content = $content;
		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );

		$cost  = Editor_Check::of( [ 'area' => 'content', 'advice' => 'Add a short section answering "cost of living in boise".' ] );
		$title = [ 'check' => 'phrase', 'targets' => [ 'title tag' ], 'sources' => [] ];

		$context = \AJR\SEOAssistant\Review\Page_Review::editor_context( 7, [ 'path' => '/living/' ] );
		$this->assertStringContainsString( 'The cost of living in Boise, in short.', $context['text'], 'the text module\'s body is read' );
		$this->assertTrue( Editor_Check::in_place( $cost, [], $context['text'] ), 'the to-do ticks itself on a Divi page' );
		$this->assertStringContainsString( 'Fix the <title> tag first.', $context['text'], 'tags out first, then entities decoded' );
		$this->assertTrue( Editor_Check::in_place( $title, [], $context['text'] ), 'copy written as &lt;title&gt; is words, not a tag' );
		$this->assertSame( $context['text'], \AJR\SEOAssistant\Review\Page_Review::prompt_content( 7, [ 'path' => '/living/' ] ), 'Claude reads the same words: the Divi text, and "<title>" not stripped as a tag' );

		// The scan's rendered text wins: a blurb's title= is visible on the page, never in the content's words.
		$context = \AJR\SEOAssistant\Review\Page_Review::editor_context(
			7,
			[
				'path'      => '/living/',
				'body_text' => 'Boise Neighborhoods Map The cost of living in Boise Moving here soon?',
			]
		);
		$this->assertSame( 'Boise Neighborhoods Map The cost of living in Boise Moving here soon?', $context['text'] );
		$this->assertTrue( Editor_Check::in_place( [ 'check' => 'phrase', 'targets' => [ 'boise neighborhoods map' ], 'sources' => [] ], [], $context['text'] ) );
		unset( $GLOBALS['wpdb'] );
	}
}
