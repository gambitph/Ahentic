<?php
/**
 * Core `wp_ai_client_prompt()` test double for the wp-mocked tier.
 *
 * Shared by connector-status probes and complete_chat() Core-path tests.
 *
 * @package Ahentic
 */

if ( ! class_exists( 'Ahentic_Test_Generative_Result' ) ) {
	/**
	 * Minimal generate_text_result() return object (`toText()`).
	 */
	class Ahentic_Test_Generative_Result {
		/**
		 * @var string
		 */
		private $text;

		/**
		 * @param string $text Generated text.
		 */
		public function __construct( $text ) {
			$this->text = (string) $text;
		}

		/**
		 * @return string
		 */
		public function toText() {
			return $this->text;
		}
	}
}

if ( ! class_exists( 'Ahentic_Test_Prompt_Builder' ) ) {
	/**
	 * Fluent stand-in for WP_AI_Client_Prompt_Builder.
	 */
	class Ahentic_Test_Prompt_Builder {
		/** @var bool|\Throwable|callable */
		public static $supported = true;

		/** @var bool When false, is_supported_for_text_generation() is false if max_tokens is set. */
		public static $supported_with_max_tokens = true;

		/** @var int */
		public static $probe_calls = 0;

		/** @var int */
		public static $generate_calls = 0;

		/** @var mixed|null Override for generate_text_result(); null uses a successful result. */
		public static $generate_result = null;

		/** @var float|null Timeout captured from using_request_options(). */
		public static $last_timeout = null;

		/** @var int|null Max tokens on the builder that generated. */
		public static $last_generate_max_tokens = null;

		/** @var int|null */
		public $max_tokens = null;

		/**
		 * Reset statics between tests.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$supported                   = true;
			self::$supported_with_max_tokens   = true;
			self::$probe_calls                 = 0;
			self::$generate_calls              = 0;
			self::$generate_result             = null;
			self::$last_timeout                = null;
			self::$last_generate_max_tokens    = null;
		}

		/**
		 * @param string $system System instruction.
		 * @return self
		 */
		public function using_system_instruction( $system ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return $this;
		}

		/**
		 * @param int $max_tokens Max output tokens.
		 * @return self
		 */
		public function using_max_tokens( $max_tokens ) {
			$this->max_tokens = (int) $max_tokens;
			return $this;
		}

		/**
		 * @param object $options RequestOptions-like object.
		 * @return self
		 */
		public function using_request_options( $options ) {
			if ( is_object( $options ) && method_exists( $options, 'getTimeout' ) ) {
				self::$last_timeout = $options->getTimeout();
			}
			return $this;
		}

		/**
		 * @return bool
		 */
		public function is_supported_for_text_generation() {
			self::$probe_calls++;
			if ( is_callable( self::$supported ) ) {
				return (bool) call_user_func( self::$supported );
			}
			if ( self::$supported instanceof \Throwable ) {
				throw self::$supported;
			}
			if ( null !== $this->max_tokens && ! self::$supported_with_max_tokens ) {
				return false;
			}
			return (bool) self::$supported;
		}

		/**
		 * @return object|\WP_Error
		 */
		public function generate_text_result() {
			self::$generate_calls++;
			self::$last_generate_max_tokens = $this->max_tokens;

			if ( null !== $this->max_tokens && ! self::$supported_with_max_tokens ) {
				return new WP_Error(
					'prompt_invalid_argument',
					'No models found that support text_generation for this prompt.'
				);
			}

			if ( null !== self::$generate_result ) {
				return self::$generate_result;
			}

			return new Ahentic_Test_Generative_Result( 'core reply' );
		}
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * @param string $prompt Prompt text.
	 * @return Ahentic_Test_Prompt_Builder
	 */
	function wp_ai_client_prompt( $prompt ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return new Ahentic_Test_Prompt_Builder();
	}
}
