<?php
/**
 * Subagent: cheap mode over existing abilities (Recipe + mini-job hop).
 *
 * Deepens forced_tools and optional one-shot slim thinks — does not invent
 * abilities or domain-specific chains. Advances tools the model (or a prior
 * batch) already chose; binds placeholders from earlier step payloads;
 * preserves remainders across HITL/browser pause; may peel a hop with a
 * main-packed brief when the control block asks.
 *
 * @package Ahentic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Subagent' ) ) {
	/**
	 * Deep module: skip or slim full main thinks for isolatable ability work.
	 *
	 * Primary interface (Recipe): after_tool_success(), preserve_batch_remainder(),
	 * bind_recipe_input(), finalize_recipe_if_idle(), recipe_state helpers.
	 * Primary interface (mini-job hop): try_begin_hop(), llm_opts_for_pending_hop(),
	 * finish_hop_after_tools(), maybe_finish_hop_after_resume(), abort_on_deny_or_failure();
	 * pure: should_enter_hop(), hop_brief_from_debug(), hop_summary_payload().
	 */
	class Ahentic_Subagent {

		/**
		 * Whether the main control block may start a mini-job hop (pure).
		 *
		 * Enter when mini_job is set, hop_brief is non-empty, next is not ask_user /
		 * missing_ability / reply-only without peel intent, and no tools are already
		 * planned (those stay on the Recipe / forced_tools path).
		 *
		 * @param array $debug   Parsed AHENTIC_DEBUG block.
		 * @param array $planned Normalized tools_planned (empty = none).
		 * @return bool
		 */
		public static function should_enter_hop( array $debug, array $planned = array() ) {
			if ( empty( $debug['mini_job'] ) ) {
				return false;
			}
			$next = isset( $debug['next'] ) ? (string) $debug['next'] : '';
			if ( in_array( $next, array( 'ask_user', 'missing_ability', 'reply' ), true ) ) {
				return false;
			}
			if ( '' === self::hop_brief_from_debug( $debug ) ) {
				return false;
			}
			if ( ! empty( $planned ) ) {
				return false;
			}
			$raw_tools = isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : null;
			if ( is_array( $raw_tools ) && ! empty( $raw_tools ) ) {
				return false;
			}
			return true;
		}

		/**
		 * Main-packed hop brief from the control block (pure).
		 *
		 * @param array $debug Parsed debug block.
		 * @return string
		 */
		public static function hop_brief_from_debug( array $debug ) {
			if ( empty( $debug['hop_brief'] ) || ! is_string( $debug['hop_brief'] ) ) {
				return '';
			}
			return trim( $debug['hop_brief'] );
		}

		/**
		 * Compact hop result for the next main think (pure).
		 *
		 * @param bool   $ok      Whether tools succeeded.
		 * @param string $summary Short human/LLM summary.
		 * @param array  $steps   Optional [{ability, ok}, …].
		 * @return array
		 */
		public static function hop_summary_payload( $ok, $summary, array $steps = array() ) {
			$clean_steps = array();
			foreach ( $steps as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$clean_steps[] = array(
					'ability' => isset( $step['ability'] ) ? (string) $step['ability'] : '',
					'ok'      => ! isset( $step['ok'] ) || ! empty( $step['ok'] ),
				);
			}
			return array(
				'ok'           => (bool) $ok,
				'mini_job_hop' => true,
				'summary'      => is_string( $summary ) ? trim( $summary ) : '',
				'steps'        => $clean_steps,
			);
		}

		/**
		 * Start a pending hop from a main control block when eligible.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $debug      Parsed debug block.
		 * @param array $planned    Normalized tools_planned.
		 * @return bool True when hop was stored (caller should enqueue, not run tools).
		 */
		public static function try_begin_hop( $session_id, array $debug, array $planned = array() ) {
			if ( ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return false;
			}
			if ( ! self::should_enter_hop( $debug, $planned ) ) {
				return false;
			}
			$brief = self::hop_brief_from_debug( $debug );
			self::set_hop(
				$session_id,
				array(
					'brief'   => $brief,
					'phase'   => 'pending_think',
					'steps'   => array(),
					'ok'      => true,
					'summary' => isset( $debug['intention'] ) ? trim( (string) $debug['intention'] ) : '',
				)
			);
			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'subagent_hop_begin',
				'Mini-job hop scheduled',
				array(
					'brief_excerpt' => class_exists( 'Ahentic_Orchestrator' )
						? Ahentic_Orchestrator::excerpt( $brief, 120 )
						: substr( $brief, 0, 120 ),
				),
				$step
			);
			Ahentic_Session_Repository::set_progress(
				$session_id,
				__( 'Running a focused mini-job…', 'ahentic' )
			);
			return true;
		}

		/**
		 * Prompt opts for a pending hop think (marks phase thinking). Empty if none.
		 *
		 * @param int $session_id Session ID.
		 * @return array{mini_job_hop?: bool, hop_brief?: string}
		 */
		public static function llm_opts_for_pending_hop( $session_id ) {
			$state = self::get_hop( $session_id );
			if ( ! is_array( $state ) ) {
				return array();
			}
			$phase = isset( $state['phase'] ) ? (string) $state['phase'] : '';
			if ( 'pending_think' !== $phase && 'thinking' !== $phase ) {
				return array();
			}
			$brief = isset( $state['brief'] ) ? (string) $state['brief'] : '';
			if ( '' === trim( $brief ) ) {
				self::abort_hop( $session_id, 'empty_brief' );
				return array();
			}
			$state['phase'] = 'thinking';
			self::set_hop( $session_id, $state );
			return array(
				'mini_job_hop' => true,
				'hop_brief'    => $brief,
			);
		}

		/**
		 * Mark hop as executing tools after a hop think planned them.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function mark_hop_running_tools( $session_id ) {
			$state = self::get_hop( $session_id );
			if ( ! is_array( $state ) ) {
				return;
			}
			$state['phase'] = 'running_tools';
			self::set_hop( $session_id, $state );
		}

		/**
		 * Whether a hop is active (pending think through tools).
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		public static function has_active_hop( $session_id ) {
			return is_array( self::get_hop( $session_id ) );
		}

		/**
		 * After hop tools (or hop reply with no tools): write short summary, clear hop.
		 *
		 * @param int    $session_id Session ID.
		 * @param bool   $any_failed Tool failure in this step.
		 * @param string $reply_text Optional hop reply text for summary.
		 * @return bool True when a hop was finalized.
		 */
		public static function finish_hop_after_tools( $session_id, $any_failed = false, $reply_text = '' ) {
			$state = self::get_hop( $session_id );
			if ( ! is_array( $state ) || ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return false;
			}

			$steps = array();
			if ( ! empty( $state['steps'] ) && is_array( $state['steps'] ) ) {
				$steps = $state['steps'];
			} else {
				$recipe = self::get_recipe( $session_id );
				if ( is_array( $recipe ) && ! empty( $recipe['steps'] ) && is_array( $recipe['steps'] ) ) {
					foreach ( $recipe['steps'] as $step ) {
						if ( ! is_array( $step ) ) {
							continue;
						}
						$steps[] = array(
							'ability' => isset( $step['ability'] ) ? (string) $step['ability'] : '',
							'ok'      => ! isset( $step['ok'] ) || ! empty( $step['ok'] ),
						);
					}
				}
			}

			$ok = ! $any_failed;
			if ( $ok && isset( $state['ok'] ) && empty( $state['ok'] ) ) {
				$ok = false;
			}
			foreach ( $steps as $step ) {
				if ( empty( $step['ok'] ) ) {
					$ok = false;
					break;
				}
			}

			$summary = isset( $state['summary'] ) ? trim( (string) $state['summary'] ) : '';
			if ( '' === $summary && is_string( $reply_text ) && '' !== trim( $reply_text ) ) {
				$summary = trim( $reply_text );
			}
			if ( '' === $summary ) {
				$summary = $ok
					? __( 'Mini-job hop finished.', 'ahentic' )
					: __( 'Mini-job hop ended with errors.', 'ahentic' );
			}

			$last_ability = '';
			foreach ( $steps as $step ) {
				if ( ! empty( $step['ability'] ) ) {
					$last_ability = (string) $step['ability'];
				}
			}

			$payload = self::hop_summary_payload( $ok, $summary, $steps );
			$content = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'tool',
					'content' => is_string( $content ) ? $content : '{}',
					'meta'    => array(
						'ability'           => $last_ability,
						'ok'                => $ok,
						'mini_job_hop'      => true,
						'subagent_complete' => true,
					),
				)
			);
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'subagent_hop_complete',
				'Mini-job hop complete',
				array(
					'ok'    => $ok,
					'steps' => count( $steps ),
				),
				(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
			);
			self::clear_recipe( $session_id );
			self::clear_hop( $session_id );
			return true;
		}

		/**
		 * Abort hop on deny / failure / veto mid-flight.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $reason     Trace reason.
		 */
		public static function abort_hop( $session_id, $reason = 'abort' ) {
			if ( ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return;
			}
			$state = self::get_hop( $session_id );
			if ( ! is_array( $state ) ) {
				return;
			}
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'subagent_hop_abort',
				'Mini-job hop aborted',
				array( 'reason' => (string) $reason ),
				(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
			);
			self::clear_hop( $session_id );
		}

		/**
		 * @param int $session_id Session ID.
		 * @return array|null
		 */
		public static function get_hop( $session_id ) {
			if ( ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return null;
			}
			return Ahentic_Session_Repository::get_subagent_hop( $session_id );
		}

		/**
		 * @param int   $session_id Session ID.
		 * @param array $state      Hop state.
		 */
		public static function set_hop( $session_id, array $state ) {
			if ( class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::set_subagent_hop( $session_id, $state );
			}
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_hop( $session_id ) {
			if ( class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::clear_subagent_hop( $session_id );
			}
		}

		/**
		 * Keep the rest of a planned batch alive across HITL or browser pause (forced_tools).
		 *
		 * @param int    $session_id Session ID.
		 * @param array  $planned    Full planned batch.
		 * @param int    $index      Index of the call that paused.
		 * @param int    $step       Step for trace.
		 * @param string $reason     Trace reason (hitl|browser).
		 */
		public static function preserve_batch_remainder( $session_id, array $planned, $index, $step, $reason = 'hitl' ) {
			$remaining = array_slice( $planned, (int) $index + 1 );
			if ( empty( $remaining ) || ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return;
			}
			$names = array();
			foreach ( $remaining as $call ) {
				$names[] = isset( $call['name'] ) ? (string) $call['name'] : '';
			}
			// Resolve purpose BEFORE ensure_chain — otherwise a fresh multi-tool pause always
			// looks like an active recipe and never gets purpose=batch.
			// Explicit apply meta only (Finish Gate). Empty must not become apply
			// (get_forced_tools_purpose() defaults unset → apply).
			$explicit = Ahentic_Session_Repository::get_forced_tools_purpose_raw( $session_id );
			$purpose  = self::resolve_remainder_purpose( $explicit, (bool) self::get_recipe( $session_id ) );
			self::ensure_chain( $session_id, $planned );
			Ahentic_Session_Repository::set_forced_tools( $session_id, $remaining, $purpose );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'subagent_batch_queued',
				'Queued remaining tools after ' . $reason . ' pause (Subagent)',
				array(
					'tools'   => $names,
					'reason'  => $reason,
					'purpose' => $purpose,
				),
				$step
			);
		}

		/**
		 * Purpose for a preserved remainder queue.
		 *
		 * Only an explicit "apply" meta (Finish Gate forced apply) stays apply.
		 * Empty / unknown → batch, or recipe when a Subagent chain is active.
		 *
		 * @param string $explicit_purpose_meta Raw META_FORCED_TOOLS_PURPOSE (may be empty).
		 * @param bool   $has_recipe            Active Subagent recipe/chain.
		 * @return string apply|batch|recipe
		 */
		public static function resolve_remainder_purpose( $explicit_purpose_meta, $has_recipe ) {
			$explicit = (string) $explicit_purpose_meta;
			if ( 'apply' === $explicit ) {
				return 'apply';
			}
			if ( 'batch' === $explicit ) {
				return 'batch';
			}
			if ( 'recipe' === $explicit ) {
				return 'recipe';
			}
			return $has_recipe ? 'recipe' : 'batch';
		}

		/**
		 * After a successful ability: record chain step; maybe finalize when idle.
		 *
		 * Does not invent follow-up abilities — only tracks / finishes a chain of
		 * tools that were already planned or forced.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $ability    Ability that just succeeded.
		 * @param array  $payload    Tool result payload.
		 * @param array  $planned    Tools planned in this step.
		 * @param int    $call_index Index of the completed call.
		 * @return bool True when a recipe was finalized.
		 */
		public static function after_tool_success( $session_id, $ability, array $payload, array $planned, $call_index ) {
			if ( ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return false;
			}

			$ability       = (string) $ability;
			$more_in_batch = isset( $planned[ (int) $call_index + 1 ] );
			$forced        = Ahentic_Session_Repository::get_forced_tools( $session_id );

			if ( count( $planned ) > 1 || self::get_recipe( $session_id ) || ! empty( $forced ) || self::has_active_hop( $session_id ) ) {
				self::ensure_chain( $session_id, $planned );
				self::record_recipe_step( $session_id, $ability, $payload );
			}

			if ( $more_in_batch || ! empty( $forced ) ) {
				return false;
			}

			// Hop owns the return summary — do not emit a separate Recipe aggregate.
			if ( self::has_active_hop( $session_id ) ) {
				return false;
			}

			$state = self::get_recipe( $session_id );
			if ( is_array( $state ) ) {
				return self::finalize_recipe( $session_id );
			}
			return false;
		}

		/**
		 * Start generic chain state if missing (no branded recipe ids).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $planned    Optional planned batch for tracing.
		 */
		public static function ensure_chain( $session_id, array $planned = array() ) {
			$state = self::get_recipe( $session_id );
			if ( is_array( $state ) ) {
				return;
			}
			$names = array();
			foreach ( $planned as $call ) {
				if ( is_array( $call ) && ! empty( $call['name'] ) ) {
					$names[] = (string) $call['name'];
				}
			}
			self::set_recipe(
				$session_id,
				array(
					'steps'   => array(),
					'planned' => $names,
				)
			);
		}

		/**
		 * Resolve placeholders from earlier chain step payloads before HITL/browser/execute.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability name.
		 * @param array  $input      Tool input.
		 * @return array Resolved input.
		 */
		public static function bind_recipe_input( $session_id, $name, array $input ) {
			unset( $name );
			$state = self::get_recipe( $session_id );
			if ( ! is_array( $state ) ) {
				if ( ! empty( $input['_recipe_bind'] ) ) {
					unset( $input['_recipe_bind'] );
				}
				return $input;
			}
			return self::bind_recipe_input_from_state( $state, $input );
		}

		/**
		 * Pure binder (test seam).
		 *
		 * Fills attachment_id / image block attrs from the latest chain step that
		 * produced an attachment_id when:
		 * - input requests `_recipe_bind=attachment_from_prior_upload`, or
		 * - attachment_id is present and is 0 (placeholder) while a prior upload exists, or
		 * - model used `from_upload` as a memory-key stand-in (not a schema field — rewritten
		 *   to attachment_id before browser/HITL).
		 *
		 * @param array $state Recipe/chain state with steps[].
		 * @param array $input Tool input.
		 * @return array
		 */
		public static function bind_recipe_input_from_state( array $state, array $input ) {
			$explicit = ! empty( $input['_recipe_bind'] ) && 'attachment_from_prior_upload' === (string) $input['_recipe_bind'];
			if ( ! empty( $input['_recipe_bind'] ) ) {
				unset( $input['_recipe_bind'] );
			}

			$from_upload = '';
			if ( ! empty( $input['from_upload'] ) ) {
				$from_upload = trim( (string) $input['from_upload'] );
				unset( $input['from_upload'] );
			}

			$attachment_id = 0;
			$url           = '';
			$alt           = isset( $state['alt'] ) ? (string) $state['alt'] : '';
			if ( ! empty( $state['steps'] ) && is_array( $state['steps'] ) ) {
				foreach ( array_reverse( $state['steps'] ) as $step ) {
					if ( empty( $step['payload'] ) || ! is_array( $step['payload'] ) ) {
						continue;
					}
					$p = $step['payload'];
					if ( ! empty( $p['attachment_id'] ) ) {
						$attachment_id = (int) $p['attachment_id'];
						$url           = isset( $p['url'] ) ? (string) $p['url'] : '';
						if ( '' === $alt && ! empty( $p['alt_text'] ) ) {
							$alt = (string) $p['alt_text'];
						}
						break;
					}
				}
			}
			if ( $attachment_id <= 0 ) {
				if ( '' !== $from_upload ) {
					$input['from_upload'] = $from_upload;
				}
				return $input;
			}

			$needs_fill = $explicit
				|| '' !== $from_upload
				|| ( array_key_exists( 'attachment_id', $input ) && 0 === (int) $input['attachment_id'] )
				|| (
					isset( $input['blocks'][0]['attributes'] ) && is_array( $input['blocks'][0]['attributes'] )
					&& array_key_exists( 'id', $input['blocks'][0]['attributes'] )
					&& 0 === (int) $input['blocks'][0]['attributes']['id']
				);

			if ( ! $needs_fill ) {
				return $input;
			}

			// Always set attachment_id when filling (including when only from_upload was present).
			$input['attachment_id'] = $attachment_id;
			if ( isset( $input['blocks'][0]['attributes'] ) && is_array( $input['blocks'][0]['attributes'] ) ) {
				$input['blocks'][0]['attributes']['id']  = $attachment_id;
				$input['blocks'][0]['attributes']['url'] = $url;
				if ( '' !== $alt ) {
					$input['blocks'][0]['attributes']['alt'] = $alt;
				}
			}
			return $input;
		}

		/**
		 * @param int    $session_id Session ID.
		 * @param string $ability    Ability.
		 * @param array  $payload    Result.
		 */
		public static function record_recipe_step( $session_id, $ability, array $payload ) {
			$state = self::get_recipe( $session_id );
			if ( ! is_array( $state ) ) {
				return;
			}
			if ( ! isset( $state['steps'] ) || ! is_array( $state['steps'] ) ) {
				$state['steps'] = array();
			}
			$state['steps'][] = array(
				'ability' => (string) $ability,
				'ok'      => ! isset( $payload['ok'] ) || ! empty( $payload['ok'] ),
				'payload' => self::compact_payload( $payload ),
			);
			self::set_recipe( $session_id, $state );
		}

		/**
		 * When forced queue is empty and a chain is active, write aggregated tool result.
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		public static function finalize_recipe_if_idle( $session_id ) {
			if ( ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return false;
			}
			if ( self::has_active_hop( $session_id ) ) {
				return false;
			}
			if ( ! empty( Ahentic_Session_Repository::get_forced_tools( $session_id ) ) ) {
				return false;
			}
			$state = self::get_recipe( $session_id );
			if ( ! is_array( $state ) ) {
				return false;
			}
			return self::finalize_recipe( $session_id );
		}

		/**
		 * After HITL/browser resume: finish hop when the forced queue is empty.
		 *
		 * @param int  $session_id Session ID.
		 * @param bool $any_failed Whether the resumed tool failed.
		 * @return bool True when hop was finalized.
		 */
		public static function maybe_finish_hop_after_resume( $session_id, $any_failed = false ) {
			if ( ! self::has_active_hop( $session_id ) ) {
				return false;
			}
			if ( ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return false;
			}
			if ( ! empty( Ahentic_Session_Repository::get_forced_tools( $session_id ) ) ) {
				return false;
			}
			return self::finish_hop_after_tools( $session_id, (bool) $any_failed, '' );
		}

		/**
		 * Deny / hard failure: clear Recipe queue and close hop with a failure summary.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $reason     Trace / summary reason.
		 */
		public static function abort_on_deny_or_failure( $session_id, $reason = 'abort' ) {
			self::abort_recipe( $session_id );
			if ( self::has_active_hop( $session_id ) ) {
				$state = self::get_hop( $session_id );
				if ( is_array( $state ) ) {
					$state['summary'] = '' !== (string) $reason
						? sprintf(
							/* translators: %s: short abort reason */
							__( 'Mini-job hop stopped (%s).', 'ahentic' ),
							(string) $reason
						)
						: __( 'Mini-job hop stopped.', 'ahentic' );
					self::set_hop( $session_id, $state );
				}
				self::finish_hop_after_tools( $session_id, true, '' );
			}
		}

		/**
		 * Append aggregated chain result and clear state.
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		public static function finalize_recipe( $session_id ) {
			$state = self::get_recipe( $session_id );
			if ( ! is_array( $state ) ) {
				return false;
			}
			$steps = isset( $state['steps'] ) && is_array( $state['steps'] ) ? $state['steps'] : array();
			if ( empty( $steps ) ) {
				self::clear_recipe( $session_id );
				return false;
			}
			$ok      = true;
			$summary = array(
				'ok'            => true,
				'subagent'      => true,
				'steps'         => array(),
				'attachment_id' => 0,
				'url'           => '',
			);
			$last_ability = '';
			foreach ( $steps as $step ) {
				$step_ok = ! empty( $step['ok'] );
				if ( ! $step_ok ) {
					$ok = false;
				}
				$ability = isset( $step['ability'] ) ? (string) $step['ability'] : '';
				if ( '' !== $ability ) {
					$last_ability = $ability;
				}
				$summary['steps'][] = array(
					'ability' => $ability,
					'ok'      => $step_ok,
				);
				if ( ! empty( $step['payload']['attachment_id'] ) ) {
					$summary['attachment_id'] = (int) $step['payload']['attachment_id'];
					$summary['url']           = isset( $step['payload']['url'] ) ? (string) $step['payload']['url'] : '';
				}
			}
			$summary['ok'] = $ok;

			$content = wp_json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			Ahentic_Session_Repository::append_entry(
				$session_id,
				array(
					'role'    => 'tool',
					'content' => is_string( $content ) ? $content : '{}',
					'meta'    => array(
						'ability'           => $last_ability,
						'ok'                => $ok,
						'subagent_complete' => true,
					),
				)
			);
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'subagent_complete',
				'Subagent chain complete',
				array(
					'ok'    => $ok,
					'steps' => count( $steps ),
				),
				(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
			);
			self::clear_recipe( $session_id );
			return true;
		}

		/**
		 * Clear chain on HITL deny / failure so we do not continue blindly.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function abort_recipe( $session_id ) {
			self::clear_recipe( $session_id );
			if ( class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::clear_forced_tools( $session_id );
			}
		}

		/**
		 * @param array $payload Tool payload.
		 * @return array
		 */
		public static function compact_payload( array $payload ) {
			$keep = array( 'ok', 'artifact_key', 'attachment_id', 'url', 'alt_text', 'width', 'height', 'mime_type', 'post_id', 'featured_media', 'cleared' );
			$out  = array();
			foreach ( $keep as $key ) {
				if ( array_key_exists( $key, $payload ) ) {
					$out[ $key ] = $payload[ $key ];
				}
			}
			return $out;
		}

		/**
		 * @param int $session_id Session ID.
		 * @return array|null
		 */
		public static function get_recipe( $session_id ) {
			if ( ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return null;
			}
			return Ahentic_Session_Repository::get_subagent_recipe( $session_id );
		}

		/**
		 * @param int   $session_id Session ID.
		 * @param array $state      Chain state.
		 */
		public static function set_recipe( $session_id, array $state ) {
			if ( class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::set_subagent_recipe( $session_id, $state );
			}
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_recipe( $session_id ) {
			if ( class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::clear_subagent_recipe( $session_id );
			}
		}
	}
}
