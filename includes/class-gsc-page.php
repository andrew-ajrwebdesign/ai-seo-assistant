<?php
/**
 * Google Search Console admin page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_GSC_Page {

	private $gsc_client;

	public function __construct( $gsc_client ) {
		$this->gsc_client = $gsc_client;
	}

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_gsc_page' ] );

		add_action( 'admin_post_ai_seo_assistant_gsc_save_settings', [ $this, 'save_settings' ] );
		add_action( 'admin_post_ai_seo_assistant_gsc_connect', [ $this, 'connect' ] );
		add_action( 'admin_post_ai_seo_assistant_gsc_callback', [ $this, 'callback' ] );
		add_action( 'admin_post_ai_seo_assistant_gsc_disconnect', [ $this, 'disconnect' ] );
		add_action( 'admin_post_ai_seo_assistant_gsc_save_property', [ $this, 'save_property' ] );
		add_action( 'admin_post_ai_seo_assistant_gsc_sync', [ $this, 'sync' ] );
	}

	public function add_gsc_page() {
		add_submenu_page(
			'ai-seo-assistant',
			'Search Console',
			'Search Console',
			'manage_options',
			'ai-seo-assistant-gsc',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_notice();

		$client_id     = $this->gsc_client->get_client_id();
		$client_secret = $this->gsc_client->get_client_secret();
		$redirect_uri  = $this->gsc_client->get_redirect_uri();
		$is_connected  = $this->gsc_client->is_connected();
		$selected_site = $this->gsc_client->get_selected_site();
		$cached_data   = $this->gsc_client->get_cached_data();

		$sites = [];

		if ( $is_connected ) {
			$sites_result = $this->gsc_client->list_sites();

			if ( is_wp_error( $sites_result ) ) {
				$this->show_inline_error( $sites_result->get_error_message() );
			} else {
				$sites = $sites_result;
			}
		}
		?>
		<div class="wrap">
			<h1>Google Search Console</h1>

			<p>
				Connect this WordPress install to Google Search Console, select a property, and manually sync recent search performance data.
			</p>

			<h2>Google OAuth Settings</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ai_seo_assistant_gsc_save_settings">
				<?php wp_nonce_field( 'ai_seo_assistant_gsc_save_settings', 'ai_seo_assistant_gsc_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_gsc_client_id">Google Client ID</label>
						</th>
						<td>
							<input
								type="text"
								id="ai_seo_assistant_gsc_client_id"
								name="ai_seo_assistant_gsc_client_id"
								value="<?php echo esc_attr( $client_id ); ?>"
								class="large-text"
								autocomplete="off"
							>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_gsc_client_secret">Google Client Secret</label>
						</th>
						<td>
							<input
								type="password"
								id="ai_seo_assistant_gsc_client_secret"
								name="ai_seo_assistant_gsc_client_secret"
								value="<?php echo esc_attr( $client_secret ); ?>"
								class="regular-text"
								autocomplete="off"
							>
						</td>
					</tr>

					<tr>
						<th scope="row">Redirect URI</th>
						<td>
							<code><?php echo esc_html( $redirect_uri ); ?></code>
							<p class="description">
								Add this exact URI to your Google OAuth Client under Authorized redirect URIs.
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Google Settings' ); ?>
			</form>

			<hr>

			<h2>Connection</h2>

			<?php if ( ! $this->gsc_client->has_credentials() ) : ?>
				<p>
					Add your Google Client ID and Client Secret first.
				</p>
			<?php elseif ( ! $is_connected ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ai_seo_assistant_gsc_connect">
					<?php wp_nonce_field( 'ai_seo_assistant_gsc_connect', 'ai_seo_assistant_gsc_nonce' ); ?>

					<?php submit_button( 'Connect Google Search Console', 'primary' ); ?>
				</form>
			<?php else : ?>
				<p>
					<strong>Status:</strong> Connected
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 20px;">
					<input type="hidden" name="action" value="ai_seo_assistant_gsc_disconnect">
					<?php wp_nonce_field( 'ai_seo_assistant_gsc_disconnect', 'ai_seo_assistant_gsc_nonce' ); ?>

					<?php submit_button( 'Disconnect Google Search Console', 'secondary' ); ?>
				</form>
			<?php endif; ?>

			<?php if ( $is_connected ) : ?>
				<hr>

				<h2>Search Console Property</h2>

				<?php if ( empty( $sites ) ) : ?>
					<p>No Search Console properties found for the connected Google account.</p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ai_seo_assistant_gsc_save_property">
						<?php wp_nonce_field( 'ai_seo_assistant_gsc_save_property', 'ai_seo_assistant_gsc_nonce' ); ?>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="ai_seo_assistant_gsc_selected_site">Selected Property</label>
								</th>
								<td>
									<select
										id="ai_seo_assistant_gsc_selected_site"
										name="ai_seo_assistant_gsc_selected_site"
									>
										<option value="">Select a property</option>
										<?php foreach ( $sites as $site ) : ?>
											<?php
											$site_url = isset( $site['siteUrl'] ) ? $site['siteUrl'] : '';

											if ( empty( $site_url ) ) {
												continue;
											}
											?>
											<option value="<?php echo esc_attr( $site_url ); ?>" <?php selected( $selected_site, $site_url ); ?>>
												<?php echo esc_html( $site_url ); ?>
												<?php if ( ! empty( $site['permissionLevel'] ) ) : ?>
													(<?php echo esc_html( $site['permissionLevel'] ); ?>)
												<?php endif; ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>

						<?php submit_button( 'Save Selected Property' ); ?>
					</form>
				<?php endif; ?>

				<?php if ( ! empty( $selected_site ) ) : ?>
					<hr>

					<h2>Manual Sync</h2>

					<p>
						Selected property:
						<code><?php echo esc_html( $selected_site ); ?></code>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ai_seo_assistant_gsc_sync">
						<?php wp_nonce_field( 'ai_seo_assistant_gsc_sync', 'ai_seo_assistant_gsc_nonce' ); ?>

						<label for="ai_seo_assistant_gsc_days">
							Date range
						</label>

						<select id="ai_seo_assistant_gsc_days" name="ai_seo_assistant_gsc_days">
							<option value="28">Last 28 days</option>
							<option value="90" selected>Last 90 days</option>
						</select>

						<?php submit_button( 'Refresh Search Console Data', 'primary', 'submit', false ); ?>
					</form>
				<?php endif; ?>

				<?php $this->render_cache_summary( $cached_data ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function save_settings() {
		$this->verify_request( 'ai_seo_assistant_gsc_save_settings' );

		$client_id     = isset( $_POST['ai_seo_assistant_gsc_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_seo_assistant_gsc_client_id'] ) ) : '';
		$client_secret = isset( $_POST['ai_seo_assistant_gsc_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_seo_assistant_gsc_client_secret'] ) ) : '';

		update_option( AI_SEO_Assistant_GSC_Client::OPTION_CLIENT_ID, $client_id, false );
		update_option( AI_SEO_Assistant_GSC_Client::OPTION_CLIENT_SECRET, $client_secret, false );

		$this->set_notice( 'success', 'Google settings saved.' );
		$this->redirect();
	}

	public function connect() {
		$this->verify_request( 'ai_seo_assistant_gsc_connect' );

		if ( ! $this->gsc_client->has_credentials() ) {
			$this->set_notice( 'error', 'Add your Google Client ID and Client Secret first.' );
			$this->redirect();
		}

		$state = wp_generate_password( 32, false, false );

		set_transient(
			'ai_seo_assistant_gsc_state_' . get_current_user_id(),
			$state,
			10 * MINUTE_IN_SECONDS
		);

        wp_redirect( esc_url_raw( $this->gsc_client->get_auth_url( $state ) ) );
        exit;
	}

	public function callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect Google Search Console.', 'ai-seo-assistant' ) );
		}

		$expected_state = get_transient( 'ai_seo_assistant_gsc_state_' . get_current_user_id() );
		$state          = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		delete_transient( 'ai_seo_assistant_gsc_state_' . get_current_user_id() );

		if ( empty( $expected_state ) || empty( $state ) || ! hash_equals( $expected_state, $state ) ) {
			$this->set_notice( 'error', 'Invalid Google connection state. Please try connecting again.' );
			$this->redirect();
		}

		if ( ! empty( $_GET['error'] ) ) {
			$this->set_notice(
				'error',
				'Google connection error: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) )
			);
			$this->redirect();
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		if ( empty( $code ) ) {
			$this->set_notice( 'error', 'Google did not return an authorization code.' );
			$this->redirect();
		}

		$result = $this->gsc_client->exchange_code_for_token( $code );

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
			$this->redirect();
		}

		$this->set_notice( 'success', 'Google Search Console connected.' );
		$this->redirect();
	}

	public function disconnect() {
		$this->verify_request( 'ai_seo_assistant_gsc_disconnect' );

		$this->gsc_client->delete_connection();

		$this->set_notice( 'success', 'Google Search Console disconnected and cached GSC data removed.' );
		$this->redirect();
	}

	public function save_property() {
		$this->verify_request( 'ai_seo_assistant_gsc_save_property' );

        $selected_site = isset( $_POST['ai_seo_assistant_gsc_selected_site'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_seo_assistant_gsc_selected_site'] ) ) : '';
        
		if ( empty( $selected_site ) ) {
			$this->set_notice( 'error', 'Please select a Search Console property.' );
			$this->redirect();
		}

		$this->gsc_client->save_selected_site( $selected_site );

		$this->set_notice( 'success', 'Search Console property saved.' );
		$this->redirect();
	}

	public function sync() {
		$this->verify_request( 'ai_seo_assistant_gsc_sync' );

		$days = isset( $_POST['ai_seo_assistant_gsc_days'] ) ? absint( $_POST['ai_seo_assistant_gsc_days'] ) : 90;

		if ( ! in_array( $days, [ 28, 90 ], true ) ) {
			$days = 90;
		}

		$result = $this->gsc_client->sync_search_analytics( $days );

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
			$this->redirect();
		}

		$page_count = ! empty( $result['pages'] ) && is_array( $result['pages'] ) ? count( $result['pages'] ) : 0;

		$this->set_notice(
			'success',
			sprintf(
				'Search Console data synced. Cached %d page(s) from %s to %s.',
				$page_count,
				$result['start_date'],
				$result['end_date']
			)
		);

		$this->redirect();
	}

	private function render_cache_summary( $cached_data ) {
		if ( empty( $cached_data ) ) {
			?>
			<hr>
			<h2>Cached Data</h2>
			<p>No Search Console data has been synced yet.</p>
			<?php
			return;
		}

		$page_count = ! empty( $cached_data['pages'] ) && is_array( $cached_data['pages'] ) ? count( $cached_data['pages'] ) : 0;
		$synced_at  = ! empty( $cached_data['synced_at'] ) ? wp_date( 'd/m/y H:i', absint( $cached_data['synced_at'] ) ) : 'Unknown';
		?>
		<hr>
		<h2>Cached Data</h2>

		<table class="widefat striped" style="max-width: 720px;">
			<tbody>
				<tr>
					<th scope="row">Property</th>
					<td><?php echo esc_html( isset( $cached_data['site_url'] ) ? $cached_data['site_url'] : '' ); ?></td>
				</tr>
				<tr>
					<th scope="row">Date range</th>
					<td>
						<?php echo esc_html( isset( $cached_data['start_date'] ) ? $cached_data['start_date'] : '' ); ?>
						–
						<?php echo esc_html( isset( $cached_data['end_date'] ) ? $cached_data['end_date'] : '' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Pages cached</th>
					<td><?php echo esc_html( number_format_i18n( $page_count ) ); ?></td>
				</tr>
				<tr>
					<th scope="row">Raw rows imported</th>
					<td><?php echo esc_html( isset( $cached_data['raw_count'] ) ? number_format_i18n( absint( $cached_data['raw_count'] ) ) : '0' ); ?></td>
				</tr>
				<tr>
					<th scope="row">Last synced</th>
					<td><?php echo esc_html( $synced_at ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private function verify_request( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Google Search Console settings.', 'ai-seo-assistant' ) );
		}

		if (
			empty( $_POST['ai_seo_assistant_gsc_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['ai_seo_assistant_gsc_nonce'] ) ),
				$action
			)
		) {
			wp_die( esc_html__( 'Invalid request.', 'ai-seo-assistant' ) );
		}
	}

	private function set_notice( $type, $message ) {
		set_transient(
			'ai_seo_assistant_gsc_notice_' . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			60
		);
	}

	private function render_notice() {
		$notice = get_transient( 'ai_seo_assistant_gsc_notice_' . get_current_user_id() );

		if ( empty( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( 'ai_seo_assistant_gsc_notice_' . get_current_user_id() );

		$type = ! empty( $notice['type'] ) && 'error' === $notice['type'] ? 'error' : 'success';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
		<?php
	}

	private function show_inline_error( $message ) {
		?>
		<div class="notice notice-error">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	private function redirect() {
		wp_safe_redirect(
			admin_url( 'admin.php?page=ai-seo-assistant-gsc' )
		);
		exit;
	}
}