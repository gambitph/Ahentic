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
		const MAX_TOOL_PROGRESS = 5;
		/** Default Agent run step budget (PRD). */
		const MAX_STEPS_PER_RUN = 24;
		/** Content / long-form run step budget when session has content artifacts (PRD). */
		const MAX_STEPS_CONTENT_RUN = 48;

		/**
		 * Session currently being processed (for abilities that need page context).
		 *
		 * @var int
		 */
		private static $current_session_id = 0;

		/**
		 * Bootstrap hooks (token limit enforcement).
		 */
		public static function init() {
			add_action( 'ahentic_token_limit_enforced', array( __CLASS__, 'on_token_limit_enforced' ), 10, 1 );
		}

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

			$may_spend = Ahentic_Usage::assert_may_spend();
			if ( is_wp_error( $may_spend ) ) {
				return $may_spend;
			}

			// Soft session spend pause: Continue/Stop only — no new goals or resume cues.
			if (
				Ahentic_Session_Repository::get_job_resumable( $session_id )
				&& Ahentic_Usage::CODE_SESSION_SOFT_BUDGET === Ahentic_Session_Repository::get_last_error_code( $session_id )
			) {
				$threshold = Ahentic_Session_Repository::get_soft_token_budget_acked( $session_id )
					+ Ahentic_Usage::SESSION_SOFT_BUDGET_TOKENS;
				return new WP_Error(
					Ahentic_Usage::CODE_SESSION_SOFT_BUDGET,
					Ahentic_Usage::session_soft_budget_message( $threshold ),
					array( 'status' => 409 )
				);
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

			// Job Resume owns Continuable cue → resume vs new-goal ritual (clears / goal / content_work).
			$start = Ahentic_Job_Resume::begin_new_goal( $session_id, $content );
			if ( isset( $start['action'] ) && 'resume' === $start['action'] ) {
				Ahentic_Session_Repository::append_entry(
					$session_id,
					array(
						'role'    => 'user',
						'content' => $content,
					)
				);
				return self::resume_job( $session_id, 'composer_cue' );
			}

			// Ritual already set status=running before append_entry so a concurrent poll
			// cannot see the new user message while status is still idle.
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
		 * Continue a stalled run (Local / no cron fallback) or resume a recoverable job.
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

			if ( Ahentic_Session_Repository::STATUS_RUNNING === $status ) {
				self::process_step( $session_id );
				return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
			}

			// Idle / error after mid-job failure or honest partial — same job, not a new message.
			if ( Ahentic_Session_Repository::get_job_resumable( $session_id ) ) {
				return self::resume_job( $session_id, 'continue_api' );
			}

			return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
		}

		/**
		 * Resume a Continue-recoverable Session job without replacing the active goal.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $source     continue_api|composer_cue.
		 * @return array|\WP_Error
		 */
		public static function resume_job( $session_id, $source = 'continue_api' ) {
			$post = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$status = Ahentic_Session_Repository::get_status( $session_id );
			if ( in_array( $status, array( Ahentic_Session_Repository::STATUS_RUNNING, Ahentic_Session_Repository::STATUS_AWAITING_BROWSER ), true ) ) {
				return new WP_Error(
					'ahentic_session_busy',
					__( 'This session is still working. Wait for it to finish or cancel it.', 'ahentic' ),
					array( 'status' => 409 )
				);
			}

			$may_spend = Ahentic_Usage::assert_may_spend();
			if ( is_wp_error( $may_spend ) ) {
				return $may_spend;
			}

			// Continue after a soft session-spend pause raises the watermark for the next boundary.
			if ( Ahentic_Usage::CODE_SESSION_SOFT_BUDGET === Ahentic_Session_Repository::get_last_error_code( $session_id ) ) {
				Ahentic_Session_Repository::set_soft_token_budget_acked(
					$session_id,
					Ahentic_Usage::ack_session_soft_budget(
						Ahentic_Session_Repository::get_tokens_used( $session_id )
					)
				);
			}

			$resumed = Ahentic_Job_Resume::begin_resume( $session_id );

			$mode_now = Ahentic_Session_Repository::get_mode( $session_id );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_resume',
				sprintf( 'Job resumed (%s)', $source ),
				array(
					'mode'         => $mode_now,
					'source'       => $source,
					'content_work' => ! empty( $resumed['content_work'] ),
					'goal'         => self::excerpt(
						isset( $resumed['active_goal'] ) ? (string) $resumed['active_goal'] : '',
						160
					),
					'env'          => Ahentic_Session_Repository::environment_snapshot(),
				)
			);

			Ahentic_Step_Queue::enqueue_step( $session_id );
			Ahentic_Step_Queue::schedule_interactive_run( $session_id );

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

			$may_spend = Ahentic_Usage::assert_may_spend();
			if ( is_wp_error( $may_spend ) ) {
				self::stop_session_for_token_limit( $session_id, $may_spend->get_error_code() );
				return false;
			}

			Ahentic_Session_Repository::touch_heartbeat( $session_id );

			$soft_budget = Ahentic_Usage::evaluate_session_soft_budget(
				Ahentic_Session_Repository::get_tokens_used( $session_id ),
				Ahentic_Session_Repository::get_soft_token_budget_acked( $session_id )
			);
			if ( empty( $soft_budget['ok'] ) ) {
				$threshold = isset( $soft_budget['threshold'] )
					? (int) $soft_budget['threshold']
					: Ahentic_Usage::SESSION_SOFT_BUDGET_TOKENS;
				Ahentic_Session_Repository::set_job_resumable( $session_id, true );
				self::fail_run(
					$session_id,
					new WP_Error(
						Ahentic_Usage::CODE_SESSION_SOFT_BUDGET,
						Ahentic_Usage::session_soft_budget_message( $threshold )
					)
				);
				return false;
			}

			$steps     = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			$max_steps = self::max_steps_for_session( $session_id );
			if ( $steps >= $max_steps ) {
				Ahentic_Session_Repository::set_job_resumable( $session_id, true );
				self::fail_run(
					$session_id,
					new WP_Error(
						'ahentic_max_steps',
						__( 'This run hit the step limit before finishing. Artifacts are kept — use Continue to resume (e.g. finish applying the draft).', 'ahentic' )
					)
				);
				return false;
			}

			$mode           = Ahentic_Session_Repository::get_mode( $session_id );
			$forced_purpose = Ahentic_Session_Repository::get_forced_tools_purpose( $session_id );
			$forced_tools   = Ahentic_Session_Repository::consume_forced_tools( $session_id );
			$from_forced    = ! empty( $forced_tools );

			if ( ! $from_forced ) {
				// consume_forced_tools keeps purpose meta; clear orphans so a later model
				// batch pause cannot inherit a stale "apply" sticker.
				Ahentic_Session_Repository::clear_forced_tools( $session_id );
			}

			if ( $from_forced ) {
				Ahentic_Session_Repository::bump_step( $session_id );
				$is_apply_forced = Ahentic_Session_Repository::FORCED_PURPOSE_APPLY === $forced_purpose;
				$debug           = array(
					'next'          => 'use_tools',
					'intention'     => $is_apply_forced
						? __( 'Finishing pending apply/verify', 'ahentic' )
						: __( 'Continuing queued tools', 'ahentic' ),
					'thinking'      => $is_apply_forced
						? __( 'Running required apply or verification tools before the final reply.', 'ahentic' )
						: __( 'Running tools queued after a pause or Subagent Recipe.', 'ahentic' ),
					'tools_planned' => $forced_tools,
					'forced_purpose'=> $forced_purpose,
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
				$planned = Ahentic_Think_Debug::normalize_tool_calls( $forced_tools );
			} else {
				// Composition: Think/Debug (incl. plan-missing retry) → Subagent disposition → tools (or finish).
				$before   = class_exists( 'Ahentic_Subagent' )
					? Ahentic_Subagent::before_think( $session_id )
					: array(
						'llm_opts' => array(),
						'in_hop'   => false,
					);
				$hop_opts = isset( $before['llm_opts'] ) && is_array( $before['llm_opts'] ) ? $before['llm_opts'] : array();
				$in_hop   = ! empty( $before['in_hop'] );
				$think    = Ahentic_Think_Debug::run_think( $session_id, $hop_opts );

				// User may have hit Stop during the LLM call — do not continue the run.
				if ( Ahentic_Session_Repository::STATUS_RUNNING !== Ahentic_Session_Repository::get_status( $session_id ) ) {
					return false;
				}

				if ( is_wp_error( $think ) ) {
					if ( class_exists( 'Ahentic_Subagent' ) ) {
						Ahentic_Subagent::on_think_failure( $session_id );
					}
					self::fail_run( $session_id, $think );
					return false;
				}

				$result      = $think['result'];
				$think_label = $think['label'];
				$debug       = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : array();
				Ahentic_Think_Debug::apply_live_progress( $session_id, $debug, $think_label );

				$result = Ahentic_Think_Debug::finalize_result_text( $result, $debug );

				$finish_without = Ahentic_Think_Debug::should_finish_without_tools( $session_id, $debug );
				$next           = isset( $debug['next'] ) ? (string) $debug['next'] : '';
				$planned        = Ahentic_Think_Debug::normalize_tool_calls( isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array() );
				$wants_tools    = ( 'use_tools' === $next );

				$sub_disp = class_exists( 'Ahentic_Subagent' )
					? Ahentic_Subagent::after_main_think(
						$session_id,
						array(
							'in_hop'               => $in_hop,
							'finish_without_tools' => $finish_without,
							'wants_tools'          => $wants_tools,
							'debug'                => $debug,
							'planned'              => $planned,
							'result'               => $result,
						)
					)
					: array(
						'action' => ( $finish_without || ! $wants_tools ) ? 'finish_reply' : 'run_tools',
					);
				$sub_action = isset( $sub_disp['action'] ) ? (string) $sub_disp['action'] : 'run_tools';

				if ( 'finish_hop' === $sub_action ) {
					return true;
				}
				if ( 'abort_to_user' === $sub_action || 'finish_reply' === $sub_action ) {
					return self::try_finish_with_reply( $session_id, $result, $debug );
				}
				if ( 'begin_hop' === $sub_action ) {
					Ahentic_Think_Debug::publish_thought_process( $session_id, $result, $debug );
					return true;
				}

				// run_tools (default).
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
				Ahentic_Think_Debug::publish_thought_process( $session_id, $result, $debug );
			}

			$available = Ahentic_Abilities::available_for_mode( $mode );
			if ( $from_forced ) {
				$planned = array_slice( $planned, 0, self::MAX_TOOL_PROGRESS );
				Ahentic_Session_Repository::set_thought(
					$session_id,
					isset( $debug['thinking'] ) ? (string) $debug['thinking'] : ''
				);
			}

			$ran_any     = false;
			$any_failed  = false;
			$any_write   = false;
			foreach ( $planned as $call_index => $call ) {
				if ( Ahentic_Session_Repository::STATUS_RUNNING !== Ahentic_Session_Repository::get_status( $session_id ) ) {
					return false;
				}

				$name  = $call['name'];
				$input = $call['input'];

				if ( ! Ahentic_Abilities::is_readonly( $name ) ) {
					$any_write = true;
				}

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
						Ahentic_Think_Debug::queue_missing_ability( $session_id, $name, $debug, $step );

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
					$ran_any    = true;
					$any_failed = true;
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
					// Keep the think's user-facing prose for finish-without-think after the batch.
					if ( class_exists( 'Ahentic_Finish_Gate' ) ) {
						Ahentic_Finish_Gate::stash_pending_final( $session_id, $result, $debug );
					}
					return false;
				}

				$ran_any = true;
				if ( empty( $run['ok'] ) ) {
					$any_failed = true;
					if ( class_exists( 'Ahentic_Subagent' ) ) {
						Ahentic_Subagent::on_tool_failure( $session_id );
					}
				} elseif ( class_exists( 'Ahentic_Subagent' ) ) {
					$payload = isset( $run['payload'] ) && is_array( $run['payload'] ) ? $run['payload'] : array( 'ok' => true );
					Ahentic_Subagent::after_tool_success( $session_id, $name, $payload, $planned, $call_index );
				}
			}

			if ( ! $ran_any ) {
				return self::try_finish_with_reply( $session_id, $result, $debug );
			}

			if ( class_exists( 'Ahentic_Subagent' ) ) {
				$after_tools = Ahentic_Subagent::after_tools(
					$session_id,
					array(
						'any_failed' => $any_failed,
						'reply_text' => isset( $result['text'] ) ? (string) $result['text'] : '',
					)
				);
				if ( isset( $after_tools['action'] ) && 'finish_hop' === $after_tools['action'] ) {
					return true;
				}
			}

			// Forced tools: finish with stashed reply when the queue succeeds (apply/batch/recipe).
			// Failures during content-work apply (or any batch/recipe failure) return to think.
			if ( $from_forced ) {
				$has_content_work = class_exists( 'Ahentic_Session_Artifacts' )
					? Ahentic_Session_Artifacts::session_has_content_work( $session_id )
					: Ahentic_Session_Repository::get_content_work( $session_id );
				$finish_forced = Ahentic_Job_Resume::should_finish_after_forced_tools( true, $any_failed, $has_content_work, $forced_purpose, $any_write );
				if ( $finish_forced ) {
					Ahentic_Session_Repository::clear_forced_tools( $session_id );
					return self::try_finish_with_reply( $session_id, $result, $debug );
				}
				// Research-only remainder (get-blocks → search): drop the peel-think stash so a
				// later idle does not publish “I’ll inspect…” as the final reply.
				if ( ! $any_write ) {
					Ahentic_Session_Repository::clear_pending_final( $session_id );
				}
				if ( empty( Ahentic_Session_Repository::get_forced_tools( $session_id ) ) ) {
					Ahentic_Session_Repository::clear_forced_tools( $session_id );
				}
				if ( $any_failed && $has_content_work && Ahentic_Session_Repository::FORCED_PURPOSE_APPLY === $forced_purpose ) {
					Ahentic_Session_Repository::append_trace(
						$session_id,
						'forced_apply_retry',
						'Forced apply failed during content work — continuing think',
						array( 'any_failed' => true ),
						(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
					);
				} else {
					Ahentic_Session_Repository::append_trace(
						$session_id,
						'forced_tools_continue',
						'Forced tools done — continuing think',
						array(
							'purpose'    => $forced_purpose,
							'any_failed' => $any_failed,
						),
						(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
					);
				}
				return true;
			}

			// After staging a ready draft without applying it in this batch, Finish Gate may
			// force from_memory apply (skip the next free LLM think). Verify stays pre-idle.
			if ( class_exists( 'Ahentic_Finish_Gate' ) ) {
				Ahentic_Finish_Gate::decide_continue(
					$session_id,
					array(
						'phase'   => Ahentic_Finish_Gate::PHASE_POST_TOOLS,
						'result'  => $result,
						'debug'   => $debug,
						'planned' => $planned,
					)
				);
			}

			// Keep the last tool / intention label visible while the next think step starts.
			return true;
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
			$decision = Ahentic_Finish_Gate::evaluate_reply( $session_id, $result, $debug );
			if ( ! empty( $decision['continue'] ) ) {
				return true;
			}
			$result = isset( $decision['result'] ) && is_array( $decision['result'] ) ? $decision['result'] : $result;
			$debug  = isset( $decision['debug'] ) && is_array( $decision['debug'] ) ? $decision['debug'] : $debug;
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

			$next = ( is_array( $debug ) && isset( $debug['next'] ) ) ? (string) $debug['next'] : '';
			if ( 'ask_user' === $next ) {
				// Clarifying pause: keep unfinished checklist steps open.
				Ahentic_Plan::pause_for_user( $session_id );
			} else {
				Ahentic_Plan::complete_on_finish( $session_id );
			}

			Ahentic_Session_Repository::mark_idle( $session_id );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_idle',
				'ask_user' === $next ? 'Run idle (waiting on user)' : 'Run idle (final reply)',
				array(
					'reason'                   => 'ask_user' === $next ? 'ask_user' : 'final_reply',
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

			$may_spend = Ahentic_Usage::assert_may_spend();
			if ( is_wp_error( $may_spend ) ) {
				return $may_spend;
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
		 * Fail the run with an assistant error message.
		 *
		 * @param int      $session_id Session ID.
		 * @param \WP_Error $error     Error.
		 */
		private static function fail_run( $session_id, $error ) {
			Ahentic_Session_Repository::set_error(
				$session_id,
				$error->get_error_message(),
				$error->get_error_code()
			);
			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'assistant',
					'content' => self::fail_run_user_message( $error ),
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
			// Keep Plan / content_work / Artifacts / active goal for Continue (#3).
			// User Stop still cancels via cancel(); do not cancel_on_stop here.
			Ahentic_Session_Repository::set_job_resumable( $session_id, true );
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_ERROR );
			Ahentic_Session_Repository::mark_idle( $session_id );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_idle',
				'Run idle after error (job resumable)',
				array(
					'reason'        => 'error',
					'code'          => $error->get_error_code(),
					'job_resumable' => true,
				)
			);
		}

		/**
		 * User-facing copy for a failed LLM / run step.
		 *
		 * Intentional Continuable pauses already carry full user copy on the WP_Error —
		 * pass those through. Transport / model failures get the generic wrapper.
		 *
		 * @param \WP_Error $error Error.
		 * @return string
		 */
		private static function fail_run_user_message( $error ) {
			$code   = $error->get_error_code();
			$detail = $error->get_error_message();

			if ( self::fail_run_code_is_user_copy( $code ) ) {
				return $detail;
			}

			// Timeouts are transport failures — not missing connectors.
			if ( self::error_looks_like_timeout( $detail ) ) {
				return sprintf(
					/* translators: %s: error message */
					__( 'Sorry — the model request timed out (%s). The run stopped; use Continue to resume the same job.', 'ahentic' ),
					$detail
				);
			}
			return sprintf(
				/* translators: %s: error message */
				__( 'Sorry — I could not complete that request (%s). Use Continue to resume, or check that WordPress AI / a model connector is configured.', 'ahentic' ),
				$detail
			);
		}

		/**
		 * Whether a fail_run code's message is already the sidebar-facing Continuable copy.
		 *
		 * @param string $code WP_Error code.
		 * @return bool
		 */
		private static function fail_run_code_is_user_copy( $code ) {
			$code = (string) $code;
			return in_array(
				$code,
				array(
					'ahentic_max_steps',
					Ahentic_Usage::CODE_SESSION_SOFT_BUDGET,
				),
				true
			);
		}

		/**
		 * @param string $detail Error detail.
		 * @return bool
		 */
		private static function error_looks_like_timeout( $detail ) {
			$detail = (string) $detail;
			return ( false !== stripos( $detail, 'timed out' ) )
				|| ( false !== stripos( $detail, 'cURL error 28' ) )
				|| ( false !== stripos( $detail, 'Operation timed out' ) );
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
		 * Friendly progress label for an ability / tool id.
		 *
		 * @param string $tool  Ability name.
		 * @param array  $debug Debug block.
		 * @return string
		 */
		public static function progress_label_for_tool( $tool, $debug = array() ) {
			$tool = (string) $tool;
			if ( class_exists( 'Ahentic_Abilities' ) ) {
				$from_catalog = Ahentic_Abilities::progress_label( $tool );
				if ( '' !== $from_catalog ) {
					return $from_catalog;
				}
			}

			// Legacy / non-catalog labels (not owned by an ability module yet).
			$legacy = array(
				'core/read-content'    => __( 'Reading site content…', 'ahentic' ),
				'ahentic/inspect-site' => __( 'Inspecting the site…', 'ahentic' ),
			);
			if ( isset( $legacy[ $tool ] ) ) {
				return $legacy[ $tool ];
			}

			$from_debug = Ahentic_Think_Debug::progress_label_from_debug( $debug, '' );
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
			Ahentic_Plan::cancel_on_stop( $session_id );
			Ahentic_Session_Repository::set_job_resumable( $session_id, false );
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
		 * Site-wide stop when daily/runaway token limit trips.
		 *
		 * Cancels running sessions only (not awaiting_human / awaiting_browser).
		 *
		 * @param string $code Ahentic_Usage::CODE_*.
		 */
		public static function on_token_limit_enforced( $code ) {
			$code = self::normalize_limit_code( $code );

			$query = new WP_Query(
				array(
					'post_type'              => Ahentic_Session_CPT::POST_TYPE,
					'post_status'            => 'private',
					'posts_per_page'         => 500,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- rare limit-trip path.
						array(
							'key'   => Ahentic_Session_Repository::META_STATUS,
							'value' => Ahentic_Session_Repository::STATUS_RUNNING,
						),
					),
				)
			);

			foreach ( $query->posts as $session_id ) {
				self::stop_session_for_token_limit( (int) $session_id, $code );
			}
		}

		/**
		 * Stop one running session due to token limit (assistant message + error status).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $code       Limit error code.
		 */
		public static function stop_session_for_token_limit( $session_id, $code ) {
			$session_id = (int) $session_id;
			$post       = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return;
			}

			$status = Ahentic_Session_Repository::get_status( $session_id );
			if ( Ahentic_Session_Repository::STATUS_RUNNING !== $status ) {
				return;
			}

			$code    = self::normalize_limit_code( $code );
			$message = Ahentic_Usage::message_for_code( $code );

			Ahentic_Session_Repository::set_pending_tool( $session_id, null );
			Ahentic_Step_Queue::release_run( $session_id );
			Ahentic_Plan::cancel_on_stop( $session_id );
			Ahentic_Session_Repository::set_error( $session_id, $message, $code );
			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'assistant',
					'content' => $message,
					'meta'    => array(
						'error' => true,
						'code'  => $code,
					),
				)
			);
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'error',
				$message,
				array(
					'code'   => $code,
					'reason' => 'token_limit',
				)
			);
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_ERROR );
			Ahentic_Session_Repository::mark_idle( $session_id );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_idle',
				'Run idle after token limit',
				array(
					'reason' => 'token_limit',
					'code'   => $code,
				)
			);
		}

		/**
		 * Normalize a token-limit error code.
		 *
		 * @param string $code Raw code.
		 * @return string
		 */
		private static function normalize_limit_code( $code ) {
			$code = (string) $code;
			if ( Ahentic_Usage::CODE_RUNAWAY_LOCK !== $code ) {
				return Ahentic_Usage::CODE_DAILY_LIMIT;
			}
			return $code;
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
				if ( class_exists( 'Ahentic_Subagent' ) ) {
					Ahentic_Subagent::after_resume_tool(
						$session_id,
						array(
							'ok'     => false,
							'reason' => 'user_denied',
						)
					);
				}
				Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
				Ahentic_Session_Repository::set_progress( $session_id, __( 'Skipping that action…', 'ahentic' ), $step );
				Ahentic_Step_Queue::enqueue_step( $session_id );
				Ahentic_Step_Queue::schedule_interactive_run( $session_id );
				return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
			}

			if ( ! in_array( $choice, array( 'allow_once', 'allow_session', 'always_allow' ), true ) ) {
				return new WP_Error( 'ahentic_bad_decision', __( 'Invalid approval decision.', 'ahentic' ), array( 'status' => 400 ) );
			}

			if ( ! Ahentic_Abilities::hitl_choice_allowed( $name, $choice ) ) {
				return new WP_Error(
					'ahentic_hitl_not_preallowable',
					__( 'This action always needs a fresh Allow once — it cannot be allowed for the chat or forever.', 'ahentic' ),
					array( 'status' => 400 )
				);
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

			if ( class_exists( 'Ahentic_Subagent' ) ) {
				if ( ! empty( $run['ok'] ) ) {
					Ahentic_Subagent::after_resume_tool(
						$session_id,
						array(
							'ok'      => true,
							'name'    => $name,
							'payload' => isset( $run['payload'] ) && is_array( $run['payload'] ) ? $run['payload'] : array( 'ok' => true ),
						)
					);
				} else {
					Ahentic_Subagent::after_resume_tool(
						$session_id,
						array(
							'ok'     => false,
							'reason' => 'tool_failed',
						)
					);
				}
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

			if ( class_exists( 'Ahentic_Subagent' ) ) {
				if ( $ok ) {
					Ahentic_Subagent::after_resume_tool(
						$session_id,
						array(
							'ok'      => true,
							'name'    => $name,
							'payload' => is_array( $tool_payload ) ? $tool_payload : array( 'ok' => true ),
						)
					);
				} else {
					Ahentic_Subagent::after_resume_tool(
						$session_id,
						array(
							'ok'     => false,
							'reason' => 'browser_error',
						)
					);
				}
			}

			$forced_remain = ! empty( Ahentic_Session_Repository::get_forced_tools( $session_id ) );
			$has_content_work = class_exists( 'Ahentic_Session_Artifacts' )
				? Ahentic_Session_Artifacts::session_has_content_work( $session_id )
				: Ahentic_Session_Repository::get_content_work( $session_id );
			// Empty purpose must stay empty — get_forced_tools_purpose() defaults to apply and
			// would falsely finish a lone get-blocks resume as a completed forced apply.
			$forced_purpose_raw = Ahentic_Session_Repository::get_forced_tools_purpose_raw( $session_id );
			$try_finish         = Ahentic_Job_Resume::should_try_finish_after_browser_resume( $name, $ok, $forced_remain, $has_content_work, $forced_purpose_raw );
			if ( $try_finish ) {
				$stashed = Ahentic_Session_Repository::get_pending_final( $session_id );
				$result  = array(
					'text'  => ( is_array( $stashed ) && ! empty( $stashed['text'] ) ) ? (string) $stashed['text'] : '',
					'model' => ( is_array( $stashed ) && ! empty( $stashed['model'] ) ) ? (string) $stashed['model'] : '',
				);
				$debug = ( is_array( $stashed ) && ! empty( $stashed['debug'] ) && is_array( $stashed['debug'] ) )
					? $stashed['debug']
					: array( 'next' => 'reply' );
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'browser_attr_batch_finish',
					'Browser batch complete — finishing without another think',
					array(
						'ability' => $name,
						'ok'      => $ok,
						'purpose' => $forced_purpose_raw,
					),
					$step
				);
				Ahentic_Session_Repository::clear_forced_tools( $session_id );
				if ( ! self::try_finish_with_reply( $session_id, $result, $debug ) ) {
					return Ahentic_Session_Repository::to_rest( $session_id, true, 100 );
				}
			} elseif ( ! $forced_remain && class_exists( 'Ahentic_Abilities' ) && Ahentic_Abilities::is_readonly( $name ) ) {
				// Lone research browser tool: do not keep a finish stash across the next think.
				Ahentic_Session_Repository::clear_pending_final( $session_id );
			}

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

			// Do not spend tokens on title/summary after a daily/runaway trip.
			if ( is_wp_error( Ahentic_Usage::check_may_spend() ) ) {
				update_post_meta( $session_id, Ahentic_Session_Repository::META_SUMMARY_STATUS, 'skipped' );
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
			$fallback = Ahentic_Tool_Runner::server_fallback_for_browser( $name, $input, $ctx );

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
				Ahentic_Tool_Runner::append_tool_failure(
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
				Ahentic_Tool_Runner::append_tool_failure(
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
		 * Truncate text for trace payloads.
		 *
		 * @param string $text Text.
		 * @param int    $max  Max length.
		 * @return string
		 */
		public static function excerpt( $text, $max = 120 ) {
			return Ahentic_Prompt_Assembler::excerpt( $text, $max );
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
