<?php
/**
 * Report_Page — AI SEO Assistant → Weekly report in wp-admin.
 *
 * The first item under the plugin's menu, visible to Administrators (the client owner). Everyone with
 * the report sees the same report; agency users (Access::TOOLS_CAP) also see a "Report delivery" panel
 * below it: whether a push key is set, when the last update arrived, a button to make a new key (shown
 * once), and the Import fallback for hosts that block the push.
 *
 * Writes happen only through admin-post.php with a nonce and a capability check, never on render —
 * except that a freshly generated key is shown once and then forgotten (a transient read-and-delete on
 * this admin screen, never on the front end).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

use AJR\SEOAssistant\Core\Secret_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The Weekly report screen.
 */
class Report_Page {

	/** Menu slug. */
	public const SLUG = 'ai-seo-assistant-weekly';

	/** Parent menu slug (the plugin's top-level menu). */
	public const PARENT = 'ai-seo-assistant';

	/** Stylesheet handle. */
	public const STYLE = 'ai-seo-assistant-weekly-report';

	/**
	 * Storage.
	 *
	 * @var Snapshot_Store
	 */
	protected Snapshot_Store $store;

	/**
	 * Screen hook suffix, set when the page is added.
	 *
	 * @var string
	 */
	protected string $hook = '';

	/**
	 * Constructor — dependencies only.
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
		add_action( 'admin_menu', [ $this, 'add_page' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_aisa_report_key', [ $this, 'generate_key' ] );
		add_action( 'admin_post_aisa_report_import', [ $this, 'import' ] );
		add_action( 'admin_post_aisa_report_access', [ $this, 'save_access' ] );
	}

	/**
	 * Agency users: the first item under the plugin's menu. The client: the plugin's only menu item.
	 *
	 * The client gets its own top-level page rather than a submenu under a parent it cannot open,
	 * because WordPress re-parents such a menu to its first open submenu and the page's screen hook
	 * then changes under it (the stylesheet would not load).
	 */
	public function add_page(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			$this->hook = (string) add_menu_page(
				__( 'Weekly report', 'ai-seo-assistant' ),
				__( 'Weekly report', 'ai-seo-assistant' ),
				'manage_options',
				self::SLUG,
				[ $this, 'render' ],
				'dashicons-chart-line',
				58
			);
			return;
		}
		$this->hook = (string) add_submenu_page(
			self::PARENT,
			__( 'Weekly report', 'ai-seo-assistant' ),
			__( 'Weekly report', 'ai-seo-assistant' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ],
			0
		);
	}

	/**
	 * The report stylesheet, on this screen only.
	 *
	 * @param string $hook Current screen hook suffix.
	 */
	public function enqueue( $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( self::STYLE, AI_SEO_ASSISTANT_URL . 'assets/css/weekly-report.css', [ 'dashicons' ], AI_SEO_ASSISTANT_VERSION );
	}

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$weeks = $this->store->all();
		$keys  = array_keys( $weeks );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only choice of which stored week to show.
		$want = isset( $_GET['week'] ) ? sanitize_text_field( wp_unslash( $_GET['week'] ) ) : '';
		$key  = in_array( $want, $keys, true ) ? $want : ( $keys[0] ?? '' );

		$context = [
			'business' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			/**
			 * The agency named on the report.
			 *
			 * @param string $agency Default "AJR Web Design".
			 */
			'agency'   => (string) apply_filters( 'ai_seo_assistant_report_agency', 'AJR Web Design' ),
			'now'      => time(),
		];

		// wp-header-end: WordPress moves other plugins' admin notices to this marker. Without it they are
		// inserted after the first h1, which is the report's headline inside the dark header.
		echo '<div class="wrap aisa-wrap"><hr class="wp-header-end">';
		if ( '' === $key ) {
			echo Report_View::empty_state( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise in Report_View.
		} else {
			$at                  = (int) array_search( $key, $keys, true );
			$context['latest']   = 0 === $at;
			$context['prev_url'] = isset( $keys[ $at + 1 ] ) ? $this->url( $keys[ $at + 1 ] ) : '';
			$context['next_url'] = $at > 0 ? $this->url( $keys[ $at - 1 ] ) : '';
			$context['alerted']  = Stale_Alert::alerted();
			echo Report_View::report( $weeks[ $key ], $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise in Report_View.
		}
		if ( current_user_can( Access::TOOLS_CAP ) ) {
			$this->delivery_panel();
		}
		echo '</div>';
	}

	/**
	 * Agency-only: push key, last update, import.
	 */
	protected function delivery_panel(): void {
		Push_Key::retire_stored();
		$user    = get_current_user_id();
		$new_key = get_transient( 'aisa_report_new_key_' . $user );
		if ( false !== $new_key ) {
			delete_transient( 'aisa_report_new_key_' . $user );
			$new_key = Secret_Store::open( $new_key ); // Held sealed for its five minutes (generate_key()).
		}
		$last = $this->store->last_push();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result code from our own redirect.
		$result = isset( $_GET['aisa_import'] ) ? sanitize_key( wp_unslash( $_GET['aisa_import'] ) ) : '';

		echo '<section class="aisa-delivery" aria-labelledby="aisa-delivery"><h2 id="aisa-delivery">' . esc_html__( 'Report delivery', 'ai-seo-assistant' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Only agency users see this panel.', 'ai-seo-assistant' ) . '</p>';

		$messages = [
			'stored'    => [ 'success', __( 'Report imported.', 'ai-seo-assistant' ) ],
			'replaced'  => [ 'success', __( 'Report imported, replacing the earlier version of that week.', 'ai-seo-assistant' ) ],
			'unchanged' => [ 'success', __( 'That week was already here, unchanged.', 'ai-seo-assistant' ) ],
			'stale'     => [ 'error', __( 'Not imported: a newer version of that week is already here.', 'ai-seo-assistant' ) ],
			'dropped'   => [ 'error', __( 'Not imported: that week is older than the 12 months kept.', 'ai-seo-assistant' ) ],
			'wrongsite' => [ 'error', __( 'Not imported: that file is a report for a different site.', 'ai-seo-assistant' ) ],
			'nofile'    => [ 'error', __( 'Not imported: no file arrived, or it was too large.', 'ai-seo-assistant' ) ],
			'invalid'   => [ 'error', __( 'Not imported: the file is not a valid weekly report.', 'ai-seo-assistant' ) ],
		];
		if ( isset( $messages[ $result ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $result ][0] ) . ' inline"><p>' . esc_html( $messages[ $result ][1] ) . '</p></div>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Push address', 'ai-seo-assistant' ) . '</th><td><code>' . esc_html( rest_url( Push_Endpoint::REST_NAMESPACE . Push_Endpoint::ROUTE ) ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Last update received', 'ai-seo-assistant' ) . '</th><td>' . esc_html( $last > 0 ? wp_date( 'l j F Y, g:ia', $last ) : __( 'Never', 'ai-seo-assistant' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Push key', 'ai-seo-assistant' ) . '</th><td>';
		if ( is_string( $new_key ) && '' !== $new_key ) {
			echo '<p><strong>' . esc_html__( 'Copy this key now — it will not be shown again:', 'ai-seo-assistant' ) . '</strong></p>';
			echo '<p><input type="text" class="large-text code" readonly value="' . esc_attr( $new_key ) . '" onfocus="this.select()" aria-label="' . esc_attr__( 'New push key', 'ai-seo-assistant' ) . '"></p>';
		} elseif ( Push_Key::from_constant() ) {
			echo esc_html__( 'Set in wp-config.php (AI_SEO_ASSISTANT_REPORT_KEY).', 'ai-seo-assistant' );
		} else {
			echo esc_html( '' !== Push_Key::get() ? __( 'Set (hidden).', 'ai-seo-assistant' ) : __( 'Not set — pushes are refused until a key is made.', 'ai-seo-assistant' ) );
		}
		if ( ! Push_Key::from_constant() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'aisa_report_key' );
			echo '<input type="hidden" name="action" value="aisa_report_key">';
			submit_button( '' !== Push_Key::get() ? __( 'Make a new key (the old one stops working)', 'ai-seo-assistant' ) : __( 'Make a key', 'ai-seo-assistant' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="aisa-snapshot">' . esc_html__( 'Import a report', 'ai-seo-assistant' ) . '</label></th><td>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aisa_report_import' );
		echo '<input type="hidden" name="action" value="aisa_report_import">';
		echo '<input type="file" id="aisa-snapshot" name="snapshot" accept="application/json,.json"> ';
		submit_button( __( 'Import', 'ai-seo-assistant' ), 'secondary', 'submit', false );
		echo '<p class="description">' . esc_html__( 'For hosts that block the weekly push: upload the JSON file retainer-scan saved for this site.', 'ai-seo-assistant' ) . '</p>';
		echo '</form></td></tr></tbody></table>';
		$this->access_form();
		echo '</section>';
	}

	/**
	 * Agency-only: who keeps the tool screens, and where the lateness alert goes.
	 */
	protected function access_form(): void {
		$named  = Access::named();
		$admins = get_users(
			[
				'role'    => 'administrator',
				'number'  => 50,
				'orderby' => 'display_name',
				'fields'  => [ 'ID', 'display_name', 'user_email' ],
			]
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result code from our own redirect.
		$saved = isset( $_GET['aisa_access'] ) ? sanitize_key( wp_unslash( $_GET['aisa_access'] ) ) : '';

		echo '<h3>' . esc_html__( 'Who sees the tools', 'ai-seo-assistant' ) . '</h3>';
		if ( 'saved' === $saved ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Saved.', 'ai-seo-assistant' ) . '</p></div>';
		} elseif ( 'self' === $saved ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Not saved: tick your own name too, or you would lose these screens.', 'ai-seo-assistant' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aisa_report_access' );
		echo '<input type="hidden" name="action" value="aisa_report_access">';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Agency users', 'ai-seo-assistant' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Agency users', 'ai-seo-assistant' ) . '</legend>';
		foreach ( $admins as $admin ) {
			$id = (int) $admin->ID;
			echo '<label><input type="checkbox" name="agency_users[]" value="' . esc_attr( (string) $id ) . '"' . checked( in_array( $id, $named, true ), true, false ) . '> ' . esc_html( $admin->display_name . ' (' . $admin->user_email . ')' ) . '</label><br>';
		}
		echo '<p class="description">' . esc_html(
			class_exists( '\AJR\Core\Admin\Support' )
				? __( 'Ticked Administrators keep the plugin’s tool screens; every other Administrator sees the Weekly report only. While nobody is ticked, AJR Core decides: Administrators on the agency’s email domain keep the tools.', 'ai-seo-assistant' )
				: __( 'Ticked Administrators keep the plugin’s tool screens; every other Administrator sees the Weekly report only. While nobody is ticked, every Administrator keeps the tools.', 'ai-seo-assistant' )
		) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="aisa-alert-email">' . esc_html__( 'Late-report alerts go to', 'ai-seo-assistant' ) . '</label></th><td><input type="email" class="regular-text" id="aisa-alert-email" name="alert_email" value="' . esc_attr( (string) get_option( Stale_Alert::ADDRESS, '' ) ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Empty sends alerts to the site’s admin email.', 'ai-seo-assistant' ) . '</p></td></tr></tbody></table>';
		submit_button( __( 'Save', 'ai-seo-assistant' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Save the agency users and the alert address.
	 */
	public function save_access(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ai-seo-assistant' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'aisa_report_access' );

		$posted = isset( $_POST['agency_users'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['agency_users'] ) ) : [];
		$admins = array_map(
			'intval',
			get_users(
				[
					'role'   => 'administrator',
					'fields' => 'ID',
					'number' => 50,
				]
			)
		);
		$ids    = array_values( array_intersect( array_unique( $posted ), $admins ) );
		if ( [] !== $ids && ! in_array( get_current_user_id(), $ids, true ) ) {
			wp_safe_redirect( add_query_arg( 'aisa_access', 'self', $this->url( '' ) ) );
			exit;
		}
		update_option( Access::OPTION, $ids, true );

		$email = isset( $_POST['alert_email'] ) ? sanitize_email( wp_unslash( $_POST['alert_email'] ) ) : '';
		update_option( Stale_Alert::ADDRESS, is_email( $email ) ? $email : '', false );

		wp_safe_redirect( add_query_arg( 'aisa_access', 'saved', $this->url( '' ) ) );
		exit;
	}

	/**
	 * Make a new push key and show it once.
	 */
	public function generate_key(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) || Push_Key::from_constant() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ai-seo-assistant' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'aisa_report_key' );
		$key = Push_Key::generate();
		if ( '' !== $key ) {
			// The transient is a wp_options row (without an object cache) for up to five minutes: seal it
			// like the key itself, so the plain key is never at rest anywhere.
			set_transient( 'aisa_report_new_key_' . get_current_user_id(), Secret_Store::seal( $key ), 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( $this->url( '' ) );
		exit;
	}

	/**
	 * Import a snapshot file (the fallback for hosts that block the push).
	 */
	public function import(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ai-seo-assistant' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'aisa_report_import' );

		$result = 'nofile';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only tmp_name/size/error are read, and the file's CONTENT is validated by Snapshot::from_json().
		$file = isset( $_FILES['snapshot'] ) && is_array( $_FILES['snapshot'] ) ? $_FILES['snapshot'] : null;
		if ( is_array( $file ) && UPLOAD_ERR_OK === ( $file['error'] ?? -1 ) && (int) ( $file['size'] ?? 0 ) <= Snapshot::MAX_BYTES && is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$errors   = [];
			$snapshot = Snapshot::from_json( (string) file_get_contents( (string) $file['tmp_name'] ), $errors ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload's temp file, not a URL.
			if ( null === $snapshot ) {
				$result = 'invalid';
			} elseif ( Push_Endpoint::host( $snapshot['site'] ) !== Push_Endpoint::host( (string) home_url() ) ) {
				$result = 'wrongsite';
			} else {
				$result = $this->store->put( $snapshot, time() );
			}
		}
		wp_safe_redirect( add_query_arg( 'aisa_import', $result, $this->url( '' ) ) );
		exit;
	}

	/**
	 * This screen's URL, optionally at a week.
	 *
	 * @param string $week Monday (YYYY-MM-DD) or ''.
	 */
	protected function url( string $week ): string {
		$args = [ 'page' => self::SLUG ];
		if ( '' !== $week ) {
			$args['week'] = $week;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
