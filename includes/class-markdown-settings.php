<?php
/**
 * Admin settings page for WP Markdown for AI.
 */

namespace AJR\MarkdownForAI;

defined( 'ABSPATH' ) || exit;

class Settings {

	private const OPTION_KEY = 'wpmai_settings';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_wpmai_clear_cache', [ $this, 'handle_clear_cache' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_filter( 'plugin_action_links_ai-seo-assistant/ai-seo-assistant.php', [ $this, 'add_action_links' ] );
	}

	/**
	 * Enqueues admin JS on the plugin settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'settings_page_wp-markdown-for-ai' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'wpmai-admin',
			WPMAI_PLUGIN_URL . 'assets/js/wpmai-admin.js',
			[],
			WPMAI_VERSION,
			true
		);

		wp_localize_script(
			'wpmai-admin',
			'wpmai',
			[
				'i18n' => [
					'loading'      => __( 'Loading…', 'wp-markdown-for-ai' ),
					'success'      => __( 'Showing live Markdown output — this is exactly what an AI agent receives.', 'wp-markdown-for-ai' ),
					'error'        => __( 'Error: ', 'wp-markdown-for-ai' ),
					'showExcluded' => __( 'Show excluded content ▼', 'wp-markdown-for-ai' ),
					'hideExcluded' => __( 'Hide excluded content ▲', 'wp-markdown-for-ai' ),
				],
			]
		);
	}

	public function add_menu_page(): void {
		add_options_page(
			esc_html__( 'Markdown for AI', 'wp-markdown-for-ai' ),
			esc_html__( 'Markdown for AI', 'wp-markdown-for-ai' ),
			'manage_options',
			'wp-markdown-for-ai',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'wpmai_settings_group',
			self::OPTION_KEY,
			[ $this, 'sanitize' ]
		);

		// --- Endpoints section ---
		add_settings_section(
			'wpmai_endpoints',
			esc_html__( 'Endpoints', 'wp-markdown-for-ai' ),
			function () {
				echo '<p>' . esc_html__( 'Control which AI discovery endpoints are active on this site.', 'wp-markdown-for-ai' ) . '</p>';
			},
			'wp-markdown-for-ai'
		);

		add_settings_field(
			'enable_llms_index',
			esc_html__( 'Enable /llms.txt', 'wp-markdown-for-ai' ),
			[ $this, 'render_enable_llms_index_field' ],
			'wp-markdown-for-ai',
			'wpmai_endpoints'
		);

		add_settings_field(
			'enable_llms_full',
			esc_html__( 'Enable /llms-full.txt', 'wp-markdown-for-ai' ),
			[ $this, 'render_enable_llms_full_field' ],
			'wp-markdown-for-ai',
			'wpmai_endpoints'
		);

		add_settings_field(
			'enable_format_param',
			esc_html__( 'Enable ?format=markdown', 'wp-markdown-for-ai' ),
			[ $this, 'render_enable_format_param_field' ],
			'wp-markdown-for-ai',
			'wpmai_endpoints'
		);

		add_settings_field(
			'full_post_limit',
			esc_html__( 'Max posts in llms-full.txt (per type)', 'wp-markdown-for-ai' ),
			[ $this, 'render_full_post_limit_field' ],
			'wp-markdown-for-ai',
			'wpmai_endpoints'
		);

		// --- Content section ---
		add_settings_section(
			'wpmai_content',
			esc_html__( 'Content', 'wp-markdown-for-ai' ),
			null,
			'wp-markdown-for-ai'
		);

		add_settings_field(
			'post_types',
			esc_html__( 'Include post types', 'wp-markdown-for-ai' ),
			[ $this, 'render_post_types_field' ],
			'wp-markdown-for-ai',
			'wpmai_content'
		);

		add_settings_field(
			'excluded_ids',
			esc_html__( 'Exclude posts / pages', 'wp-markdown-for-ai' ),
			[ $this, 'render_excluded_ids_field' ],
			'wp-markdown-for-ai',
			'wpmai_content'
		);

		add_settings_field(
			'excerpt_length',
			esc_html__( 'Excerpt length (words)', 'wp-markdown-for-ai' ),
			[ $this, 'render_excerpt_length_field' ],
			'wp-markdown-for-ai',
			'wpmai_content'
		);

		// --- Polylang section (only shown when Polylang is active) ---
		if ( $this->polylang_active() ) {
			add_settings_section(
				'wpmai_polylang',
				esc_html__( 'Polylang', 'wp-markdown-for-ai' ),
				function () {
					echo '<p>' . esc_html__( 'Filter which languages appear in the llms.txt index and llms-full.txt.', 'wp-markdown-for-ai' ) . '</p>';
				},
				'wp-markdown-for-ai'
			);

			add_settings_field(
				'polylang_languages',
				esc_html__( 'Include languages', 'wp-markdown-for-ai' ),
				[ $this, 'render_polylang_languages_field' ],
				'wp-markdown-for-ai',
				'wpmai_polylang'
			);
		}

		// --- AI Instructions section ---
		add_settings_section(
			'wpmai_instructions',
			esc_html__( 'AI Instructions', 'wp-markdown-for-ai' ),
			function () {
				echo '<p>' . esc_html__( 'Optional block added to the top of llms.txt to guide AI agents on how to use this site\'s content.', 'wp-markdown-for-ai' ) . '</p>';
			},
			'wp-markdown-for-ai'
		);

		add_settings_field(
			'ai_instructions',
			esc_html__( 'Instructions', 'wp-markdown-for-ai' ),
			[ $this, 'render_ai_instructions_field' ],
			'wp-markdown-for-ai',
			'wpmai_instructions'
		);

		// --- Cache section ---
		add_settings_section(
			'wpmai_cache',
			esc_html__( 'Cache', 'wp-markdown-for-ai' ),
			null,
			'wp-markdown-for-ai'
		);

		add_settings_field(
			'cache_ttl_hours',
			esc_html__( 'Cache duration (hours)', 'wp-markdown-for-ai' ),
			[ $this, 'render_ttl_field' ],
			'wp-markdown-for-ai',
			'wpmai_cache'
		);
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	public function render_enable_llms_index_field(): void {
		$checked = (bool) self::get_option( 'enable_llms_index', true );
		printf(
			'<label><input type="checkbox" name="%s[enable_llms_index]" value="1"%s> %s</label>',
			esc_attr( self::OPTION_KEY ),
			checked( $checked, true, false ),
			esc_html__( 'Serve /llms.txt', 'wp-markdown-for-ai' )
		);
	}

	public function render_enable_llms_full_field(): void {
		$checked = (bool) self::get_option( 'enable_llms_full', true );
		printf(
			'<label><input type="checkbox" name="%s[enable_llms_full]" value="1"%s> %s</label><p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			checked( $checked, true, false ),
			esc_html__( 'Serve /llms-full.txt', 'wp-markdown-for-ai' ),
			esc_html__( 'Disable on very large sites if generation is too slow even with caching.', 'wp-markdown-for-ai' )
		);
	}

	public function render_enable_format_param_field(): void {
		$checked = (bool) self::get_option( 'enable_format_param', true );
		printf(
			'<label><input type="checkbox" name="%s[enable_format_param]" value="1"%s> %s</label><p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			checked( $checked, true, false ),
			esc_html__( 'Allow ?format=markdown on individual posts and pages', 'wp-markdown-for-ai' ),
			esc_html__( 'Also controls the Link header and <link> tag on each page.', 'wp-markdown-for-ai' )
		);
	}

	public function render_full_post_limit_field(): void {
		$value = (int) self::get_option( 'full_post_limit', 200 );
		printf(
			'<input type="number" name="%s[full_post_limit]" value="%d" min="1" max="1000" style="width:80px"><p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			$value,
			esc_html__( 'Caps the number of posts included per post type in llms-full.txt to prevent memory exhaustion on large sites.', 'wp-markdown-for-ai' )
		);
	}

	public function render_post_types_field(): void {
		$saved      = self::get_option( 'post_types', [ 'post', 'page' ] );
		$post_types = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $post_types as $pt ) {
			$checked = in_array( $pt->name, $saved, true );
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%s[post_types][]" value="%s"%s> %s <code>(%s)</code></label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $pt->name ),
				checked( $checked, true, false ),
				esc_html( $pt->labels->name ),
				esc_html( $pt->name )
			);
		}
	}

	public function render_excluded_ids_field(): void {
		$saved       = self::get_option( 'excluded_ids', [] );
		$recommended = $this->get_recommended_exclusions();

		if ( ! empty( $recommended ) ) {
			echo '<p style="margin-bottom:8px"><strong>' . esc_html__( 'Recommended exclusions:', 'wp-markdown-for-ai' ) . '</strong></p>';
			echo '<div style="border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:12px;background:#fafafa">';

			foreach ( $recommended as $item ) {
				$checked = in_array( $item['id'], array_map( 'absint', $saved ), true );
				printf(
					'<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
						<input type="checkbox" name="%s[excluded_ids][]" value="%d"%s>
						<span>%s</span>
						<code style="color:#666;font-size:11px">/%s/</code>
						<span style="background:%s;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;font-weight:600">%s</span>
					</label>',
					esc_attr( self::OPTION_KEY ),
					(int) $item['id'],
					checked( $checked, true, false ),
					esc_html( $item['title'] ),
					esc_html( $item['slug'] ),
					esc_attr( $item['badge_color'] ),
					esc_html( $item['badge'] )
				);
			}

			echo '</div>';
		}

		echo '<p style="margin-bottom:4px"><strong>' . esc_html__( 'Additional IDs to exclude:', 'wp-markdown-for-ai' ) . '</strong></p>';

		// Show IDs not already covered by the recommended checkboxes.
		$recommended_ids = array_column( $recommended, 'id' );
		$extra_ids       = array_diff( array_map( 'absint', $saved ), $recommended_ids );
		$extra_value     = implode( ', ', $extra_ids );

		printf(
			'<input type="text" name="%s[excluded_ids_extra]" value="%s" style="width:400px" placeholder="42, 57, 103">
			<p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $extra_value ),
			esc_html__( 'Comma-separated post/page IDs not listed above.', 'wp-markdown-for-ai' )
		);
	}

	/**
	 * Detects pages that are commonly unsuitable for AI indexing.
	 *
	 * @return array List of [ id, title, slug, badge, badge_color ] arrays.
	 */
	private function get_recommended_exclusions(): array {
		$candidates = [];

		// WordPress built-in: privacy policy page.
		$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $privacy_id ) {
			$candidates[ $privacy_id ] = [ 'badge' => 'Privacy Policy', 'badge_color' => '#8b5cf6' ];
		}

		// WooCommerce pages.
		if ( function_exists( 'wc_get_page_id' ) ) {
			$woo_pages = [
				'cart'      => [ 'badge' => 'WooCommerce', 'badge_color' => '#7c3aed' ],
				'checkout'  => [ 'badge' => 'WooCommerce', 'badge_color' => '#7c3aed' ],
				'myaccount' => [ 'badge' => 'WooCommerce', 'badge_color' => '#7c3aed' ],
			];
			foreach ( $woo_pages as $key => $meta ) {
				$id = (int) wc_get_page_id( $key );
				if ( $id > 0 ) {
					$candidates[ $id ] = $meta;
				}
			}
		}

		// Common slugs that suggest legal/utility content.
		$slug_patterns = [
			'terms'            => [ 'badge' => 'Recommended', 'badge_color' => '#059669' ],
			'terms-conditions' => [ 'badge' => 'Recommended', 'badge_color' => '#059669' ],
			'cookie-policy'    => [ 'badge' => 'Recommended', 'badge_color' => '#059669' ],
			'cookie-policy-eu' => [ 'badge' => 'Recommended', 'badge_color' => '#059669' ],
			'gdpr'             => [ 'badge' => 'Recommended', 'badge_color' => '#059669' ],
			'legal'            => [ 'badge' => 'Recommended', 'badge_color' => '#059669' ],
			'disclaimer'       => [ 'badge' => 'Recommended', 'badge_color' => '#059669' ],
			'thank-you'        => [ 'badge' => 'Recommended', 'badge_color' => '#059669' ],
			'order-received'   => [ 'badge' => 'Recommended', 'badge_color' => '#059669' ],
		];

		$slug_pages = get_posts(
			[
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => 50,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'post_name__in'          => array_keys( $slug_patterns ),
			]
		);

		foreach ( $slug_pages as $page ) {
			if ( isset( $slug_patterns[ $page->post_name ] ) && ! isset( $candidates[ $page->ID ] ) ) {
				$candidates[ $page->ID ] = $slug_patterns[ $page->post_name ];
			}
		}

		if ( empty( $candidates ) ) {
			return [];
		}

		// Fetch post data for all candidates in one query.
		$posts = get_posts(
			[
				'post__in'               => array_keys( $candidates ),
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => count( $candidates ),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'orderby'                => 'title',
				'order'                  => 'ASC',
			]
		);

		$result = [];

		foreach ( $posts as $post ) {
			$meta     = $candidates[ $post->ID ];
			$result[] = [
				'id'          => $post->ID,
				'title'       => get_the_title( $post ),
				'slug'        => $post->post_name,
				'badge'       => $meta['badge'],
				'badge_color' => $meta['badge_color'],
			];
		}

		return $result;
	}

	/**
	 * Returns all published posts that are excluded due to SEO noindex signals.
	 *
	 * Queries only the allowed post types and checks each via Indexability.
	 * Capped at 500 posts to keep the settings page fast on large sites.
	 *
	 * @return array List of [ id, title, slug, type, reason ] arrays.
	 */
	private function get_noindex_posts(): array {
		$allowed_types = self::get_option( 'post_types', [ 'post', 'page' ] );

		if ( empty( $allowed_types ) ) {
			return [];
		}

		$posts = get_posts(
			[
				'post_type'              => $allowed_types,
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'orderby'                => 'title',
				'order'                  => 'ASC',
			]
		);

		$result   = [];
		$excluded = array_map( 'absint', self::get_option( 'excluded_ids', [] ) );

		foreach ( $posts as $post ) {
			// Skip posts already in the manual exclude list — shown elsewhere.
			if ( in_array( $post->ID, $excluded, true ) ) {
				continue;
			}

			if ( ! Indexability::is_indexable( $post ) ) {
				$result[] = [
					'id'    => $post->ID,
					'title' => get_the_title( $post ),
					'slug'  => $post->post_name,
					'type'  => $post->post_type,
				];
			}
		}

		return $result;
	}

	public function render_excerpt_length_field(): void {
		$value = (int) self::get_option( 'excerpt_length', 20 );
		printf(
			'<input type="number" name="%s[excerpt_length]" value="%d" min="5" max="100" style="width:80px"> %s',
			esc_attr( self::OPTION_KEY ),
			$value,
			esc_html__( 'words shown per item in the llms.txt index.', 'wp-markdown-for-ai' )
		);
	}

	public function render_polylang_languages_field(): void {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return;
		}

		$saved = self::get_option( 'polylang_languages', [] );
		$slugs = pll_languages_list( [ 'fields' => 'slug' ] );
		$names = pll_languages_list( [ 'fields' => 'name' ] );

		foreach ( $slugs as $i => $slug ) {
			$name    = $names[ $i ] ?? $slug;
			$checked = empty( $saved ) || in_array( $slug, $saved, true );
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%s[polylang_languages][]" value="%s"%s> %s</label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $slug ),
				checked( $checked, true, false ),
				esc_html( $name )
			);
		}

		echo '<p class="description">' . esc_html__( 'Leave all checked to include every language.', 'wp-markdown-for-ai' ) . '</p>';
	}

	public function render_ai_instructions_field(): void {
		$value = self::get_option( 'ai_instructions', '' );
		printf(
			'<textarea name="%s[ai_instructions]" rows="6" style="width:600px;font-family:monospace">%s</textarea>
			<p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_textarea( $value ),
			esc_html__( 'Plain text or Markdown. Inserted under an "## Instructions" heading at the top of llms.txt. Use this to tell agents what the site is for, what content to prioritise, and any usage notes.', 'wp-markdown-for-ai' )
		);
	}

	public function render_ttl_field(): void {
		$value = (int) self::get_option( 'cache_ttl_hours', 12 );
		printf(
			'<input type="number" name="%s[cache_ttl_hours]" value="%d" min="1" max="168" style="width:80px"> %s',
			esc_attr( self::OPTION_KEY ),
			$value,
			esc_html__( 'hours — cache clears automatically when content is saved.', 'wp-markdown-for-ai' )
		);
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$llms_url      = home_url( '/llms.txt' );
		$llms_full_url = home_url( '/llms-full.txt' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Markdown for AI', 'wp-markdown-for-ai' ); ?></h1>

			<div class="notice notice-info" style="padding:12px 16px">
				<p>
					<strong><?php esc_html_e( 'Discovery endpoints:', 'wp-markdown-for-ai' ); ?></strong><br>
					<a href="<?php echo esc_url( $llms_url ); ?>" target="_blank"><?php echo esc_url( $llms_url ); ?></a>
					&mdash; <?php esc_html_e( 'Index of all content with links to Markdown versions.', 'wp-markdown-for-ai' ); ?><br>
					<a href="<?php echo esc_url( $llms_full_url ); ?>" target="_blank"><?php echo esc_url( $llms_full_url ); ?></a>
					&mdash; <?php esc_html_e( 'Full site content inlined as Markdown.', 'wp-markdown-for-ai' ); ?>
				</p>
				<p><?php esc_html_e( 'Append ?format=markdown to any post or page URL to retrieve its Markdown version.', 'wp-markdown-for-ai' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'wpmai_settings_group' );
				do_settings_sections( 'wp-markdown-for-ai' );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Excluded by SEO Settings', 'wp-markdown-for-ai' ); ?></h2>
			<?php
			$noindex_posts = $this->get_noindex_posts();
			$noindex_count = count( $noindex_posts );

			if ( ! get_option( 'blog_public' ) ) :
				?>
				<div class="notice notice-warning inline" style="margin:0">
					<p><?php esc_html_e( 'Your site is set to discourage search engines (Settings → Reading). No content will be served by any Markdown endpoint.', 'wp-markdown-for-ai' ); ?></p>
				</div>
			<?php elseif ( 0 === $noindex_count ) : ?>
				<p style="color:#666"><?php esc_html_e( 'No posts or pages are currently excluded due to noindex settings.', 'wp-markdown-for-ai' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of excluded posts/pages */
						esc_html( _n(
							'%d post or page is currently excluded from Markdown endpoints because it is set to noindex.',
							'%d posts or pages are currently excluded from Markdown endpoints because they are set to noindex.',
							$noindex_count,
							'wp-markdown-for-ai'
						) ),
						(int) $noindex_count
					);
					?>
					<button type="button" id="wpmai-noindex-toggle" class="button button-link" style="margin-left:8px">
						<?php esc_html_e( 'Show excluded content ▼', 'wp-markdown-for-ai' ); ?>
					</button>
				</p>

				<table id="wpmai-noindex-list" style="display:none;border-collapse:collapse;width:100%;max-width:800px">
					<thead>
						<tr style="border-bottom:2px solid #ddd">
							<th style="text-align:left;padding:8px 12px"><?php esc_html_e( 'Title', 'wp-markdown-for-ai' ); ?></th>
							<th style="text-align:left;padding:8px 12px"><?php esc_html_e( 'Type', 'wp-markdown-for-ai' ); ?></th>
							<th style="text-align:left;padding:8px 12px"><?php esc_html_e( 'Slug', 'wp-markdown-for-ai' ); ?></th>
							<th style="text-align:left;padding:8px 12px"><?php esc_html_e( 'Edit', 'wp-markdown-for-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $noindex_posts as $i => $item ) : ?>
							<tr style="border-bottom:1px solid #eee;background:<?php echo $i % 2 === 0 ? '#fff' : '#f9f9f9'; ?>">
								<td style="padding:8px 12px"><?php echo esc_html( $item['title'] ); ?></td>
								<td style="padding:8px 12px"><code><?php echo esc_html( $item['type'] ); ?></code></td>
								<td style="padding:8px 12px"><code>/<?php echo esc_html( $item['slug'] ); ?>/</code></td>
								<td style="padding:8px 12px">
									<a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>">
										<?php esc_html_e( 'Edit', 'wp-markdown-for-ai' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Markdown Preview', 'wp-markdown-for-ai' ); ?></h2>
			<p><?php esc_html_e( 'Select any post or page to preview its Markdown output as an AI agent would receive it.', 'wp-markdown-for-ai' ); ?></p>

			<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
				<?php
				$allowed_types = self::get_option( 'post_types', [ 'post', 'page' ] );
				$preview_posts = get_posts(
					[
						'post_type'              => $allowed_types,
						'post_status'            => 'publish',
						'posts_per_page'         => 100,
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
						'orderby'                => 'title',
						'order'                  => 'ASC',
					]
				);
				?>
				<select id="wpmai-preview-select" style="max-width:400px">
					<option value=""><?php esc_html_e( '— Select a post or page —', 'wp-markdown-for-ai' ); ?></option>
					<?php foreach ( $preview_posts as $preview_post ) : ?>
						<option value="<?php echo esc_url( add_query_arg( 'format', 'markdown', get_permalink( $preview_post ) ) ); ?>">
							<?php echo esc_html( html_entity_decode( get_the_title( $preview_post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?>
							<span>(<?php echo esc_html( $preview_post->post_type ); ?>)</span>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="button" id="wpmai-preview-btn" class="button button-secondary">
					<?php esc_html_e( 'Preview', 'wp-markdown-for-ai' ); ?>
				</button>
				<a id="wpmai-preview-link" href="#" target="_blank" style="display:none">
					<?php esc_html_e( 'Open in new tab ↗', 'wp-markdown-for-ai' ); ?>
				</a>
			</div>

			<div id="wpmai-preview-wrap" style="display:none">
				<div id="wpmai-preview-status" style="margin-bottom:8px;color:#666;font-style:italic"></div>
				<textarea id="wpmai-preview-output" readonly style="width:100%;height:500px;font-family:monospace;font-size:12px;line-height:1.6;background:#1e1e1e;color:#d4d4d4;border:1px solid #333;padding:16px;resize:vertical;box-sizing:border-box"></textarea>
			</div>


			<hr>

			<h2><?php esc_html_e( 'Cache', 'wp-markdown-for-ai' ); ?></h2>
			<p><?php esc_html_e( 'The cache is cleared automatically whenever a post or page is saved. Use this to force-clear all cached Markdown output.', 'wp-markdown-for-ai' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpmai_clear_cache">
				<?php wp_nonce_field( 'wpmai_clear_cache' ); ?>
				<?php submit_button( __( 'Clear all caches', 'wp-markdown-for-ai' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function admin_notices(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'settings_page_wp-markdown-for-ai' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['cache_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cache cleared.', 'wp-markdown-for-ai' ) . '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

	public function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-markdown-for-ai' ) );
		}

		check_admin_referer( 'wpmai_clear_cache' );

		Cache::flush_all();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'wp-markdown-for-ai', 'cache_cleared' => '1' ],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=wp-markdown-for-ai' ) ),
			esc_html__( 'Settings', 'wp-markdown-for-ai' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	// -------------------------------------------------------------------------
	// Sanitize
	// -------------------------------------------------------------------------

	public function sanitize( array $input ): array {
		$clean = [];

		// Endpoint toggles.
		$clean['enable_llms_index']   = ! empty( $input['enable_llms_index'] );
		$clean['enable_llms_full']    = ! empty( $input['enable_llms_full'] );
		$clean['enable_format_param'] = ! empty( $input['enable_format_param'] );
		$clean['full_post_limit']     = max( 1, min( 1000, (int) ( $input['full_post_limit'] ?? 200 ) ) );

		// Content.
		$valid_types         = array_keys( get_post_types( [ 'public' => true ] ) );
		$submitted_types     = $input['post_types'] ?? [];
		$clean['post_types'] = array_values(
			array_intersect( array_map( 'sanitize_key', (array) $submitted_types ), $valid_types )
		);

		// Merge checkbox-selected recommended IDs with manually entered extra IDs.
		$checkbox_ids          = array_map( 'absint', (array) ( $input['excluded_ids'] ?? [] ) );
		$extra_ids             = array_map( 'absint', explode( ',', $input['excluded_ids_extra'] ?? '' ) );
		$clean['excluded_ids'] = array_values( array_unique( array_filter( array_merge( $checkbox_ids, $extra_ids ) ) ) );

		$clean['excerpt_length'] = max( 5, min( 100, (int) ( $input['excerpt_length'] ?? 20 ) ) );

		// Polylang.
		if ( $this->polylang_active() && function_exists( 'pll_languages_list' ) ) {
			$valid_langs                  = pll_languages_list( [ 'fields' => 'slug' ] );
			$submitted_langs              = $input['polylang_languages'] ?? [];
			$clean['polylang_languages']  = array_values(
				array_intersect( array_map( 'sanitize_key', (array) $submitted_langs ), $valid_langs )
			);
		}

		// AI instructions — allow basic Markdown, strip HTML tags.
		$clean['ai_instructions'] = wp_strip_all_tags( $input['ai_instructions'] ?? '' );

		// Cache.
		$clean['cache_ttl_hours'] = max( 1, min( 168, (int) ( $input['cache_ttl_hours'] ?? 12 ) ) );

		Cache::flush_all();

		return $clean;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function polylang_active(): bool {
		return defined( 'POLYLANG_VERSION' );
	}

	/**
	 * Returns a single setting value with a default fallback.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_option( string $key, $default = null ) {
		$options = get_option( self::OPTION_KEY, [] );
		return $options[ $key ] ?? $default;
	}

	/**
	 * Returns the list of post types currently enabled for Markdown endpoints.
	 *
	 * @return string[]
	 */
	public static function allowed_post_types(): array {
		return (array) self::get_option( 'post_types', [ 'post', 'page' ] );
	}
}
