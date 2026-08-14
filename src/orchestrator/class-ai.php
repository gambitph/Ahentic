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
		 * HTTP ceiling for provider calls (list-models + generate).
		 *
		 * Same value for chat and image: a timeout is a max wait, not a pause.
		 * Builder RequestOptions cover generate; complete_chat() also raises
		 * http_request_timeout so a slow list-models is not mapped to
		 * "No models found that support text_generation".
		 */
		const REQUEST_TIMEOUT_SECONDS = 120.0;

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

			/**
			 * Short-circuit a chat completion (e2e testing only).
			 *
			 * Nothing in production hooks this — only the e2e-only mu-plugin
			 * (tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php, never shipped
			 * with the plugin) does, so it's a no-op outside the Playwright suite.
			 * Returning a non-null, complete_chat()-shaped array here skips the
			 * real AI provider entirely and returns it as-is.
			 *
			 * @param array|null $override Non-null to short-circuit; null (default) proceeds to a real provider.
			 * @param string     $system   System instruction.
			 * @param array      $history  Prior turns.
			 * @param string     $user     Latest user message.
			 * @param array      $options  Options passed to complete_chat().
			 */
			$override = apply_filters( 'pre_ahentic_ai_complete_chat', null, $system, $history, $user, $options );
			if ( null !== $override ) {
				return $override;
			}

			self::begin_llm_heartbeat( $session_id );
			$http_timeout_cb = self::install_http_timeout_ceiling();
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
				self::remove_http_timeout_ceiling( $http_timeout_cb );
				self::end_llm_heartbeat( $session_id );
			}
		}

		/**
		 * Vision: describe an image file path or remote URL; return structured alt/description.
		 *
		 * @param string $file_or_url Local path or http(s) URL.
		 * @param string $mime_type   MIME type.
		 * @return array|\WP_Error { description, alt_text_suggestion }
		 */
		public static function describe_image( $file_or_url, $mime_type = 'image/jpeg' ) {
			$file_or_url = (string) $file_or_url;
			$mime_type   = (string) $mime_type;

			/**
			 * Short-circuit describe-image (e2e).
			 *
			 * @param array|null $override Non-null to short-circuit.
			 * @param string     $file_or_url File path or URL.
			 * @param string     $mime_type MIME.
			 */
			$override = apply_filters( 'pre_ahentic_ai_describe_image', null, $file_or_url, $mime_type );
			if ( null !== $override ) {
				return $override;
			}

			$system = 'You are an accessibility expert. Respond with JSON only matching '
				. '{"description":"1-3 sentence general description","alt_text_suggestion":"objective, under 125 characters, no Image of/Photo of prefixes"}.';

			try {
				$has_core = function_exists( 'wp_ai_client_prompt' );
				$has_sdk  = class_exists( '\WordPress\AiClient\AiClient' );

				if ( $has_core ) {
					/*
					 * WP_AI_Client_Prompt_Builder exposes snake_case APIs via __call —
					 * do not gate on method_exists() (those methods are not declared).
					 * Same rule as complete_via_core().
					 */
					$builder = wp_ai_client_prompt( 'Describe this image for accessibility.' );
					if ( ! is_object( $builder ) ) {
						return new WP_Error( 'ahentic_ai_api', __( 'Unexpected AI client API shape.', 'ahentic' ) );
					}

					$builder = $builder->using_system_instruction( $system );
					$builder = $builder->with_file( $file_or_url, $mime_type );
					$builder = $builder->as_json_response();

					$result = $builder->generate_text_result();
					if ( is_wp_error( $result ) ) {
						return self::vision_provider_error( $result );
					}
					$text = self::result_to_text( $result );
					return self::parse_describe_json( $text );
				}

				if ( $has_sdk ) {
					$builder = \WordPress\AiClient\AiClient::prompt( 'Describe this image for accessibility.' );
					$builder = $builder->usingSystemInstruction( $system )->withFile( $file_or_url, $mime_type )->asJsonResponse();
					$result  = $builder->generateTextResult();
					$text    = self::result_to_text( $result );
					return self::parse_describe_json( $text );
				}

				return new WP_Error(
					'ahentic_ai_unavailable',
					__( 'No AI client is available. Install and configure the WordPress AI plugin (Settings → AI / Connectors).', 'ahentic' )
				);
			} catch ( Exception $e ) {
				return self::vision_provider_error( $e );
			} catch ( Throwable $e ) {
				return self::vision_provider_error( $e );
			}
		}

		/**
		 * Provider-safe aspect-ratio candidates for an Ahentic ratio.
		 *
		 * gpt-image advertises 1:1 / 3:2 / 2:3; dall-e advertises 1:1 / 7:4 / 4:7.
		 * Try both landscape/portrait families so either model can match.
		 *
		 * @param string $aspect_ratio Requested Ahentic ratio.
		 * @return list<string>
		 */
		private static function aspect_ratio_candidates( $aspect_ratio ) {
			$aspect_ratio = (string) $aspect_ratio;
			switch ( $aspect_ratio ) {
				case '16:9':
				case '4:3':
					// gpt-image landscape first, then dall-e wide.
					return array( '3:2', '7:4' );
				case '9:16':
				case '3:4':
					return array( '2:3', '4:7' );
				case '1:1':
					return array( '1:1' );
				default:
					return array( $aspect_ratio );
			}
		}

		/**
		 * @param string $aspect_ratio Requested Ahentic ratio.
		 * @return string square|landscape|portrait
		 */
		private static function orientation_for_aspect_ratio( $aspect_ratio ) {
			switch ( (string) $aspect_ratio ) {
				case '9:16':
				case '3:4':
					return 'portrait';
				case '1:1':
					return 'square';
				default:
					return 'landscape';
			}
		}

		/**
		 * Raise WP HTTP timeout for list-models + generate during complete_chat().
		 *
		 * Builder RequestOptions only bind after a model is chosen; list-models
		 * uses HttpTransporter::send() with no options. This filter covers that hop.
		 *
		 * @return callable|null Filter callback to remove, or null if add_filter is unavailable.
		 */
		private static function install_http_timeout_ceiling() {
			if ( ! function_exists( 'add_filter' ) ) {
				return null;
			}
			$callback = static function ( $timeout ) {
				return max( (float) $timeout, self::REQUEST_TIMEOUT_SECONDS );
			};
			add_filter( 'http_request_timeout', $callback );
			return $callback;
		}

		/**
		 * Undo install_http_timeout_ceiling().
		 *
		 * @param callable|null $callback From install_http_timeout_ceiling().
		 */
		private static function remove_http_timeout_ceiling( $callback ) {
			if ( null === $callback || ! function_exists( 'remove_filter' ) ) {
				return;
			}
			remove_filter( 'http_request_timeout', $callback );
		}

		/**
		 * Whether the builder can generate images with its current options.
		 *
		 * @param object $builder Prompt builder.
		 * @return bool|null True/false when probeable, null if method missing.
		 */
		private static function builder_supports_image_generation( $builder ) {
			return self::builder_support_flag(
				$builder,
				'is_supported_for_image_generation',
				'isSupportedForImageGeneration',
				false
			);
		}

		/**
		 * Probe a Core/SDK builder support method (snake_case then camelCase).
		 *
		 * @param object    $builder      Prompt builder.
		 * @param string    $snake_method Core snake_case method.
		 * @param string    $camel_method SDK camelCase method.
		 * @param bool|null $on_throw     Value when the probe throws.
		 * @return bool|null True/false when probeable, null if method missing.
		 */
		private static function builder_support_flag( $builder, $snake_method, $camel_method, $on_throw = false ) {
			if ( ! is_object( $builder ) ) {
				return null;
			}
			try {
				if ( is_callable( array( $builder, $snake_method ) ) ) {
					return (bool) call_user_func( array( $builder, $snake_method ) );
				}
				if ( is_callable( array( $builder, $camel_method ) ) ) {
					return (bool) call_user_func( array( $builder, $camel_method ) );
				}
			} catch ( Throwable $e ) {
				return $on_throw;
			}
			return null;
		}

		/**
		 * @param object $builder    Prompt builder.
		 * @param string $aspect     Aspect ratio string.
		 * @param bool   $snake_case Core snake_case API.
		 * @return object
		 */
		private static function builder_with_aspect_ratio( $builder, $aspect, $snake_case ) {
			if ( $snake_case ) {
				return $builder->as_output_media_aspect_ratio( (string) $aspect );
			}
			if ( method_exists( $builder, 'asOutputMediaAspectRatio' ) ) {
				return $builder->asOutputMediaAspectRatio( (string) $aspect );
			}
			return $builder;
		}

		/**
		 * @param object $builder      Prompt builder.
		 * @param string $orientation  square|landscape|portrait.
		 * @param bool   $snake_case   Core snake_case API.
		 * @return object
		 */
		private static function builder_with_orientation( $builder, $orientation, $snake_case ) {
			if ( ! class_exists( '\WordPress\AiClient\Files\Enums\MediaOrientationEnum' ) ) {
				return $builder;
			}
			$enum = \WordPress\AiClient\Files\Enums\MediaOrientationEnum::from( (string) $orientation );
			if ( $snake_case ) {
				return $builder->as_output_media_orientation( $enum );
			}
			if ( method_exists( $builder, 'asOutputMediaOrientation' ) ) {
				return $builder->asOutputMediaOrientation( $enum );
			}
			return $builder;
		}

		/**
		 * Pick a provider-supported size constraint (aspect candidates, then orientation).
		 *
		 * Aspect ratio must be cleared to use orientation-only — rebuild via $make_builder.
		 *
		 * @param callable $make_builder Returns a fresh prompt builder.
		 * @param string   $aspect_ratio Requested Ahentic ratio.
		 * @param bool     $snake_case   Use Core snake_case fluent methods.
		 * @return array{0:object,1:string} Builder and effective constraint label.
		 */
		private static function apply_image_aspect_ratio( $make_builder, $aspect_ratio, $snake_case = true ) {
			$aspect_ratio = (string) $aspect_ratio;
			$candidates   = self::aspect_ratio_candidates( $aspect_ratio );

			$builder = call_user_func( $make_builder );
			foreach ( $candidates as $candidate ) {
				$builder   = self::builder_with_aspect_ratio( $builder, $candidate, $snake_case );
				$supported = self::builder_supports_image_generation( $builder );
				if ( false !== $supported ) {
					return array( $builder, $candidate );
				}
			}

			// Aspect options exhausted — rebuild without aspect and use orientation (OpenAI default size otherwise is square).
			$orientation = self::orientation_for_aspect_ratio( $aspect_ratio );
			$builder     = call_user_func( $make_builder );
			$builder     = self::builder_with_orientation( $builder, $orientation, $snake_case );

			return array( $builder, 'orientation:' . $orientation );
		}

		/**
		 * Apply the shared request timeout to a Core / SDK prompt builder.
		 *
		 * @param object $builder    Prompt builder.
		 * @param bool   $snake_case Core snake_case API.
		 * @return object
		 */
		private static function apply_request_timeout( $builder, $snake_case = true ) {
			if ( ! is_object( $builder ) ) {
				return $builder;
			}
			if ( ! class_exists( '\WordPress\AiClient\Providers\Http\DTO\RequestOptions' ) ) {
				return $builder;
			}
			$options = \WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
				array(
					\WordPress\AiClient\Providers\Http\DTO\RequestOptions::KEY_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
				)
			);
			if ( $snake_case && is_callable( array( $builder, 'using_request_options' ) ) ) {
				return $builder->using_request_options( $options );
			}
			if ( method_exists( $builder, 'usingRequestOptions' ) ) {
				return $builder->usingRequestOptions( $options );
			}
			return $builder;
		}

		/**
		 * Whether the builder's current options have a text-generation candidate.
		 *
		 * @param object $builder Prompt builder.
		 * @return bool|null True/false when probeable, null if method missing or threw.
		 */
		private static function builder_supports_text_generation( $builder ) {
			return self::builder_support_flag(
				$builder,
				'is_supported_for_text_generation',
				'isSupportedForTextGeneration',
				null
			);
		}

		/**
		 * @param string $prompt Prompt text.
		 * @param bool   $snake_case Core path.
		 * @return object|\WP_Error
		 */
		private static function make_image_prompt_builder( $prompt, $snake_case = true ) {
			if ( $snake_case ) {
				$builder = wp_ai_client_prompt( $prompt );
				if ( ! is_object( $builder ) ) {
					return new WP_Error( 'ahentic_ai_api', __( 'Unexpected AI client API shape.', 'ahentic' ) );
				}
				return self::apply_request_timeout( $builder, true );
			}
			return \WordPress\AiClient\AiClient::prompt( $prompt );
		}

		/**
		 * Generate an image via AI Client; return data URI + mime + dimensions when known.
		 *
		 * @param string $prompt       Prompt.
		 * @param string $aspect_ratio Aspect ratio e.g. 16:9.
		 * @return array|\WP_Error { data_uri, mime_type, width, height }
		 */
		public static function generate_image( $prompt, $aspect_ratio = '16:9' ) {
			$prompt       = (string) $prompt;
			$aspect_ratio = (string) $aspect_ratio;

			/**
			 * Short-circuit generate-image (e2e).
			 *
			 * @param array|null $override Non-null to short-circuit.
			 * @param string     $prompt Prompt.
			 * @param string     $aspect_ratio Aspect ratio.
			 */
			$override = apply_filters( 'pre_ahentic_ai_generate_image', null, $prompt, $aspect_ratio );
			if ( null !== $override ) {
				return $override;
			}

			try {
				/*
				 * Core WP_AI_Client_Prompt_Builder exposes snake_case via __call —
				 * never gate on method_exists() (same rule as complete_via_core).
				 * CamelCase generateImage() is NOT in Core's generating-method map, so
				 * provider failures return the builder itself instead of WP_Error.
				 */
				if ( function_exists( 'wp_ai_client_prompt' ) ) {
					$plain = self::make_image_prompt_builder( $prompt, true );
					if ( is_wp_error( $plain ) ) {
						return $plain;
					}

					list( $builder ) = self::apply_image_aspect_ratio(
						static function () use ( $prompt ) {
							return self::make_image_prompt_builder( $prompt, true );
						},
						$aspect_ratio,
						true
					);

					$file = $builder->generate_image();

					if ( is_wp_error( $file ) ) {
						return self::image_gen_provider_error( $file );
					}

					return self::file_dto_to_generated( $file );
				}

				if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
					list( $builder ) = self::apply_image_aspect_ratio(
						static function () use ( $prompt ) {
							return self::make_image_prompt_builder( $prompt, false );
						},
						$aspect_ratio,
						false
					);
					$file = $builder->generateImage();
					return self::file_dto_to_generated( $file );
				}

				return new WP_Error(
					'ahentic_ai_unavailable',
					__( 'No AI client is available. Install and configure the WordPress AI plugin (Settings → AI / Connectors).', 'ahentic' )
				);
			} catch ( Exception $e ) {
				return self::image_gen_provider_error( $e );
			} catch ( Throwable $e ) {
				return self::image_gen_provider_error( $e );
			}
		}

		/**
		 * @param mixed $result Generative result or string.
		 * @return string
		 */
		private static function result_to_text( $result ) {
			if ( is_string( $result ) ) {
				return $result;
			}
			if ( is_object( $result ) && method_exists( $result, 'to_text' ) ) {
				return (string) $result->to_text();
			}
			if ( is_object( $result ) && method_exists( $result, 'toText' ) ) {
				return (string) $result->toText();
			}
			$normalized = self::normalize_result( $result );
			return is_array( $normalized ) && isset( $normalized['text'] ) ? (string) $normalized['text'] : '';
		}

		/**
		 * @param string $text JSON text.
		 * @return array|\WP_Error
		 */
		private static function parse_describe_json( $text ) {
			$text = trim( (string) $text );
			$data = json_decode( $text, true );
			if ( ! is_array( $data ) ) {
				// Try extract object.
				if ( preg_match( '/\{[\s\S]*\}/', $text, $m ) ) {
					$data = json_decode( $m[0], true );
				}
			}
			if ( ! is_array( $data ) || ! isset( $data['description'], $data['alt_text_suggestion'] ) ) {
				return new WP_Error(
					'ahentic_describe_image_parse',
					__( 'AI returned an unexpected description format.', 'ahentic' )
				);
			}
			return array(
				'description'         => (string) $data['description'],
				'alt_text_suggestion' => (string) $data['alt_text_suggestion'],
			);
		}

		/**
		 * @param mixed $file File DTO.
		 * @return array|\WP_Error
		 */
		private static function file_dto_to_generated( $file ) {
			if ( is_wp_error( $file ) ) {
				return self::image_gen_provider_error( $file );
			}
			if ( ! is_object( $file ) || ! method_exists( $file, 'getDataUri' ) ) {
				return new WP_Error( 'ahentic_image_gen_empty', __( 'No image was generated.', 'ahentic' ) );
			}
			$data_uri = $file->getDataUri();
			$mime     = method_exists( $file, 'getMimeType' ) ? (string) $file->getMimeType() : 'image/png';
			if ( ! is_string( $data_uri ) || '' === $data_uri ) {
				return new WP_Error( 'ahentic_image_gen_empty', __( 'Generated image had no data URI.', 'ahentic' ) );
			}
			return array(
				'data_uri'  => $data_uri,
				'mime_type' => $mime ? $mime : 'image/png',
				'width'     => 0,
				'height'    => 0,
			);
		}

		/**
		 * @param mixed $err Error or throwable.
		 * @return \WP_Error
		 */
		private static function vision_provider_error( $err ) {
			if ( is_wp_error( $err ) ) {
				return new WP_Error(
					'ahentic_vision_unsupported',
					__( 'Your configured AI provider doesn\'t support image understanding — check Settings → Connectors.', 'ahentic' ),
					array( 'previous' => $err->get_error_message() )
				);
			}
			return new WP_Error(
				'ahentic_vision_unsupported',
				__( 'Your configured AI provider doesn\'t support image understanding — check Settings → Connectors.', 'ahentic' ),
				array( 'previous' => method_exists( $err, 'getMessage' ) ? $err->getMessage() : '' )
			);
		}

		/**
		 * @param mixed $err Throwable or WP_Error.
		 * @return \WP_Error
		 */
		private static function image_gen_provider_error( $err ) {
			$previous = '';
			if ( is_wp_error( $err ) ) {
				$previous = $err->get_error_message();
			} elseif ( is_object( $err ) && method_exists( $err, 'getMessage' ) ) {
				$previous = $err->getMessage();
			}

			$message = __( 'Your configured AI provider doesn\'t support image generation — check Settings → Connectors.', 'ahentic' );
			if ( '' !== $previous ) {
				/* translators: %s: underlying provider/network error */
				$message = sprintf( __( 'Image generation failed: %s', 'ahentic' ), $previous );
			}

			return new WP_Error(
				'ahentic_image_gen_failed',
				$message,
				array( 'previous' => $previous )
			);
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
			$system      = class_exists( 'Ahentic_Prompt_Assembler' )
				? Ahentic_Prompt_Assembler::ensure_utf8( (string) $system )
				: (string) $system;
			$user        = class_exists( 'Ahentic_Prompt_Assembler' )
				? Ahentic_Prompt_Assembler::ensure_utf8( (string) $user )
				: (string) $user;
			$prompt_text = self::flatten_prompt( $history, $user );
			if ( class_exists( 'Ahentic_Prompt_Assembler' ) ) {
				$prompt_text = Ahentic_Prompt_Assembler::ensure_utf8( $prompt_text );
			}
			$max_tokens = self::resolve_max_output_tokens( $options );

			try {
				$make_builder = static function () use ( $prompt_text, $system ) {
					$builder = wp_ai_client_prompt( $prompt_text );
					if ( ! is_object( $builder ) ) {
						return $builder;
					}
					$builder = self::apply_request_timeout( $builder, true );
					return $builder->using_system_instruction( (string) $system );
				};

				$builder = $make_builder();
				if ( ! is_object( $builder ) ) {
					return new WP_Error( 'ahentic_ai_api', __( 'Unexpected AI client API shape.', 'ahentic' ) );
				}

				/*
				 * Core maps “no candidate models for these options” to prompt_invalid_argument
				 * with a misleading “doesn't support text_generation” message (see php-ai-client
				 * model selection). Probe before generate: some connectors advertise
				 * text_generation but not maxTokens. Do not pay a second generate_text_result().
				 */
				if ( $max_tokens > 0 ) {
					$with_max  = $builder->using_max_tokens( $max_tokens );
					$supported = self::builder_supports_text_generation( $with_max );
					if ( false !== $supported ) {
						$builder = $with_max;
					} else {
						$fresh = $make_builder();
						if ( is_object( $fresh ) ) {
							$builder = $fresh;
						}
					}
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
		 * @return array{ text: string, debug: ?array, truncated: bool, truncated_key: string }
		 */
		public static function extract_debug_block( $text ) {
			$text          = (string) $text;
			$debug         = null;
			$truncated     = false;
			$truncated_key = '';

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

				// A block cut off at the output limit has no closing brace at all, so the
				// pattern above cannot match. Rebuild the largest valid prefix instead of
				// throwing away a whole generation.
				if ( null === $debug && preg_match( '/<<<\s*AHENTIC_DEBUG\s*(\{[\s\S]*)$/u', $text, $m ) ) {
					$salvaged = self::salvage_truncated_json( $m[1] );
					if ( is_array( $salvaged['debug'] ) ) {
						$debug         = $salvaged['debug'];
						$truncated     = true;
						$truncated_key = $salvaged['incomplete_key'];
					} elseif ( '' !== $salvaged['incomplete_key'] ) {
						// Nothing recoverable, but we still know where it stopped.
						$truncated     = true;
						$truncated_key = $salvaged['incomplete_key'];
					}
				}

				// Drop everything from the opener onward so markers never reach the UI.
				$text = trim( preg_replace( '/<<<\s*AHENTIC_DEBUG[\s\S]*/u', '', $text, 1 ) );
			}

			// Last resort: strip any leftover markers.
			$text = trim( preg_replace( '/<<<\s*AHENTIC_DEBUG[\s\S]*?(?:AHENTIC_DEBUG>{2,}|$)/u', '', $text ) );
			$text = trim( preg_replace( '/AHENTIC_DEBUG>{2,}/u', '', $text ) );

			// A salvaged block is a partial thought, not a finished one — synthesizing
			// prose from it would claim work that was cut off before it ran.
			if ( '' === $text && is_array( $debug ) && ! $truncated ) {
				$text = self::fallback_reply_from_debug( $debug );
			}

			return array(
				'text'          => $text,
				'debug'         => $debug,
				'truncated'     => $truncated,
				'truncated_key' => $truncated_key,
			);
		}

		/**
		 * The four `next` values the agent loop accepts.
		 *
		 * @var array
		 */
		private static $next_values = array( 'reply', 'ask_user', 'use_tools', 'missing_ability' );

		/**
		 * Common model spellings of `next`, mapped to the accepted values.
		 *
		 * @var array
		 */
		private static $next_aliases = array(
			'use tools'         => 'use_tools',
			'usetools'          => 'use_tools',
			'use_tool'          => 'use_tools',
			'tools'             => 'use_tools',
			'tool'              => 'use_tools',
			'tool_use'          => 'use_tools',
			'tool_calls'        => 'use_tools',
			'call_tools'        => 'use_tools',
			'run_tools'         => 'use_tools',
			'answer'            => 'reply',
			'respond'           => 'reply',
			'response'          => 'reply',
			'final'             => 'reply',
			'final_reply'       => 'reply',
			'finish'            => 'reply',
			'done'              => 'reply',
			'complete'          => 'reply',
			'ask'               => 'ask_user',
			'ask-user'          => 'ask_user',
			'ask user'          => 'ask_user',
			'askuser'           => 'ask_user',
			'question'          => 'ask_user',
			'clarify'           => 'ask_user',
			'missing-ability'   => 'missing_ability',
			'missing ability'   => 'missing_ability',
			'missingability'    => 'missing_ability',
			'missing_abilities' => 'missing_ability',
			'no_ability'        => 'missing_ability',
		);

		/**
		 * Repair a parsed control block whose `next` is missing or misspelled.
		 *
		 * A round trip to the model is the most expensive way to recover a value we can
		 * derive from the block already paid for, so map known spellings or infer from
		 * the rest of the block.
		 *
		 * Truncated blocks are deliberately left alone: one cut off at the output limit
		 * is missing `tools_planned` because of where it stopped, not because the model
		 * intended no tools, so inferring `reply` there would silently drop the work and
		 * inferring `use_tools` could run a half-recovered write. Those stay unusable and
		 * fall through to the caller's retry.
		 *
		 * @param mixed  $debug         Parsed debug payload.
		 * @param bool   $truncated     Whether the block was cut off mid-JSON.
		 * @param string $truncated_key Top-level key whose value was lost.
		 * @return array{ debug: mixed, changed: bool, from: string, to: string, reason: string }
		 */
		public static function normalize_debug_next( $debug, $truncated = false, $truncated_key = '' ) {
			$unchanged = array(
				'debug'   => $debug,
				'changed' => false,
				'from'    => '',
				'to'      => '',
				'reason'  => '',
			);

			if ( ! is_array( $debug ) || empty( $debug ) ) {
				return $unchanged;
			}

			$raw = isset( $debug['next'] ) && is_scalar( $debug['next'] ) ? (string) $debug['next'] : '';
			if ( in_array( $raw, self::$next_values, true ) ) {
				return $unchanged;
			}

			$key    = preg_replace( '/[^a-z_ -]/', '', strtolower( trim( $raw ) ) );
			$next   = '';
			$reason = '';

			if ( in_array( $key, self::$next_values, true ) ) {
				// Right value, wrong casing or padding.
				$next   = $key;
				$reason = 'alias';
			} elseif ( isset( self::$next_aliases[ $key ] ) ) {
				$next   = self::$next_aliases[ $key ];
				$reason = 'alias';
			} else {
				$tools = isset( $debug['tools_planned'] ) && is_array( $debug['tools_planned'] )
					? $debug['tools_planned']
					: array();

				if ( ! empty( $debug['ability_needed'] ) ) {
					$next   = 'missing_ability';
					$reason = 'inferred_ability_needed';
				} elseif ( ! empty( $tools ) && 'tools_planned' !== $truncated_key ) {
					$next   = 'use_tools';
					$reason = 'inferred_tools_planned';
				} elseif ( ! $truncated ) {
					$next   = 'reply';
					$reason = 'inferred_no_tools';
				}
			}

			if ( '' === $next ) {
				return $unchanged;
			}

			$debug['next'] = $next;

			return array(
				'debug'   => $debug,
				'changed' => true,
				'from'    => $raw,
				'to'      => $next,
				'reason'  => $reason,
			);
		}

		/**
		 * Rebuild a parseable object from a control block cut off mid-JSON.
		 *
		 * Walks the partial JSON tracking string / escape state and container depth,
		 * rewinds to the last complete top-level member, and closes the object. The
		 * member that was interrupted is dropped rather than half-recovered, so a
		 * salvaged block never carries a partial tool call — callers get
		 * `incomplete_key` to tell what was lost.
		 *
		 * @param string $raw Partial JSON starting at `{`.
		 * @return array{ debug: ?array, incomplete_key: string }
		 */
		private static function salvage_truncated_json( $raw ) {
			$none = array(
				'debug'          => null,
				'incomplete_key' => '',
			);

			$raw = trim( (string) $raw );
			$len = strlen( $raw );
			if ( 0 === $len || '{' !== $raw[0] ) {
				return $none;
			}

			$stack         = array();
			$in_string     = false;
			$escaped       = false;
			$string_start  = -1;
			$expect_key    = false;
			$current_key   = '';
			$last_complete = -1;

			for ( $i = 0; $i < $len; $i++ ) {
				$ch = $raw[ $i ];

				if ( $in_string ) {
					if ( $escaped ) {
						$escaped = false;
					} elseif ( '\\' === $ch ) {
						$escaped = true;
					} elseif ( '"' === $ch ) {
						$in_string = false;
						if ( 1 === count( $stack ) && $expect_key ) {
							$current_key = substr( $raw, $string_start + 1, $i - $string_start - 1 );
							$expect_key  = false;
						}
					}
					continue;
				}

				if ( '"' === $ch ) {
					$in_string    = true;
					$string_start = $i;
					continue;
				}

				if ( '{' === $ch || '[' === $ch ) {
					$stack[] = $ch;
					if ( 1 === count( $stack ) ) {
						$expect_key = '{' === $ch;
					}
					continue;
				}

				if ( '}' === $ch || ']' === $ch ) {
					array_pop( $stack );
					// A nested value belonging to a top-level key just closed.
					if ( 1 === count( $stack ) ) {
						$last_complete = $i + 1;
						$current_key   = '';
					}
					continue;
				}

				// Top-level separator: everything before it is a complete member.
				if ( ',' === $ch && 1 === count( $stack ) ) {
					$last_complete = $i;
					$current_key   = '';
					$expect_key    = true;
				}
			}

			// Balanced input is not truncated — nothing for this path to repair.
			if ( empty( $stack ) ) {
				return $none;
			}

			if ( $last_complete < 1 ) {
				// Truncated before any member finished; the key name is still useful.
				return array(
					'debug'          => null,
					'incomplete_key' => $current_key,
				);
			}

			$closer = ( '[' === $stack[0] ) ? ']' : '}';
			$parsed = json_decode( substr( $raw, 0, $last_complete ) . $closer, true );

			return array(
				'debug'          => is_array( $parsed ) ? $parsed : null,
				'incomplete_key' => $current_key,
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
				'/^(i co(?:uld|n)\b|i will\b|i\'ll\b|i\'m\b|i am\b|let me\b|next i\b|checking\b|looking\b|searching\b|planning\b|now i\b|i need to\b|i should\b)/i',
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
				if ( class_exists( 'Ahentic_Prompt_Assembler' ) ) {
					$content = Ahentic_Prompt_Assembler::ensure_utf8( $content );
				}
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

			$raw           = $text;
			$extracted     = self::extract_debug_block( $text );
			$text          = $extracted['text'];
			$debug         = $extracted['debug'];
			$truncated     = ! empty( $extracted['truncated'] );
			$truncated_key = isset( $extracted['truncated_key'] ) ? (string) $extracted['truncated_key'] : '';

			$normalized       = self::normalize_debug_next( $debug, $truncated, $truncated_key );
			$debug            = $normalized['debug'];
			$debug_normalized = $normalized['changed']
				? array(
					'from'   => $normalized['from'],
					'to'     => $normalized['to'],
					'reason' => $normalized['reason'],
				)
				: null;

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
						'text'             => '',
						'tokens_in'        => $in,
						'tokens_out'       => $out,
						'tokens_total'     => $total > 0 ? $total : ( $in + $out ),
						'model'            => $model,
						'debug'            => is_array( $debug ) ? $debug : null,
						'truncated'        => $truncated,
						'truncated_key'    => $truncated_key,
						'debug_normalized' => $debug_normalized,
					);
				}

				return new WP_Error( 'ahentic_ai_empty', __( 'The model returned an empty response.', 'ahentic' ) );
			}

			return array(
				'text'             => $text,
				'tokens_in'        => $in,
				'tokens_out'       => $out,
				'tokens_total'     => $total > 0 ? $total : ( $in + $out ),
				'model'            => $model,
				'debug'            => $debug,
				'truncated'        => $truncated,
				'truncated_key'    => $truncated_key,
				'debug_normalized' => $debug_normalized,
			);
		}
	}
}
