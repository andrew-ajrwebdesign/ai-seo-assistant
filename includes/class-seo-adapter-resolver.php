<?php
/**
 * Resolves which SEO plugin adapter should be used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_SEO_Adapter_Resolver {

	const OPTION_NAME = 'ai_seo_assistant_seo_integration';

	private $tsf_adapter;
	private $yoast_adapter;
	private $rankmath_adapter;

	public function __construct( $tsf_adapter, $yoast_adapter, $rankmath_adapter ) {
		$this->tsf_adapter      = $tsf_adapter;
		$this->yoast_adapter    = $yoast_adapter;
		$this->rankmath_adapter = $rankmath_adapter;
	}

	public function get_adapter() {
		$integration = get_option( self::OPTION_NAME, 'auto' );

		if ( 'the_seo_framework' === $integration ) {
			return $this->tsf_adapter;
		}

		if ( 'yoast' === $integration ) {
			return $this->yoast_adapter;
		}

		if ( 'rank_math' === $integration ) {
			return $this->rankmath_adapter;
		}

		return $this->auto_detect_adapter();
	}

	private function auto_detect_adapter() {
		if ( $this->tsf_adapter->is_available() ) {
			return $this->tsf_adapter;
		}

		if ( $this->yoast_adapter->is_available() ) {
			return $this->yoast_adapter;
		}

		if ( $this->rankmath_adapter->is_available() ) {
			return $this->rankmath_adapter;
		}

		return $this->tsf_adapter;
	}

	public function get_current_integration_id() {
		return $this->get_adapter()->get_id();
	}

	public function get_current_integration_name() {
		return $this->get_adapter()->get_name();
	}
}