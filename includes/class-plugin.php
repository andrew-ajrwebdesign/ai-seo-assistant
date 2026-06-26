<?php
/**
 * Main plugin bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_Plugin {

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

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		$this->tsf_adapter      = new AI_SEO_Assistant_TSF_Adapter();
		$this->yoast_adapter    = new AI_SEO_Assistant_Yoast_Adapter();
		$this->rankmath_adapter = new AI_SEO_Assistant_RankMath_Adapter();

		$this->seo_adapter_resolver = new AI_SEO_Assistant_SEO_Adapter_Resolver(
			$this->tsf_adapter,
			$this->yoast_adapter,
			$this->rankmath_adapter
		);

		$this->seo_adapter       = $this->seo_adapter_resolver->get_adapter() ?? $this->tsf_adapter;
		$this->content_extractor = new AI_SEO_Assistant_Content_Extractor();
		$this->prompt_builder    = new AI_SEO_Assistant_Prompt_Builder();
		$this->openai_client     = new AI_SEO_Assistant_OpenAI_Client();
		$this->logger            = new AI_SEO_Assistant_Logger();
		$this->local_seo_context = new AI_SEO_Assistant_Local_SEO_Context();
		$this->gsc_client        = new AI_SEO_Assistant_GSC_Client();

		$this->metadata_generator = new AI_SEO_Assistant_Metadata_Generator(
			$this->seo_adapter,
			$this->content_extractor,
			$this->prompt_builder,
			$this->openai_client,
			$this->logger,
			$this->local_seo_context,
			$this->gsc_client
		);

		$this->admin = new AI_SEO_Assistant_Admin(
			$this->seo_adapter,
			$this->logger,
			$this->local_seo_context,
			$this->seo_adapter_resolver
		);

		$this->audit_page = new AI_SEO_Assistant_Audit_Page(
			$this->seo_adapter,
			$this->logger,
			$this->content_extractor,
			$this->local_seo_context,
			$this->gsc_client
		);

		$this->report_page = new AI_SEO_Assistant_Report_Page(
			$this->seo_adapter
		);

		$this->gsc_page = new AI_SEO_Assistant_GSC_Page(
			$this->gsc_client
		);

		$this->indexing_tools_page = new AI_SEO_Assistant_Indexing_Tools_Page(
			$this->seo_adapter
		);

		$this->ajax = new AI_SEO_Assistant_Ajax(
			$this->metadata_generator
		);

		$this->admin->init();
		( new AI_SEO_Assistant_Markdown_Page() )->init();
		$this->audit_page->init();
		$this->report_page->init();
		$this->gsc_page->init();
		$this->indexing_tools_page->init();
		$this->ajax->init();
	}
}