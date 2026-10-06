<?php
/**
 * A $wpdb stand-in for the options table rows the plugin reads and writes in raw SQL: the scan lock
 * (INSERT IGNORE, SELECT, DELETE by name and value) and the cancelled-run flag (SELECT).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Tests\Unit;

/**
 * Options rows in memory.
 */
class Fake_Options_Db {

	/** @var string */
	public $prefix = 'wp_';

	/** @var string */
	public $options = 'wp_options';

	/** @var array<string,string> option_name => option_value */
	public $rows = [];

	/** @var int How many INSERT IGNOREs were tried. */
	public $inserts = 0;

	/**
	 * Keep the query and its arguments.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function prepare( $query, ...$args ) {
		return [ $query, $args ];
	}

	/**
	 * INSERT IGNORE (1 when inserted, 0 when the name is taken) and DELETE (by name, or name and value).
	 *
	 * @param array{0:string,1:array<int,mixed>} $prepared prepare().
	 * @return int Rows affected.
	 */
	public function query( $prepared ) {
		[ $q, $args ] = $prepared;
		if ( 0 === strpos( $q, 'INSERT IGNORE' ) ) {
			++$this->inserts;
			if ( isset( $this->rows[ $args[0] ] ) ) {
				return 0;
			}
			$this->rows[ $args[0] ] = (string) $args[1];
			return 1;
		}
		if ( 0 === strpos( $q, 'DELETE' ) ) {
			$name = (string) $args[0];
			if ( ! isset( $this->rows[ $name ] ) || ( isset( $args[1] ) && false !== strpos( $q, 'option_value' ) && $this->rows[ $name ] !== (string) $args[1] ) ) {
				return 0;
			}
			unset( $this->rows[ $name ] );
			return 1;
		}

		return 0;
	}

	/**
	 * SELECT option_value by name.
	 *
	 * @param array{0:string,1:array<int,mixed>} $prepared prepare().
	 * @return string|null
	 */
	public function get_var( $prepared ) {
		return $this->rows[ (string) $prepared[1][0] ] ?? null;
	}
}
