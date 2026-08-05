<?php
/**
 * Thin wrapper around WordPress AI Client / php-ai-client.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_AI' ) ) {
	/**
	 * AI generation helpers for the orchestrator.
	 */
	class Ahentic_AI {
		/** Default max output tokens per completion (PRD). */
		const MAX_OUTPUT_TOKENS = 8000;
		/** Max output when staging / long-form content (PRD). */
		const MAX_OUTPUT_TOKENS_CONTENT = 16000;

		/**
		 * Session currently wrapping an LLM call (for curl progress heartbeats).
		 *
		 * @var int
		 */
		private static $heartbeat_session_id = 0;

		/**
		 * Whether a generation path is available.
		 *
		 * @return bool
		 */
		public static function is_available() {
			if ( function_exists( 'wp_ai_client_prompt' ) ) {
				return true;
			}
			// Prefer already-loaded Core SDK; avoid autoloading a second Composer copy.
			if ( class_exists( '\WordPress\AiClient\AiClient', false ) ) {
				return true;
			}
			return class_exists( '\WordPress\AiClient\AiClient' );
		}

		/**
		 * Generate assistant text for a chat turn.
		 *
		 * @param string $system  System instruction.
		 * @param array  $history Prior turns: [ ['role'=>'user|assistant','content'=>'…'], … ] excluding latest user if passed separately.
		 * @param string $user    Latest user message.
		 * @param array  $options Optional. max_output_tokens (int), session_id (int) for mid-wait heartbeats.
		 * @return array|\WP_Error { text, tokens_in, tokens_out, tokens_total, model }
		 */
		public static function complete_chat( $system, array $history, $user, $options = array() ) {
			$options    = is_array( $options ) ? $options : array();
			$session_id = isset( $options['session_id'] ) ? (int) $options['session_id'] : 0;

			self::begin_llm_heartbeat( $session_id );
			try {
				// Always prefer Core helpers when present — never mix with a Composer SDK copy.
				if ( function_exists( 'wp_ai_client_prompt' ) ) {
					return self::complete_via_core( $system, $history, $user, $options );
				}

				if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
					return self::complete_via_sdk( $system, $history, $user, $options );
				}

				return new WP_Error(
					'ahentic_ai_unavailable',
					__( 'No AI client is available. Install and configure the WordPress AI plugin (Settings → AI / Connectors).', 'ahentic' ),
					array( 'status' => 503 )
				);
			} finally {
				self::end_llm_heartbeat( $session_id );
			}
		}

		/**
		 * Mark LLM in-flight: scheduled keepalive + WP HTTP curl progress ticks.
		 *
		 * @param int $session_id Session ID.
		 */
		private static function begin_llm_heartbeat( $session_id ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}
			self::$heartbeat_session_id = $session_id;
			if ( class_exists( 'Ahentic_Step_Queue' ) ) {
				Ahentic_Step_Queue::start_llm_keepalive( $session_id );
			} elseif ( class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::touch_heartbeat( $session_id );
			}
			add_action( 'http_api_curl', array( __CLASS__, 'attach_curl_heartbeat_progress' ), 10, 3 );
		}

		/**
		 * @param int $session_id Session ID.
		 */
		private static function end_llm_heartbeat( $session_id ) {
			remove_action( 'http_api_curl', array( __CLASS__, 'attach_curl_heartbeat_progress' ), 10 );
			self::$heartbeat_session_id = 0;
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}
			if ( class_exists( 'Ahentic_Step_Queue' ) ) {
				Ahentic_Step_Queue::stop_llm_keepalive( $session_id );
			} elseif ( class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::touch_heartbeat( $session_id );
			}
		}

		/**
		 * Bump session heartbeat from in-process curl transfer progress (when AI uses WP HTTP).
		 *
		 * @param resource|\CurlHandle $handle      Curl handle.
		 * @param array                $parsed_args Request args.
		 * @param string               $url         URL.
		 */
		public static function attach_curl_heartbeat_progress( $handle, $parsed_args = array(), $url = '' ) {
			unset( $parsed_args, $url );
			$session_id = (int) self::$heartbeat_session_id;
			if ( $session_id <= 0 || ! function_exists( 'curl_setopt' ) ) {
				return;
			}

			$ok_handle = is_resource( $handle );
			if ( ! $ok_handle && is_object( $handle ) && 'CurlHandle' === get_class( $handle ) ) {
				$ok_handle = true;
			}
			if ( ! $ok_handle ) {
				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- intentional progress hook on WP HTTP curl handle.
			curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt(
				$handle,
				CURLOPT_PROGRESSFUNCTION,
				static function () use ( $session_id ) {
					static $last = 0;
					$now         = time();
					if ( $now - $last < 5 ) {
						return 0;
					}
					$last = $now;
					if ( class_exists( 'Ahentic_Session_Repository' ) ) {
						Ahentic_Session_Repository::touch_heartbeat( $session_id );
					}
					if ( class_exists( 'Ahentic_Step_Queue' ) ) {
						Ahentic_Step_Queue::refresh_run_lock( $session_id );
					}
					return 0;
				}
			);
		}

		/**
		 * Single-shot text generation (summaries, etc.).
		 *
		 * @param string $system System instruction.
		 * @param string $user   User prompt.
		 * @return array|\WP_Error
		 */
		public static function complete_text( $system, $user ) {
			return self::complete_chat( $system, array(), $user );
		}

		/**
		 * Core AI Client path (WP 7.0+).
		 *
		 * WP_AI_Client_Prompt_Builder exposes snake_case APIs via __call — do not
		 * gate on method_exists() (those methods are not declared on the class).
		 *
		 * @param string $system  System.
		 * @param array  $history History.
		 * @param string $user    User.
		 * @param array  $options Options.
		 * @return array|\WP_Error
		 */
		private static function complete_via_core( $system, array $history, $user, $options = array() ) {
			$prompt_text = self::flatten_prompt( $history, $user );
			$max_tokens  = self::resolve_max_output_tokens( $options );

			try {
				$builder = wp_ai_client_prompt( $prompt_text );
				if ( ! is_object( $builder ) ) {
					return new WP_Error( 'ahentic_ai_api', __( 'Unexpected AI client API shape.', 'ahentic' ) );
				}

				$builder = $builder->using_system_instruction( (string) $system );
				if ( $max_tokens > 0 ) {
					$builder = $builder->using_max_tokens( $max_tokens );
				}

				$result = $builder->generate_text_result();

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return self::normalize_result( $result );
			} catch ( Exception $e ) {
				return new WP_Error( 'ahentic_ai_exception', $e->getMessage() );
			} catch ( Throwable $e ) {
				return new WP_Error( 'ahentic_ai_exception', $e->getMessage() );
			}
		}

		/**
		 * Strip <<<AHENTIC_DEBUG … AHENTIC_DEBUG>>> from model text.
		 *
		 * Tolerant of common model mistakes (2+ trailing `>`, markdown fences,
		 * missing user prose). Never leaves the debug markers in returned text.
		 *
		 * @param string $text Raw model text.
		 * @return array{ text: string, debug: ?array }
		 */
		public static function extract_debug_block( $text ) {
			$text  = (string) $text;
			$debug = null;

			// Primary: <<<AHENTIC_DEBUG … AHENTIC_DEBUG>> (2+ greater-thans).
			$pattern = '/<<<\s*AHENTIC_DEBUG\s*([\s\S]*?)\s*AHENTIC_DEBUG>{2,}/u';
			if ( preg_match( $pattern, $text, $m ) ) {
				$debug = self::parse_debug_json( $m[1] );
				$text  = trim( preg_replace( $pattern, '', $text, 1 ) );
			}

			// Fallback: opener present but closer mangled / truncated — pull JSON object.
			if ( null === $debug && preg_match( '/<<<\s*AHENTIC_DEBUG\s*/u', $text ) ) {
				if ( preg_match( '/<<<\s*AHENTIC_DEBUG\s*(\{[\s\S]*\})/u', $text, $m ) ) {
					$debug = self::parse_debug_json( $m[1] );
				}
				// Drop everything from the opener onward so markers never reach the UI.
				$text = trim( preg_replace( '/<<<\s*AHENTIC_DEBUG[\s\S]*/u', '', $text, 1 ) );
			}

			// Last resort: strip any leftover markers.
			$text = trim( preg_replace( '/<<<\s*AHENTIC_DEBUG[\s\S]*?(?:AHENTIC_DEBUG>{2,}|$)/u', '', $text ) );
			$text = trim( preg_replace( '/AHENTIC_DEBUG>{2,}/u', '', $text ) );

			if ( '' === $text && is_array( $debug ) ) {
				$text = self::fallback_reply_from_debug( $debug );
			}

			return array(
				'text'  => $text,
				'debug' => $debug,
			);
		}

		/**
		 * Parse the JSON payload inside a debug block.
		 *
		 * @param string $raw Raw JSON (may include fences).
		 * @return array|null
		 */
		private static function parse_debug_json( $raw ) {
			$raw = trim( (string) $raw );
			$raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
			$raw = preg_replace( '/\s*```$/', '', $raw );
			$raw = trim( $raw );

			$parsed = json_decode( $raw, true );
			if ( is_array( $parsed ) ) {
				return $parsed;
			}

			// Extract first { … } if extra prose surrounds it.
			if ( preg_match( '/\{[\s\S]*\}/u', $raw, $m ) ) {
				$parsed = json_decode( $m[0], true );
				if ( is_array( $parsed ) ) {
					return $parsed;
				}
			}

			return null;
		}

		/**
		 * When the model only emitted a debug block, still produce chat text.
		 * Prefers thinking, then intention — the same trail the sidebar showed before plans.
		 *
		 * @param array $debug Parsed debug.
		 * @return string
		 */
		public static function fallback_reply_from_debug( array $debug ) {
			$next      = isset( $debug['next'] ) ? (string) $debug['next'] : 'reply';
			$thinking  = isset( $debug['thinking'] ) ? trim( (string) $debug['thinking'] ) : '';
			$intention = isset( $debug['intention'] ) ? trim( (string) $debug['intention'] ) : '';

			// Final replies should not dump mid-process thinking into chat.
			if ( in_array( $next, array( 'reply', 'ask_user', 'missing_ability' ), true ) ) {
				if ( 'ask_user' === $next && $intention ) {
					return sprintf(
						/* translators: %s: short intention */
						__( 'I need a bit more information to continue (%s).', 'ahentic' ),
						$intention
					);
				}
				if ( $thinking && ! self::text_looks_like_process( $thinking ) ) {
					return $thinking;
				}
				if ( $intention ) {
					return sprintf(
						/* translators: %s: short intention / status */
						__( 'Done — %s.', 'ahentic' ),
						lcfirst( $intention )
					);
				}
				return __( 'Done.', 'ahentic' );
			}

			if ( $thinking ) {
				return $thinking;
			}

			if ( $intention ) {
				return $intention;
			}

			return __( 'I need a bit more information to continue.', 'ahentic' );
		}

		/**
		 * Whether text reads like an in-progress thought rather than a user-facing answer.
		 *
		 * @param string $text Text.
		 * @return bool
		 */
		public static function text_looks_like_process( $text ) {
			$text = trim( (string) $text );
			if ( '' === $text ) {
				return true;
			}
			return (bool) preg_match(
				'/^(i co(?:uld|n)\b|i will\b|i\'ll\b|let me\b|next i\b|checking\b|looking\b|searching\b|planning\b|now i\b|i need to\b|i should\b)/i',
				$text
			);
		}

		/**
		 * Composer SDK path.
		 *
		 * @param string $system  System.
		 * @param array  $history History.
		 * @param string $user    User.
		 * @param array  $options Options.
		 * @return array|\WP_Error
		 */
		private static function complete_via_sdk( $system, array $history, $user, $options = array() ) {
			$max_tokens = self::resolve_max_output_tokens( $options );
			try {
				$messages = array();
				foreach ( $history as $turn ) {
					$role    = isset( $turn['role'] ) ? $turn['role'] : 'user';
					$content = isset( $turn['content'] ) ? (string) $turn['content'] : '';
					if ( '' === $content ) {
						continue;
					}
					$part = new \WordPress\AiClient\Messages\DTO\MessagePart( $content );
					if ( 'assistant' === $role || 'model' === $role ) {
						$messages[] = new \WordPress\AiClient\Messages\DTO\ModelMessage( array( $part ) );
					} else {
						$messages[] = new \WordPress\AiClient\Messages\DTO\UserMessage( array( $part ) );
					}
				}

				$builder = \WordPress\AiClient\AiClient::prompt( (string) $user )
					->usingSystemInstruction( (string) $system );

				if ( $max_tokens > 0 ) {
					$builder = $builder->usingMaxTokens( $max_tokens );
				}

				if ( ! empty( $messages ) ) {
					$builder = $builder->withHistory( ...$messages );
				}

				$result = $builder->generateTextResult();
				return self::normalize_result( $result );
			} catch ( Exception $e ) {
				return new WP_Error(
					'ahentic_ai_exception',
					sprintf(
						/* translators: %s: exception message */
						__( 'AI generation failed: %s', 'ahentic' ),
						$e->getMessage()
					)
				);
			} catch ( Throwable $e ) {
				return new WP_Error(
					'ahentic_ai_exception',
					sprintf(
						/* translators: %s: exception message */
						__( 'AI generation failed: %s', 'ahentic' ),
						$e->getMessage()
					)
				);
			}
		}

		/**
		 * @param array $options Options.
		 * @return int
		 */
		private static function resolve_max_output_tokens( array $options ) {
			if ( isset( $options['max_output_tokens'] ) ) {
				return max( 256, (int) $options['max_output_tokens'] );
			}
			return self::MAX_OUTPUT_TOKENS;
		}

		/**
		 * Flatten history for APIs that only take a single prompt string.
		 *
		 * @param array  $history History.
		 * @param string $user    Latest user.
		 * @return string
		 */
		private static function flatten_prompt( array $history, $user ) {
			$parts = array();
			foreach ( $history as $turn ) {
				$role    = isset( $turn['role'] ) ? $turn['role'] : 'user';
				$content = isset( $turn['content'] ) ? (string) $turn['content'] : '';
				if ( '' === $content ) {
					continue;
				}
				$label   = ( 'assistant' === $role || 'model' === $role ) ? 'Assistant' : 'User';
				$parts[] = $label . ': ' . $content;
			}
			$parts[] = 'User: ' . $user;
			return implode( "\n\n", $parts );
		}

		/**
		 * Normalize GenerativeAiResult-like object to array.
		 *
		 * @param mixed $result Result object.
		 * @return array|\WP_Error
		 */
		private static function normalize_result( $result ) {
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$text = '';
			if ( is_object( $result ) && method_exists( $result, 'toText' ) ) {
				try {
					$text = (string) $result->toText();
				} catch ( Exception $e ) {
					return new WP_Error( 'ahentic_ai_empty', $e->getMessage() );
				} catch ( Throwable $e ) {
					return new WP_Error( 'ahentic_ai_empty', $e->getMessage() );
				}
			} elseif ( is_string( $result ) ) {
				$text = $result;
			}

			$in = $out = $total = 0;
			$model = '';

			if ( is_object( $result ) && method_exists( $result, 'getTokenUsage' ) ) {
				$usage = $result->getTokenUsage();
				if ( is_object( $usage ) ) {
					if ( method_exists( $usage, 'getPromptTokens' ) ) {
						$in = (int) $usage->getPromptTokens();
					}
					if ( method_exists( $usage, 'getCompletionTokens' ) ) {
						$out = (int) $usage->getCompletionTokens();
					}
					if ( method_exists( $usage, 'getTotalTokens' ) ) {
						$total = (int) $usage->getTotalTokens();
					}
				}
			}

			if ( is_object( $result ) && method_exists( $result, 'getModelMetadata' ) ) {
				$meta = $result->getModelMetadata();
				if ( is_object( $meta ) && method_exists( $meta, 'getId' ) ) {
					$model = (string) $meta->getId();
				} elseif ( is_object( $meta ) && method_exists( $meta, 'getName' ) ) {
					$model = (string) $meta->getName();
				}
			}

			$raw       = $text;
			$extracted = self::extract_debug_block( $text );
			$text      = $extracted['text'];
			$debug     = $extracted['debug'];

			if ( '' === trim( $text ) ) {
				// Debug-only replies become fallback prose in extract_debug_block.
				// If the model emitted tokens / markers that stripped to nothing
				// (malformed AHENTIC_DEBUG), return a soft empty result so the
				// orchestrator can retry for a usable control block instead of
				// hard-failing the run with ahentic_ai_empty.
				$had_raw = '' !== trim( (string) $raw );
				$had_out = $out > 0;
				if ( $had_raw || $had_out ) {
					return array(
						'text'         => '',
						'tokens_in'    => $in,
						'tokens_out'   => $out,
						'tokens_total' => $total > 0 ? $total : ( $in + $out ),
						'model'        => $model,
						'debug'        => is_array( $debug ) ? $debug : null,
					);
				}

				return new WP_Error( 'ahentic_ai_empty', __( 'The model returned an empty response.', 'ahentic' ) );
			}

			return array(
				'text'         => $text,
				'tokens_in'    => $in,
				'tokens_out'   => $out,
				'tokens_total' => $total > 0 ? $total : ( $in + $out ),
				'model'        => $model,
				'debug'        => $debug,
			);
		}
	}
}
