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
use AJR\SEOAssistant\AI\OpenAI_Client;
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
	private $openai_client;
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
		$this->openai_client     = new OpenAI_Client();
		$this->logger            = new Logger();
		$this->local_seo_context = new Local_SEO_Context();
		$this->gsc_client        = new GSC_Client();

		$this->metadata_generator = new Metadata_Generator(
			$this->seo_adapter,
			$this->content_extractor,
			$this->prompt_builder,
			$this->openai_client,
			$this->logger,
			$this->local_seo_context,
			$this->gsc_client
		);

		$this->admin = new Admin(
			$this->seo_adapter,
			$this->logger,
			$this->local_seo_context,
			$this->seo_adapter_resolver
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

		// Redirects: the front-end handler runs on every request; the admin UI
		// and the table install only load in the admin (the front end reads the
		// cached lookup map, never the table).
		$this->redirect_store = new Redirect_Store();
		( new Redirect_Handler( $this->redirect_store ) )->register();

		if ( is_admin() ) {
			$this->redirect_store->maybe_install();
			$this->redirects_page = new Redirects_Page( $this->redirect_store, $this->gsc_client );
			$this->redirects_page->init();
		}
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
