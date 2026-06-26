<?php
/**
 * Per-IP rate limiting for the llms.txt and ?format=markdown endpoints.
 *
 * Uses WordPress transients for storage — compatible with Redis/Memcache
 * when object-cache.php is configured. Adds no new database tables.
 *
 * Default limit: 60 requests per 60 seconds per IP (configurable via filter).
 */

namespace AJR\MarkdownForAI;

defined( 'ABSPATH' ) || exit;

class Rate_Limiter {

	/**
	 * Returns the maximum number of requests allowed in the window.
	 *
	 * @return int
	 */
	private function limit(): int {
		return (int) apply_filters( 'wpmai_rate_limit_requests', 60 );
	}

	/**
	 * Returns the window duration in seconds.
	 *
	 * @return int
	 */
	private function window(): int {
		return (int) apply_filters( 'wpmai_rate_limit_window', 60 );
	}

	/**
	 * Checks whether the current request is within the rate limit.
	 *
	 * Sends 429 headers and exits if the limit is exceeded.
	 */
	public function check(): void {
		$ip  = $this->client_ip();
		$key = 'wpmai_rl_' . md5( $ip );

		$data = get_transient( $key );

		if ( false === $data ) {
			$data = [ 'count' => 0, 'reset' => time() + $this->window() ];
		}

		$data['count']++;

		set_transient( $key, $data, $this->window() );

		if ( $data['count'] > $this->limit() ) {
			$retry_after = max( 0, $data['reset'] - time() );
			header( 'HTTP/1.1 429 Too Many Requests' );
			header( 'Retry-After: ' . $retry_after );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-RateLimit-Limit: ' . $this->limit() );
			header( 'X-RateLimit-Remaining: 0' );
			header( 'X-RateLimit-Reset: ' . $data['reset'] );
			exit( 'Too many requests. Please try again in ' . $retry_after . ' seconds.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		header( 'X-RateLimit-Limit: ' . $this->limit() );
		header( 'X-RateLimit-Remaining: ' . max( 0, $this->limit() - $data['count'] ) );
		header( 'X-RateLimit-Reset: ' . $data['reset'] );
	}

	/**
	 * Returns the client IP address, respecting common reverse-proxy headers.
	 *
	 * Only trusts forwarded IPs when the connecting IP is a loopback or
	 * private-range address, to prevent header spoofing from public clients.
	 *
	 * @return string
	 */
	private function client_ip(): string {
		$remote_addr = $_SERVER['REMOTE_ADDR'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( $this->is_private_ip( $remote_addr ) ) {
			$forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $forwarded ) {
				$ips = array_map( 'trim', explode( ',', $forwarded ) );
				foreach ( $ips as $ip ) {
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						return $ip;
					}
				}
			}
		}

		return $remote_addr;
	}

	/**
	 * Checks if an IP address is in a private or loopback range.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private function is_private_ip( string $ip ): bool {
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}
