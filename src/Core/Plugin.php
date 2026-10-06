<?php
/**
 * Main plugin bootstrap.
 *
 * @package AJR\SEOAssistant
 */

namespace AJR\SEOAssistant\Core;

use AJR\SEOAssistant\Adapters\TSF_Adapter;
use AJR\SEOAssistant\Adapters\Yoast_Adapter;
use AJR\SEOAssistant\Adapters\RankMath_Adapter;
use AJR\SEOAssistant\Adapters\SEO_Adapter_Resolver;
use AJR\SEOAssistant\Content\Content_Extractor;
use AJR\SEOAssistant\Content\Local_SEO_Context;
use AJR\SEOAssistant\AI\Prompt_Builder;
use AJR\SEOAssistant\AI\Claude_Client;
use AJR\SEOAssistant\AI\Metadata_Generator;
use AJR\SEOAssistant\Admin\Admin;
use AJR\SEOAssistant\Admin\Ajax;
use AJR\SEOAssistant\Admin\Menu;
use AJR\SEOAssistant\Admin\Secret_Notices;
use AJR\SEOAssistant\Admin\Tools_Actions;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin for each request.
 */
class Plugin {

	/**
	 * The one instance (a bootstrap holder, not a service locator).
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * The instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private: use instance().
	 */
	private function __construct() {}

	/**
	 * Wire the plugin for this request.
	 *
	 * ⚖ FRONT-END WEIGHT (4.4.0, kept in 5.0). A visitor's page view builds only what acts there: the report
	 * push endpoint (REST), its daily lateness check (cron), the tools capability and the secret-option
	 * write guard. The SEO scan's three hooks (a page saved, the scan's cron events, a push received) are
	 * closures that name the scan classes only inside their bodies, so the scan code loads when one of them
	 * fires (a save, a cron run, a push), never on a page view. Everything else is built when is_admin(),
	 * which covers admin-ajax.php and admin-post.php as well as the screens.
	 */
	public function init() {
		add_action( 'init', [ $this, 'load_textdomain' ] );

		$report_store = new \AJR\SEOAssistant\Report\Snapshot_Store();
		( new \AJR\SEOAssistant\Report\Access() )->register();
		( new \AJR\SEOAssistant\Report\Push_Endpoint( $report_store ) )->register();
		( new \AJR\SEOAssistant\Report\Stale_Alert( $report_store ) )->register();

		// Every write to a secret option, from any screen, plugin, cron job or REST call, is checked and
		// sealed (4.4.0). On every request: a write that bypasses wp-admin must not bypass the guard.
		( new Secret_Guard() )->register();

		$this->register_scan_hooks();

		if ( is_admin() ) {
			$this->init_admin( $report_store );
		}
	}

	/**
	 * The SEO scan's triggers (decision 2026-10-06): after each push, per page on save (deferred to a
	 * single cron event, never inline), and its own cron events.
	 */
	protected function register_scan_hooks(): void {
		add_action(
			'transition_post_status',
			static function ( $new_status, $old_status, $post ): void {
				\AJR\SEOAssistant\Scan\Scheduler::on_transition( $new_status, $old_status, $post );
			},
			10,
			3
		);
		add_action(
			'aisa_scan_run',
			static function (): void {
				\AJR\SEOAssistant\Scan\Scheduler::run();
			}
		);
		add_action(
			'aisa_scan_post',
			static function ( $post_id ): void {
				\AJR\SEOAssistant\Scan\Scheduler::run_post( (int) $post_id );
			}
		);
		// Contract 3 (AJR Core 0.22 Business details): the latest pushed Google listing check, or null.
		add_filter(
			'ajr_core_business_profile_check',
			static function ( $value ) {
				$block = \AJR\SEOAssistant\Report\Snapshot_Store::listing_block();

				return null !== $block ? $block : $value;
			}
		);
		add_action(
			\AJR\SEOAssistant\Report\Snapshot_Store::RECEIVED_ACTION,
			static function (): void {
				\AJR\SEOAssistant\Scan\Scheduler::after_push();
			}
		);
	}

	/**
	 * Build and register everything that only acts in wp-admin (screens, editor box, AJAX, admin-post).
	 *
	 * @param \AJR\SEOAssistant\Report\Snapshot_Store $report_store Report storage.
	 */
	protected function init_admin( \AJR\SEOAssistant\Report\Snapshot_Store $report_store ) {
		$tsf      = new TSF_Adapter();
		$resolver = new SEO_Adapter_Resolver( $tsf, new Yoast_Adapter(), new RankMath_Adapter() );
		$adapter  = $resolver->get_adapter() ?? $tsf;
		$claude   = new Claude_Client();
		$logger   = new Logger();
		$context  = new Local_SEO_Context();

		$generator = new Metadata_Generator(
			$adapter,
			new Content_Extractor(),
			new Prompt_Builder(),
			$claude,
			$logger,
			$context,
			new Page_Data() // The page's pushed Search Console data (the on-site connection is gone).
		);

		// The editor box (agency only) and its AJAX actions.
		( new Admin( $adapter, $logger, $context, $resolver, $claude ) )->init();
		( new Ajax( $generator ) )->init();

		// The menu and the screens: Report (everyone with manage_options), then the agency's tools.
		( new Menu( $report_store ) )->register();
		( new Tools_Actions() )->register();

		// One-time data changes and the agency's notices.
		( new Upgrade() )->register();
		( new Secret_Notices() )->register();

		// Once AJR Core 0.22+ keeps page types: move the old AISA page roles into them (one query, then a flag).
		add_action(
			'admin_init',
			static function (): void {
				// The agency only (page types are theirs to set); a refused page keeps its old role and the
				// next agency admin load tries again.
				if ( ! current_user_can( \AJR\SEOAssistant\Report\Access::TOOLS_CAP ) || ! \AJR\SEOAssistant\Scan\Page_Role::core() || get_option( 'ai_seo_assistant_roles_migrated' ) ) {
					return;
				}
				$out = \AJR\SEOAssistant\Scan\Page_Role::migrate();
				if ( null === $out || 0 === $out['failed'] ) {
					update_option( 'ai_seo_assistant_roles_migrated', time(), false );
				}
			}
		);
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * Hooked on `init` (not earlier) so it runs after WordPress has finished setting up locales.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'ai-seo-assistant',
			false,
			dirname( AI_SEO_ASSISTANT_BASENAME ) . '/languages'
		);
	}
}
