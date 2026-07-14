<?php
/**
 * Redirects admin page.
 *
 * Provides the UI for managing redirects (add / edit / delete / enable) and,
 * when Search Console is connected, surfaces cached GSC page URLs that no longer
 * resolve as one-click redirect suggestions. All writes go through Redirect_Store,
 * which rebuilds the front-end lookup map.
 */

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Redirects\Redirect_Store;
use AJR\SEOAssistant\GSC\GSC_Client;

defined( 'ABSPATH' ) || exit;

class Redirects_Page {

	private const MENU_SLUG = 'ai-seo-assistant-redirects';
	private const MAX_SUGGESTIONS = 50;

	/**
	 * Redirect data store.
	 *
	 * @var Redirect_Store
	 */
	private Redirect_Store $store;

	/**
	 * Search Console client, used to build 404 suggestions.
	 *
	 * @var GSC_Client
	 */
	private GSC_Client $gsc_client;

	public function __construct( Redirect_Store $store, GSC_Client $gsc_client ) {
		$this->store      = $store;
		$this->gsc_client = $gsc_client;
	}

	/**
	 * Registers the menu entry and the form-processing endpoints.
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_post_ai_seo_assistant_save_redirect', [ $this, 'handle_save' ] );
		add_action( 'admin_post_ai_seo_assistant_delete_redirect', [ $this, 'handle_delete' ] );
		add_action( 'admin_post_ai_seo_assistant_toggle_redirect', [ $this, 'handle_toggle' ] );
	}

	public function add_page(): void {
		add_submenu_page(
			'ai-seo-assistant',
			__( 'Redirects', 'ai-seo-assistant' ),
			__( 'Redirects', 'ai-seo-assistant' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$editing = isset( $_GET['edit'] ) ? $this->store->get( absint( $_GET['edit'] ) ) : null;
		$prefill = isset( $_GET['new_source'] ) ? Redirect_Store::normalize_path( sanitize_text_field( wp_unslash( $_GET['new_source'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Redirects', 'ai-seo-assistant' ) . '</h1>';
		echo '<p class="description" style="max-width:720px">' . esc_html__( 'Send old or broken URLs to a new destination so visitors and search engines get a real page instead of a 404. Matching is on the exact path (a trailing slash is ignored).', 'ai-seo-assistant' ) . '</p>';

		$this->render_notice();
		$this->render_form( $editing, $prefill );
		$this->render_table();
		$this->render_suggestions();

		echo '</div>';
	}

	/**
	 * Shows a success/error notice based on the redirect-back query args.
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( $_GET['notice'] ) : '';
		$msg    = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $notice ) {
			return;
		}

		$messages = [
			'added'   => __( 'Redirect added.', 'ai-seo-assistant' ),
			'updated' => __( 'Redirect updated.', 'ai-seo-assistant' ),
			'deleted' => __( 'Redirect deleted.', 'ai-seo-assistant' ),
			'toggled' => __( 'Redirect updated.', 'ai-seo-assistant' ),
		];

		if ( 'error' === $notice ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( '' !== $msg ? $msg : __( 'Something went wrong.', 'ai-seo-assistant' ) )
			);
			return;
		}

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}
	}

	/**
	 * Renders the add/edit form.
	 *
	 * @param array|null $editing Row being edited, or null when adding.
	 * @param string     $prefill Source path to pre-fill when adding from a suggestion.
	 */
	private function render_form( ?array $editing, string $prefill ): void {
		$id     = $editing ? (int) $editing['id'] : 0;
		$source = $editing ? $editing['source_path'] : $prefill;
		$target = $editing ? $editing['target'] : '';
		$status = $editing ? (int) $editing['status_code'] : 301;
		$active = $editing ? (bool) $editing['is_enabled'] : true;

		echo '<h2 style="margin-top:1.5em">' . ( $editing ? esc_html__( 'Edit redirect', 'ai-seo-assistant' ) : esc_html__( 'Add a redirect', 'ai-seo-assistant' ) ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ai_seo_assistant_save_redirect">';
		echo '<input type="hidden" name="redirect_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'ai_seo_assistant_redirects', 'ai_seo_assistant_redirects_nonce' );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Source.
		echo '<tr><th scope="row"><label for="aisa-source">' . esc_html__( 'Source path', 'ai-seo-assistant' ) . '</label></th><td>';
		echo '<input name="source_path" id="aisa-source" type="text" class="regular-text" value="' . esc_attr( $source ) . '" placeholder="/old-page/" required>';
		echo '<p class="description">' . esc_html__( 'The path that should be redirected, relative to your site root. Example: /old-services/', 'ai-seo-assistant' ) . '</p>';
		echo '</td></tr>';

		// Target.
		echo '<tr><th scope="row"><label for="aisa-target">' . esc_html__( 'Destination', 'ai-seo-assistant' ) . '</label></th><td>';
		echo '<input name="target" id="aisa-target" type="text" class="regular-text" value="' . esc_attr( $target ) . '" placeholder="/new-page/ or https://example.com/">';
		echo '<p class="description">' . esc_html__( 'Where to send it. A relative path or a full URL. Leave blank when using status 410 (Gone).', 'ai-seo-assistant' ) . '</p>';
		echo '</td></tr>';

		// Status code.
		echo '<tr><th scope="row"><label for="aisa-status">' . esc_html__( 'Type', 'ai-seo-assistant' ) . '</label></th><td>';
		echo '<select name="status_code" id="aisa-status">';
		foreach ( $this->status_choices() as $code => $label ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $code,
				selected( $status, $code, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '</td></tr>';

		// Enabled.
		echo '<tr><th scope="row">' . esc_html__( 'Active', 'ai-seo-assistant' ) . '</th><td>';
		echo '<label><input type="checkbox" name="is_enabled" value="1"' . checked( $active, true, false ) . '> ' . esc_html__( 'Enable this redirect', 'ai-seo-assistant' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( $editing ? __( 'Save changes', 'ai-seo-assistant' ) : __( 'Add redirect', 'ai-seo-assistant' ) );

		if ( $editing ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '" class="button-link" style="margin-left:8px">' . esc_html__( 'Cancel', 'ai-seo-assistant' ) . '</a>';
		}

		echo '</form>';
	}

	/**
	 * Renders the table of existing redirects.
	 */
	private function render_table(): void {
		$rows = $this->store->all();

		echo '<h2 style="margin-top:2em">' . esc_html__( 'Your redirects', 'ai-seo-assistant' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p style="color:#666">' . esc_html__( 'No redirects yet. Add one above, or create one from a Search Console suggestion below.', 'ai-seo-assistant' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:960px"><thead><tr>';
		echo '<th>' . esc_html__( 'Source', 'ai-seo-assistant' ) . '</th>';
		echo '<th>' . esc_html__( 'Destination', 'ai-seo-assistant' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'ai-seo-assistant' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ai-seo-assistant' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'ai-seo-assistant' ) . '</th>';
		echo '</tr></thead><tbody>';

		$edit_base = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		foreach ( $rows as $row ) {
			$id       = (int) $row['id'];
			$enabled  = (bool) $row['is_enabled'];
			$is_gone  = 410 === (int) $row['status_code'];
			$edit_url = add_query_arg( 'edit', $id, $edit_base );

			echo '<tr>';
			echo '<td><code>' . esc_html( $row['source_path'] ) . '</code></td>';
			echo '<td>' . ( $is_gone ? '<em>' . esc_html__( '— (Gone)', 'ai-seo-assistant' ) . '</em>' : '<code>' . esc_html( $row['target'] ) . '</code>' ) . '</td>';
			echo '<td>' . esc_html( $this->status_short( (int) $row['status_code'] ) ) . '</td>';
			echo '<td>' . ( $enabled
				? '<span style="color:#1a7f37;font-weight:600">' . esc_html__( 'Active', 'ai-seo-assistant' ) . '</span>'
				: '<span style="color:#8a8a8a">' . esc_html__( 'Disabled', 'ai-seo-assistant' ) . '</span>' ) . '</td>';

			echo '<td style="white-space:nowrap">';
			echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Edit', 'ai-seo-assistant' ) . '</a> ';

			// Toggle (POST — changes state).
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			echo '<input type="hidden" name="action" value="ai_seo_assistant_toggle_redirect">';
			echo '<input type="hidden" name="redirect_id" value="' . esc_attr( (string) $id ) . '">';
			echo '<input type="hidden" name="enable" value="' . ( $enabled ? '0' : '1' ) . '">';
			wp_nonce_field( 'ai_seo_assistant_toggle_redirect_' . $id );
			echo '<button type="submit" class="button button-small">' . ( $enabled ? esc_html__( 'Disable', 'ai-seo-assistant' ) : esc_html__( 'Enable', 'ai-seo-assistant' ) ) . '</button>';
			echo '</form> ';

			// Delete (POST — destructive).
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Delete this redirect?', 'ai-seo-assistant' ) ) . '\')">';
			echo '<input type="hidden" name="action" value="ai_seo_assistant_delete_redirect">';
			echo '<input type="hidden" name="redirect_id" value="' . esc_attr( (string) $id ) . '">';
			wp_nonce_field( 'ai_seo_assistant_delete_redirect_' . $id );
			echo '<button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Delete', 'ai-seo-assistant' ) . '</button>';
			echo '</form>';

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders Search Console 404 suggestions, when available.
	 */
	private function render_suggestions(): void {
		echo '<h2 style="margin-top:2em">' . esc_html__( 'Suggestions from Search Console', 'ai-seo-assistant' ) . '</h2>';

		if ( ! $this->gsc_client->is_connected() ) {
			printf(
				'<p style="color:#666">%s <a href="%s">%s</a></p>',
				esc_html__( 'Connect Google Search Console to see URLs Google ranks that no longer resolve on your site.', 'ai-seo-assistant' ),
				esc_url( admin_url( 'admin.php?page=ai-seo-assistant-gsc' ) ),
				esc_html__( 'Open Search Console settings', 'ai-seo-assistant' )
			);
			return;
		}

		$suggestions = $this->build_gsc_suggestions();

		if ( empty( $suggestions ) ) {
			echo '<p style="color:#666">' . esc_html__( 'No likely 404s found in your cached Search Console data. Sync Search Console on its page to refresh this list.', 'ai-seo-assistant' ) . '</p>';
			return;
		}

		echo '<p class="description" style="max-width:720px">' . esc_html__( 'These URLs appear in your Search Console data but do not resolve to a post or page on this site — they may be 404ing. Some archive or term URLs can appear here even when valid, so review before creating a redirect.', 'ai-seo-assistant' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:960px"><thead><tr>';
		echo '<th>' . esc_html__( 'Search Console URL', 'ai-seo-assistant' ) . '</th>';
		echo '<th>' . esc_html__( 'Path', 'ai-seo-assistant' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'ai-seo-assistant' ) . '</th>';
		echo '</tr></thead><tbody>';

		$new_base = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		foreach ( $suggestions as $item ) {
			$create_url = add_query_arg( 'new_source', rawurlencode( $item['path'] ), $new_base ) . '#aisa-source';

			echo '<tr>';
			echo '<td><a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $item['url'] ) . '</a></td>';
			echo '<td><code>' . esc_html( $item['path'] ) . '</code></td>';
			echo '<td><a href="' . esc_url( $create_url ) . '" class="button button-small">' . esc_html__( 'Create redirect', 'ai-seo-assistant' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// -------------------------------------------------------------------------
	// Form processing
	// -------------------------------------------------------------------------

	public function handle_save(): void {
		$this->require_cap();
		check_admin_referer( 'ai_seo_assistant_redirects', 'ai_seo_assistant_redirects_nonce' );

		$id   = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
		$data = [
			'source_path' => isset( $_POST['source_path'] ) ? wp_unslash( $_POST['source_path'] ) : '',
			'target'      => isset( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : '',
			'status_code' => isset( $_POST['status_code'] ) ? (int) $_POST['status_code'] : 301,
			'is_enabled'  => ! empty( $_POST['is_enabled'] ),
		];

		$result = $id ? $this->store->update( $id, $data ) : $this->store->insert( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect_back( 'error', $result->get_error_message() );
		}

		$this->redirect_back( $id ? 'updated' : 'added' );
	}

	public function handle_delete(): void {
		$this->require_cap();

		$id = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
		check_admin_referer( 'ai_seo_assistant_delete_redirect_' . $id );

		if ( $id ) {
			$this->store->delete( $id );
		}

		$this->redirect_back( 'deleted' );
	}

	public function handle_toggle(): void {
		$this->require_cap();

		$id = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
		check_admin_referer( 'ai_seo_assistant_toggle_redirect_' . $id );

		if ( $id ) {
			$this->store->toggle( $id, ! empty( $_POST['enable'] ) );
		}

		$this->redirect_back( 'toggled' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds redirect suggestions from cached Search Console page data.
	 *
	 * Considers only this site's URLs, skips paths already redirected, and keeps
	 * those that do not resolve to a published post or page. Capped for display.
	 *
	 * @return array<int, array{path: string, url: string}>
	 */
	private function build_gsc_suggestions(): array {
		$data = $this->gsc_client->get_cached_data();

		if ( empty( $data['pages'] ) || ! is_array( $data['pages'] ) ) {
			return [];
		}

		$existing = [];
		foreach ( $this->store->all() as $row ) {
			$existing[ $row['source_path'] ] = true;
		}

		$home        = home_url();
		$seen        = [];
		$suggestions = [];

		foreach ( array_keys( $data['pages'] ) as $url ) {
			$url = (string) $url;

			// Only consider URLs on this site.
			if ( 0 !== strpos( $url, $home ) ) {
				continue;
			}

			$path = Redirect_Store::normalize_path( $url );

			if ( '' === $path || '/' === $path || isset( $seen[ $path ] ) || isset( $existing[ $path ] ) ) {
				continue;
			}

			// Skip URLs that resolve to a real post or page.
			if ( url_to_postid( $url ) > 0 ) {
				continue;
			}

			$seen[ $path ]  = true;
			$suggestions[]  = [ 'path' => $path, 'url' => $url ];

			if ( count( $suggestions ) >= self::MAX_SUGGESTIONS ) {
				break;
			}
		}

		usort( $suggestions, static fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );

		return $suggestions;
	}

	/**
	 * Full status-code labels for the form select.
	 *
	 * @return array<int, string>
	 */
	private function status_choices(): array {
		return [
			301 => __( '301 — Permanent', 'ai-seo-assistant' ),
			302 => __( '302 — Temporary', 'ai-seo-assistant' ),
			307 => __( '307 — Temporary (keep method)', 'ai-seo-assistant' ),
			410 => __( '410 — Gone (no destination)', 'ai-seo-assistant' ),
		];
	}

	/**
	 * Short status label for the table.
	 *
	 * @param int $code Status code.
	 * @return string
	 */
	private function status_short( int $code ): string {
		$labels = [
			301 => __( '301 Permanent', 'ai-seo-assistant' ),
			302 => __( '302 Temporary', 'ai-seo-assistant' ),
			307 => __( '307 Temporary', 'ai-seo-assistant' ),
			410 => __( '410 Gone', 'ai-seo-assistant' ),
		];

		return $labels[ $code ] ?? (string) $code;
	}

	/**
	 * Redirects back to the page with a notice.
	 *
	 * @param string $notice Notice key.
	 * @param string $msg    Optional error detail.
	 */
	private function redirect_back( string $notice, string $msg = '' ): void {
		$args = [
			'page'   => self::MENU_SLUG,
			'notice' => $notice,
		];

		if ( '' !== $msg ) {
			$args['msg'] = rawurlencode( $msg );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Blocks the request when the user lacks the required capability.
	 */
	private function require_cap(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ai-seo-assistant' ) );
		}
	}
}
