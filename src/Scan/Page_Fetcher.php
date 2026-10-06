<?php
/**
 * Page_Fetcher — loads one of the site's own pages over loopback, as a logged-out visitor sees it.
 *
 * WHY. The scan judges what Google sees: the rendered page, theme and builder output included. A loopback
 * GET to the page's own permalink, with no cookies, is the cheapest faithful copy.
 *
 * SAFETY. Only this site's own host is ever requested (the address is built from get_permalink() and
 * checked against home_url() before sending), so there is nothing for a caller to point elsewhere. GET
 * only, a short timeout, a capped body, at most two redirects. On a local copy whose safety mu-plugin
 * sets WP_HTTP_BLOCK_EXTERNAL, WordPress still allows the site's own host, which is what makes the DDEV
 * loopback work; TLS verification follows WordPress's own loopback rule (`https_local_ssl_verify`).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Loopback fetch of a site page.
 */
class Page_Fetcher {

	/** Seconds before the fetch gives up and the scan falls back to post content. */
	public const TIMEOUT = 10;

	/** Largest body read, in bytes. */
	public const MAX_BYTES = 3145728;

	/**
	 * Fetch a page.
	 *
	 * @param string $url    The page's permalink.
	 * @param string $method 'GET' | 'HEAD'.
	 * @return array{ok:bool,status:int,html:string,error:string}
	 */
	public static function fetch( string $url, string $method = 'GET' ): array {
		if ( ! self::is_own( $url ) ) {
			return self::fail( 0, 'not this site' );
		}
		$args     = [
			'method'              => $method,
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0, // Never followed: a redirect is reported as one, and cannot lead off this site.
			'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'limit_response_size' => self::MAX_BYTES,
			'user-agent'          => 'AI SEO Assistant scan (' . home_url( '/' ) . ')',
			'headers'             => [ 'Accept' => 'text/html' ],
			'cookies'             => [],
		];
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return self::fail( 0, $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$type   = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( 'HEAD' === $method ) {
			return [
				'ok'     => $status >= 200 && $status < 400,
				'status' => $status,
				'html'   => '',
				'error'  => '',
			];
		}
		if ( 200 !== $status ) {
			/* translators: %d: HTTP status code. */
			return self::fail( $status, sprintf( __( 'the page answered HTTP %d', 'ai-seo-assistant' ), $status ) );
		}
		if ( '' !== $type && false === stripos( $type, 'html' ) ) {
			return self::fail( $status, __( 'the page did not answer with HTML', 'ai-seo-assistant' ) );
		}

		return [
			'ok'     => true,
			'status' => $status,
			'html'   => (string) wp_remote_retrieve_body( $response ),
			'error'  => '',
		];
	}

	/**
	 * Whether a URL is on this site's own host.
	 *
	 * @param string $url URL.
	 */
	public static function is_own( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return '' !== $host && $host === $home && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true );
	}

	/**
	 * A failed result.
	 *
	 * @param int    $status HTTP status.
	 * @param string $error  Why.
	 * @return array{ok:bool,status:int,html:string,error:string}
	 */
	protected static function fail( int $status, string $error ): array {
		return [
			'ok'     => false,
			'status' => $status,
			'html'   => '',
			'error'  => mb_substr( $error, 0, 160 ),
		];
	}
}
