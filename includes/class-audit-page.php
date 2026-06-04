<?php
/**
 * Metadata audit page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Audit_Page {

	private $tsf_adapter;
	private $logger;
	private $content_extractor;
	private $local_seo_context;
	private $gsc_client;
	private $screen_hook = '';

	public function __construct( $tsf_adapter, $logger, $content_extractor, $local_seo_context, $gsc_client = null ) {
		$this->tsf_adapter       = $tsf_adapter;
		$this->logger            = $logger;
		$this->content_extractor = $content_extractor;
		$this->local_seo_context = $local_seo_context;
		$this->gsc_client        = $gsc_client;
	}

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_audit_page' ] );
	}

	public function add_audit_page() {
		$this->screen_hook = add_submenu_page(
			'ai-seo-assistant',
			'Metadata Audit',
			'Metadata Audit',
			'manage_options',
			'ai-seo-assistant-audit',
			[ $this, 'render_audit_page' ]
		);
	}

	public function render_audit_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_types = get_option( 'ai_seo_assistant_post_types', [ 'post', 'page' ] );

		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		$selected_post_type = isset( $_GET['post_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['post_type_filter'] ) ) : '';
		$selected_status    = isset( $_GET['metadata_status'] ) ? sanitize_key( wp_unslash( $_GET['metadata_status'] ) ) : '';
		$current_page       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page           = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 20;

		if ( ! in_array( $per_page, [ 10, 20, 50, 100 ], true ) ) {
			$per_page = 20;
		}

		if ( ! empty( $selected_post_type ) && in_array( $selected_post_type, $post_types, true ) ) {
			$query_post_types = [ $selected_post_type ];
		} else {
			$query_post_types = $post_types;
		}

		$available_post_types = get_post_types( [ 'public' => true ], 'objects' );

		$all_items     = $this->get_audit_items( $query_post_types, $selected_status );
		$summary       = $this->get_audit_summary( $all_items );
		$total_items   = count( $all_items );
		$total_pages   = max( 1, (int) ceil( $total_items / $per_page ) );
		$current_page  = min( $current_page, $total_pages );
		$offset        = ( $current_page - 1 ) * $per_page;
		$display_items = array_slice( $all_items, $offset, $per_page );
		?>
		<div class="wrap">
			<h1>Metadata Audit</h1>

			<p>
				Review SEO titles, meta descriptions, indexing status, Local SEO/SEO Focus content match, and cached Google Search Console data.
			</p>

			<form method="get" class="ai-seo-assistant-audit-filters">
				<input type="hidden" name="page" value="ai-seo-assistant-audit">

				<label for="post_type_filter">Post type</label>
				<select id="post_type_filter" name="post_type_filter">
					<option value="">All enabled post types</option>

					<?php foreach ( $post_types as $post_type_name ) : ?>
						<?php
						if ( empty( $available_post_types[ $post_type_name ] ) ) {
							continue;
						}

						$post_type_object = $available_post_types[ $post_type_name ];
						?>
						<option value="<?php echo esc_attr( $post_type_name ); ?>" <?php selected( $selected_post_type, $post_type_name ); ?>>
							<?php echo esc_html( $post_type_object->labels->singular_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="metadata_status">Status</label>
				<select id="metadata_status" name="metadata_status">
					<option value="">All statuses</option>
					<option value="missing_title" <?php selected( $selected_status, 'missing_title' ); ?>>Missing SEO title</option>
					<option value="missing_description" <?php selected( $selected_status, 'missing_description' ); ?>>Missing meta description</option>
					<option value="missing_any" <?php selected( $selected_status, 'missing_any' ); ?>>Missing either field</option>
					<option value="needs_review" <?php selected( $selected_status, 'needs_review' ); ?>>Needs review</option>
				</select>

				<label for="per_page">Rows</label>
				<select id="per_page" name="per_page">
					<option value="10" <?php selected( $per_page, 10 ); ?>>10</option>
					<option value="20" <?php selected( $per_page, 20 ); ?>>20</option>
					<option value="50" <?php selected( $per_page, 50 ); ?>>50</option>
					<option value="100" <?php selected( $per_page, 100 ); ?>>100</option>
				</select>

				<?php submit_button( 'Apply Filters', 'secondary', '', false ); ?>
			</form>

			<?php $this->render_summary_cards( $summary ); ?>

			<div class="tablenav top">
				<div class="tablenav-pages">
					<?php echo wp_kses_post( $this->render_pagination( $current_page, $total_pages, $per_page, $selected_post_type, $selected_status ) ); ?>
				</div>
				<br class="clear">
			</div>

			<table class="widefat fixed striped ai-seo-assistant-audit-table">
				<thead>
					<tr>
						<th class="column-primary">Title</th>
						<th>Type</th>
						<th>Indexing</th>
						<th>Content Match</th>
						<th>Search Console</th>
						<th>SEO Title</th>
						<th>Meta Description</th>
						<th>Last Generated</th>
						<th>Actions</th>
					</tr>
				</thead>

				<tbody>
					<?php if ( ! empty( $display_items ) ) : ?>
						<?php foreach ( $display_items as $item ) : ?>
							<tr data-post-id="<?php echo esc_attr( $item['post_id'] ); ?>">
								<td class="column-primary">
									<strong><?php echo esc_html( $item['title'] ); ?></strong>

									<div class="row-actions">
										<span class="edit">
											<a href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>">Edit</a>
										</span>
										|
										<span class="view">
											<a href="<?php echo esc_url( get_permalink( $item['post_id'] ) ); ?>" target="_blank" rel="noopener noreferrer">View</a>
										</span>
									</div>

									<button type="button" class="toggle-row">
										<span class="screen-reader-text">Show more details</span>
									</button>
								</td>

								<td data-colname="Type">
									<?php echo esc_html( $item['post_type'] ); ?>
								</td>

								<td data-colname="Indexing">
									<?php echo wp_kses_post( $this->render_indexing_badge( $item['indexing_status'] ) ); ?>
								</td>

								<td data-colname="Content Match">
									<?php echo wp_kses_post( $this->render_content_match_badge( $item['content_match']['status'] ) ); ?>

									<?php if ( ! empty( $item['content_match']['missing'] ) ) : ?>
										<div class="ai-seo-assistant-content-match-missing">
											<strong>Missing:</strong>
											<?php echo esc_html( implode( ', ', $item['content_match']['missing'] ) ); ?>
										</div>
									<?php endif; ?>
								</td>

								<td class="ai-seo-audit-gsc-cell" data-colname="Search Console">
									<?php echo wp_kses_post( $this->render_gsc_summary( $item['gsc_data'] ) ); ?>
								</td>

								<td class="ai-seo-audit-title-cell" data-colname="SEO Title">
									<?php echo wp_kses_post( $this->render_status_badge( $item['title_status'] ) ); ?>

									<?php if ( ! empty( $item['seo_title'] ) ) : ?>
										<div class="ai-seo-assistant-audit-meta-preview">
											<?php echo esc_html( $item['seo_title'] ); ?>
										</div>
									<?php else : ?>
										<div class="ai-seo-assistant-audit-empty">
											No custom SEO title set.
										</div>
									<?php endif; ?>
								</td>

								<td class="ai-seo-audit-description-cell" data-colname="Meta Description">
									<?php echo wp_kses_post( $this->render_status_badge( $item['description_status'] ) ); ?>

									<?php if ( ! empty( $item['seo_description'] ) ) : ?>
										<div class="ai-seo-assistant-audit-meta-preview">
											<?php echo esc_html( $item['seo_description'] ); ?>
										</div>
									<?php else : ?>
										<div class="ai-seo-assistant-audit-empty">
											No custom meta description set.
										</div>
									<?php endif; ?>
								</td>

								<td class="ai-seo-audit-last-generated-cell" data-colname="Last Generated">
									<?php echo wp_kses_post( $item['last_generated'] ); ?>
								</td>

								<td class="ai-seo-audit-actions-cell" data-colname="Actions">
									<a
										class="button"
										href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>"
									>
										Open Editor
									</a>

									<button
										type="button"
										class="button button-primary ai-seo-audit-generate-save"
										data-post-id="<?php echo esc_attr( $item['post_id'] ); ?>"
									>
										Generate &amp; Save
									</button>

									<div class="ai-seo-audit-row-status"></div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="9">
								No audit items found for the selected filters.
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php echo wp_kses_post( $this->render_pagination( $current_page, $total_pages, $per_page, $selected_post_type, $selected_status ) ); ?>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	private function get_audit_items( $post_types, $selected_status = '' ) {
		$items = [];

		$query = new WP_Query(
			[
				'post_type'      => $post_types,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			]
		);

		if ( ! $query->have_posts() ) {
			return $items;
		}

		while ( $query->have_posts() ) {
			$query->the_post();

			$post_id = get_the_ID();

			$seo_title       = $this->tsf_adapter->get_title( $post_id );
			$seo_description = $this->tsf_adapter->get_description( $post_id );

			$title_status       = AI_SEO_Assistant_Utils::get_title_status( $seo_title );
			$description_status = AI_SEO_Assistant_Utils::get_description_status( $seo_description );

			if ( ! $this->matches_status_filter( $selected_status, $seo_title, $seo_description, $title_status, $description_status ) ) {
				continue;
			}

			$post_type_object = get_post_type_object( get_post_type( $post_id ) );
			$latest_log       = $this->logger->get_latest_log( $post_id );

			$items[] = [
				'post_id'            => $post_id,
				'title'              => get_the_title( $post_id ) ? get_the_title( $post_id ) : '(no title)',
				'post_type'          => $post_type_object ? $post_type_object->labels->singular_name : get_post_type( $post_id ),
				'indexing_status'    => $this->tsf_adapter->get_indexing_status( $post_id ),
				'content_match'      => $this->get_content_match_status( $post_id ),
				'gsc_data'           => $this->get_gsc_page_data( $post_id ),
				'seo_title'          => $seo_title,
				'seo_description'    => $seo_description,
				'title_status'       => $title_status,
				'description_status' => $description_status,
				'last_generated'     => $this->format_last_generated( $latest_log ),
			];
		}

		wp_reset_postdata();

		return $items;
	}

	private function get_audit_summary( $items ) {
		$summary = [
			'total_reviewed'       => 0,
			'indexable'            => 0,
			'noindex'              => 0,
			'not_public'           => 0,
			'good_titles'          => 0,
			'good_descriptions'    => 0,
			'missing_titles'       => 0,
			'missing_descriptions' => 0,
			'needs_review'         => 0,
			'content_good'         => 0,
			'content_partial'      => 0,
			'content_missing'      => 0,
			'gsc_target_first'     => 0,
			'gsc_doing_well'       => 0,
		];

		foreach ( $items as $item ) {
			$summary['total_reviewed']++;

			if ( 'Indexable' === $item['indexing_status'] ) {
				$summary['indexable']++;
			}

			if ( 'Noindex' === $item['indexing_status'] ) {
				$summary['noindex']++;
			}

			if ( 'Not public' === $item['indexing_status'] ) {
				$summary['not_public']++;
			}

			if ( 'Looks good' === $item['title_status'] ) {
				$summary['good_titles']++;
			}

			if ( 'Looks good' === $item['description_status'] ) {
				$summary['good_descriptions']++;
			}

			if ( empty( trim( (string) $item['seo_title'] ) ) ) {
				$summary['missing_titles']++;
			}

			if ( empty( trim( (string) $item['seo_description'] ) ) ) {
				$summary['missing_descriptions']++;
			}

			if ( 'Looks good' !== $item['title_status'] || 'Looks good' !== $item['description_status'] ) {
				$summary['needs_review']++;
			}

			if ( ! empty( $item['content_match']['status'] ) ) {
				if ( 'Good' === $item['content_match']['status'] ) {
					$summary['content_good']++;
				}

				if ( 'Partial' === $item['content_match']['status'] ) {
					$summary['content_partial']++;
				}

				if ( 'Missing' === $item['content_match']['status'] ) {
					$summary['content_missing']++;
				}
			}

			if ( ! empty( $item['gsc_data'] ) && is_array( $item['gsc_data'] ) ) {
				$clicks      = isset( $item['gsc_data']['clicks'] ) ? (float) $item['gsc_data']['clicks'] : 0;
				$impressions = isset( $item['gsc_data']['impressions'] ) ? (float) $item['gsc_data']['impressions'] : 0;
				$ctr         = isset( $item['gsc_data']['ctr'] ) ? (float) $item['gsc_data']['ctr'] : 0;
				$position    = isset( $item['gsc_data']['position'] ) ? (float) $item['gsc_data']['position'] : 0;
				$opportunity = $this->get_gsc_opportunity_label( $clicks, $impressions, $ctr, $position );

				if ( 'Target first' === $opportunity['label'] || 'Page 2 opportunity' === $opportunity['label'] || 'Low CTR' === $opportunity['label'] ) {
					$summary['gsc_target_first']++;
				}

				if ( 'Doing well' === $opportunity['label'] ) {
					$summary['gsc_doing_well']++;
				}
			}
		}

		return $summary;
	}

	private function render_summary_cards( $summary ) {
		?>
		<div class="ai-seo-assistant-summary-grid">
			<?php
			$this->render_summary_card( 'Pages reviewed', $summary['total_reviewed'], 'neutral' );
			$this->render_summary_card( 'Indexable', $summary['indexable'], 'good' );
			$this->render_summary_card( 'Noindex', $summary['noindex'], $summary['noindex'] > 0 ? 'bad' : 'good' );
			$this->render_summary_card( 'Missing titles', $summary['missing_titles'], $summary['missing_titles'] > 0 ? 'bad' : 'good' );
			$this->render_summary_card( 'Missing descriptions', $summary['missing_descriptions'], $summary['missing_descriptions'] > 0 ? 'bad' : 'good' );
			$this->render_summary_card( 'Needs review', $summary['needs_review'], $summary['needs_review'] > 0 ? 'warning' : 'good' );
			$this->render_summary_card( 'Content good', $summary['content_good'], 'good' );
			$this->render_summary_card( 'Content partial', $summary['content_partial'], $summary['content_partial'] > 0 ? 'warning' : 'good' );
			$this->render_summary_card( 'GSC opportunities', $summary['gsc_target_first'], $summary['gsc_target_first'] > 0 ? 'warning' : 'good' );
			$this->render_summary_card( 'Doing well', $summary['gsc_doing_well'], 'good' );
			?>
		</div>
		<?php
	}

	private function render_summary_card( $label, $value, $tone = 'neutral' ) {
		?>
		<div class="ai-seo-assistant-summary-card is-<?php echo esc_attr( $tone ); ?>">
			<div class="ai-seo-assistant-summary-value">
				<?php echo esc_html( number_format_i18n( absint( $value ) ) ); ?>
			</div>
			<div class="ai-seo-assistant-summary-label">
				<?php echo esc_html( $label ); ?>
			</div>
		</div>
		<?php
	}

	private function matches_status_filter( $selected_status, $seo_title, $seo_description, $title_status, $description_status ) {
		if ( empty( $selected_status ) ) {
			return true;
		}

		$missing_title       = empty( trim( (string) $seo_title ) );
		$missing_description = empty( trim( (string) $seo_description ) );

		if ( 'missing_title' === $selected_status ) {
			return $missing_title;
		}

		if ( 'missing_description' === $selected_status ) {
			return $missing_description;
		}

		if ( 'missing_any' === $selected_status ) {
			return $missing_title || $missing_description;
		}

		if ( 'needs_review' === $selected_status ) {
			return 'Looks good' !== $title_status || 'Looks good' !== $description_status;
		}

		return true;
	}

	private function render_status_badge( $status ) {
		$class = 'ai-seo-assistant-badge';

		if ( 'Looks good' === $status ) {
			$class .= ' is-good';
		} elseif ( 'Missing' === $status ) {
			$class .= ' is-missing';
		} else {
			$class .= ' is-warning';
		}

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $class ),
			esc_html( $status )
		);
	}

	private function render_indexing_badge( $status ) {
		$class = 'ai-seo-assistant-indexing-badge';

		if ( 'Indexable' === $status ) {
			$class .= ' is-indexable';
		} elseif ( 'Noindex' === $status ) {
			$class .= ' is-noindex';
		} else {
			$class .= ' is-not-public';
		}

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $class ),
			esc_html( $status )
		);
	}

	private function get_content_match_status( $post_id ) {
		$page_context = $this->local_seo_context->get_page_context( $post_id );
		$terms        = $this->get_local_focus_terms( $page_context );

		if ( empty( $terms ) ) {
			return [
				'status'  => 'Not set',
				'matched' => [],
				'missing' => [],
			];
		}

		$content = $this->content_extractor->get_content( $post_id );

		if ( empty( $content ) ) {
			return [
				'status'  => 'Missing',
				'matched' => [],
				'missing' => $terms,
			];
		}

		$content = $this->normalize_match_text( $content );

		$matched_terms = [];
		$missing_terms = [];

		foreach ( $terms as $term ) {
			if ( $this->content_contains_term( $content, $term ) ) {
				$matched_terms[] = $term;
			} else {
				$missing_terms[] = $term;
			}
		}

		if ( empty( $missing_terms ) ) {
			$status = 'Good';
		} elseif ( ! empty( $matched_terms ) ) {
			$status = 'Partial';
		} else {
			$status = 'Missing';
		}

		return [
			'status'  => $status,
			'matched' => $matched_terms,
			'missing' => $missing_terms,
		];
	}

	private function get_local_focus_terms( $page_context ) {
		$terms      = [];
		$focus_mode = isset( $page_context['focus_mode'] ) ? $page_context['focus_mode'] : 'general';

		$fields = [
			'service_focus',
		];

		if ( 'local' === $focus_mode ) {
			$fields[] = 'primary_location';
			$fields[] = 'secondary_locations';
		}

		foreach ( $fields as $field ) {
			if ( empty( $page_context[ $field ] ) ) {
				continue;
			}

			$split_terms = preg_split( '/,|\n|\r/', $page_context[ $field ] );

			if ( empty( $split_terms ) || ! is_array( $split_terms ) ) {
				continue;
			}

			foreach ( $split_terms as $term ) {
				$term = trim( $term );

				$term = preg_replace( '/^(and|or|near|in|around|serving)\s+/i', '', $term );
				$term = trim( $term );

				if ( '' === $term ) {
					continue;
				}

				$terms[] = $term;
			}
		}

		$terms = array_unique( $terms );

		return array_values( $terms );
	}

	private function content_contains_term( $content, $term ) {
		$content = $this->normalize_match_text( $content );
		$term    = $this->normalize_match_text( $term );

		if ( '' === $term ) {
			return false;
		}

		if ( false !== strpos( $content, $term ) ) {
			return true;
		}

		$content_concepts = $this->extract_content_match_concepts( $content );
		$term_concepts    = $this->extract_content_match_concepts( $term );

		if ( empty( $term_concepts ) ) {
			return false;
		}

		foreach ( $term_concepts as $concept ) {
			if ( ! in_array( $concept, $content_concepts, true ) ) {
				return false;
			}
		}

		return true;
	}

	private function extract_content_match_concepts( $text ) {
		$text = $this->normalize_match_text( $text );

		$concepts = [];

		if ( false !== strpos( $text, 'wordpress' ) ) {
			$concepts[] = 'wordpress';
		}

		if (
			false !== strpos( $text, 'customization' ) ||
			false !== strpos( $text, 'customisation' ) ||
			false !== strpos( $text, 'customize' ) ||
			false !== strpos( $text, 'customise' ) ||
			false !== strpos( $text, 'anpassung' ) ||
			false !== strpos( $text, 'anpassungen' ) ||
			false !== strpos( $text, 'anpassen' ) ||
			false !== strpos( $text, 'angepasst' )
		) {
			$concepts[] = 'customization';
		}

		if (
			false !== strpos( $text, 'web development' ) ||
			false !== strpos( $text, 'website development' ) ||
			false !== strpos( $text, 'wordpress development' ) ||
			false !== strpos( $text, 'development' ) ||
			false !== strpos( $text, 'developer' ) ||
			false !== strpos( $text, 'entwicklung' ) ||
			false !== strpos( $text, 'entwickler' )
		) {
			$concepts[] = 'development';
		}

		if (
			false !== strpos( $text, 'seo' ) ||
			false !== strpos( $text, 'suchmaschinenoptimierung' )
		) {
			$concepts[] = 'seo';
		}

		if (
			false !== strpos( $text, 'performance' ) ||
			false !== strpos( $text, 'optimierung' ) ||
			false !== strpos( $text, 'geschwindigkeit' ) ||
			false !== strpos( $text, 'core web vitals' )
		) {
			$concepts[] = 'performance';
		}

		if (
			false !== strpos( $text, 'support' ) ||
			false !== strpos( $text, 'technical support' ) ||
			false !== strpos( $text, 'technical wordpress support' ) ||
			false !== strpos( $text, 'betreuung' ) ||
			false !== strpos( $text, 'unterstützung' )
		) {
			$concepts[] = 'support';
		}

		if ( false !== strpos( $text, 'freiburg' ) ) {
			$concepts[] = 'freiburg';
		}

		if ( false !== strpos( $text, 'basel' ) ) {
			$concepts[] = 'basel';
		}

		if ( false !== strpos( $text, 'mulhouse' ) ) {
			$concepts[] = 'mulhouse';
		}

		return array_values( array_unique( $concepts ) );
	}

	private function normalize_match_text( $text ) {
		$text = strtolower( wp_strip_all_tags( (string) $text ) );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}

	private function render_content_match_badge( $status ) {
		$class = 'ai-seo-assistant-content-match-badge';

		if ( 'Good' === $status ) {
			$class .= ' is-good';
		} elseif ( 'Partial' === $status ) {
			$class .= ' is-partial';
		} elseif ( 'Missing' === $status ) {
			$class .= ' is-missing';
		} else {
			$class .= ' is-not-set';
		}

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $class ),
			esc_html( $status )
		);
	}

	private function get_gsc_page_data( $post_id ) {
		if ( empty( $this->gsc_client ) || ! method_exists( $this->gsc_client, 'get_page_data' ) ) {
			return [];
		}

		$url = get_permalink( $post_id );

		if ( empty( $url ) ) {
			return [];
		}

		$urls_to_try = array_unique(
			[
				$url,
				trailingslashit( $url ),
				untrailingslashit( $url ),
			]
		);

		foreach ( $urls_to_try as $candidate_url ) {
			$data = $this->gsc_client->get_page_data( $candidate_url );

			if ( ! empty( $data ) ) {
				return $data;
			}
		}

		return [];
	}

	private function render_gsc_summary( $data ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return '<span class="ai-seo-assistant-gsc-empty">No search data</span>';
		}

		$clicks      = isset( $data['clicks'] ) ? (float) $data['clicks'] : 0;
		$impressions = isset( $data['impressions'] ) ? (float) $data['impressions'] : 0;
		$ctr         = isset( $data['ctr'] ) ? (float) $data['ctr'] : 0;
		$position    = isset( $data['position'] ) ? (float) $data['position'] : 0;
		$queries     = isset( $data['queries'] ) && is_array( $data['queries'] ) ? $data['queries'] : [];

		$top_query = '';

		if ( ! empty( $queries[0]['query'] ) ) {
			$top_query = $queries[0]['query'];
		}

		$opportunity = $this->get_gsc_opportunity_label( $clicks, $impressions, $ctr, $position );

		ob_start();
		?>
		<div class="ai-seo-assistant-gsc-summary">
			<?php echo wp_kses_post( $this->render_gsc_opportunity_badge( $opportunity ) ); ?>

			<div class="ai-seo-assistant-gsc-metrics">
				<span><strong><?php echo esc_html( number_format_i18n( $clicks ) ); ?></strong> clicks</span>
				<span><strong><?php echo esc_html( number_format_i18n( $impressions ) ); ?></strong> impressions</span>
				<span><strong><?php echo esc_html( number_format_i18n( $ctr * 100, 1 ) ); ?>%</strong> CTR</span>

				<?php if ( $position > 0 ) : ?>
					<span><strong><?php echo esc_html( number_format_i18n( $position, 1 ) ); ?></strong> avg pos.</span>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $top_query ) ) : ?>
				<div class="ai-seo-assistant-gsc-query">
					<strong>Top query:</strong>
					<?php echo esc_html( $top_query ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function get_gsc_opportunity_label( $clicks, $impressions, $ctr, $position ) {
		if ( $impressions <= 0 ) {
			return [
				'label' => 'No data',
				'tone'  => 'neutral',
			];
		}

		if ( $impressions < 5 || $position > 30 ) {
			return [
				'label' => 'Low visibility',
				'tone'  => 'neutral',
			];
		}

		if ( $impressions >= 20 && $clicks <= 0 ) {
			return [
				'label' => 'Target first',
				'tone'  => 'bad',
			];
		}

		if ( $position >= 8 && $position <= 20 && $impressions >= 5 ) {
			return [
				'label' => 'Page 2 opportunity',
				'tone'  => 'warning',
			];
		}

		if ( $impressions >= 50 && $ctr < 0.02 ) {
			return [
				'label' => 'Low CTR',
				'tone'  => 'warning',
			];
		}

		if ( $clicks > 0 && $ctr >= 0.03 && $position > 0 && $position <= 10 ) {
			return [
				'label' => 'Doing well',
				'tone'  => 'good',
			];
		}

		if ( $clicks > 0 ) {
			return [
				'label' => 'Monitor',
				'tone'  => 'neutral',
			];
		}

		return [
			'label' => 'Needs review',
			'tone'  => 'warning',
		];
	}

	private function render_gsc_opportunity_badge( $opportunity ) {
		$label = isset( $opportunity['label'] ) ? $opportunity['label'] : 'No data';
		$tone  = isset( $opportunity['tone'] ) ? $opportunity['tone'] : 'neutral';

		$class = 'ai-seo-assistant-gsc-badge is-' . sanitize_html_class( $tone );

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	private function format_last_generated( $latest_log ) {
		if ( empty( $latest_log ) || ! is_array( $latest_log ) ) {
			return 'Never';
		}

		/*
		 * Do not show failed generation attempts in the audit table.
		 * The audit table should only show successful metadata generations.
		 */
		if ( ! empty( $latest_log['error'] ) ) {
			return 'Never';
		}

		$timestamp = isset( $latest_log['timestamp'] ) ? absint( $latest_log['timestamp'] ) : 0;

		if ( empty( $timestamp ) ) {
			return 'Never';
		}

		$output = esc_html( wp_date( 'd/m/y H:i', $timestamp ) );

		$source = isset( $latest_log['source'] ) ? sanitize_text_field( $latest_log['source'] ) : '';
		$model  = isset( $latest_log['model'] ) ? sanitize_text_field( $latest_log['model'] ) : '';

		if ( ! empty( $source ) || ! empty( $model ) ) {
			$output .= '<div class="ai-seo-assistant-audit-small">';

			if ( ! empty( $source ) ) {
				$output .= esc_html( $source );
			}

			if ( ! empty( $model ) ) {
				$output .= ' / ' . esc_html( $model );
			}

			$output .= '</div>';
		}

		return $output;
	}

	private function render_pagination( $current_page, $total_pages, $per_page, $selected_post_type, $selected_status ) {
		if ( $total_pages <= 1 ) {
			return '<span class="displaying-num">1 page</span>';
		}

		$base_args = [
			'page'     => 'ai-seo-assistant-audit',
			'per_page' => $per_page,
			'paged'    => '%#%',
		];

		if ( ! empty( $selected_post_type ) ) {
			$base_args['post_type_filter'] = $selected_post_type;
		}

		if ( ! empty( $selected_status ) ) {
			$base_args['metadata_status'] = $selected_status;
		}

		$base_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );

		$links = paginate_links(
			[
				'base'      => $base_url,
				'format'    => '',
				'current'   => $current_page,
				'total'     => $total_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'type'      => 'array',
			]
		);

		if ( empty( $links ) || ! is_array( $links ) ) {
			return '';
		}

		$output  = '<span class="displaying-num">';
		$output .= esc_html(
			sprintf(
				'%d page%s',
				$total_pages,
				1 === $total_pages ? '' : 's'
			)
		);
		$output .= '</span> ';
		$output .= '<span class="pagination-links">';
		$output .= implode( "\n", $links );
		$output .= '</span>';

		return $output;
	}
}