<?php
/**
 * Minimal stand-ins for the WordPress classes the Report module type-checks against. Loaded only when
 * WordPress itself is not; they hold data and nothing more.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- test stubs.

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * WP_Error stand-in.
	 */
	class WP_Error {

		/** @var string */
		public $code;

		/** @var string */
		public $message;

		/** @var mixed */
		public $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Error code.
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error data.
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * WP_REST_Request stand-in: a body and headers.
	 */
	class WP_REST_Request {

		/** @var string */
		protected $body = '';

		/** @var array<string,string> */
		protected $headers = [];

		/**
		 * Set the body.
		 *
		 * @param string $body Body.
		 */
		public function set_body( $body ) {
			$this->body = $body;
		}

		/**
		 * Get the body.
		 */
		public function get_body() {
			return $this->body;
		}

		/**
		 * Set a header.
		 *
		 * @param string $name  Name.
		 * @param string $value Value.
		 */
		public function set_header( $name, $value ) {
			$this->headers[ strtolower( str_replace( '-', '_', $name ) ) ] = $value;
		}

		/**
		 * Get a header (WordPress normalises names the same way).
		 *
		 * @param string $name Name.
		 */
		public function get_header( $name ) {
			return $this->headers[ strtolower( str_replace( '-', '_', $name ) ) ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * WP_REST_Response stand-in.
	 */
	class WP_REST_Response {

		/** @var mixed */
		public $data;

		/** @var int */
		public $status;

		/**
		 * Constructor.
		 *
		 * @param mixed $data   Data.
		 * @param int   $status Status.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/**
		 * Status.
		 */
		public function get_status() {
			return $this->status;
		}

		/**
		 * Data.
		 */
		public function get_data() {
			return $this->data;
		}
	}

	/**
	 * Just enough WP_User: an ID and roles.
	 */
	class WP_User {

		/**
		 * User ID.
		 *
		 * @var int
		 */
		public $ID = 0;

		/**
		 * Role slugs.
		 *
		 * @var array<int,string>
		 */
		public $roles = [];
	}
}
