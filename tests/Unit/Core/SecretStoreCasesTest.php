<?php
/**
 * The shared Secret_Store behaviour cases (tests/fixtures/secret-store-cases.json), run against this
 * plugin's Core\Secret_Store.
 *
 * WHY (stack review round 2, 2026-10-06, code-standards proposal 1): AJR Core and this plugin each carry
 * their own copy of one encryption scheme, and the two drifted (the duplicate-salt check; what a failed
 * encryption leaves behind). The cases file is the contract and is kept BYTE-IDENTICAL in both repos;
 * each repo maps the cases onto its own API here. This file is this plugin's adapter:
 *
 * - salts:    Secret_Store::salts_usable() (what salts_in_config() runs on the real constants).
 * - read:     the report push key option, written with Secret_Store::set(), tampered, read with get().
 * - write:    the Claude key through its real 5.0 save path, Admin\Settings_Page::save() (the Search
 *             Console screen that held this case in 4.4 was removed). A Claude key must start with
 *             "sk-ant-", so the adapter prefixes the fixture's submitted and expected values with it.
 * - legacy:   a plain-text push key on a site already at upgrade level 4.4.0 (left by an update that ran
 *             from cron or WP-CLI), read with get() in the given request context.
 * - constant: the Claude key, Claude_Client::get_api_key(), AI_SEO_ASSISTANT_ANTHROPIC_API_KEY.
 *
 * Each case runs in its own process: the cases define constants (WP_CLI, the wp-config key).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Core;

use AJR\SEOAssistant\AI\Claude_Client;
use AJR\SEOAssistant\Core\Secret_Store;
use AJR\SEOAssistant\Core\Upgrade;
use AJR\SEOAssistant\Admin\Settings_Page;
use AJR\SEOAssistant\Report\Access;
use WP_Mock\Tools\TestCase;

/**
 * Secret_Store as the test suite sees it: encryption can be switched off, the way a server without
 * libsodium behaves.
 */
class Fixture_Secret_Store extends Secret_Store {

	/**
	 * Switch encryption on or off.
	 *
	 * @param bool $on Whether libsodium is "available".
	 */
	public static function encryption( bool $on ): void {
		static::$no_sodium = ! $on;
	}
}

/**
 * Thrown by the fake wp_safe_redirect() so a save handler's `exit` is never reached.
 */
class Redirected extends \RuntimeException {
}

/**
 * One test per shared case.
 */
class SecretStoreCasesTest extends TestCase {

	/** Where the shared file lives. */
	protected const FIXTURE = __DIR__ . '/../../fixtures/secret-store-cases.json';

	/** The option read/legacy cases use (it has no settings screen of its own). */
	protected const READ_OPTION = 'ai_seo_assistant_report_key';

	/**
	 * The fake options table.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Rows written (update_option/add_option calls that changed something).
	 *
	 * @var int
	 */
	protected int $writes = 0;

	/**
	 * What wp_salt() returns.
	 *
	 * @var string
	 */
	protected string $salt = 'fixture-salt-one';

	/**
	 * The request context: 'admin', 'cron', 'cli' or 'front'.
	 *
	 * @var string
	 */
	protected string $context = 'admin';

	/**
	 * The Settings screen's last result code (from its redirect).
	 *
	 * @var string
	 */
	protected string $code = '';

	/**
	 * Every case in the shared file, keyed by its id.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function provide_cases(): array {
		$data = json_decode( (string) file_get_contents( self::FIXTURE ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a test fixture.
		$out  = [];
		foreach ( $data['cases'] as $case ) {
			$out[ $case['id'] ] = [ $case ];
		}

		return $out;
	}

	/**
	 * The file is there, versioned, and covers every kind (coverage: a bare pass on zero cases is not a
	 * result).
	 */
	public function test_fixture_covers_every_kind(): void {
		$data  = json_decode( (string) file_get_contents( self::FIXTURE ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a test fixture.
		$kinds = array_count_values( array_column( $data['cases'], 'kind' ) );
		ksort( $kinds );

		$this->assertSame( 1, $data['version'] );
		$this->assertSame( [ 'constant', 'legacy', 'read', 'salts', 'write' ], array_keys( $kinds ) );
		$this->assertGreaterThanOrEqual( 30, count( $data['cases'] ), 'coverage: the shared cases' );
	}

	/**
	 * Fakes for options, salts, context and the screen helpers.
	 */
	public function setUp(): void {
		parent::setUp();
		Secret_Store::reset();
		Fixture_Secret_Store::encryption( true );

		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => array_key_exists( $n, $this->options ) ? $this->options[ $n ] : $d );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $n, $v ) {
				if ( array_key_exists( $n, $this->options ) && $this->options[ $n ] === $v ) {
					return false;
				}
				++$this->writes;
				$this->options[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'add_option' )->andReturnUsing(
			function ( $n, $v ) {
				if ( array_key_exists( $n, $this->options ) ) {
					return false;
				}
				++$this->writes;
				$this->options[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $n ) {
				if ( ! array_key_exists( $n, $this->options ) ) {
					return false;
				}
				++$this->writes;
				unset( $this->options[ $n ] );
				return true;
			}
		);
		\WP_Mock::userFunction( 'wp_salt' )->andReturnUsing( fn( $scheme ) => $this->salt . '-' . $scheme );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.
		\WP_Mock::userFunction( 'is_admin' )->andReturnUsing( fn() => 'admin' === $this->context );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturnUsing( fn() => 'cron' === $this->context );
		\WP_Mock::userFunction( 'current_user_can' )->andReturnUsing( fn( $cap ) => 'admin' === $this->context && in_array( $cap, [ 'manage_options', Access::TOOLS_CAP ], true ) );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'esc_html__' )->andReturnArg( 0 );
	}

	/**
	 * Run one shared case.
	 *
	 * @dataProvider provide_cases
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param array<string,mixed> $c The case.
	 */
	public function test_shared_case( array $c ): void {
		$given = $c['given'];
		$then  = $c['then'];

		switch ( $c['kind'] ) {
			case 'salts':
				$this->assertSame( $then['salts_in_config'], Secret_Store::salts_usable( $given['constants'] ) );
				return;

			case 'read':
				$this->run_read( $given, $then );
				break;

			case 'write':
				$this->run_write( $given, $then );
				break;

			case 'legacy':
				$this->run_legacy( $given, $then );
				break;

			case 'constant':
				$this->run_constant( $given, $then );
				return;

			default:
				$this->fail( 'Unknown case kind ' . $c['kind'] . ': the adapter must learn it.' );
		}

		$table = serialize( $this->options ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- what the options table would hold.
		foreach ( $then['absent'] ?? [] as $needle ) {
			$this->assertStringNotContainsString( $needle, $table, $c['id'] . ': plain text left in the options table' );
		}
	}

	/**
	 * Write, tamper, read.
	 *
	 * @param array<string,mixed> $given Case input.
	 * @param array<string,mixed> $then  Expected.
	 */
	protected function run_read( array $given, array $then ): void {
		$this->assertTrue( Secret_Store::set( self::READ_OPTION, $given['plain'] ) );
		$row = $this->options[ self::READ_OPTION ];

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- tampering with test ciphertext.
		switch ( $given['tamper'] ) {
			case 'cipher-bit':
			case 'nonce-bit':
				$field          = 'cipher-bit' === $given['tamper'] ? 'c' : 'n';
				$bytes          = base64_decode( $row[ $field ] );
				$i              = (int) $given['byte'];
				$bytes[ $i ]    = chr( ord( $bytes[ $i ] ) ^ 1 );
				$row[ $field ]  = base64_encode( $bytes );
				break;
			case 'truncate-cipher':
				$row['c'] = base64_encode( substr( base64_decode( $row['c'] ), 0, (int) $given['byte'] ) );
				break;
			case 'cipher-not-base64':
				$row['c'] = $given['value'];
				break;
			case 'version':
				$row['v'] = $given['value'];
				break;
			case 'rotate-salts':
				$this->salt = 'fixture-salt-two';
				break;
			case 'none':
				break;
			default:
				$this->fail( 'Unknown tamper ' . $given['tamper'] );
		}
		// phpcs:enable
		$this->options[ self::READ_OPTION ] = $row;
		Secret_Store::reset(); // A new request: the key is derived again from (possibly rotated) salts.

		$this->assertSame( $then['get'], Secret_Store::get( self::READ_OPTION ) );
		if ( array_key_exists( 'unreadable', $then ) ) {
			$this->assertSame( $then['unreadable'], in_array( self::READ_OPTION, Secret_Store::unreadable(), true ) );
		}
		if ( array_key_exists( 'row_keys', $then ) ) {
			$this->assertSame( $then['row_keys'], array_keys( $this->options[ self::READ_OPTION ] ) );
		}
	}

	/**
	 * Seed, then submit through the Settings screen's save handler (5.0).
	 *
	 * @param array<string,mixed> $given Case input.
	 * @param array<string,mixed> $then  Expected.
	 */
	protected function run_write( array $given, array $then ): void {
		$option = Claude_Client::OPTION_API_KEY;
		$key    = static fn( string $v ): string => '' === trim( $v ) ? $v : Claude_Client::KEY_PREFIX . $v;
		if ( '' !== $given['stored'] ) {
			$this->assertTrue( Secret_Store::set( $option, $key( $given['stored'] ) ) );
		}
		$before = $this->options[ $option ] ?? null;

		\WP_Mock::userFunction( 'check_admin_referer' )->andReturn( 1 );
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $s ) => trim( (string) $s ) );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'admin_url' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			function ( $args ) {
				$this->code = (string) ( $args['aisa'] ?? '' );
				return 'url';
			}
		);
		\WP_Mock::userFunction( 'wp_safe_redirect' )->andReturnUsing(
			static function () {
				throw new Redirected( 'redirect' );
			}
		);

		Fixture_Secret_Store::encryption( (bool) $given['encryption'] );
		$_POST = [ 'api_key' => $key( (string) $given['submit'] ) ];
		try {
			( new Settings_Page() )->save();
			$this->fail( 'save() did not redirect' );
		} catch ( Redirected $e ) {
			unset( $e ); // Reached the redirect: the handler finished.
		} finally {
			$_POST = [];
			Fixture_Secret_Store::encryption( true );
		}

		$this->assertSame( $then['accepted'], 'saved' === $this->code, 'accepted' );
		Secret_Store::reset();
		$this->assertSame( '' === $then['get'] ? '' : $key( $then['get'] ), Secret_Store::get( $option ) );
		$this->assertSame( $then['has'], array_key_exists( $option, $this->options ), 'has a row' );
		if ( array_key_exists( 'row_unchanged', $then ) ) {
			$this->assertSame( $then['row_unchanged'], ( $this->options[ $option ] ?? null ) === $before, 'row unchanged' );
		}
	}

	/**
	 * A plain-text secret on an upgraded site, read once in the case's request context.
	 *
	 * @param array<string,mixed> $given Case input.
	 * @param array<string,mixed> $then  Expected.
	 */
	protected function run_legacy( array $given, array $then ): void {
		$this->options = [
			Upgrade::OPTION   => Upgrade::LEVEL,
			self::READ_OPTION => $given['plain'],
		];
		$before         = $this->options;
		$this->writes   = 0;
		$this->context  = $given['context'];
		if ( 'cli' === $given['context'] ) {
			define( 'WP_CLI', true );
		}
		Fixture_Secret_Store::encryption( (bool) $given['encryption'] );

		$this->assertSame( $then['get'], Secret_Store::get( self::READ_OPTION ), 'the key still works' );
		Fixture_Secret_Store::encryption( true );

		if ( $then['sealed'] ) {
			$this->assertTrue( Secret_Store::is_envelope( $this->options[ self::READ_OPTION ] ), 'sealed in place' );
			$this->assertSame( $given['plain'], Secret_Store::get( self::READ_OPTION ) );
		} else {
			$this->assertSame( 0, $this->writes, 'nothing written at all' );
			$this->assertSame( $before, $this->options );
		}
	}

	/**
	 * wp-config.php constant against the stored Claude key.
	 *
	 * @param array<string,mixed> $given Case input.
	 * @param array<string,mixed> $then  Expected.
	 */
	protected function run_constant( array $given, array $then ): void {
		if ( '' !== $given['stored'] ) {
			$this->assertTrue( Secret_Store::set( Claude_Client::OPTION_API_KEY, $given['stored'] ) );
		}
		if ( null !== $given['constant'] ) {
			define( Claude_Client::CONFIG_CONSTANT, $given['constant'] );
		}

		$this->assertSame( $then['resolved'], ( new Claude_Client() )->get_api_key() );
	}
}
