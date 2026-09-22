<?php
/**
 * Admin UI: settings page, editor metabox, asset loading, and saving fields.
 */

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Core\Utils;
use AJR\SEOAssistant\AI\Claude_Client;

defined( 'ABSPATH' ) || exit;

class Admin {

	const NONCE_ACTION = 'ai_seo_assistant_generate';
	const NONCE_NAME   = 'ai_seo_assistant_nonce';

	/**
	 * Option that held the OpenAI key before the move to Claude (4.0.0).
	 */
	const LEGACY_OPENAI_KEY_OPTION = 'ai_seo_assistant_api_key';

	/**
	 * Autoloaded marker recording which one-time settings clean-ups have run.
	 */
	const SETTINGS_VERSION_OPTION = 'ai_seo_assistant_settings_version';

	/**
	 * The clean-up level this code expects. Deliberately NOT the plugin
	 * version: it only moves when a release adds a new one-time clean-up. If
	 * it followed the header, every release would re-run the clean-ups.
	 */
	const SETTINGS_VERSION = '4.0.0';

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
	 * Wires the admin UI to its collaborators.
	 *
	 * @param object               $seo_adapter          Active SEO plugin adapter.
	 * @param \AJR\SEOAssistant\Core\Logger $logger      Generation log store.
	 * @param object               $local_seo_context    Site and page SEO focus.
	 * @param object               $seo_adapter_resolver Detects the active SEO plugin.
	 * @param Claude_Client        $ai_client            Claude API client.
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
		add_action( 'save_post', [ $this, 'save_metadata_fields' ], 99 );
		add_action( 'wp_after_insert_post', [ $this, 'save_metadata_fields' ], 9999 );

		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'remove_legacy_openai_settings' ] );

		// A saved key is only needed when generating, so keep it out of the
		// options loaded (and object-cached) on every request.
		add_action( 'add_option_' . Claude_Client::OPTION_API_KEY, [ $this, 'keep_api_key_out_of_autoload' ] );
		add_action( 'update_option_' . Claude_Client::OPTION_API_KEY, [ $this, 'keep_api_key_out_of_autoload' ] );

		add_action( 'admin_post_ai_seo_assistant_test_claude', [ $this, 'test_claude_connection' ] );

		add_filter( 'plugin_action_links_' . AI_SEO_ASSISTANT_BASENAME, [ $this, 'add_action_links' ] );
	}

	/**
	 * Adds a "Settings" shortcut to the plugin's row on the Plugins screen.
	 *
	 * Points at the plugin's main settings page so the whole plugin has a
	 * single, discoverable entry point from the Plugins list.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=ai-seo-assistant' ) ),
			esc_html__( 'Settings', 'ai-seo-assistant' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	public function add_meta_box() {
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
		$is_editor   = in_array( $hook, [ 'post.php', 'post-new.php' ], true );
		$is_settings = 'toplevel_page_ai-seo-assistant' === $hook;
		$is_audit    = 'ai-seo-assistant_page_ai-seo-assistant-audit' === $hook;
		$is_report   = 'ai-seo-assistant_page_ai-seo-assistant-report' === $hook;
		$is_gsc      = 'ai-seo-assistant_page_ai-seo-assistant-gsc' === $hook;
		$is_indexing = 'ai-seo-assistant_page_ai-seo-assistant-indexing' === $hook;

		if ( ! $is_editor && ! $is_settings && ! $is_audit && ! $is_report && ! $is_gsc && ! $is_indexing ) {
			return;
		}

		wp_enqueue_style(
			'ai-seo-assistant-base',
			AI_SEO_ASSISTANT_URL . 'assets/css/admin-base.css',
			[],
			AI_SEO_ASSISTANT_VERSION
		);

		if ( $is_editor ) {
			wp_enqueue_style(
				'ai-seo-assistant-metabox',
				AI_SEO_ASSISTANT_URL . 'assets/css/admin-metabox.css',
				[ 'ai-seo-assistant-base' ],
				AI_SEO_ASSISTANT_VERSION
			);
		}

		if ( $is_audit || $is_indexing ) {
			wp_enqueue_style(
				'ai-seo-assistant-audit',
				AI_SEO_ASSISTANT_URL . 'assets/css/admin-audit.css',
				[ 'ai-seo-assistant-base' ],
				AI_SEO_ASSISTANT_VERSION
			);
		}

		if ( $is_report ) {
			wp_enqueue_style(
				'ai-seo-assistant-report',
				AI_SEO_ASSISTANT_URL . 'assets/css/admin-report.css',
				[ 'ai-seo-assistant-base' ],
				AI_SEO_ASSISTANT_VERSION
			);

			wp_enqueue_style(
				'ai-seo-assistant-print',
				AI_SEO_ASSISTANT_URL . 'assets/css/admin-print.css',
				[ 'ai-seo-assistant-report' ],
				AI_SEO_ASSISTANT_VERSION,
				'print'
			);
		}

		if ( $is_editor || $is_audit ) {
			wp_enqueue_script(
				'ai-seo-assistant-admin-js',
				AI_SEO_ASSISTANT_URL . 'assets/js/admin.js',
				[ 'jquery' ],
				AI_SEO_ASSISTANT_VERSION,
				true
			);

			wp_localize_script(
				'ai-seo-assistant-admin-js',
				'aiSeoAssistant',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				]
			);
		}
	}

	public function add_settings_page() {
		add_menu_page(
			'AI SEO Assistant',
			'AI SEO Assistant',
			'manage_options',
			'ai-seo-assistant',
			[ $this, 'render_settings_page' ],
			'dashicons-chart-line',
			58
		);

		add_submenu_page(
			'ai-seo-assistant',
			'Settings',
			'Settings',
			'manage_options',
			'ai-seo-assistant',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_focus_mode',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_focus_mode' ],
				'default'           => 'general',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			Claude_Client::OPTION_API_KEY,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_api_key' ],
				'default'           => '',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			Claude_Client::OPTION_MODEL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_model' ],
				'default'           => Claude_Client::DEFAULT_MODEL,
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_brand_context',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_primary_locations',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_secondary_locations',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_priority_services',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_local_notes',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_metadata_guidance',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_recommendation_guidance',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_gsc_guidance',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_post_types',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_post_types' ],
				'default'           => [ 'post', 'page' ],
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_tone',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'clear, practical, direct, and not overly salesy',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_title_length',
			[
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 60,
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_description_length',
			[
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 155,
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_avoid_phrases',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => 'discover, explore, learn more, unlock, dive into, welcome to, comprehensive guide, ultimate guide, in today\'s digital world',
			]
		);

		register_setting(
			'ai_seo_assistant_settings',
			'ai_seo_assistant_include_brand',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'no',
			]
		);
	}

	public function sanitize_focus_mode( $mode ) {
		$mode = sanitize_key( $mode );

		if ( ! in_array( $mode, [ 'general', 'local' ], true ) ) {
			$mode = 'general';
		}

		return $mode;
	}

	/**
	 * Keeps the saved Claude key when the field is submitted blank, and
	 * refuses anything that is not an Anthropic key.
	 *
	 * A rejected value keeps the existing key rather than wiping it, so a
	 * mistyped paste never silently switches AI generation off.
	 *
	 * @param mixed $api_key Submitted value.
	 * @return string
	 */
	public function sanitize_api_key( $api_key ) {
		$api_key     = trim( sanitize_text_field( (string) $api_key ) );
		$current_key = (string) get_option( Claude_Client::OPTION_API_KEY, '' );

		if ( '' === $api_key ) {
			return $current_key;
		}

		if ( 0 !== strpos( $api_key, Claude_Client::KEY_PREFIX ) ) {
			add_settings_error(
				Claude_Client::OPTION_API_KEY,
				'ai_seo_assistant_invalid_key',
				__( 'That does not look like a Claude API key (they start with sk-ant-). The previous key was kept.', 'ai-seo-assistant' )
			);

			return $current_key;
		}

		return $api_key;
	}

	/**
	 * Marks the saved Claude key as not autoloaded.
	 *
	 * The Settings API saves options with default autoload, which would put
	 * the plaintext key into memory and any persistent object cache on every
	 * page view. wp_set_option_autoload() exists from WordPress 6.4; on older
	 * versions the key simply stays autoloaded, as it did before.
	 */
	public function keep_api_key_out_of_autoload() {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( Claude_Client::OPTION_API_KEY, false );
		}
	}

	/**
	 * Restricts the model to the supported Claude models.
	 *
	 * @param mixed $model Submitted value.
	 * @return string
	 */
	public function sanitize_model( $model ) {
		$model = sanitize_text_field( (string) $model );

		return Claude_Client::is_supported_model( $model ) ? $model : Claude_Client::DEFAULT_MODEL;
	}

	/**
	 * Removes settings left over from the OpenAI version, once per site.
	 *
	 * The old option held an OpenAI secret that nothing reads any more;
	 * leaving an unused credential in the database is a liability. A stored
	 * OpenAI model name is reset so the dropdown shows a real Claude model.
	 *
	 * Gated by an autoloaded marker: after the first run, every admin request
	 * costs one in-memory option read and nothing else. (Checking the old
	 * options directly would query the database on every admin page once
	 * they are gone, because a missing option is not autoloaded.)
	 */
	public function remove_legacy_openai_settings() {
		if ( version_compare( (string) get_option( self::SETTINGS_VERSION_OPTION, '0' ), self::SETTINGS_VERSION, '>=' ) ) {
			return;
		}

		delete_option( self::LEGACY_OPENAI_KEY_OPTION );

		$model = get_option( Claude_Client::OPTION_MODEL, false );

		if ( false !== $model && ! Claude_Client::is_supported_model( $model ) ) {
			update_option( Claude_Client::OPTION_MODEL, Claude_Client::DEFAULT_MODEL, false );
		}

		update_option( self::SETTINGS_VERSION_OPTION, self::SETTINGS_VERSION, true );
	}

	public function sanitize_post_types( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return [ 'post', 'page' ];
		}

		$available_post_types = get_post_types( [ 'public' => true ], 'names' );

		return array_values(
			array_intersect(
				array_map( 'sanitize_key', $post_types ),
				$available_post_types
			)
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Top-level menu pages do not print Settings API notices on their own,
		// so without this a rejected API key would be dropped silently.
		settings_errors();

		$settings_notice = get_transient( 'ai_seo_assistant_settings_notice_' . get_current_user_id() );

		if ( ! empty( $settings_notice ) ) {
			delete_transient( 'ai_seo_assistant_settings_notice_' . get_current_user_id() );

			$notice_type = ! empty( $settings_notice['type'] ) && 'error' === $settings_notice['type'] ? 'error' : 'success';
			?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
				<p><?php echo esc_html( $settings_notice['message'] ); ?></p>
			</div>
			<?php
		}

		$focus_mode              = get_option( 'ai_seo_assistant_focus_mode', 'general' );
		$seo_detected            = $this->seo_adapter_resolver->get_adapter() !== null;
		$active_seo_name         = $this->seo_adapter_resolver->get_current_integration_name();
		$model                   = $this->ai_client->get_model();
		$brand_context           = get_option( 'ai_seo_assistant_brand_context', '' );
		$primary_locations       = get_option( 'ai_seo_assistant_primary_locations', '' );
		$secondary_locations     = get_option( 'ai_seo_assistant_secondary_locations', '' );
		$priority_services       = get_option( 'ai_seo_assistant_priority_services', '' );
		$local_notes             = get_option( 'ai_seo_assistant_local_notes', '' );
		$metadata_guidance       = get_option( 'ai_seo_assistant_metadata_guidance', '' );
		$recommendation_guidance = get_option( 'ai_seo_assistant_recommendation_guidance', '' );
		$gsc_guidance            = get_option( 'ai_seo_assistant_gsc_guidance', '' );
		$post_types              = get_option( 'ai_seo_assistant_post_types', [ 'post', 'page' ] );
		$tone                    = get_option( 'ai_seo_assistant_tone', 'clear, practical, direct, and not overly salesy' );
		$title_length            = get_option( 'ai_seo_assistant_title_length', 60 );
		$description_length      = get_option( 'ai_seo_assistant_description_length', 155 );
		$avoid_phrases           = get_option( 'ai_seo_assistant_avoid_phrases', 'discover, explore, learn more, unlock, dive into, welcome to, comprehensive guide, ultimate guide, in today\'s digital world' );
		$include_brand           = get_option( 'ai_seo_assistant_include_brand', 'no' );

		$available_post_types = get_post_types( [ 'public' => true ], 'objects' );
		?>
		<div class="wrap">
			<h1>AI SEO Assistant</h1>

			<?php if ( $seo_detected ) : ?>
				<div class="notice notice-info inline" style="margin: 12px 0;">
					<p>
						<strong>SEO Integration:</strong> Auto-detected &mdash; currently using <strong><?php echo esc_html( $active_seo_name ); ?></strong>.
						Supports The SEO Framework, Yoast SEO, and Rank Math.
					</p>
				</div>
			<?php else : ?>
				<div class="notice notice-warning inline" style="margin: 12px 0;">
					<p>
						<strong>No supported SEO plugin detected.</strong>
						Please activate The SEO Framework, Yoast SEO, or Rank Math to use AI SEO Assistant.
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'ai_seo_assistant_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_focus_mode">SEO Focus Mode</label>
						</th>
						<td>
							<select id="ai_seo_assistant_focus_mode" name="ai_seo_assistant_focus_mode">
								<option value="general" <?php selected( $focus_mode, 'general' ); ?>>General / Online</option>
								<option value="local" <?php selected( $focus_mode, 'local' ); ?>>Local SEO</option>
							</select>

							<p class="description">
								Use General / Online for non-location-specific businesses, online services, blogs, and most national/remote websites. Use Local SEO for clinics, local practices, trades, restaurants, and service-area businesses.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( Claude_Client::OPTION_API_KEY ); ?>">
								<?php esc_html_e( 'Claude API key', 'ai-seo-assistant' ); ?>
							</label>
						</th>
						<td>
							<?php
							$key_from_config   = $this->ai_client->has_config_key();
							$has_saved_api_key = '' !== (string) get_option( Claude_Client::OPTION_API_KEY, '' );

							$test_claude_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=ai_seo_assistant_test_claude' ),
								'ai_seo_assistant_test_claude',
								'ai_seo_assistant_test_claude_nonce'
							);
							?>

							<?php if ( $key_from_config ) : ?>

								<p>
									<strong><?php esc_html_e( 'Claude API key loaded from wp-config.php.', 'ai-seo-assistant' ); ?></strong>
								</p>

								<?php if ( $has_saved_api_key ) : ?>
									<p class="description">
										<?php esc_html_e( 'A key is also saved in the database, but the wp-config.php key takes priority and is the one used.', 'ai-seo-assistant' ); ?>
									</p>
								<?php endif; ?>

							<?php else : ?>

								<input
									type="password"
									id="<?php echo esc_attr( Claude_Client::OPTION_API_KEY ); ?>"
									name="<?php echo esc_attr( Claude_Client::OPTION_API_KEY ); ?>"
									value=""
									class="regular-text"
									autocomplete="off"
									spellcheck="false"
									placeholder="<?php echo esc_attr( $has_saved_api_key ? __( 'Saved. Leave blank to keep the current key.', 'ai-seo-assistant' ) : 'sk-ant-...' ); ?>"
								/>

								<p class="description">
									<?php if ( $has_saved_api_key ) : ?>
										<?php
										printf(
											/* translators: %s: masked API key, e.g. sk-ant-api...AbCd. */
											esc_html__( 'Current key: %s. Paste a new key to replace it.', 'ai-seo-assistant' ),
											'<code>' . esc_html( $this->ai_client->get_key_hint() ) . '</code>'
										);
										?>
									<?php else : ?>
										<?php
										printf(
											/* translators: %s: wp-config.php constant name. */
											esc_html__( 'Recommended: define %s in wp-config.php so the key never sits in the database. Pasting a key here also works.', 'ai-seo-assistant' ),
											'<code>' . esc_html( Claude_Client::CONFIG_CONSTANT ) . '</code>'
										);
										?>
									<?php endif; ?>
								</p>

							<?php endif; ?>

							<p>
								<a href="<?php echo esc_url( $test_claude_url ); ?>" class="button button-secondary">
									<?php esc_html_e( 'Test Claude connection', 'ai-seo-assistant' ); ?>
								</a>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( Claude_Client::OPTION_MODEL ); ?>"><?php esc_html_e( 'Model', 'ai-seo-assistant' ); ?></label>
						</th>
						<td>
							<select id="<?php echo esc_attr( Claude_Client::OPTION_MODEL ); ?>" name="<?php echo esc_attr( Claude_Client::OPTION_MODEL ); ?>">
								<?php foreach ( Claude_Client::available_models() as $model_id => $model_info ) : ?>
									<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $model, $model_id ); ?>>
										<?php echo esc_html( $model_info['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Opus gives the most careful titles and recommendations. Sonnet and Haiku answer faster and cost less per request.', 'ai-seo-assistant' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_brand_context">Brand / Site Context</label>
						</th>
						<td>
							<textarea
								id="ai_seo_assistant_brand_context"
								name="ai_seo_assistant_brand_context"
								rows="6"
								class="large-text"
							><?php echo esc_textarea( $brand_context ); ?></textarea>
							<p class="description">
								Overall business/site context used by both metadata generation and SEO recommendations.
							</p>
						</td>
					</tr>

					<?php if ( 'local' === $focus_mode ) : ?>
						<tr>
							<th scope="row">
								<label for="ai_seo_assistant_primary_locations">Primary Local SEO Locations</label>
							</th>
							<td>
								<textarea
									id="ai_seo_assistant_primary_locations"
									name="ai_seo_assistant_primary_locations"
									rows="3"
									class="large-text"
								><?php echo esc_textarea( $primary_locations ); ?></textarea>
								<p class="description">
									Highest-priority towns, cities, or service areas.
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ai_seo_assistant_secondary_locations">Secondary Local SEO Locations</label>
							</th>
							<td>
								<textarea
									id="ai_seo_assistant_secondary_locations"
									name="ai_seo_assistant_secondary_locations"
									rows="3"
									class="large-text"
								><?php echo esc_textarea( $secondary_locations ); ?></textarea>
								<p class="description">
									Additional nearby towns or service areas. These should be used naturally, not stuffed into every title.
								</p>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_priority_services">
								<?php echo esc_html( 'local' === $focus_mode ? 'Priority Services' : 'Priority Services / Topics' ); ?>
							</label>
						</th>
						<td>
							<textarea
								id="ai_seo_assistant_priority_services"
								name="ai_seo_assistant_priority_services"
								rows="4"
								class="large-text"
							><?php echo esc_textarea( $priority_services ); ?></textarea>
							<p class="description">
								<?php echo esc_html( 'local' === $focus_mode ? 'Services that matter most for search visibility or revenue.' : 'Services, topics, offers, or page themes that matter most for search visibility.' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_local_notes">SEO Strategy Notes</label>
						</th>
						<td>
							<textarea
								id="ai_seo_assistant_local_notes"
								name="ai_seo_assistant_local_notes"
								rows="4"
								class="large-text"
							><?php echo esc_textarea( $local_notes ); ?></textarea>
							<p class="description">
								General strategy guidance for this website. This is included in both metadata and recommendation prompts.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_metadata_guidance">Metadata Guidance</label>
						</th>
						<td>
							<textarea
								id="ai_seo_assistant_metadata_guidance"
								name="ai_seo_assistant_metadata_guidance"
								rows="5"
								class="large-text"
							><?php echo esc_textarea( $metadata_guidance ); ?></textarea>
							<p class="description">
								Client-specific guidance for SEO titles and meta descriptions. Use this to control tone, wording, keyword priorities, and what should or should not appear in generated metadata.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_recommendation_guidance">Recommendation Guidance</label>
						</th>
						<td>
							<textarea
								id="ai_seo_assistant_recommendation_guidance"
								name="ai_seo_assistant_recommendation_guidance"
								rows="6"
								class="large-text"
							><?php echo esc_textarea( $recommendation_guidance ); ?></textarea>
							<p class="description">
								Client-specific guidance for page SEO recommendations. Use this to control what the AI should prioritize when reviewing content, structure, internal links, local SEO, or business goals.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_gsc_guidance">Search Console Guidance</label>
						</th>
						<td>
							<textarea
								id="ai_seo_assistant_gsc_guidance"
								name="ai_seo_assistant_gsc_guidance"
								rows="5"
								class="large-text"
							><?php echo esc_textarea( $gsc_guidance ); ?></textarea>
							<p class="description">
								Optional guidance for how Search Console data should be interpreted. Use this to avoid overreacting to low-volume data or to define what counts as a priority opportunity for this client.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Enabled Post Types</th>
						<td>
							<?php foreach ( $available_post_types as $post_type ) : ?>
								<label style="display:block; margin-bottom:4px;">
									<input
										type="checkbox"
										name="ai_seo_assistant_post_types[]"
										value="<?php echo esc_attr( $post_type->name ); ?>"
										<?php checked( in_array( $post_type->name, (array) $post_types, true ) ); ?>
									>
									<?php echo esc_html( $post_type->labels->singular_name ); ?>
									<code><?php echo esc_html( $post_type->name ); ?></code>
								</label>
							<?php endforeach; ?>

							<p class="description">
								Choose which public post types should show the AI SEO Assistant metabox.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_tone">Default Tone</label>
						</th>
						<td>
							<input
								type="text"
								id="ai_seo_assistant_tone"
								name="ai_seo_assistant_tone"
								value="<?php echo esc_attr( $tone ); ?>"
								class="large-text"
							>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_title_length">Preferred Title Length</label>
						</th>
						<td>
							<input
								type="number"
								id="ai_seo_assistant_title_length"
								name="ai_seo_assistant_title_length"
								value="<?php echo esc_attr( $title_length ); ?>"
								class="small-text"
								min="30"
								max="80"
							>
							<p class="description">Recommended: 55–60 characters.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_description_length">Preferred Description Length</label>
						</th>
						<td>
							<input
								type="number"
								id="ai_seo_assistant_description_length"
								name="ai_seo_assistant_description_length"
								value="<?php echo esc_attr( $description_length ); ?>"
								class="small-text"
								min="100"
								max="180"
							>
							<p class="description">Recommended: 145–155 characters.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_avoid_phrases">Avoid Phrases</label>
						</th>
						<td>
							<textarea
								id="ai_seo_assistant_avoid_phrases"
								name="ai_seo_assistant_avoid_phrases"
								rows="4"
								class="large-text"
							><?php echo esc_textarea( $avoid_phrases ); ?></textarea>
							<p class="description">
								Comma-separated phrases the AI should avoid.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="ai_seo_assistant_include_brand">Include Brand in Title?</label>
						</th>
						<td>
							<select id="ai_seo_assistant_include_brand" name="ai_seo_assistant_include_brand">
								<option value="no" <?php selected( $include_brand, 'no' ); ?>>No</option>
								<option value="yes" <?php selected( $include_brand, 'yes' ); ?>>Yes</option>
								<option value="only_when_natural" <?php selected( $include_brand, 'only_when_natural' ); ?>>Only when natural</option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
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

		// Only write to the SEO plugin if the field contains a value — an empty
		// metabox field must not overwrite data already stored by the active SEO
		// plugin (e.g. Yoast saves its own values via the block-editor REST API
		// before WordPress processes classic metaboxes, and a blank POST value
		// would silently clear them).
		if ( isset( $_POST['ai_seo_title'] ) && '' !== trim( wp_unslash( $_POST['ai_seo_title'] ) ) ) {
			$this->tsf_adapter->save_title(
				$post_id,
				wp_unslash( $_POST['ai_seo_title'] )
			);
		}

		if ( isset( $_POST['ai_seo_description'] ) && '' !== trim( wp_unslash( $_POST['ai_seo_description'] ) ) ) {
			$this->tsf_adapter->save_description(
				$post_id,
				wp_unslash( $_POST['ai_seo_description'] )
			);
		}

		$this->local_seo_context->save_page_context( $post_id, $_POST );
	}

	/**
	 * admin-post handler for "Test Claude connection".
	 *
	 * Sends one tiny request with the configured key and model, then returns
	 * to the settings screen with a notice naming the model that answered,
	 * or the masked reason it failed.
	 */
	public function test_claude_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to test the Claude connection.', 'ai-seo-assistant' ) );
		}

		if (
			empty( $_GET['ai_seo_assistant_test_claude_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['ai_seo_assistant_test_claude_nonce'] ) ),
				'ai_seo_assistant_test_claude'
			)
		) {
			wp_die( esc_html__( 'Invalid request.', 'ai-seo-assistant' ) );
		}

		delete_transient( 'ai_seo_assistant_settings_notice_' . get_current_user_id() );

		$key_hint = $this->ai_client->get_key_hint();
		$result   = $this->ai_client->test_connection();

		if ( is_wp_error( $result ) ) {
			set_transient(
				'ai_seo_assistant_settings_notice_' . get_current_user_id(),
				[
					'type'    => 'error',
					'message' => sprintf(
						/* translators: 1: key hint, 2: error message. */
						__( 'Claude connection failed using %1$s: %2$s', 'ai-seo-assistant' ),
						$key_hint,
						Utils::mask_sensitive_text( $result->get_error_message() )
					),
				],
				60
			);
		} else {
			set_transient(
				'ai_seo_assistant_settings_notice_' . get_current_user_id(),
				[
					'type'    => 'success',
					'message' => sprintf(
						/* translators: 1: model ID, 2: key hint. */
						__( 'Claude is connected: %1$s answered using %2$s.', 'ai-seo-assistant' ),
						$this->ai_client->get_last_model(),
						$key_hint
					),
				],
				60
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ai-seo-assistant' ) );
		exit;
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
