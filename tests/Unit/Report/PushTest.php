<?php
/**
 * Tests for Report\Push_Key and Report\Push_Endpoint — who may deliver a report, and what is stored.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\Report;

use AJR\SEOAssistant\Report\Push_Endpoint;
use AJR\SEOAssistant\Report\Push_Key;
use AJR\SEOAssistant\Report\Snapshot_Store;
use WP_Mock\Tools\TestCase;

/**
 * Push signing and the REST endpoint.
 */
class PushTest extends TestCase {

	/** A 43-character key, as generate() makes. */
	protected const KEY = 'k3y-for-tests_0123456789abcdefghijklmnopqrs';

	/**
	 * Fake options and transients.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Fake transients.
	 *
	 * @var array<string,mixed>
	 */
	protected array $transients = [];

	/**
	 * In-memory WordPress.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->options    = [ Push_Key::OPTION => self::KEY ];
		$this->transients = [];
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => $this->options[ $n ] ?? $d );
		\WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $n, $v ) {
				$this->options[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'add_option' )->andReturnUsing(
			function ( $n, $v ) {
				if ( array_key_exists( $n, $this->options ) ) {
					return false;
				}
				$this->options[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $n ) {
				unset( $this->options[ $n ] );
				return true;
			}
		);
		\WP_Mock::userFunction( 'wp_salt' )->andReturnUsing( fn( $scheme ) => 'test-salt-' . $scheme );
		\AJR\SEOAssistant\Core\Secret_Store::reset();
		\WP_Mock::userFunction( 'get_transient' )->andReturnUsing( fn( $n ) => $this->transients[ $n ] ?? false );
		\WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			function ( $n, $v ) {
				$this->transients[ $n ] = $v;
				return true;
			}
		);
		\WP_Mock::userFunction( 'home_url' )->andReturn( 'https://www.Northfield.example/' );
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $part = -1 ) => parse_url( $url, $part ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test double for wp_parse_url.
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'wp_hash' )->andReturnUsing( fn( $d ) => md5( 'salt' . $d ) );
	}

	/**
	 * The fixture week as a JSON body.
	 */
	protected function body(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-week.json' );
	}

	/**
	 * A request signed with a key at a time.
	 *
	 * @param string   $body Body.
	 * @param string   $key  Key.
	 * @param int|null $time Signing time (default now).
	 */
	protected function request( string $body, string $key = self::KEY, ?int $time = null ): \WP_REST_Request {
		$time    = $time ?? time();
		$request = new \WP_REST_Request();
		$request->set_body( $body );
		$request->set_header( 'X-AISA-Signature', 't=' . $time . ',v1=' . Push_Key::sign( $key, $body, $time ) );

		return $request;
	}

	/**
	 * The signature rules, on their own.
	 */
	public function test_verify_rules(): void {
		$now = 1790000000;
		$sig = 't=' . $now . ',v1=' . Push_Key::sign( self::KEY, 'body', $now );

		$this->assertSame( '', Push_Key::verify( self::KEY, 'body', $sig, $now ) );
		$this->assertSame( '', Push_Key::verify( self::KEY, 'body', $sig, $now + Push_Key::WINDOW ), 'edge of the window' );
		$this->assertSame( 'expired', Push_Key::verify( self::KEY, 'body', $sig, $now + Push_Key::WINDOW + 1 ) );
		$this->assertSame( 'expired', Push_Key::verify( self::KEY, 'body', $sig, $now - Push_Key::WINDOW - 1 ) );
		$this->assertSame( 'mismatch', Push_Key::verify( self::KEY, 'body!', $sig, $now ), 'a changed body' );
		$this->assertSame( 'mismatch', Push_Key::verify( self::KEY . 'x', 'body', $sig, $now ), 'another key' );
		$this->assertSame( 'malformed', Push_Key::verify( self::KEY, 'body', 'v1=abc', $now ) );
		$this->assertSame( 'malformed', Push_Key::verify( self::KEY, 'body', '', $now ) );
		$this->assertSame( 'not-set-up', Push_Key::verify( '', 'body', $sig, $now ) );
		$this->assertSame( 'not-set-up', Push_Key::verify( 'short', 'body', $sig, $now ) );
	}

	/**
	 * A generated key is long and URL-safe; each one is new; it is stored SEALED, never as itself (4.4.0).
	 */
	public function test_generate(): void {
		$a = Push_Key::generate();
		$b = Push_Key::generate();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $a );
		$this->assertNotSame( $a, $b );
		$stored = $this->options[ Push_Key::OPTION ];
		$this->assertTrue( \AJR\SEOAssistant\Core\Secret_Store::is_envelope( $stored ), 'stored as an envelope' );
		$this->assertStringNotContainsString( $b, serialize( $stored ), 'the key itself is nowhere in the row' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- what the row would hold.
		$this->assertSame( $b, Push_Key::get(), 'and it reads back' );
	}

	/**
	 * A push signed with a key that is stored sealed is accepted (the endpoint reads through Secret_Store).
	 */
	public function test_sealed_key_verifies_a_push(): void {
		unset( $this->options[ Push_Key::OPTION ] );
		$key = Push_Key::generate();

		$result = ( new Push_Endpoint( new Snapshot_Store() ) )->handle( $this->request( $this->body(), $key ) );
		$this->assertSame( 200, $result->get_status() );
	}

	/**
	 * A correctly signed push of this site's week is stored.
	 */
	public function test_good_push_is_stored(): void {
		$endpoint = new Push_Endpoint( new Snapshot_Store() );
		$request  = $this->request( $this->body() );

		$this->assertTrue( $endpoint->authorize( $request ) );
		$response = $endpoint->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'result' => 'stored', 'week' => '2026-09-21', 'period' => 'week', 'start' => '2026-09-21', 'pages' => 0 ], $response->get_data() );
		$this->assertArrayHasKey( '2026-09-21', $this->options[ Snapshot_Store::OPTION ] );
		$this->assertGreaterThan( 0, $this->options[ Snapshot_Store::LAST_PUSH ] );
	}

	/**
	 * Unsigned, tampered, stale and wrong-key pushes never reach the handler.
	 */
	public function test_bad_signatures_are_refused(): void {
		$endpoint = new Push_Endpoint( new Snapshot_Store() );

		$unsigned = new \WP_REST_Request();
		$unsigned->set_body( $this->body() );
		$cases = [
			'unsigned' => $unsigned,
			'wrong key' => $this->request( $this->body(), strrev( self::KEY ) ),
			'old'       => $this->request( $this->body(), self::KEY, time() - 3600 ),
		];
		$tampered = $this->request( $this->body() );
		$tampered->set_body( str_replace( '"count": 11', '"count": 99', $this->body() ) );
		$cases['tampered body'] = $tampered;

		foreach ( $cases as $label => $request ) {
			$result = $endpoint->authorize( $request );
			$this->assertInstanceOf( \WP_Error::class, $result, $label );
			$this->assertSame( 401, $result->get_error_data()['status'], $label );
		}
		$this->assertArrayNotHasKey( Snapshot_Store::OPTION, $this->options, 'nothing stored' );
	}

	/**
	 * Repeated key guesses from one address are turned away — but a GOOD signature never is, so junk
	 * from a shared address (a CDN edge) cannot block the real weekly push.
	 */
	public function test_failures_are_rate_limited(): void {
		$endpoint = new Push_Endpoint( new Snapshot_Store() );
		for ( $i = 0; $i < Push_Endpoint::MAX_FAILURES; $i++ ) {
			$endpoint->authorize( $this->request( $this->body(), strrev( self::KEY ) ) );
		}

		$result = $endpoint->authorize( $this->request( $this->body(), strrev( self::KEY ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'], 'the guesser waits out the window' );
		$this->assertTrue( $endpoint->authorize( $this->request( $this->body() ) ), 'a valid push still gets through' );
	}

	/**
	 * A request with no signature header is refused without writing anything.
	 */
	public function test_unsigned_requests_write_nothing(): void {
		$request = new \WP_REST_Request();
		$request->set_body( $this->body() );
		$result = ( new Push_Endpoint( new Snapshot_Store() ) )->authorize( $request );

		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertSame( [], $this->transients );
	}

	/**
	 * With no key set up the endpoint says so, and it is not counted as an attack.
	 */
	public function test_not_set_up(): void {
		unset( $this->options[ Push_Key::OPTION ] );
		$result = ( new Push_Endpoint( new Snapshot_Store() ) )->authorize( $this->request( $this->body() ) );

		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( [], $this->transients );
	}

	/**
	 * A report for another site is refused even when correctly signed; www and case do not matter.
	 */
	public function test_wrong_site_is_refused(): void {
		$endpoint = new Push_Endpoint( new Snapshot_Store() );

		$other  = str_replace( '"northfield.example"', '"southfield.example"', $this->body() );
		$result = $endpoint->handle( $this->request( $other ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aisa_report_wrong_site', $result->get_error_code() );

		$www = str_replace( '"northfield.example"', '"WWW.northfield.example"', $this->body() );
		$this->assertSame( 200, $endpoint->handle( $this->request( $www ) )->get_status() );
	}

	/**
	 * A malformed snapshot is refused with the reasons.
	 */
	public function test_invalid_snapshot(): void {
		$body   = str_replace( '"schema": 1', '"schema": 9', $this->body() );
		$result = ( new Push_Endpoint( new Snapshot_Store() ) )->handle( $this->request( $body ) );

		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'schema must be 1', $result->message );
	}

	/**
	 * An older version of a stored week answers 409 and changes nothing.
	 */
	public function test_older_retry_is_a_conflict(): void {
		$endpoint = new Push_Endpoint( new Snapshot_Store() );
		$endpoint->handle( $this->request( $this->body() ) );

		$older = str_replace( '2026-09-28T07:12:00+00:00', '2026-09-27T07:12:00+00:00', $this->body() );
		$this->assertSame( 409, $endpoint->handle( $this->request( $older ) )->get_status() );
	}
}
