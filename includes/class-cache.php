<?php
/**
 * Transient-based cache manager.
 *
 * All transient keys are prefixed with WPMAI_CACHE_PREFIX so they can be
 * flushed collectively without touching unrelated transients.
 */

namespace AJR\MarkdownForAI;

defined( 'ABSPATH' ) || exit;

class Cache {

	private const PREFIX = 'wpmai_';

	public function register(): void {
		// Flush index caches whenever any included post type changes.
		add_action( 'save_post', [ $this, 'on_save_post' ], 10, 2 );
		add_action( 'delete_post', [ $this, 'on_delete_post' ] );
		add_action( 'wp_update_term', [ $this, 'flush_index_caches' ] );
	}

	/**
	 * Returns a cached value or false on miss.
	 *
	 * @param string $key Cache key (without prefix).
	 * @return mixed|false
	 */
	public static function get( string $key ) {
		return get_transient( self::PREFIX . $key );
	}

	/**
	 * Stores a value in the cache.
	 *
	 * @param string $key     Cache key (without prefix).
	 * @param mixed  $value   Value to store.
	 * @param int    $ttl     TTL in seconds.
	 */
	public static function set( string $key, $value, int $ttl ): void {
		set_transient( self::PREFIX . $key, $value, $ttl );
	}

	/**
	 * Deletes a single cache entry.
	 *
	 * @param string $key Cache key (without prefix).
	 */
	public static function delete( string $key ): void {
		delete_transient( self::PREFIX . $key );
	}

	/**
	 * Returns the cache key for a single post's Markdown output.
	 */
	public static function post_key( int $post_id ): string {
		return 'post_' . $post_id;
	}

	/**
	 * Flushes all plugin transients from the options table.
	 *
	 * Called from the settings "Clear Cache" action and on deactivation.
	 */
	public static function flush_all(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%'
			)
		);
	}

	/**
	 * Flushes only the site-wide index caches (llms.txt and all llms-full pages).
	 */
	public static function flush_index_caches(): void {
		global $wpdb;

		self::delete( 'llms_index' );

		// Delete all paginated full-content pages (llms_full_p1, llms_full_p2, …).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::PREFIX . 'llms_full_p' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::PREFIX . 'llms_full_p' ) . '%'
			)
		);
	}

	public function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$allowed_types = Settings::get_option( 'post_types', [ 'post', 'page' ] );

		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		self::delete( self::post_key( $post_id ) );
		self::flush_index_caches();
	}

	public function on_delete_post( int $post_id ): void {
		self::delete( self::post_key( $post_id ) );
		self::flush_index_caches();
	}
}
