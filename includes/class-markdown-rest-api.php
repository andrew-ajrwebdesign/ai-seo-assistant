<?php
/**
 * REST API endpoints for machine-readable Markdown access.
 *
 * GET /wp-json/wpmai/v1/posts            — paginated list of indexable posts
 * GET /wp-json/wpmai/v1/posts/{id}/markdown — full Markdown for one post
 */

namespace AJR\MarkdownForAI;

defined( 'ABSPATH' ) || exit;

class Rest_Api {

	const NAMESPACE = 'wpmai/v1';
	const PER_PAGE  = 20;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_posts' ],
				'permission_callback' => '__return_true',
				'args'                => $this->posts_args(),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>[\d]+)/markdown',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_markdown' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Returns a paginated list of indexable posts with their Markdown URLs.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_posts( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$settings   = get_option( 'wpmai_settings', [] );
		$post_types = Settings::allowed_post_types();
		$page       = max( 1, (int) $request->get_param( 'page' ) );
		$per_page   = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?? self::PER_PAGE ) ) );
		$type_param = $request->get_param( 'type' );

		if ( $type_param && in_array( $type_param, $post_types, true ) ) {
			$post_types = [ $type_param ];
		}

		$excluded_ids = array_map( 'absint', (array) ( $settings['excluded_ids'] ?? [] ) );

		$query = new \WP_Query(
			[
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'post__not_in'           => $excluded_ids,  // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'no_found_rows'          => false,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			]
		);

		$items = [];
		foreach ( $query->posts as $post ) {
			if ( ! Indexability::is_indexable( $post ) ) {
				continue;
			}
			$items[] = [
				'id'           => $post->ID,
				'type'         => $post->post_type,
				'title'        => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'          => get_permalink( $post ),
				'markdown_url' => add_query_arg( 'format', 'markdown', get_permalink( $post ) ),
				'modified'     => get_the_modified_date( 'c', $post ),
			];
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	/**
	 * Returns the full Markdown body for a single post.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_markdown( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'rest_post_not_found', __( 'Post not found.', 'wp-markdown-for-ai' ), [ 'status' => 404 ] );
		}

		$settings     = get_option( 'wpmai_settings', [] );
		$excluded_ids = array_map( 'absint', (array) ( $settings['excluded_ids'] ?? [] ) );

		if ( in_array( $id, $excluded_ids, true ) ) {
			return new \WP_Error( 'rest_post_excluded', __( 'Post is excluded from Markdown output.', 'wp-markdown-for-ai' ), [ 'status' => 403 ] );
		}

		if ( ! Indexability::is_indexable( $post ) ) {
			return new \WP_Error( 'rest_post_noindex', __( 'Post is marked noindex.', 'wp-markdown-for-ai' ), [ 'status' => 403 ] );
		}

		$cache     = new Cache();
		$cache_key = $cache->post_key( $id );
		$markdown  = $cache->get( $cache_key );

		if ( false === $markdown ) {
			$converter = new Markdown_Converter();
			$markdown  = $converter->convert( $post );
			$ttl       = absint( $settings['cache_ttl'] ?? 3600 );
			$cache->set( $cache_key, $markdown, $ttl );
		}

		$response = new \WP_REST_Response(
			[
				'id'       => $post->ID,
				'markdown' => $markdown,
			]
		);

		$response->header( 'Last-Modified', gmdate( 'D, d M Y H:i:s', strtotime( $post->post_modified_gmt ) ) . ' GMT' );

		return $response;
	}

	/**
	 * Returns argument schema for the /posts endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function posts_args(): array {
		return [
			'page'     => [
				'default'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'default'           => self::PER_PAGE,
				'sanitize_callback' => 'absint',
			],
			'type'     => [
				'sanitize_callback' => 'sanitize_key',
			],
		];
	}
}
