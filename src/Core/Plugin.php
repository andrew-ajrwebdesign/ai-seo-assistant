<?php
/**
 * Main plugin bootstrap.
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
use AJR\SEOAssistant\Admin\Audit_Page;
use AJR\SEOAssistant\Admin\Report_Page;
use AJR\SEOAssistant\Admin\Markdown_Page;
use AJR\SEOAssistant\Admin\Indexing_Tools_Page;
use AJR\SEOAssistant\GSC\GSC_Client;
use AJR\SEOAssistant\GSC\GSC_Page;
use AJR\SEOAssistant\Redirects\Redirect_Store;
use AJR\SEOAssistant\Redirects\Redirect_Handler;
use AJR\SEOAssistant\Redirects\Core_Suggestions;
use AJR\SEOAssistant\Admin\Redirects_Page;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static $instance = null;

	private $tsf_adapter;
	private $yoast_adapter;
	private $rankmath_adapter;
	private $seo_adapter_resolver;
	private $seo_adapter;

	private $content_extractor;
	private $prompt_builder;
	private $ai_client;
	private $logger;
	private $local_seo_context;
	private $metadata_generator;
	private $admin;
	private $audit_page;
	private $report_page;
	private $gsc_client;
	private $gsc_page;
	private $indexing_tools_page;
	private $ajax;
	private $redirect_store;
	private $redirects_page;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'init', [ $this, 'load_textdomain' ] );

		$this->tsf_adapter      = new TSF_Adapter();
		$this->yoast_adapter    = new Yoast_Adapter();
		$this->rankmath_adapter = new RankMath_Adapter();

		$this->seo_adapter_resolver = new SEO_Adapter_Resolver(
			$this->tsf_adapter,
			$this->yoast_adapter,
			$this->rankmath_adapter
		);

		$this->seo_adapter       = $this->seo_adapter_resolver->get_adapter() ?? $this->tsf_adapter;
		$this->content_extractor = new Content_Extractor();
		$this->prompt_builder    = new Prompt_Builder();
		$this->ai_client         = new Claude_Client();
		$this->logger            = new Logger();
		$this->local_seo_context = new Local_SEO_Context();
		$this->gsc_client        = new GSC_Client();

		$this->metadata_generator = new Metadata_Generator(
			$this->seo_adapter,
			$this->content_extractor,
			$this->prompt_builder,
			$this->ai_client,
			$this->logger,
			$this->local_seo_context,
			$this->gsc_client
		);

		$this->admin = new Admin(
			$this->seo_adapter,
			$this->logger,
			$this->local_seo_context,
			$this->seo_adapter_resolver,
			$this->ai_client
		);

		$this->audit_page = new Audit_Page(
			$this->seo_adapter,
			$this->logger,
			$this->content_extractor,
			$this->local_seo_context,
			$this->gsc_client
		);

		$this->report_page = new Report_Page(
			$this->seo_adapter
		);

		$this->gsc_page = new GSC_Page(
			$this->gsc_client
		);

		$this->indexing_tools_page = new Indexing_Tools_Page(
			$this->seo_adapter
		);

		$this->ajax = new Ajax(
			$this->metadata_generator
		);

		$this->admin->init();
		( new Markdown_Page() )->init();
		$this->audit_page->init();
		$this->report_page->init();
		$this->gsc_page->init();
		$this->indexing_tools_page->init();
		$this->ajax->init();

		/*
		 * ⛔ REDIRECTS HAVE MOVED TO AJR CORE. ONE FEATURE, ONE PLUGIN.
		 *
		 * AJR Core 0.5.0 owns redirects on every site, retainer or not. Both plugins
		 * hooked `template_redirect` at priority 1, so with both active the same rules
		 * were applied twice by two owners — and a rule edited on one screen and not the
		 * other would have been decided by whichever plugin happened to load first. That
		 * is not a conflict anyone would see in testing; it is one that appears months
		 * later as "that redirect stopped working".
		 *
		 * So this feature stands down the moment AJR Core is present: no handler, no
		 * menu item, no table install. It keeps running only on a site that does not
		 * have AJR Core yet, because standing down there would drop that site's
		 * redirects on an update — the one outcome worse than duplication.
		 *
		 * AJR Core copies this plugin's rules into its own table on first run and
		 * changes nothing here, so the hand-over needs no migration and is reversible.
		 * The code goes altogether in 5.0, once AJR Core is on every site.
		 */
		if ( self::core_owns_redirects() ) {
			/*
			 * The SUGGESTIONS do not move with the redirects, and should not: they need
			 * Search Console, which is this plugin's job and part of what a retainer pays
			 * for. AJR Core owns the screen and the rules; this supplies the knowledge of
			 * which addresses Google is still asking for. Admin only — it is a screen.
			 */
			if ( is_admin() ) {
				( new Core_Suggestions( $this->gsc_client ) )->register();
			}
		} else {
			$this->redirect_store = new Redirect_Store();
			( new Redirect_Handler( $this->redirect_store ) )->register();

			if ( is_admin() ) {
				/*
				 * ⛔ is_admin() is a CONTEXT flag, not a permission check. admin-post.php
				 * defines WP_ADMIN and fires admin_init (line 27) BEFORE it checks
				 * is_user_logged_in() (line 36) — verified in core on this site — so anything
				 * gated on is_admin() alone is reachable by a request carrying no cookie.
				 * Here that only ever meant a dbDelta while the version flag was stale, which
				 * self-heals, so nothing was exposed; this makes the gate say what it means.
				 *
				 * The capability is checked ON admin_init, not here: calling
				 * current_user_can() at plugins_loaded resolves the current user before the
				 * authentication filters have run, which breaks application-password and REST
				 * logins — a worse bug than the one being fixed.
				 */
				add_action(
					'admin_init',
					function (): void {
						if ( current_user_can( 'manage_options' ) ) {
							$this->redirect_store->maybe_install();
						}
					}
				);
				$this->redirects_page = new Redirects_Page( $this->redirect_store, $this->gsc_client );
				$this->redirects_page->init();
			}
		}
	}

	/**
	 * Whether AJR Core is present and owns redirects on this site.
	 *
	 * Checks for the class rather than the plugin file, so it is true exactly when AJR
	 * Core has actually loaded — a plugin that is installed but not active, or active but
	 * fatally broken, must NOT switch this plugin's redirects off.
	 *
	 * @return bool
	 */
	public static function core_owns_redirects() {
		return class_exists( '\AJR\Core\Redirects\Redirect_Store' );
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * Hooked on `init` (not earlier) so it runs after WordPress has finished
	 * setting up locales, avoiding the just-in-time translation-loading notice
	 * introduced in WordPress 6.7.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'ai-seo-assistant',
			false,
			dirname( AI_SEO_ASSISTANT_BASENAME ) . '/languages'
		);
	}
}
