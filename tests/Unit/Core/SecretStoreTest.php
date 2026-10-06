<?php
/**
 * Tests for Core\Secret_Store (4.4.0): secrets sealed at rest, and every path that stores one.
 *
 * Covers the contract's list: round trip, tamper → failure, constant precedence, empty submit keeps the
 * value, migration from plain text. Plus two structural guards: nothing in src/ writes a secret option
 * with update_option()/add_option() (only Secret_Store may), and uninstall.php deletes every secret.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Core;

use AJR\SEOAssistant\Admin\Admin;
use AJR\SEOAssistant\AI\Claude_Client;
use AJR\SEOAssistant\Core\Secret_Store;
use AJR\SEOAssistant\Core\Upgrade;
use WP_Mock\Tools\TestCase;

/**
 * Secret_Store with options and salts faked in memory.
 */
class SecretStoreTest extends TestCase {

	/** A key in Anthropic's shape (not a real key). */
	protected const CLAUDE = 'sk-ant-api03-TESTONLY0000000000000000000000000000000000000000-abcdAAAA';

	/**
	 * The fake options table.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Autoload flag per option, as add_option() was told.
	 *
	 * @var array<string,mixed>
	 */
	protected array $autoload = [];

	/**
	 * The salt wp_salt() returns (changed to simulate rotated salts).
	 *
	 * @var string
	 */
	protected string $salt = 'salt-A';

	/**
	 * In-memory options and salts.
	 */
	public function setUp(): void {
		parent::setUp();
		Secret_Store::reset();
		$this->options  = [];
		$this->autoload = [];
		$this->salt     = 'salt-A';

		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => array_key_exists( $n, $this->options ) ? $this->options[ $n ] : $d );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $n, $v ) {
				$this->options[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'add_option' )->andReturnUsing(
			function ( $n, $v, $deprecated = '', $autoload = null ) {
				if ( array_key_exists( $n, $this->options ) ) {
					return false;
				}
				$this->options[ $n ]  = $v;
				$this->autoload[ $n ] = $autoload;
				return true;
			}
		);
		\WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $n ) {
				unset( $this->options[ $n ], $this->autoload[ $n ] );
				return true;
			}
		);
		\WP_Mock::userFunction( 'wp_salt' )->andReturnUsing( fn( $scheme ) => $this->salt . '-' . $scheme );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.
	}

	/**
	 * Sealed, stored non-autoloaded as an array envelope, and read back; the plaintext is nowhere in the row.
	 */
	public function test_round_trip(): void {
		$this->assertTrue( Secret_Store::set( Claude_Client::OPTION_API_KEY, self::CLAUDE ) );

		$row = $this->options[ Claude_Client::OPTION_API_KEY ];
		$this->assertTrue( Secret_Store::is_envelope( $row ) );
		$this->assertSame( [ 'v', 'n', 'c' ], array_keys( $row ) );
		$this->assertFalse( $this->autoload[ Claude_Client::OPTION_API_KEY ], 'never autoloaded' );
		$this->assertStringNotContainsString( 'TESTONLY', serialize( $row ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- what the row would hold.
		$this->assertSame( self::CLAUDE, Secret_Store::get( Claude_Client::OPTION_API_KEY ) );
		$this->assertSame( 'AAAA', Secret_Store::last4( Claude_Client::OPTION_API_KEY ) );

		// A fresh nonce per write: the same secret never seals to the same bytes twice.
		$again = Secret_Store::seal( self::CLAUDE );
		$this->assertNotSame( $row['n'], $again['n'] );
		$this->assertNotSame( $row['c'], $again['c'] );
	}

	/**
	 * Structured data (OAuth tokens) round-trips as sealed JSON.
	 */
	public function test_array_round_trip(): void {
		$tokens = [
			'access_token'  => 'ya29.test',
			'refresh_token' => '1//test-refresh',
			'expires_at'    => 123,
		];
		Secret_Store::set_array( 'ai_seo_assistant_gsc_token_data', $tokens );

		$this->assertTrue( Secret_Store::is_envelope( $this->options['ai_seo_assistant_gsc_token_data'] ) );
		$this->assertSame( $tokens, Secret_Store::get_array( 'ai_seo_assistant_gsc_token_data' ) );
	}

	/**
	 * A changed byte, or rotated salts, read as "no secret" — never an exception, never garbage — and
	 * the option is reported unreadable for the agency notice.
	 */
	public function test_tamper_and_rotated_salts_fail_closed(): void {
		Secret_Store::set( Claude_Client::OPTION_API_KEY, self::CLAUDE );
		$good = $this->options[ Claude_Client::OPTION_API_KEY ];

		$bad      = $good;
		$cipher   = base64_decode( $bad['c'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- flipping one byte of the test ciphertext.
		$cipher[3] = chr( ord( $cipher[3] ) ^ 1 );
		$bad['c'] = base64_encode( $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- see above.
		$this->options[ Claude_Client::OPTION_API_KEY ] = $bad;
		$this->assertSame( '', Secret_Store::get( Claude_Client::OPTION_API_KEY ), 'tampered' );
		$this->assertSame( [ Claude_Client::OPTION_API_KEY ], Secret_Store::unreadable() );

		$this->options[ Claude_Client::OPTION_API_KEY ] = [ 'v' => 1, 'n' => 'short', 'c' => 'x' ];
		Secret_Store::reset();
		$this->assertSame( '', Secret_Store::get( Claude_Client::OPTION_API_KEY ), 'malformed' );

		$this->options[ Claude_Client::OPTION_API_KEY ] = $good;
		$this->salt = 'salt-B';
		Secret_Store::reset();
		$this->assertSame( '', Secret_Store::get( Claude_Client::OPTION_API_KEY ), 'salts rotated' );

		$this->salt = 'salt-A';
		Secret_Store::reset();
		$this->assertSame( self::CLAUDE, Secret_Store::get( Claude_Client::OPTION_API_KEY ), 'the right salts open it again' );
		$this->assertSame( [], Secret_Store::unreadable() );
	}

	/**
	 * The plain-text rows of 4.3.x are sealed in place, once; the plain text is gone; the value is kept.
	 */
	public function test_migration_from_plaintext(): void {
		$this->options = [
			'ai_seo_assistant_anthropic_api_key' => self::CLAUDE,
			'ai_seo_assistant_report_key'        => 'k3y-for-tests_0123456789abcdefghijklmnopqrs',
			'ai_seo_assistant_gsc_token_data'    => [ 'refresh_token' => '1//r' ],
			'ai_seo_assistant_settings_version'  => '4.0.0',
		];
		\WP_Mock::onFilter( 'ai_seo_assistant_core_owns_redirects' )->with( false )->reply( false );

		$results = Upgrade::run();

		$this->assertSame( 'migrated', $results['ai_seo_assistant_anthropic_api_key'] );
		$this->assertSame( 'migrated', $results['ai_seo_assistant_report_key'] );
		$this->assertSame( 'migrated', $results['ai_seo_assistant_gsc_token_data'] );
		$this->assertSame( 'absent', $results['ai_seo_assistant_gsc_client_secret'] );
		foreach ( [ 'ai_seo_assistant_anthropic_api_key', 'ai_seo_assistant_report_key', 'ai_seo_assistant_gsc_token_data' ] as $option ) {
			$this->assertTrue( Secret_Store::is_envelope( $this->options[ $option ] ), $option . ' sealed' );
			$this->assertFalse( $this->autoload[ $option ], $option . ' not autoloaded' );
		}
		$this->assertStringNotContainsString( 'TESTONLY', serialize( $this->options ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the whole fake table.
		$this->assertSame( self::CLAUDE, Secret_Store::get( 'ai_seo_assistant_anthropic_api_key' ) );
		$this->assertSame( [ 'refresh_token' => '1//r' ], Secret_Store::get_array( 'ai_seo_assistant_gsc_token_data' ) );
		$this->assertSame( Upgrade::LEVEL, $this->options[ Upgrade::OPTION ] );
		$this->assertSame( [], $this->options['ai_seo_assistant_agency_users'], 'agency option created, autoloaded' );
		$this->assertTrue( $this->autoload['ai_seo_assistant_agency_users'] );

		// Idempotent: a second pass changes nothing.
		$before = $this->options;
		$this->assertSame( 'sealed', Secret_Store::migrate( 'ai_seo_assistant_anthropic_api_key' ) );
		$this->assertSame( $before['ai_seo_assistant_anthropic_api_key'], $this->options['ai_seo_assistant_anthropic_api_key'] );
	}

	/**
	 * Settings field: a blank submit keeps the stored value; a new key is returned sealed; a non-key is
	 * refused and the old one kept; "Clear" empties it; an envelope passes through (add_option's second
	 * sanitize pass).
	 */
	public function test_settings_field_is_write_only(): void {
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'add_settings_error' );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		$admin = new Admin( null, null, null, null, new Claude_Client() );

		Secret_Store::set( Claude_Client::OPTION_API_KEY, self::CLAUDE );
		$stored = $this->options[ Claude_Client::OPTION_API_KEY ];

		$this->assertSame( $stored, $admin->sanitize_api_key( '' ), 'empty submit keeps the stored value' );
		$this->assertSame( $stored, $admin->sanitize_api_key( 'not-a-key' ), 'a non-key keeps the stored value' );

		$new = $admin->sanitize_api_key( 'sk-ant-api03-NEWKEY000000000000000000' );
		$this->assertTrue( Secret_Store::is_envelope( $new ), 'a new key is returned sealed' );
		$this->assertSame( 'sk-ant-api03-NEWKEY000000000000000000', Secret_Store::open( $new ) );
		$this->assertSame( $new, $admin->sanitize_api_key( $new ), 'an envelope passes straight through' );

		$_POST[ Claude_Client::OPTION_API_KEY . '_clear' ] = '1';
		$this->assertSame( '', $admin->sanitize_api_key( '' ), 'Clear empties it' );
		unset( $_POST[ Claude_Client::OPTION_API_KEY . '_clear' ] );
	}

	/**
	 * The wp-config constant wins over a stored key.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_wins(): void {
		Secret_Store::set( Claude_Client::OPTION_API_KEY, self::CLAUDE );
		$client = new Claude_Client();
		$this->assertSame( self::CLAUDE, $client->get_api_key() );

		define( 'AI_SEO_ASSISTANT_ANTHROPIC_API_KEY', 'sk-ant-from-config-0000' );
		$this->assertSame( 'sk-ant-from-config-0000', $client->get_api_key() );
		$this->assertTrue( $client->has_config_key() );
	}

	/**
	 * ⛔ Structural guard: only Secret_Store writes a secret option. A plain update_option()/add_option()
	 * on one of them anywhere in src/ is how a plaintext key would come back.
	 */
	public function test_no_direct_writes_to_secret_options(): void {
		$names     = array_keys( Secret_Store::OPTIONS );
		$constants = []; // "Class::NAME" usable from any file.
		$own       = []; // File name => [ "self::NAME", ... ] usable only inside the defining class.
		foreach ( [ 'AJR\SEOAssistant\AI\Claude_Client', 'AJR\SEOAssistant\GSC\GSC_Client', 'AJR\SEOAssistant\Report\Push_Key' ] as $class ) {
			$short = substr( (string) strrchr( '\\' . $class, '\\' ), 1 );
			foreach ( ( new \ReflectionClass( $class ) )->getConstants() as $name => $value ) {
				if ( in_array( $value, $names, true ) ) {
					$constants[]                  = $short . '::' . $name;
					$own[ $short . '.php' ][] = 'self::' . $name;
					$own[ $short . '.php' ][] = 'static::' . $name;
				}
			}
		}
		$this->assertCount( count( $names ), $constants, 'every secret option has a class constant to look for' );

		$src     = dirname( __DIR__, 3 ) . '/src';
		$scanned = 0;
		$hits    = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' !== $file->getExtension() || 'Secret_Store.php' === $file->getFilename() ) {
				continue;
			}
			++$scanned;
			$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
			if ( ! preg_match_all( '/\b(?:update_option|add_option)\s*\(\s*([^,]+?)\s*,/', $code, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $arg ) {
				$arg = trim( $arg, " \t'\"" );
				if ( in_array( $arg, $names, true ) || in_array( $arg, $constants, true ) || in_array( $arg, $own[ $file->getFilename() ] ?? [], true ) ) {
					$hits[] = $file->getFilename() . ': ' . $arg;
				}
			}
		}
		$this->assertGreaterThan( 40, $scanned, 'scanned the source tree (coverage)' );
		$this->assertSame( [], $hits, 'secret options written without Secret_Store' );
	}

	/**
	 * uninstall.php deletes every secret option.
	 */
	public function test_uninstall_deletes_every_secret(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source.
		foreach ( array_keys( Secret_Store::OPTIONS ) as $option ) {
			$this->assertStringContainsString( "'" . $option . "'", $uninstall, $option . ' is deleted on uninstall' );
		}
	}
}
