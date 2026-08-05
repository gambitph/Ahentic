<?php
/**
 * Class stubs for the WordPress-functions-mocked tier.
 *
 * Brain Monkey (via Patchwork) can fake WordPress *functions* at runtime, but
 * it cannot fabricate *classes* — a real class definition still has to exist
 * for `instanceof`/method calls to work. Keep this to the minimum shape
 * Ahentic's code actually calls; if a test needs more of WP_Error (or any
 * other WP class) than this, that's a sign the code under test needs real
 * WordPress and belongs in the Playwright suite instead.
 *
 * @package Ahentic
 */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Error.
	 */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/** @var mixed */
		private $data;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Optional error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}
