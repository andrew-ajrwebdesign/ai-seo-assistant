<?php
/**
 * Resolves which SEO plugin adapter should be used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_SEO_Assistant_SEO_Adapter_Resolver {

	private $tsf_adapter;
	private $yoast_adapter;
	private $rankmath_adapter;

	public function __construct( $tsf_adapter, $yoast_adapter, $rankmath_adapter ) {
		$this->tsf_adapter      = $tsf_adapter;
		$this->yoast_adapter    = $yoast_adapter;
		$this->rankmath_adapter = $rankmath_adapter;
	}

	public function get_adapter() {
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

		return null;
	}

	public static function any_seo_plugin_active(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || class_exists( 'The_SEO_Framework\Load' )
			|| defined( 'WPSEO_VERSION' ) || defined( 'YOAST_SEO_VERSION' ) || class_exists( 'WPSEO_Options' )
			|| defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) || class_exists( '\RankMath\Runner' );
	}

	public function get_current_integration_id() {
		$adapter = $this->get_adapter();
		return $adapter ? $adapter->get_id() : '';
	}

	public function get_current_integration_name() {
		$adapter = $this->get_adapter();
		return $adapter ? $adapter->get_name() : 'None detected';
	}
}