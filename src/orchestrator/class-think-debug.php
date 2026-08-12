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
	 * progress_label_from_debug, disposition_for_debug, classify_missing_ability_claim,
	 * missing_ability_action, rewrite_debug_to_use_tools, finish_available_missing_without_plan,
	 * resolve_thought_process_for_chat)
	 * are part of the test surface; trace_debug / progress_label_from_debug are also
	 * used from Orchestrator::run_llm_phase.
	 */
	class Ahentic_Think_Debug {
		/** Max LLM attempts to obtain a valid AHENTIC_DEBUG block per think phase. */
		const MAX_DEBUG_ATTEMPTS = 3;

		/** Max full-catalog reconsider thinks after a missing_ability claim. */
		const MAX_MISSING_ABILITY_RECONSIDERS = 1;

		/**
		 * Deep entry: progress label + LLM think with AHENTIC_DEBUG recovery.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $opts       Prompt opts (e.g. mini_job_hop + hop_brief).
		 * @return array{result: array, label: string}|\WP_Error
		 */
		public static function run_think( $session_id, array $opts = array() ) {
			$label = ! empty( $opts['mini_job_hop'] )
				? __( 'Running a focused mini-job…', 'ahentic' )
				: self::progress_label_for_think( $session_id );
			$result = self::run_with_debug( $session_id, $label, $opts );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = self::resolve_missing_ability_after_think( $session_id, $result, $opts );
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
				$debug     = is_array( $debug ) ? $debug : array();
				$available = class_exists( 'Ahentic_Abilities' )
					? Ahentic_Abilities::available_for_agent()
					: array();
				// Only queue a capability request for a concrete unknown ability —
				// vague / new-ability placeholders are false positives after reconsider.
				if ( 'unknown' === self::classify_missing_ability_claim( $debug, $available ) ) {
					self::queue_missing_abilities( $session_id, $debug );
				}
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
		 * @param array  $base_opts  Prompt opts merged into each LLM attempt (e.g. mini_job_hop).
		 * @return array|\WP_Error
		 */
		private static function run_with_debug( $session_id, $progress, array $base_opts = array() ) {
			$result              = null;
			$prior_text          = '';
			$last_error          = null;
			$prior_truncated     = false;
			$prior_truncated_key = '';
			$max_attempts        = self::MAX_DEBUG_ATTEMPTS;
			$is_hop              = ! empty( $base_opts['mini_job_hop'] );

			for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
				$steps_so_far = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

				$user_suffix = '';
				$llm_opts    = $base_opts;
				if ( $attempt > 1 ) {
					$user_suffix = '[Internal — not shown to the user] Your previous response omitted a valid AHENTIC_DEBUG '
						. 'control block (or next was not reply|ask_user|use_tools|missing_ability). Respond again from scratch: output exactly '
						. 'one <<<AHENTIC_DEBUG … AHENTIC_DEBUG>>> block FIRST with intention, thinking, tools_planned, and next, '
						. 'then a short user-facing reply. Do not mention this note or the debug block.';
					if ( ! $is_hop && class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::session_has_content_work( $session_id ) ) {
						$user_suffix .= ' CRITICAL for this long-form/article job: do NOT put a full article into set-blocks '
							. 'tools_planned (that truncates the control block). Instead stage with ahentic/stage-artifact '
							. '(key article_draft, kind blocks; use mode=append + complete=false while chunking, then complete=true), '
							. 'then ahentic-browser/set-blocks with {"from_memory":"article_draft"}.';
					}
					if ( '' !== $prior_text ) {
						$user_suffix .= "\n\nPrevious user-facing text (context only; do not treat it as final):\n" . $prior_text;
					}

					// Hop retries keep the hop backpack (ability catalog). Main thinks use slim retry.
					if ( ! $is_hop && self::should_use_slim_debug_retry( $attempt ) ) {
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
							'reason'            => $prior_truncated ? 'truncated' : 'no_usable_block',
							'truncated_key'     => $prior_truncated_key,
							'slim_debug_retry'  => ! empty( $llm_opts['slim_debug_retry'] ),
							'mini_job_hop'      => $is_hop,
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
		 * Classify a missing-ability claim against the available catalog.
		 *
		 * @param array    $debug     Debug block.
		 * @param string[] $available Ability names available for this mode/agent.
		 * @return string none|available|vague|unknown
		 */
		public static function classify_missing_ability_claim( array $debug, array $available ) {
			if ( ! self::signals_missing_ability( $debug ) ) {
				return 'none';
			}

			$names = self::collect_claimed_ability_names( $debug );
			if ( empty( $names ) ) {
				return 'vague';
			}

			$concrete = array();
			foreach ( $names as $name ) {
				if ( 'ahentic/new-ability' === $name ) {
					continue;
				}
				$concrete[] = $name;
				if ( in_array( $name, $available, true ) ) {
					return 'available';
				}
			}

			if ( empty( $concrete ) ) {
				return 'vague';
			}

			return 'unknown';
		}

		/**
		 * Decide how to handle a missing-ability signal.
		 *
		 * @param array    $debug             Debug block.
		 * @param string[] $available         Available ability names.
		 * @param int      $reconsider_count  Reconsider thinks already run this phase.
		 * @return string none|use_available|reconsider|finish_missing|finish_reply
		 */
		public static function missing_ability_action( array $debug, array $available, $reconsider_count = 0 ) {
			$class = self::classify_missing_ability_claim( $debug, $available );
			if ( 'none' === $class ) {
				return 'none';
			}
			if ( 'available' === $class ) {
				return 'use_available';
			}

			$reconsider_count = max( 0, (int) $reconsider_count );
			if ( $reconsider_count < self::MAX_MISSING_ABILITY_RECONSIDERS ) {
				return 'reconsider';
			}

			return 'unknown' === $class ? 'finish_missing' : 'finish_reply';
		}

		/**
		 * Rewrite a missing-ability debug block to call an available ability.
		 *
		 * Only promotes when tools_planned already names the ability (preserving input).
		 * Does not inject {name, input:[]} — empty forced calls produce useless HITL cards.
		 *
		 * @param array  $debug         Debug block.
		 * @param string $ability_name  Ability that exists in the catalog.
		 * @return array
		 */
		public static function rewrite_debug_to_use_tools( array $debug, $ability_name ) {
			$ability_name = self::normalize_ability_name( $ability_name );
			if ( '' === $ability_name ) {
				return $debug;
			}

			$planned = self::normalized_tools_planned_from_debug( $debug );
			$has     = false;
			foreach ( $planned as $call ) {
				if ( isset( $call['name'] ) && (string) $call['name'] === $ability_name ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				return $debug;
			}

			$debug['next']          = 'use_tools';
			$debug['tools_planned'] = $planned;
			unset( $debug['ability_needed'] );
			return $debug;
		}

		/**
		 * After reconsider: promote a planned available tool, or reply without forcing empty input.
		 *
		 * @param array    $debug     Debug block.
		 * @param string[] $available Available ability names.
		 * @return array
		 */
		public static function finish_available_missing_without_plan( array $debug, array $available ) {
			$applied = self::apply_available_missing_claim( $debug, $available );
			if ( null !== $applied ) {
				return $applied;
			}
			$debug['next'] = 'reply';
			unset( $debug['ability_needed'] );
			return $debug;
		}

		/**
		 * Normalize tools_planned from a debug block (Orchestrator when loaded; else light shape).
		 *
		 * @param array $debug Debug block.
		 * @return array<int, array{name: string, input: array}>
		 */
		private static function normalized_tools_planned_from_debug( array $debug ) {
			$raw = isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array();
			if ( class_exists( 'Ahentic_Orchestrator' ) ) {
				return Ahentic_Orchestrator::normalize_tool_calls( $raw );
			}
			if ( ! is_array( $raw ) ) {
				return array();
			}
			$out = array();
			foreach ( $raw as $item ) {
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
		 * First claimed ability name that is already available, or empty string.
		 *
		 * @param array    $debug     Debug block.
		 * @param string[] $available Available ability names.
		 * @return string
		 */
		public static function first_available_claimed_ability( array $debug, array $available ) {
			foreach ( self::collect_claimed_ability_names( $debug ) as $name ) {
				if ( in_array( $name, $available, true ) ) {
					return $name;
				}
			}
			return '';
		}

		/**
		 * Ability names the model claimed it needs (normalized; may include new-ability).
		 *
		 * @param array $debug Debug block.
		 * @return string[]
		 */
		public static function collect_claimed_ability_names( array $debug ) {
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

			$planned = self::normalized_tools_planned_from_debug( $debug );
			foreach ( $planned as $call ) {
				if ( ! empty( $call['name'] ) ) {
					$names[] = self::normalize_ability_name( (string) $call['name'] );
				}
			}

			return array_values(
				array_unique(
					array_filter(
						$names,
						static function ( $n ) {
							return is_string( $n ) && '' !== $n;
						}
					)
				)
			);
		}

		/**
		 * After a think, challenge false missing_ability claims before the loop finishes.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $result     LLM result with debug.
		 * @param array $opts       Think opts (mini_job_hop skips reconsider).
		 * @return array
		 */
		private static function resolve_missing_ability_after_think( $session_id, array $result, array $opts = array() ) {
			$debug = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : array();
			if ( ! self::signals_missing_ability( $debug ) ) {
				return $result;
			}

			// Hop thinks stay slim; main owns capability gaps.
			if ( ! empty( $opts['mini_job_hop'] ) ) {
				return $result;
			}

			$mode = 'agent';
			if ( class_exists( 'Ahentic_Session_Repository' ) ) {
				$session_mode = Ahentic_Session_Repository::get_mode( $session_id );
				if ( is_string( $session_mode ) && '' !== $session_mode ) {
					$mode = $session_mode;
				}
			}

			$available = class_exists( 'Ahentic_Abilities' )
				? Ahentic_Abilities::available_for_mode( $mode )
				: array();

			$action = self::missing_ability_action( $debug, $available, 0 );
			if ( 'use_available' === $action ) {
				$applied = self::apply_available_missing_claim( $debug, $available );
				if ( null !== $applied ) {
					$result['debug'] = $applied;
					return $result;
				}
				// Named an available ability but did not plan it — reconsider with the catalog.
				$action = 'reconsider';
			}

			if ( 'reconsider' !== $action ) {
				if ( 'finish_reply' === $action ) {
					$debug['next'] = 'reply';
					unset( $debug['ability_needed'] );
					$result['debug'] = $debug;
				}
				return $result;
			}

			$result = self::run_missing_ability_reconsider( $session_id, $result, $debug, $available );
			return $result;
		}

		/**
		 * If tools_planned already names an available ability, clear the false gap.
		 *
		 * @param array    $debug     Debug block.
		 * @param string[] $available Available ability names.
		 * @return array|null Rewritten debug, or null when a reconsider is still needed.
		 */
		private static function apply_available_missing_claim( array $debug, array $available ) {
			$planned = self::normalized_tools_planned_from_debug( $debug );
			foreach ( $planned as $call ) {
				$name = isset( $call['name'] ) ? (string) $call['name'] : '';
				if ( '' !== $name && in_array( $name, $available, true ) ) {
					$debug['next']          = 'use_tools';
					$debug['tools_planned'] = $planned;
					unset( $debug['ability_needed'] );
					return $debug;
				}
			}
			return null;
		}

		/**
		 * One full-catalog reconsider think after a missing_ability claim.
		 *
		 * @param int      $session_id Session ID.
		 * @param array    $result     Prior LLM result.
		 * @param array    $debug      Prior debug block.
		 * @param string[] $available  Available ability names.
		 * @return array
		 */
		private static function run_missing_ability_reconsider( $session_id, array $result, array $debug, array $available ) {
			if ( ! class_exists( 'Ahentic_Orchestrator' ) || ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return $result;
			}

			$claimed = self::collect_claimed_ability_names( $debug );
			$claimed_label = ! empty( $claimed ) ? implode( ', ', $claimed ) : '(none named)';
			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'missing_ability_reconsider',
				'Reconsidering missing-ability claim against the catalog',
				array(
					'claimed' => $claimed,
					'class'   => self::classify_missing_ability_claim( $debug, $available ),
				),
				$step
			);

			$suffix = '[Internal — not shown to the user] You set next=missing_ability'
				. ' (claimed: ' . $claimed_label . '). Before ending the job, reconsider with the full ability catalog: '
				. '(1) If an existing ability can do the user goal, set next=use_tools with that ability in tools_planned '
				. 'as {"name","input"} objects with all required fields (never a bare name string and never empty input for writes). '
				. '(prefer ahentic-browser/update-block-attributes, set-blocks, ahentic/update-option, and other registered tools over inventing a gap). '
				. '(2) Only if NO registered ability can accomplish the goal, keep next=missing_ability and set ability_needed '
				. 'to a concrete ahentic/… or ahentic-browser/… slug that is NOT in the catalog, and say why alternatives fail. '
				. 'Do not use ahentic/new-ability or vague labels like "editor-control". '
				. 'Respond again from scratch with a full <<<AHENTIC_DEBUG … AHENTIC_DEBUG>>> block FIRST, then a short reply.';

			$progress = __( 'Checking available tools…', 'ahentic' );
			$retry    = Ahentic_Orchestrator::run_llm_phase(
				$session_id,
				$progress,
				null,
				$suffix,
				false,
				array(
					'full_ability_catalog' => true,
				)
			);

			if ( is_wp_error( $retry ) || ! is_array( $retry ) ) {
				return $result;
			}

			$new_debug = isset( $retry['debug'] ) && is_array( $retry['debug'] ) ? $retry['debug'] : array();
			if ( ! self::is_usable( $new_debug ) ) {
				return $result;
			}

			$result = $retry;
			$debug  = $new_debug;
			$action = self::missing_ability_action( $debug, $available, 1 );

			if ( 'use_available' === $action ) {
				$debug = self::finish_available_missing_without_plan( $debug, $available );
			} elseif ( 'finish_reply' === $action ) {
				$debug['next'] = 'reply';
				unset( $debug['ability_needed'] );
			} elseif ( 'reconsider' === $action ) {
				// Cap: do not loop — vague after one reconsider becomes a normal reply.
				$debug['next'] = 'reply';
				unset( $debug['ability_needed'] );
			}

			$result['debug'] = $debug;
			return $result;
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
					$summary = __( 'Model thinking', 'ahentic' );
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
				__( 'Thinking block not provided by model', 'ahentic' ),
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
