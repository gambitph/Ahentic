<?php
/**
 * Ability tool execution pipeline for the orchestrator.
 *
 * Owns the shared run path used by the step loop, HITL approval resume,
 * and browser result persist: auto-stage → from_memory → HITL/browser pause →
 * execute → Finish gate assess → persist.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Tool_Runner' ) ) {
	/**
	 * Deep pipeline module for one Ability run.
	 *
	 * Primary interface: run() and record_completed_result(). Pipeline helpers
	 * (auto-stage, from_memory, preflight, …) live here — not on the Orchestrator.
	 * Write assessment is Ahentic_Finish_Gate::assess_write_payload.
	 * Progress labels / session context remain on Ahentic_Orchestrator (shared with the step loop).
	 */
	class Ahentic_Tool_Runner {

		/**
		 * Run one available ability through the contract pipeline:
		 * auto-stage → from_memory validate/expand → HITL pause → browser pause → PHP execute → Finish gate assess → persist.
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
				$input = self::maybe_auto_stage_tool_input( $session_id, $name, $input, $step );
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
						self::append_tool_failure( $session_id, $name, $valid, $step );
						return array(
							'outcome' => 'continued',
							'ok'      => false,
						);
					}
				} else {
					$resolved = self::expand_from_memory( $session_id, $name, $input );
					if ( is_wp_error( $resolved ) ) {
						self::append_tool_failure( $session_id, $name, $resolved, $step );
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
				$preflight = self::browser_preflight( $session_id, $name, $input );
				if ( is_wp_error( $preflight ) ) {
					self::append_tool_failure( $session_id, $name, $preflight, $step );
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
				$resolved = self::expand_from_memory( $session_id, $name, $input );
				if ( is_wp_error( $resolved ) ) {
					self::append_tool_failure( $session_id, $name, $resolved, $step );
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
				'input'        => self::trace_tool_input( $input ),
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
			$payload = $ok ? $tool_result : self::tool_error_payload( $tool_result );

			if ( $ok && $artifact_key && class_exists( 'Ahentic_Session_Artifacts' ) ) {
				Ahentic_Session_Artifacts::mark_applied( $session_id, $artifact_key );
			}

			$payload = Ahentic_Finish_Gate::assess_write_payload( $session_id, $name, $payload, $ok );

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
					'excerpt' => self::excerpt( $content, 240 ),
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

			$tool_payload = Ahentic_Finish_Gate::assess_write_payload( $session_id, $name, $tool_payload, $ok );

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
				'excerpt' => self::excerpt( $content, 240 ),
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
		 * Normalize a WP_Error into the tool-result payload the agent reads.
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
		 * Map a browser ability to a server twin when the editor is not open.
		 *
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
		 * Append a failed tool result (e.g. artifact_missing) and continue the loop.
		 *
		 * @param int       $session_id Session ID.
		 * @param string    $name       Ability.
		 * @param \WP_Error $error      Error.
		 * @param int       $step       Step.
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
		 * Browser preflight: editor open / fresh page context, or server fallback.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability.
		 * @param array  $input      Tool input.
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
		 * Expand input.from_memory for tool execution.
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
			$summary = self::hitl_summary_for_pending( $session_id, $name, $input );
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
				// Fallback HITL from browser_preflight keeps key only (no memory pointer), matching prior orchestrator.
				if ( ! $fallback ) {
					$mem = self::memory_pointer_for_pending( $session_id, $artifact_key );
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
				'input'   => self::trace_tool_input( $input ),
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
				$mem                     = self::memory_pointer_for_pending( $session_id, $artifact_key );
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
				self::preserve_browser_batch_remainder( $session_id, $planned, $call_index, $step );
			}

			$trace_data = array(
				'ability'      => $name,
				'input'        => self::trace_tool_input( $input ),
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

		/**
		 * HITL summary, enriched with artifact pointer when using from_memory.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability.
		 * @param array  $input      Tool input.
		 * @return string
		 */
		private static function hitl_summary_for_pending( $session_id, $name, array $input ) {
			$summary = Ahentic_Abilities::hitl_summary( $name, $input );
			if ( empty( $input['from_memory'] ) || ! class_exists( 'Ahentic_Session_Artifacts' ) ) {
				return $summary;
			}
			$key = Ahentic_Session_Artifacts::sanitize_artifact_key( (string) $input['from_memory'] );
			$mem = self::memory_pointer_for_pending( $session_id, $key );
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
		private static function memory_pointer_for_pending( $session_id, $key ) {
			if ( ! class_exists( 'Ahentic_Session_Artifacts' ) || ! $key ) {
				return null;
			}
			return Ahentic_Session_Artifacts::pointer_with_excerpt( $session_id, $key );
		}

		/**
		 * Keep the rest of a planned batch alive across a browser pause.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $planned    Full planned batch.
		 * @param int   $index      Index of the call that paused.
		 * @param int   $step       Step number for the trace.
		 */
		private static function preserve_browser_batch_remainder( $session_id, array $planned, $index, $step ) {
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
		 * Truncate text for traces / summaries.
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
	}
}
