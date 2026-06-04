<?php
/**
 * Indexing audit and cleanup tools.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Indexing_Tools_Page {

	private $seo_adapter;

	public function __construct( $seo_adapter ) {
		$this->seo_adapter = $seo_adapter;
	}

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_post_ai_seo_assistant_apply_noindex', [ $this, 'apply_noindex' ] );
		add_action( 'admin_post_ai_seo_assistant_remove_noindex', [ $this, 'remove_noindex' ] );
		add_action( 'admin_post_ai_seo_assistant_apply_recommended_noindex', [ $this, 'apply_recommended_noindex' ] );
	}

	public function add_page() {
		add_submenu_page(
			'ai-seo-assistant',
			'Indexing Tools',
			'Indexing Tools',
			'manage_options',
			'ai-seo-assistant-indexing',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pages = $this->get_all_page_rows();

		$recommended_count = 0;
		$noindex_count     = 0;
		$review_count      = 0;

		foreach ( $pages as $page ) {
			if ( ! empty( $page['recommended_noindex'] ) ) {
				$recommended_count++;
			}

			if ( ! empty( $page['is_noindex'] ) ) {
				$noindex_count++;
			}

			if ( ! empty( $page['needs_review'] ) ) {
				$review_count++;
			}
		}
		?>
		<div class="wrap ai-seo-indexing-wrap">
			<h1>Indexing Tools</h1>

			<p>
				Review all pages and quickly apply noindex to system, legal, WooCommerce, account, checkout, and utility pages.
				Normal content pages are shown for visibility, and optional noindex is available when you want to override the suggestion.
			</p>

			<?php $this->render_notice(); ?>

			<div class="ai-seo-indexing-summary">
				<div class="postbox ai-seo-indexing-summary-card">
					<strong>Total pages</strong><br>
					<span><?php echo esc_html( count( $pages ) ); ?></span>
				</div>

				<div class="postbox ai-seo-indexing-summary-card">
					<strong>Recommended noindex</strong><br>
					<span><?php echo esc_html( $recommended_count ); ?></span>
				</div>

				<div class="postbox ai-seo-indexing-summary-card">
					<strong>Currently noindex</strong><br>
					<span><?php echo esc_html( $noindex_count ); ?></span>
				</div>

				<div class="postbox ai-seo-indexing-summary-card">
					<strong>Needs review</strong><br>
					<span><?php echo esc_html( $review_count ); ?></span>
				</div>
			</div>

			<?php if ( empty( $pages ) ) : ?>
				<div class="notice notice-info">
					<p>No pages were found.</p>
				</div>
			<?php else : ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ai-seo-indexing-bulk-form">
					<input type="hidden" name="action" value="ai_seo_assistant_apply_recommended_noindex">
					<?php wp_nonce_field( 'ai_seo_assistant_apply_recommended_noindex', 'ai_seo_assistant_indexing_nonce' ); ?>

					<?php submit_button( 'Apply Suggested Noindex Rules', 'primary', 'submit', false ); ?>
				</form>

				<table class="widefat striped ai-seo-indexing-table">
					<thead>
						<tr>
							<th>Page</th>
							<th>Type / Reason</th>
							<th>Current Status</th>
							<th>Recommendation</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pages as $page ) : ?>
							<?php
							$post_id    = absint( $page['post_id'] );
							$is_noindex = ! empty( $page['is_noindex'] );
							$edit_link  = get_edit_post_link( $post_id );
							$view_link  = get_permalink( $post_id );
							?>
							<tr>
								<td>
									<strong>
										<?php if ( $edit_link ) : ?>
											<a href="<?php echo esc_url( $edit_link ); ?>">
												<?php echo esc_html( get_the_title( $post_id ) ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( get_the_title( $post_id ) ); ?>
										<?php endif; ?>
									</strong>

									<div class="row-actions">
										<?php if ( $edit_link ) : ?>
											<span><a href="<?php echo esc_url( $edit_link ); ?>">Edit</a> | </span>
										<?php endif; ?>

										<?php if ( $view_link ) : ?>
											<span><a href="<?php echo esc_url( $view_link ); ?>" target="_blank" rel="noopener noreferrer">View</a></span>
										<?php endif; ?>
									</div>
								</td>

								<td>
									<?php echo esc_html( $page['reason'] ); ?>
								</td>

								<td>
									<?php if ( $is_noindex ) : ?>
										<span class="ai-seo-indexing-status is-noindex">Noindex</span>
									<?php else : ?>
										<span class="ai-seo-indexing-status is-indexable">Indexable</span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( ! empty( $page['recommended_noindex'] ) ) : ?>
										<span class="ai-seo-indexing-status is-suggested">Suggested noindex</span>
									<?php elseif ( ! empty( $page['needs_review'] ) ) : ?>
										<span class="ai-seo-indexing-status is-review">Review</span>
									<?php else : ?>
										<span class="ai-seo-indexing-status is-keep">Keep indexable</span>
									<?php endif; ?>
								</td>

								<td>
									<?php if ( ! empty( $page['recommended_noindex'] ) && ! $is_noindex ) : ?>

										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="ai_seo_assistant_apply_noindex">
											<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
											<?php wp_nonce_field( 'ai_seo_assistant_apply_noindex_' . $post_id, 'ai_seo_assistant_indexing_nonce' ); ?>

											<?php submit_button( 'Apply Noindex', 'primary small', 'submit', false ); ?>
										</form>

									<?php elseif ( $is_noindex ) : ?>

										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="ai_seo_assistant_remove_noindex">
											<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
											<?php wp_nonce_field( 'ai_seo_assistant_remove_noindex_' . $post_id, 'ai_seo_assistant_indexing_nonce' ); ?>

											<?php submit_button( 'Remove Noindex', 'secondary small', 'submit', false ); ?>
										</form>

									<?php else : ?>

										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="ai_seo_assistant_apply_noindex">
											<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
											<?php wp_nonce_field( 'ai_seo_assistant_apply_noindex_' . $post_id, 'ai_seo_assistant_indexing_nonce' ); ?>

											<?php submit_button( 'Optional Noindex', 'secondary small ai-seo-optional-noindex-button', 'submit', false ); ?>
										</form>

									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

			<?php endif; ?>
		</div>
		<?php
	}

	private function render_notice() {
		if ( empty( $_GET['ai_seo_indexing_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['ai_seo_indexing_notice'] ) );

		$messages = [
			'applied'       => [ 'success', 'Noindex applied.' ],
			'removed'       => [ 'success', 'Noindex removed.' ],
			'bulk_applied'  => [ 'success', 'Suggested noindex rules applied.' ],
			'not_supported' => [ 'error', 'The active SEO integration does not support noindex updates yet.' ],
			'error'         => [ 'error', 'Something went wrong.' ],
		];

		if ( empty( $messages[ $notice ] ) ) {
			return;
		}

		$type    = $messages[ $notice ][0];
		$message = $messages[ $notice ][1];
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	public function apply_noindex() {
		$post_id = $this->validate_single_action( 'apply_noindex' );

		if ( is_wp_error( $post_id ) ) {
			$this->redirect_with_notice( 'error' );
		}

		if ( ! $this->adapter_supports_noindex() ) {
			$this->redirect_with_notice( 'not_supported' );
		}

		$this->seo_adapter->save_noindex( $post_id, true );

		$this->redirect_with_notice( 'applied' );
	}

	public function remove_noindex() {
		$post_id = $this->validate_single_action( 'remove_noindex' );

		if ( is_wp_error( $post_id ) ) {
			$this->redirect_with_notice( 'error' );
		}

		if ( ! $this->adapter_supports_noindex() ) {
			$this->redirect_with_notice( 'not_supported' );
		}

		$this->seo_adapter->save_noindex( $post_id, false );

		$this->redirect_with_notice( 'removed' );
	}

	public function apply_recommended_noindex() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage indexing tools.', 'ai-seo-assistant' ) );
		}

		if (
			empty( $_POST['ai_seo_assistant_indexing_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['ai_seo_assistant_indexing_nonce'] ) ),
				'ai_seo_assistant_apply_recommended_noindex'
			)
		) {
			$this->redirect_with_notice( 'error' );
		}

		if ( ! $this->adapter_supports_noindex() ) {
			$this->redirect_with_notice( 'not_supported' );
		}

		$pages = $this->get_all_page_rows();

		foreach ( $pages as $page ) {
			$post_id = absint( $page['post_id'] );

			if (
				$post_id &&
				! empty( $page['recommended_noindex'] ) &&
				current_user_can( 'edit_post', $post_id )
			) {
				$this->seo_adapter->save_noindex( $post_id, true );
			}
		}

		$this->redirect_with_notice( 'bulk_applied' );
	}

	private function validate_single_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'permission_denied', 'Permission denied.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'invalid_post', 'Invalid post.' );
		}

		$nonce_action = 'ai_seo_assistant_' . $action . '_' . $post_id;

		if (
			empty( $_POST['ai_seo_assistant_indexing_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['ai_seo_assistant_indexing_nonce'] ) ),
				$nonce_action
			)
		) {
			return new WP_Error( 'invalid_nonce', 'Invalid nonce.' );
		}

		return $post_id;
	}

	private function get_all_page_rows() {
		$system_pages = $this->get_system_page_map();

		$query = new WP_Query(
			[
				'post_type'      => 'page',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			]
		);

		$rows = [];

		foreach ( $query->posts as $post_id ) {
			$post_id = absint( $post_id );

			if ( ! $post_id ) {
				continue;
			}

			$is_system  = isset( $system_pages[ $post_id ] );
			$reason     = $is_system ? $system_pages[ $post_id ]['reason'] : 'Standard content page';
			$is_noindex = $this->is_noindex( $post_id );

			$rows[] = [
				'post_id'             => $post_id,
				'reason'              => $reason,
				'is_noindex'          => $is_noindex,
				'recommended_noindex' => $is_system,
				'needs_review'        => ! $is_system && $is_noindex,
			];
		}

		return $rows;
	}

	private function get_system_page_map() {
		$pages = [];

		$this->add_woocommerce_pages( $pages );
		$this->add_wordpress_policy_pages( $pages );
		$this->add_common_slug_pages( $pages );

		return $this->dedupe_pages_assoc( $pages );
	}

	private function add_woocommerce_pages( &$pages ) {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return;
		}

		$woo_pages = [
			'cart'      => 'WooCommerce cart page',
			'checkout'  => 'WooCommerce checkout page; covers order-pay and order-received endpoints',
			'myaccount' => 'WooCommerce customer account page; covers login, register, lost-password, orders, downloads, addresses, and payment-methods endpoints',
			'terms'     => 'WooCommerce terms page',
		];

		foreach ( $woo_pages as $key => $reason ) {
			$post_id = wc_get_page_id( $key );

			if ( $post_id && $post_id > 0 ) {
				$pages[] = [
					'post_id' => $post_id,
					'reason'  => $reason,
				];
			}
		}
	}

	private function add_wordpress_policy_pages( &$pages ) {
		$privacy_page_id = absint( get_option( 'wp_page_for_privacy_policy' ) );

		if ( $privacy_page_id ) {
			$pages[] = [
				'post_id' => $privacy_page_id,
				'reason'  => 'WordPress privacy policy page',
			];
		}
	}

	private function add_common_slug_pages( &$pages ) {
		$slugs = [
			'cart'                 => 'Cart/system page',
			'checkout'             => 'Checkout/system page',
			'my-account'           => 'Customer account/system page',
			'account'              => 'Customer account/system page',
			'login'                => 'Login page',
			'register'             => 'Registration page',
			'lost-password'        => 'Lost password page',
			'privacy-policy'       => 'Legal/privacy page',
			'privacy-policy-gdpr'  => 'Legal/privacy page',
			'cookie-policy'        => 'Cookie policy page',
			'cookie-policy-eu'     => 'Cookie policy page',
			'terms'                => 'Terms/legal page',
			'terms-conditions'     => 'Terms/legal page',
			'terms-and-conditions' => 'Terms/legal page',
			'refund-policy'        => 'Refund policy page',
			'returns'              => 'Returns policy page',
			'return-policy'        => 'Returns policy page',
			'shipping-policy'      => 'Shipping policy page',
			'thank-you'            => 'Thank you/conversion page',
			'thank-you-page'       => 'Thank you/conversion page',
			'order-received'       => 'Order received/thank you page',
			'order-pay'            => 'Order payment page',
		];

		foreach ( $slugs as $slug => $reason ) {
			$page = get_page_by_path( $slug );

			if ( $page && ! empty( $page->ID ) ) {
				$pages[] = [
					'post_id' => absint( $page->ID ),
					'reason'  => $reason,
				];
			}
		}
	}

	private function dedupe_pages_assoc( $pages ) {
		$deduped = [];

		foreach ( $pages as $page ) {
			$post_id = absint( $page['post_id'] );

			if ( ! $post_id ) {
				continue;
			}

			if ( isset( $deduped[ $post_id ] ) ) {
				$deduped[ $post_id ]['reason'] .= '; ' . $page['reason'];
				continue;
			}

			$deduped[ $post_id ] = [
				'post_id' => $post_id,
				'reason'  => sanitize_text_field( $page['reason'] ),
			];
		}

		return $deduped;
	}

	private function is_noindex( $post_id ) {
		if ( ! method_exists( $this->seo_adapter, 'is_noindex' ) ) {
			return false;
		}

		return (bool) $this->seo_adapter->is_noindex( $post_id );
	}

	private function adapter_supports_noindex() {
		return method_exists( $this->seo_adapter, 'save_noindex' ) && method_exists( $this->seo_adapter, 'is_noindex' );
	}

	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'                   => 'ai-seo-assistant-indexing',
					'ai_seo_indexing_notice' => sanitize_key( $notice ),
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
