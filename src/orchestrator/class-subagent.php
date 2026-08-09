<?php
/**
 * Subagent: cheap mode over existing abilities (forced_tools Recipe).
 *
 * Deepens forced_tools — does not invent abilities or domain-specific chains.
 * Advances tools the model (or a prior batch) already chose; binds placeholders
 * from earlier step payloads; preserves remainders across HITL/browser pause.
 *
 * @package Ahentic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Subagent' ) ) {
	/**
	 * Deep module: skip full main thinks between already-planned ability steps.
	 *
	 * Primary interface: after_tool_success(), preserve_batch_remainder(),
	 * bind_recipe_input(), finalize_recipe_if_idle(), recipe_state helpers.
	 */
	class Ahentic_Subagent {

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
			self::ensure_chain( $session_id, $planned );
			$names = array();
			foreach ( $remaining as $call ) {
				$names[] = isset( $call['name'] ) ? (string) $call['name'] : '';
			}
			// Preserve Finish Gate apply purpose; otherwise batch/recipe for model queues.
			$purpose = Ahentic_Session_Repository::get_forced_tools_purpose( $session_id );
			if ( Ahentic_Session_Repository::FORCED_PURPOSE_APPLY !== $purpose ) {
				$purpose = self::get_recipe( $session_id )
					? Ahentic_Session_Repository::FORCED_PURPOSE_RECIPE
					: Ahentic_Session_Repository::FORCED_PURPOSE_BATCH;
			}
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

			if ( count( $planned ) > 1 || self::get_recipe( $session_id ) || ! empty( $forced ) ) {
				self::ensure_chain( $session_id, $planned );
				self::record_recipe_step( $session_id, $ability, $payload );
			}

			if ( $more_in_batch || ! empty( $forced ) ) {
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
