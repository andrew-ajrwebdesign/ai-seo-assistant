<?php
/**
 * Markdown for AI — dedicated admin page.
 *
 * Registers the "Markdown for AI" submenu page and handles all settings,
 * cache clearing, and the live Markdown preview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Markdown_Page {

	private string $screen_hook = '';

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_post_wpmai_clear_cache', [ $this, 'handle_clear_cache' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
	}

	public function add_page(): void {
		$this->screen_hook = add_submenu_page(
			'ai-seo-assistant',
			'Markdown for AI',
			'Markdown for AI',
			'manage_options',
			'ai-seo-assistant-markdown',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'wpmai_settings_group',
			'wpmai_settings',
			[ 'sanitize_callback' => [ $this, 'sanitize' ] ]
		);
	}

	public function enqueue_scripts( string $hook ): void {
		if ( $this->screen_hook !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'wpmai-admin',
			AI_SEO_ASSISTANT_URL . 'assets/js/wpmai-admin.js',
			[],
			AI_SEO_ASSISTANT_VERSION,
			true
		);

		wp_localize_script(
			'wpmai-admin',
			'wpmai',
			[
				'i18n' => [
					'loading'      => __( 'Loading…', 'ai-seo-assistant' ),
					'success'      => __( 'Showing live Markdown output — this is exactly what an AI agent receives.', 'ai-seo-assistant' ),
					'error'        => __( 'Error: ', 'ai-seo-assistant' ),
					'showExcluded' => __( 'Show excluded content ▼', 'ai-seo-assistant' ),
					'hideExcluded' => __( 'Hide excluded content ▲', 'ai-seo-assistant' ),
				],
			]
		);
	}

	public function admin_notices(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'ai-seo-assistant_page_ai-seo-assistant-markdown' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['cache_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Markdown cache cleared.</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s             = get_option( 'wpmai_settings', [] );
		$enabled       = isset( $s['enabled'] ) ? (bool) $s['enabled'] : true;
		$post_types    = $s['post_types'] ?? [ 'post', 'page' ];
		$excluded_ids  = $s['excluded_ids'] ?? [];
		$allowed_langs = $s['polylang_languages'] ?? [];
		$llms_url      = home_url( '/llms.txt' );
		$llms_full_url = home_url( '/llms-full.txt' );
		$option_key    = 'wpmai_settings';
		?>
		<div class="wrap">
			<h1>Markdown for AI</h1>

			<?php $this->render_conflict_notices(); ?>

			<div class="notice notice-info" style="padding:12px 16px">
				<p>
					<strong>Discovery endpoints:</strong><br>
					<a href="<?php echo esc_url( $llms_url ); ?>" target="_blank"><?php echo esc_url( $llms_url ); ?></a>
					&mdash; Index of all content with links to Markdown versions.<br>
					<a href="<?php echo esc_url( $llms_full_url ); ?>" target="_blank"><?php echo esc_url( $llms_full_url ); ?></a>
					&mdash; Full site content inlined as Markdown.
				</p>
				<p>Append <code>?format=markdown</code> to any post or page URL to retrieve its Markdown version.</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpmai_settings_group' ); ?>

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row">Enable Markdown for AI</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[enabled]" value="1" <?php checked( $enabled ); ?>>
								Markdown for AI is <strong><?php echo $enabled ? 'enabled' : 'disabled'; ?></strong>
							</label>
							<p class="description">
								<strong>Checked</strong> = all endpoints active (/llms.txt, /llms-full.txt, ?format=markdown, REST API).<br>
								<strong>Unchecked</strong> = all endpoints disabled. Your settings are preserved and can be re-enabled at any time.
							</p>
						</td>
					</tr>

				</table>

				<h2>Endpoints</h2>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row">Enable /llms.txt</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[enable_llms_index]" value="1" <?php checked( (bool) ( $s['enable_llms_index'] ?? true ) ); ?>>
								Serve /llms.txt
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">Enable /llms-full.txt</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[enable_llms_full]" value="1" <?php checked( (bool) ( $s['enable_llms_full'] ?? true ) ); ?>>
								Serve /llms-full.txt
							</label>
							<p class="description">Disable on very large sites if generation is too slow even with caching.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Enable ?format=markdown</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[enable_format_param]" value="1" <?php checked( (bool) ( $s['enable_format_param'] ?? true ) ); ?>>
								Allow <code>?format=markdown</code> on individual posts and pages
							</label>
							<p class="description">Also controls the HTTP Link header and &lt;link&gt; tag on each page.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wpmai_full_post_limit">Max posts in llms-full.txt (per type)</label>
						</th>
						<td>
							<input type="number" id="wpmai_full_post_limit" name="<?php echo esc_attr( $option_key ); ?>[full_post_limit]" value="<?php echo esc_attr( $s['full_post_limit'] ?? 200 ); ?>" min="1" max="1000" style="width:80px">
							<p class="description">Caps the number of posts per post type to prevent memory exhaustion on large sites.</p>
						</td>
					</tr>

				</table>

				<h2>Content</h2>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row">Include post types</th>
						<td>
							<?php foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) : ?>
								<label style="display:block;margin-bottom:4px">
									<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $post_types, true ) ); ?>>
									<?php echo esc_html( $pt->labels->name ); ?> <code>(<?php echo esc_html( $pt->name ); ?>)</code>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">Exclude posts / pages</th>
						<td>
							<?php $this->render_exclusions_field( $excluded_ids, $option_key ); ?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wpmai_excerpt_length">Excerpt length (words)</label>
						</th>
						<td>
							<input type="number" id="wpmai_excerpt_length" name="<?php echo esc_attr( $option_key ); ?>[excerpt_length]" value="<?php echo esc_attr( $s['excerpt_length'] ?? 20 ); ?>" min="5" max="100" style="width:80px">
							words shown per item in the /llms.txt index.
						</td>
					</tr>

				</table>

				<?php if ( defined( 'POLYLANG_VERSION' ) && function_exists( 'pll_languages_list' ) ) : ?>
					<h2>Polylang</h2>
					<p>Filter which languages appear in the llms.txt index and llms-full.txt.</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Include languages</th>
							<td>
								<?php
								$slugs = pll_languages_list( [ 'fields' => 'slug' ] );
								$names = pll_languages_list( [ 'fields' => 'name' ] );
								foreach ( $slugs as $i => $slug ) :
									$name    = $names[ $i ] ?? $slug;
									$checked = empty( $allowed_langs ) || in_array( $slug, $allowed_langs, true );
									?>
									<label style="display:block;margin-bottom:4px">
										<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[polylang_languages][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?>>
										<?php echo esc_html( $name ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description">Leave all checked to include every language.</p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<h2>AI Instructions</h2>
				<p>Optional block added to the top of /llms.txt to guide AI agents on how to use this site's content.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wpmai_ai_instructions">Instructions</label>
						</th>
						<td>
							<textarea id="wpmai_ai_instructions" name="<?php echo esc_attr( $option_key ); ?>[ai_instructions]" rows="6" style="width:600px;font-family:monospace"><?php echo esc_textarea( $s['ai_instructions'] ?? '' ); ?></textarea>
							<p class="description">Plain text or Markdown. Use this to tell agents what the site is for, what content to prioritise, and any usage notes.</p>
						</td>
					</tr>
				</table>

				<h2>Cache</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wpmai_cache_ttl_hours">Cache duration (hours)</label>
						</th>
						<td>
							<input type="number" id="wpmai_cache_ttl_hours" name="<?php echo esc_attr( $option_key ); ?>[cache_ttl_hours]" value="<?php echo esc_attr( $s['cache_ttl_hours'] ?? 12 ); ?>" min="1" max="168" style="width:80px">
							hours — cache clears automatically when content is saved.
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>

			<?php $this->render_noindex_section(); ?>

			<hr>

			<?php $this->render_preview_section( $post_types ); ?>

			<hr>

			<h2>Cache</h2>
			<p>The cache clears automatically whenever a post is saved. Use this to force-clear all cached Markdown output.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpmai_clear_cache">
				<?php wp_nonce_field( 'wpmai_clear_cache' ); ?>
				<?php submit_button( 'Clear all caches', 'secondary', 'submit', false ); ?>
			</form>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Conflict detection
	// -------------------------------------------------------------------------

	private function render_conflict_notices(): void {
		$conflicts = $this->detect_conflicts();

		foreach ( $conflicts as $conflict ) {
			$type = $conflict['type']; // 'error' | 'warning'
			printf(
				'<div class="notice notice-%s" style="padding:12px 16px"><p>%s</p></div>',
				esc_attr( $type ),
				wp_kses( $conflict['message'], [ 'strong' => [], 'a' => [ 'href' => [], 'target' => [] ], 'code' => [] ] )
			);
		}
	}

	private function detect_conflicts(): array {
		$conflicts     = [];
		$physical_llms = ABSPATH . 'llms.txt';
		$file_exists   = file_exists( $physical_llms );
		$yoast_on      = $this->yoast_llms_enabled();

		// --- Handle the delete action first so notices reflect the new state ---
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['wpmai_delete_llms'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'wpmai_delete_llms' ) ) {
			if ( current_user_can( 'manage_options' ) && $file_exists ) {
				wp_delete_file( $physical_llms );
				$file_exists = false;
				$conflicts[] = [
					'type'    => 'success',
					'message' => '<strong>llms.txt deleted.</strong> This plugin will now serve /llms.txt dynamically. '
						. ( $yoast_on ? 'Yoast SEO\'s llms.txt feature is still enabled — it will recreate the file on its next weekly run. Turn it off in <a href="' . esc_url( admin_url( 'admin.php?page=wpseo_page_settings#/site-features' ) ) . '" target="_blank">Yoast → Settings → Site features</a> to prevent that.' : '' ),
				];
			}
		}

		// --- Physical llms.txt file at WordPress root ---
		// A real file is served by the web server before WordPress loads,
		// completely bypassing this plugin's rewrite-rule endpoint.
		if ( $file_exists ) {
			$delete_url = add_query_arg(
				[ 'page' => 'ai-seo-assistant-markdown', 'wpmai_delete_llms' => '1', '_wpnonce' => wp_create_nonce( 'wpmai_delete_llms' ) ],
				admin_url( 'admin.php' )
			);

			$source_note = $yoast_on
				? 'Yoast SEO\'s llms.txt feature is also enabled and will recreate this file on its next weekly run.'
				: 'It may have been created by another plugin or placed there manually.';

			$conflicts[] = [
				'type'    => 'error',
				'message' => '<strong>A physical llms.txt file exists at your site root.</strong> '
					. 'Your web server serves this file directly, so this plugin\'s dynamic /llms.txt endpoint is currently bypassed. '
					. $source_note . ' '
					. '<a href="' . esc_url( $delete_url ) . '">Delete it now</a> to let this plugin take over.',
			];
		}

		// --- Yoast SEO llms.txt feature is enabled ---
		// Even without a physical file today, Yoast will generate one on its next weekly cron,
		// which will then silently override this plugin's dynamic endpoint.
		if ( $yoast_on ) {
			$yoast_settings_url = admin_url( 'admin.php?page=wpseo_page_settings#/site-features' );

			$detail = $file_exists
				? 'It has already created (or attempted to create) a physical file — see the error above.'
				: 'No physical file exists yet, but Yoast will create one on its next weekly cron run, which will then bypass this plugin\'s dynamic endpoint.';

			$conflicts[] = [
				'type'    => 'warning',
				'message' => '<strong>Yoast SEO\'s llms.txt feature is enabled.</strong> ' . $detail . ' '
					. 'To use this plugin\'s always-current, configurable version instead, turn off Yoast\'s feature in '
					. '<a href="' . esc_url( $yoast_settings_url ) . '" target="_blank">Yoast → Settings → Site features</a>.',
			];
		}

		return $conflicts;
	}

	private function yoast_llms_enabled(): bool {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return false;
		}

		$wpseo = get_option( 'wpseo', [] );
		return ! empty( $wpseo['enable_llms_txt'] );
	}

	// -------------------------------------------------------------------------
	// Exclusions field
	// -------------------------------------------------------------------------

	private function render_exclusions_field( array $saved, string $option_key ): void {
		$recommended = $this->get_recommended_exclusions();

		if ( ! empty( $recommended ) ) {
			echo '<p style="margin-bottom:8px"><strong>Recommended exclusions:</strong></p>';
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
					esc_attr( $option_key ),
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

		echo '<p style="margin-bottom:4px"><strong>Additional IDs to exclude:</strong></p>';

		$recommended_ids = array_column( $recommended, 'id' );
		$extra_ids       = array_diff( array_map( 'absint', $saved ), $recommended_ids );

		printf(
			'<input type="text" name="%s[excluded_ids_extra]" value="%s" style="width:400px" placeholder="42, 57, 103">
			<p class="description">Comma-separated post/page IDs not listed above.</p>',
			esc_attr( $option_key ),
			esc_attr( implode( ', ', $extra_ids ) )
		);
	}

	private function get_recommended_exclusions(): array {
		$candidates = [];

		$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $privacy_id ) {
			$candidates[ $privacy_id ] = [ 'badge' => 'Privacy Policy', 'badge_color' => '#8b5cf6' ];
		}

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( [ 'cart', 'checkout', 'myaccount' ] as $key ) {
				$id = (int) wc_get_page_id( $key );
				if ( $id > 0 ) {
					$candidates[ $id ] = [ 'badge' => 'WooCommerce', 'badge_color' => '#7c3aed' ];
				}
			}
		}

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

		$slug_pages = get_posts( [
			'post_type'              => 'page',
			'post_status'            => 'publish',
			'posts_per_page'         => 50,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'post_name__in'          => array_keys( $slug_patterns ),
		] );

		foreach ( $slug_pages as $page ) {
			if ( isset( $slug_patterns[ $page->post_name ] ) && ! isset( $candidates[ $page->ID ] ) ) {
				$candidates[ $page->ID ] = $slug_patterns[ $page->post_name ];
			}
		}

		if ( empty( $candidates ) ) {
			return [];
		}

		$posts  = get_posts( [
			'post__in'               => array_keys( $candidates ),
			'post_type'              => 'any',
			'post_status'            => 'any',
			'posts_per_page'         => count( $candidates ),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
		] );
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

	// -------------------------------------------------------------------------
	// Excluded by SEO settings
	// -------------------------------------------------------------------------

	private function render_noindex_section(): void {
		$noindex_posts = $this->get_noindex_posts();
		$count         = count( $noindex_posts );

		echo '<h2>Excluded by SEO Settings</h2>';

		if ( ! get_option( 'blog_public' ) ) {
			echo '<div class="notice notice-warning inline" style="margin:0"><p>Your site is set to discourage search engines (Settings → Reading). No content will be served by any Markdown endpoint.</p></div>';
			return;
		}

		if ( 0 === $count ) {
			echo '<p style="color:#666">No posts or pages are currently excluded due to noindex settings.</p>';
			return;
		}

		printf(
			'<p>%s <button type="button" id="wpmai-noindex-toggle" class="button button-link" style="margin-left:8px">Show excluded content ▼</button></p>',
			esc_html( sprintf(
				_n(
					'%d post or page is excluded from Markdown endpoints because it is set to noindex.',
					'%d posts or pages are excluded from Markdown endpoints because they are set to noindex.',
					$count
				),
				$count
			) )
		);

		echo '<table id="wpmai-noindex-list" style="display:none;border-collapse:collapse;width:100%;max-width:800px">
			<thead><tr style="border-bottom:2px solid #ddd">
				<th style="text-align:left;padding:8px 12px">Title</th>
				<th style="text-align:left;padding:8px 12px">Type</th>
				<th style="text-align:left;padding:8px 12px">Slug</th>
				<th style="text-align:left;padding:8px 12px">Edit</th>
			</tr></thead><tbody>';

		foreach ( $noindex_posts as $i => $item ) {
			printf(
				'<tr style="border-bottom:1px solid #eee;background:%s">
					<td style="padding:8px 12px">%s</td>
					<td style="padding:8px 12px"><code>%s</code></td>
					<td style="padding:8px 12px"><code>/%s/</code></td>
					<td style="padding:8px 12px"><a href="%s">Edit</a></td>
				</tr>',
				$i % 2 === 0 ? '#fff' : '#f9f9f9',
				esc_html( $item['title'] ),
				esc_html( $item['type'] ),
				esc_html( $item['slug'] ),
				esc_url( get_edit_post_link( $item['id'] ) )
			);
		}

		echo '</tbody></table>';
	}

	private function get_noindex_posts(): array {
		$s             = get_option( 'wpmai_settings', [] );
		$allowed_types = $s['post_types'] ?? [ 'post', 'page' ];

		if ( empty( $allowed_types ) ) {
			return [];
		}

		$posts    = get_posts( [
			'post_type'              => $allowed_types,
			'post_status'            => 'publish',
			'posts_per_page'         => 500,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
		] );
		$excluded = array_map( 'absint', $s['excluded_ids'] ?? [] );
		$result   = [];

		foreach ( $posts as $post ) {
			if ( in_array( $post->ID, $excluded, true ) ) {
				continue;
			}

			if ( ! \AJR\MarkdownForAI\Indexability::is_indexable( $post ) ) {
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

	// -------------------------------------------------------------------------
	// Markdown preview
	// -------------------------------------------------------------------------

	private function render_preview_section( array $post_types ): void {
		$preview_posts = get_posts( [
			'post_type'              => $post_types ?: [ 'post', 'page' ],
			'post_status'            => 'publish',
			'posts_per_page'         => 100,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
		] );
		?>
		<h2>Markdown Preview</h2>
		<p>Select any post or page to preview its Markdown output as an AI agent would receive it.</p>

		<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
			<select id="wpmai-preview-select" style="max-width:400px">
				<option value="">— Select a post or page —</option>
				<?php foreach ( $preview_posts as $preview_post ) : ?>
					<option value="<?php echo esc_url( add_query_arg( 'format', 'markdown', get_permalink( $preview_post ) ) ); ?>">
						<?php echo esc_html( html_entity_decode( get_the_title( $preview_post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?>
						(<?php echo esc_html( $preview_post->post_type ); ?>)
					</option>
				<?php endforeach; ?>
			</select>
			<button type="button" id="wpmai-preview-btn" class="button button-secondary">Preview</button>
			<a id="wpmai-preview-link" href="#" target="_blank" style="display:none">Open in new tab ↗</a>
		</div>

		<div id="wpmai-preview-wrap" style="display:none">
			<div id="wpmai-preview-status" style="margin-bottom:8px;color:#666;font-style:italic"></div>
			<textarea id="wpmai-preview-output" readonly style="width:100%;height:500px;font-family:monospace;font-size:12px;line-height:1.6;background:#1e1e1e;color:#d4d4d4;border:1px solid #333;padding:16px;resize:vertical;box-sizing:border-box"></textarea>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Cache clear
	// -------------------------------------------------------------------------

	public function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ai-seo-assistant' ) );
		}

		check_admin_referer( 'wpmai_clear_cache' );

		\AJR\MarkdownForAI\Cache::flush_all();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'ai-seo-assistant-markdown', 'cache_cleared' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// Sanitize
	// -------------------------------------------------------------------------

	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = [];
		}

		$clean = [];

		$clean['enabled']             = ! empty( $input['enabled'] );
		$clean['enable_llms_index']   = ! empty( $input['enable_llms_index'] );
		$clean['enable_llms_full']    = ! empty( $input['enable_llms_full'] );
		$clean['enable_format_param'] = ! empty( $input['enable_format_param'] );
		$clean['full_post_limit']     = max( 1, min( 1000, (int) ( $input['full_post_limit'] ?? 200 ) ) );

		$valid_types         = array_keys( get_post_types( [ 'public' => true ] ) );
		$submitted_types     = $input['post_types'] ?? [];
		$clean['post_types'] = array_values(
			array_intersect( array_map( 'sanitize_key', (array) $submitted_types ), $valid_types )
		);

		$checkbox_ids          = array_map( 'absint', (array) ( $input['excluded_ids'] ?? [] ) );
		$extra_ids             = array_map( 'absint', explode( ',', $input['excluded_ids_extra'] ?? '' ) );
		$clean['excluded_ids'] = array_values( array_unique( array_filter( array_merge( $checkbox_ids, $extra_ids ) ) ) );

		$clean['excerpt_length'] = max( 5, min( 100, (int) ( $input['excerpt_length'] ?? 20 ) ) );

		if ( defined( 'POLYLANG_VERSION' ) && function_exists( 'pll_languages_list' ) ) {
			$valid_langs                 = pll_languages_list( [ 'fields' => 'slug' ] );
			$submitted_langs             = $input['polylang_languages'] ?? [];
			$clean['polylang_languages'] = array_values(
				array_intersect( array_map( 'sanitize_key', (array) $submitted_langs ), $valid_langs )
			);
		}

		$clean['ai_instructions'] = wp_strip_all_tags( $input['ai_instructions'] ?? '' );
		$clean['cache_ttl_hours'] = max( 1, min( 168, (int) ( $input['cache_ttl_hours'] ?? 12 ) ) );

		\AJR\MarkdownForAI\Cache::flush_all();

		return $clean;
	}
}
