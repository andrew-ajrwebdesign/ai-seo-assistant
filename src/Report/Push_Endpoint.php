<?php
/**
 * Push_Endpoint — where retainer-scan delivers the weekly report.
 *
 *   POST /wp-json/ai-seo-assistant/v1/report
 *   Content-Type: application/json
 *   X-AISA-Signature: t=<unix>,v1=<hmac>        (see Push_Key)
 *
 * Order, and why:
 *   1. Size         on rest_pre_dispatch, before WordPress matches the route: it json_decode()s an
 *                   application/json body while validating parameters, BEFORE permission_callback, so a
 *                   size check there would come after the decode it is meant to prevent.
 *   2. Signature    checked in permission_callback, so an unsigned request never reaches the handler. A
 *                   good signature is never throttled (callers behind one proxy share an address).
 *   3. Rate limit   only a wrong or expired signature counts; an address over the limit gets 429 (its
 *                   signature is still hashed first: an HMAC is cheap, a lock-out of the real push is not).
 *   4. Validate     Snapshot::from_json(); anything malformed is refused whole.
 *   5. Right site   the snapshot names its site; one client's report can never be stored on another's.
 *   6. Store        Snapshot_Store::put() (a same-week correction replaces, an older retry is ignored).
 *
 * Works on any host: plain HTTPS to the WordPress REST API, no SSH or FTP. A host or firewall that blocks
 * outside POSTs is covered by the Import fallback on the report screen.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * REST route for report pushes.
 */
class Push_Endpoint {

	/** REST namespace. */
	public const REST_NAMESPACE = 'ai-seo-assistant/v1';

	/** Route. */
	public const ROUTE = '/report';

	/** Failed attempts allowed per address per window before 429. */
	public const MAX_FAILURES = 10;

	/** Failure-count window, in seconds. */
	public const FAILURE_WINDOW = 600;

	/**
	 * Storage.
	 *
	 * @var Snapshot_Store
	 */
	protected Snapshot_Store $store;

	/**
	 * Constructor — dependencies only, no hooks.
	 *
	 * @param Snapshot_Store $store Storage.
	 */
	public function __construct( Snapshot_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_filter( 'rest_pre_dispatch', [ $this, 'refuse_oversize' ], 10, 3 );
	}

	/**
	 * Refuse an oversized push before WordPress decodes its JSON (step 1 above). Other routes pass through.
	 *
	 * @param mixed            $result  Earlier filter's result (null to carry on).
	 * @param mixed            $server  REST server (unused).
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function refuse_oversize( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- the filter's signature.
		if ( null !== $result || ! $request instanceof \WP_REST_Request || '/' . self::REST_NAMESPACE . self::ROUTE !== untrailingslashit( (string) $request->get_route() ) ) {
			return $result;
		}

		return self::too_large( $request ) ?? $result;
	}

	/**
	 * A 413 error when the body (declared or actual) is over the v2 limit, else null.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	protected static function too_large( \WP_REST_Request $request ): ?\WP_Error {
		$declared = (int) $request->get_header( 'content_length' );
		if ( $declared > Snapshot::MAX_BYTES_V2 || strlen( (string) $request->get_body() ) > Snapshot::MAX_BYTES_V2 ) {
			return new \WP_Error( 'aisa_report_too_large', 'Body is larger than ' . Snapshot::MAX_BYTES_V2 . ' bytes.', [ 'status' => 413 ] );
		}

		return null;
	}

	/**
	 * Register the route.
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'authorize' ],
				'show_in_index'       => false,
			]
		);
	}

	/**
	 * Signature check (runs before handle()).
	 *
	 * A good signature is checked FIRST and is never throttled: behind a CDN or a shared proxy every
	 * caller can share one address, and a stranger's junk must not lock out the real weekly push. Only a
	 * wrong or expired signature (a key guess) counts toward the limit; a request with no usable
	 * signature at all is refused without a database write.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function authorize( \WP_REST_Request $request ) {
		// Size again (refuse_oversize() normally answered already): never hash a body that big.
		$too_large = self::too_large( $request );
		if ( null !== $too_large ) {
			return $too_large;
		}
		$problem = Push_Key::verify( Push_Key::get(), (string) $request->get_body(), (string) $request->get_header( Push_Key::HEADER ), time() );
		if ( '' === $problem ) {
			return true;
		}
		if ( 'not-set-up' === $problem ) {
			return new \WP_Error( 'aisa_report_not_set_up', 'Report push is not set up on this site.', [ 'status' => 503 ] );
		}

		$client = $this->client_key();
		if ( $this->failures( $client ) >= self::MAX_FAILURES ) {
			return new \WP_Error( 'aisa_report_limited', 'Too many failed attempts. Try again later.', [ 'status' => 429 ] );
		}
		if ( 'mismatch' === $problem || 'expired' === $problem ) {
			$this->fail( $client );
		}

		return new \WP_Error( 'aisa_report_unauthorized', 'Signature ' . $problem . '.', [ 'status' => 401 ] );
	}

	/**
	 * Validate and store the snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$errors   = [];
		$snapshot = Snapshot::from_json( (string) $request->get_body(), $errors );
		if ( null === $snapshot ) {
			return new \WP_Error( 'aisa_report_invalid', 'Snapshot refused: ' . implode( '; ', $errors ), [ 'status' => 422 ] );
		}

		$here = self::host( (string) home_url() );
		if ( self::host( $snapshot['site'] ) !== $here ) {
			return new \WP_Error( 'aisa_report_wrong_site', 'This report is for ' . $snapshot['site'] . ', not ' . $here . '.', [ 'status' => 422 ] );
		}

		$period = (string) ( $snapshot['period'] ?? 'week' );
		$start  = (string) $snapshot[ $period ]['start'];
		$pages  = count( (array) ( $snapshot['pages'] ?? [] ) );
		$result = $this->store->receive( $snapshot, time() );

		return new \WP_REST_Response(
			[
				'result' => $result,
				'week'   => $start, // Kept for v1 senders (retainer-scan reads this key).
				'period' => $period,
				'start'  => $start,
				'pages'  => $pages,
			],
			'stale' === $result ? 409 : 200
		);
	}

	/**
	 * A host name compared the way retainer-scan writes it: lower case, no "www.".
	 *
	 * @param string $url A URL or host.
	 */
	public static function host( string $url ): string {
		$host = (string) wp_parse_url( false !== strpos( $url, '//' ) ? $url : '//' . $url, PHP_URL_HOST );

		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * Failed attempts from an address in the current window.
	 *
	 * @param string $client Hashed address.
	 */
	protected function failures( string $client ): int {
		$state = get_transient( 'aisa_rp_fail_' . $client );

		return is_array( $state ) ? (int) ( $state['count'] ?? 0 ) : 0;
	}

	/**
	 * Count a failed attempt. The window is FIXED from the first failure (it does not restart on every
	 * hit), so a trickle of junk cannot hold an address at the limit forever.
	 *
	 * @param string $client Hashed address.
	 */
	protected function fail( string $client ): void {
		$state = get_transient( 'aisa_rp_fail_' . $client );
		$now   = time();
		$start = is_array( $state ) ? (int) ( $state['start'] ?? 0 ) : 0;
		$count = is_array( $state ) ? (int) ( $state['count'] ?? 0 ) : 0;
		if ( $start <= 0 || $now - $start >= self::FAILURE_WINDOW ) {
			$start = $now;
			$count = 0;
		}
		set_transient(
			'aisa_rp_fail_' . $client,
			[
				'count' => $count + 1,
				'start' => $start,
			],
			max( 1, self::FAILURE_WINDOW - ( $now - $start ) )
		);
	}

	/**
	 * A salted hash of the caller's address (the address itself is never stored). REMOTE_ADDR only:
	 * forwarded-for headers are written by the caller and would let a guesser pick its own bucket.
	 * IPv6 is bucketed by its /64, which one machine usually holds whole.
	 */
	protected function client_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			$ip     = false === $packed ? $ip : bin2hex( substr( $packed, 0, 8 ) ) . '/64';
		}

		return substr( wp_hash( $ip, 'nonce' ), 0, 20 );
	}
}
