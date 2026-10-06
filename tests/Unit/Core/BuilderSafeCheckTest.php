<?php
/**
 * The builder-safe CI check (code-standards re-review 4): strip_shortcodes() in src/ only with a reason on
 * its line. It must flag 5.0's Utils.php:159, which emptied every Divi page's text.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/bin/check-builder-safe.php';

/**
 * bin/check-builder-safe.php.
 */
class BuilderSafeCheckTest extends TestCase {

	/** The fixtures. */
	protected const DIR = __DIR__ . '/../../fixtures/builder-safe/';

	/**
	 * Each case in the fixture, decided as its comment says.
	 */
	public function test_cases(): void {
		$this->assertSame( [ 4, 5, 6, 8, 13 ], \aisa_builder_safe_findings( (string) file_get_contents( self::DIR . 'cases.php' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a fixture.
	}

	/**
	 * The old Utils.php (commit 4695128) is flagged at line 159, its strip_shortcodes() call.
	 */
	public function test_flags_the_old_utils_line_159(): void {
		$this->assertSame( [ self::DIR . 'old-utils.php:159' ], \aisa_builder_safe_scan( [ self::DIR . 'old-utils.php' ] ) );
	}

	/**
	 * The plugin's own src/ passes.
	 */
	public function test_src_passes(): void {
		$this->assertSame( [], \aisa_builder_safe_scan( [ dirname( __DIR__, 3 ) . '/src' ] ) );
	}
}
