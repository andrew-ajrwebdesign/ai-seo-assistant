<?php
/**
 * Unit-test stand-in for wp-admin/includes/upgrade.php (ABSPATH is tests/): records dbDelta() calls.
 *
 * @package AJR\SEOAssistant
 */

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Record the schema instead of running it.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return array<int,string>
	 */
	function dbDelta( $sql ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- core's name.
		$GLOBALS['aisa_dbdelta'][] = $sql;
		return [];
	}
}
