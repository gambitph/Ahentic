<?php
/**
 * Background step / summary queue.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Step_Queue' ) ) {
	/**
	 * Enqueue orchestrator steps and summary jobs.
	 */
	class Ahentic_Step_Queue {
		const HOOK_STEP    = 'ahentic_process_step';
		const HOOK_SUMMARY = 'ahentic_session_summary';
		const GROUP        = 'ahentic';
		const META_RUN_LOCK = '_ahentic_run_lock';

		/**
		 * Register cron / AS handlers.
		 */
		public static function init() {
			add_action( self::HOOK_STEP, array( __CLASS__, 'handle_step' ), 10, 1 );
			add_action( self::HOOK_SUMMARY, array( __CLASS__, 'handle_summary' ), 10, 1 );
		}

		/**
		 * Enqueue a process_step job (backup if shutdown processing is skipped).
		 *
		 * @param int $session_id Session ID.
		 */
		public static function enqueue_step( $session_id ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK_STEP, array( $session_id ), self::GROUP );
				return;
			}

			wp_schedule_single_event( time(), self::HOOK_STEP, array( $session_id ) );
			self::spawn_cron();
		}

		/**
		 * Run the step after the HTTP response so the sidebar can poll progress.
		 *
		 * Preferred path for interactive chat; Action Scheduler / cron is the fallback.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function schedule_interactive_run( $session_id ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}

			$callback = static function () use ( $session_id ) {
				self::finish_request_then_process( $session_id );
			};

			if ( did_action( 'shutdown' ) ) {
				$callback();
				return;
			}

			add_action( 'shutdown', $callback, 0 );
		}

		/**
		 * Close the client connection when possible, then process the run.
		 *
		 * @param int $session_id Session ID.
		 */
		private static function finish_request_then_process( $session_id ) {
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			} else {
				while ( ob_get_level() > 0 ) {
					ob_end_flush();
				}
				flush();
			}

			if ( class_exists( 'Ahentic_Orchestrator' ) ) {
				Ahentic_Orchestrator::process_step( (int) $session_id );
			}
		}

		/**
		 * Claim exclusive processing for a session run.
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		public static function try_claim_run( $session_id ) {
			$session_id = (int) $session_id;
			$now        = time();
			$lock       = get_post_meta( $session_id, self::META_RUN_LOCK, true );

			if ( is_array( $lock ) && isset( $lock['until'] ) && (int) $lock['until'] > $now ) {
				return false;
			}

			update_post_meta(
				$session_id,
				self::META_RUN_LOCK,
				array(
					'until' => $now + 300,
					'at'    => gmdate( 'c' ),
				)
			);
			return true;
		}

		/**
		 * Release the run lock.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function release_run( $session_id ) {
			delete_post_meta( (int) $session_id, self::META_RUN_LOCK );
		}

		/**
		 * Enqueue session summary (slight delay to avoid summarizing mid-burst).
		 *
		 * @param int $session_id Session ID.
		 */
		public static function enqueue_summary( $session_id ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_unschedule_all_actions( self::HOOK_SUMMARY, array( $session_id ), self::GROUP );
				as_schedule_single_action( time() + 45, self::HOOK_SUMMARY, array( $session_id ), self::GROUP );
				return;
			}

			$next = wp_next_scheduled( self::HOOK_SUMMARY, array( $session_id ) );
			if ( $next ) {
				wp_unschedule_event( $next, self::HOOK_SUMMARY, array( $session_id ) );
			}
			wp_schedule_single_event( time() + 45, self::HOOK_SUMMARY, array( $session_id ) );
			self::spawn_cron();
		}

		/**
		 * Cron / AS callback for a step.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function handle_step( $session_id ) {
			if ( class_exists( 'Ahentic_Orchestrator' ) ) {
				Ahentic_Orchestrator::process_step( (int) $session_id );
			}
		}

		/**
		 * Cron / AS callback for summary.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function handle_summary( $session_id ) {
			if ( class_exists( 'Ahentic_Orchestrator' ) ) {
				Ahentic_Orchestrator::run_summary( (int) $session_id );
			}
		}

		/**
		 * Nudge WP-Cron when Action Scheduler is unavailable.
		 */
		private static function spawn_cron() {
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}
}
