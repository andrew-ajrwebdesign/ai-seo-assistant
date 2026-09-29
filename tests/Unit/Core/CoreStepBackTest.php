<?php
/**
 * Tests for Core\Plugin::core_owns_redirects() / core_owns_markdown() — when this plugin steps back
 * for the site's core plugin (4.3.2: a site's own core, e.g. ocb-core, as well as AJR Core).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Core;

use AJR\SEOAssistant\Core\Plugin;
use WP_Mock\Tools\TestCase;

/**
 * The step-back rules. AJR Core is absent in these tests (its classes are not loaded).
 */
class CoreStepBackTest extends TestCase {

	/**
	 * No site core says anything: this plugin keeps both jobs.
	 */
	public function test_keeps_both_jobs_by_default(): void {
		\WP_Mock::onFilter( 'ai_seo_assistant_core_owns_redirects' )->with( false )->reply( false );
		\WP_Mock::onFilter( 'ai_seo_assistant_core_owns_markdown' )->with( false )->reply( false );

		$this->assertFalse( Plugin::core_owns_redirects() );
		$this->assertFalse( Plugin::core_owns_markdown() );
	}

	/**
	 * A site core that serves Markdown for AI takes it.
	 */
	public function test_site_core_takes_markdown(): void {
		\WP_Mock::onFilter( 'ai_seo_assistant_core_owns_markdown' )->with( false )->reply( true );

		$this->assertTrue( Plugin::core_owns_markdown() );
	}

	/**
	 * A site core that runs the redirects takes them — only while this plugin holds no rules.
	 */
	public function test_site_core_takes_redirects_only_when_we_hold_none(): void {
		\WP_Mock::onFilter( 'ai_seo_assistant_core_owns_redirects' )->with( false )->reply( true );

		\WP_Mock::userFunction( 'get_option' )->with( 'ai_seo_assistant_redirect_map', [] )->andReturn( [] )->once();
		$this->assertTrue( Plugin::core_owns_redirects(), 'no rules held: step back' );
	}

	/**
	 * ⛔ Rules held: never step back, or live redirects would silently stop (a site core does not
	 * copy our rules across the way AJR Core does).
	 */
	public function test_never_drops_rules_it_holds(): void {
		\WP_Mock::onFilter( 'ai_seo_assistant_core_owns_redirects' )->with( false )->reply( true );

		\WP_Mock::userFunction( 'get_option' )->with( 'ai_seo_assistant_redirect_map', [] )->andReturn( [ '/old/' => [ '/new/', 301 ] ] )->once();
		$this->assertFalse( Plugin::core_owns_redirects() );
	}
}
