<?php
/**
 * Ability tool execution pipeline for the orchestrator.
 *
 * Extracts the shared run path used by the step loop, HITL approval resume,
 * and browser result persist.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Tool_Runner' ) ) {
	/**
	 * Runs one ability through auto-stage → from_memory → HITL/browser pause → execute → assess → persist.
	 *
	 * Orchestrator helpers (maybe_auto_stage_tool_input, expand_from_memory, etc.) are called as
	 * public static methods on Ahentic_Orchestrator. Prefer wrapping execute with
	 * Ahentic_Orchestrator::with_current_session() when available so abilities can read page context.
	 */
	class Ahentic_Tool_Runner {

		/**
		 * Run one available ability through the contract pipeline:
		 * auto-stage → from_memory validate/expand → HITL pause → browser pause → PHP execute → assess → persist.
		 *
		 * @param int    $session_id Session CPT ID.
		 * @param string $name       Ability name (already known available for the mode).
		 * @param array  $input      Tool input.
		 * @param array  $ctx {
		 *   @type int         $step         Step count for progress/trace.
		 *   @type array|null  $debug        Control-block debug (progress labels).
		 *   @type bool        $skip_hitl    True after human approval (do not pause for HITL again).
		 *   @type string      $approved     allow_once|allow_session|always_allow when skip_hitl.
		 *   @type array       $planned      Full tools_planned batch (browser remainder).
		 *   @type int         $call_index   Index in $planned of this call.
		 *   @type string      $source       Optional meta source e.g. suggested_action.
		 *   @type bool        $auto_stage   Default true; set false to skip maybe_auto_stage.
		 * }
		 * @return array {
		 *   @type string $outcome  One of: paused_hitl, paused_browser, continued.
		 *   @type bool   $ok       For continued (true when the tool succeeded).
		 * }
		 */
		public static function run( $session_id, $name, array $input, array $ctx = array() ) {
			$session_id = (int) $session_id;
			$name       = (string) $name;
			$step       = isset( $ctx['step'] ) ? (int) $ctx['step'] : (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			$debug      = isset( $ctx['debug'] ) && is_array( $ctx['debug'] ) ? $ctx['debug'] : array();
			$skip_hitl  = ! empty( $ctx['skip_hitl'] );
			$approved   = isset( $ctx['approved'] ) ? (string) $ctx['approved'] : '';
			$planned    = isset( $ctx['planned'] ) && is_array( $ctx['planned'] ) ? $ctx['planned'] : array();
			$call_index = isset( $ctx['call_index'] ) ? (int) $ctx['call_index'] : 0;
			$source     = isset( $ctx['source'] ) ? (string) $ctx['source'] : '';
			$auto_stage = ! isset( $ctx['auto_stage'] ) || $ctx['auto_stage'];

			// Oversized inline bodies → auto-stage + from_memory (pending stays key-only).
			if ( $auto_stage && class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$input = Ahentic_Orchestrator::maybe_auto_stage_tool_input( $session_id, $name, $input, $step );
			}

			// Session artifacts: validate from_memory early; expand only for PHP execute.
			// Pending HITL / browser keep key only (PRD); REST expands for the browser runner.
			$artifact_key = '';
			$needs_hitl   = Ahentic_Abilities::requires_hitl( $name )
				&& ! Ahentic_Session_Repository::hitl_is_preallowed( $session_id, $name )
				&& ! $skip_hitl;
			$needs_browser = Ahentic_Abilities::requires_browser_runtime( $name, $input );

			if ( class_exists( 'Ahentic_Session_Artifacts' ) && ! empty( $input['from_memory'] ) ) {
				$artifact_key = Ahentic_Session_Artifacts::sanitize_artifact_key( (string) $input['from_memory'] );
				if ( $needs_hitl || $needs_browser ) {
					$valid = Ahentic_Session_Artifacts::validate_from_memory( $session_id, $name, $input );
					if ( is_wp_error( $valid ) ) {
						Ahentic_Orchestrator::append_tool_failure( $session_id, $name, $valid, $step );
						return array(
							'outcome' => 'continued',
							'ok'      => false,
						);
					}
				} else {
					$resolved = Ahentic_Orchestrator::expand_from_memory( $session_id, $name, $input );
					if ( is_wp_error( $resolved ) ) {
						Ahentic_Orchestrator::append_tool_failure( $session_id, $name, $resolved, $step );
						return array(
							'outcome' => 'continued',
							'ok'      => false,
						);
					}
					$input        = $resolved['input'];
					$artifact_key = $resolved['artifact_key'];
				}
			}

			// Mutating abilities pause for human approval unless already allowed / skip_hitl.
			// HITL must run before browser pause so save-post / convert-blocks can be approved first.
			if ( $needs_hitl ) {
				return self::pause_hitl( $session_id, $name, $input, $artifact_key, $step, $source, false );
			}

			// Browser abilities (and http-fetch as_user) pause for the sidebar to run JS and POST the result.
			if ( $needs_browser ) {
				$preflight = Ahentic_Orchestrator::browser_preflight( $session_id, $name, $input );
				if ( is_wp_error( $preflight ) ) {
					Ahentic_Orchestrator::append_tool_failure( $session_id, $name, $preflight, $step );
					return array(
						'outcome' => 'continued',
						'ok'      => false,
					);
				}
				if ( is_array( $preflight ) && ! empty( $preflight['fallback'] ) ) {
					$name          = (string) $preflight['fallback']['name'];
					$input         = isset( $preflight['fallback']['input'] ) && is_array( $preflight['fallback']['input'] ) ? $preflight['fallback']['input'] : $input;
					$needs_browser = false;
					$needs_hitl    = Ahentic_Abilities::requires_hitl( $name )
						&& ! Ahentic_Session_Repository::hitl_is_preallowed( $session_id, $name )
						&& ! $skip_hitl;
					if ( $needs_hitl ) {
						// Re-enter HITL path for the server fallback ability.
						return self::pause_hitl( $session_id, $name, $input, $artifact_key, $step, $source, true );
					}
					// Fall through to PHP execute with rewritten ability.
				} else {
					return self::pause_browser(
						$session_id,
						$name,
						$input,
						$artifact_key,
						$step,
						$debug,
						$planned,
						$call_index,
						$skip_hitl ? $approved : '',
						$source
					);
				}
			}

			// Approval resume: clear pending and resume running before PHP execute.
			if ( $skip_hitl ) {
				Ahentic_Session_Repository::set_pending_tool( $session_id, null );
				Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
			}

			if ( class_exists( 'Ahentic_Session_Artifacts' ) && ! empty( $input['from_memory'] ) ) {
				$resolved = Ahentic_Orchestrator::expand_from_memory( $session_id, $name, $input );
				if ( is_wp_error( $resolved ) ) {
					Ahentic_Orchestrator::append_tool_failure( $session_id, $name, $resolved, $step );
					return array(
						'outcome' => 'continued',
						'ok'      => false,
					);
				}
				$input        = $resolved['input'];
				$artifact_key = $resolved['artifact_key'] ? $resolved['artifact_key'] : $artifact_key;
			}

			$label = Ahentic_Orchestrator::progress_label_for_tool( $name, $debug );
			Ahentic_Session_Repository::set_progress( $session_id, $label, $step );

			$trace_data = array(
				'ability'      => $name,
				'input'        => Ahentic_Orchestrator::trace_tool_input( $input ),
				'artifact_key' => $artifact_key,
			);
			if ( $skip_hitl && $approved ) {
				$trace_data['approved'] = $approved;
			}
			if ( $source ) {
				$trace_data['source'] = $source;
			}
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'tool_executed',
				$label,
				$trace_data,
				$step
			);

			if ( method_exists( 'Ahentic_Orchestrator', 'with_current_session' ) ) {
				$tool_result = Ahentic_Orchestrator::with_current_session(
					$session_id,
					static function () use ( $name, $input ) {
						return Ahentic_Abilities::execute( $name, $input );
					}
				);
			} else {
				$tool_result = Ahentic_Abilities::execute( $name, $input );
			}

			Ahentic_Session_Repository::touch_heartbeat( $session_id );
			$ok      = ! is_wp_error( $tool_result );
			$payload = $ok ? $tool_result : Ahentic_Orchestrator::tool_error_payload( $tool_result );

			if ( $ok && $artifact_key && class_exists( 'Ahentic_Session_Artifacts' ) ) {
				Ahentic_Session_Artifacts::mark_applied( $session_id, $artifact_key );
			}

			$payload = Ahentic_Orchestrator::assess_write_payload( $session_id, $name, $payload, $ok );

			$content = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $content ) ) {
				$content = '{}';
			}

			$entry_meta = array(
				'ability'      => $name,
				'ok'           => $ok,
				'artifact_key' => $artifact_key ? $artifact_key : null,
			);
			if ( $skip_hitl && $approved ) {
				$entry_meta['approved'] = $approved;
			}
			if ( $source ) {
				$entry_meta['source'] = $source;
			}

			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'tool',
					'content' => $content,
					'meta'    => $entry_meta,
				)
			);
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'tool_result',
				$ok ? ( 'Result: ' . $name ) : ( 'Error: ' . $name ),
				array(
					'ability' => $name,
					'ok'      => $ok,
					'excerpt' => Ahentic_Orchestrator::excerpt( $content, 240 ),
				),
				$step
			);

			return array(
				'outcome' => 'continued',
				'ok'      => $ok,
			);
		}

		/**
		 * Persist a tool result that already ran (browser resume path).
		 * Applies assess_write_payload, appends entry+trace, clears pending, sets running.
		 * Does NOT enqueue the next step — caller does that.
		 *
		 * @param int    $session_id   Session ID.
		 * @param string $name         Ability name.
		 * @param array  $tool_payload Result payload (already shaped).
		 * @param bool   $ok           Whether the tool succeeded.
		 * @param array  $meta         Extra entry meta (call_id, browser, artifact_key, approved, source).
		 * @param int    $step         Step count.
		 * @return void
		 */
		public static function record_completed_result( $session_id, $name, array $tool_payload, $ok, array $meta, $step ) {
			$session_id = (int) $session_id;
			$name       = (string) $name;
			$ok         = (bool) $ok;
			$step       = (int) $step;
			$artifact_key = ! empty( $meta['artifact_key'] ) ? (string) $meta['artifact_key'] : '';

			if ( $ok && $artifact_key && class_exists( 'Ahentic_Session_Artifacts' ) ) {
				Ahentic_Session_Artifacts::mark_applied( $session_id, $artifact_key );
			}

			$tool_payload = Ahentic_Orchestrator::assess_write_payload( $session_id, $name, $tool_payload, $ok );

			$content = wp_json_encode( $tool_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $content ) ) {
				$content = '{}';
			}

			$entry_meta = array_merge(
				array(
					'ability'      => $name,
					'ok'           => $ok,
					'artifact_key' => $artifact_key ? $artifact_key : null,
				),
				$meta
			);

			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'tool',
					'content' => $content,
					'meta'    => $entry_meta,
				)
			);

			$trace_data = array(
				'ability' => $name,
				'ok'      => $ok,
				'excerpt' => Ahentic_Orchestrator::excerpt( $content, 240 ),
			);
			if ( ! empty( $meta['browser'] ) ) {
				$trace_data['browser'] = true;
			}

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'tool_result',
				$ok ? ( 'Result: ' . $name ) : ( 'Error: ' . $name ),
				$trace_data,
				$step
			);

			Ahentic_Session_Repository::set_pending_tool( $session_id, null );
			Ahentic_Session_Repository::clear_browser_paused_at( $session_id );
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_RUNNING );
			Ahentic_Session_Repository::set_progress( $session_id, __( 'Planning next steps…', 'ahentic' ), $step );
		}

		/**
		 * Pause the session for human approval of a mutating ability.
		 *
		 * @param int    $session_id   Session ID.
		 * @param string $name         Ability name.
		 * @param array  $input        Tool input (key-only when from_memory).
		 * @param string $artifact_key Artifact key or empty.
		 * @param int    $step         Step count.
		 * @param string $source       Optional source meta.
		 * @param bool   $fallback     True when this HITL is for a browser→PHP fallback ability.
		 * @return array { outcome: paused_hitl, ok: false }
		 */
		private static function pause_hitl( $session_id, $name, array $input, $artifact_key, $step, $source = '', $fallback = false ) {
			$summary = Ahentic_Orchestrator::hitl_summary_for_pending( $session_id, $name, $input );
			$pending = array(
				'name'    => $name,
				'input'   => $input,
				'summary' => $summary,
				'call_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ahentic_', true ),
			);
			if ( $source ) {
				$pending['source'] = $source;
			}
			if ( $artifact_key ) {
				$pending['artifact_key'] = $artifact_key;
				// Fallback HITL from browser_preflight keeps key only (no memory pointer), matching orchestrator.
				if ( ! $fallback ) {
					$mem = Ahentic_Orchestrator::memory_pointer_for_pending( $session_id, $artifact_key );
					if ( $mem ) {
						$pending['memory'] = $mem;
					}
				}
			}
			Ahentic_Session_Repository::set_pending_tool( $session_id, $pending );
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_AWAITING_HUMAN );
			Ahentic_Session_Repository::set_progress(
				$session_id,
				sprintf(
					/* translators: %s: short action summary */
					__( 'Waiting for your approval: %s', 'ahentic' ),
					$summary
				),
				$step
			);

			$trace_data = array(
				'ability' => $name,
				'input'   => Ahentic_Orchestrator::trace_tool_input( $input ),
			);
			if ( $fallback ) {
				$trace_data['fallback'] = true;
			}
			if ( $source ) {
				$trace_data['source'] = $source;
			}

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'hitl_pause',
				$summary,
				$trace_data,
				$step
			);

			return array(
				'outcome' => 'paused_hitl',
				'ok'      => false,
			);
		}

		/**
		 * Pause the session for the sidebar browser runtime.
		 *
		 * @param int    $session_id   Session ID.
		 * @param string $name         Ability name.
		 * @param array  $input        Tool input (key-only when from_memory).
		 * @param string $artifact_key Artifact key or empty.
		 * @param int    $step         Step count.
		 * @param array  $debug        Debug block for progress labels.
		 * @param array  $planned      Full tools_planned batch.
		 * @param int    $call_index   Index of this call in $planned.
		 * @param string $approved     Approval choice when resuming after HITL, or empty.
		 * @param string $source       Optional source meta.
		 * @return array { outcome: paused_browser, ok: false }
		 */
		private static function pause_browser( $session_id, $name, array $input, $artifact_key, $step, array $debug, array $planned, $call_index, $approved = '', $source = '' ) {
			$summary = Ahentic_Abilities::browser_summary( $name, $input );
			$pending = array(
				'name'    => $name,
				'input'   => $input,
				'summary' => $summary,
				'runtime' => 'browser',
				'call_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ahentic_', true ),
			);
			if ( $approved ) {
				$pending['approved'] = $approved;
			}
			if ( $source ) {
				$pending['source'] = $source;
			}
			if ( $artifact_key ) {
				$pending['artifact_key'] = $artifact_key;
				$mem                     = Ahentic_Orchestrator::memory_pointer_for_pending( $session_id, $artifact_key );
				if ( $mem ) {
					$pending['memory'] = $mem;
				}
			}
			Ahentic_Session_Repository::set_pending_tool( $session_id, $pending );
			Ahentic_Session_Repository::set_status( $session_id, Ahentic_Session_Repository::STATUS_AWAITING_BROWSER );
			Ahentic_Session_Repository::touch_browser_paused_at( $session_id );
			Ahentic_Session_Repository::set_progress(
				$session_id,
				sprintf(
					/* translators: %s: tool / action label */
					__( 'Waiting for this page to run: %s', 'ahentic' ),
					Ahentic_Orchestrator::progress_label_for_tool( $name, $debug )
				),
				$step
			);

			if ( ! empty( $planned ) ) {
				Ahentic_Orchestrator::preserve_browser_batch_remainder( $session_id, $planned, $call_index, $step );
			}

			$trace_data = array(
				'ability'      => $name,
				'input'        => Ahentic_Orchestrator::trace_tool_input( $input ),
				'artifact_key' => $artifact_key,
			);
			if ( $approved ) {
				$trace_data['approved'] = $approved;
			}
			if ( $source ) {
				$trace_data['source'] = $source;
			}

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'browser_pause',
				$summary,
				$trace_data,
				$step
			);

			return array(
				'outcome' => 'paused_browser',
				'ok'      => false,
			);
		}
	}
}
