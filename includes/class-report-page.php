<?php
/**
 * Client-facing SEO metadata report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Report_Page {

	private $tsf_adapter;

	public function __construct( $tsf_adapter ) {
		$this->tsf_adapter = $tsf_adapter;
	}

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_report_page' ] );
	}

	public function add_report_page() {
		add_submenu_page(
			'ai-seo-assistant',
			'Client Report',
			'Client Report',
			'manage_options',
			'ai-seo-assistant-report',
			[ $this, 'render_report_page' ]
		);
	}

	public function render_report_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! AI_SEO_Assistant_SEO_Adapter_Resolver::any_seo_plugin_active() ) {
			echo '<div class="wrap"><h1>Client Report</h1><div class="notice notice-warning inline" style="margin:12px 0"><p><strong>No supported SEO plugin detected.</strong> Please activate The SEO Framework, Yoast SEO, or Rank Math to use AI SEO Assistant.</p></div></div>';
			return;
		}

		$selected_post_type = isset( $_GET['post_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['post_type_filter'] ) ) : '';
		$selected_status    = isset( $_GET['metadata_status'] ) ? sanitize_key( wp_unslash( $_GET['metadata_status'] ) ) : '';

		$post_types = get_option( 'ai_seo_assistant_post_types', [ 'post', 'page' ] );

		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		if ( ! empty( $selected_post_type ) && in_array( $selected_post_type, $post_types, true ) ) {
			$query_post_types = [ $selected_post_type ];
		} else {
			$query_post_types = $post_types;
		}

		$items                = $this->get_report_items( $query_post_types, $selected_status );
		$summary              = $this->get_report_summary( $items );
		$available_post_types = get_post_types( [ 'public' => true ], 'objects' );
		?>
		<div class="wrap ai-seo-assistant-report-wrap">
			<div class="ai-seo-assistant-report-actions no-print">
				<button type="button" class="button button-primary" onclick="window.print();">
					Print / Save as PDF
				</button>
			</div>

			<div class="ai-seo-assistant-report-header">
				<h1>SEO Metadata Report</h1>

				<p class="ai-seo-assistant-report-meta">
					<strong>Site:</strong> <?php echo esc_html( get_bloginfo( 'name' ) ); ?><br>
					<strong>Report date:</strong> <?php echo esc_html( wp_date( 'd/m/y H:i' ) ); ?>
				</p>

				<p class="ai-seo-assistant-report-intro">
					This report reviews key SEO metadata and indexing settings for selected pages. “Indexable” means the page is configured to allow search engines to index it. “Noindex” means the page is set not to appear in search results.
				</p>
			</div>

			<form method="get" class="ai-seo-assistant-report-filters no-print">
				<input type="hidden" name="page" value="ai-seo-assistant-report">

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

				<?php submit_button( 'Apply Filters', 'secondary', '', false ); ?>
			</form>

			<?php $this->render_summary_cards( $summary ); ?>

			<table class="widefat fixed striped ai-seo-assistant-report-table">
				<thead>
					<tr>
						<th>Page</th>
						<th>URL</th>
						<th>Indexing</th>
						<th>SEO Title</th>
						<th>Meta Description</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $items ) ) : ?>
						<?php foreach ( $items as $item ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<div class="ai-seo-assistant-report-small">
										<?php echo esc_html( $item['post_type'] ); ?>
									</div>
								</td>

								<td>
									<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $item['url'] ); ?>
									</a>
								</td>

								<td>
									<?php echo wp_kses_post( $this->render_indexing_badge( $item['indexing_status'] ) ); ?>
								</td>

								<td>
									<?php echo wp_kses_post( $this->render_status_badge( $item['title_status'] ) ); ?>

									<div class="ai-seo-assistant-report-text">
										<?php echo esc_html( $item['seo_title'] ? $item['seo_title'] : 'No custom SEO title set.' ); ?>
									</div>
								</td>

								<td>
									<?php echo wp_kses_post( $this->render_status_badge( $item['description_status'] ) ); ?>

									<div class="ai-seo-assistant-report-text">
										<?php echo esc_html( $item['seo_description'] ? $item['seo_description'] : 'No custom meta description set.' ); ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="5">
								No report items found for the selected filters.
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="ai-seo-assistant-report-footer">
				<p>
					Generated from WordPress SEO metadata and indexing settings.
				</p>
			</div>
		</div>
		<?php
	}

	private function get_report_items( $post_types, $selected_status = '' ) {
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

			$items[] = [
				'post_id'            => $post_id,
				'title'              => get_the_title( $post_id ) ? get_the_title( $post_id ) : '(no title)',
				'url'                => get_permalink( $post_id ),
				'post_type'          => $post_type_object ? $post_type_object->labels->singular_name : get_post_type( $post_id ),
				'indexing_status'    => $this->tsf_adapter->get_indexing_status( $post_id ),
				'seo_title'          => $seo_title,
				'seo_description'    => $seo_description,
				'title_status'       => $title_status,
				'description_status' => $description_status,
			];
		}

		wp_reset_postdata();

		return $items;
	}

	private function get_report_summary( $items ) {
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
		}

		return $summary;
	}

	private function render_summary_cards( $summary ) {
		?>
		<div class="ai-seo-assistant-report-summary">
			<?php
			$this->render_summary_card( 'Pages reviewed', $summary['total_reviewed'], 'neutral' );
			$this->render_summary_card( 'Indexable', $summary['indexable'], 'good' );
			$this->render_summary_card( 'Noindex', $summary['noindex'], $summary['noindex'] > 0 ? 'bad' : 'good' );
			$this->render_summary_card( 'Not public', $summary['not_public'], 'neutral' );
			$this->render_summary_card( 'Good titles', $summary['good_titles'], 'good' );
			$this->render_summary_card( 'Good descriptions', $summary['good_descriptions'], 'good' );
			$this->render_summary_card( 'Missing titles', $summary['missing_titles'], $summary['missing_titles'] > 0 ? 'bad' : 'good' );
			$this->render_summary_card( 'Missing descriptions', $summary['missing_descriptions'], $summary['missing_descriptions'] > 0 ? 'bad' : 'good' );
			$this->render_summary_card( 'Needs review', $summary['needs_review'], $summary['needs_review'] > 0 ? 'warning' : 'good' );
			?>
		</div>
		<?php
	}

	private function render_summary_card( $label, $value, $tone = 'neutral' ) {
		?>
		<div class="ai-seo-assistant-report-card is-<?php echo esc_attr( $tone ); ?>">
			<div class="ai-seo-assistant-report-card-value">
				<?php echo esc_html( number_format_i18n( absint( $value ) ) ); ?>
			</div>
			<div class="ai-seo-assistant-report-card-label">
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
}