<?php
/**
 * Tests for the stack-review behaviour outside the secrets: nothing is written when a post is saved (5.0: the
 * 4.x editor box and its save are gone); business facts fall back to AJR Core when this plugin's own are empty.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Core;

use AJR\SEOAssistant\Content\Local_SEO_Context;
use WP_Mock\Tools\TestCase;

/**
 * Metabox save and business-fact fallback.
 */
class StackFixesTest extends TestCase {

	/**
	 * Fake options.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Options and the request plumbing every test needs.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->options = [];
		$_POST         = [];
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => array_key_exists( $n, $this->options ) ? $this->options[ $n ] : $d );
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->andReturn( 1 );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
	}

	/**
	 * 5.0 (Andrew: "all we really need to see is the do this in editor"): the editor box writes nothing
	 * when a post is saved. No save handler of this plugin is on the save hooks, and a save posting the 4.x
	 * box's fields (title, description, local focus) leaves the SEO plugin's fields and the post meta alone.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_title_or_description_write_on_post_save(): void {
		// No code of this plugin hooks a post save (the scan's transition_post_status only queues a rescan).
		$root = dirname( __DIR__, 3 );
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) );
		foreach ( $it as $file ) {
			if ( '.php' !== substr( (string) $file, -4 ) ) {
				continue;
			}
			$src = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
			$this->assertSame( 0, preg_match( "/add_action\\(\\s*'(save_post[a-z_]*|wp_after_insert_post|edit_post|pre_post_update|wp_insert_post)'/", $src ), basename( (string) $file ) . ' hooks a post save' );
			if ( 'Page_Review.php' !== basename( (string) $file ) ) { // The review's Apply is the one writer (logged, with Undo).
				$this->assertStringNotContainsString( 'save_title(', str_replace( 'function save_title(', '', $src ), basename( (string) $file ) . ' writes an SEO title outside the page review' );
				$this->assertStringNotContainsString( 'save_description(', str_replace( 'function save_description(', '', $src ), basename( (string) $file ) . ' writes an SEO description outside the page review' );
			}
		}

		// The editor box registers its box, assets and the Done tick, nothing else.
		if ( ! defined( 'AI_SEO_ASSISTANT_BASENAME' ) ) {
			define( 'AI_SEO_ASSISTANT_BASENAME', 'ai-seo-assistant/ai-seo-assistant.php' );
		}
		$box = new \AJR\SEOAssistant\Admin\Editor_Box();
		\WP_Mock::expectActionAdded( 'add_meta_boxes', [ $box, 'add_box' ] );
		\WP_Mock::expectActionAdded( 'admin_enqueue_scripts', [ $box, 'enqueue' ] );
		\WP_Mock::expectActionAdded( 'wp_ajax_aisa_todo_done', [ $box, 'ajax_done' ] );
		$box->register();
		$this->assertFalse( class_exists( 'AJR\SEOAssistant\Admin\Admin', true ), 'the 4.x box is gone' );
		$this->assertFalse( method_exists( Local_SEO_Context::class, 'save_page_context' ), 'and its field save' );
	}

	/**
	 * Without AJR Core: this plugin's own values only, general mode by default.
	 */
	public function test_business_facts_without_ajr_core(): void {
		$context = new Local_SEO_Context();
		$this->assertSame( 'general', $context->get_focus_mode() );
		$this->assertSame( '', $context->get_global_context()['priority_services'] );
	}

	/**
	 * With AJR Core: empty own settings read AJR Core's business profile; a filled own setting wins.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_business_facts_fall_back_to_ajr_core(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- stand-ins for AJR Core's classes, defined only in this process.
		eval(
			'namespace AJR\Core\Framework; class Config { public static $v = [
				"schema.type" => "RealEstateAgent",
				"business.services" => "Buying a home | Help with offers\nSelling",
				"business.knows_about" => "Neighbourhood guides",
				"business.area_served" => "City: Northfield\nRegion: Valley County",
				"business.locality" => "Northfield",
				"business.region" => "ID",
			]; public static function get( string $k, $f = null ) { return self::$v[ $k ] ?? $f; } }
			namespace AJR\Core\Schema; class Business_Profile { public static function is_local_type( $t ): bool { return "RealEstateAgent" === $t; } }'
		);

		$context = new Local_SEO_Context();
		$this->assertSame( 'local', $context->get_focus_mode(), 'local business type → local mode' );
		$global = $context->get_global_context();
		$this->assertSame( "Buying a home\nSelling\nNeighbourhood guides", $global['priority_services'] );
		$this->assertSame( "Northfield\nValley County", $global['primary_locations'] );

		$this->options['ai_seo_assistant_focus_mode']        = 'general';
		$this->options['ai_seo_assistant_priority_services'] = 'Our own list';
		$this->assertSame( 'general', $context->get_focus_mode(), 'own choice wins' );
		$this->assertSame( 'Our own list', $context->get_global_context()['priority_services'] );

		\AJR\Core\Framework\Config::$v['business.area_served'] = '';
		$this->options['ai_seo_assistant_focus_mode']           = 'local';
		$this->assertSame( 'Northfield, ID', $context->get_global_context()['primary_locations'], 'town and region when no areas' );
	}
}
