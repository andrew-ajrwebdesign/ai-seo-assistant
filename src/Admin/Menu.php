<?php
/**
 * Menu — AI SEO Assistant → Report · SEO scan · Search Console · Changes · Settings (mockup A1/A2).
 *
 * The menu opens on the Report (Weekly | Monthly), which every Administrator sees. The four tool screens
 * follow in working order and need Access::TOOLS_CAP, so a client Administrator sees Report only, and
 * editors and other roles see no menu at all.
 *
 * Each screen's CSS and JS load on that screen alone (by its hook suffix), never elsewhere in wp-admin.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Report\Access;
use AJR\SEOAssistant\Report\Report_Page;
use AJR\SEOAssistant\Report\Snapshot_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu and routes each screen.
 */
class Menu {

	/**
	 * Report storage.
	 *
	 * @var Snapshot_Store
	 */
	protected Snapshot_Store $store;

	/**
	 * Screen hook suffixes => 'report' | 'tool'.
	 *
	 * @var array<string,string>
	 */
	protected array $hooks = [];

	/**
	 * The report screen.
	 *
	 * @var Report_Page
	 */
	protected Report_Page $report;

	/**
	 * Constructor.
	 *
	 * @param Snapshot_Store $store Report storage.
	 */
	public function __construct( Snapshot_Store $store ) {
		$this->store  = $store;
		$this->report = new Report_Page( $store );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		$this->report->register();
		( new Settings_Page() )->register();
		add_action( 'admin_menu', [ $this, 'add' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'plugin_action_links_' . AI_SEO_ASSISTANT_BASENAME, [ $this, 'action_links' ] );
	}

	/**
	 * Add the menu.
	 */
	public function add(): void {
		$parent = Report_Page::SLUG;
		$this->hooks[ (string) add_menu_page( __( 'AI SEO Assistant', 'ai-seo-assistant' ), __( 'AI SEO Assistant', 'ai-seo-assistant' ), 'manage_options', $parent, [ $this->report, 'render' ], 'dashicons-chart-line', 58 ) ] = 'report';
		$this->hooks[ (string) add_submenu_page( $parent, __( 'Report', 'ai-seo-assistant' ), __( 'Report', 'ai-seo-assistant' ), 'manage_options', $parent, [ $this->report, 'render' ] ) ]                                     = 'report';

		$screens = [
			Scan_Page::SLUG           => [ __( 'SEO scan', 'ai-seo-assistant' ), new Scan_Page() ],
			Search_Console_Page::SLUG => [ __( 'Search Console', 'ai-seo-assistant' ), new Search_Console_Page() ],
			Changes_Page::SLUG        => [ __( 'Changes', 'ai-seo-assistant' ), new Changes_Page() ],
			Settings_Page::SLUG       => [ __( 'Settings', 'ai-seo-assistant' ), new Settings_Page() ],
		];
		foreach ( $screens as $slug => [ $title, $screen ] ) {
			$hook = add_submenu_page( $parent, $title, $title, Access::TOOLS_CAP, $slug, [ $screen, 'render' ] );
			if ( $hook ) {
				$this->hooks[ (string) $hook ] = 'tool';
			}
		}
	}

	/**
	 * Load the screen's assets on that screen only.
	 *
	 * @param string $hook Current screen's hook suffix.
	 */
	public function enqueue( $hook ): void {
		$kind = $this->hooks[ (string) $hook ] ?? '';
		if ( 'report' === $kind ) {
			$this->report->enqueue_assets();
		} elseif ( 'tool' === $kind ) {
			Ui::enqueue();
		}
	}

	/**
	 * "Report" and (for the agency) "Settings" on the Plugins screen row.
	 *
	 * @param array<int|string,string> $links Links.
	 * @return array<int|string,string>
	 */
	public function action_links( $links ): array {
		$links = (array) $links;
		if ( current_user_can( Access::TOOLS_CAP ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Settings_Page::SLUG ) ) . '">' . esc_html__( 'Settings', 'ai-seo-assistant' ) . '</a>' );
		}
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Report_Page::SLUG ) ) . '">' . esc_html__( 'Report', 'ai-seo-assistant' ) . '</a>' );

		return $links;
	}
}
