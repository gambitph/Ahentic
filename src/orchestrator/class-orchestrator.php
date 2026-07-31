<?php
/**
 * PHP Agent Orchestrator — step loop for sidebar (and future Automations).
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Orchestrator' ) ) {
	/**
	 * Plans and runs multi-step agent work for a session.
	 */
	class Ahentic_Orchestrator {
		const MAX_HISTORY_TURNS = 40;
		const MAX_TOOL_PROGRESS = 5;
		const MAX_STEPS_PER_RUN = 12;
		/** Max LLM attempts to obtain a valid AHENTIC_DEBUG block per think phase. */
		const MAX_DEBUG_ATTEMPTS = 3;

		/**
		 * Accept a user message and start a run (async — sidebar polls for progress).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $content    User text.
		 * @param string $mode       Optional mode override (agent|ask).
		 * @return array|\WP_Error Session REST payload.
		 */
		public static function handle_user_message( $session_id, $content, $mode = '' ) {
			$post = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$content = trim( wp_unslash( (string) $content ) );
			if ( '' === $content ) {
				return new WP_Error( 'ahentic_empty_message', __( 'Message cannot be empty.', 'ahentic' ), array( 'status' => 400 ) );
			}

			$status = Ahentic_Session_Repository::get_status( $session_id );
			if ( in_array( $status, array( Ahentic_Session_Repository::STATUS_RUNNING, Ahentic_Session_Repository::STATUS_AWAITING_HUMAN, Ahentic_Session_Repository::STATUS_AWAITING_BROWSER ), true ) ) {
				return new WP_Error(
					'ahentic_session_busy',
					__( 'This session is still working. Wait for it to finish or cancel it.', 'ahentic' ),
					array( 'status' => 409 )
				);
			}

			if ( $mode ) {
				Ahentic_Session_Repository::set_mode( $session_id, $mode );
			}

			Ahentic_Session_Repository::clear_error( $session_id );
			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'user',
					'content' => $content,
				)
			);

			Ahentic_Session_Repository::maybe_set_auto_title( $session_id, $content );
			update_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, 0 );
			Ahentic_Session_Repository::consume_capability_requests( $session_id );
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
			Ahentic_Session_Repository::set_progress( $session_id, __( 'Planning next steps…', 'ahentic' ) );

			$mode_now = Ahentic_Session_Repository::get_mode( $session_id );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_start',
				sprintf( 'Run started (%s)', $mode_now ),
				array(
					'mode'    => $mode_now,
					'message' => self::excerpt( $content, 160 ),
				)
			);

			// Return immediately; run continues via queue + shutdown so the sidebar can poll progress.
			Ahentic_Step_Queue::enqueue_step( $session_id );
			Ahentic_Step_Queue::schedule_interactive_run( $session_id );

			return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
		}

		/**
		 * Continue a stalled run (Local / no cron fallback).
		 *
		 * @param int $session_id Session ID.
		 * @return array|\WP_Error
		 */
		public static function continue_run( $session_id ) {
			$post = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$status = Ahentic_Session_Repository::get_status( $session_id );
			if ( Ahentic_Session_Repository::STATUS_RUNNING !== $status ) {
				return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
			}

			self::process_step( $session_id );
			return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
		}

		/**
		 * One queued agent step: think → optionally run tools → continue or finish.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function process_step( $session_id ) {
			$session_id = (int) $session_id;
			$post       = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return;
			}

			if ( Ahentic_Session_Repository::STATUS_RUNNING !== Ahentic_Session_Repository::get_status( $session_id ) ) {
				return;
			}

			if ( ! Ahentic_Step_Queue::try_claim_run( $session_id ) ) {
				return;
			}

			$should_continue = false;

			try {
				$should_continue = self::run_one_step( $session_id );
			} finally {
				Ahentic_Step_Queue::release_run( $session_id );
			}

			if ( $should_continue && Ahentic_Session_Repository::STATUS_RUNNING === Ahentic_Session_Repository::get_status( $session_id ) ) {
				Ahentic_Step_Queue::enqueue_step( $session_id );
				Ahentic_Step_Queue::schedule_interactive_run( $session_id );
			}
		}

		/**
		 * Think once; run tools if requested; return whether another step is needed.
		 *
		 * @param int $session_id Session ID.
		 * @return bool True to enqueue another process_step.
		 */
		private static function run_one_step( $session_id ) {
			if ( Ahentic_Session_Repository::STATUS_RUNNING !== Ahentic_Session_Repository::get_status( $session_id ) ) {
				return false;
			}

			$steps = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			if ( $steps >= self::MAX_STEPS_PER_RUN ) {
				self::fail_run(
					$session_id,
					new WP_Error(
						'ahentic_max_steps',
						__( 'This run hit the maximum number of steps. Try a simpler request or start a new message.', 'ahentic' )
					)
				);
				return false;
			}

			$mode = Ahentic_Session_Repository::get_mode( $session_id );

			// Keep the last meaningful step label (tool / intention) while the model thinks.
			$think_label = self::progress_label_for_think( $session_id );

			$result = self::run_llm_with_debug(
				$session_id,
				$think_label,
				self::system_prompt( $mode )
			);

			if ( is_wp_error( $result ) ) {
				self::fail_run( $session_id, $result );
				return false;
			}

			$debug = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : array();
			// Surface the same intention the debugger shows under llm_thinking.
			Ahentic_Session_Repository::set_progress(
				$session_id,
				self::progress_label_from_debug( $debug, $think_label )
			);

			// Missing / unusable control block after retries → stop with last prose (do not ask the user).
			if ( ! self::debug_is_usable( $debug ) ) {
				self::finish_with_reply( $session_id, $result, $debug );
				return false;
			}

			$next    = (string) $debug['next'];
			$planned = self::normalize_tool_calls( isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array() );

			// Explicit missing-ability signal (or reply that still names ability_needed).
			if ( self::debug_signals_missing_ability( $debug ) ) {
				self::queue_missing_abilities_from_debug( $session_id, $debug );
				self::finish_with_reply( $session_id, $result, $debug );
				return false;
			}

			$wants_tools = ( 'agent' === $mode ) && ( 'use_tools' === $next );

			if ( ! $wants_tools ) {
				self::finish_with_reply( $session_id, $result, $debug );
				return false;
			}

			if ( empty( $planned ) ) {
				$planned = array(
					array(
						'name'  => Ahentic_Abilities::SNAPSHOT,
						'input' => array(),
					),
				);
			}

			$planned = array_slice( $planned, 0, self::MAX_TOOL_PROGRESS );

			// Show the first tool step immediately (same label the debugger will log).
			$first_tool = isset( $planned[0]['name'] ) ? (string) $planned[0]['name'] : '';
			if ( '' !== $first_tool ) {
				Ahentic_Session_Repository::set_progress(
					$session_id,
					self::progress_label_for_tool( $first_tool, $debug )
				);
			}

			// Optional intermediate assistant note before tools (user-facing prose only).
			// After a failed tool (or near-duplicate prose), keep this as progress — not another chat bubble.
			if ( ! empty( $result['text'] ) ) {
				$omit = self::should_omit_intermediate( $session_id, (string) $result['text'] );
				if ( $omit ) {
					$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
					if ( self::recent_tool_failure( $session_id ) ) {
						$retry_label = __( 'Retrying…', 'ahentic' );
						if ( '' !== $first_tool ) {
							$tool_label  = self::progress_label_for_tool( $first_tool, $debug );
							$retry_label = sprintf(
								/* translators: %s: tool progress label (e.g. Searching the plugin directory…) */
								__( 'Retrying — %s', 'ahentic' ),
								$tool_label
							);
						}
						Ahentic_Session_Repository::set_progress( $session_id, $retry_label, $step );
						Ahentic_Session_Repository::append_trace(
							$session_id,
							'intermediate_omitted',
							'Skipped duplicate/recovery chat bubble',
							array(
								'reason'  => 'tool_failure_recovery',
								'excerpt' => self::excerpt( (string) $result['text'], 120 ),
							),
							$step
						);
					}
				} else {
					Ahentic_Session_Repository::append_entry(
						$session_id,
						array(
							'role'    => 'assistant',
							'content' => $result['text'],
							'meta'    => array(
								'model'        => isset( $result['model'] ) ? $result['model'] : '',
								'debug'        => $debug,
								'intermediate' => true,
							),
						)
					);
				}
			}

			$ran_any = false;
			foreach ( $planned as $call ) {
				$name  = $call['name'];
				$input = $call['input'];

				if ( ! in_array( $name, Ahentic_Abilities::available_for_agent(), true ) ) {
					$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
					Ahentic_Session_Repository::set_progress(
						$session_id,
						self::progress_label_for_tool( $name, $debug ),
						$step
					);
					self::queue_missing_ability( $session_id, $name, $debug, $step );

					$request = null;
					$pending = Ahentic_Session_Repository::get_capability_requests( $session_id );
					foreach ( $pending as $item ) {
						if ( isset( $item['ability'] ) && (string) $item['ability'] === (string) $name ) {
							$request = $item;
							break;
						}
					}
					if ( ! is_array( $request ) ) {
						$request = array();
					}

					$tool_payload = array(
						'ok'      => false,
						'error'   => 'ability_unavailable',
						'message' => sprintf(
							/* translators: %s: ability name */
							__( 'Ability %s is not available in this build yet.', 'ahentic' ),
							$name
						),
					);
					if ( ! empty( $request ) ) {
						$tool_payload['capability_request'] = $request;
						$tool_payload['hint']               = __(
							'This ability is unavailable. Explain what you cannot do yet and any workaround. Do not mention X, Twitter, hashtags, @wpahentic, request cards, or sidebar UI — the product shows a separate button for that.',
							'ahentic'
						);
					}

					Ahentic_Session_Repository::append_entry(
						$session_id,
						array(
							'role'    => 'tool',
							'content' => wp_json_encode(
								$tool_payload,
								JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
							),
							'meta'    => array(
								'ability'            => $name,
								'ok'                 => false,
								'capability_request' => $request,
							),
						)
					);
					Ahentic_Session_Repository::append_trace(
						$session_id,
						'tool_result',
						'Ability unavailable: ' . $name,
						array(
							'ability'            => $name,
							'ok'                 => false,
							'capability_request' => $request,
						),
						$step
					);
					$ran_any = true;
					continue;
				}

				$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

				// Mutating abilities pause for human approval unless already allowed.
				if ( Ahentic_Abilities::requires_hitl( $name ) && ! Ahentic_Session_Repository::hitl_is_preallowed( $session_id, $name ) ) {
					$summary = Ahentic_Abilities::hitl_summary( $name, $input );
					$pending = array(
						'name'    => $name,
						'input'   => $input,
						'summary' => $summary,
						'call_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ahentic_', true ),
					);
					Ahentic_Session_Repository::set_pending_tool( $session_id, $pending );
					Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_AWAITING_HUMAN );
					Ahentic_Session_Repository::set_progress(
						$session_id,
						__( 'Waiting for your approval…', 'ahentic' ),
						$step
					);
					Ahentic_Session_Repository::append_trace(
						$session_id,
						'hitl_pause',
						$summary,
						array(
							'ability' => $name,
							'input'   => $input,
						),
						$step
					);
					return false;
				}

				$label = self::progress_label_for_tool( $name, $debug );
				Ahentic_Session_Repository::set_progress( $session_id, $label, $step );
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'tool_executed',
					$label,
					array(
						'ability' => $name,
						'input'   => $input,
					),
					$step
				);

				$tool_result = Ahentic_Abilities::execute( $name, $input );
				$ok          = ! is_wp_error( $tool_result );
				$payload     = $ok
					? $tool_result
					: array(
						'ok'      => false,
						'error'   => $tool_result->get_error_code(),
						'message' => $tool_result->get_error_message(),
					);

				$content = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $content ) ) {
					$content = '{}';
				}

				Ahentic_Session_Repository::append_entry(
					$session_id,
					array(
						'role'    => 'tool',
						'content' => $content,
						'meta'    => array(
							'ability' => $name,
							'ok'      => $ok,
						),
					)
				);
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'tool_result',
					$ok ? ( 'Result: ' . $name ) : ( 'Error: ' . $name ),
					array(
						'ability' => $name,
						'ok'      => $ok,
						'excerpt' => self::excerpt( $content, 240 ),
					),
					$step
				);
				$ran_any = true;
			}

			if ( ! $ran_any ) {
				self::finish_with_reply( $session_id, $result, $debug );
				return false;
			}

			// Keep the last tool / intention label visible while the next think step starts.
			return true;
		}

		/**
		 * Normalize tools_planned to [ ['name'=>…, 'input'=>[]], … ].
		 *
		 * @param mixed $planned Raw from model.
		 * @return array<int, array{name: string, input: array}>
		 */
		private static function normalize_tool_calls( $planned ) {
			if ( ! is_array( $planned ) ) {
				return array();
			}

			$out = array();
			foreach ( $planned as $item ) {
				if ( is_string( $item ) && '' !== $item ) {
					$out[] = array(
						'name'  => $item,
						'input' => array(),
					);
					continue;
				}
				if ( ! is_array( $item ) ) {
					continue;
				}
				$name = '';
				if ( isset( $item['name'] ) ) {
					$name = (string) $item['name'];
				} elseif ( isset( $item['ability'] ) ) {
					$name = (string) $item['ability'];
				} elseif ( isset( $item['id'] ) ) {
					$name = (string) $item['id'];
				}
				if ( '' === $name ) {
					continue;
				}
				$input = array();
				if ( isset( $item['input'] ) && is_array( $item['input'] ) ) {
					$input = $item['input'];
				} elseif ( isset( $item['args'] ) && is_array( $item['args'] ) ) {
					$input = $item['args'];
				}
				$out[] = array(
					'name'  => $name,
					'input' => $input,
				);
			}

			return $out;
		}

		/**
		 * Run the LLM until a usable AHENTIC_DEBUG block appears, or attempts are exhausted.
		 *
		 * Retries are internal (debugger only). Never prompts the user to continue.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $progress   Progress label.
		 * @param string $system     System prompt.
		 * @return array|\WP_Error
		 */
		private static function run_llm_with_debug( $session_id, $progress, $system ) {
			$result       = null;
			$prior_text   = '';
			$max_attempts = self::MAX_DEBUG_ATTEMPTS;

			for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
				$steps_so_far = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
				if ( $attempt > 1 && $steps_so_far >= self::MAX_STEPS_PER_RUN ) {
					break;
				}

				$user_suffix = '';
				if ( $attempt > 1 ) {
					$user_suffix = '[Internal — not shown to the user] Your previous response omitted a valid AHENTIC_DEBUG '
						. 'control block (or next was not reply|ask_user|use_tools|missing_ability). Respond again from scratch: output exactly '
						. 'one <<<AHENTIC_DEBUG … AHENTIC_DEBUG>>> block FIRST with intention, thinking, tools_planned, and next, '
						. 'then a short user-facing reply. Do not mention this note or the debug block.';
					if ( '' !== $prior_text ) {
						$user_suffix .= "\n\nPrevious user-facing text (context only; do not treat it as final):\n" . $prior_text;
					}

					Ahentic_Session_Repository::append_trace(
						$session_id,
						'debug_retry',
						sprintf( 'Retrying for AHENTIC_DEBUG (%d/%d)', $attempt, $max_attempts ),
						array(
							'attempt'       => $attempt,
							'max'           => $max_attempts,
							'prior_excerpt' => self::excerpt( $prior_text, 160 ),
						),
						$steps_so_far
					);
				}

				$result = self::run_llm_phase( $session_id, $progress, $system, null, $user_suffix );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$debug = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : null;
				if ( self::debug_is_usable( $debug ) ) {
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

				$prior_text = isset( $result['text'] ) ? (string) $result['text'] : '';
			}

			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'debug_retries_exhausted',
				sprintf( 'AHENTIC_DEBUG still missing after %d attempts', $max_attempts ),
				array(
					'attempts' => $max_attempts,
				),
				$step
			);

			return $result;
		}

		/**
		 * Whether parsed debug can drive the agent loop.
		 *
		 * @param mixed $debug Debug payload.
		 * @return bool
		 */
		private static function debug_is_usable( $debug ) {
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
		private static function debug_signals_missing_ability( array $debug ) {
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
		private static function queue_missing_abilities_from_debug( $session_id, array $debug ) {
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

			$planned = self::normalize_tool_calls( isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array() );
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
		private static function normalize_ability_name( $name ) {
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
		private static function queue_missing_ability( $session_id, $ability, array $debug, $step = 0 ) {
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
					'raw'     => isset( $request['goal_raw'] ) ? self::excerpt( (string) $request['goal_raw'], 120 ) : '',
				),
				(int) $step
			);
		}

		/**
		 * One LLM phase with progress + trace.
		 *
		 * @param int         $session_id  Session ID.
		 * @param string      $progress    Progress label.
		 * @param string      $system      System prompt.
		 * @param array|null  $extra_turn  Optional prior assistant turn to inject into history.
		 * @param string      $user_suffix Optional text appended to the user message (retries).
		 * @return array|\WP_Error
		 */
		private static function run_llm_phase( $session_id, $progress, $system, $extra_turn = null, $user_suffix = '' ) {
			$step = Ahentic_Session_Repository::bump_step( $session_id );
			Ahentic_Session_Repository::set_progress( $session_id, $progress, $step );

			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			$built   = self::build_chat_payload( $entries );
			$history = $built['history'];
			$user    = $built['user'];

			if ( is_string( $user_suffix ) && '' !== trim( $user_suffix ) ) {
				$user .= "\n\n" . trim( $user_suffix );
			}

			if ( is_array( $extra_turn ) && ! empty( $extra_turn['content'] ) ) {
				$history[] = array(
					'role'    => 'assistant',
					'content' => (string) $extra_turn['content'],
				);
			}

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'step_start',
				sprintf( 'Step %d — %s', $step, $progress ),
				array(
					'progress'      => $progress,
					'history_turns' => count( $history ),
				),
				$step
			);

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'llm_request',
				'LLM request',
				array(
					'progress'     => $progress,
					'user_excerpt' => self::excerpt( $user, 120 ),
				),
				$step
			);

			$result = Ahentic_AI::complete_chat( $system, $history, $user );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			Ahentic_Session_Repository::add_tokens(
				$session_id,
				$result['tokens_in'],
				$result['tokens_out'],
				$result['tokens_total']
			);

			$debug = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : null;
			self::trace_debug( $session_id, $debug, $step );

			// Prefer the model's intention/thinking over the generic phase label while tools/reply follow.
			if ( is_array( $debug ) ) {
				$reason_label = self::progress_label_from_debug( $debug, '' );
				if ( '' !== $reason_label ) {
					Ahentic_Session_Repository::set_progress( $session_id, $reason_label, $step );
					$progress = $reason_label;
				}
			}

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'llm_response',
				'LLM response',
				array(
					'model'         => $result['model'],
					'tokens_in'     => $result['tokens_in'],
					'tokens_out'    => $result['tokens_out'],
					'tokens_total'  => $result['tokens_total'],
					'reply_excerpt' => self::excerpt( $result['text'], 200 ),
					'progress'      => $progress,
				),
				$step
			);

			return $result;
		}

		/**
		 * Persist thinking / tools_planned into the trace.
		 *
		 * @param int        $session_id Session ID.
		 * @param array|null $debug      Debug block.
		 * @param int        $step       Step.
		 */
		private static function trace_debug( $session_id, $debug, $step ) {
			if ( $debug ) {
				$intention = isset( $debug['intention'] ) ? (string) $debug['intention'] : '';
				$thinking  = isset( $debug['thinking'] ) ? (string) $debug['thinking'] : '';
				$planned   = isset( $debug['tools_planned'] ) && is_array( $debug['tools_planned'] ) ? $debug['tools_planned'] : array();
				$next      = isset( $debug['next'] ) ? (string) $debug['next'] : '';
				// Match the live status string so debugger + sidebar show the same step text.
				$summary = self::progress_label_from_debug( $debug, '' );
				if ( '' === $summary ) {
					$summary = 'Model thinking';
				}

				Ahentic_Session_Repository::append_trace(
					$session_id,
					'llm_thinking',
					$summary,
					array(
						'intention'     => $intention,
						'thinking'      => $thinking,
						'tools_planned' => array_values( $planned ),
						'next'          => $next,
					),
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
		 * Append assistant reply and idle the session.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $result     AI result.
		 * @param array $debug      Debug meta.
		 */
		private static function finish_with_reply( $session_id, array $result, $debug = array() ) {
			Ahentic_Session_Repository::set_progress( $session_id, __( 'Finishing…', 'ahentic' ) );

			$meta = array(
				'model' => isset( $result['model'] ) ? $result['model'] : '',
				'debug' => $debug,
			);

			$requests = Ahentic_Session_Repository::consume_capability_requests( $session_id );
			if ( ! empty( $requests ) ) {
				$meta['capability_requests'] = array_values( $requests );
				// Convenience: first request for simple UIs.
				$meta['capability_request'] = $requests[0];
			}

			$actions = self::suggested_actions_for_session( $session_id );
			if ( ! empty( $actions ) ) {
				$meta['actions'] = $actions;
			}

			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'assistant',
					'content' => $result['text'],
					'meta'    => $meta,
				)
			);

			Ahentic_Session_Repository::mark_idle( $session_id );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_idle',
				'Run idle (final reply)',
				array(
					'reason'                   => 'final_reply',
					'capability_request_count' => count( $requests ),
					'action_count'             => count( $actions ),
				)
			);
		}

		/**
		 * Deterministic suggested actions from recent tool results (idle follow-ups).
		 *
		 * @param int $session_id Session ID.
		 * @return array<int, array<string, mixed>>
		 */
		private static function suggested_actions_for_session( $session_id ) {
			if ( ! class_exists( 'Ahentic_Abilities_Plugins' ) ) {
				return array();
			}

			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			if ( empty( $entries ) ) {
				return array();
			}

			$saw_activate_after_install = false;

			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				$entry = $entries[ $i ];
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$role = isset( $entry['role'] ) ? (string) $entry['role'] : '';
				if ( 'user' === $role || 'assistant' === $role ) {
					// Left the latest tool cluster without an install result.
					return array();
				}
				if ( 'tool' !== $role ) {
					continue;
				}

				$ability = isset( $entry['meta']['ability'] ) ? (string) $entry['meta']['ability'] : '';
				$decoded = json_decode( (string) $entry['content'], true );
				if ( ! is_array( $decoded ) ) {
					$decoded = array();
				}
				$tool_ok = ! isset( $entry['meta']['ok'] ) || ! empty( $entry['meta']['ok'] );
				if ( isset( $decoded['ok'] ) ) {
					$tool_ok = (bool) $decoded['ok'];
				}

				if ( Ahentic_Abilities_Plugins::ACTIVATE === $ability && $tool_ok ) {
					$saw_activate_after_install = true;
					continue;
				}

				if ( Ahentic_Abilities_Plugins::INSTALL === $ability ) {
					if ( $saw_activate_after_install ) {
						return array();
					}
					return Ahentic_Abilities_Plugins::suggested_actions_after_install( $decoded );
				}
			}

			return array();
		}

		/**
		 * Start a suggested ability action (typically HITL) from the sidebar.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $action     Action payload from the client.
		 * @return array|\WP_Error
		 */
		public static function handle_suggested_action( $session_id, array $action ) {
			$post = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$status = Ahentic_Session_Repository::get_status( $session_id );
			if ( Ahentic_Session_Repository::STATUS_IDLE !== $status ) {
				return new WP_Error(
					'ahentic_session_busy',
					__( 'This session is still working. Wait for it to finish or cancel it.', 'ahentic' ),
					array( 'status' => 409 )
				);
			}

			$type = isset( $action['type'] ) ? (string) $action['type'] : '';
			if ( 'ability' !== $type ) {
				return new WP_Error( 'ahentic_bad_action', __( 'Only ability actions can be started from the server.', 'ahentic' ), array( 'status' => 400 ) );
			}

			$name  = isset( $action['name'] ) ? (string) $action['name'] : '';
			$input = isset( $action['input'] ) && is_array( $action['input'] ) ? $action['input'] : array();
			if ( '' === $name || ! in_array( $name, Ahentic_Abilities::available_for_agent(), true ) ) {
				return new WP_Error( 'ahentic_unknown_action', __( 'That action is not available.', 'ahentic' ), array( 'status' => 400 ) );
			}

			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

			if ( Ahentic_Abilities::requires_hitl( $name ) && ! Ahentic_Session_Repository::hitl_is_preallowed( $session_id, $name ) ) {
				$summary = Ahentic_Abilities::hitl_summary( $name, $input );
				$pending = array(
					'name'    => $name,
					'input'   => $input,
					'summary' => $summary,
					'call_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ahentic_', true ),
					'source'  => 'suggested_action',
				);
				Ahentic_Session_Repository::set_pending_tool( $session_id, $pending );
				Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_AWAITING_HUMAN );
				Ahentic_Session_Repository::set_progress(
					$session_id,
					__( 'Waiting for your approval…', 'ahentic' ),
					$step
				);
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'hitl_pause',
					$summary,
					array(
						'ability' => $name,
						'input'   => $input,
						'source'  => 'suggested_action',
					),
					$step
				);
				return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
			}

			// Non-HITL (or preallowed): run immediately and continue the agent loop.
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
			$label = self::progress_label_for_tool( $name );
			Ahentic_Session_Repository::set_progress( $session_id, $label, $step );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'tool_executed',
				$label,
				array(
					'ability' => $name,
					'input'   => $input,
					'source'  => 'suggested_action',
				),
				$step
			);

			$tool_result = Ahentic_Abilities::execute( $name, $input );
			$ok          = ! is_wp_error( $tool_result );
			$payload     = $ok
				? $tool_result
				: array(
					'ok'      => false,
					'error'   => $tool_result->get_error_code(),
					'message' => $tool_result->get_error_message(),
				);
			$content = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $content ) ) {
				$content = '{}';
			}

			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'tool',
					'content' => $content,
					'meta'    => array(
						'ability' => $name,
						'ok'      => $ok,
						'source'  => 'suggested_action',
					),
				)
			);
			Ahentic_Session_Repository::set_progress( $session_id, __( 'Planning next steps…', 'ahentic' ), $step );
			Ahentic_Step_Queue::enqueue_step( $session_id );
			Ahentic_Step_Queue::schedule_interactive_run( $session_id );

			return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
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
		 * Fail the run with an assistant error message.
		 *
		 * @param int      $session_id Session ID.
		 * @param \WP_Error $error     Error.
		 */
		private static function fail_run( $session_id, $error ) {
			Ahentic_Session_Repository::set_error( $session_id, $error->get_error_message() );
			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'assistant',
					'content' => sprintf(
						/* translators: %s: error message */
						__( 'Sorry — I could not complete that request (%s). Check that WordPress AI / a model connector is configured.', 'ahentic' ),
						$error->get_error_message()
					),
					'meta'    => array(
						'error' => true,
						'code'  => $error->get_error_code(),
					),
				)
			);
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'error',
				$error->get_error_message(),
				array( 'code' => $error->get_error_code() )
			);
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_ERROR );
			Ahentic_Session_Repository::mark_idle( $session_id );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_idle',
				'Run idle after error',
				array( 'reason' => 'error' )
			);
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
		 * Whether the latest tool cluster includes a failed ability call.
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		private static function recent_tool_failure( $session_id ) {
			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			if ( empty( $entries ) ) {
				return false;
			}

			$saw_tool = false;
			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				$entry = $entries[ $i ];
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$role = isset( $entry['role'] ) ? (string) $entry['role'] : '';
				if ( 'user' === $role ) {
					break;
				}
				if ( 'assistant' === $role ) {
					// Intermediate before this think step — stop once we leave the tool cluster.
					if ( $saw_tool ) {
						break;
					}
					continue;
				}
				if ( 'tool' !== $role ) {
					continue;
				}
				$saw_tool = true;
				if ( isset( $entry['meta']['ok'] ) && ! $entry['meta']['ok'] ) {
					return true;
				}
				$decoded = json_decode( (string) $entry['content'], true );
				if ( is_array( $decoded ) && array_key_exists( 'ok', $decoded ) && ! $decoded['ok'] ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Skip intermediate chat bubbles that would duplicate recovery / prior status prose.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $text       Candidate intermediate text.
		 * @return bool
		 */
		private static function should_omit_intermediate( $session_id, $text ) {
			if ( self::recent_tool_failure( $session_id ) ) {
				return true;
			}

			$normalized = self::normalize_message_text( $text );
			if ( '' === $normalized ) {
				return true;
			}

			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				$entry = $entries[ $i ];
				if ( ! is_array( $entry ) || 'assistant' !== ( isset( $entry['role'] ) ? $entry['role'] : '' ) ) {
					continue;
				}
				$prev = self::normalize_message_text( isset( $entry['content'] ) ? $entry['content'] : '' );
				if ( '' === $prev ) {
					continue;
				}
				if ( $prev === $normalized ) {
					return true;
				}
				// Near-duplicate: same opening stretch (common when the model rephrases slightly).
				$a = substr( $prev, 0, 80 );
				$b = substr( $normalized, 0, 80 );
				if ( '' !== $a && $a === $b ) {
					return true;
				}
				// Only compare against the latest assistant bubble.
				break;
			}

			return false;
		}

		/**
		 * Collapse whitespace for duplicate detection.
		 *
		 * @param string $text Raw text.
		 * @return string
		 */
		private static function normalize_message_text( $text ) {
			$text = strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $text ) ) );
			return $text;
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
		 * User-facing progress label from the model's intention / thinking.
		 *
		 * @param array  $debug    Debug block.
		 * @param string $fallback Label when reasoning is missing.
		 * @return string
		 */
		private static function progress_label_from_debug( $debug, $fallback = '' ) {
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

		/**
		 * Friendly progress label for an ability / tool id.
		 *
		 * @param string $tool  Ability name.
		 * @param array  $debug Debug block.
		 * @return string
		 */
		private static function progress_label_for_tool( $tool, $debug = array() ) {
			$map = array(
				'ahentic/list-plugins'       => __( 'Checking installed plugins…', 'ahentic' ),
				'ahentic/search-plugins'     => __( 'Searching the plugin directory…', 'ahentic' ),
				'ahentic/get-site-snapshot'  => __( 'Reading site snapshot…', 'ahentic' ),
				'ahentic/get-site-health'    => __( 'Checking site health…', 'ahentic' ),
				'ahentic/get-option'         => __( 'Reading site settings…', 'ahentic' ),
				'ahentic/search-content'     => __( 'Searching site content…', 'ahentic' ),
				'ahentic/list-content'       => __( 'Listing posts and pages…', 'ahentic' ),
				'ahentic/get-content'        => __( 'Reading post content…', 'ahentic' ),
				'ahentic/find-unused-media'  => __( 'Scanning media for unused images…', 'ahentic' ),
				'ahentic/install-plugin'     => __( 'Installing plugin…', 'ahentic' ),
				'ahentic/activate-plugin'    => __( 'Activating plugin…', 'ahentic' ),
				'core/read-content'          => __( 'Reading site content…', 'ahentic' ),
				'ahentic/inspect-site'       => __( 'Inspecting the site…', 'ahentic' ),
			);

			if ( isset( $map[ $tool ] ) ) {
				return $map[ $tool ];
			}

			$from_debug = self::progress_label_from_debug( $debug, '' );
			if ( '' !== $from_debug ) {
				return $from_debug;
			}

			$short = preg_replace( '/^.*\//', '', $tool );
			$short = str_replace( '-', ' ', (string) $short );
			return sprintf(
				/* translators: %s: tool slug */
				__( 'Running %s…', 'ahentic' ),
				$short ? $short : __( 'next step', 'ahentic' )
			);
		}

		/**
		 * Cancel a running / awaiting session.
		 *
		 * @param int $session_id Session ID.
		 * @return array|\WP_Error
		 */
		public static function cancel( $session_id ) {
			$post = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			Ahentic_Session_Repository::set_pending_tool( $session_id, null );
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_CANCELLED );
			Ahentic_Step_Queue::release_run( $session_id );
			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'event',
					'content' => __( 'Run cancelled.', 'ahentic' ),
					'meta'    => array( 'type' => 'cancelled' ),
				)
			);
			Ahentic_Session_Repository::mark_idle( $session_id );

			return Ahentic_Session_Repository::to_rest( $session_id );
		}

		/**
		 * HITL approval resume — run or deny the pending mutating ability.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $decision   Decision payload.
		 * @return array|\WP_Error
		 */
		public static function handle_approval( $session_id, array $decision ) {
			$pending = Ahentic_Session_Repository::get_pending_tool( $session_id );
			if ( ! $pending || empty( $pending['name'] ) ) {
				return new WP_Error( 'ahentic_no_pending', __( 'No pending tool to approve.', 'ahentic' ), array( 'status' => 400 ) );
			}

			$status = Ahentic_Session_Repository::get_status( $session_id );
			if ( Ahentic_Session_Repository::STATUS_AWAITING_HUMAN !== $status ) {
				return new WP_Error( 'ahentic_not_awaiting', __( 'This session is not waiting for approval.', 'ahentic' ), array( 'status' => 409 ) );
			}

			$choice = isset( $decision['decision'] ) ? (string) $decision['decision'] : '';
			$name   = (string) $pending['name'];
			$input  = isset( $pending['input'] ) && is_array( $pending['input'] ) ? $pending['input'] : array();
			$step   = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

			if ( 'deny' === $choice ) {
				$payload = array(
					'ok'      => false,
					'error'   => 'user_denied',
					'message' => __( 'User denied this action.', 'ahentic' ),
					'ability' => $name,
				);
				Ahentic_Session_Repository::set_pending_tool( $session_id, null );
				Ahentic_Session_Repository::append_entry(
					$session_id,
					array(
						'role'    => 'tool',
						'content' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						'meta'    => array(
							'ability' => $name,
							'ok'      => false,
							'denied'  => true,
						),
					)
				);
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'hitl_denied',
					'Denied: ' . $name,
					array( 'ability' => $name ),
					$step
				);
				Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
				Ahentic_Session_Repository::set_progress( $session_id, __( 'Planning next steps…', 'ahentic' ), $step );
				Ahentic_Step_Queue::enqueue_step( $session_id );
				Ahentic_Step_Queue::schedule_interactive_run( $session_id );
				return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
			}

			if ( ! in_array( $choice, array( 'allow_once', 'allow_session', 'always_allow' ), true ) ) {
				return new WP_Error( 'ahentic_bad_decision', __( 'Invalid approval decision.', 'ahentic' ), array( 'status' => 400 ) );
			}

			if ( 'allow_session' === $choice ) {
				Ahentic_Session_Repository::add_hitl_session_allow( $session_id, $name );
			}
			if ( 'always_allow' === $choice ) {
				Ahentic_Session_Repository::add_hitl_always_allow( $name );
				Ahentic_Session_Repository::add_hitl_session_allow( $session_id, $name );
			}

			Ahentic_Session_Repository::set_pending_tool( $session_id, null );
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );

			$label = self::progress_label_for_tool( $name );
			Ahentic_Session_Repository::set_progress( $session_id, $label, $step );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'tool_executed',
				$label,
				array(
					'ability'  => $name,
					'input'    => $input,
					'approved' => $choice,
				),
				$step
			);

			$tool_result = Ahentic_Abilities::execute( $name, $input );
			$ok          = ! is_wp_error( $tool_result );
			$payload     = $ok
				? $tool_result
				: array(
					'ok'      => false,
					'error'   => $tool_result->get_error_code(),
					'message' => $tool_result->get_error_message(),
				);

			$content = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $content ) ) {
				$content = '{}';
			}

			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'tool',
					'content' => $content,
					'meta'    => array(
						'ability'  => $name,
						'ok'       => $ok,
						'approved' => $choice,
					),
				)
			);
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'tool_result',
				$ok ? ( 'Result: ' . $name ) : ( 'Error: ' . $name ),
				array(
					'ability' => $name,
					'ok'      => $ok,
					'excerpt' => self::excerpt( $content, 240 ),
				),
				$step
			);

			// Keep the approved tool's step label visible into the next think.
			Ahentic_Step_Queue::enqueue_step( $session_id );
			Ahentic_Step_Queue::schedule_interactive_run( $session_id );

			return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
		}

		/**
		 * Browser ability result resume (stub-ready).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $payload    { call_id, result|error }.
		 * @return array|\WP_Error
		 */
		public static function handle_browser_result( $session_id, array $payload ) {
			$pending = Ahentic_Session_Repository::get_pending_tool( $session_id );
			if ( ! $pending ) {
				return new WP_Error( 'ahentic_no_pending', __( 'No pending browser tool.', 'ahentic' ), array( 'status' => 400 ) );
			}

			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'tool',
					'content' => isset( $payload['error'] )
						? (string) $payload['error']
						: wp_json_encode( isset( $payload['result'] ) ? $payload['result'] : $payload ),
					'meta'    => array(
						'tool'     => $pending,
						'call_id'  => isset( $payload['call_id'] ) ? $payload['call_id'] : '',
						'browser'  => true,
					),
				)
			);
			Ahentic_Session_Repository::set_pending_tool( $session_id, null );
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
			Ahentic_Step_Queue::enqueue_step( $session_id );

			return Ahentic_Session_Repository::to_rest( $session_id );
		}

		/**
		 * Enqueue summary job.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function enqueue_summary( $session_id ) {
			Ahentic_Step_Queue::enqueue_summary( $session_id );
		}

		/**
		 * Run session summary + knowledge classification.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function run_summary( $session_id ) {
			$session_id = (int) $session_id;
			$post       = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return;
			}

			// Skip if a new run started.
			if ( Ahentic_Session_Repository::STATUS_IDLE !== Ahentic_Session_Repository::get_status( $session_id ) ) {
				return;
			}

			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			$lines   = array();
			foreach ( $entries as $entry ) {
				if ( ! in_array( $entry['role'], array( 'user', 'assistant' ), true ) ) {
					continue;
				}
				$lines[] = strtoupper( $entry['role'] ) . ': ' . $entry['content'];
			}

			if ( count( $lines ) < 1 ) {
				update_post_meta( $session_id, Ahentic_Session_Repository::META_SUMMARY_STATUS, 'skipped' );
				return;
			}

			$transcript = implode( "\n\n", array_slice( $lines, -30 ) );
			$mode       = Ahentic_Session_Repository::get_mode( $session_id );
			$title      = $post->post_title;

			$system = 'You summarize an Ahentic agent session on a WordPress site.

Return ONLY valid JSON (no markdown) with this exact shape:
{
  "title": string,
  "summary": string,
  "knowledge_important": boolean,
  "knowledge_kinds": string[],
  "facts": [
    {
      "topic": string,
      "kind": string,
      "summary": string,
      "confidence": "high"|"medium"|"low"
    }
  ],
  "open_loops": string[]
}

Rules:
- knowledge_important=true only for durable, site-specific knowledge.
- knowledge_important=false for generic how-tos or empty/aborted chats.
- Never include passwords, API keys, tokens, or private PII.
- If nothing durable, use knowledge_important=false, knowledge_kinds=[], facts=[].';

			$user = 'Session mode: ' . $mode . "\nSession title (current): " . $title . "\n\nTranscript:\n" . $transcript;

			$result = Ahentic_AI::complete_text( $system, $user );
			if ( is_wp_error( $result ) ) {
				update_post_meta( $session_id, Ahentic_Session_Repository::META_SUMMARY_STATUS, 'error' );
				return;
			}

			Ahentic_Session_Repository::add_tokens(
				$session_id,
				$result['tokens_in'],
				$result['tokens_out'],
				$result['tokens_total']
			);

			$parsed = self::parse_summary_json( $result['text'] );
			if ( ! $parsed ) {
				update_post_meta( $session_id, Ahentic_Session_Repository::META_SUMMARY_STATUS, 'error' );
				return;
			}

			if ( ! empty( $parsed['title'] ) ) {
				Ahentic_Session_Repository::maybe_set_auto_title( $session_id, $parsed['title'] );
			}

			if ( ! empty( $parsed['summary'] ) ) {
				wp_update_post(
					array(
						'ID'           => $session_id,
						'post_excerpt' => sanitize_textarea_field( $parsed['summary'] ),
					)
				);
			}

			$important = ! empty( $parsed['knowledge_important'] );
			$override  = (string) get_post_meta( $session_id, Ahentic_Session_Repository::META_KNOWLEDGE_OVERRIDE, true );
			if ( 'force_important' === $override ) {
				$important = true;
			} elseif ( 'force_ignore' === $override ) {
				$important = false;
			}

			update_post_meta( $session_id, Ahentic_Session_Repository::META_KNOWLEDGE_IMPORTANT, $important ? '1' : '0' );
			update_post_meta(
				$session_id,
				Ahentic_Session_Repository::META_KNOWLEDGE_KINDS,
				wp_slash( wp_json_encode( isset( $parsed['knowledge_kinds'] ) ? $parsed['knowledge_kinds'] : array() ) )
			);
			update_post_meta(
				$session_id,
				Ahentic_Session_Repository::META_KNOWLEDGE_FACTS,
				wp_slash( wp_json_encode( isset( $parsed['facts'] ) ? $parsed['facts'] : array() ) )
			);
			update_post_meta( $session_id, Ahentic_Session_Repository::META_SUMMARY_STATUS, 'ready' );
			update_post_meta( $session_id, Ahentic_Session_Repository::META_SUMMARY_AT, gmdate( 'c' ) );
			update_post_meta( $session_id, Ahentic_Session_Repository::META_SUMMARY_MODEL, $result['model'] );

			// Site knowledge upsert lands with the knowledge bootstrap feature.
		}

		/**
		 * Build history + latest user message for the model.
		 *
		 * Tool results since the last user message are appended to the user prompt
		 * so the next think can observe them.
		 *
		 * @param array $entries Session entries.
		 * @return array{history: array, user: string}
		 */
		private static function build_chat_payload( array $entries ) {
			$normalized = array();
			foreach ( $entries as $entry ) {
				if ( ! empty( $entry['meta']['error'] ) ) {
					continue;
				}
				$role = isset( $entry['role'] ) ? $entry['role'] : '';
				if ( 'user' === $role || 'assistant' === $role ) {
					$normalized[] = array(
						'role'    => $role,
						'content' => (string) $entry['content'],
					);
				} elseif ( 'tool' === $role ) {
					$ability      = isset( $entry['meta']['ability'] ) ? (string) $entry['meta']['ability'] : 'tool';
					$normalized[] = array(
						'role'    => 'tool',
						'content' => '[Ability result: ' . $ability . "]\n" . (string) $entry['content'],
					);
				}
			}

			$last_user_i = -1;
			foreach ( $normalized as $i => $turn ) {
				if ( 'user' === $turn['role'] ) {
					$last_user_i = $i;
				}
			}

			if ( $last_user_i < 0 ) {
				return array(
					'history' => array(),
					'user'    => '',
				);
			}

			$history = array();
			for ( $i = 0; $i < $last_user_i; $i++ ) {
				$turn = $normalized[ $i ];
				if ( 'tool' === $turn['role'] ) {
					$history[] = array(
						'role'    => 'assistant',
						'content' => $turn['content'],
					);
				} else {
					$history[] = $turn;
				}
			}

			$user     = $normalized[ $last_user_i ]['content'];
			$trailing = array_slice( $normalized, $last_user_i + 1 );
			if ( ! empty( $trailing ) ) {
				$chunks = array();
				foreach ( $trailing as $turn ) {
					$chunks[] = $turn['content'];
				}
				$user .= "\n\n---\nAbility results from this run (use these facts; do not invent conflicting data):\n"
					. implode( "\n\n", $chunks );
			}

			if ( count( $history ) > self::MAX_HISTORY_TURNS ) {
				$history = array_slice( $history, -1 * self::MAX_HISTORY_TURNS );
			}

			return array(
				'history' => $history,
				'user'    => $user,
			);
		}

		/**
		 * System prompt for agent / ask modes.
		 *
		 * @param string $mode Mode.
		 * @return string
		 */
		private static function system_prompt( $mode ) {
			$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$site_url   = home_url( '/' );
			$available  = Ahentic_Abilities::available_for_agent();
			$tools_list = implode( ', ', $available );
			$admin_map  = Ahentic_Abilities::format_admin_links_for_prompt();

			$base = 'You are Ahentic, an AI workspace agent for WordPress. '
				. 'You help the user understand and improve their WordPress site. '
				. "Current site (hint only): {$site_name} ({$site_url}). "
				. 'Be concise, practical, and specific to WordPress when possible. '
				. 'Do not invent that you changed the site unless a tool confirmed it. '
				. 'When you need verified site data, call tools — do not guess plugin lists or stack details.';

			if ( 'ask' === $mode ) {
				$base .= ' Mode: Ask — answer questions; prefer next="reply". You may use readonly tools if needed. '
					. 'If the user asks you to change the site and you cannot (no write ability), set next="missing_ability" '
					. 'with ability_needed (e.g. "ahentic/update-site-title"), explain you cannot do it yet, and give manual steps with admin links. '
					. 'Do not mention X, Twitter, hashtags, @handles, request cards, or any sidebar UI for requesting features.';
			} else {
				$base .= ' Mode: Agent — you run a multi-step loop. When you need site facts, set next="use_tools" '
					. 'and list tools in tools_planned. After tool results appear in the next message, think again '
					. 'and either call more tools or set next="reply" / "ask_user" / "missing_ability". '
					. "Available abilities right now: {$tools_list}. "
					. 'Prefer ahentic/get-site-snapshot when you need the site name, theme, environment, active plugins, or admin_links. '
					. 'Prefer ahentic/get-site-health for Site Health counts/issues; ahentic/get-option for allowlisted options (blog_public, blogdescription/tagline, permalink_structure, show_on_front, etc.). '
					. 'Prefer ahentic/list-plugins for installed active+inactive plugins; ahentic/search-plugins to search wordpress.org (pass query like "SEO"). '
					. 'HITL replaces ask_user for mutating abilities: when the concrete next step is ahentic/install-plugin or ahentic/activate-plugin '
					. '(or any other ability that pauses for human approval), do NOT set next="ask_user" or ask “shall I install/activate it?” in chat. '
					. 'Instead set next="use_tools" and put that ability in tools_planned immediately — the product shows Allow/Deny; that IS the confirmation. '
					. 'In the short user-facing reply, say what you are about to do (e.g. install or activate the chosen plugin) and that they can approve below; '
					. 'never claim success until a tool result confirms it. Use ask_user only for real choices the tools cannot decide '
					. '(e.g. which of two plugins to pick when both are fine). '
					. 'Chain install → activate: after a successful ahentic/install-plugin tool result with active=false, if the user wanted the plugin working '
					. '(install / set up / turn on / “help me find one”), immediately set next="use_tools" with ahentic/activate-plugin using the same slug or plugin_file — '
					. 'do not stop at “installed but not active; activate from Plugins.” Only skip chaining when the user clearly asked to install without activating. '
					. 'Prefer ahentic/search-content to find posts/pages by phrase (title, body, or meta); '
					. 'ahentic/list-content to browse by type/status; ahentic/get-content to read one post (body + safe meta). '
					. 'Prefer ahentic/find-unused-media to scan the media library for images that look unused (not featured/logo/icon/in content). '
					. 'Use edit_url / view_url / media_library_url / plugins_url from those results when linking the user. '
					. 'Do not claim you ran a tool that is not in the available list. '
					. 'IMPORTANT — when the user asks you to create/update/delete/change something and you do not have a matching '
					. 'available ability, do NOT only give manual instructions with next="reply". Instead either: '
					. '(A) set next="use_tools" and put the needed ability name in tools_planned even if it is not in the available list '
					. '(the orchestrator will mark it unavailable), or '
					. '(B) set next="missing_ability" and ability_needed to that ability slug (e.g. "ahentic/update-site-title" or "ahentic/delete-posts"). '
					. 'In your user-facing reply: explain you cannot do it yet and give a short workaround with admin links if useful. '
					. 'Never mention X, Twitter, hashtags, @handles, tweet URLs, request cards, or sidebar UI — the product UI handles feature requests separately. '
					. 'If a tool result has error ability_unavailable, follow the same reply pattern.';
			}

			$base .= "\n\n"
				. 'When you tell the user to open a wp-admin screen, settings page, plugins list, editor, or any other area of their site, '
				. 'ALWAYS include a clickable Markdown link using a full URL from the admin link map below (or from a tool result such as admin_links / edit URLs). '
				. 'Format: [Settings → General](https://example.com/wp-admin/options-general.php). '
				. 'Do not nest bold markers inside the link brackets (wrong: [**Settings → General**](url); right: [Settings → General](url) or **[Settings → General](url)**). '
				. 'Do not only write path breadcrumbs like "Settings → General" without a link. '
				. 'Never invent /wp-admin/ paths — use the map or tool-provided URLs.'
				. "\n\nAdmin link map (use these URLs):\n"
				. $admin_map;

			$base .= "\n\n"
				. 'Before your user-facing reply, output exactly one debug block (the user will not see it) in this form:' . "\n"
				. '<<<AHENTIC_DEBUG' . "\n"
				. '{"intention":"Checking installed plugins","thinking":"1-3 sentences","tools_planned":["ahentic/get-site-snapshot"],"ability_needed":"ahentic/update-site-title","next":"reply|ask_user|use_tools|missing_ability"}' . "\n"
				. 'AHENTIC_DEBUG>>>' . "\n"
				. 'intention must be a short present-tense status the UI can show live (e.g. "Checking installed plugins", '
				. '"Searching the media library", "Summarizing findings") — not a private note. Keep it under ~10 words. '
				. 'tools_planned may be strings (ability names) or objects {"name":"ahentic/…","input":{}}. '
				. 'ability_needed is optional except when next is missing_ability (string or list of ability slugs). '
				. 'Closing marker: AHENTIC_DEBUG followed by exactly three > characters. '
				. 'After the closing marker, write a short normal reply the user can read (even when next is use_tools — e.g. what you are about to check). '
				. 'Never mention the debug block.';

			return $base;
		}

		/**
		 * Truncate text for trace payloads.
		 *
		 * @param string $text Text.
		 * @param int    $max  Max length.
		 * @return string
		 */
		private static function excerpt( $text, $max = 120 ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
			if ( strlen( $text ) <= $max ) {
				return $text;
			}
			return rtrim( substr( $text, 0, $max - 1 ) ) . '…';
		}

		/**
		 * Parse JSON summary from model output.
		 *
		 * @param string $text Raw model text.
		 * @return array|null
		 */
		private static function parse_summary_json( $text ) {
			$text = trim( (string) $text );
			if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
				$text = $m[0];
			}
			$data = json_decode( $text, true );
			return is_array( $data ) ? $data : null;
		}
	}
}
