<?php
/**
 * Google Search Console API client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_GSC_Client {

	const OPTION_CLIENT_ID       = 'ai_seo_assistant_gsc_client_id';
	const OPTION_CLIENT_SECRET   = 'ai_seo_assistant_gsc_client_secret';
	const OPTION_TOKEN_DATA      = 'ai_seo_assistant_gsc_token_data';
	const OPTION_SELECTED_SITE   = 'ai_seo_assistant_gsc_selected_site';
	const OPTION_CACHE           = 'ai_seo_assistant_gsc_cache';
	const OPTION_LAST_SYNC       = 'ai_seo_assistant_gsc_last_sync';
	const OPTION_LAST_SYNC_RANGE = 'ai_seo_assistant_gsc_last_sync_range';

	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	public function get_redirect_uri() {
		return admin_url( 'admin-post.php?action=ai_seo_assistant_gsc_callback' );
	}

	public function get_client_id() {
		if ( defined( 'AI_SEO_ASSISTANT_GOOGLE_CLIENT_ID' ) && AI_SEO_ASSISTANT_GOOGLE_CLIENT_ID ) {
			return trim( (string) AI_SEO_ASSISTANT_GOOGLE_CLIENT_ID );
		}

		return trim( (string) get_option( self::OPTION_CLIENT_ID, '' ) );
	}

	public function get_client_secret() {
		if ( defined( 'AI_SEO_ASSISTANT_GOOGLE_CLIENT_SECRET' ) && AI_SEO_ASSISTANT_GOOGLE_CLIENT_SECRET ) {
			return trim( (string) AI_SEO_ASSISTANT_GOOGLE_CLIENT_SECRET );
		}

		return trim( (string) get_option( self::OPTION_CLIENT_SECRET, '' ) );
	}

	public function has_credentials() {
		return ! empty( $this->get_client_id() ) && ! empty( $this->get_client_secret() );
	}

	public function is_connected() {
		$token_data = $this->get_token_data();

		return ! empty( $token_data['access_token'] ) || ! empty( $token_data['refresh_token'] );
	}

	public function get_token_data() {
		$token_data = get_option( self::OPTION_TOKEN_DATA, [] );

		return is_array( $token_data ) ? $token_data : [];
	}

	public function save_token_data( $token_data ) {
		$existing = $this->get_token_data();

		if ( empty( $token_data['refresh_token'] ) && ! empty( $existing['refresh_token'] ) ) {
			$token_data['refresh_token'] = $existing['refresh_token'];
		}

		if ( ! empty( $token_data['expires_in'] ) ) {
			$token_data['expires_at'] = time() + absint( $token_data['expires_in'] ) - 60;
		}

		update_option( self::OPTION_TOKEN_DATA, $token_data, false );
	}

	public function delete_connection() {
		delete_option( self::OPTION_TOKEN_DATA );
		delete_option( self::OPTION_SELECTED_SITE );
		delete_option( self::OPTION_CACHE );
		delete_option( self::OPTION_LAST_SYNC );
		delete_option( self::OPTION_LAST_SYNC_RANGE );
	}

	public function get_auth_url( $state ) {
		return add_query_arg(
			[
				'client_id'     => $this->get_client_id(),
				'redirect_uri'  => $this->get_redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			],
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
	}

	public function exchange_code_for_token( $code ) {
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 30,
				'body'    => [
					'code'          => $code,
					'client_id'     => $this->get_client_id(),
					'client_secret' => $this->get_client_secret(),
					'redirect_uri'  => $this->get_redirect_uri(),
					'grant_type'    => 'authorization_code',
				],
			]
		);

		return $this->handle_token_response( $response );
	}

	public function refresh_access_token() {
		$token_data = $this->get_token_data();

		if ( empty( $token_data['refresh_token'] ) ) {
			return new WP_Error(
				'ai_seo_gsc_missing_refresh_token',
				'Missing Google refresh token. Please disconnect and reconnect Google Search Console.'
			);
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 30,
				'body'    => [
					'client_id'     => $this->get_client_id(),
					'client_secret' => $this->get_client_secret(),
					'refresh_token' => $token_data['refresh_token'],
					'grant_type'    => 'refresh_token',
				],
			]
		);

		return $this->handle_token_response( $response );
	}

	private function handle_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'ai_seo_gsc_token_error',
				'Google token error: ' . AI_SEO_Assistant_Utils::mask_sensitive_text( $body )
			);
		}

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error(
				'ai_seo_gsc_missing_access_token',
				'Google did not return an access token.'
			);
		}

		$this->save_token_data( $data );

		return $data;
	}

	public function get_access_token() {
		$token_data = $this->get_token_data();

		if ( empty( $token_data['access_token'] ) ) {
			return new WP_Error(
				'ai_seo_gsc_not_connected',
				'Google Search Console is not connected.'
			);
		}

		if ( ! empty( $token_data['expires_at'] ) && time() >= absint( $token_data['expires_at'] ) ) {
			$refreshed = $this->refresh_access_token();

			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}

			$token_data = $this->get_token_data();
		}

		return $token_data['access_token'];
	}

	public function list_sites() {
		$response = $this->request(
			'GET',
			'https://www.googleapis.com/webmasters/v3/sites'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['siteEntry'] ) || ! is_array( $response['siteEntry'] ) ) {
			return [];
		}

		return $response['siteEntry'];
	}

	public function get_selected_site() {
		return get_option( self::OPTION_SELECTED_SITE, '' );
	}

    public function save_selected_site( $site_url ) {
        $site_url = sanitize_text_field( $site_url );

        update_option( self::OPTION_SELECTED_SITE, $site_url, false );
    }

	public function sync_search_analytics( $days = 90 ) {
		$site_url = $this->get_selected_site();

		if ( empty( $site_url ) ) {
			return new WP_Error(
				'ai_seo_gsc_missing_site',
				'Please select a Search Console property first.'
			);
		}

		$days = absint( $days );

		if ( $days <= 0 ) {
			$days = 90;
		}

		$end_date   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days', strtotime( $end_date ) ) );

		$endpoint = sprintf(
			'https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query',
			rawurlencode( $site_url )
		);

		$response = $this->request(
			'POST',
			$endpoint,
			[
				'startDate'  => $start_date,
				'endDate'    => $end_date,
				'dimensions' => [ 'page', 'query' ],
				'rowLimit'   => 25000,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rows = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : [];

		$cache = $this->aggregate_rows_by_page( $rows );

		$data = [
			'site_url'   => $site_url,
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'days'       => $days,
			'pages'      => $cache,
			'raw_count'  => count( $rows ),
			'synced_at'  => time(),
		];

		update_option( self::OPTION_CACHE, $data, false );
		update_option( self::OPTION_LAST_SYNC, time(), false );
		update_option(
			self::OPTION_LAST_SYNC_RANGE,
			[
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'days'       => $days,
			],
			false
		);

		return $data;
	}

	private function aggregate_rows_by_page( $rows ) {
		$pages = [];

		foreach ( $rows as $row ) {
			if ( empty( $row['keys'][0] ) ) {
				continue;
			}

			$page_url = esc_url_raw( $row['keys'][0] );
			$query    = isset( $row['keys'][1] ) ? sanitize_text_field( $row['keys'][1] ) : '';

			if ( empty( $page_url ) ) {
				continue;
			}

			if ( empty( $pages[ $page_url ] ) ) {
				$pages[ $page_url ] = [
					'url'                 => $page_url,
					'clicks'              => 0,
					'impressions'         => 0,
					'ctr'                 => 0,
					'position'            => 0,
					'position_weight_sum' => 0,
					'queries'             => [],
				];
			}

			$clicks      = isset( $row['clicks'] ) ? (float) $row['clicks'] : 0;
			$impressions = isset( $row['impressions'] ) ? (float) $row['impressions'] : 0;
			$position    = isset( $row['position'] ) ? (float) $row['position'] : 0;

			$pages[ $page_url ]['clicks']      += $clicks;
			$pages[ $page_url ]['impressions'] += $impressions;

			if ( $impressions > 0 ) {
				$pages[ $page_url ]['position_weight_sum'] += $position * $impressions;
			}

			if ( ! empty( $query ) ) {
				if ( empty( $pages[ $page_url ]['queries'][ $query ] ) ) {
					$pages[ $page_url ]['queries'][ $query ] = [
						'query'       => $query,
						'clicks'      => 0,
						'impressions' => 0,
						'ctr'         => 0,
						'position'    => 0,
					];
				}

				$pages[ $page_url ]['queries'][ $query ]['clicks']      += $clicks;
				$pages[ $page_url ]['queries'][ $query ]['impressions'] += $impressions;

				if ( $impressions > 0 ) {
					$pages[ $page_url ]['queries'][ $query ]['position'] = $position;
				}
			}
		}

		foreach ( $pages as $page_url => $page ) {
			if ( $page['impressions'] > 0 ) {
				$pages[ $page_url ]['ctr']      = $page['clicks'] / $page['impressions'];
				$pages[ $page_url ]['position'] = $page['position_weight_sum'] / $page['impressions'];
			}

			unset( $pages[ $page_url ]['position_weight_sum'] );

			$queries = array_values( $pages[ $page_url ]['queries'] );

			usort(
				$queries,
				function ( $a, $b ) {
					if ( $a['clicks'] === $b['clicks'] ) {
						return $b['impressions'] <=> $a['impressions'];
					}

					return $b['clicks'] <=> $a['clicks'];
				}
			);

			$queries = array_slice( $queries, 0, 10 );

			foreach ( $queries as $index => $query_data ) {
				if ( ! empty( $query_data['impressions'] ) ) {
					$queries[ $index ]['ctr'] = $query_data['clicks'] / $query_data['impressions'];
				}
			}

			$pages[ $page_url ]['queries'] = $queries;
		}

		return $pages;
	}

	public function get_cached_data() {
		$data = get_option( self::OPTION_CACHE, [] );

		return is_array( $data ) ? $data : [];
	}

	public function get_page_data( $url ) {
		$data = $this->get_cached_data();

		if ( empty( $data['pages'] ) || ! is_array( $data['pages'] ) ) {
			return [];
		}

		$url = esc_url_raw( $url );

		return isset( $data['pages'][ $url ] ) ? $data['pages'][ $url ] : [];
	}

	private function request( $method, $url, $body = null ) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$args = [
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			],
			'method'  => $method,
		];

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 401 === $status_code ) {
			$refreshed = $this->refresh_access_token();

			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}

			$access_token = $this->get_access_token();

			if ( is_wp_error( $access_token ) ) {
				return $access_token;
			}

			$args['headers']['Authorization'] = 'Bearer ' . $access_token;
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code   = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'ai_seo_gsc_api_error',
				'Google Search Console API error: ' . AI_SEO_Assistant_Utils::mask_sensitive_text( $response_body )
			);
		}

		$data = json_decode( $response_body, true );

		if ( null === $data ) {
			return [];
		}

		return $data;
	}
}