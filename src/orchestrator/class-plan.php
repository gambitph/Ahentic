<?php
/**
 * Session plan checklist lifecycle (control-block plan → sidebar card).
 *
 * Deep module: may this Session show / advance a plan card?
 * Primary interface: sync_after_think(), ensure_after_think(), advance_after_tool(),
 * complete_on_finish(), cancel_on_stop(), reopen_cancelled_steps().
 * The Orchestrator must call these — do not reimplement plan FSM at call sites.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Plan' ) ) {
	/**
	 * Deep module: session plan checklist lifecycle.
	 *
	 * Primary interface: sync_after_think(), ensure_after_think(), advance_after_tool(),
	 * complete_on_finish(), cancel_on_stop(), reopen_cancelled_steps().
	 * Pure helpers (normalize_from_debug, merge_with_existing, requires_for_think) are
	 * part of the test surface; normalize_from_debug is also used for llm_thinking traces.
	 */
	class Ahentic_Plan {
		/**
		 * Minimum plan steps for a new plan card when the run needs a plan
		 * (≥2 tools or any write). Single-step write plans are allowed.
		 */
		const MIN_PLAN_STEPS = 1;
		/** Cap plan length so it cannot outgrow a single run. */
		const MAX_PLAN_STEPS = 12;

		/**
		 * After an LLM think: persist plan from the control block; report if a plan retry is required.
		 *
		 * @param int    $session_id Session ID.
		 * @param array  $debug      Parsed debug block.
		 * @param string $mode       agent|ask.
		 * @param array  $planned    Normalized tools_planned.
		 * @return bool True when Agent work needs a plan and none is persisted yet.
		 */
		public static function sync_after_think( $session_id, $debug, $mode, array $planned ) {
			self::apply_from_debug( $session_id, $debug );
			return self::requires_for_think( $mode, $planned )
				&& ! Ahentic_Session_Repository::get_plan( $session_id );
		}

		/**
		 * After a plan-retry think (or when synthesizing): apply debug plan, else invent a minimal one.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $debug      Parsed debug block.
		 * @param array $planned    Normalized tools_planned.
		 */
		public static function ensure_after_think( $session_id, $debug, array $planned ) {
			self::apply_from_debug( $session_id, $debug );
			if ( ! Ahentic_Session_Repository::get_plan( $session_id ) ) {
				self::ensure_synthetic( $session_id, $debug, $planned );
			}
		}

		/**
		 * Persist multi-step plan from the control block (orchestrator state, not a tool).
		 *
		 * Plans are orchestrator state (not abilities). A new plan is shown when
		 * it has at least one step; later thinks may update statuses.
		 * Completed steps from a prior plan are preserved if the model omits them.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $debug      Parsed debug block.
		 */
		private static function apply_from_debug( $session_id, $debug ) {
			if ( ! is_array( $debug ) || ! array_key_exists( 'plan', $debug ) ) {
				return;
			}

			// Explicit null / empty clears only when a plan was already visible.
			if ( null === $debug['plan'] || false === $debug['plan'] || '' === $debug['plan'] ) {
				return;
			}

			$normalized = self::normalize_from_debug( $debug );
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

			$merged  = self::merge_with_existing( $normalized, $existing );
			$changed = Ahentic_Session_Repository::set_plan( $session_id, $merged );
			if ( ! $changed ) {
				return;
			}

			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );
			Ahentic_Session_Repository::append_trace(
				$session_id,
				'plan_updated',
				self::trace_summary( $merged ),
				array(
					'title' => isset( $merged['title'] ) ? $merged['title'] : '',
					'steps' => $merged['steps'],
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
		public static function merge_with_existing( array $incoming, $existing ) {
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
		public static function normalize_from_debug( $debug ) {
			if ( ! is_array( $debug ) || ! isset( $debug['plan'] ) ) {
				return null;
			}

			$raw = $debug['plan'];
			if ( ! is_array( $raw ) ) {
				return null;
			}

			// Accept either { title, steps: [...] } or a bare steps array.
			$title     = '';
			$steps_raw = $raw;
			if ( isset( $raw['steps'] ) && is_array( $raw['steps'] ) ) {
				$title     = isset( $raw['title'] ) ? trim( (string) $raw['title'] ) : '';
				$steps_raw = $raw['steps'];
			} elseif ( self::is_list_array( $raw ) ) {
				$steps_raw = $raw;
			} else {
				return null;
			}

			$steps       = array();
			$in_progress = 0;
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
		 * Short trace summary for a plan update.
		 *
		 * @param array $plan Normalized plan.
		 * @return string
		 */
		private static function trace_summary( array $plan ) {
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
		 * Mark remaining plan steps completed when the run idles with a final reply.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function complete_on_finish( $session_id ) {
			self::set_open_steps_status( $session_id, 'completed' );
		}

		/**
		 * Mark unfinished plan steps cancelled when the user stops the run or the run fails.
		 *
		 * Without this the card keeps a step at in_progress, so the checklist reads
		 * as still working after Stop / LLM errors / token-limit stops.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function cancel_on_stop( $session_id ) {
			self::set_open_steps_status( $session_id, 'cancelled' );
		}

		/**
		 * Re-open cancelled plan steps so Continue can finish the same checklist.
		 *
		 * Completed steps stay completed. Used by mid-failure job resume (#3).
		 *
		 * @param int $session_id Session ID.
		 */
		public static function reopen_cancelled_steps( $session_id ) {
			$plan = Ahentic_Session_Repository::get_plan( $session_id );
			if ( ! is_array( $plan ) || empty( $plan['steps'] ) || ! is_array( $plan['steps'] ) ) {
				return;
			}
			$changed = false;
			$steps   = array();
			$opened  = false;
			foreach ( $plan['steps'] as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$status = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				if ( 'cancelled' === $status ) {
					$step['status'] = $opened ? 'pending' : 'in_progress';
					$opened         = true;
					$changed        = true;
				} elseif ( 'in_progress' === $status ) {
					$opened = true;
				} elseif ( 'pending' === $status && ! $opened ) {
					$step['status'] = 'in_progress';
					$opened         = true;
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
		 * Set every non-terminal plan step to a terminal status.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $status     completed|cancelled.
		 */
		private static function set_open_steps_status( $session_id, $status ) {
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
				$current = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				if ( 'completed' !== $current && 'cancelled' !== $current ) {
					$step['status'] = $status;
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
		public static function advance_after_tool( $session_id, $name ) {
			$plan = Ahentic_Session_Repository::get_plan( $session_id );
			if ( ! is_array( $plan ) || empty( $plan['steps'] ) || ! is_array( $plan['steps'] ) ) {
				return;
			}

			$short   = self::ability_short_label( $name );
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
		 * Whether this think requires a persisted plan (Agent + ≥2 tools or any write).
		 *
		 * @param string $mode    agent|ask.
		 * @param array  $planned Normalized tools_planned (from Orchestrator::normalize_tool_calls).
		 * @return bool
		 */
		public static function requires_for_think( $mode, array $planned ) {
			if ( 'agent' !== $mode ) {
				return false;
			}
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
		 * @param array $planned    Normalized tools_planned (from Orchestrator::normalize_tool_calls).
		 */
		private static function ensure_synthetic( $session_id, $debug, array $planned = array() ) {
			if ( Ahentic_Session_Repository::get_plan( $session_id ) ) {
				return;
			}
			$intention = is_array( $debug ) && isset( $debug['intention'] ) ? trim( (string) $debug['intention'] ) : '';
			$steps     = array();
			if ( count( $planned ) > 0 ) {
				$i = 1;
				foreach ( $planned as $call ) {
					$name  = isset( $call['name'] ) ? (string) $call['name'] : '';
					$short = self::ability_short_label( $name );
					$steps[] = array(
						'id'      => (string) $i,
						'content' => $short ? sprintf(
							/* translators: %s: ability short name */
							__( 'Run %s', 'ahentic' ),
							$short
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
				self::trace_summary( $plan ),
				array(
					'title'     => $plan['title'],
					'steps'     => $plan['steps'],
					'synthetic' => true,
				),
				$step
			);
		}

		/**
		 * Human-readable ability short name for plan step matching / labels.
		 *
		 * @param string $name Ability name.
		 * @return string Lowercase words without namespace (e.g. "create post").
		 */
		private static function ability_short_label( $name ) {
			$short = strtolower( (string) preg_replace( '/^.*\//', '', (string) $name ) );
			return str_replace( '-', ' ', $short );
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
	}
}
