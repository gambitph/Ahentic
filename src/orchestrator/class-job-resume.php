<?php
/**
 * Job Resume — new goal vs resume same job vs forced-tools finish.
 *
 * Deep module: Continuable Session ritual (sticky goal / content_work / Plan reopen /
 * run-ephemera clears) and forced-apply finish policy.
 * Primary interface: begin_new_goal(), begin_resume(), should_finish_after_forced_tools(),
 * should_try_finish_after_browser_resume().
 * The Orchestrator must call these — do not re-sequence Repository clears at call sites.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Job_Resume' ) ) {
	/**
	 * Deep module: Session run-start / resume ritual + forced-finish policy.
	 *
	 * Product interface (Orchestrator): begin_new_goal(), begin_resume(),
	 * should_finish_after_forced_tools(), should_try_finish_after_browser_resume().
	 * Cue / content-work / active-goal helpers stay public for Prompt Assembler and
	 * unit tests but are not a second resume registry — prefer the begin_* ritual.
	 */
	class Ahentic_Job_Resume {

		/**
		 * Default progress label when a run starts or resumes.
		 *
		 * @return string
		 */
		public static function default_progress_label() {
			return __( 'Planning next steps…', 'ahentic' );
		}

		/**
		 * Whether a Continuable Session + resume-only cue should resume the same job.
		 *
		 * @param array  $session Session-shaped bag (needs job_resumable).
		 * @param string $content User message.
		 * @return bool
		 */
		public static function prefers_resume( array $session, $content ) {
			return ! empty( $session['job_resumable'] ) && self::message_looks_like_resume_cue( $content );
		}

		/**
		 * New-goal run-start ritual.
		 *
		 * Session bag (array): returns planned Session fields (test seam).
		 * Session id (int): persists ritual, or returns action=resume when Continuable + cue.
		 *
		 * @param array|int $session Session bag or session id.
		 * @param string    $content User message.
		 * @return array Bag plan, or array{action:string} when persisting by id.
		 */
		public static function begin_new_goal( $session, $content = '' ) {
			if ( is_array( $session ) ) {
				return self::plan_new_goal( $session, $content );
			}

			$session_id = (int) $session;
			$bag        = self::read_session_bag( $session_id );
			if ( self::prefers_resume( $bag, $content ) ) {
				return array( 'action' => 'resume' );
			}

			$planned = self::plan_new_goal( $bag, $content );
			self::persist_session_plan( $session_id, $planned );
			return array(
				'action'       => 'new_goal',
				'content_work' => ! empty( $planned['content_work'] ),
				'active_goal'  => isset( $planned['active_goal'] ) ? (string) $planned['active_goal'] : '',
			);
		}

		/**
		 * Resume-same-job ritual (Continue / composer cue after Continuable failure).
		 *
		 * Session bag (array): returns planned Session fields (test seam).
		 * Session id (int): persists ritual and reopens cancelled Plan steps.
		 *
		 * @param array|int $session Session bag or session id.
		 * @return array Bag plan, or array with content_work / active_goal when persisting by id.
		 */
		public static function begin_resume( $session ) {
			if ( is_array( $session ) ) {
				return self::plan_resume( $session );
			}

			$session_id = (int) $session;
			$planned    = self::plan_resume( self::read_session_bag( $session_id ) );
			self::persist_session_plan( $session_id, $planned );
			if ( ! empty( $planned['reopen_plan'] ) && class_exists( 'Ahentic_Plan' ) ) {
				Ahentic_Plan::reopen_cancelled_steps( $session_id );
			}
			return array(
				'content_work' => ! empty( $planned['content_work'] ),
				'active_goal'  => isset( $planned['active_goal'] ) ? (string) $planned['active_goal'] : '',
			);
		}

		/**
		 * Whether user text is only a resume cue (not a new goal).
		 *
		 * @param string $content User message.
		 * @return bool
		 */
		public static function message_looks_like_resume_cue( $content ) {
			$text = strtolower( trim( (string) $content ) );
			if ( '' === $text ) {
				return false;
			}
			// Whole-message cues (optional please / soft punctuation).
			if ( preg_match( '/^(please\s+)?(continue|keep going|go on|resume|retry|try again)[.!]?\s*$/u', $text ) ) {
				return true;
			}
			if ( preg_match( '/^(please\s+)?(finish|complete)\s+it[.!]?\s*$/u', $text ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Detect long-form / article writing intent from the user message (PRD intent gate).
		 *
		 * Lives with resume policy so sticky content_work cannot disagree with cue detection.
		 *
		 * @param string $content User message.
		 * @return bool
		 */
		public static function message_looks_like_content_work( $content ) {
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
		 * Resolve content_work for a new user message.
		 *
		 * Resume cues must not clear an existing long-form job.
		 *
		 * @param bool $message_is_content_work Message matches write/article intent.
		 * @param bool $is_resume_cue           Message is only a resume cue.
		 * @param bool $session_has_content_work Session already mid content work.
		 * @return bool
		 */
		public static function resolve_content_work_on_message( $message_is_content_work, $is_resume_cue, $session_has_content_work ) {
			if ( $message_is_content_work ) {
				return true;
			}
			if ( $is_resume_cue && $session_has_content_work ) {
				return true;
			}
			return false;
		}

		/**
		 * Pick the active user goal for pinned run context.
		 *
		 * Prefers an explicitly stored goal; otherwise the latest non-resume user line.
		 *
		 * @param array  $entries     Session entries (newest last).
		 * @param string $stored_goal Optional persisted active goal.
		 * @return string
		 */
		public static function active_goal_from_entries( array $entries, $stored_goal = '' ) {
			$stored = trim( (string) $stored_goal );
			if ( '' !== $stored ) {
				return $stored;
			}

			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				$entry = $entries[ $i ];
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$role = isset( $entry['role'] ) ? (string) $entry['role'] : '';
				if ( 'user' !== $role ) {
					continue;
				}
				$text = trim( (string) ( isset( $entry['content'] ) ? $entry['content'] : '' ) );
				if ( '' === $text ) {
					continue;
				}
				if ( self::message_looks_like_resume_cue( $text ) ) {
					continue;
				}
				return $text;
			}

			return '';
		}

		/**
		 * Whether the step loop should idle after forced apply/verify tools.
		 *
		 * Content-work apply failures must return to think instead of final_reply.
		 * Batch remainders and Subagent recipes must always return to think — they are
		 * not Finish Gate apply/verify queues.
		 *
		 * @param bool   $from_forced       This step ran Orchestrator-forced tools.
		 * @param bool   $any_tool_failed   At least one forced tool failed.
		 * @param bool   $has_content_work  Session is mid long-form content work.
		 * @param string $purpose           apply|batch|recipe (Repository FORCED_PURPOSE_*).
		 * @return bool True → finish with stashed reply; false → keep looping (or not forced).
		 */
		public static function should_finish_after_forced_tools( $from_forced, $any_tool_failed, $has_content_work, $purpose = 'apply' ) {
			if ( ! $from_forced ) {
				return false;
			}
			if ( 'apply' !== (string) $purpose ) {
				return false;
			}
			if ( $any_tool_failed && $has_content_work ) {
				return false;
			}
			return true;
		}

		/**
		 * After a browser pause resumes, whether to try finishing instead of a free LLM think.
		 *
		 * Forced browser tools pause one-at-a-time; when the last tool resumes the forced
		 * queue is empty so should_finish_after_forced_tools never runs. Light attribute
		 * patches (internal links, alt text) must not buy another think just to re-verify.
		 *
		 * @param string $ability             Completed browser ability.
		 * @param bool   $ok                  Tool succeeded.
		 * @param bool   $forced_tools_remain Forced queue still has tools.
		 * @param bool   $has_content_work    Session is mid long-form content work.
		 * @param string $forced_purpose      apply|batch|recipe (empty = not an apply finish).
		 * @return bool True → try finish/idle before enqueueing another think.
		 */
		public static function should_try_finish_after_browser_resume( $ability, $ok, $forced_tools_remain, $has_content_work, $forced_purpose = '' ) {
			if ( $forced_tools_remain || ! $ok ) {
				return false;
			}
			$ability = (string) $ability;
			$purpose = (string) $forced_purpose;

			// Finish Gate apply queue (set-blocks ± title) — even mid content_work.
			if ( 'apply' === $purpose ) {
				$set_blocks = class_exists( 'Ahentic_Abilities_Browser' )
					? Ahentic_Abilities_Browser::SET_BLOCKS
					: 'ahentic-browser/set-blocks';
				$update_doc = class_exists( 'Ahentic_Abilities_Browser' )
					? Ahentic_Abilities_Browser::UPDATE_POST_DOCUMENT
					: 'ahentic-browser/update-post-document';
				if ( $set_blocks === $ability || $update_doc === $ability ) {
					return true;
				}
			}

			if ( $has_content_work ) {
				return false;
			}
			$attr_patch = class_exists( 'Ahentic_Abilities_Browser' )
				? Ahentic_Abilities_Browser::UPDATE_BLOCK_ATTRIBUTES
				: 'ahentic-browser/update-block-attributes';
			return $attr_patch === $ability;
		}

		/**
		 * Plan new-goal Session fields from a Session-shaped bag.
		 *
		 * @param array  $session Session bag.
		 * @param string $content User message.
		 * @return array
		 */
		private static function plan_new_goal( array $session, $content ) {
			$content       = trim( (string) $content );
			$is_resume_cue = self::message_looks_like_resume_cue( $content );
			$had_content   = ! empty( $session['content_work'] );
			$content_work  = self::resolve_content_work_on_message(
				self::message_looks_like_content_work( $content ),
				$is_resume_cue,
				$had_content
			);

			$active_goal = isset( $session['active_goal'] ) ? (string) $session['active_goal'] : '';
			$set_goal    = false;
			if ( ! $is_resume_cue ) {
				$active_goal = $content;
				$set_goal    = true;
			}

			return array_merge(
				self::shared_run_start_fields(),
				array(
					'content_work'                => $content_work,
					'active_goal'                 => $active_goal,
					'set_active_goal'             => $set_goal,
					'clear_plan'                  => true,
					'reopen_plan'                 => false,
					'clear_context_summary'       => true,
					'consume_capability_requests' => true,
					'touch_heartbeat'             => false,
				)
			);
		}

		/**
		 * Plan resume-same-job Session fields from a Session-shaped bag.
		 *
		 * @param array $session Session bag.
		 * @return array
		 */
		private static function plan_resume( array $session ) {
			$content_work = ! empty( $session['content_work'] );
			$active_goal  = isset( $session['active_goal'] ) ? (string) $session['active_goal'] : '';

			return array_merge(
				self::shared_run_start_fields(),
				array(
					'content_work'                => $content_work,
					'active_goal'                 => $active_goal,
					'set_active_goal'             => false,
					'clear_plan'                  => false,
					'reopen_plan'                 => true,
					'clear_context_summary'       => false,
					'consume_capability_requests' => false,
					'touch_heartbeat'             => true,
				)
			);
		}

		/**
		 * Fields shared by new-goal and resume rituals (run ephemera clears + busy chrome).
		 *
		 * @return array
		 */
		private static function shared_run_start_fields() {
			return array(
				'job_resumable'           => false,
				'status'                  => 'running',
				'progress'                => self::default_progress_label(),
				'step_count'              => 0,
				'clear_error'             => true,
				'clear_verify'            => true,
				'clear_pending_final'     => true,
				'clear_forced_tools'      => true,
				'clear_thought'           => true,
				'clear_browser_paused_at' => true,
				'set_llm_keepalive'       => false,
			);
		}

		/**
		 * Read Continuable / content_work / goal fields for planning.
		 *
		 * @param int $session_id Session ID.
		 * @return array
		 */
		private static function read_session_bag( $session_id ) {
			$content_work = false;
			if ( class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$content_work = Ahentic_Session_Artifacts::session_has_content_work( $session_id );
			} elseif ( class_exists( 'Ahentic_Session_Repository' ) ) {
				$content_work = Ahentic_Session_Repository::get_content_work( $session_id );
			}

			$job_resumable = false;
			$active_goal   = '';
			if ( class_exists( 'Ahentic_Session_Repository' ) ) {
				$job_resumable = Ahentic_Session_Repository::get_job_resumable( $session_id );
				$active_goal   = Ahentic_Session_Repository::get_active_goal( $session_id );
			}

			return array(
				'job_resumable' => (bool) $job_resumable,
				'content_work'  => (bool) $content_work,
				'active_goal'   => (string) $active_goal,
			);
		}

		/**
		 * Persist a planned ritual onto the Session Repository.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $planned    Output of plan_new_goal / plan_resume.
		 */
		private static function persist_session_plan( $session_id, array $planned ) {
			if ( ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return;
			}

			if ( ! empty( $planned['clear_error'] ) ) {
				Ahentic_Session_Repository::clear_error( $session_id );
			}
			Ahentic_Session_Repository::set_job_resumable( $session_id, ! empty( $planned['job_resumable'] ) );

			if ( ! empty( $planned['clear_verify'] ) ) {
				Ahentic_Session_Repository::clear_verify_pending( $session_id );
				Ahentic_Session_Repository::clear_verify_attempts( $session_id );
			}
			if ( ! empty( $planned['clear_pending_final'] ) ) {
				Ahentic_Session_Repository::clear_pending_final( $session_id );
			}
			if ( ! empty( $planned['clear_forced_tools'] ) ) {
				Ahentic_Session_Repository::clear_forced_tools( $session_id );
				Ahentic_Session_Repository::clear_subagent_recipe( $session_id );
			}
			if ( ! empty( $planned['clear_thought'] ) ) {
				Ahentic_Session_Repository::clear_thought( $session_id );
			}
			if ( ! empty( $planned['clear_browser_paused_at'] ) ) {
				Ahentic_Session_Repository::clear_browser_paused_at( $session_id );
			}
			if ( ! empty( $planned['clear_context_summary'] ) ) {
				Ahentic_Session_Repository::clear_context_summary( $session_id );
			}
			Ahentic_Session_Repository::set_llm_keepalive(
				$session_id,
				! empty( $planned['set_llm_keepalive'] )
			);

			Ahentic_Session_Repository::set_content_work( $session_id, ! empty( $planned['content_work'] ) );

			if ( ! empty( $planned['consume_capability_requests'] ) ) {
				Ahentic_Session_Repository::consume_capability_requests( $session_id );
			}

			if ( ! empty( $planned['clear_plan'] ) ) {
				Ahentic_Session_Repository::clear_plan( $session_id );
			}

			if ( ! empty( $planned['set_active_goal'] ) ) {
				Ahentic_Session_Repository::set_active_goal(
					$session_id,
					isset( $planned['active_goal'] ) ? (string) $planned['active_goal'] : ''
				);
			}

			Ahentic_Session_Repository::reset_step_count( $session_id );

			$status = isset( $planned['status'] ) ? (string) $planned['status'] : Ahentic_Session_Repository::STATUS_RUNNING;
			Ahentic_Session_Repository::set_status( $session_id, $status );
			Ahentic_Session_Repository::set_progress(
				$session_id,
				isset( $planned['progress'] ) ? (string) $planned['progress'] : self::default_progress_label()
			);

			if ( ! empty( $planned['touch_heartbeat'] ) ) {
				Ahentic_Session_Repository::touch_heartbeat( $session_id );
			}
		}
	}
}
