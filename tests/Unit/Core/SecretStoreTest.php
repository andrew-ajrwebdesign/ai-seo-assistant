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
use AJR\SEOAssistant\Core\Secret_Guard;
use AJR\SEOAssistant\Core\Secret_Store;
use AJR\SEOAssistant\Core\Upgrade;
use AJR\SEOAssistant\Report\Access;
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
	 * update_option() calls that changed a row.
	 *
	 * @var int
	 */
	protected int $writes = 0;

	/**
	 * Options deleted, in order.
	 *
	 * @var array<int,string>
	 */
	protected array $deleted = [];

	/**
	 * When true, update_option() fails for every secret option.
	 *
	 * @var bool
	 */
	protected bool $refuse_secret_writes = false;

	/**
	 * Secret writes refused while $refuse_secret_writes is on (each one is a sealing attempt).
	 *
	 * @var int
	 */
	protected int $refused = 0;

	/**
	 * In-memory options and salts.
	 */
	public function setUp(): void {
		parent::setUp();
		Secret_Store::reset();
		$this->options  = [];
		$this->autoload = [];
		$this->salt     = 'salt-A';
		$this->writes   = 0;
		$this->deleted  = [];

		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => array_key_exists( $n, $this->options ) ? $this->options[ $n ] : $d );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $n, $v, $autoload = null ) {
				if ( $this->refuse_secret_writes && isset( Secret_Store::OPTIONS[ $n ] ) ) {
					++$this->refused;
					return false; // Stands in for a server that cannot store the sealed value.
				}
				// WordPress: an unchanged value is not written (and its autoload flag is not touched); a
				// changed one takes the $autoload given, on an existing row too.
				if ( array_key_exists( $n, $this->options ) && $this->options[ $n ] === $v ) {
					return false;
				}
				++$this->writes;
				$this->options[ $n ] = $v;
				if ( null !== $autoload || ! array_key_exists( $n, $this->autoload ) ) {
					$this->autoload[ $n ] = $autoload;
				}
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
				$this->deleted[] = $n;
				unset( $this->options[ $n ], $this->autoload[ $n ] );
				return true;
			}
		);
		\WP_Mock::userFunction( 'wp_salt' )->andReturnUsing( fn( $scheme ) => $this->salt . '-' . $scheme );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.
		// Request context: a visitor's page view by default; tests switch it.
		\WP_Mock::userFunction( 'is_admin' )->andReturnUsing( fn() => $this->context['admin'] );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturnUsing( fn() => $this->context['cron'] );
		\WP_Mock::userFunction( 'current_user_can' )->andReturnUsing( fn( $cap ) => in_array( $cap, $this->context['caps'], true ) );
		$this->context = [
			'admin' => false,
			'cron'  => false,
			'caps'  => [],
		];
	}

	/**
	 * The request the code under test believes it is in.
	 *
	 * @var array{admin:bool,cron:bool,caps:array<int,string>}
	 */
	protected array $context = [
		'admin' => false,
		'cron'  => false,
		'caps'  => [],
	];

	/**
	 * What core's update_option() does with a secret option's filters, in core's order
	 * (wp-includes/option.php): sanitize_option_{name}, then pre_update_option_{name} with the old value,
	 * then "unchanged means no write". The filters are the ones Secret_Guard registers.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value submitted.
	 * @return bool Whether the row changed.
	 */
	protected function core_update_option( string $option, $value ): bool {
		$value = Secret_Guard::on_sanitize( $value, $option );
		$old   = array_key_exists( $option, $this->options ) ? $this->options[ $option ] : false;
		$value = Secret_Guard::on_update( $value, $old );
		if ( $value === $old ) {
			return false;
		}
		$this->options[ $option ] = $value;

		return true;
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
	 * A fake $wpdb for the 5.0 upgrade step: the 4.x redirects table is absent unless told otherwise.
	 *
	 * @param string|null $table      What SHOW TABLES answers.
	 * @param int         $enabled    Enabled rules in it.
	 */
	protected function fake_db( ?string $table = null, int $enabled = 0 ): object {
		$wpdb = new class( $table, $enabled ) {
			/** @var string */
			public $prefix = 'wp_';
			/** @var int */
			public $checks = 0;
			/** @var string|null */
			public $table;
			/** @var int */
			public $enabled;
			/** @var array<int,string> */
			public $queries = [];
			public function __construct( $table, $enabled ) {
				$this->table   = $table;
				$this->enabled = $enabled;
			}
			public function esc_like( $s ) {
				return $s;
			}
			public function prepare( $q, ...$a ) {
				return $q;
			}
			public function get_charset_collate() {
				return '';
			}
			public function get_var( $q ) {
				++$this->checks;
				return $this->table;
			}
			public function get_col( $q ) {
				return array_fill( 0, $this->enabled, '/old/' );
			}
			public function query( $q ) {
				$this->queries[] = $q;
				return true;
			}
		};
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.

		return $wpdb;
	}

	/**
	 * Google's revoke endpoint, recorded.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	protected array $revoked = [];

	/**
	 * Mock the HTTP calls the 5.0 step makes (the Google revoke).
	 */
	protected function fake_http(): void {
		\WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing(
			function ( $url, $args ) {
				$this->revoked[] = [ $url, $args ];
				return [ 'response' => [ 'code' => 200 ] ];
			}
		);
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
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
		$this->fake_db();
		$this->fake_http();

		$results = Upgrade::run();

		$this->assertSame( 'migrated', $results['ai_seo_assistant_anthropic_api_key'] );
		$this->assertSame( 'migrated', $results['ai_seo_assistant_report_key'] );
		$this->assertSame( 'migrated', $results['ai_seo_assistant_gsc_token_data'] );
		$this->assertSame( 'absent', $results['ai_seo_assistant_gsc_client_secret'] );
		foreach ( [ 'ai_seo_assistant_anthropic_api_key', 'ai_seo_assistant_report_key' ] as $option ) {
			$this->assertTrue( Secret_Store::is_envelope( $this->options[ $option ] ), $option . ' sealed' );
			$this->assertFalse( $this->autoload[ $option ], $option . ' not autoloaded' );
		}
		$this->assertStringNotContainsString( 'TESTONLY', serialize( $this->options ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the whole fake table.
		$this->assertSame( self::CLAUDE, Secret_Store::get( 'ai_seo_assistant_anthropic_api_key' ) );
		// 5.0: the Search Console grant was revoked at Google (with the sealed refresh token) and deleted.
		$this->assertSame( Upgrade::REVOKE_URL, $this->revoked[0][0] ?? '' );
		$this->assertSame( '1//r', $this->revoked[0][1]['body']['token'] ?? '' );
		$this->assertLessThanOrEqual( 3, $this->revoked[0][1]['timeout'] );
		$this->assertArrayNotHasKey( 'ai_seo_assistant_gsc_token_data', $this->options );
		$this->assertSame( Upgrade::LEVEL, $this->options[ Upgrade::OPTION ] );
		$this->assertSame( [], $this->options['ai_seo_assistant_agency_users'], 'agency option created, autoloaded' );
		$this->assertTrue( $this->autoload['ai_seo_assistant_agency_users'] );

		// Idempotent: a second pass changes nothing.
		$before = $this->options;
		$this->assertSame( 'sealed', Secret_Store::migrate( 'ai_seo_assistant_anthropic_api_key' ) );
		$this->assertSame( $before['ai_seo_assistant_anthropic_api_key'], $this->options['ai_seo_assistant_anthropic_api_key'] );
	}

	/**
	 * Settings (5.0, Settings_Page::save()): a blank submit keeps the stored key; a non-key is refused and the
	 * old one kept; a new key is stored sealed; "Clear" removes it.
	 */
	public function test_settings_field_is_write_only(): void {
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $s ) => trim( (string) $s ) );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'check_admin_referer' )->andReturn( 1 );
		\WP_Mock::userFunction( 'admin_url' )->andReturnArg( 0 );
		$code = '';
		\WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			function ( $args ) use ( &$code ) {
				$code = (string) ( $args['aisa'] ?? '' );
				return 'url';
			}
		);
		\WP_Mock::userFunction( 'wp_safe_redirect' )->andReturnUsing(
			static function () {
				throw new \RuntimeException( 'redirect' );
			}
		);
		$this->context['caps'] = [ 'manage_options', \AJR\SEOAssistant\Report\Access::TOOLS_CAP ];
		$save = function ( array $post ) {
			$_POST = $post;
			try {
				( new \AJR\SEOAssistant\Admin\Settings_Page() )->save();
			} catch ( \RuntimeException $e ) {
				unset( $e );
			} finally {
				$_POST = [];
			}
		};

		Secret_Store::set( Claude_Client::OPTION_API_KEY, self::CLAUDE );
		$stored = $this->options[ Claude_Client::OPTION_API_KEY ];

		$save( [ 'api_key' => '' ] );
		$this->assertSame( $stored, $this->options[ Claude_Client::OPTION_API_KEY ], 'empty submit keeps the stored value' );
		$save( [ 'api_key' => 'not-a-key' ] );
		$this->assertSame( 'badkey', $code );
		$this->assertSame( $stored, $this->options[ Claude_Client::OPTION_API_KEY ], 'a non-key keeps the stored value' );

		$save( [ 'api_key' => 'sk-ant-api03-NEWKEY000000000000000000' ] );
		$this->assertSame( 'saved', $code );
		$this->assertTrue( Secret_Store::is_envelope( $this->options[ Claude_Client::OPTION_API_KEY ] ), 'a new key is stored sealed' );
		$this->assertSame( 'sk-ant-api03-NEWKEY000000000000000000', Secret_Store::get( Claude_Client::OPTION_API_KEY ) );

		$save( [ 'clear_api_key' => '1' ] );
		$this->assertSame( 'cleared', $code );
		$this->assertArrayNotHasKey( Claude_Client::OPTION_API_KEY, $this->options, 'Clear removes it' );
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
		foreach ( [ 'AJR\SEOAssistant\AI\Claude_Client', 'AJR\SEOAssistant\Report\Push_Key' ] as $class ) {
			$short = substr( (string) strrchr( '\\' . $class, '\\' ), 1 );
			foreach ( ( new \ReflectionClass( $class ) )->getConstants() as $name => $value ) {
				if ( in_array( $value, $names, true ) ) {
					$constants[]                  = $short . '::' . $name;
					$own[ $short . '.php' ][] = 'self::' . $name;
					$own[ $short . '.php' ][] = 'static::' . $name;
				}
			}
		}
		$this->assertCount( count( array_diff( $names, Upgrade::GSC_OPTIONS ) ), $constants, 'every live secret option has a class constant to look for (5.0: the Google ones exist only to be deleted)' );

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
	 * Round 2, sec M1: replacing a key is ONE write to the same row (never delete-then-add, which left a
	 * moment with no key and lost it if the add failed), and the row ends up non-autoloaded even when it
	 * was autoloaded before (update_option() applies $autoload because a fresh nonce always changes it).
	 */
	public function test_set_replaces_in_one_write(): void {
		$this->options[ Claude_Client::OPTION_API_KEY ]  = self::CLAUDE; // A 4.3.x row, autoloaded.
		$this->autoload[ Claude_Client::OPTION_API_KEY ] = true;

		$this->assertTrue( Secret_Store::set( Claude_Client::OPTION_API_KEY, self::CLAUDE ) );
		$this->assertSame( [], $this->deleted, 'never deleted on the way' );
		$this->assertSame( 1, $this->writes );
		$this->assertFalse( $this->autoload[ Claude_Client::OPTION_API_KEY ], 'non-autoloaded after the write' );

		$first = $this->options[ Claude_Client::OPTION_API_KEY ];
		$this->assertTrue( Secret_Store::set( Claude_Client::OPTION_API_KEY, self::CLAUDE ), 'same secret again still writes (new nonce)' );
		$this->assertNotSame( $first, $this->options[ Claude_Client::OPTION_API_KEY ] );
		$this->assertSame( self::CLAUDE, Secret_Store::get( Claude_Client::OPTION_API_KEY ) );
	}

	/**
	 * Round 2, sec M2: options.php's generic form (`option_page=options&page_options=...`) posted by an
	 * Administrator WITHOUT the tools capability (the client) changes no secret, the push key included
	 * (it has no registered setting). Emulates options.php: each name in page_options is update_option()'d
	 * with its $_POST value.
	 */
	public function test_generic_options_form_cannot_replace_a_secret(): void {
		Secret_Store::set( 'ai_seo_assistant_report_key', 'agency-push-key-0123456789abcdef' );
		Secret_Store::set( Claude_Client::OPTION_API_KEY, self::CLAUDE );
		$before = $this->options;

		$this->context['admin'] = true;
		$this->context['caps']  = [ 'manage_options' ]; // The client's Administrator.
		$post = [
			'option_page'                        => 'options',
			'page_options'                       => 'ai_seo_assistant_report_key,ai_seo_assistant_anthropic_api_key,ai_seo_assistant_gsc_client_secret',
			'ai_seo_assistant_report_key'        => 'attacker-chosen-key',
			'ai_seo_assistant_anthropic_api_key' => 'sk-ant-attacker',
			'ai_seo_assistant_gsc_client_secret' => 'attacker-secret',
		];
		$changed = 0;
		foreach ( explode( ',', $post['page_options'] ) as $option ) {
			$changed += (int) $this->core_update_option( $option, $post[ $option ] );
		}

		$this->assertSame( 0, $changed, 'no secret row changed' );
		$this->assertSame( $before, $this->options );
		$this->assertArrayNotHasKey( 'ai_seo_assistant_gsc_client_secret', $this->options, 'an absent secret is not created either' );
		$this->assertSame( '', Secret_Guard::on_sanitize( 'attacker', 'ai_seo_assistant_gsc_client_secret' ), "add_option() path: an absent secret stays empty" );
		$this->assertSame( 'agency-push-key-0123456789abcdef', Secret_Store::get( 'ai_seo_assistant_report_key' ) );
	}

	/**
	 * An allowed writer (tools capability) who writes plain text through any path gets it sealed; a value
	 * that is already sealed, or empty (Clear), passes as is; Secret_Store's own writes pass in any context.
	 */
	public function test_guard_seals_plain_text_from_allowed_writers(): void {
		$this->context['caps'] = [ 'manage_options', Access::TOOLS_CAP ];

		$this->assertTrue( $this->core_update_option( 'ai_seo_assistant_report_key', 'typed-into-options-php-0123' ) );
		$row = $this->options['ai_seo_assistant_report_key'];
		$this->assertTrue( Secret_Store::is_envelope( $row ), 'sealed on the way in' );
		$this->assertSame( 'typed-into-options-php-0123', Secret_Store::open( $row ) );

		$this->assertTrue( $this->core_update_option( 'ai_seo_assistant_gsc_token_data', [ 'refresh_token' => '1//x' ] ) );
		$this->assertSame( [ 'refresh_token' => '1//x' ], Secret_Store::get_array( 'ai_seo_assistant_gsc_token_data' ), 'arrays sealed as JSON' );

		$this->assertSame( $row, Secret_Guard::on_update( $row, 'old' ), 'an envelope passes' );
		$this->assertSame( '', Secret_Guard::on_update( '', $row ), 'Clear passes' );

		// Cron with no user: refused from outside, allowed through Secret_Store (e.g. a token refresh).
		$this->context = [
			'admin' => false,
			'cron'  => true,
			'caps'  => [],
		];
		$this->assertSame( $row, Secret_Guard::on_update( 'plain-from-another-plugin', $row ), 'cron write from outside refused' );
		$this->assertTrue( Secret_Store::set_array( 'ai_seo_assistant_gsc_token_data', [ 'refresh_token' => '1//y' ] ) );
		$this->assertSame( [ 'refresh_token' => '1//y' ], Secret_Store::get_array( 'ai_seo_assistant_gsc_token_data' ) );
	}

	/**
	 * WP-CLI may write a secret (whoever runs it holds wp-config.php), and it is sealed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_guard_allows_and_seals_wp_cli(): void {
		define( 'WP_CLI', true );
		$this->assertTrue( $this->core_update_option( Claude_Client::OPTION_API_KEY, self::CLAUDE ) );
		$this->assertSame( self::CLAUDE, Secret_Store::open( $this->options[ Claude_Client::OPTION_API_KEY ] ) );
	}

	/**
	 * Every secret option gets both filters, hooked by Secret_Guard.
	 */
	public function test_guard_hooks_every_secret_option(): void {
		foreach ( array_keys( Secret_Store::OPTIONS ) as $option ) {
			\WP_Mock::expectFilterAdded( 'sanitize_option_' . $option, [ Secret_Guard::class, 'on_sanitize' ], 99, 2 );
			\WP_Mock::expectFilterAdded( 'pre_update_option_' . $option, [ Secret_Guard::class, 'on_update' ], 99, 2 );
		}
		( new Secret_Guard() )->register();
		$this->assertSame( Secret_Guard::OPTIONS, Secret_Store::OPTIONS, 'one list' );
		$this->assertCount( 5, Secret_Store::OPTIONS, 'coverage: every secret option' );
	}

	/**
	 * Round 2, sec L1: a plain-text secret met after the upgrade (an update run from cron or WP-CLI, a
	 * restored backup) is sealed by get() in wp-admin, cron or WP-CLI, and the agency is told; never on a
	 * visitor's page view, and never before the upgrade (Upgrade::run() owns that).
	 */
	public function test_get_reseals_plain_text_after_the_upgrade(): void {
		$this->options[ Upgrade::OPTION ]                 = Upgrade::LEVEL;
		$this->options[ Claude_Client::OPTION_API_KEY ]   = self::CLAUDE;
		$this->options['ai_seo_assistant_gsc_token_data'] = [ 'refresh_token' => '1//r' ];

		// A visitor's page view: read, never written.
		$this->assertSame( self::CLAUDE, Secret_Store::get( Claude_Client::OPTION_API_KEY ) );
		$this->assertSame( self::CLAUDE, $this->options[ Claude_Client::OPTION_API_KEY ], 'front end: no write' );
		$this->assertSame( 0, $this->writes );

		// Below the upgrade level: left to Upgrade::run().
		$this->context['admin']           = true;
		$this->options[ Upgrade::OPTION ] = '4.0.0';
		Secret_Store::get( Claude_Client::OPTION_API_KEY );
		$this->assertSame( 0, $this->writes, 'below the level: no write' );

		// wp-admin after the upgrade: sealed, value unchanged, the agency told once.
		$this->options[ Upgrade::OPTION ] = Upgrade::LEVEL;
		$this->assertSame( self::CLAUDE, Secret_Store::get( Claude_Client::OPTION_API_KEY ) );
		$this->assertTrue( Secret_Store::is_envelope( $this->options[ Claude_Client::OPTION_API_KEY ] ) );
		$this->assertFalse( $this->autoload[ Claude_Client::OPTION_API_KEY ] );
		$this->assertSame( self::CLAUDE, Secret_Store::get( Claude_Client::OPTION_API_KEY ) );

		// Cron: the token data too.
		$this->context['admin'] = false;
		$this->context['cron']  = true;
		$this->assertSame( [ 'refresh_token' => '1//r' ], Secret_Store::get_array( 'ai_seo_assistant_gsc_token_data' ) );
		$this->assertTrue( Secret_Store::is_envelope( $this->options['ai_seo_assistant_gsc_token_data'] ) );

		$this->assertSame( [ Claude_Client::OPTION_API_KEY, 'ai_seo_assistant_gsc_token_data' ], $this->options[ Secret_Store::RESEALED_OPTION ] );
		$this->assertFalse( $this->autoload[ Secret_Store::RESEALED_OPTION ], 'the notice list is not autoloaded' );
		$this->assertStringNotContainsString( 'TESTONLY', serialize( $this->options ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the whole fake table.
	}

	/**
	 * Round 2, sec L3: salts that exist but share a value are not salts (wp_salt() treats a duplicate as
	 * unset and falls back to values it keeps in the database).
	 */
	public function test_duplicate_salts_are_not_in_config(): void {
		// salts_in_config() must read every constant wp_salt() compares, SECRET_* included.
		$core = [];
		foreach ( [ 'AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE', 'SECRET' ] as $first ) {
			foreach ( [ 'KEY', 'SALT' ] as $second ) {
				$core[] = $first . '_' . $second;
			}
		}
		$this->assertEqualsCanonicalizing( $core, Secret_Store::SALT_CONSTANTS );

		$good = [];
		foreach ( Secret_Store::SALT_CONSTANTS as $i => $constant ) {
			$good[ $constant ] = 'unique-phrase-' . $i;
		}
		$this->assertTrue( Secret_Store::salts_usable( $good ) );

		$dup                  = $good;
		$dup['NONCE_SALT']    = $good['AUTH_KEY']; // A salt the key does not use, duplicating one it does.
		$this->assertFalse( Secret_Store::salts_usable( $dup ), 'AUTH_KEY shared with NONCE_SALT' );

		$same = array_fill_keys( Secret_Store::SALT_CONSTANTS, 'one phrase pasted eight times' );
		$this->assertFalse( Secret_Store::salts_usable( $same ) );

		$dup_unused                   = $good;
		$dup_unused['LOGGED_IN_SALT'] = $good['NONCE_KEY']; // Two salts the key does not use.
		$this->assertTrue( Secret_Store::salts_usable( $dup_unused ), 'only the four the key is made from matter' );

		$sample              = $good;
		$sample['AUTH_SALT'] = 'put your unique phrase here';
		$this->assertFalse( Secret_Store::salts_usable( $sample ) );

		$missing = $good;
		unset( $missing['SECURE_AUTH_KEY'] );
		$this->assertFalse( Secret_Store::salts_usable( $missing ) );
	}

	/**
	 * Round 2, perf 6: when a secret cannot be sealed the attempt is recorded, the level stays put, the
	 * redirects-table check runs ONCE (not on every admin load), and the next admin loads skip the step
	 * for a day. A later success clears the record.
	 */
	public function test_failed_upgrade_is_recorded_and_not_retried_every_load(): void {
		$this->options = [
			'ai_seo_assistant_anthropic_api_key' => self::CLAUDE,
			Upgrade::OPTION                      => '4.0.0',
		];
		$this->context['caps'] = [ 'manage_options' ];
		// 5.0: a 4.x redirects table with 3 enabled rules (counted table checks).
		$wpdb = $this->fake_db( 'wp_ai_seo_assistant_redirects', 3 );
		$this->fake_http();

		// The database refuses every write to the secret rows (stands in for "cannot seal").
		$this->refuse_secret_writes = true;

		$upgrade = new Upgrade();
		$upgrade->maybe_run();

		$this->assertSame( '4.0.0', $this->options[ Upgrade::OPTION ], 'level not advanced' );
		$attempt = $this->options[ Upgrade::ATTEMPT_OPTION ];
		$this->assertSame( [ 'ai_seo_assistant_anthropic_api_key' ], $attempt['failed'] );
		$this->assertTrue( $this->autoload[ Upgrade::ATTEMPT_OPTION ] );
		$this->assertSame( 0, $wpdb->checks, 'no 5.0 step (and no table check) while sealing fails' );
		$this->assertSame( 1, $this->refused, 'one sealing attempt' );

		// The next admin loads, within the day: nothing runs.
		$upgrade->maybe_run();
		$upgrade->maybe_run();
		$this->assertSame( 0, $wpdb->checks, 'no table check on every admin load' );
		$this->assertSame( 1, $this->refused, 'no sealing attempt on every admin load' );
		$this->assertTrue( Upgrade::waiting() );

		// A day later: the secrets are tried again, the table check is not.
		$this->options[ Upgrade::ATTEMPT_OPTION ]['at'] = time() - Upgrade::RETRY_AFTER - 1;
		$upgrade->maybe_run();
		$this->assertSame( 0, $wpdb->checks, 'still no table check while sealing fails' );
		$this->assertGreaterThan( time() - 60, $this->options[ Upgrade::ATTEMPT_OPTION ]['at'], 'attempt re-recorded' );

		// Writes work again: the next retry succeeds and clears the record.
		$this->refuse_secret_writes = false;
		$this->options[ Upgrade::ATTEMPT_OPTION ]['at'] = time() - Upgrade::RETRY_AFTER - 1;
		$upgrade->maybe_run();
		$this->assertSame( Upgrade::LEVEL, $this->options[ Upgrade::OPTION ] );
		$this->assertArrayNotHasKey( Upgrade::ATTEMPT_OPTION, $this->options );
		$this->assertTrue( Secret_Store::is_envelope( $this->options['ai_seo_assistant_anthropic_api_key'] ) );
		// 5.0 step: the table holds enabled rules, so it is kept and the redirect part stays pending.
		$this->assertSame( 3, $this->options[ Upgrade::REDIRECTS_PENDING ] );
		$this->assertSame( [], array_filter( $wpdb->queries, static fn( $q ) => false !== stripos( $q, 'DROP TABLE' ) ), 'never dropped with enabled rules' );
		unset( $GLOBALS['wpdb'] );
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
