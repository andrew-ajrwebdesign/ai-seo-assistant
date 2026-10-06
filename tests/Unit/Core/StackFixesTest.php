<?php
/**
 * Tests for the 4.4.0 stack-review behaviour outside the secrets: the metabox writes only what changed,
 * once per request; business facts fall back to AJR Core when this plugin's own are empty.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Core;

use AJR\SEOAssistant\Admin\Admin;
use AJR\SEOAssistant\AI\Claude_Client;
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
	 * An Admin wired to recording fakes.
	 *
	 * @param array<int,array<int,mixed>> $writes Receives [ field, post, value ] per adapter write.
	 */
	protected function admin( array &$writes ): Admin {
		$adapter = new class( $writes ) {
			/** @var array<int,array<int,mixed>> */
			public array $writes;
			public function __construct( array &$writes ) {
				$this->writes = &$writes;
			}
			public function save_title( $post_id, $value ) {
				$this->writes[] = [ 'title', $post_id, $value ];
			}
			public function save_description( $post_id, $value ) {
				$this->writes[] = [ 'description', $post_id, $value ];
			}
		};
		$context = new class() {
			public int $calls = 0;
			public function save_page_context( $post_id, $data ) {
				++$this->calls;
			}
		};

		return new Admin( $adapter, null, $context, null, new Claude_Client() );
	}

	/**
	 * A title still equal to what the box showed is not written (a Yoast-sidebar edit survives); a changed
	 * description is; the second wp_after_insert_post pass for the same post writes nothing.
	 */
	public function test_metabox_writes_only_changes_once(): void {
		$_POST = [
			Admin::NONCE_NAME             => 'n',
			'ai_seo_title'                => 'Shown at load',
			'ai_seo_title_original'       => 'Shown at load',
			'ai_seo_description'          => 'Generated description',
			'ai_seo_description_original' => 'Old description',
		];
		$writes = [];
		$admin  = $this->admin( $writes );

		$admin->save_metadata_fields( 7 );
		$admin->save_metadata_fields( 7 );

		$this->assertSame( [ [ 'description', 7, 'Generated description' ] ], $writes );
	}

	/**
	 * A form from before 4.4.0 (no *_original fields) keeps the old rule: write when non-empty; an empty
	 * field never clears the SEO plugin's value.
	 */
	public function test_metabox_without_originals_keeps_old_rule(): void {
		$_POST  = [
			Admin::NONCE_NAME    => 'n',
			'ai_seo_title'       => 'A title',
			'ai_seo_description' => '   ',
		];
		$writes = [];
		$this->admin( $writes )->save_metadata_fields( 8 );

		$this->assertSame( [ [ 'title', 8, 'A title' ] ], $writes );
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
