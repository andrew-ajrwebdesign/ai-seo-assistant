<?php
/**
 * Report_Page — AI SEO Assistant → Report, with Weekly | Monthly tabs (mockup A1/A2, F1–F4).
 *
 * The plugin's menu opens here, and it is all a client Administrator sees of the plugin. Weekly: the
 * pushed week (Report_View, unchanged from 4.3 apart from taps being kept out of the total). Monthly: the
 * pushed billing month (Month_View) with what the plugin changed that month and what it achieved, from
 * the change log. Report delivery (push key, import, alerts) moved to Settings in 5.0, for the agency.
 *
 * Read-only: nothing is written while this screen renders.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

use AJR\SEOAssistant\Changes\Change_Log;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * The Report screen.
 */
class Report_Page {

	/** Menu slug (unchanged from 4.3, so bookmarks keep working). */
	public const SLUG = 'ai-seo-assistant-weekly';

	/** Stylesheet handle. */
	public const STYLE = 'ai-seo-assistant-weekly-report';

	/**
	 * Storage.
	 *
	 * @var Snapshot_Store
	 */
	protected Snapshot_Store $store;

	/**
	 * Constructor — dependencies only.
	 *
	 * @param Snapshot_Store $store Storage.
	 */
	public function __construct( Snapshot_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Register hooks (the menu itself is Admin\Menu's).
	 */
	public function register(): void {
	}

	/**
	 * The report stylesheet and the small print script (Admin\Menu calls this on this screen only).
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style( self::STYLE, AI_SEO_ASSISTANT_URL . 'assets/css/weekly-report.css', [ 'dashicons' ], AI_SEO_ASSISTANT_VERSION );
		wp_enqueue_script(
			'ai-seo-assistant-report',
			AI_SEO_ASSISTANT_URL . 'assets/js/aisa-report.js',
			[],
			AI_SEO_ASSISTANT_VERSION,
			[
				'strategy'  => 'defer',
				'in_footer' => true,
			]
		);
	}

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only choice of view.
		$view    = isset( $_GET['view'] ) && 'monthly' === $_GET['view'] ? 'monthly' : 'weekly';
		$context = [
			'business' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			/**
			 * The agency named on the report.
			 *
			 * @param string $agency Default "AJR Web Design".
			 */
			'agency'   => (string) apply_filters( 'ai_seo_assistant_report_agency', 'AJR Web Design' ),
			'now'      => time(),
			'tabs'     => $this->tabs( $view ),
			// AJR Core's "Need a hand?" card (AJR Core 0.22+): it is for the client, so the Report shows it too.
			'aside'    => \AJR\SEOAssistant\Admin\Ui::support_card(),
		];

		// wp-header-end: WordPress moves other plugins' notices to this marker, not into the dark header.
		echo '<div class="wrap aisa-wrap"><hr class="wp-header-end">';
		if ( 'monthly' === $view ) {
			$this->monthly( $context );
		} else {
			$this->weekly( $context );
		}
		echo '</div>';
	}

	/**
	 * The Weekly view.
	 *
	 * @param array<string,mixed> $context Context.
	 */
	protected function weekly( array $context ): void {
		$weeks = $this->store->all();
		$keys  = array_keys( $weeks );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only choice of which stored week to show.
		$want = isset( $_GET['week'] ) ? sanitize_text_field( wp_unslash( $_GET['week'] ) ) : '';
		$key  = in_array( $want, $keys, true ) ? $want : ( $keys[0] ?? '' );
		if ( '' === $key ) {
			echo Report_View::empty_state( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise in Report_View.
			return;
		}
		$at                  = (int) array_search( $key, $keys, true );
		$context['latest']   = 0 === $at;
		$context['prev_url'] = isset( $keys[ $at + 1 ] ) ? $this->url( [ 'week' => $keys[ $at + 1 ] ] ) : '';
		$context['next_url'] = $at > 0 ? $this->url( [ 'week' => $keys[ $at - 1 ] ] ) : '';
		$context['alerted']  = Stale_Alert::alerted();
		echo Report_View::report( $weeks[ $key ], $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise in Report_View.
	}

	/**
	 * The Monthly view.
	 *
	 * @param array<string,mixed> $context Context.
	 */
	protected function monthly( array $context ): void {
		$months = $this->store->months();
		$keys   = array_keys( $months );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only choice of which stored month to show.
		$want = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$key  = in_array( $want, $keys, true ) ? $want : ( $keys[0] ?? '' );
		if ( '' === $key ) {
			echo Month_View::month_empty( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise in Month_View.
			return;
		}
		$at                  = (int) array_search( $key, $keys, true );
		$context['prev_url'] = isset( $keys[ $at + 1 ] ) ? $this->url(
			[
				'view'  => 'monthly',
				'month' => $keys[ $at + 1 ],
			]
		) : '';
		$context['next_url'] = $at > 0 ? $this->url(
			[
				'view'  => 'monthly',
				'month' => $keys[ $at - 1 ],
			]
		) : '';
		echo Month_View::month( $months[ $key ], $context, self::achievements( $months[ $key ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise in Month_View.
	}

	/**
	 * "What we did and what it achieved": the plugin's own changes in the billing month, one line per page
	 * per day, with the measured effect, in the owner's words.
	 *
	 * @param array<string,mixed> $snap Month snapshot.
	 * @return array<int,array<string,mixed>>
	 */
	public static function achievements( array $snap ): array {
		$log   = new Change_Log();
		$rows  = $log->find(
			[
				'since'          => $snap['month']['start'] . ' 00:00:00',
				'until'          => gmdate( 'Y-m-d', (int) strtotime( $snap['month']['end'] . ' +1 day UTC' ) ) . ' 00:00:00',
				'limit'          => 500,
				// Page types (most set automatically) are housekeeping, not what the client paid for.
				'exclude_fields' => [ \AJR\SEOAssistant\Scan\Auto_Types::FIELD ],
			]
		);
		$data  = new Page_Data();
		$group = [];
		foreach ( array_reverse( $rows ) as $row ) {
			if ( null !== $row['undone_at'] ) {
				continue;
			}
			$day                                     = substr( (string) $row['applied_at'], 0, 10 );
			$group[ $day . '|' . $row['post_id'] ][] = $row;
		}
		$out = [];
		foreach ( $group as $key => $changes ) {
			$day    = (string) strtok( $key, '|' );
			$fields = array_unique( array_column( $changes, 'field' ) );
			$title  = wp_strip_all_tags( (string) get_the_title( (int) $changes[0]['post_id'] ) );
			$page   = $data->get( (string) $changes[0]['path'] );
			$effect = null;
			foreach ( $changes as $c ) {
				if ( in_array( $c['field'], Change_Log::MEASURED, true ) ) {
					$effect = $log->effect( $c, $page );
					break;
				}
			}
			$out[] = [
				'date'   => $day,
				'text'   => self::what( $title, $fields ),
				'result' => self::result( $effect ),
				'detail' => self::detail( $effect ),
			];
		}

		return $out;
	}

	/**
	 * "Rewrote how your Water Heaters page appears on Google: a clearer title and description."
	 *
	 * @param string            $title  Page title.
	 * @param array<int,string> $fields Fields changed.
	 */
	protected static function what( string $title, array $fields ): string {
		$listing = array_intersect( $fields, [ 'title', 'description' ] );
		$alt     = [] !== array_intersect( [ 'alt', 'content' ], $fields ); // "content" is alt text written into the page.
		if ( [] !== $listing && $alt ) {
			/* translators: %s: page title. */
			return sprintf( __( 'Updated your %s page’s Google listing and photo descriptions.', 'ai-seo-assistant' ), $title );
		}
		if ( 2 === count( $listing ) ) {
			/* translators: %s: page title. */
			return sprintf( __( 'Rewrote how your %s page appears on Google: a clearer title and description.', 'ai-seo-assistant' ), $title );
		}
		if ( in_array( 'title', $listing, true ) ) {
			/* translators: %s: page title. */
			return sprintf( __( 'Rewrote the Google title for your %s page.', 'ai-seo-assistant' ), $title );
		}
		if ( in_array( 'description', $listing, true ) ) {
			/* translators: %s: page title. */
			return sprintf( __( 'Rewrote the Google description for your %s page.', 'ai-seo-assistant' ), $title );
		}
		if ( $alt ) {
			/* translators: %s: page title. */
			return sprintf( __( 'Described the photos on your %s page for Google and for people using screen readers.', 'ai-seo-assistant' ), $title );
		}
		if ( in_array( \AJR\SEOAssistant\Scan\Auto_Types::FIELD, $fields, true ) ) {
			/* translators: %s: page title. */
			return sprintf( __( 'Told Google what kind of page your %s page is.', 'ai-seo-assistant' ), $title );
		}
		if ( ! in_array( 'keyphrase', $fields, true ) ) {
			/* translators: %s: page title. */
			return sprintf( __( 'Improved your %s page for Google.', 'ai-seo-assistant' ), $title );
		}

		/* translators: %s: page title. */
		return sprintf( __( 'Set the main search your %s page is written for.', 'ai-seo-assistant' ), $title );
	}

	/**
	 * The chip for an effect.
	 *
	 * @param array<string,mixed>|null $effect Change_Log::effect().
	 */
	protected static function result( ?array $effect ): string {
		if ( null === $effect ) {
			return 'nothing';
		}
		if ( 'measured' === $effect['state'] ) {
			return (string) ( $effect['verdict'] ?? 'same' );
		}

		return 'early';
	}

	/**
	 * The plain-English result line.
	 *
	 * @param array<string,mixed>|null $effect Change_Log::effect().
	 */
	protected static function detail( ?array $effect ): string {
		if ( null === $effect ) {
			return __( 'Helps Google and screen readers understand the page; it does not change clicks on its own.', 'ai-seo-assistant' );
		}
		$from = isset( $effect['from'] ) ? wp_date( 'j F', (int) strtotime( $effect['from'] . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) ) : '';
		if ( 'measured' !== $effect['state'] ) {
			/* translators: %s: date. */
			return sprintf( __( 'We measure after 4 weeks of data (from %s); you will see it in a later report.', 'ai-seo-assistant' ), $from );
		}
		$before = number_format_i18n( (float) $effect['ctr_before'], 1 );
		$after  = number_format_i18n( (float) $effect['ctr_after'], 1 );
		if ( 'better' === $effect['verdict'] ) {
			/* translators: 1: clicks in every 100 now, 2: before, 3: extra visits. */
			return sprintf( __( 'More people click it now: %1$s in every 100 who see it, up from %2$s. About %3$s extra visits in the 4 weeks after.', 'ai-seo-assistant' ), $after, $before, number_format_i18n( max( 0, (int) $effect['extra_clicks'] ) ) );
		}
		if ( 'worse' === $effect['verdict'] ) {
			/* translators: 1: now, 2: before. */
			return sprintf( __( 'Fewer people click it: %1$s in every 100 who see it, down from %2$s. We are looking at it.', 'ai-seo-assistant' ), $after, $before );
		}

		/* translators: 1: now, 2: before. */
		return sprintf( __( 'About the same: %1$s in every 100 who see it click, against %2$s before.', 'ai-seo-assistant' ), $after, $before );
	}

	/**
	 * The Weekly | Monthly tabs (in the dark header).
	 *
	 * @param string $view Current view.
	 */
	protected function tabs( string $view ): string {
		$tabs = '';
		foreach ( [
			'weekly'  => __( 'Weekly', 'ai-seo-assistant' ),
			'monthly' => __( 'Monthly', 'ai-seo-assistant' ),
		] as $key => $label ) {
			$current = $key === $view;
			$tabs   .= '<a class="aisa-tab' . ( $current ? ' is-current' : '' ) . '" href="' . esc_url( $this->url( 'monthly' === $key ? [ 'view' => 'monthly' ] : [] ) ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}

		return '<nav class="aisa-tabs" aria-label="' . esc_attr__( 'Report period', 'ai-seo-assistant' ) . '">' . $tabs . '</nav>';
	}

	/**
	 * This screen's URL.
	 *
	 * @param array<string,string> $args Query arguments.
	 */
	protected function url( array $args ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}
}
