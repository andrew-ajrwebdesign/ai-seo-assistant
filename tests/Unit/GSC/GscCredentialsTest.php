<?php
/**
 * Tests for the Search Console credential rows and the revoke call (4.4.0, review round 2).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit\GSC;

use AJR\SEOAssistant\Core\Secret_Store;
use AJR\SEOAssistant\GSC\GSC_Client;
use AJR\SEOAssistant\GSC\GSC_Page;
use WP_Mock\Tools\TestCase;

/**
 * Which part of a saved credential the screen shows; how long a revoke may hold a request.
 */
class GscCredentialsTest extends TestCase {

	/**
	 * Sealed options in memory.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = [];

	/**
	 * Fake options, salts and translations.
	 */
	public function setUp(): void {
		parent::setUp();
		Secret_Store::reset();
		$this->options = [];
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $n, $d = false ) => $this->options[ $n ] ?? $d );
		\WP_Mock::userFunction( 'wp_salt' )->andReturnUsing( fn( $scheme ) => 'salt-' . $scheme );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
	}

	/**
	 * The client ID shows its start (every ID ends ".apps.googleusercontent.com", so "ends …com" said
	 * nothing); the client secret still shows only its last four.
	 */
	public function test_client_id_shows_its_start_secret_its_end(): void {
		$this->options[ GSC_Client::OPTION_CLIENT_ID ]     = Secret_Store::seal( '123456789012-abcdefghij.apps.googleusercontent.com' );
		$this->options[ GSC_Client::OPTION_CLIENT_SECRET ] = Secret_Store::seal( 'GOCSPX-secretvalue-WXYZ' );

		$this->assertSame( 'Saved · starts 123456789012…', GSC_Page::saved_hint( GSC_Client::OPTION_CLIENT_ID, 'text' ) );
		$this->assertStringNotContainsString( '.com', GSC_Page::saved_hint( GSC_Client::OPTION_CLIENT_ID, 'text' ) );
		$this->assertSame( 'Saved · ends …WXYZ', GSC_Page::saved_hint( GSC_Client::OPTION_CLIENT_SECRET, 'password' ) );
		$this->assertStringNotContainsString( 'GOCSPX', GSC_Page::saved_hint( GSC_Client::OPTION_CLIENT_SECRET, 'password' ), 'the secret never shows its start' );

		$this->options[ GSC_Client::OPTION_CLIENT_SECRET ] = [ 'v' => 1, 'n' => 'x', 'c' => 'y' ];
		$this->assertSame( 'Saved, but it can no longer be read: re-enter it.', GSC_Page::saved_hint( GSC_Client::OPTION_CLIENT_SECRET, 'password' ) );
	}

	/**
	 * Revoking at Google is best effort inside a click (Disconnect) or the uninstall request: 3 seconds at
	 * most, not 10.
	 */
	public function test_revoke_waits_three_seconds_at_most(): void {
		$seen = null;
		\WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing(
			function ( $url, $args ) use ( &$seen ) {
				$seen = [ $url, $args ];
				return new \WP_Error( 'http_request_failed', 'timed out' );
			}
		);
		\WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( fn( $x ) => $x instanceof \WP_Error );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 0 );

		$this->assertFalse( GSC_Client::revoke( [ 'refresh_token' => '1//r' ] ) );
		$this->assertSame( 'https://oauth2.googleapis.com/revoke', $seen[0] );
		$this->assertSame( 3, $seen[1]['timeout'] );
	}
}
