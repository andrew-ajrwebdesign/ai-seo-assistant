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
		$this->assertTrue( Editor_Check::in_place( [ 'targets' => [ 'treasure valley map' ], 'words' => true ] + $exact, [], self::TEXT ), 'inferred: each word on the page' );

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
	 * The editor's to-do box on Andrew's page after his edit: the H1 and the line show done without a
	 * click and are not counted; the link (not there yet) is still a to-do.
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
		$this->assertTrue( $items['Content']['found'] );
		$this->assertNull( $items['Links']['done'], 'not there yet: still to do' );
	}
}
