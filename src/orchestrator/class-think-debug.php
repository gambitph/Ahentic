<?php
/**
 * Think / AHENTIC_DEBUG recovery for one agent step.
 *
 * Deep module: run a think until a usable control block appears.
 * Primary interface: run_think(), apply_live_progress(), finalize_result_text(),
 * should_finish_without_tools(), publish_thought_process(), queue_missing_ability().
 * The Orchestrator must call these — do not reimplement debug retry at call sites.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Think_Debug' ) ) {
	/**
	 * Think/debug module: run a think until a usable control block appears.
	 *
	 * Primary interface: run_think(), apply_live_progress(), finalize_result_text(),
	 * should_finish_without_tools(), publish_thought_process(), queue_missing_ability().
	 * Pure helpers (is_usable, signals_missing_ability, normalize_ability_name,
	 * progress_label_from_debug, disposition_for_debug, resolve_thought_process_for_chat)
	 * are part of the test surface; trace_debug / progress_label_from_debug are also
	 * used from Orchestrator::run_llm_phase.
	 */
	class Ahentic_Think_Debug {
		/** Max LLM attempts to obtain a valid AHENTIC_DEBUG block per think phase. */
		const MAX_DEBUG_ATTEMPTS = 3;

		/**
		 * Deep entry: progress label + LLM think with AHENTIC_DEBUG recovery.
		 *
		 * @param int $session_id Session ID.
		 * @return array{result: array, label: string}|\WP_Error
		 */
		public static function run_think( $session_id ) {
			$label  = self::progress_label_for_think( $session_id );
			$result = self::run_with_debug( $session_id, $label );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'result' => $result,
				'label'  => $label,
			);
		}

		/**
		 * Surface the control-block intention as the live progress label.
		 *
		 * @param int    $session_id     Session ID.
		 * @param array  $debug          Parsed debug block.
		 * @param string $fallback_label Label when intention/thinking are empty.
		 */
		public static function apply_live_progress( $session_id, $debug, $fallback_label = '' ) {
			Ahentic_Session_Repository::set_progress(
				$session_id,
				self::progress_label_from_debug( $debug, $fallback_label )
			);
		}

		/**
		 * Fill empty user-facing text from debug thinking / intention.
		 *
		 * @param array $result LLM result.
		 * @param array $debug  Parsed debug block.
		 * @return array
		 */
		public static function finalize_result_text( array $result, $debug ) {
			return self::ensure_thought_process_text( $result, $debug );
		}

		/**
		 * Pure post-think branch (no session writes).
		 *
		 * @param mixed $debug Debug payload.
		 * @return string finish_unusable|finish_missing|continue
		 */
		public static function disposition_for_debug( $debug ) {
			if ( ! self::is_usable( $debug ) ) {
				return 'finish_unusable';
			}
			$debug = is_array( $debug ) ? $debug : array();
			if ( self::signals_missing_ability( $debug ) ) {
				return 'finish_missing';
			}
			return 'continue';
		}

		/**
		 * Whether the step should finish without running tools (queues missing-ability requests).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $debug      Parsed debug block.
		 * @return bool
		 */
		public static function should_finish_without_tools( $session_id, $debug ) {
			$disposition = self::disposition_for_debug( $debug );
			if ( 'finish_missing' === $disposition ) {
				self::queue_missing_abilities( $session_id, is_array( $debug ) ? $debug : array() );
			}
			return 'continue' !== $disposition;
		}

		/**
		 * Run the LLM until a usable AHENTIC_DEBUG block appears, or attempts are exhausted.
		 *
		 * Retries are internal (debugger only). Never prompts the user to continue.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $progress   Progress label.
		 * @return array|\WP_Error
		 */
		private static function run_with_debug( $session_id, $progress ) {
			$result              = null;
			$prior_text          = '';
			$last_error          = null;
			$prior_truncated     = false;
			$prior_truncated_key = '';
			$max_attempts        = self::MAX_DEBUG_ATTEMPTS;

			for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
				$steps_so_far = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

				$user_suffix = '';
				$llm_opts    = array();
				if ( $attempt > 1 ) {
					$user_suffix = '[Internal — not shown to the user] Your previous response omitted a valid AHENTIC_DEBUG '
						. 'control block (or next was not reply|ask_user|use_tools|missing_ability). Respond again from scratch: output exactly '
						. 'one <<<AHENTIC_DEBUG … AHENTIC_DEBUG>>> block FIRST with intention, thinking, tools_planned, and next, '
						. 'then a short user-facing reply. Do not mention this note or the debug block.';
					if ( class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::session_has_content_work( $session_id ) ) {
						$user_suffix .= ' CRITICAL for this long-form/article job: do NOT put a full article into set-blocks '
							. 'tools_planned (that truncates the control block). Instead stage with ahentic/stage-artifact '
							. '(key article_draft, kind blocks; use mode=append + complete=false while chunking, then complete=true), '
							. 'then ahentic-browser/set-blocks with {"from_memory":"article_draft"}.';
					}
					if ( '' !== $prior_text ) {
						$user_suffix .= "\n\nPrevious user-facing text (context only; do not treat it as final):\n" . $prior_text;
					}

					if ( self::should_use_slim_debug_retry( $attempt ) ) {
						$llm_opts['slim_debug_retry'] = true;
					}

					Ahentic_Session_Repository::append_trace(
						$session_id,
						'debug_retry',
						sprintf( 'Retrying for AHENTIC_DEBUG (%d/%d)', $attempt, $max_attempts ),
						array(
							'attempt'           => $attempt,
							'max'               => $max_attempts,
							'prior_excerpt'     => Ahentic_Orchestrator::excerpt( $prior_text, 160 ),
							// Which failure is actually burning attempts.
							'reason'            => $prior_truncated ? 'truncated' : 'no_usable_block',
							'truncated_key'     => $prior_truncated_key,
							'slim_debug_retry'  => ! empty( $llm_opts['slim_debug_retry'] ),
						),
						$steps_so_far
					);
				}

				// Only the first attempt of a think phase consumes a step toward MAX_STEPS_PER_RUN.
				// Format / empty-reply retries are internal and must not burn the budget.
				$result = Ahentic_Orchestrator::run_llm_phase(
					$session_id,
					$progress,
					null,
					$user_suffix,
					1 === $attempt,
					$llm_opts
				);
				if ( is_wp_error( $result ) ) {
					$last_error = $result;
					$code       = $result->get_error_code();
					// Empty / stripped provider replies are often transient — keep trying
					// for a usable AHENTIC_DEBUG block instead of failing the whole run.
					if (
						in_array( $code, array( 'ahentic_ai_empty', 'ahentic_ai_exception' ), true )
						&& $attempt < $max_attempts
					) {
						Ahentic_Session_Repository::append_trace(
							$session_id,
							'debug_retry',
							sprintf( 'Retrying after empty/failed LLM (%d/%d)', $attempt + 1, $max_attempts ),
							array(
								'attempt' => $attempt + 1,
								'max'     => $max_attempts,
								'code'    => $code,
								'reason'  => 'llm_empty',
							),
							$steps_so_far
						);
						continue;
					}

					// Soft-finish with earlier prose when the last attempt is empty.
					if ( 'ahentic_ai_empty' === $code && '' !== trim( $prior_text ) ) {
						Ahentic_Session_Repository::append_trace(
							$session_id,
							'debug_retries_exhausted',
							sprintf( 'AHENTIC_DEBUG still missing after %d attempts (using prior text)', $max_attempts ),
							array(
								'attempts' => $max_attempts,
								'code'     => $code,
							),
							$steps_so_far
						);
						return array(
							'text'         => $prior_text,
							'tokens_in'    => 0,
							'tokens_out'   => 0,
							'tokens_total' => 0,
							'model'        => '',
							'debug'        => null,
						);
					}

					return $result;
				}

				$debug = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : null;
				if ( self::is_usable( $debug ) ) {
					if ( $attempt > 1 ) {
						$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
						Ahentic_Session_Repository::append_trace(
							$session_id,
							'debug_recovered',
							sprintf( 'AHENTIC_DEBUG recovered on attempt %d', $attempt ),
							array(
								'attempt' => $attempt,
								'next'    => (string) $debug['next'],
							),
							$step
						);
					}
					return $result;
				}

				$text = isset( $result['text'] ) ? (string) $result['text'] : '';
				if ( '' !== trim( $text ) ) {
					$prior_text = $text;
				}
				$prior_truncated     = ! empty( $result['truncated'] );
				$prior_truncated_key = isset( $result['truncated_key'] ) ? (string) $result['truncated_key'] : '';
				$last_error          = null;
			}

			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'debug_retries_exhausted',
				sprintf( 'AHENTIC_DEBUG still missing after %d attempts', $max_attempts ),
				array(
					'attempts'      => $max_attempts,
					'reason'        => $prior_truncated ? 'truncated' : 'no_usable_block',
					'truncated_key' => $prior_truncated_key,
				),
				$step
			);

			if ( is_wp_error( $last_error ) && '' === trim( $prior_text ) ) {
				return $last_error;
			}

			if ( ( null === $result || ! is_array( $result ) ) && '' !== trim( $prior_text ) ) {
				return array(
					'text'         => $prior_text,
					'tokens_in'    => 0,
					'tokens_out'   => 0,
					'tokens_total' => 0,
					'model'        => '',
					'debug'        => null,
				);
			}

			return $result;
		}

		/**
		 * Whether this debug recovery attempt should use a slim LLM prompt.
		 *
		 * Attempt 1 is a full think. Attempt 2+ only need the control block recovered —
		 * not a second full system/history backpack.
		 *
		 * @param int $attempt 1-based attempt number.
		 * @return bool
		 */
		public static function should_use_slim_debug_retry( $attempt ) {
			return (int) $attempt > 1;
		}

		/**
		 * Whether parsed debug can drive the agent loop.
		 *
		 * @param mixed $debug Debug payload.
		 * @return bool
		 */
		public static function is_usable( $debug ) {
			if ( ! is_array( $debug ) || empty( $debug ) ) {
				return false;
			}
			$next = isset( $debug['next'] ) ? (string) $debug['next'] : '';
			return in_array( $next, array( 'reply', 'ask_user', 'use_tools', 'missing_ability' ), true );
		}

		/**
		 * Whether debug indicates a needed ability is not available yet.
		 *
		 * @param array $debug Debug block.
		 * @return bool
		 */
		public static function signals_missing_ability( array $debug ) {
			$next = isset( $debug['next'] ) ? (string) $debug['next'] : '';
			if ( 'missing_ability' === $next ) {
				return true;
			}
			// Soft signal: final reply that still names a needed ability.
			if ( in_array( $next, array( 'reply', 'ask_user' ), true ) && ! empty( $debug['ability_needed'] ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Queue capability requests from a missing_ability debug block.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $debug      Debug block.
		 */
		private static function queue_missing_abilities( $session_id, array $debug ) {
			$step  = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			$names = self::resolve_needed_ability_names( $debug );

			if ( empty( $names ) ) {
				$names = array( 'ahentic/new-ability' );
			}

			foreach ( $names as $name ) {
				self::queue_missing_ability( $session_id, $name, $debug, $step );
			}
		}

		/**
		 * Ability names the model says it needs (normalized).
		 *
		 * @param array $debug Debug block.
		 * @return string[]
		 */
		private static function resolve_needed_ability_names( array $debug ) {
			$names = array();

			if ( ! empty( $debug['ability_needed'] ) ) {
				$needed = $debug['ability_needed'];
				if ( is_string( $needed ) ) {
					$names[] = self::normalize_ability_name( $needed );
				} elseif ( is_array( $needed ) ) {
					foreach ( $needed as $item ) {
						if ( is_string( $item ) ) {
							$names[] = self::normalize_ability_name( $item );
						} elseif ( is_array( $item ) ) {
							$raw = '';
							if ( isset( $item['name'] ) ) {
								$raw = (string) $item['name'];
							} elseif ( isset( $item['ability'] ) ) {
								$raw = (string) $item['ability'];
							}
							if ( '' !== $raw ) {
								$names[] = self::normalize_ability_name( $raw );
							}
						}
					}
				}
			}

			$planned = Ahentic_Orchestrator::normalize_tool_calls( isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array() );
			$available = Ahentic_Abilities::available_for_agent();
			foreach ( $planned as $call ) {
				if ( empty( $call['name'] ) ) {
					continue;
				}
				if ( ! in_array( $call['name'], $available, true ) ) {
					$names[] = (string) $call['name'];
				}
			}

			$names = array_values(
				array_unique(
					array_filter(
						$names,
						static function ( $n ) {
							return is_string( $n ) && '' !== $n;
						}
					)
				)
			);

			return $names;
		}

		/**
		 * Normalize a freeform ability label to ahentic/slug form.
		 *
		 * @param string $name Raw name.
		 * @return string
		 */
		public static function normalize_ability_name( $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				return '';
			}

			if ( false !== strpos( $name, '/' ) ) {
				return strtolower( $name );
			}

			$slug = strtolower( $name );
			$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
			$slug = trim( (string) $slug, '-' );
			if ( '' === $slug ) {
				return 'ahentic/new-ability';
			}

			return 'ahentic/' . $slug;
		}

		/**
		 * Build + queue one missing-ability capability request (deduped by repository).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $ability    Ability name.
		 * @param array  $debug      Debug block.
		 * @param int    $step       Trace step.
		 */
		public static function queue_missing_ability( $session_id, $ability, array $debug, $step = 0 ) {
			if ( ! class_exists( 'Ahentic_Capability_Request' ) ) {
				return;
			}

			$ability = self::normalize_ability_name( $ability );
			if ( '' === $ability ) {
				return;
			}

			// Skip if this ability is actually available.
			if ( in_array( $ability, Ahentic_Abilities::available_for_agent(), true ) ) {
				return;
			}

			$context = self::raw_context_for_capability_request( $session_id, $debug );
			$request = Ahentic_Capability_Request::build( $ability, $context );
			if ( empty( $request ) ) {
				return;
			}

			Ahentic_Session_Repository::queue_capability_request( $session_id, $request );

			if ( ! empty( $request['tokens_total'] ) ) {
				Ahentic_Session_Repository::add_tokens(
					$session_id,
					isset( $request['tokens_in'] ) ? (int) $request['tokens_in'] : 0,
					isset( $request['tokens_out'] ) ? (int) $request['tokens_out'] : 0,
					(int) $request['tokens_total']
				);
			}

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'capability_request_goal',
				'Summarized capability request goal',
				array(
					'ability' => $ability,
					'goal'    => isset( $request['goal'] ) ? $request['goal'] : '',
					'raw'     => isset( $request['goal_raw'] ) ? Ahentic_Orchestrator::excerpt( (string) $request['goal_raw'], 120 ) : '',
				),
				(int) $step
			);
		}

		/**
		 * Persist thinking / tools_planned into the trace.
		 *
		 * @param int        $session_id Session ID.
		 * @param array|null $debug      Debug block.
		 * @param int        $step       Step.
		 */
		public static function trace_debug( $session_id, $debug, $step ) {
			if ( $debug ) {
				$intention = isset( $debug['intention'] ) ? (string) $debug['intention'] : '';
				$thinking  = isset( $debug['thinking'] ) ? (string) $debug['thinking'] : '';
				$planned   = isset( $debug['tools_planned'] ) && is_array( $debug['tools_planned'] ) ? $debug['tools_planned'] : array();
				$next      = isset( $debug['next'] ) ? (string) $debug['next'] : '';
				$plan      = Ahentic_Plan::normalize_from_debug( $debug );
				// Match the live status string so debugger + sidebar show the same step text.
				$summary = self::progress_label_from_debug( $debug, '' );
				if ( '' === $summary ) {
					$summary = 'Model thinking';
				}

				$data = array(
					'intention'     => $intention,
					'thinking'      => $thinking,
					'tools_planned' => array_values( $planned ),
					'next'          => $next,
				);
				if ( null !== $plan ) {
					$data['plan'] = $plan;
				}

				Ahentic_Session_Repository::append_trace(
					$session_id,
					'llm_thinking',
					$summary,
					$data,
					$step
				);
				return;
			}

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'llm_thinking',
				'Thinking block not provided by model',
				array(
					'intention'     => '',
					'thinking'      => '',
					'tools_planned' => array(),
					'next'          => '',
					'missing'       => true,
				),
				$step
			);
		}

		/**
		 * Context for summarizing a capability-request action phrase.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $debug      Debug block.
		 * @return string
		 */
		private static function raw_context_for_capability_request( $session_id, $debug = array() ) {
			$parts = array();

			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				if ( isset( $entries[ $i ]['role'] ) && 'user' === $entries[ $i ]['role'] ) {
					$user = isset( $entries[ $i ]['content'] ) ? trim( (string) $entries[ $i ]['content'] ) : '';
					if ( '' !== $user ) {
						$parts[] = 'User: ' . $user;
					}
					break;
				}
			}

			if ( is_array( $debug ) && ! empty( $debug['intention'] ) ) {
				$parts[] = 'Agent intention: ' . trim( (string) $debug['intention'] );
			}

			return implode( "\n", $parts );
		}

		/**
		 * Whether a progress label is a generic phase placeholder (not a real step).
		 *
		 * @param string $label Progress label.
		 * @return bool
		 */
		private static function is_generic_progress_label( $label ) {
			$label = trim( (string) $label );
			if ( '' === $label ) {
				return true;
			}

			$generic = array(
				__( 'Planning next steps…', 'ahentic' ),
				__( 'Reviewing results…', 'ahentic' ),
				__( 'Starting…', 'ahentic' ),
				__( 'Finishing…', 'ahentic' ),
				__( 'Thinking…', 'ahentic' ),
				__( 'Retrying…', 'ahentic' ),
			);

			return in_array( $label, $generic, true );
		}

		/**
		 * Label to show while waiting on the LLM — prefer the last real step within this run.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function progress_label_for_think( $session_id ) {
			$fallback = __( 'Planning next steps…', 'ahentic' );
			// First step of a new run — never reuse a prior-run intention/tool label.
			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			if ( $step < 1 ) {
				return $fallback;
			}

			$current = Ahentic_Session_Repository::get_progress( $session_id );
			$label   = is_array( $current ) && ! empty( $current['label'] )
				? (string) $current['label']
				: '';

			if ( '' !== $label && ! self::is_generic_progress_label( $label ) ) {
				return $label;
			}

			return $fallback;
		}

		/**
		 * Fill empty user-facing text from debug thinking / intention (final replies).
		 *
		 * @param array $result LLM result.
		 * @param array $debug  Parsed debug block.
		 * @return array
		 */
		private static function ensure_thought_process_text( array $result, array $debug ) {
			$text = isset( $result['text'] ) ? trim( (string) $result['text'] ) : '';
			if ( '' !== $text ) {
				return $result;
			}
			if ( empty( $debug ) ) {
				return $result;
			}
			$fallback = Ahentic_AI::fallback_reply_from_debug( $debug );
			if ( '' === trim( (string) $fallback ) ) {
				return $result;
			}
			$result['text'] = $fallback;
			return $result;
		}

		/**
		 * Prose to show in the sidebar for this think step.
		 * Prefers debug.thinking (thought process), then reply text, then intention.
		 *
		 * @param array $result LLM result.
		 * @param array $debug  Parsed debug block.
		 * @return string
		 */
		public static function resolve_thought_process_for_chat( array $result, array $debug ) {
			$thinking  = is_array( $debug ) && isset( $debug['thinking'] ) ? trim( (string) $debug['thinking'] ) : '';
			$text      = isset( $result['text'] ) ? trim( (string) $result['text'] ) : '';
			$intention = is_array( $debug ) && isset( $debug['intention'] ) ? trim( (string) $debug['intention'] ) : '';

			if ( '' !== $thinking ) {
				return $thinking;
			}
			if ( '' !== $text ) {
				return $text;
			}
			if ( '' !== $intention ) {
				return $intention;
			}
			return '';
		}

		/**
		 * Publish ephemeral thought process for the sidebar (not a durable chat entry).
		 *
		 * @param int    $session_id Session ID.
		 * @param array  $result     LLM result.
		 * @param array  $debug      Parsed debug block.
		 */
		public static function publish_thought_process( $session_id, array $result, array $debug ) {
			$content = self::resolve_thought_process_for_chat( $result, $debug );
			if ( '' === $content ) {
				return;
			}
			Ahentic_Session_Repository::set_thought( $session_id, $content );
			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'thought_process',
				Ahentic_Orchestrator::excerpt( $content, 120 ),
				array(
					'text' => Ahentic_Orchestrator::excerpt( $content, 400 ),
				),
				$step
			);
		}

		/**
		 * User-facing progress label from the model's intention / thinking.
		 *
		 * @param array  $debug    Debug block.
		 * @param string $fallback Label when reasoning is missing.
		 * @return string
		 */
		public static function progress_label_from_debug( $debug, $fallback = '' ) {
			if ( ! is_array( $debug ) ) {
				return (string) $fallback;
			}

			$intention = isset( $debug['intention'] ) ? trim( (string) $debug['intention'] ) : '';
			if ( '' !== $intention ) {
				return self::format_progress_label( $intention );
			}

			$thinking = isset( $debug['thinking'] ) ? trim( (string) $debug['thinking'] ) : '';
			if ( '' !== $thinking ) {
				// Prefer the first sentence so the status stays short.
				$parts = preg_split( '/(?<=[.!?])\s+/', $thinking, 2 );
				$first = is_array( $parts ) && ! empty( $parts[0] ) ? $parts[0] : $thinking;
				return self::format_progress_label( $first, 80 );
			}

			return (string) $fallback;
		}

		/**
		 * Normalize a free-text progress phrase (ellipsis, length, capitalization).
		 *
		 * @param string $text Raw phrase.
		 * @param int    $max  Max length before ellipsis.
		 * @return string
		 */
		private static function format_progress_label( $text, $max = 72 ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
			$text = rtrim( $text, " \t." );
			$text = preg_replace( '/…+$/u', '', $text );
			$text = trim( (string) $text );
			if ( '' === $text ) {
				return '';
			}

			if ( function_exists( 'mb_strtoupper' ) && function_exists( 'mb_substr' ) ) {
				$first = mb_strtoupper( mb_substr( $text, 0, 1 ) );
				$rest  = mb_substr( $text, 1 );
				$text  = $first . $rest;
			} else {
				$text = ucfirst( $text );
			}

			if ( strlen( $text ) > $max ) {
				$text = rtrim( substr( $text, 0, $max - 1 ) );
			}

			return $text . '…';
		}

	}
}
