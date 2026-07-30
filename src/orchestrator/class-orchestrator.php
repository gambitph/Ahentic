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
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
			Ahentic_Session_Repository::set_progress( $session_id, __( 'Starting…', 'ahentic' ) );

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

			$result = self::run_llm_phase(
				$session_id,
				__( 'Thinking…', 'ahentic' ),
				self::system_prompt( $mode ),
				null
			);

			if ( is_wp_error( $result ) ) {
				self::fail_run( $session_id, $result );
				return false;
			}

			$debug   = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : array();
			$next    = isset( $debug['next'] ) ? (string) $debug['next'] : 'reply';
			$planned = self::normalize_tool_calls( isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array() );

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

			// Optional intermediate assistant note before tools (user-facing prose only).
			if ( ! empty( $result['text'] ) ) {
				Ahentic_Session_Repository::append_entry(
					$session_id,
					array(
						'role'    => 'assistant',
						'content' => $result['text'],
						'meta'    => array(
							'model'       => isset( $result['model'] ) ? $result['model'] : '',
							'debug'       => $debug,
							'intermediate'=> true,
						),
					)
				);
			}

			$ran_any = false;
			foreach ( $planned as $call ) {
				$name  = $call['name'];
				$input = $call['input'];

				if ( ! in_array( $name, Ahentic_Abilities::available_for_agent(), true ) ) {
					$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
					Ahentic_Session_Repository::append_entry(
						$session_id,
						array(
							'role'    => 'tool',
							'content' => wp_json_encode(
								array(
									'ok'      => false,
									'error'   => 'ability_unavailable',
									'message' => sprintf(
										/* translators: %s: ability name */
										__( 'Ability %s is not available in this build yet.', 'ahentic' ),
										$name
									),
								),
								JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
							),
							'meta'    => array(
								'ability' => $name,
								'ok'      => false,
							),
						)
					);
					Ahentic_Session_Repository::append_trace(
						$session_id,
						'tool_result',
						'Ability unavailable: ' . $name,
						array(
							'ability' => $name,
							'ok'      => false,
						),
						$step
					);
					$ran_any = true;
					continue;
				}

				$label = self::progress_label_for_tool( $name, $debug );
				$step  = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
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

			Ahentic_Session_Repository::set_progress( $session_id, __( 'Thinking…', 'ahentic' ) );
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
		 * One LLM phase with progress + trace.
		 *
		 * @param int         $session_id Session ID.
		 * @param string      $progress   Progress label.
		 * @param string      $system     System prompt.
		 * @param array|null  $extra_turn Optional prior assistant turn to inject into history.
		 * @return array|\WP_Error
		 */
		private static function run_llm_phase( $session_id, $progress, $system, $extra_turn = null ) {
			$step = Ahentic_Session_Repository::bump_step( $session_id );
			Ahentic_Session_Repository::set_progress( $session_id, $progress, $step );

			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			$built   = self::build_chat_payload( $entries );
			$history = $built['history'];

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
					'user_excerpt' => self::excerpt( $built['user'], 120 ),
				),
				$step
			);

			$result = Ahentic_AI::complete_chat( $system, $history, $built['user'] );
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

				Ahentic_Session_Repository::append_trace(
					$session_id,
					'llm_thinking',
					$intention ? $intention : 'Model thinking',
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

			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'assistant',
					'content' => $result['text'],
					'meta'    => array(
						'model' => isset( $result['model'] ) ? $result['model'] : '',
						'debug' => $debug,
					),
				)
			);

			Ahentic_Session_Repository::mark_idle( $session_id );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'run_idle',
				'Run idle (final reply)',
				array( 'reason' => 'final_reply' )
			);
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
				'ahentic/analyze-plugins'    => __( 'Analyzing plugins…', 'ahentic' ),
				'ahentic/get-site-snapshot'  => __( 'Reading site snapshot…', 'ahentic' ),
				'ahentic/get-site-health'    => __( 'Checking site health…', 'ahentic' ),
				'ahentic/search-content'     => __( 'Searching site content…', 'ahentic' ),
				'ahentic/list-content'       => __( 'Listing posts and pages…', 'ahentic' ),
				'ahentic/install-plugin'     => __( 'Installing plugin…', 'ahentic' ),
				'ahentic/activate-plugin'    => __( 'Activating plugin…', 'ahentic' ),
				'core/read-content'          => __( 'Reading site content…', 'ahentic' ),
				'ahentic/inspect-site'       => __( 'Inspecting the site…', 'ahentic' ),
			);

			if ( isset( $map[ $tool ] ) ) {
				return $map[ $tool ];
			}

			if ( ! empty( $debug['intention'] ) ) {
				return sprintf(
					/* translators: %s: short intention */
					__( 'Working on: %s…', 'ahentic' ),
					self::excerpt( (string) $debug['intention'], 48 )
				);
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
		 * HITL approval resume (stub-ready).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $decision   Decision payload.
		 * @return array|\WP_Error
		 */
		public static function handle_approval( $session_id, array $decision ) {
			$pending = Ahentic_Session_Repository::get_pending_tool( $session_id );
			if ( ! $pending ) {
				return new WP_Error( 'ahentic_no_pending', __( 'No pending tool to approve.', 'ahentic' ), array( 'status' => 400 ) );
			}

			$choice = isset( $decision['decision'] ) ? (string) $decision['decision'] : '';

			if ( 'deny' === $choice ) {
				Ahentic_Session_Repository::set_pending_tool( $session_id, null );
				Ahentic_Session_Repository::append_entry(
					$session_id,
					array(
						'role'    => 'tool',
						'content' => __( 'User denied this action.', 'ahentic' ),
						'meta'    => array(
							'tool'   => $pending,
							'denied' => true,
						),
					)
				);
				Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
				Ahentic_Step_Queue::enqueue_step( $session_id );
				return Ahentic_Session_Repository::to_rest( $session_id );
			}

			// allow_once | allow_session | always_allow — ability execution comes later.
			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'event',
					'content' => __( 'Approval recorded. Tool execution will run when abilities are wired.', 'ahentic' ),
					'meta'    => array(
						'type'     => 'approval',
						'decision' => $choice,
						'tool'     => $pending,
					),
				)
			);
			Ahentic_Session_Repository::set_pending_tool( $session_id, null );
			Ahentic_Session_Repository::mark_idle( $session_id );

			return Ahentic_Session_Repository::to_rest( $session_id );
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
				$base .= ' Mode: Ask — answer questions; prefer next="reply". You may use readonly tools if needed.';
			} else {
				$base .= ' Mode: Agent — you run a multi-step loop. When you need site facts, set next="use_tools" '
					. 'and list tools in tools_planned. After tool results appear in the next message, think again '
					. 'and either call more tools or set next="reply" / "ask_user". '
					. "Available abilities right now: {$tools_list}. "
					. 'Prefer ahentic/get-site-snapshot when you need the site name, theme, environment, active plugins, or admin_links. '
					. 'Do not claim you ran a tool that is not in the available list.';
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
				. '{"intention":"short goal for this step","thinking":"1-3 sentences","tools_planned":["ahentic/get-site-snapshot"],"next":"reply|ask_user|use_tools"}' . "\n"
				. 'AHENTIC_DEBUG>>>' . "\n"
				. 'tools_planned may be strings (ability names) or objects {"name":"ahentic/…","input":{}}. '
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
