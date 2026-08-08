<?php
/**
 * Job resume decisions — sticky goal / content_work / forced-apply finish policy.
 *
 * Pure helpers for mid-failure Continue (issue #3). Session I/O lives on the
 * Orchestrator + Repository; this module owns the decision arithmetic so it
 * stays unit-testable without a WordPress boot.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Job_Resume' ) ) {
	/**
	 * Resume mid-failure policy helpers.
	 */
	class Ahentic_Job_Resume {

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
		 *
		 * @param bool $from_forced       This step ran Orchestrator-forced tools.
		 * @param bool $any_tool_failed   At least one forced tool failed.
		 * @param bool $has_content_work  Session is mid long-form content work.
		 * @return bool True → finish with stashed reply; false → keep looping (or not forced).
		 */
		public static function should_finish_after_forced_tools( $from_forced, $any_tool_failed, $has_content_work ) {
			if ( ! $from_forced ) {
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
		 * @return bool True → try finish/idle before enqueueing another think.
		 */
		public static function should_try_finish_after_browser_resume( $ability, $ok, $forced_tools_remain, $has_content_work ) {
			if ( $forced_tools_remain || ! $ok || $has_content_work ) {
				return false;
			}
			$attr_patch = class_exists( 'Ahentic_Abilities_Browser' )
				? Ahentic_Abilities_Browser::UPDATE_BLOCK_ATTRIBUTES
				: 'ahentic-browser/update-block-attributes';
			return $attr_patch === (string) $ability;
		}
	}
}
