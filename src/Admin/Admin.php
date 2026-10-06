<?php
/**
 * Admin UI: settings page, editor metabox, asset loading, and saving fields.
 */

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Core\Utils;
use AJR\SEOAssistant\AI\Claude_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen, editor metabox and their saves.
 *
 * One-time settings clean-ups (the OpenAI-era key, 4.0.0) moved to Core\Upgrade in 4.4.0.
 */
class Admin {

	const NONCE_ACTION = 'ai_seo_assistant_generate';
	const NONCE_NAME   = 'ai_seo_assistant_nonce';

	private $tsf_adapter;
	private $logger;
	private $local_seo_context;
	private $seo_adapter_resolver;

	/**
	 * Claude client, used for the settings screen (key status, model list)
	 * and the "Test Claude connection" action.
	 *
	 * @var Claude_Client
	 */
	private $ai_client;

	/**
	 * Post IDs whose metabox fields were already saved in this request.
	 *
	 * @var array<int,true>
	 */
	protected $saved = [];

	/**
	 * Wires the admin UI to its collaborators.
	 *
	 * @param object                        $seo_adapter          Active SEO plugin adapter.
	 * @param \AJR\SEOAssistant\Core\Logger $logger      Generation log store.
	 * @param object                        $local_seo_context    Site and page SEO focus.
	 * @param object                        $seo_adapter_resolver Detects the active SEO plugin.
	 * @param Claude_Client                 $ai_client            Claude API client.
	 */
	public function __construct( $seo_adapter, $logger, $local_seo_context, $seo_adapter_resolver, Claude_Client $ai_client ) {
		$this->tsf_adapter          = $seo_adapter;
		$this->logger               = $logger;
		$this->local_seo_context    = $local_seo_context;
		$this->seo_adapter_resolver = $seo_adapter_resolver;
		$this->ai_client            = $ai_client;
	}

	public function init() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		/*
		 * ONE save hook, deliberately the late one. Yoast writes its own meta on wp_after_insert_post at
		 * priority 10, after save_post, so a value written on save_post was overwritten by Yoast's copy of
		 * the old one (decision "Yoast overwrite fix", 2026-06).
		 */
		add_action( 'wp_after_insert_post', [ $this, 'save_metadata_fields' ], 9999 );
		// 5.0: the settings screen moved to Admin\Settings_Page (mockup G1); this class is the editor box only.
	}

	public function add_meta_box() {
		// The editor box calls Claude on the agency's key: agency users only (4.3.0). Without the box
		// there is no nonce in the form, so save_metadata_fields() does nothing for anyone else.
		if ( ! current_user_can( \AJR\SEOAssistant\Report\Access::TOOLS_CAP ) ) {
			return;
		}
		$post_types = get_option( 'ai_seo_assistant_post_types', [ 'post', 'page' ] );

		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'ai-seo-assistant',
				'AI SEO Assistant',
				[ $this, 'render_meta_box' ],
				$post_type,
				'normal',
				'high'
			);
		}
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$current_title       = $this->tsf_adapter->get_title( $post->ID );
		$current_description = $this->tsf_adapter->get_description( $post->ID );

		// What the box showed at page load. save_metadata_fields() writes a field only when it differs, so
		// a title changed in the SEO plugin's own sidebar is never reverted by this box's stale copy.
		printf( '<input type="hidden" name="ai_seo_title_original" value="%s">', esc_attr( $current_title ) );
		printf( '<input type="hidden" name="ai_seo_description_original" value="%s">', esc_attr( $current_description ) );

		$title_status       = Utils::get_title_status( $current_title );
		$description_status = Utils::get_description_status( $current_description );
		$latest_log         = $this->get_latest_successful_log( $post->ID );
		$page_context       = $this->local_seo_context->get_page_context( $post->ID );
		$is_local_mode      = $this->local_seo_context->is_local_mode();
		?>
		<div class="ai-seo-assistant-box">
			<p class="ai-seo-assistant-intro">
				<strong><?php echo esc_html( $this->tsf_adapter->get_name() ); ?> integration</strong><br>
				This tool fills the active SEO plugin title and meta description fields. It does not output duplicate front-end tags.
			</p>

			<hr class="ai-seo-assistant-divider">

			<div class="ai-seo-assistant-status">
				<strong>Current SEO status</strong><br>
				SEO title: <span id="ai-seo-title-status"><?php echo esc_html( $title_status ); ?></span><br>
				Meta description: <span id="ai-seo-description-status"><?php echo esc_html( $description_status ); ?></span>
			</div>

			<?php if ( ! empty( $latest_log ) ) : ?>
				<div class="ai-seo-assistant-log-summary">
					<strong>Last generation</strong><br>

					<?php
					$generated_timestamp = isset( $latest_log['timestamp'] ) ? absint( $latest_log['timestamp'] ) : 0;
					$generated_display   = '';

					if ( $generated_timestamp ) {
						$generated_display = wp_date( 'd/m/y H:i', $generated_timestamp );
					}
					?>

					Generated: <?php echo esc_html( $generated_display ); ?><br>
					Source: <?php echo esc_html( isset( $latest_log['source'] ) ? $latest_log['source'] : '' ); ?>

					<?php if ( ! empty( $latest_log['model'] ) ) : ?>
						<br>Model: <?php echo esc_html( $latest_log['model'] ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="ai-seo-assistant-field">
				<label for="ai_seo_title">
					<strong>SEO Title</strong>
				</label>

				<input
					type="text"
					id="ai_seo_title"
					name="ai_seo_title"
					value="<?php echo esc_attr( $current_title ); ?>"
					maxlength="70"
				>

				<span class="ai-seo-assistant-count">
					<span id="ai-seo-title-count"><?php echo esc_html( mb_strlen( $current_title ) ); ?></span> characters
				</span>
			</div>

			<div class="ai-seo-assistant-field">
				<label for="ai_seo_description">
					<strong>Meta Description</strong>
				</label>

				<textarea
					id="ai_seo_description"
					name="ai_seo_description"
					rows="4"
					maxlength="180"
				><?php echo esc_textarea( $current_description ); ?></textarea>

				<span class="ai-seo-assistant-count">
					<span id="ai-seo-description-count"><?php echo esc_html( mb_strlen( $current_description ) ); ?></span> characters
				</span>
			</div>

			<div class="ai-seo-assistant-local-context">
				<h3><?php echo esc_html( $is_local_mode ? 'Local SEO Focus' : 'SEO Focus' ); ?></h3>

				<p class="description">
					<?php if ( $is_local_mode ) : ?>
						Optional page-specific context used by the AI when generating metadata and recommendations. Use this for important service pages, local SEO pages, or pages targeting specific towns/services.
					<?php else : ?>
						Optional page-specific context used by the AI when generating metadata and recommendations. Use this for important service pages, topic pages, landing pages, or high-priority content.
					<?php endif; ?>
				</p>

				<div class="ai-seo-assistant-field">
					<label for="ai_seo_service_focus">
						<strong><?php echo esc_html( $is_local_mode ? 'Primary Service Focus' : 'Primary Service / Topic Focus' ); ?></strong>
					</label>

					<input
						type="text"
						id="ai_seo_service_focus"
						name="ai_seo_service_focus"
						value="<?php echo esc_attr( $page_context['service_focus'] ); ?>"
						placeholder="<?php echo esc_attr( $is_local_mode ? 'Example: Comprehensive Developmental or Neuropsychological Evaluations' : 'Example: WordPress Performance, SEO, Technical Support' ); ?>"
					>
				</div>

				<?php if ( $is_local_mode ) : ?>
					<div class="ai-seo-assistant-field">
						<label for="ai_seo_primary_location">
							<strong>Primary Location Focus</strong>
						</label>

						<input
							type="text"
							id="ai_seo_primary_location"
							name="ai_seo_primary_location"
							value="<?php echo esc_attr( $page_context['primary_location'] ); ?>"
							placeholder="Example: Franklin, MA"
						>
					</div>

					<div class="ai-seo-assistant-field">
						<label for="ai_seo_secondary_locations">
							<strong>Secondary Locations</strong>
						</label>

						<textarea
							id="ai_seo_secondary_locations"
							name="ai_seo_secondary_locations"
							rows="3"
							placeholder="Example: Medway, Norfolk, Millis, Medfield, Holliston"
						><?php echo esc_textarea( $page_context['secondary_locations'] ); ?></textarea>
					</div>
				<?php endif; ?>

				<div class="ai-seo-assistant-field">
					<label for="ai_seo_search_intent">
						<strong>Search Intent</strong>
					</label>

					<input
						type="text"
						id="ai_seo_search_intent"
						name="ai_seo_search_intent"
						value="<?php echo esc_attr( $page_context['search_intent'] ); ?>"
						placeholder="<?php echo esc_attr( $is_local_mode ? 'Example: Parents looking for neuropsychological evaluations nearby' : 'Example: Business owners looking for practical WordPress support or SEO analysis' ); ?>"
					>
				</div>

				<div class="ai-seo-assistant-field">
					<label for="ai_seo_priority">
						<strong>Priority</strong>
					</label>

					<select id="ai_seo_priority" name="ai_seo_priority">
						<option value="" <?php selected( $page_context['priority'], '' ); ?>>Not set</option>
						<option value="high" <?php selected( $page_context['priority'], 'high' ); ?>>High</option>
						<option value="medium" <?php selected( $page_context['priority'], 'medium' ); ?>>Medium</option>
						<option value="low" <?php selected( $page_context['priority'], 'low' ); ?>>Low</option>
					</select>
				</div>

				<div class="ai-seo-assistant-field">
					<label for="ai_seo_page_notes">
						<strong>Page Notes</strong>
					</label>

					<textarea
						id="ai_seo_page_notes"
						name="ai_seo_page_notes"
						rows="3"
						placeholder="<?php echo esc_attr( $is_local_mode ? 'Example: This is the client\'s highest-priority service page and should support local search visibility.' : 'Example: This is a high-priority landing page and should clearly explain the service, benefits, and next steps.' ); ?>"
					><?php echo esc_textarea( $page_context['page_notes'] ); ?></textarea>
					<div class="ai-seo-assistant-focus-autofill">
						<h4>Autofill SEO Focus</h4>

						<p class="description">
							Use Search Console data first, then page content, to suggest page-level SEO focus fields. Suggestions are not saved until you update the page.
						</p>

						<label class="ai-seo-assistant-inline-option">
							<input type="checkbox" id="ai-seo-autofill-overwrite" value="1">
							Overwrite existing focus fields
						</label>

						<p>
							<button
								type="button"
								class="button"
								id="ai-seo-autofill-focus-button"
								data-post-id="<?php echo esc_attr( $post->ID ); ?>"
							>
								Autofill SEO Focus
							</button>
						</p>

						<div
							id="ai-seo-autofill-focus-output"
							class="ai-seo-assistant-focus-autofill-output"
						></div>
					</div>
				</div>
			</div>

			<div class="ai-seo-assistant-actions">
				<button
					type="button"
					class="button button-primary"
					id="ai-seo-generate-button"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>"
				>
					Generate Metadata
				</button>

				<button
					type="button"
					class="button"
					id="ai-seo-clear-button"
				>
					Clear Fields
				</button>

				<button
					type="button"
					class="button"
					id="ai-seo-preview-content-button"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>"
				>
					Preview Extracted Content
				</button>

				<button
					type="button"
					class="button"
					id="ai-seo-recommendations-button"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>"
				>
					Generate SEO Recommendations
				</button>

				<span
					id="ai-seo-status"
					class="ai-seo-assistant-status-message"
				></span>
			</div>

			<div
				id="ai-seo-extracted-content-preview"
				class="ai-seo-assistant-preview"
			></div>

			<div
				id="ai-seo-recommendations-output"
				class="ai-seo-assistant-recommendations-output"
			></div>

			<p class="ai-seo-assistant-note">
				Generated metadata is not saved until you update the post/page.
			</p>
		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook ) {
		// The editor box is agency-only (see add_meta_box()), so its assets are too.
		$is_editor = in_array( $hook, [ 'post.php', 'post-new.php' ], true ) && current_user_can( \AJR\SEOAssistant\Report\Access::TOOLS_CAP );
		if ( ! $is_editor ) {
			return;
		}
		wp_enqueue_style( 'ai-seo-assistant-base', AI_SEO_ASSISTANT_URL . 'assets/css/admin-base.css', [], AI_SEO_ASSISTANT_VERSION );
		wp_enqueue_style( 'ai-seo-assistant-metabox', AI_SEO_ASSISTANT_URL . 'assets/css/admin-metabox.css', [ 'ai-seo-assistant-base' ], AI_SEO_ASSISTANT_VERSION );
		wp_enqueue_script( 'ai-seo-assistant-admin-js', AI_SEO_ASSISTANT_URL . 'assets/js/admin.js', [ 'jquery' ], AI_SEO_ASSISTANT_VERSION, true );
		wp_localize_script(
			'ai-seo-assistant-admin-js',
			'aiSeoAssistant',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			]
		);
	}

	public function save_metadata_fields( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Once per post per request: wp_insert_post can run more than once for one save (a plugin updating
		// the post from its own save hook), and the second pass must not write again.
		if ( isset( $this->saved[ $post_id ] ) ) {
			return;
		}
		$this->saved[ $post_id ] = true;

		$title       = self::posted_change( 'ai_seo_title' );
		$description = self::posted_change( 'ai_seo_description' );

		if ( null !== $title ) {
			$this->tsf_adapter->save_title( $post_id, $title );
		}

		if ( null !== $description ) {
			$this->tsf_adapter->save_description( $post_id, $description );
		}

		$this->local_seo_context->save_page_context( $post_id, $_POST );
	}

	/**
	 * The value to write for one SEO field, or null to leave the SEO plugin's value alone.
	 *
	 * Null when the field is absent or empty (an empty box must not clear what Yoast or Rank Math
	 * stores) and when it still equals what the box showed at page load: then nobody changed it HERE,
	 * and writing it back would revert an edit made in the SEO plugin's own sidebar on the same save.
	 * A form without the *_original field (rendered before 4.4.0) keeps the old rule: write if non-empty.
	 *
	 * @param string $field POST field name.
	 * @return string|null
	 */
	protected static function posted_change( string $field ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- the caller verified the nonce; the value is compared, and sanitised by the adapter.
		if ( ! isset( $_POST[ $field ] ) || ! is_string( $_POST[ $field ] ) ) {
			return null;
		}
		$value = wp_unslash( $_POST[ $field ] );
		if ( '' === trim( $value ) ) {
			return null;
		}
		if ( isset( $_POST[ $field . '_original' ] ) && is_string( $_POST[ $field . '_original' ] )
			&& trim( wp_unslash( $_POST[ $field . '_original' ] ) ) === trim( $value ) ) {
			return null;
		}
		// phpcs:enable

		return $value;
	}

	private function get_latest_successful_log( $post_id ) {
		$log = $this->logger->get_latest_log( $post_id );

		if ( empty( $log ) || ! is_array( $log ) ) {
			return [];
		}

		if ( ! empty( $log['error'] ) ) {
			return [];
		}

		return $log;
	}
}
