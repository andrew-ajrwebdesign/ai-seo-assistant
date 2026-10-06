<?php
/**
 * The schema-guard CI check (code-standards B1): a write to the plugin's tables only after Schema::ensure()
 * or Schema::is_current() in its function. It must flag the Scan_Store.php of a82d723, whose save_facts()
 * wrote body_text without one.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/bin/check-schema-guard.php';

/**
 * bin/check-schema-guard.php.
 */
class SchemaGuardCheckTest extends TestCase {

	/** The fixtures. */
	protected const DIR = __DIR__ . '/../../fixtures/schema-guard/';

	/**
	 * Each case in the fixture, decided as its comment says.
	 */
	public function test_cases(): void {
		$this->assertSame( [ 7, 8, 16, 30 ], \aisa_schema_guard_findings( (string) file_get_contents( self::DIR . 'cases.php' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a fixture.
	}

	/**
	 * The old Scan_Store.php (a82d723) is flagged at every write, save_facts()'s body_text write (line 44)
	 * first.
	 */
	public function test_flags_the_old_scan_store(): void {
		$found = \aisa_schema_guard_scan( [ self::DIR . 'old-scan-store.php' ] );
		$this->assertSame( self::DIR . 'old-scan-store.php:44', $found[0] );
		$this->assertCount( 5, $found, 'save_facts, save_issues, save_suggestions, prune, delete' );
	}

	/**
	 * A file without Schema::table() is not checked (core tables need no schema check).
	 */
	public function test_core_tables_not_checked(): void {
		$this->assertSame( [], \aisa_schema_guard_findings( '<?php function f() { global $wpdb; $wpdb->query( "UPDATE wp_options SET x = 1" ); }' ) );
	}

	/**
	 * The plugin's own src/ passes.
	 */
	public function test_src_passes(): void {
		$this->assertSame( [], \aisa_schema_guard_scan( [ dirname( __DIR__, 3 ) . '/src' ] ) );
	}
}
