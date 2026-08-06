<?php
/**
 * PHP Agent Orchestrator — step loop for sidebar (and future Agents).
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
		/** When history exceeds this many turns, compact older ones (PRD). */
		const COMPACT_HISTORY_THRESHOLD = 16;
		/** Keep this many recent history turns verbatim after compaction. */
		const COMPACT_KEEP_RECENT = 10;
		/** Soft char budget for history before compacting. */
		const COMPACT_CHAR_THRESHOLD = 24000;
		/** Max chars for the rolling earlier-context summary. */
		const COMPACT_SUMMARY_MAX_CHARS = 4000;
		/** Default Agent run step budget (PRD). */
		const MAX_STEPS_PER_RUN = 24;
		/** Content / long-form run step budget when session has content artifacts (PRD). */
		const MAX_STEPS_CONTENT_RUN = 48;
		/**
		 * Minimum plan steps for a new plan card when the run needs a plan
		 * (≥2 tools or any write). Single-step write plans are allowed.
		 */
		const MIN_PLAN_STEPS = 1;
		/** Cap plan length so it cannot outgrow a single run. */
		const MAX_PLAN_STEPS = 12;
		/** Max LLM attempts to obtain a valid AHENTIC_DEBUG block per think phase. */
		const MAX_DEBUG_ATTEMPTS = 3;
		/** Cap each tool-result payload injected into the next think prompt. */
		const MAX_TOOL_RESULT_CHARS = 8000;
		/** Cap for the newest live-editor snapshot; superseded copies are collapsed so one full read fits. */
		const MAX_TOOL_RESULT_CHARS_SNAPSHOT = 24000;
		/** Max repair-think cycles for a thin body before an honest partial finish. */
		const MAX_VERIFY_ATTEMPTS = 1;
		/** Plain-text characters a long-form body must reach before the agent may finish. */
		const LONG_FORM_MIN_CHARS = 2000;

		/**
		 * Session currently being processed (for abilities that need page context).
		 *
		 * @var int
		 */
		private static $current_session_id = 0;

		/**
		 * Session id for the in-flight orchestrator step (0 if idle).
		 *
		 * @return int
		 */
		public static function current_session_id() {
			return (int) self::$current_session_id;
		}

		/**
		 * Run a callback with current_session_id set (abilities / artifacts may read it).
		 *
		 * @param int      $session_id Session ID.
		 * @param callable $callback   Callback.
		 * @return mixed Callback return value.
		 */
		public static function with_current_session( $session_id, $callback ) {
			$previous                 = self::$current_session_id;
			self::$current_session_id = (int) $session_id;
			try {
				return call_user_func( $callback );
			} finally {
				self::$current_session_id = $previous;
			}
		}

		/**
		 * Accept a user message and start a run (async — sidebar polls for progress).
		 *
		 * @param int         $session_id Session ID.
		 * @param string      $content    User text.
		 * @param string      $mode       Optional mode override (agent|ask).
		 * @param array|null  $page_context Optional sidebar page context.
		 * @return array|\WP_Error Session REST payload.
		 */
		public static function handle_user_message( $session_id, $content, $mode = '', $page_context = null ) {
			$post = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$content = trim( wp_unslash( (string) $content ) );
			if ( '' === $content ) {
				return new WP_Error( 'ahentic_empty_message', __( 'Message cannot be empty.', 'ahentic' ), array( 'status' => 400 ) );
			}

			$status = Ahentic_Session_Repository::get_status( $session_id );

			// New prompt during HITL = skip the pending action and redirect with the user's instruction.
			if ( Ahentic_Session_Repository::STATUS_AWAITING_HUMAN === $status ) {
				self::skip_pending_hitl_tool( $session_id, 'user_redirect' );
			} elseif ( in_array( $status, array( Ahentic_Session_Repository::STATUS_RUNNING, Ahentic_Session_Repository::STATUS_AWAITING_BROWSER ), true ) ) {
				return new WP_Error(
					'ahentic_session_busy',
					__( 'This session is still working. Wait for it to finish or cancel it.', 'ahentic' ),
					array( 'status' => 409 )
				);
			}

			if ( $mode ) {
				Ahentic_Session_Repository::set_mode( $session_id, $mode );
			}

			if ( is_array( $page_context ) ) {
				Ahentic_Session_Repository::set_page_context( $session_id, $page_context );
			}

			Ahentic_Session_Repository::clear_error( $session_id );
			Ahentic_Session_Repository::clear_verify_pending( $session_id );
			Ahentic_Session_Repository::clear_verify_attempts( $session_id );
			Ahentic_Session_Repository::clear_pending_final( $session_id );
			Ahentic_Session_Repository::clear_forced_tools( $session_id );
			Ahentic_Session_Repository::clear_thought( $session_id );
			Ahentic_Session_Repository::clear_browser_paused_at( $session_id );
			Ahentic_Session_Repository::clear_context_summary( $session_id );
			Ahentic_Session_Repository::set_llm_keepalive( $session_id, false );

			// Intent gate: long-form / article jobs get content budgets + stricter verify
			// even before any artifact is staged (PRD content-and-editor).
			$content_intent = self::message_looks_like_content_work( $content );
			Ahentic_Session_Repository::set_content_work( $session_id, $content_intent );

			update_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, 0 );
			Ahentic_Session_Repository::consume_capability_requests( $session_id );
			Ahentic_Session_Repository::clear_plan( $session_id );
			// Mark running before append_entry so a concurrent poll cannot see the new
			// user message while status is still idle (sidebar would drop busy chrome
			// and stop polling / browser resume).
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
			Ahentic_Session_Repository::set_progress( $session_id, __( 'Planning next steps…', 'ahentic' ) );

			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'user',
					'content' => $content,
				)
			);

			Ahentic_Session_Repository::maybe_set_auto_title( $session_id, $content );

			$mode_now = Ahentic_Session_Repository::get_mode( $session_id );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_start',
				sprintf( 'Run started (%s)', $mode_now ),
				array(
					'mode'    => $mode_now,
					'message' => self::excerpt( $content, 160 ),
					// Recorded per run so a log read months later still says which
					// plugin, WordPress, PHP, and AI client produced it.
					'env'     => Ahentic_Session_Repository::environment_snapshot(),
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
			if ( Ahentic_Session_Repository::STATUS_AWAITING_BROWSER === $status ) {
				self::recover_stale_browser( $session_id );
				return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
			}

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

			Ahentic_Session_Repository::touch_heartbeat( $session_id );

			$should_continue = false;

			try {
				self::$current_session_id = $session_id;
				$should_continue          = self::run_one_step( $session_id );
			} finally {
				self::$current_session_id = 0;
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

			Ahentic_Session_Repository::touch_heartbeat( $session_id );

			$steps     = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			$max_steps = self::max_steps_for_session( $session_id );
			if ( $steps >= $max_steps ) {
				self::fail_run(
					$session_id,
					new WP_Error(
						'ahentic_max_steps',
						__( 'This run hit the step limit before finishing. Artifacts are kept — send Continue or another message to resume (e.g. finish applying the draft).', 'ahentic' )
					)
				);
				return false;
			}

			$mode         = Ahentic_Session_Repository::get_mode( $session_id );
			$forced_tools = Ahentic_Session_Repository::consume_forced_tools( $session_id );
			$from_forced  = ! empty( $forced_tools );

			if ( $from_forced ) {
				Ahentic_Session_Repository::bump_step( $session_id );
				$debug = array(
					'next'          => 'use_tools',
					'intention'     => __( 'Finishing pending apply/verify', 'ahentic' ),
					'thinking'      => __( 'Running required apply or verification tools before the final reply.', 'ahentic' ),
					'tools_planned' => $forced_tools,
				);
				$result = array(
					'text'  => '',
					'model' => '',
					'debug' => $debug,
				);
				$stashed = Ahentic_Session_Repository::get_pending_final( $session_id );
				if ( is_array( $stashed ) && ! empty( $stashed['text'] ) ) {
					$result['text'] = (string) $stashed['text'];
					if ( ! empty( $stashed['model'] ) ) {
						$result['model'] = (string) $stashed['model'];
					}
					if ( ! empty( $stashed['debug'] ) && is_array( $stashed['debug'] ) ) {
						$debug = array_merge( $stashed['debug'], $debug );
						$result['debug'] = $debug;
					}
				}
				$first = isset( $forced_tools[0]['name'] ) ? (string) $forced_tools[0]['name'] : '';
				Ahentic_Session_Repository::set_progress(
					$session_id,
					'' !== $first
						? self::progress_label_for_tool( $first, $debug )
						: __( 'Finishing pending work…', 'ahentic' )
				);
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'forced_tools',
					'Running orchestrator-forced tools',
					array( 'tools' => $forced_tools ),
					(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
				);
				$planned = self::normalize_tool_calls( $forced_tools );
			} else {
				// Keep the last meaningful step label (tool / intention) while the model thinks.
				$think_label = self::progress_label_for_think( $session_id );

				$result = self::run_llm_with_debug(
					$session_id,
					$think_label,
					self::system_prompt( $mode, $session_id )
				);

				// User may have hit Stop during the LLM call — do not continue the run.
				if ( Ahentic_Session_Repository::STATUS_RUNNING !== Ahentic_Session_Repository::get_status( $session_id ) ) {
					return false;
				}

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

				// Persist multi-step plan from the control block (orchestrator state, not a tool).
				self::apply_plan_from_debug( $session_id, $debug );

				// Agent write / multi-tool work must have a plan (PRD).
				if ( self::debug_requires_plan( $debug, $mode ) && ! Ahentic_Session_Repository::get_plan( $session_id ) ) {
					$plan_retry = self::run_llm_phase(
						$session_id,
						__( 'Planning the work…', 'ahentic' ),
						self::system_prompt( $mode, $session_id ),
						null,
						'[Internal — not shown to the user] Your previous control block used tools or a write without a plan. '
						. 'Respond again from scratch with a full <<<AHENTIC_DEBUG … AHENTIC_DEBUG>>> block that includes a non-empty '
						. 'plan.steps list covering the work (at least one step), then tools_planned / next as needed. '
						. 'Do not mention this note.',
						false
					);
					if ( ! is_wp_error( $plan_retry ) && is_array( $plan_retry ) ) {
						$result = $plan_retry;
						$debug  = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : array();
						self::apply_plan_from_debug( $session_id, $debug );
					}
					if ( ! Ahentic_Session_Repository::get_plan( $session_id ) ) {
						self::ensure_synthetic_plan( $session_id, $debug );
					}
				}

				// Fill empty final-reply text from thinking/intention when needed.
				$result = self::ensure_thought_process_text( $result, $debug );

				// Missing / unusable control block after retries → stop with last prose (do not ask the user).
				if ( ! self::debug_is_usable( $debug ) ) {
					return self::try_finish_with_reply( $session_id, $result, $debug );
				}

				$next    = (string) $debug['next'];
				$planned = self::normalize_tool_calls( isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array() );

				// Explicit missing-ability signal (or reply that still names ability_needed).
				if ( self::debug_signals_missing_ability( $debug ) ) {
					self::queue_missing_abilities_from_debug( $session_id, $debug );
					return self::try_finish_with_reply( $session_id, $result, $debug );
				}

				$wants_tools = ( 'use_tools' === $next );

				if ( ! $wants_tools ) {
					return self::try_finish_with_reply( $session_id, $result, $debug );
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

				// Ephemeral thought for the sidebar (not a durable chat entry).
				self::publish_thought_process( $session_id, $result, $debug );
			}

			$available = Ahentic_Abilities::available_for_mode( $mode );
			if ( $from_forced ) {
				$planned = array_slice( $planned, 0, self::MAX_TOOL_PROGRESS );
				Ahentic_Session_Repository::set_thought(
					$session_id,
					isset( $debug['thinking'] ) ? (string) $debug['thinking'] : ''
				);
			}

			$ran_any = false;
			foreach ( $planned as $call_index => $call ) {
				if ( Ahentic_Session_Repository::STATUS_RUNNING !== Ahentic_Session_Repository::get_status( $session_id ) ) {
					return false;
				}

				$name  = $call['name'];
				$input = $call['input'];

				if ( ! in_array( $name, $available, true ) ) {
					$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
					Ahentic_Session_Repository::set_progress(
						$session_id,
						self::progress_label_for_tool( $name, $debug ),
						$step
					);

					$ask_write_blocked = ( 'ask' === $mode )
						&& in_array( $name, Ahentic_Abilities::available_for_agent(), true )
						&& ! Ahentic_Abilities::is_readonly( $name );

					$request = array();
					if ( ! $ask_write_blocked ) {
						self::queue_missing_ability( $session_id, $name, $debug, $step );

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
					}

					if ( $ask_write_blocked ) {
						$tool_payload = array(
							'ok'      => false,
							'error'   => 'ability_ask_readonly',
							'message' => sprintf(
								/* translators: %s: ability name */
								__( 'Ability %s changes the site and is not available in Ask mode. Switch to Agent mode to run it.', 'ahentic' ),
								$name
							),
							'hint'    => __(
								'Ask mode can only use read-only tools. Tell the user to switch the composer mode to Agent to install, activate, or otherwise change the site. Do not claim you made a change.',
								'ahentic'
							),
						);
					} else {
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
						$ask_write_blocked ? ( 'Ask mode blocked write: ' . $name ) : ( 'Ability unavailable: ' . $name ),
						array(
							'ability'            => $name,
							'ok'                 => false,
							'error'              => $ask_write_blocked ? 'ability_ask_readonly' : 'ability_unavailable',
							'capability_request' => $request,
						),
						$step
					);
					$ran_any = true;
					continue;
				}

				$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

				$run = Ahentic_Tool_Runner::run(
					$session_id,
					$name,
					$input,
					array(
						'step'       => $step,
						'debug'      => $debug,
						'planned'    => $planned,
						'call_index' => $call_index,
					)
				);

				if ( in_array( $run['outcome'], array( 'paused_hitl', 'paused_browser' ), true ) ) {
					return false;
				}

				$ran_any = true;
			}

			if ( ! $ran_any ) {
				return self::try_finish_with_reply( $session_id, $result, $debug );
			}

			// Forced apply/verify tools: try to finish with the stashed reply instead of another free think.
			if ( $from_forced ) {
				return self::try_finish_with_reply( $session_id, $result, $debug );
			}

			// After staging a ready draft without applying it in this batch, skip the next free
			// LLM think and force from_memory apply (saves a full debug-retry cycle).
			if ( 'agent' === $mode && class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$unapplied = self::ready_unapplied_content_artifacts( $session_id );
				if ( ! empty( $unapplied ) && ! self::planned_includes_artifact_apply( $planned, $unapplied ) ) {
					$apply_tools = self::build_forced_apply_tools( $session_id, $unapplied );
					if ( ! empty( $apply_tools ) ) {
						Ahentic_Session_Repository::set_forced_tools( $session_id, $apply_tools );
						Ahentic_Session_Repository::append_trace(
							$session_id,
							'apply_required',
							'Ready artifacts staged — forcing apply',
							array(
								'keys'  => $unapplied,
								'tools' => $apply_tools,
							),
							(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
						);
					}
				}
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
				// Models sometimes emit "input": [] (JSON list) instead of {}.
				if ( self::is_list_array( $input ) ) {
					$input = array();
				}
				if (
					class_exists( 'Ahentic_Session_Artifacts' )
					&& Ahentic_Session_Artifacts::STAGE === $name
				) {
					$input = Ahentic_Session_Artifacts::coerce_stage_input( $input );
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
			$result              = null;
			$prior_text          = '';
			$last_error          = null;
			$prior_truncated     = false;
			$prior_truncated_key = '';
			$max_attempts        = self::MAX_DEBUG_ATTEMPTS;

			for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
				$steps_so_far = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

				$user_suffix = '';
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

					Ahentic_Session_Repository::append_trace(
						$session_id,
						'debug_retry',
						sprintf( 'Retrying for AHENTIC_DEBUG (%d/%d)', $attempt, $max_attempts ),
						array(
							'attempt'       => $attempt,
							'max'           => $max_attempts,
							'prior_excerpt' => self::excerpt( $prior_text, 160 ),
							// Which failure is actually burning attempts.
							'reason'        => $prior_truncated ? 'truncated' : 'no_usable_block',
							'truncated_key' => $prior_truncated_key,
						),
						$steps_so_far
					);
				}

				// Only the first attempt of a think phase consumes a step toward MAX_STEPS_PER_RUN.
				// Format / empty-reply retries are internal and must not burn the budget.
				$result = self::run_llm_phase(
					$session_id,
					$progress,
					$system,
					null,
					$user_suffix,
					1 === $attempt
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
		 * @param bool        $bump_step   When false (AHENTIC_DEBUG / empty-reply retries), reuse the
		 *                                 current step number and do not consume MAX_STEPS_PER_RUN.
		 * @return array|\WP_Error
		 */
		private static function run_llm_phase( $session_id, $progress, $system, $extra_turn = null, $user_suffix = '', $bump_step = true ) {
			if ( $bump_step ) {
				$step = Ahentic_Session_Repository::bump_step( $session_id );
			} else {
				$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
				if ( $step < 1 ) {
					$step = Ahentic_Session_Repository::bump_step( $session_id );
				}
			}
			Ahentic_Session_Repository::set_progress( $session_id, $progress, $step );

			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			$built   = self::build_chat_payload( $entries );
			$built   = self::apply_context_compaction( $session_id, $built );
			$history = $built['history'];
			$user    = $built['user'];

			$page_context_note = self::format_page_context_for_prompt( $session_id );
			if ( '' !== $page_context_note ) {
				$user .= "\n\n" . $page_context_note;
			}

			if ( class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$artifacts_note = Ahentic_Session_Artifacts::format_for_prompt( $session_id );
				if ( '' !== $artifacts_note ) {
					$user .= "\n\n" . $artifacts_note;
				}
			}

			$verify_note = self::verify_context_for_prompt( $session_id );
			if ( '' !== $verify_note ) {
				$user .= "\n\n" . $verify_note;
			}

			$pinned = self::pinned_run_context_for_prompt( $session_id );
			if ( '' !== $pinned ) {
				$user = $pinned . "\n\n" . $user;
			}

			if ( is_string( $user_suffix ) && '' !== trim( $user_suffix ) ) {
				$user .= "\n\n" . trim( $user_suffix );
			}

			// Accumulated tool results push the system-prompt format spec far out of recency,
			// so the protocol is re-anchored as the last thing the model reads every turn.
			$user .= "\n\n" . '[Format reminder] Output exactly one <<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>> block FIRST '
				. '(intention, thinking, tools_planned, next), then the short user-facing reply. '
				. 'This applies to every turn, including verification and read-back steps — never reply with prose only.';

			if ( is_array( $extra_turn ) && ! empty( $extra_turn['content'] ) ) {
				$history[] = array(
					'role'    => 'assistant',
					'content' => (string) $extra_turn['content'],
				);
			}

			$step_summary = $bump_step
				? sprintf( 'Step %d — %s', $step, $progress )
				: sprintf( 'Step %d (retry) — %s', $step, $progress );

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'step_start',
				$step_summary,
				array(
					'progress'      => $progress,
					'history_turns' => count( $history ),
					'debug_retry'   => ! $bump_step,
					'compacted'     => ! empty( $built['compacted'] ),
					'superseded'    => isset( $built['superseded'] ) ? (int) $built['superseded'] : 0,
				),
				$step
			);

			if ( ! empty( $built['clipped'] ) ) {
				$clip_names = array();
				foreach ( $built['clipped'] as $clip ) {
					$clip_names[] = $clip['ability'] . ' ' . $clip['len'] . '/' . $clip['cap'];
				}
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'prompt_clipped',
					sprintf( 'Tool results clipped for the prompt: %s', implode( ', ', $clip_names ) ),
					array( 'clipped' => $built['clipped'] ),
					$step
				);
			}

			$max_output = self::max_output_tokens_for_session( $session_id );

			// Prompt sizes, not prompt text: a runaway context is the single most
			// common cause of slow runs and is invisible from excerpts alone.
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'llm_request',
				sprintf( 'LLM request — prompt %dc', strlen( $system ) + strlen( $user ) ),
				array(
					'progress'      => $progress,
					'user_excerpt'  => self::excerpt( $user, 120 ),
					'debug_retry'   => ! $bump_step,
					'system_len'    => strlen( $system ),
					'user_len'      => strlen( $user ),
					'history_turns' => count( $history ),
					'max_output'    => $max_output,
					'content_work'  => Ahentic_Session_Repository::get_content_work( $session_id ),
				),
				$step
			);

			$started_ms = (int) round( microtime( true ) * 1000 );
			$result     = Ahentic_AI::complete_chat(
				$system,
				$history,
				$user,
				array(
					'max_output_tokens' => $max_output,
					'session_id'        => $session_id,
				)
			);
			$elapsed_ms = (int) round( microtime( true ) * 1000 ) - $started_ms;
			if ( is_wp_error( $result ) ) {
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'llm_error',
					sprintf( 'LLM failed after %dms', $elapsed_ms ),
					array(
						'code'        => $result->get_error_code(),
						'message'     => $result->get_error_message(),
						'duration_ms' => $elapsed_ms,
					),
					$step
				);
				return $result;
			}

			Ahentic_Session_Repository::add_tokens(
				$session_id,
				$result['tokens_in'],
				$result['tokens_out'],
				$result['tokens_total']
			);

			$debug = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : null;

			// The parse layer repairs a derivable `next` so a misspelling does not cost a
			// whole round trip; record it so the debugger still shows what was recovered.
			if ( ! empty( $result['debug_normalized'] ) ) {
				$normalized = $result['debug_normalized'];
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'debug_normalized',
					sprintf(
						/* translators: 1: recovered next value, 2: how the value was derived */
						__( 'Recovered next=%1$s (%2$s) — retry avoided', 'ahentic' ),
						$normalized['to'],
						$normalized['reason']
					),
					array_merge(
						$normalized,
						array(
							'truncated'     => ! empty( $result['truncated'] ),
							'truncated_key' => isset( $result['truncated_key'] ) ? (string) $result['truncated_key'] : '',
						)
					),
					$step
				);
			}

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
				sprintf( 'LLM response — %dms, %d out', $elapsed_ms, (int) $result['tokens_out'] ),
				array(
					'model'         => $result['model'],
					'tokens_in'     => $result['tokens_in'],
					'tokens_out'    => $result['tokens_out'],
					'tokens_total'  => $result['tokens_total'],
					'duration_ms'   => $elapsed_ms,
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
				$plan      = self::normalize_plan_from_debug( $debug );
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
		 * Finish when possible; otherwise keep the run alive for verification / apply.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $result     LLM result.
		 * @param array $debug      Debug meta.
		 * @return bool True when the run should continue (another step); false when finished.
		 */
		private static function try_finish_with_reply( $session_id, array $result, $debug = array() ) {
			$mode = Ahentic_Session_Repository::get_mode( $session_id );

			if ( 'agent' === $mode ) {
				$unapplied = self::ready_unapplied_content_artifacts( $session_id );
				if ( ! empty( $unapplied ) ) {
					self::stash_pending_final( $session_id, $result, $debug );
					$apply_tools = self::build_forced_apply_tools( $session_id, $unapplied );
					if ( ! empty( $apply_tools ) ) {
						Ahentic_Session_Repository::set_forced_tools( $session_id, $apply_tools );
					}
					$keys = implode( ', ', $unapplied );
					Ahentic_Session_Repository::set_progress(
						$session_id,
						__( 'Applying staged draft…', 'ahentic' )
					);
					Ahentic_Session_Repository::set_thought(
						$session_id,
						sprintf(
							/* translators: %s: artifact keys */
							__( 'A draft is staged (%s) but not applied yet — applying via from_memory before finishing.', 'ahentic' ),
							$keys
						)
					);
					Ahentic_Session_Repository::append_trace(
						$session_id,
						'apply_required',
						'Ready artifacts not applied — continuing',
						array(
							'keys'  => $unapplied,
							'tools' => $apply_tools,
						),
						(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
					);
					return true;
				}

				if ( ! empty( Ahentic_Session_Repository::get_verify_pending( $session_id ) ) ) {
					$gate = self::run_verification_gate( $session_id, $result, $debug );
					if ( 'continue' === $gate ) {
						return true;
					}
					if ( is_array( $gate ) && isset( $gate['result'] ) ) {
						$result = $gate['result'];
						$debug  = isset( $gate['debug'] ) ? $gate['debug'] : $debug;
					}
				}
			}

			self::finish_with_reply( $session_id, $result, $debug );
			return false;
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
			Ahentic_Session_Repository::clear_thought( $session_id );

			$stashed = Ahentic_Session_Repository::get_pending_final( $session_id );
			if ( is_array( $stashed ) && ! empty( $stashed['text'] ) ) {
				$current = isset( $result['text'] ) ? trim( (string) $result['text'] ) : '';
				// Prefer a real stashed closing reply over empty / process-y / repair boilerplate.
				if ( '' === $current || self::reply_looks_like_process( $current ) ) {
					$result['text'] = (string) $stashed['text'];
					if ( empty( $result['model'] ) && ! empty( $stashed['model'] ) ) {
						$result['model'] = $stashed['model'];
					}
					if ( ( ! is_array( $debug ) || empty( $debug ) ) && ! empty( $stashed['debug'] ) && is_array( $stashed['debug'] ) ) {
						$debug = $stashed['debug'];
					}
				}
			}
			Ahentic_Session_Repository::clear_pending_final( $session_id );
			Ahentic_Session_Repository::clear_verify_attempts( $session_id );

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

			$content = isset( $result['text'] ) ? trim( (string) $result['text'] ) : '';
			if ( '' === $content && is_array( $debug ) ) {
				$content = trim( (string) Ahentic_AI::fallback_reply_from_debug( $debug ) );
			}
			if ( '' === $content || self::reply_looks_like_process( $content ) ) {
				$content = self::user_facing_finish_fallback( $debug, $content );
			}

			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'assistant',
					'content' => $content,
					'meta'    => $meta,
				)
			);

			self::complete_plan_on_finish( $session_id );

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
			$mode  = Ahentic_Session_Repository::get_mode( $session_id );
			if ( '' === $name || ! in_array( $name, Ahentic_Abilities::available_for_mode( $mode ), true ) ) {
				if ( 'ask' === $mode && in_array( $name, Ahentic_Abilities::available_for_agent(), true ) && ! Ahentic_Abilities::is_readonly( $name ) ) {
					return new WP_Error(
						'ahentic_ask_readonly',
						__( 'Ask mode can only run read-only actions. Switch to Agent mode to change the site.', 'ahentic' ),
						array( 'status' => 400 )
					);
				}
				return new WP_Error( 'ahentic_unknown_action', __( 'That action is not available.', 'ahentic' ), array( 'status' => 400 ) );
			}

			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );

			$run = Ahentic_Tool_Runner::run(
				$session_id,
				$name,
				$input,
				array(
					'step'       => $step,
					'source'     => 'suggested_action',
					'auto_stage' => false,
				)
			);

			if ( in_array( $run['outcome'], array( 'paused_hitl', 'paused_browser' ), true ) ) {
				return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
			}

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
		 * Normalize a WP_Error into the tool-result payload the agent reads.
		 *
		 * Includes error_data fields (hint, meta_skipped, next_tool, etc.) when present.
		 *
		 * @param \WP_Error $error Error from an ability.
		 * @return array
		 */
		public static function tool_error_payload( $error ) {
			$payload = array(
				'ok'      => false,
				'error'   => $error->get_error_code(),
				'message' => $error->get_error_message(),
			);

			$data = $error->get_error_data();
			if ( is_array( $data ) ) {
				foreach ( $data as $key => $value ) {
					$key = (string) $key;
					if ( '' === $key || isset( $payload[ $key ] ) ) {
						continue;
					}
					// Keep HTTP status internal; surface agent-facing recovery fields.
					if ( 'status' === $key ) {
						continue;
					}
					$payload[ $key ] = $value;
				}
			}

			return $payload;
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
		 * Apply a multi-step plan from the AHENTIC_DEBUG control block.
		 *
		 * Plans are orchestrator state (not abilities). A new plan is shown when
		 * it has at least one step; later thinks may update statuses.
		 * Completed steps from a prior plan are preserved if the model omits them.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $debug      Parsed debug block.
		 */
		private static function apply_plan_from_debug( $session_id, $debug ) {
			if ( ! is_array( $debug ) || ! array_key_exists( 'plan', $debug ) ) {
				return;
			}

			// Explicit null / empty clears only when a plan was already visible.
			if ( null === $debug['plan'] || false === $debug['plan'] || '' === $debug['plan'] ) {
				return;
			}

			$normalized = self::normalize_plan_from_debug( $debug );
			if ( null === $normalized ) {
				return;
			}

			$existing = Ahentic_Session_Repository::get_plan( $session_id );
			$step_n   = count( $normalized['steps'] );

			// First visible plan requires ≥ MIN_PLAN_STEPS (1); updates may refine.
			if ( null === $existing && $step_n < self::MIN_PLAN_STEPS ) {
				return;
			}
			if ( $step_n < 1 ) {
				return;
			}

			$merged  = self::merge_plan_with_existing( $normalized, $existing );
			$changed = Ahentic_Session_Repository::set_plan( $session_id, $merged );
			if ( ! $changed ) {
				return;
			}

			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'plan_updated',
				self::plan_trace_summary( $merged ),
				array(
					'title' => isset( $merged['title'] ) ? $merged['title'] : '',
					'steps' => $merged['steps'],
				),
				$step
			);
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
		private static function resolve_thought_process_for_chat( array $result, array $debug ) {
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
		private static function publish_thought_process( $session_id, array $result, array $debug ) {
			$content = self::resolve_thought_process_for_chat( $result, $debug );
			if ( '' === $content ) {
				return;
			}
			Ahentic_Session_Repository::set_thought( $session_id, $content );
			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'thought_process',
				self::excerpt( $content, 120 ),
				array(
					'text' => self::excerpt( $content, 400 ),
				),
				$step
			);
		}

		/**
		 * Keep finished steps when the model re-sends only remaining work.
		 *
		 * @param array      $incoming Normalized incoming plan.
		 * @param array|null $existing Existing plan from session meta.
		 * @return array
		 */
		private static function merge_plan_with_existing( array $incoming, $existing ) {
			if ( ! is_array( $existing ) || empty( $existing['steps'] ) || ! is_array( $existing['steps'] ) ) {
				return $incoming;
			}

			$incoming_steps = isset( $incoming['steps'] ) && is_array( $incoming['steps'] )
				? $incoming['steps']
				: array();
			$by_id = array();
			foreach ( $incoming_steps as $step ) {
				if ( ! is_array( $step ) || empty( $step['id'] ) ) {
					continue;
				}
				$by_id[ (string) $step['id'] ] = $step;
			}

			$merged = array();
			$seen   = array();

			// Preserve prior order; keep completed/cancelled even if omitted from the update.
			foreach ( $existing['steps'] as $old ) {
				if ( ! is_array( $old ) || empty( $old['id'] ) ) {
					continue;
				}
				$id = (string) $old['id'];
				if ( isset( $by_id[ $id ] ) ) {
					$merged[]     = $by_id[ $id ];
					$seen[ $id ] = true;
					continue;
				}
				$old_status = isset( $old['status'] ) ? (string) $old['status'] : 'pending';
				if ( in_array( $old_status, array( 'completed', 'cancelled' ), true ) ) {
					$merged[]     = $old;
					$seen[ $id ] = true;
				}
			}

			foreach ( $incoming_steps as $step ) {
				if ( ! is_array( $step ) || empty( $step['id'] ) ) {
					continue;
				}
				$id = (string) $step['id'];
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$merged[]     = $step;
				$seen[ $id ] = true;
			}

			if ( empty( $merged ) ) {
				return $incoming;
			}

			// Cap and keep a single in_progress.
			$merged      = array_slice( $merged, 0, self::MAX_PLAN_STEPS );
			$in_progress = 0;
			foreach ( $merged as $i => $step ) {
				if ( ! isset( $step['status'] ) || 'in_progress' !== $step['status'] ) {
					continue;
				}
				++$in_progress;
				if ( $in_progress > 1 ) {
					$merged[ $i ]['status'] = 'pending';
				}
			}

			$title = isset( $incoming['title'] ) ? trim( (string) $incoming['title'] ) : '';
			if ( '' === $title && ! empty( $existing['title'] ) ) {
				$title = (string) $existing['title'];
			}

			return array(
				'title' => $title,
				'steps' => array_values( $merged ),
			);
		}

		/**
		 * Normalize debug.plan into { title, steps } or null.
		 *
		 * @param array $debug Debug block.
		 * @return array|null
		 */
		private static function normalize_plan_from_debug( $debug ) {
			if ( ! is_array( $debug ) || ! isset( $debug['plan'] ) ) {
				return null;
			}

			$raw = $debug['plan'];
			if ( ! is_array( $raw ) ) {
				return null;
			}

			// Accept either { title, steps: [...] } or a bare steps array.
			$title = '';
			$steps_raw = $raw;
			if ( isset( $raw['steps'] ) && is_array( $raw['steps'] ) ) {
				$title     = isset( $raw['title'] ) ? trim( (string) $raw['title'] ) : '';
				$steps_raw = $raw['steps'];
			} elseif ( self::is_list_array( $raw ) ) {
				$steps_raw = $raw;
			} else {
				return null;
			}

			$steps        = array();
			$in_progress  = 0;
			foreach ( $steps_raw as $index => $item ) {
				if ( count( $steps ) >= self::MAX_PLAN_STEPS ) {
					break;
				}
				if ( is_string( $item ) ) {
					$content = trim( $item );
					$status  = 'pending';
					$id      = (string) ( $index + 1 );
				} elseif ( is_array( $item ) ) {
					$content = isset( $item['content'] )
						? trim( (string) $item['content'] )
						: ( isset( $item['label'] ) ? trim( (string) $item['label'] ) : '' );
					if ( '' === $content && isset( $item['text'] ) ) {
						$content = trim( (string) $item['text'] );
					}
					$status = isset( $item['status'] ) ? (string) $item['status'] : 'pending';
					if ( ! in_array( $status, array( 'pending', 'in_progress', 'completed', 'cancelled' ), true ) ) {
						$status = 'pending';
					}
					$id = isset( $item['id'] ) ? trim( (string) $item['id'] ) : '';
					if ( '' === $id ) {
						$id = (string) ( $index + 1 );
					}
				} else {
					continue;
				}

				if ( '' === $content ) {
					continue;
				}

				// Keep a single in_progress step for clearer UI.
				if ( 'in_progress' === $status ) {
					++$in_progress;
					if ( $in_progress > 1 ) {
						$status = 'pending';
					}
				}

				$steps[] = array(
					'id'      => $id,
					'content' => $content,
					'status'  => $status,
				);
			}

			if ( empty( $steps ) ) {
				return null;
			}

			return array(
				'title' => $title,
				'steps' => $steps,
			);
		}

		/**
		 * Whether an array is a list (0..n-1 keys).
		 *
		 * @param array $arr Array.
		 * @return bool
		 */
		private static function is_list_array( array $arr ) {
			if ( function_exists( 'array_is_list' ) ) {
				return array_is_list( $arr );
			}
			if ( array() === $arr ) {
				return true;
			}
			return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
		}

		/**
		 * Short trace summary for a plan update.
		 *
		 * @param array $plan Normalized plan.
		 * @return string
		 */
		private static function plan_trace_summary( array $plan ) {
			$steps = isset( $plan['steps'] ) && is_array( $plan['steps'] ) ? $plan['steps'] : array();
			$total = count( $steps );
			$done  = 0;
			foreach ( $steps as $step ) {
				if ( isset( $step['status'] ) && 'completed' === $step['status'] ) {
					++$done;
				}
			}
			$title = isset( $plan['title'] ) ? trim( (string) $plan['title'] ) : '';
			if ( '' !== $title ) {
				return sprintf( 'Plan updated: %s (%d/%d)', $title, $done, $total );
			}
			return sprintf( 'Plan updated (%d/%d complete)', $done, $total );
		}

		/**
		 * Inject the current plan into the system prompt so later thinks stay aligned.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function plan_context_for_prompt( $session_id ) {
			$plan = Ahentic_Session_Repository::get_plan( $session_id );
			if ( ! is_array( $plan ) || empty( $plan['steps'] ) ) {
				return '';
			}

			$lines = array(
				'Current multi-step plan (re-send this FULL list in debug.plan every think — keep completed steps; '
				. 'only change statuses). Chat replies stay normal prose — thought process and findings — not checklist labels:',
			);
			if ( ! empty( $plan['title'] ) ) {
				$lines[] = 'Title: ' . $plan['title'];
			}
			foreach ( $plan['steps'] as $step ) {
				$id      = isset( $step['id'] ) ? (string) $step['id'] : '';
				$status  = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				$content = isset( $step['content'] ) ? (string) $step['content'] : '';
				$lines[] = sprintf( '- [%s] id=%s %s', $status, $id, $content );
			}
			$lines[] = 'When a step finishes, mark it completed (do not remove it), set the next one in_progress, '
				. 'and write a normal chat reply with what you learned. When all are done, mark every step completed.';

			return "\n\n" . implode( "\n", $lines );
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
		public static function progress_label_for_tool( $tool, $debug = array() ) {
			$map = array(
				'ahentic/list-plugins'              => __( 'Checking installed plugins…', 'ahentic' ),
				'ahentic/search-plugins'            => __( 'Searching the plugin directory…', 'ahentic' ),
				'ahentic/get-site-snapshot'         => __( 'Reading site snapshot…', 'ahentic' ),
				'ahentic/get-wordpress-guidance'    => __( 'Loading WordPress guidance…', 'ahentic' ),
				'ahentic/get-site-health'           => __( 'Checking site health…', 'ahentic' ),
				'ahentic/get-option'                => __( 'Reading site settings…', 'ahentic' ),
				'ahentic/http-fetch'                => __( 'Fetching URL…', 'ahentic' ),
				'ahentic/get-debug-log'             => __( 'Reading debug log…', 'ahentic' ),
				'ahentic/get-admin-context'         => __( 'Reading admin page context…', 'ahentic' ),
				'ahentic-browser/get-current-page'            => __( 'Reading the current page…', 'ahentic' ),
				'ahentic-browser/get-visible-page'            => __( 'Reading what is on the screen…', 'ahentic' ),
				'ahentic-browser/get-editor-state'            => __( 'Reading the block editor…', 'ahentic' ),
				'ahentic-browser/get-blocks'                  => __( 'Reading editor blocks…', 'ahentic' ),
				'ahentic-browser/get-selection'               => __( 'Reading the editor selection…', 'ahentic' ),
				'ahentic-browser/get-block-type'              => __( 'Reading block type schema…', 'ahentic' ),
				'ahentic-browser/list-block-types'            => __( 'Listing block types…', 'ahentic' ),
				'ahentic-browser/focus-block'                 => __( 'Focusing a block…', 'ahentic' ),
				'ahentic-browser/update-block-attributes'     => __( 'Updating block attributes…', 'ahentic' ),
				'ahentic-browser/replace-blocks'              => __( 'Replacing blocks…', 'ahentic' ),
				'ahentic-browser/set-blocks'                  => __( 'Setting editor blocks…', 'ahentic' ),
				'ahentic-browser/insert-blocks'               => __( 'Inserting blocks…', 'ahentic' ),
				'ahentic-browser/duplicate-blocks'            => __( 'Duplicating blocks…', 'ahentic' ),
				'ahentic-browser/move-blocks'                 => __( 'Moving blocks…', 'ahentic' ),
				'ahentic-browser/normalize-block-styles'      => __( 'Stripping custom block styles…', 'ahentic' ),
				'ahentic-browser/restyle-blocks-to-palette'   => __( 'Restyling blocks to palette…', 'ahentic' ),
				'ahentic-browser/convert-blocks'              => __( 'Converting blocks to core…', 'ahentic' ),
				'ahentic-browser/audit-accessibility'         => __( 'Auditing editor accessibility…', 'ahentic' ),
				'ahentic-browser/update-post-title'           => __( 'Updating the editor title…', 'ahentic' ),
				'ahentic-browser/save-post'                   => __( 'Saving the post…', 'ahentic' ),
				'ahentic/search-content'                     => __( 'Searching site content…', 'ahentic' ),
				'ahentic/list-content'                       => __( 'Listing posts and pages…', 'ahentic' ),
				'ahentic/generate-image'                     => __( 'Generating an image…', 'ahentic' ),
				'ahentic/upload-media'                       => __( 'Uploading media…', 'ahentic' ),
				'ahentic/describe-image'                     => __( 'Describing image…', 'ahentic' ),
				'ahentic/get-content'                        => __( 'Reading post content…', 'ahentic' ),
				'ahentic/create-post'                        => __( 'Creating a draft post…', 'ahentic' ),
				'ahentic/update-post'                        => __( 'Updating post content…', 'ahentic' ),
				'ahentic/set-post-status'                    => __( 'Updating post status…', 'ahentic' ),
				'ahentic/stage-artifact'                     => __( 'Staging session artifact…', 'ahentic' ),
				'ahentic/list-artifacts'                     => __( 'Listing session artifacts…', 'ahentic' ),
				'ahentic/delete-artifact'                    => __( 'Deleting session artifact…', 'ahentic' ),
				'ahentic/find-unused-media'                  => __( 'Scanning media for unused images…', 'ahentic' ),
				'ahentic/install-plugin'            => __( 'Installing plugin…', 'ahentic' ),
				'ahentic/activate-plugin'           => __( 'Activating plugin…', 'ahentic' ),
				'ahentic/deactivate-plugin'         => __( 'Deactivating plugin…', 'ahentic' ),
				'ahentic/uninstall-plugin'          => __( 'Uninstalling plugin…', 'ahentic' ),
				'ahentic/update-term'               => __( 'Updating taxonomy term…', 'ahentic' ),
				'core/read-content'                 => __( 'Reading site content…', 'ahentic' ),
				'ahentic/inspect-site'              => __( 'Inspecting the site…', 'ahentic' ),
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
			self::cancel_plan_on_stop( $session_id );
			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'event',
					'content' => __( 'Run cancelled.', 'ahentic' ),
					'meta'    => array( 'type' => 'cancelled' ),
				)
			);
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_idle',
				'Run cancelled by user',
				array(
					'reason' => 'user_cancel',
				)
			);
			Ahentic_Session_Repository::mark_idle( $session_id );

			return Ahentic_Session_Repository::to_rest( $session_id );
		}

		/**
		 * Skip a pending HITL tool without running it (Deny/Skip or mid-HITL redirect).
		 *
		 * Appends a tool result the model can adapt to, then clears pending_tool.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $reason     user_denied|user_redirect.
		 */
		private static function skip_pending_hitl_tool( $session_id, $reason = 'user_denied' ) {
			$session_id = (int) $session_id;
			$pending    = Ahentic_Session_Repository::get_pending_tool( $session_id );
			if ( ! $pending || empty( $pending['name'] ) ) {
				Ahentic_Session_Repository::set_pending_tool( $session_id, null );
				return;
			}

			$name   = (string) $pending['name'];
			$step   = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			$reason = sanitize_key( (string) $reason );
			if ( ! in_array( $reason, array( 'user_denied', 'user_redirect' ), true ) ) {
				$reason = 'user_denied';
			}

			if ( 'user_redirect' === $reason ) {
				$message = __( 'User skipped this action and sent a new instruction. Do not retry the same ability/input.', 'ahentic' );
				$hint    = __(
					'The user redirected with a new message. Honor that instruction. Do not retry this skipped ability/input; pursue a different approach toward their goal, or ask one clear choice if you truly cannot proceed.',
					'ahentic'
				);
			} else {
				$message = __( 'User skipped this action.', 'ahentic' );
				$hint    = __(
					'The user skipped this approval. Do not retry the same ability/input. Continue toward their goal with a different approach (alternative tools, native WordPress features, or a short ask_user with real choices). Only stop if no alternative is possible — then briefly explain and offer options.',
					'ahentic'
				);
			}

			$payload = array(
				'ok'      => false,
				'error'   => 'user_denied',
				'skipped' => true,
				'reason'  => $reason,
				'message' => $message,
				'hint'    => $hint,
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
						'skipped' => true,
						'reason'  => $reason,
					),
				)
			);
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'hitl_denied',
				( 'user_redirect' === $reason ? 'Skipped (redirect): ' : 'Skipped: ' ) . $name,
				array(
					'ability' => $name,
					'reason'  => $reason,
				),
				$step
			);
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
				self::skip_pending_hitl_tool( $session_id, 'user_denied' );
				Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
				Ahentic_Session_Repository::set_progress( $session_id, __( 'Skipping that action…', 'ahentic' ), $step );
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

			$run = Ahentic_Tool_Runner::run(
				$session_id,
				$name,
				$input,
				array(
					'step'      => $step,
					'skip_hitl' => true,
					'approved'  => $choice,
					'auto_stage' => false,
				)
			);

			if ( 'paused_browser' === $run['outcome'] ) {
				return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
			}

			// Keep the approved tool's step label visible into the next think.
			Ahentic_Step_Queue::enqueue_step( $session_id );
			Ahentic_Step_Queue::schedule_interactive_run( $session_id );

			return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
		}

		/**
		 * Browser ability result resume.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $payload    { call_id, result|error }.
		 * @return array|\WP_Error
		 */
		public static function handle_browser_result( $session_id, array $payload ) {
			$pending = Ahentic_Session_Repository::get_pending_tool( $session_id );
			if ( ! $pending || empty( $pending['name'] ) ) {
				return new WP_Error( 'ahentic_no_pending', __( 'No pending browser tool.', 'ahentic' ), array( 'status' => 400 ) );
			}

			$status = Ahentic_Session_Repository::get_status( $session_id );
			if ( Ahentic_Session_Repository::STATUS_AWAITING_BROWSER !== $status ) {
				return new WP_Error( 'ahentic_not_awaiting', __( 'This session is not waiting for a browser result.', 'ahentic' ), array( 'status' => 409 ) );
			}

			$call_id = isset( $payload['call_id'] ) ? (string) $payload['call_id'] : '';
			if ( $call_id && ! empty( $pending['call_id'] ) && $call_id !== (string) $pending['call_id'] ) {
				return new WP_Error( 'ahentic_call_mismatch', __( 'Browser result does not match the pending tool.', 'ahentic' ), array( 'status' => 409 ) );
			}

			$name = (string) $pending['name'];
			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			$ok   = empty( $payload['error'] );
			$artifact_key = ! empty( $pending['artifact_key'] ) ? (string) $pending['artifact_key'] : '';

			if ( $ok ) {
				$result = isset( $payload['result'] ) ? $payload['result'] : $payload;
				if ( ! is_array( $result ) ) {
					$result = array( 'value' => $result );
				}
				// Nested ok:false from browser handlers is still a tool failure.
				if ( array_key_exists( 'ok', $result ) && false === $result['ok'] ) {
					$ok           = false;
					$tool_payload = $result;
				} else {
					// Keep page identity fresh when browser page reads succeed.
					if ( 'ahentic-browser/get-current-page' === $name || 'ahentic-browser/get-visible-page' === $name ) {
						Ahentic_Session_Repository::set_page_context( $session_id, $result );
					}
					$tool_payload = $result;
				}
			} else {
				$tool_payload = array(
					'ok'      => false,
					'error'   => 'browser_error',
					'message' => is_string( $payload['error'] ) ? $payload['error'] : __( 'Browser ability failed.', 'ahentic' ),
					'ability' => $name,
				);
			}

			Ahentic_Tool_Runner::record_completed_result(
				$session_id,
				$name,
				$tool_payload,
				$ok,
				array(
					'call_id'      => $call_id ? $call_id : ( isset( $pending['call_id'] ) ? (string) $pending['call_id'] : '' ),
					'browser'      => true,
					'artifact_key' => $artifact_key ? $artifact_key : null,
				),
				$step
			);

			Ahentic_Step_Queue::enqueue_step( $session_id );
			Ahentic_Step_Queue::schedule_interactive_run( $session_id );

			return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
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
		 * Format stored sidebar page context for the model user turn.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function format_page_context_for_prompt( $session_id ) {
			$ctx = Ahentic_Session_Repository::get_page_context( $session_id );
			if ( empty( $ctx ) || ! is_array( $ctx ) ) {
				return '';
			}

			$lines = array( '---', 'Active browser page context (user’s open tab; trust this over guessing):' );

			if ( ! empty( $ctx['url'] ) ) {
				$lines[] = '- url: ' . (string) $ctx['url'];
			}
			if ( ! empty( $ctx['title'] ) ) {
				$lines[] = '- document_title: ' . (string) $ctx['title'];
			}
			if ( array_key_exists( 'isAdmin', $ctx ) ) {
				$lines[] = '- is_admin: ' . ( ! empty( $ctx['isAdmin'] ) ? 'true' : 'false' );
			}

			$in_editor = ! empty( $ctx['is_block_editor'] );
			$lines[]   = '- is_block_editor: ' . ( $in_editor ? 'true' : 'false' );

			if ( $in_editor ) {
				$lines[] = '- post_id: ' . ( isset( $ctx['post_id'] ) && null !== $ctx['post_id'] && '' !== $ctx['post_id']
					? (string) (int) $ctx['post_id']
					: 'null (new unsaved document)' );
				if ( ! empty( $ctx['post_type'] ) ) {
					$lines[] = '- post_type: ' . (string) $ctx['post_type'];
				}
				if ( array_key_exists( 'editor_title', $ctx ) ) {
					$lines[] = '- editor_title: ' . ( '' !== (string) $ctx['editor_title']
						? (string) $ctx['editor_title']
						: '(empty)' );
				}
				if ( ! empty( $ctx['status'] ) ) {
					$lines[] = '- status: ' . (string) $ctx['status'];
				}
				$lines[] = '- is_new: ' . ( ! empty( $ctx['is_new'] ) ? 'true' : 'false' );
				$lines[] = '- is_dirty: ' . ( ! empty( $ctx['is_dirty'] ) ? 'true' : 'false' );
				$lines[] = '- blocks_count: ' . (string) (int) ( isset( $ctx['blocks_count'] ) ? $ctx['blocks_count'] : 0 );
				$lines[] = '- routing: is_block_editor=true — edit THIS open document with ahentic-browser/* '
					. '(update-post-title, set-blocks, insert-blocks, replace-blocks, update-block-attributes). '
					. 'Do not ahentic/update-post (or other server body writes) for this document while the editor is open. '
					. 'Do not ahentic/create-post unless the user explicitly wants a separate post/page. '
					. 'Do not ahentic-browser/save-post unless the user explicitly asks to save/publish. '
					. 'Pass real block objects {name, attributes, innerBlocks} — never bracket stubs, plain-text descriptions, or clientId hashes. '
					. 'Block addressing uses short refs (b1, b2, …) from get-blocks/get-selection; for a full rewrite prefer set-blocks (no refs needed).';
				$cheatsheet = self::format_core_blocks_cheatsheet_for_prompt();
				if ( '' !== $cheatsheet ) {
					$lines[] = $cheatsheet;
				}
			} else {
				$lines[] = '- routing: Block editor is not open — prefer server content abilities '
					. '(ahentic/create-post, ahentic/update-post, ahentic/set-post-status) for drafts and body edits. '
					. 'Pass real post content — never bracket stubs or shorthand placeholders.';
			}

			return implode( "\n", $lines );
		}

		/**
		 * Load curated core-block cheatsheet (distilled from Gutenberg block.json).
		 *
		 * @return array<string, mixed>
		 */
		private static function load_core_blocks_cheatsheet() {
			static $cached = null;
			if ( null !== $cached ) {
				return $cached;
			}
			$cached = array();
			$path   = plugin_dir_path( AHENTIC_FILE ) . 'src/data/core-blocks-cheatsheet.json';
			if ( ! is_readable( $path ) ) {
				return $cached;
			}
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin data file.
			if ( false === $raw || '' === $raw ) {
				return $cached;
			}
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				return $cached;
			}
			$cached = $data;
			return $cached;
		}

		/**
		 * Compact core-block cookbook + cheatsheet for editor turns.
		 *
		 * @return string
		 */
		private static function format_core_blocks_cheatsheet_for_prompt() {
			$data = self::load_core_blocks_cheatsheet();
			if ( empty( $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
				return '';
			}

			$lines   = array();
			$lines[] = '- core_blocks_cookbook (curated; source Gutenberg block-library — do not call get-block-type for these unless an update fails):';
			$lines[] = '  Rules: rich-text attrs accept HTML strings; address blocks with refs (b1, b2) from get-blocks — never clientId hashes; skip get-block-type for core/* text blocks; prefer set-blocks for full-document rewrites.';

			foreach ( $data['blocks'] as $name => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$parts = array();
				foreach ( $attrs as $attr_key => $attr_desc ) {
					$parts[] = $attr_key . '=' . (string) $attr_desc;
				}
				$rich = isset( $block['rich_text'] ) && is_array( $block['rich_text'] ) ? $block['rich_text'] : array();
				$tip  = isset( $block['tip'] ) ? (string) $block['tip'] : '';
				$line = '  • ' . (string) $name;
				if ( ! empty( $parts ) ) {
					$line .= ' — ' . implode( '; ', $parts );
				}
				if ( ! empty( $rich ) ) {
					$line .= ' [rich-text: ' . implode( ',', array_map( 'strval', $rich ) ) . ']';
				}
				if ( '' !== $tip ) {
					$line .= ' — ' . $tip;
				}
				$lines[] = $line;
			}

			if ( ! empty( $data['source'] ) ) {
				$lines[] = '  Ref: ' . (string) $data['source'];
			}

			return implode( "\n", $lines );
		}

		/**
		 * Build history + latest user message for the model.
		 *
		 * Tool results since the last user message are appended to the user prompt
		 * so the next think can observe them.
		 *
		 * @param array $entries Session entries.
		 * @return array{history: array, user: string, clipped: array, superseded: int}
		 */
		private static function build_chat_payload( array $entries ) {
			$latest_snapshot = self::latest_live_editor_snapshots( $entries );
			$clipped         = array();
			$superseded      = 0;

			$normalized = array();
			foreach ( $entries as $i => $entry ) {
				if ( ! empty( $entry['meta']['error'] ) ) {
					continue;
				}
				$role = isset( $entry['role'] ) ? $entry['role'] : '';
				if ( 'user' === $role || 'assistant' === $role ) {
					if ( ! empty( $entry['meta']['thought_process'] ) || ! empty( $entry['meta']['intermediate'] ) ) {
						continue;
					}
					$normalized[] = array(
						'role'    => $role,
						'content' => (string) $entry['content'],
					);
				} elseif ( 'tool' === $role ) {
					$ability  = isset( $entry['meta']['ability'] ) ? (string) $entry['meta']['ability'] : 'tool';
					$snapshot = isset( $latest_snapshot[ $ability ] );

					if ( $snapshot && $latest_snapshot[ $ability ] !== $i ) {
						$body = '[Superseded — a newer ' . $ability . ' result appears below.]';
						++$superseded;
					} else {
						$raw_len = strlen( (string) $entry['content'] );
						$cap     = $snapshot ? self::MAX_TOOL_RESULT_CHARS_SNAPSHOT : self::MAX_TOOL_RESULT_CHARS;
						$body    = self::truncate_tool_result_for_prompt( (string) $entry['content'], $cap );
						if ( $raw_len > $cap ) {
							// A clipped read-back makes the model re-read what it can never see,
							// so record it: this was invisible and cost a full debugging round.
							$clipped[] = array(
								'ability' => $ability,
								'len'     => $raw_len,
								'cap'     => $cap,
							);
						}
					}

					$normalized[] = array(
						'role'    => 'tool',
						'content' => '[Ability result: ' . $ability . "]\n" . $body,
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
					'history'    => array(),
					'user'       => '',
					'clipped'    => $clipped,
					'superseded' => $superseded,
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
				$user .= "\n\n---\nAbility results from this run (use these facts; do not invent conflicting data). "
					. "If a result includes block ref values (b1, b2, …), pass those refs back to tools EXACTLY as printed — "
					. "never invent Gutenberg clientId UUID hashes. "
					. "ok:true means the mutate applied — but if this message (or session) still lists pending write verification "
					. "or ready unapplied artifacts, you MUST set next=\"use_tools\" for the required readonly check / from_memory apply "
					. "before next=\"reply\". Do not claim the article is finished from chat alone:\n"
					. implode( "\n\n", $chunks );
			}

			if ( count( $history ) > self::MAX_HISTORY_TURNS ) {
				$history = array_slice( $history, -1 * self::MAX_HISTORY_TURNS );
			}

			return array(
				'history'    => $history,
				'user'       => $user,
				'compacted'  => false,
				'clipped'    => $clipped,
				'superseded' => $superseded,
			);
		}

		/**
		 * Mid-run compaction: summarize older history; never drop plan / artifacts / latest goal.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $payload    From build_chat_payload.
		 * @return array{history: array, user: string, compacted?: bool}
		 */
		private static function apply_context_compaction( $session_id, array $payload ) {
			$history = isset( $payload['history'] ) && is_array( $payload['history'] ) ? $payload['history'] : array();
			$user    = isset( $payload['user'] ) ? (string) $payload['user'] : '';
			// Prompt-shaping notes are diagnostics about the build, so they survive compaction.
			$notes   = array(
				'clipped'    => isset( $payload['clipped'] ) && is_array( $payload['clipped'] ) ? $payload['clipped'] : array(),
				'superseded' => isset( $payload['superseded'] ) ? (int) $payload['superseded'] : 0,
			);

			$chars = strlen( $user );
			foreach ( $history as $turn ) {
				$chars += isset( $turn['content'] ) ? strlen( (string) $turn['content'] ) : 0;
			}

			$needs = count( $history ) > self::COMPACT_HISTORY_THRESHOLD || $chars > self::COMPACT_CHAR_THRESHOLD;
			if ( ! $needs ) {
				return array_merge(
					$notes,
					array(
						'history'   => $history,
						'user'      => $user,
						'compacted' => false,
					)
				);
			}

			$keep_n = min( self::COMPACT_KEEP_RECENT, count( $history ) );
			$keep   = $keep_n > 0 ? array_slice( $history, -1 * $keep_n ) : array();
			$old    = $keep_n > 0 ? array_slice( $history, 0, -1 * $keep_n ) : $history;

			$summary = self::build_extractive_context_summary( $session_id, $old );
			Ahentic_Session_Repository::set_context_summary( $session_id, $summary );

			$compacted_history = array();
			if ( '' !== $summary ) {
				$compacted_history[] = array(
					'role'    => 'user',
					'content' => "[Earlier in this session — compact summary; current plan, artifact keys, and latest goal are pinned separately and must not be ignored]\n"
						. $summary,
				);
				$compacted_history[] = array(
					'role'    => 'assistant',
					'content' => 'Understood. I will continue from the pinned plan, artifacts, and latest user goal.',
				);
			}

			foreach ( $keep as $turn ) {
				$compacted_history[] = $turn;
			}

			if ( count( $compacted_history ) > self::MAX_HISTORY_TURNS ) {
				$compacted_history = array_slice( $compacted_history, -1 * self::MAX_HISTORY_TURNS );
			}

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'context_compact',
				'Compacted older chat/tool context for this think',
				array(
					'old_turns'  => count( $old ),
					'kept_turns' => count( $keep ),
					'summary_len'=> strlen( $summary ),
				),
				(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
			);

			return array_merge(
				$notes,
				array(
					'history'   => $compacted_history,
					'user'      => $user,
					'compacted' => true,
				)
			);
		}

		/**
		 * Extractive rolling summary of older turns (no extra LLM call).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $turns      Older history turns.
		 * @return string
		 */
		private static function build_extractive_context_summary( $session_id, array $turns ) {
			$prior = trim( (string) Ahentic_Session_Repository::get_context_summary( $session_id ) );
			$lines = array();
			if ( '' !== $prior ) {
				$lines[] = 'Previous compact notes: ' . self::excerpt( $prior, 800 );
			}

			foreach ( $turns as $turn ) {
				if ( ! is_array( $turn ) ) {
					continue;
				}
				$role = isset( $turn['role'] ) ? (string) $turn['role'] : 'user';
				$text = isset( $turn['content'] ) ? trim( (string) $turn['content'] ) : '';
				if ( '' === $text ) {
					continue;
				}
				$label = ( 'assistant' === $role || 'model' === $role ) ? 'Assistant/tool' : 'User';
				// Tool-shaped lines get a tighter excerpt.
				$limit = ( 0 === strpos( $text, '[Ability result:' ) ) ? 280 : 420;
				$lines[] = $label . ': ' . self::excerpt( $text, $limit );
			}

			$summary = implode( "\n", $lines );
			if ( strlen( $summary ) > self::COMPACT_SUMMARY_MAX_CHARS ) {
				$summary = substr( $summary, -1 * self::COMPACT_SUMMARY_MAX_CHARS );
				$nl      = strpos( $summary, "\n" );
				if ( false !== $nl && $nl < 200 ) {
					$summary = substr( $summary, $nl + 1 );
				}
			}
			return trim( $summary );
		}

		/**
		 * Always-retained mid-run pins: latest goal + plan (artifacts added separately).
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function pinned_run_context_for_prompt( $session_id ) {
			$parts = array();

			$goal = self::latest_user_goal_excerpt( $session_id );
			if ( '' !== $goal ) {
				$parts[] = 'Latest user goal: ' . $goal;
			}

			$plan = Ahentic_Session_Repository::get_plan( $session_id );
			if ( is_array( $plan ) && ! empty( $plan['steps'] ) && is_array( $plan['steps'] ) ) {
				$title = isset( $plan['title'] ) ? trim( (string) $plan['title'] ) : '';
				$step_bits = array();
				foreach ( $plan['steps'] as $step ) {
					if ( ! is_array( $step ) ) {
						continue;
					}
					$status  = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
					$content = isset( $step['content'] ) ? trim( (string) $step['content'] ) : '';
					if ( '' === $content ) {
						continue;
					}
					$step_bits[] = '[' . $status . '] ' . $content;
				}
				if ( ! empty( $step_bits ) ) {
					$parts[] = 'Current plan'
						. ( '' !== $title ? ' (“' . $title . '”)' : '' )
						. ': '
						. implode( '; ', $step_bits );
				}
			}

			if ( empty( $parts ) ) {
				return '';
			}

			return "---\nPinned run context (must retain — do not drop):\n- " . implode( "\n- ", $parts );
		}

		/**
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function latest_user_goal_excerpt( $session_id ) {
			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				$entry = $entries[ $i ];
				if ( ! is_array( $entry ) || 'user' !== ( isset( $entry['role'] ) ? $entry['role'] : '' ) ) {
					continue;
				}
				$text = trim( (string) ( isset( $entry['content'] ) ? $entry['content'] : '' ) );
				if ( '' === $text ) {
					continue;
				}
				return self::excerpt( $text, 400 );
			}
			return '';
		}

		/**
		 * System prompt for agent / ask modes.
		 *
		 * @param string $mode       Mode.
		 * @param int    $session_id Optional session for current plan context.
		 * @return string
		 */
		private static function system_prompt( $mode, $session_id = 0 ) {
			$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$site_url   = home_url( '/' );
			$available  = Ahentic_Abilities::available_for_mode( $mode );
			$tools_list = implode( ', ', $available );
			$admin_map  = Ahentic_Abilities::format_admin_links_for_prompt();

			$base = 'You are Ahentic, an AI workspace agent for WordPress. '
				. 'You help the user understand and improve their WordPress site. '
				. "Current site (hint only): {$site_name} ({$site_url}). "
				. 'Be concise, practical, and specific to WordPress when possible. '
				. 'Do not invent that you changed the site unless a tool confirmed it. '
				. 'When you need verified site data, call tools — do not guess plugin lists or stack details.';

			$readonly_tool_guidance = 'Prefer ahentic/get-site-snapshot when you need the site name, theme, environment, active plugins, or admin_links. '
				. 'Prefer ahentic/get-site-health for Site Health counts/issues; ahentic/get-option for allowlisted options (blog_public, blogdescription/tagline, permalink_structure, show_on_front, etc.). '
				. 'Prefer ahentic/list-plugins for installed active+inactive plugins; ahentic/search-plugins to search wordpress.org (pass query like "SEO"). '
				. 'When unsure about WordPress best practice — plugins vs custom code/theme edits, SEO plugin choice, cleanup, pre-launch gaps, or editor vs server content edits — '
				. 'call ahentic/get-wordpress-guidance before inventing a risky approach. '
				. 'Pass {"topic":"plugin-hygiene"} (ids: plugin-hygiene, custom-code-snippets, pre-launch-gaps, seo-decisioning, safe-cleanup, editor-vs-server, web-image-fit) '
				. 'or {"query":"add google analytics"}; omit both to list the catalog. Follow the returned guidance, then use site tools for facts. '
				. 'Tool priority: prefer server (ahentic/*) abilities when they can fully do the job. '
				. 'Use ahentic-browser/* only when you need the live open tab, block editor APIs, or the user’s browser session — or when no server ability exists. '
				. 'Never use the browser to simulate a server ability (e.g. do not click Install when ahentic/install-plugin exists). '
				. 'Prefer ahentic/get-admin-context or ahentic-browser/get-current-page for screen identity (“which page am I on?”, white screen / broken admin URL). '
				. 'Prefer ahentic-browser/get-visible-page when the user asks what is on the screen, to explain the UI, notices, buttons, or form fields currently visible. '
				. 'Active browser page context is attached to each turn when available (URL + is_block_editor / post_id / post_type / editor_title). '
				. 'Trust the LATEST attached page context over earlier assumptions about where the user is; only re-call get-current-page / get-editor-state if you need a fresh read. '
				. 'CRITICAL — content routing by page context: '
				. 'If is_block_editor=true, make content/title/structure changes with ahentic-browser/* '
				. '(update-post-title, set-blocks, insert-blocks, replace-blocks, update-block-attributes, get-selection / get-blocks as needed) '
				. 'so edits appear live in the open canvas. Do NOT use ahentic/update-post (or other server body writes) for that open document while the editor is open. '
				. 'Use ahentic/create-post only to create a NEW post/page that is not the open document, or when the block editor is not open. '
				. 'After create-post, if a later turn’s page context shows the user in the block editor (any document), continue content work with browser tools. '
				. 'If the block editor is not open, prefer server content abilities (create-post / update-post / set-post-status) as appropriate. '
				. 'If page context is missing is_block_editor but the URL looks like post.php / post-new.php / site-editor, call get-editor-state before create-post. '
				. 'Do NOT call ahentic-browser/save-post after editor edits unless the user explicitly asks to save, publish, update the live site, or persist changes — '
				. 'Gutenberg already keeps unsaved edits in the canvas; stop after inserting/updating blocks and let the user save. '
				. 'CRITICAL — real block objects only: insert/replace/set-blocks must pass an array of {name, attributes, innerBlocks} '
				. '(JavaScript createBlock shape). Never pass plain-text descriptions, bracket stubs like [full article], or “block structure” shorthand '
				. 'unless the user explicitly asked for placeholders. For a full article rewrite prefer ahentic-browser/set-blocks (replaces the whole document). '
				. 'For long articles, prefer ahentic/stage-artifact then set-blocks/create-post with {"from_memory":"article_draft"} '
				. '(or chunked insert-blocks/replace-blocks one section per step) — do not re-paste a huge draft from chat into tools_planned. '
				. 'Use get-block-type ONLY for non-core (third-party) blocks or after an attribute update fails with unknown keys — never as a first step for core/heading, core/paragraph, core/button, etc. '
				. 'get-block-type input is {name:"core/heading"} (block name), not a block ref. '
				. 'Rich-text attributes (content/text/caption/citation): pass HTML strings; get-blocks returns them as HTML (and may include a plain preview). '
				. 'CRITICAL — block refs: get-blocks / get-selection return short refs (b1, b2, …). When calling tools that take ref / refs / after_ref / root_ref, '
				. 'copy those refs EXACTLY from the latest get-blocks / get-selection result. Never invent refs and never send Gutenberg clientId UUID hashes. '
				. 'If a tool returns missing refs / block_not_found, re-call get-blocks (or get-selection) and use the fresh refs — do not guess. '
				. 'CRITICAL — never re-check your own writes: tool results are authoritative. After a successful create-post / update-post / '
				. 'set-blocks / insert-blocks / replace-blocks (or any other mutate), do NOT call get-content, get-blocks, or any other readonly '
				. 'ability to confirm it landed — the write result already reports what was persisted (content_text_chars / text_chars / '
				. 'inserted_count / before). Go straight to next="reply". '
				. 'If a write result contains "thin": true, the body is too small for the long-form work requested — keep writing '
				. '(expand or restage it) instead of replying. Staging (stage-artifact) is not done — still apply with from_memory. '
				. 'A page snapshot (get-visible-page / get-current-page) shows the page as rendered when it last loaded, so it can never '
				. 'confirm a change made after that — never use it to verify a write, a plugin activation, or a setting. If a stale notice is '
				. 'still on screen, say so and tell the user to reload rather than re-reading the screen. '
				. 'If a tool returns error placeholder_content / ahentic_placeholder_content / ahentic_use_browser_editor, fix the approach '
				. '(real block objects and/or browser tools) — do not claim the article was written. '
				. 'Core block cookbook (common attrs): '
				. 'core/heading → content (HTML), level (1–6); '
				. 'core/paragraph → content (HTML); '
				. 'core/button → text (HTML label), url; '
				. 'core/image → url, alt, id, caption (HTML); '
				. 'core/list + core/list-item → list-item content (HTML); '
				. 'core/html → content (raw HTML escape hatch). '
				. 'When the block editor is open, a fuller cheatsheet is attached with page context. '
				. 'Prefer ahentic/create-post + ahentic/update-post + ahentic/set-post-status when the block editor is NOT open (server-side drafts/publish). '
				. 'When a long draft is ready, stage it with ahentic/stage-artifact (key e.g. article_draft, kind blocks|html|post_content, '
				. 'payload: for blocks use {"blocks":[{name,attributes,innerBlocks},…]} — never put the body under content/blocks at the top level; '
				. 'while first writing a draft, chunk with mode=append + complete=false, then complete=true; '
				. 'when revising an already-ready artifact or rewriting the whole article, use mode=replace or a new key — never append onto a finished draft), '
				. 'then apply with set-blocks / create-post / update-post using {"from_memory":"article_draft"} — do not invent keys; list-artifacts shows what is staged. '
				. 'Prefer ahentic/http-fetch to GET a URL. For public pages omit as_user. For wp-admin / logged-in same-site pages pass {"url":"…","as_user":true} — that runs in the user’s browser with their session. Judge soft white screens by success_marker/body, not status alone. '
				. 'Prefer ahentic/get-debug-log for PHP fatals when WP_DEBUG_LOG is available. '
				. 'Prefer ahentic/search-content to find posts/pages by phrase (title, body, or meta); '
				. 'ahentic/list-content to browse by type/status; ahentic/get-content to read one post (body + safe meta). '
				. 'Prefer ahentic/update-post (Agent mode, editor not open) to change content/title/excerpt/slug/meta (does not change publish status); '
				. 'ahentic/set-post-status to publish/schedule/trash (HITL). '
				. 'For custom fields / WooCommerce prices: first ahentic/get-content with {"id":…,"include_meta":true}, then update using the exact meta keys under meta '
				. '(WooCommerce simple products typically use _regular_price and _price). Never invent top-level fields like "price". '
				. 'Always pass tools_planned as objects with input when a tool needs args (e.g. {"name":"ahentic/get-content","input":{"id":123}}), not bare ability name strings. '
				. 'Prefer ahentic/find-unused-media to scan the media library for images that look unused (not featured/logo/icon/in content). '
				. 'Before generating or placing post images, call ahentic/get-wordpress-guidance with topic web-image-fit (aspect ratio + framing); then generate-image → upload-media from_memory → ONE placement step — never default post images to tall or square. '
				. 'To place a generated image in the open post: ahentic/generate-image → ahentic/upload-media {"from_memory":"<artifact_key>"} (allow HITL) → place exactly once: either ahentic-browser/insert-blocks with a single core/image {id,url,alt} (index 0 or before_ref of the first block) OR set-featured-image when the user asked for featured/thumbnail/cover — never both, never insert-blocks twice for the same image. Never from_memory on insert-blocks for image artifacts. '
				. 'Prefer ahentic/update-term (Agent mode) to change an existing category/tag/custom taxonomy term: pass taxonomy plus term_id or term (ID/slug/name), then name/slug/description/parent and/or meta. '
				. 'Use edit_url / view_url / media_library_url / plugins_url from those results when linking the user. '
				. 'Do not claim you ran a tool that is not in the available list. ';

			if ( 'ask' === $mode ) {
				$base .= ' Mode: Ask — you run the same multi-step loop as Agent, but ONLY with read-only tools '
					. '(lookups and searches; no install/activate/update/delete or other site changes). '
					. 'When you need site facts, set next="use_tools" and list tools in tools_planned. After tool results appear '
					. 'in the next message, think again and either call more readonly tools or set next="reply" / "ask_user" / "missing_ability". '
					. "Available readonly abilities right now: {$tools_list}. "
					. $readonly_tool_guidance
					. 'If the user asks you to change the site (install/activate/deactivate/uninstall plugins, edit content, update settings, etc.): '
					. 'do NOT call write tools. Set next="reply" (or "ask_user" if you need a real choice), explain that Ask mode is read-only, '
					. 'tell them to switch the composer mode to Agent to make changes, and give manual steps with admin links if useful. '
					. 'If a tool result has error ability_ask_readonly, follow that pattern. '
					. 'If they need a write ability that does not exist in any mode yet, set next="missing_ability" with ability_needed '
					. '(e.g. "ahentic/update-site-title") and explain the gap with a short workaround. '
					. 'Never mention X, Twitter, hashtags, @handles, request cards, or any sidebar UI for requesting features. '
					. 'If a tool result has error ability_unavailable, explain you cannot do it yet and any workaround.';
			} else {
				$base .= ' Mode: Agent — you run a multi-step loop. When you need site facts, set next="use_tools" '
					. 'and list tools in tools_planned. After tool results appear in the next message, think again '
					. 'and either call more tools or set next="reply" / "ask_user" / "missing_ability". '
					. "Available abilities right now: {$tools_list}. "
					. $readonly_tool_guidance
					. 'HITL replaces ask_user for mutating abilities: when the concrete next step is ahentic/install-plugin, ahentic/activate-plugin, '
					. 'ahentic/deactivate-plugin, ahentic/uninstall-plugin, ahentic/create-post, ahentic/update-post, ahentic/set-post-status, ahentic/update-term, '
					. 'ahentic-browser/save-post, ahentic-browser/convert-blocks '
					. '(or any other ability that pauses for human approval), do NOT set next="ask_user" or ask “shall I install/activate/deactivate/uninstall/update it?” in chat. '
					. 'Instead set next="use_tools" and put that ability in tools_planned immediately — the product shows Allow/Skip; that IS the confirmation. '
					. 'In the short user-facing reply, say what you are about to do (e.g. install or uninstall a plugin, or update a post/term) and that they can approve below; '
					. 'never claim success until a tool result confirms it. Use ask_user only for real choices the tools cannot decide '
					. '(e.g. which of two plugins to pick when both are fine). '
					. 'If a tool result has error user_denied or skipped=true: the user skipped that action (or redirected with a new message). '
					. 'Do NOT retry the same ability/input. Adapt: try a different approach toward their goal (e.g. core blocks instead of a form plugin), '
					. 'or ask_user with one clear choice if you truly cannot proceed without them. Follow any hint and any newer user message. '
					. 'Chain install → activate: after a successful ahentic/install-plugin tool result with active=false, if the user wanted the plugin working '
					. '(install / set up / turn on / “help me find one”), immediately set next="use_tools" with ahentic/activate-plugin using the same slug or plugin_file — '
					. 'do not stop at “installed but not active; activate from Plugins.” Only skip chaining when the user clearly asked to install without activating. '
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
				. '{"intention":"Checking installed plugins","thinking":"1-3 sentences","plan":{"title":"Install SEO plugin","steps":[{"id":"1","content":"See what SEO plugins are installed","status":"in_progress"},{"id":"2","content":"Search for a suitable SEO plugin","status":"pending"},{"id":"3","content":"Install and activate","status":"pending"}]},"tools_planned":[{"name":"ahentic/list-plugins","input":{}}],"ability_needed":"ahentic/update-site-title","next":"reply|ask_user|use_tools|missing_ability"}' . "\n"
				. 'AHENTIC_DEBUG>>>' . "\n"
				. 'intention must be a short present-tense status the UI can show live (e.g. "Checking installed plugins", '
				. '"Searching the media library", "Summarizing findings") — not a private note. Keep it under ~10 words. '
				. 'thinking is shown to the user in the sidebar chat on every step — write 1–3 clear sentences of your thought '
				. 'process and findings (what you know, what you will check or just learned from tools). Do not leave thinking empty. '
				. 'tools_planned may be strings (ability names) or objects {"name":"ahentic/…","input":{}}. '
				. 'ability_needed is optional except when next is missing_ability (string or list of ability slugs). '
				. 'plan is orchestrator state (not a tool). In Agent mode you MUST include a non-empty plan.steps list when you '
				. 'intend 2+ tools in tools_planned OR any write (non-readonly) ability. A single readonly tool may omit plan. '
				. 'Omit plan for simple Ask answers. When you include plan, use coarse user-facing steps (not every tool name), '
				. 'keep exactly one status "in_progress", and on later thinks ALWAYS re-send the FULL plan including already '
				. 'completed/cancelled steps (same ids) — never drop finished steps from the list; only update their status. '
				. 'The plan checklist is silent UI metadata — it must NOT replace thinking or chat narration. '
				. 'Closing marker: AHENTIC_DEBUG followed by exactly three > characters. '
				. 'After the closing marker, write a short normal reply the user can read (even when next is use_tools — e.g. what you are about to check or what you just learned). '
				. 'Never mention the debug block.';

			if ( $session_id ) {
				$base .= self::plan_context_for_prompt( $session_id );
				if ( class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::session_has_content_work( $session_id ) ) {
					$base .= ' CRITICAL — this run is long-form content work: you MUST use ahentic/stage-artifact '
						. '(while drafting: chunk with mode=append until complete=true; when revising a ready draft or rewriting the full article: mode=replace or a new key) '
						. 'then apply with set-blocks/create-post/update-post '
						. 'using {"from_memory":"…"} — do not finish after a thin one-section set-blocks rewrite. '
						. 'A finished article needs a full multi-section body; each write result reports its size, so keep writing when it comes back thin.';
				}
			}

			return $base;
		}

		/**
		 * Step budget for this session (content / long-form gets a higher ceiling).
		 *
		 * @param int $session_id Session ID.
		 * @return int
		 */
		private static function max_steps_for_session( $session_id ) {
			if ( class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::session_has_content_work( $session_id ) ) {
				return self::MAX_STEPS_CONTENT_RUN;
			}
			return self::MAX_STEPS_PER_RUN;
		}

		/**
		 * Prompt note when writes still need verification.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function verify_context_for_prompt( $session_id ) {
			$notes = array();

			$unapplied = self::ready_unapplied_content_artifacts( $session_id );
			if ( ! empty( $unapplied ) ) {
				$notes[] = 'Ready artifacts not yet applied: '
					. implode( ', ', $unapplied )
					. '. Set next="use_tools" with set-blocks / create-post / update-post using {"from_memory":"<key>"} before next="reply".';
			}

			$findings = Ahentic_Session_Repository::get_verify_pending( $session_id );
			if ( ! empty( $findings ) ) {
				$chars = 0;
				foreach ( $findings as $item ) {
					$chars = max( $chars, isset( $item['chars'] ) ? (int) $item['chars'] : 0 );
				}
				$notes[] = sprintf(
					'The body you have written so far is too thin for this long-form request (%1$d characters of text, minimum %2$d). '
						. 'Keep writing: expand it with real sections via set-blocks / insert-blocks / update-post. '
						. 'Do not set next="reply" until the body is complete. Do not call a readonly ability to re-check it — the write result reports the size.',
					$chars,
					self::LONG_FORM_MIN_CHARS
				);
			}

			if ( empty( $notes ) ) {
				return '';
			}
			return "---\n" . implode( "\n", $notes );
		}

		/**
		 * Max output tokens for this completion (8k default, 16k for content staging).
		 *
		 * @param int $session_id Session ID.
		 * @return int
		 */
		private static function max_output_tokens_for_session( $session_id ) {
			if ( class_exists( 'Ahentic_AI' ) && class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::session_has_content_work( $session_id ) ) {
				return Ahentic_AI::MAX_OUTPUT_TOKENS_CONTENT;
			}
			return class_exists( 'Ahentic_AI' ) ? Ahentic_AI::MAX_OUTPUT_TOKENS : 8000;
		}

		/**
		 * Judge a successful write from its own return payload and mark the verdict on it.
		 *
		 * Writes already report what they persisted — server abilities reload the post and
		 * measure it, editor abilities read the live store back — so no readonly ability is
		 * called to confirm a write. Only long-form runs are judged: a short body is
		 * legitimate everywhere else.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability.
		 * @param mixed  $payload    Tool payload.
		 * @param bool   $ok         Success.
		 * @return mixed Payload, marked with thin/thin_reason when the body is too small.
		 */
		public static function assess_write_payload( $session_id, $name, $payload, $ok ) {
			if ( ! $ok || ! class_exists( 'Ahentic_Abilities' ) ) {
				return $payload;
			}
			$name = (string) $name;
			if ( Ahentic_Abilities::is_readonly( $name ) ) {
				return $payload;
			}
			if ( class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::is_artifact_ability( $name ) ) {
				return $payload;
			}

			self::advance_plan_after_tool( $session_id, $name );

			if ( ! is_array( $payload ) || ! self::ability_writes_body( $name ) ) {
				return $payload;
			}
			if ( ! class_exists( 'Ahentic_Session_Artifacts' ) || ! Ahentic_Session_Artifacts::session_has_content_work( $session_id ) ) {
				return $payload;
			}

			$chars  = self::body_chars_from_write_payload( $payload );
			$target = self::write_target_key( $name, $payload );

			// A later write to the same document supersedes what an earlier one reported.
			$findings = array();
			foreach ( Ahentic_Session_Repository::get_verify_pending( $session_id ) as $item ) {
				if ( isset( $item['target'] ) && (string) $item['target'] === $target ) {
					continue;
				}
				$findings[] = $item;
			}

			$thin = ( $chars >= 0 && $chars < self::LONG_FORM_MIN_CHARS )
				|| self::write_payload_looks_like_placeholder( $payload );

			if ( $thin ) {
				$payload['thin']        = true;
				$payload['thin_reason'] = sprintf(
					/* translators: 1: measured characters, 2: required characters */
					__( 'This document holds %1$d characters of text; the long-form work requested needs at least %2$d. Keep writing — expand it with real sections instead of replying.', 'ahentic' ),
					max( 0, $chars ),
					self::LONG_FORM_MIN_CHARS
				);
				$findings[] = array(
					'ability' => $name,
					'target'  => $target,
					'at'      => gmdate( 'c' ),
					'chars'   => max( 0, $chars ),
				);
			}

			Ahentic_Session_Repository::set_verify_pending( $session_id, $findings );

			if ( $thin ) {
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'verify_thin',
					sprintf( 'Thin body after %s', $name ),
					array(
						'ability' => $name,
						'target'  => $target,
						'chars'   => max( 0, $chars ),
						'minimum' => self::LONG_FORM_MIN_CHARS,
					),
					(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
				);
			}

			return $payload;
		}

		/**
		 * Writes whose payload reports a body worth measuring.
		 *
		 * @param string $name Ability.
		 * @return bool
		 */
		private static function ability_writes_body( $name ) {
			return in_array(
				(string) $name,
				array(
					'ahentic/create-post',
					'ahentic/update-post',
					'ahentic-browser/set-blocks',
					'ahentic-browser/insert-blocks',
					'ahentic-browser/replace-blocks',
				),
				true
			);
		}

		/**
		 * Which document a write landed on, so a later write can supersede its finding.
		 *
		 * @param string $name    Ability.
		 * @param array  $payload Payload.
		 * @return string
		 */
		private static function write_target_key( $name, array $payload ) {
			if ( 0 === strpos( (string) $name, 'ahentic-browser/' ) ) {
				// Editor writes all land on the one open document.
				return 'editor';
			}
			$post_id = 0;
			if ( ! empty( $payload['id'] ) ) {
				$post_id = (int) $payload['id'];
			} elseif ( ! empty( $payload['post_id'] ) ) {
				$post_id = (int) $payload['post_id'];
			}
			return 'post:' . $post_id;
		}

		/**
		 * Plain-text size of the document a write left behind, or -1 when it did not report one.
		 *
		 * Editor writes report the whole document (text_chars), not just the blocks they wrote,
		 * so chunked drafting accumulates instead of flagging every section.
		 *
		 * @param array $payload Payload.
		 * @return int
		 */
		private static function body_chars_from_write_payload( array $payload ) {
			$sources = array( $payload );
			if ( isset( $payload['post'] ) && is_array( $payload['post'] ) ) {
				$sources[] = $payload['post'];
			}
			foreach ( $sources as $source ) {
				if ( isset( $source['text_chars'] ) ) {
					return (int) $source['text_chars'];
				}
				if ( isset( $source['content_text_chars'] ) ) {
					return (int) $source['content_text_chars'];
				}
			}
			return -1;
		}

		/**
		 * Leading placeholder prose in the body a write reported back.
		 *
		 * @param array $payload Payload.
		 * @return bool
		 */
		private static function write_payload_looks_like_placeholder( array $payload ) {
			$preview = '';
			if ( isset( $payload['content_preview'] ) ) {
				$preview = (string) $payload['content_preview'];
			} elseif ( isset( $payload['post']['content_preview'] ) ) {
				$preview = (string) $payload['post']['content_preview'];
			}
			if ( '' === trim( $preview ) ) {
				return false;
			}
			return (bool) preg_match(
				'/^\s*(lorem ipsum|placeholder|\[full article\]|todo:?\s*write|coming soon)/i',
				wp_strip_all_tags( $preview )
			);
		}

		/**
		 * Detect long-form / article writing intent from the user message (PRD intent gate).
		 *
		 * @param string $content User message.
		 * @return bool
		 */
		private static function message_looks_like_content_work( $content ) {
			$text = strtolower( trim( (string) $content ) );
			if ( '' === $text ) {
				return false;
			}
			if ( preg_match( '/\b(full article|entire (article|post|page)|long[- ]form|finish (the )?(article|post|draft)|complete (the )?(article|post|draft))\b/u', $text ) ) {
				return true;
			}
			if ( preg_match( '/\b(finish|complete|write|draft|create|rewrite|expand|fill out)\b.{0,48}\b(article|post|blog|guide|essay|draft|content)\b/u', $text ) ) {
				return true;
			}
			if ( preg_match( '/\b(article|post|blog|guide)\b.{0,32}\b(finish|complete|write|draft|create)\b/u', $text ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Stash candidate closing prose so verify/apply continues do not drop it.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $result     LLM result.
		 * @param array $debug      Debug meta.
		 */
		private static function stash_pending_final( $session_id, array $result, $debug = array() ) {
			$text = isset( $result['text'] ) ? trim( (string) $result['text'] ) : '';
			if ( ( '' === $text || self::reply_looks_like_process( $text ) ) && is_array( $debug ) && class_exists( 'Ahentic_AI' ) ) {
				$fallback = trim( (string) Ahentic_AI::fallback_reply_from_debug( $debug ) );
				if ( '' !== $fallback && ! self::reply_looks_like_process( $fallback ) ) {
					$text = $fallback;
				}
			}
			if ( '' === $text || self::reply_looks_like_process( $text ) ) {
				return;
			}

			$existing = Ahentic_Session_Repository::get_pending_final( $session_id );
			if ( is_array( $existing ) && ! empty( $existing['text'] ) ) {
				// Keep the first good closing reply unless the new one is longer/better.
				if ( strlen( $text ) < strlen( (string) $existing['text'] ) ) {
					return;
				}
			}

			Ahentic_Session_Repository::set_pending_final(
				$session_id,
				array(
					'text'  => $text,
					'model' => isset( $result['model'] ) ? (string) $result['model'] : '',
					'debug' => is_array( $debug ) ? $debug : array(),
				)
			);
		}

		/**
		 * Content artifacts that are ready but not yet applied to the site/editor.
		 *
		 * @param int $session_id Session ID.
		 * @return array<int, string> Artifact keys.
		 */
		private static function ready_unapplied_content_artifacts( $session_id ) {
			if ( ! class_exists( 'Ahentic_Session_Artifacts' ) ) {
				return array();
			}
			$content_kinds = array(
				Ahentic_Session_Artifacts::KIND_BLOCKS,
				Ahentic_Session_Artifacts::KIND_HTML,
				Ahentic_Session_Artifacts::KIND_MARKDOWN,
				Ahentic_Session_Artifacts::KIND_POST_CONTENT,
			);
			$keys = array();
			foreach ( Ahentic_Session_Artifacts::list_pointers( $session_id ) as $p ) {
				$status = isset( $p['status'] ) ? (string) $p['status'] : '';
				$kind   = isset( $p['kind'] ) ? (string) $p['kind'] : '';
				$key    = isset( $p['key'] ) ? (string) $p['key'] : '';
				if ( '' === $key || Ahentic_Session_Artifacts::STATUS_READY !== $status ) {
					continue;
				}
				if ( ! in_array( $kind, $content_kinds, true ) ) {
					continue;
				}
				$keys[] = $key;
			}
			return $keys;
		}

		/**
		 * Mark remaining plan steps completed when the run idles with a final reply.
		 *
		 * @param int $session_id Session ID.
		 */
		private static function complete_plan_on_finish( $session_id ) {
			$plan = Ahentic_Session_Repository::get_plan( $session_id );
			if ( ! is_array( $plan ) || empty( $plan['steps'] ) || ! is_array( $plan['steps'] ) ) {
				return;
			}
			$changed = false;
			$steps   = array();
			foreach ( $plan['steps'] as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$status = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				if ( 'cancelled' !== $status && 'completed' !== $status ) {
					$step['status'] = 'completed';
					$changed        = true;
				}
				$steps[] = $step;
			}
			if ( ! $changed ) {
				return;
			}
			$plan['steps'] = $steps;
			Ahentic_Session_Repository::set_plan( $session_id, $plan );
		}

		/**
		 * Mark unfinished plan steps cancelled when the user stops the run.
		 *
		 * Without this the card keeps a step at in_progress, so the checklist reads
		 * as still working after Stop.
		 *
		 * @param int $session_id Session ID.
		 */
		private static function cancel_plan_on_stop( $session_id ) {
			$plan = Ahentic_Session_Repository::get_plan( $session_id );
			if ( ! is_array( $plan ) || empty( $plan['steps'] ) || ! is_array( $plan['steps'] ) ) {
				return;
			}
			$changed = false;
			$steps   = array();
			foreach ( $plan['steps'] as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$status = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				if ( 'completed' !== $status && 'cancelled' !== $status ) {
					$step['status'] = 'cancelled';
					$changed        = true;
				}
				$steps[] = $step;
			}
			if ( ! $changed ) {
				return;
			}
			$plan['steps'] = $steps;
			Ahentic_Session_Repository::set_plan( $session_id, $plan );
		}

		/**
		 * Advance the plan checklist after a successful tool (best-effort).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability name.
		 */
		private static function advance_plan_after_tool( $session_id, $name ) {
			$plan = Ahentic_Session_Repository::get_plan( $session_id );
			if ( ! is_array( $plan ) || empty( $plan['steps'] ) || ! is_array( $plan['steps'] ) ) {
				return;
			}

			$short   = strtolower( (string) preg_replace( '/^.*\//', '', (string) $name ) );
			$short   = str_replace( '-', ' ', $short );
			$steps   = $plan['steps'];
			$changed = false;
			$marked  = false;

			foreach ( $steps as $i => $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$status  = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				$content = isset( $step['content'] ) ? strtolower( (string) $step['content'] ) : '';
				if ( in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
					continue;
				}
				if ( '' !== $short && false !== strpos( $content, $short ) ) {
					$steps[ $i ]['status'] = 'completed';
					$changed               = true;
					$marked                = true;
					break;
				}
			}

			if ( ! $marked ) {
				foreach ( $steps as $i => $step ) {
					if ( ! is_array( $step ) ) {
						continue;
					}
					$status = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
					if ( 'in_progress' === $status ) {
						$steps[ $i ]['status'] = 'completed';
						$changed               = true;
						$marked                = true;
						break;
					}
				}
			}

			if ( ! $marked ) {
				foreach ( $steps as $i => $step ) {
					if ( ! is_array( $step ) ) {
						continue;
					}
					$status = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
					if ( 'pending' === $status ) {
						$steps[ $i ]['status'] = 'completed';
						$changed               = true;
						break;
					}
				}
			}

			// Promote the next pending step.
			foreach ( $steps as $i => $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$status = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				if ( 'pending' === $status ) {
					$steps[ $i ]['status'] = 'in_progress';
					$changed               = true;
					break;
				}
			}

			if ( ! $changed ) {
				return;
			}
			$plan['steps'] = $steps;
			Ahentic_Session_Repository::set_plan( $session_id, $plan );
		}

		/**
		 * Whether closing text reads like mid-process thinking.
		 *
		 * @param string $text Text.
		 * @return bool
		 */
		private static function reply_looks_like_process( $text ) {
			if ( class_exists( 'Ahentic_AI' ) ) {
				return Ahentic_AI::text_looks_like_process( $text );
			}
			return '' === trim( (string) $text );
		}

		/**
		 * Last-resort user-facing finish copy when the model left empty / process-y prose.
		 *
		 * @param array  $debug   Debug meta.
		 * @param string $content Current content.
		 * @return string
		 */
		private static function user_facing_finish_fallback( $debug, $content ) {
			unset( $content );
			if ( is_array( $debug ) && class_exists( 'Ahentic_AI' ) ) {
				$from_debug = trim( (string) Ahentic_AI::fallback_reply_from_debug( $debug ) );
				if ( '' !== $from_debug && ! self::reply_looks_like_process( $from_debug ) ) {
					return $from_debug;
				}
			}
			return __( 'Done.', 'ahentic' );
		}

		/**
		 * @param int   $session_id Session ID.
		 * @param array $result     Pending final reply.
		 * @param array $debug      Debug.
		 * @return string|array 'continue' or { result, debug }
		 */
		private static function run_verification_gate( $session_id, array $result, $debug ) {
			$findings = Ahentic_Session_Repository::get_verify_pending( $session_id );
			if ( empty( $findings ) ) {
				return array(
					'result' => $result,
					'debug'  => $debug,
				);
			}

			// Keep the model's closing prose so a repair think cannot lose it.
			self::stash_pending_final( $session_id, $result, $debug );

			$attempts = Ahentic_Session_Repository::bump_verify_attempts( $session_id );
			$step     = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

			if ( $attempts > self::MAX_VERIFY_ATTEMPTS ) {
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'verify_partial',
					'Body still thin after a repair attempt — honest partial finish',
					array(
						'findings' => $findings,
						'attempts' => $attempts,
					),
					$step
				);
				Ahentic_Session_Repository::clear_verify_pending( $session_id );
				Ahentic_Session_Repository::clear_forced_tools( $session_id );

				$stashed = Ahentic_Session_Repository::get_pending_final( $session_id );
				$msg     = __(
					'I applied a draft, but the body still looks thin or like a placeholder. Send Continue and I’ll expand it.',
					'ahentic'
				);
				if ( is_array( $stashed ) && ! empty( $stashed['text'] ) && ! self::reply_looks_like_process( (string) $stashed['text'] ) ) {
					$result['text'] = trim( (string) $stashed['text'] ) . "\n\n" . $msg;
				} else {
					$result['text'] = $msg;
				}

				return array(
					'result' => $result,
					'debug'  => $debug,
				);
			}

			// No forced tools: the next step is a free repair think, never a read-back.
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'verify_required',
				'Thin body — continuing to expand',
				array(
					'findings' => $findings,
					'attempts' => $attempts,
				),
				$step
			);
			Ahentic_Session_Repository::set_progress( $session_id, __( 'Expanding draft…', 'ahentic' ), $step );
			Ahentic_Session_Repository::set_thought(
				$session_id,
				__( 'The body written so far is too thin for long-form work — expanding it before finishing.', 'ahentic' )
			);
			return 'continue';
		}

		/**
		 * Forced mutate to apply the first ready content artifact.
		 *
		 * @param int                $session_id Session ID.
		 * @param array<int, string> $keys       Ready artifact keys.
		 * @return array<int, array{name: string, input: array}>
		 */
		private static function build_forced_apply_tools( $session_id, array $keys ) {
			if ( empty( $keys ) ) {
				return array();
			}
			$key = (string) $keys[0];
			$ctx = Ahentic_Session_Repository::get_page_context( $session_id );
			$editor_open = ! empty( $ctx['is_block_editor'] );
			$post_id     = ! empty( $ctx['post_id'] ) ? (int) $ctx['post_id'] : 0;

			if ( $editor_open && class_exists( 'Ahentic_Abilities_Browser' ) ) {
				return array(
					array(
						'name'  => Ahentic_Abilities_Browser::SET_BLOCKS,
						'input' => array( 'from_memory' => $key ),
					),
				);
			}

			if ( $post_id > 0 && class_exists( 'Ahentic_Abilities_Content' ) ) {
				return array(
					array(
						'name'  => Ahentic_Abilities_Content::UPDATE,
						'input' => array(
							'id'          => $post_id,
							'from_memory' => $key,
						),
					),
				);
			}

			if ( class_exists( 'Ahentic_Abilities_Content' ) ) {
				return array(
					array(
						'name'  => Ahentic_Abilities_Content::CREATE,
						'input' => array( 'from_memory' => $key ),
					),
				);
			}

			return array();
		}

		/**
		 * Whether this tool batch already applies one of the ready artifact keys.
		 *
		 * @param array              $planned Tool calls.
		 * @param array<int, string> $keys    Ready keys.
		 * @return bool
		 */
		private static function planned_includes_artifact_apply( array $planned, array $keys ) {
			$key_lookup = array();
			foreach ( $keys as $k ) {
				$key_lookup[ (string) $k ] = true;
			}
			$apply_names = array();
			if ( class_exists( 'Ahentic_Abilities_Browser' ) ) {
				$apply_names[] = Ahentic_Abilities_Browser::SET_BLOCKS;
			}
			if ( class_exists( 'Ahentic_Abilities_Content' ) ) {
				$apply_names[] = Ahentic_Abilities_Content::CREATE;
				$apply_names[] = Ahentic_Abilities_Content::UPDATE;
			}
			foreach ( $planned as $call ) {
				if ( ! is_array( $call ) ) {
					continue;
				}
				$name  = isset( $call['name'] ) ? (string) $call['name'] : '';
				$input = isset( $call['input'] ) && is_array( $call['input'] ) ? $call['input'] : array();
				if ( ! in_array( $name, $apply_names, true ) ) {
					continue;
				}
				$mem = isset( $input['from_memory'] ) ? (string) $input['from_memory'] : '';
				if ( '' !== $mem && isset( $key_lookup[ $mem ] ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability.
		 * @param array  $input      Input.
		 * @return true|array{fallback: array}|\WP_Error
		 */
		public static function browser_preflight( $session_id, $name, array $input ) {
			$ctx     = Ahentic_Session_Repository::get_page_context( $session_id );
			$updated = ! empty( $ctx['updatedAt'] ) ? strtotime( (string) $ctx['updatedAt'] ) : 0;
			$fresh   = $updated && ( time() - $updated ) <= 180;

			$needs_editor = class_exists( 'Ahentic_Abilities_Browser' )
				&& Ahentic_Abilities_Browser::is_browser( $name )
				&& ! in_array(
					$name,
					array(
						'ahentic-browser/get-current-page',
						'ahentic-browser/get-visible-page',
					),
					true
				);

			if ( $needs_editor && empty( $ctx['is_block_editor'] ) ) {
				$fallback = self::server_fallback_for_browser( $name, $input, $ctx );
				if ( $fallback ) {
					Ahentic_Session_Repository::append_trace(
						$session_id,
						'browser_fallback',
						'Editor not open — using server ability',
						array(
							'from' => $name,
							'to'   => $fallback['name'],
						),
						(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
					);
					return array( 'fallback' => $fallback );
				}
				$post_id = isset( $ctx['post_id'] ) ? (int) $ctx['post_id'] : 0;
				$msg     = $post_id > 0
					? sprintf(
						/* translators: %d: post ID */
						__( 'Open the block editor for post #%d (or the Ahentic sidebar on that screen) so this browser ability can run.', 'ahentic' ),
						$post_id
					)
					: __( 'Open the block editor (or keep the Ahentic sidebar on an editor screen) so this browser ability can run.', 'ahentic' );
				return new WP_Error( 'ahentic_browser_runtime_missing', $msg );
			}

			if ( ! $fresh && empty( $ctx ) ) {
				return new WP_Error(
					'ahentic_browser_runtime_missing',
					__( 'The Ahentic sidebar does not have a fresh page context. Keep the sidebar open on the target page and try again.', 'ahentic' )
				);
			}

			return true;
		}

		/**
		 * @param string $name  Browser ability.
		 * @param array  $input Input.
		 * @param array  $ctx   Page context.
		 * @return array|null
		 */
		public static function server_fallback_for_browser( $name, array $input, array $ctx ) {
			if ( ! class_exists( 'Ahentic_Abilities_Browser' ) || ! class_exists( 'Ahentic_Abilities_Content' ) ) {
				return null;
			}
			if ( Ahentic_Abilities_Browser::SET_BLOCKS !== $name && Ahentic_Abilities_Browser::UPDATE_POST_TITLE !== $name ) {
				return null;
			}

			$post_id      = isset( $ctx['post_id'] ) ? (int) $ctx['post_id'] : 0;
			$server_input = array();
			if ( ! empty( $input['from_memory'] ) ) {
				$server_input['from_memory'] = $input['from_memory'];
			}
			if ( Ahentic_Abilities_Browser::UPDATE_POST_TITLE === $name && isset( $input['title'] ) ) {
				$server_input['title'] = $input['title'];
			}

			if ( $post_id > 0 ) {
				$server_input['id'] = $post_id;
				return array(
					'name'  => Ahentic_Abilities_Content::UPDATE,
					'input' => $server_input,
				);
			}

			if ( ! empty( $server_input['from_memory'] ) || ! empty( $server_input['title'] ) ) {
				return array(
					'name'  => Ahentic_Abilities_Content::CREATE,
					'input' => $server_input,
				);
			}

			return null;
		}

		/**
		 * Timed recovery when awaiting_browser stalls.
		 *
		 * @param int $session_id Session ID.
		 */
		private static function recover_stale_browser( $session_id ) {
			$pending = Ahentic_Session_Repository::get_pending_tool( $session_id );
			if ( ! $pending || empty( $pending['name'] ) ) {
				return;
			}
			$paused = Ahentic_Session_Repository::get_browser_paused_at( $session_id );
			$age    = $paused ? ( time() - strtotime( $paused ) ) : 0;
			if ( $age < 45 ) {
				return;
			}

			$name     = (string) $pending['name'];
			$input    = isset( $pending['input'] ) && is_array( $pending['input'] ) ? $pending['input'] : array();
			$step     = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			$ctx      = Ahentic_Session_Repository::get_page_context( $session_id );
			$fallback = self::server_fallback_for_browser( $name, $input, $ctx );

			Ahentic_Session_Repository::set_pending_tool( $session_id, null );
			Ahentic_Session_Repository::clear_browser_paused_at( $session_id );
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );

			if ( $fallback ) {
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'browser_timeout_fallback',
					'Browser pause timed out — falling back to server ability',
					array(
						'from' => $name,
						'to'   => $fallback['name'],
					),
					$step
				);
				self::append_tool_failure(
					$session_id,
					$name,
					new WP_Error(
						'ahentic_browser_timeout',
						sprintf(
							/* translators: %s: server ability name */
							__( 'Browser runtime timed out. Retry with server ability %s (or open the block editor and Continue).', 'ahentic' ),
							$fallback['name']
						),
						array( 'fallback' => $fallback )
					),
					$step
				);
			} else {
				self::append_tool_failure(
					$session_id,
					$name,
					new WP_Error(
						'ahentic_browser_timeout',
						__( 'Browser runtime timed out. Open the target page/editor with the Ahentic sidebar, then send Continue.', 'ahentic' )
					),
					$step
				);
			}

			Ahentic_Session_Repository::set_progress( $session_id, __( 'Recovering after browser timeout…', 'ahentic' ), $step );
			Ahentic_Step_Queue::enqueue_step( $session_id );
			Ahentic_Step_Queue::schedule_interactive_run( $session_id );
		}

		/**
		 * Whether this think requires a persisted plan (Agent + ≥2 tools or any write).
		 *
		 * @param array  $debug Parsed debug block.
		 * @param string $mode  agent|ask.
		 * @return bool
		 */
		private static function debug_requires_plan( $debug, $mode ) {
			if ( 'agent' !== $mode || ! is_array( $debug ) ) {
				return false;
			}
			$planned = self::normalize_tool_calls( isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array() );
			if ( count( $planned ) >= 2 ) {
				return true;
			}
			foreach ( $planned as $call ) {
				$name = isset( $call['name'] ) ? (string) $call['name'] : '';
				if ( $name && class_exists( 'Ahentic_Abilities' ) && ! Ahentic_Abilities::is_readonly( $name ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Persist a minimal plan when the model used tools/writes without one.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $debug      Parsed debug block.
		 */
		private static function ensure_synthetic_plan( $session_id, $debug ) {
			if ( Ahentic_Session_Repository::get_plan( $session_id ) ) {
				return;
			}
			$intention = is_array( $debug ) && isset( $debug['intention'] ) ? trim( (string) $debug['intention'] ) : '';
			$planned   = self::normalize_tool_calls( isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array() );
			$steps     = array();
			if ( count( $planned ) > 0 ) {
				$i = 1;
				foreach ( $planned as $call ) {
					$name = isset( $call['name'] ) ? (string) $call['name'] : '';
					$short = $name ? preg_replace( '/^.*\//', '', $name ) : '';
					$steps[] = array(
						'id'      => (string) $i,
						'content' => $short ? sprintf(
							/* translators: %s: ability short name */
							__( 'Run %s', 'ahentic' ),
							str_replace( '-', ' ', $short )
						) : __( 'Complete the next action', 'ahentic' ),
						'status'  => 1 === $i ? 'in_progress' : 'pending',
					);
					++$i;
					if ( $i > self::MAX_PLAN_STEPS ) {
						break;
					}
				}
			} else {
				$steps[] = array(
					'id'      => '1',
					'content' => $intention ? $intention : __( 'Complete the requested work', 'ahentic' ),
					'status'  => 'in_progress',
				);
			}
			$plan = array(
				'title' => $intention ? $intention : __( 'Working plan', 'ahentic' ),
				'steps' => $steps,
			);
			Ahentic_Session_Repository::set_plan( $session_id, $plan );
			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'plan_updated',
				self::plan_trace_summary( $plan ),
				array(
					'title'     => $plan['title'],
					'steps'     => $plan['steps'],
					'synthetic' => true,
				),
				$step
			);
		}

		/**
		 * Auto-stage oversized tool bodies and rewrite to from_memory.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability.
		 * @param array  $input      Tool input.
		 * @param int    $step       Step.
		 * @return array
		 */
		public static function maybe_auto_stage_tool_input( $session_id, $name, array $input, $step ) {
			if ( ! class_exists( 'Ahentic_Session_Artifacts' ) ) {
				return $input;
			}
			if ( ! empty( $input['from_memory'] ) || ! Ahentic_Session_Artifacts::ability_supports_from_memory( $name ) ) {
				return $input;
			}

			$threshold = 6000;
			$kind      = '';
			$payload   = null;

			if ( ! empty( $input['blocks'] ) && is_array( $input['blocks'] ) ) {
				$encoded = wp_json_encode( $input['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( is_string( $encoded ) && strlen( $encoded ) >= $threshold ) {
					$kind    = Ahentic_Session_Artifacts::KIND_BLOCKS;
					$payload = array( 'blocks' => $input['blocks'] );
				}
			} elseif ( ! empty( $input['content'] ) && is_string( $input['content'] ) && strlen( $input['content'] ) >= $threshold ) {
				$kind    = Ahentic_Session_Artifacts::KIND_POST_CONTENT;
				$payload = array( 'content' => $input['content'] );
			}

			if ( ! $payload || ! $kind ) {
				return $input;
			}

			$key    = 'auto_' . substr( md5( $name . '|' . $step . '|' . wp_json_encode( array_keys( $payload ) ) ), 0, 12 );
			$result = Ahentic_Session_Artifacts::stage(
				$session_id,
				$key,
				array(
					'kind'    => $kind,
					'title'   => __( 'Auto-staged draft', 'ahentic' ),
					'payload' => $payload,
					'meta'    => array(
						'source' => 'auto_stage',
						'step'   => (int) $step,
					),
					'status'  => Ahentic_Session_Artifacts::STATUS_READY,
				)
			);
			if ( is_wp_error( $result ) ) {
				return $input;
			}

			$out = $input;
			unset( $out['blocks'], $out['content'] );
			$out['from_memory'] = $key;

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'artifact_auto_staged',
				sprintf( 'Auto-staged oversized payload as %s', $key ),
				array(
					'ability' => $name,
					'key'     => $key,
					'kind'    => $kind,
				),
				(int) $step
			);

			return $out;
		}

		/**
		 * HITL summary, enriched with artifact pointer when using from_memory.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability.
		 * @param array  $input      Tool input.
		 * @return string
		 */
		public static function hitl_summary_for_pending( $session_id, $name, array $input ) {
			$summary = Ahentic_Abilities::hitl_summary( $name, $input );
			if ( empty( $input['from_memory'] ) || ! class_exists( 'Ahentic_Session_Artifacts' ) ) {
				return $summary;
			}
			$key  = Ahentic_Session_Artifacts::sanitize_artifact_key( (string) $input['from_memory'] );
			$mem  = self::memory_pointer_for_pending( $session_id, $key );
			if ( ! $mem ) {
				return $summary;
			}
			$bits = array( $summary );
			if ( ! empty( $mem['title'] ) ) {
				$bits[] = '"' . $mem['title'] . '"';
			}
			if ( ! empty( $mem['bytes'] ) ) {
				$bits[] = (int) $mem['bytes'] . ' bytes';
			}
			if ( ! empty( $mem['excerpt'] ) ) {
				$bits[] = $mem['excerpt'];
			}
			return implode( ' · ', $bits );
		}

		/**
		 * Short memory pointer for HITL / browser pending (no full body).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $key        Artifact key.
		 * @return array|null
		 */
		public static function memory_pointer_for_pending( $session_id, $key ) {
			if ( ! class_exists( 'Ahentic_Session_Artifacts' ) || ! $key ) {
				return null;
			}
			return Ahentic_Session_Artifacts::pointer_with_excerpt( $session_id, $key );
		}

		/**
		 * Expand input.from_memory for tool execution / browser pause.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability name.
		 * @param array  $input      Tool input.
		 * @return array{input: array, artifact_key: string}|\WP_Error
		 */
		public static function expand_from_memory( $session_id, $name, array $input ) {
			if ( ! class_exists( 'Ahentic_Session_Artifacts' ) ) {
				return array(
					'input'        => $input,
					'artifact_key' => '',
				);
			}
			$resolved = Ahentic_Session_Artifacts::apply_from_memory( $session_id, $name, $input );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			return array(
				'input'        => isset( $resolved['input'] ) && is_array( $resolved['input'] ) ? $resolved['input'] : $input,
				'artifact_key' => isset( $resolved['artifact_key'] ) ? (string) $resolved['artifact_key'] : '',
			);
		}

		/**
		 * Append a failed tool result (e.g. artifact_missing) and continue the loop.
		 *
		 * @param int      $session_id Session ID.
		 * @param string   $name       Ability.
		 * @param \WP_Error $error     Error.
		 * @param int      $step       Step.
		 */
		public static function append_tool_failure( $session_id, $name, $error, $step ) {
			$payload = self::tool_error_payload( $error );
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
						'ok'      => false,
					),
				)
			);
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'tool_result',
				'Error: ' . $name,
				array(
					'ability' => $name,
					'ok'      => false,
					'error'   => $error->get_error_code(),
					'excerpt' => self::excerpt( $content, 240 ),
				),
				$step
			);
		}

		/**
		 * Keep the rest of a planned batch alive across a browser pause.
		 *
		 * Pausing returns from the step, so calls the model planned after the paused
		 * one were dropped and had to be re-planned by a whole extra think. Only an
		 * all-browser remainder is re-queued: those pause again immediately instead of
		 * running to the end of the forced batch.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $planned    Full planned batch.
		 * @param int   $index      Index of the call that paused.
		 * @param int   $step       Step number for the trace.
		 */
		public static function preserve_browser_batch_remainder( $session_id, array $planned, $index, $step ) {
			$remaining = array_slice( $planned, (int) $index + 1 );
			if ( empty( $remaining ) ) {
				return;
			}

			$names       = array();
			$all_browser = true;
			foreach ( $remaining as $call ) {
				$name  = isset( $call['name'] ) ? (string) $call['name'] : '';
				$input = isset( $call['input'] ) && is_array( $call['input'] ) ? $call['input'] : array();
				$names[] = $name;
				if ( '' === $name || ! Ahentic_Abilities::requires_browser_runtime( $name, $input ) ) {
					$all_browser = false;
				}
			}

			if ( ! $all_browser ) {
				return;
			}

			Ahentic_Session_Repository::set_forced_tools( $session_id, $remaining );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'browser_batch_queued',
				'Queued remaining browser tools to run after the pause',
				array( 'tools' => $names ),
				$step
			);
		}

		/**
		 * Shrink tool input for trace / pending dumps (omit huge bodies).
		 *
		 * @param array $input Input.
		 * @return array
		 */
		public static function trace_tool_input( array $input ) {
			$out = $input;
			if ( isset( $out['blocks'] ) && is_array( $out['blocks'] ) ) {
				$out['blocks'] = array(
					'_omitted' => true,
					'count'    => count( $out['blocks'] ),
				);
			}
			if ( isset( $out['content'] ) && is_string( $out['content'] ) && strlen( $out['content'] ) > 240 ) {
				$out['content'] = self::excerpt( $out['content'], 240 );
			}
			// Never dump local filesystem paths into traces.
			if ( isset( $out['source_path'] ) ) {
				$out['source_path'] = '[local]';
			}
			return $out;
		}

		/**
		 * Abilities that read the current state of the open editor.
		 *
		 * These describe one document, so only the newest result is meaningful —
		 * unlike id/query-scoped reads (get-content, list-content) where each
		 * result answers a different question and must all be kept.
		 *
		 * @param string $name Ability name.
		 * @return bool
		 */
		private static function ability_is_live_editor_snapshot( $name ) {
			return in_array(
				(string) $name,
				array(
					'ahentic-browser/get-blocks',
					'ahentic-browser/get-editor-state',
					'ahentic-browser/get-selection',
				),
				true
			);
		}

		/**
		 * Entry index of the newest result per live-editor snapshot ability.
		 *
		 * @param array $entries Session entries.
		 * @return array<string, int|string>
		 */
		private static function latest_live_editor_snapshots( array $entries ) {
			$latest = array();
			foreach ( $entries as $i => $entry ) {
				if ( 'tool' !== ( isset( $entry['role'] ) ? $entry['role'] : '' ) ) {
					continue;
				}
				if ( ! empty( $entry['meta']['error'] ) ) {
					continue;
				}
				$ability = isset( $entry['meta']['ability'] ) ? (string) $entry['meta']['ability'] : '';
				if ( self::ability_is_live_editor_snapshot( $ability ) ) {
					$latest[ $ability ] = $i;
				}
			}
			return $latest;
		}

		/**
		 * Cap tool-result JSON injected into the next think prompt.
		 *
		 * @param string $content Raw tool entry content.
		 * @param int    $max     Optional cap override (0 uses the default).
		 * @return string
		 */
		private static function truncate_tool_result_for_prompt( $content, $max = 0 ) {
			$content = (string) $content;
			$max     = (int) $max > 0 ? (int) $max : self::MAX_TOOL_RESULT_CHARS;

			if ( strlen( $content ) <= $max ) {
				return $content;
			}
			return rtrim( substr( $content, 0, $max - 1 ) ) . '…';
		}

		/**
		 * Truncate text for trace payloads.
		 *
		 * @param string $text Text.
		 * @param int    $max  Max length.
		 * @return string
		 */
		public static function excerpt( $text, $max = 120 ) {
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
