<?php
/**
 * Google Search Console admin page.
 */

namespace AJR\SEOAssistant\GSC;

use AJR\SEOAssistant\Adapters\SEO_Adapter_Resolver;
use AJR\SEOAssistant\Core\Secret_Store;

defined( 'ABSPATH' ) || exit;

class GSC_Page {

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
			\AJR\SEOAssistant\Report\Access::TOOLS_CAP,
			'ai-seo-assistant-gsc',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( \AJR\SEOAssistant\Report\Access::TOOLS_CAP ) ) {
			return;
		}

		if ( ! SEO_Adapter_Resolver::any_seo_plugin_active() ) {
			echo '<div class="notice notice-warning inline" style="margin:12px 0"><p><strong>No supported SEO plugin detected.</strong> Please activate The SEO Framework, Yoast SEO, or Rank Math to use AI SEO Assistant.</p></div>';
		}

		$this->render_notice();

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
					<?php
					$this->render_secret_row( GSC_Client::OPTION_CLIENT_ID, __( 'Google Client ID', 'ai-seo-assistant' ), $this->gsc_client->client_id_from_config(), 'text' );
					$this->render_secret_row( GSC_Client::OPTION_CLIENT_SECRET, __( 'Google Client Secret', 'ai-seo-assistant' ), $this->gsc_client->client_secret_from_config(), 'password' );
					?>

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

		// Both fields are write-only (never rendered back): blank keeps the saved value, "Clear" removes it.
		// Until 4.4.0 a blank Client ID field wiped the saved ID; it can no longer be blank-by-display.
		$failed = false;
		foreach ( [ GSC_Client::OPTION_CLIENT_ID, GSC_Client::OPTION_CLIENT_SECRET ] as $option ) {
			if ( ! empty( $_POST[ $option . '_clear' ] ) ) {
				Secret_Store::set( $option, '' );
				continue;
			}
			$value = isset( $_POST[ $option ] ) && is_string( $_POST[ $option ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $option ] ) ) ) : '';
			if ( '' !== $value && ! Secret_Store::set( $option, $value ) ) {
				$failed = true;
			}
		}

		if ( $failed ) {
			$this->set_notice( 'error', __( 'A value could not be encrypted on this server, so it was not saved. Define it in wp-config.php instead.', 'ai-seo-assistant' ) );
		} else {
			$this->set_notice( 'success', 'Google settings saved.' );
		}
		$this->redirect();
	}

	/**
	 * One write-only credential row: "Saved · ends …XXXX", a Replace field and a Clear box.
	 *
	 * The stored value never reaches the page, not even in value="". When wp-config.php defines it, the
	 * row says so and offers no field.
	 *
	 * @param string $option      Option (and field) name.
	 * @param string $label       Row label.
	 * @param bool   $from_config Whether a wp-config.php constant supplies the value.
	 * @param string $type        Input type for a new value (the ID is not secret enough to mask).
	 */
	protected function render_secret_row( $option, $label, $from_config, $type ) {
		$saved = Secret_Store::has( $option );
		$ends  = $saved ? Secret_Store::last4( $option ) : '';
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<?php if ( $from_config ) : ?>
					<p><strong><?php esc_html_e( 'Set in wp-config.php.', 'ai-seo-assistant' ); ?></strong></p>
				<?php else : ?>
					<?php if ( $saved ) : ?>
						<p>
							<strong>
								<?php
								echo esc_html(
									'' !== $ends
										/* translators: %s: last four characters of the saved value. */
										? sprintf( __( 'Saved · ends …%s', 'ai-seo-assistant' ), $ends )
										: __( 'Saved, but it can no longer be read: re-enter it.', 'ai-seo-assistant' )
								);
								?>
							</strong>
						</p>
					<?php endif; ?>
					<input
						type="<?php echo esc_attr( 'password' === $type ? 'password' : 'text' ); ?>"
						id="<?php echo esc_attr( $option ); ?>"
						name="<?php echo esc_attr( $option ); ?>"
						value=""
						class="large-text"
						autocomplete="<?php echo esc_attr( 'password' === $type ? 'new-password' : 'off' ); ?>"
						spellcheck="false"
						placeholder="<?php echo esc_attr( $saved ? __( 'Leave blank to keep the saved value', 'ai-seo-assistant' ) : __( 'From Google Cloud Console', 'ai-seo-assistant' ) ); ?>"
					>
					<?php if ( $saved ) : ?>
						<p>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option . '_clear' ); ?>" value="1">
								<?php esc_html_e( 'Clear the saved value', 'ai-seo-assistant' ); ?>
							</label>
						</p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Stored encrypted; never shown again after saving.', 'ai-seo-assistant' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
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
		if ( ! current_user_can( \AJR\SEOAssistant\Report\Access::TOOLS_CAP ) ) {
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
		if ( ! current_user_can( \AJR\SEOAssistant\Report\Access::TOOLS_CAP ) ) {
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
