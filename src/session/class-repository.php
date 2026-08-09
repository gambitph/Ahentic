<?php
/**
 * Session persistence helpers.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Session_Repository' ) ) {
	/**
	 * Create / read / update ahentic-session posts and conversation entries.
	 */
	class Ahentic_Session_Repository {
		const META_STATUS           = '_ahentic_status';
		const META_MODE             = '_ahentic_mode';
		const META_PENDING_TOOL     = '_ahentic_pending_tool';
		const META_STEP_COUNT       = '_ahentic_step_count';
		const META_TOKENS_IN        = '_ahentic_tokens_in';
		const META_TOKENS_OUT       = '_ahentic_tokens_out';
		const META_TOKENS_USED      = '_ahentic_tokens_used';
		const META_ENTRIES          = '_ahentic_entries';
		const META_SUMMARY_STATUS   = '_ahentic_summary_status';
		const META_SUMMARY_AT       = '_ahentic_summary_at';
		const META_SUMMARY_MODEL    = '_ahentic_summary_model';
		const META_KNOWLEDGE_IMPORTANT = '_ahentic_knowledge_important';
		const META_KNOWLEDGE_KINDS  = '_ahentic_knowledge_kinds';
		const META_KNOWLEDGE_FACTS  = '_ahentic_knowledge_facts';
		const META_KNOWLEDGE_OVERRIDE = '_ahentic_knowledge_override';
		const META_HITL_SESSION     = '_ahentic_hitl_session_allows';
		const META_SETTINGS_SNAPSHOTS = '_ahentic_settings_snapshots';
		const META_ERROR            = '_ahentic_last_error';
		const META_AUTO_TITLE       = '_ahentic_auto_title';
		const META_TRACE            = '_ahentic_trace';
		const META_RUN_SEQ          = '_ahentic_run_seq';
		const META_PROGRESS         = '_ahentic_progress';
		const META_HEARTBEAT        = '_ahentic_heartbeat_at';
		const META_PLAN             = '_ahentic_plan';
		const META_CAPABILITY_REQUESTS = '_ahentic_capability_requests';
		const META_PAGE_CONTEXT        = '_ahentic_page_context';
		const META_VERIFY_PENDING      = '_ahentic_verify_pending';
		const META_VERIFY_ATTEMPTS     = '_ahentic_verify_attempts';
		const META_PENDING_FINAL       = '_ahentic_pending_final';
		const META_FORCED_TOOLS         = '_ahentic_forced_tools';
		const META_FORCED_TOOLS_PURPOSE = '_ahentic_forced_tools_purpose';
		const META_SUBAGENT_RECIPE      = '_ahentic_subagent_recipe';
		/** Forced tools from Finish Gate / apply_required — may finish after success. */
		const FORCED_PURPOSE_APPLY = 'apply';
		/** Remaining tools after browser/HITL pause — must return to think. */
		const FORCED_PURPOSE_BATCH = 'batch';
		/** Subagent Recipe chain — must return to think after the chain. */
		const FORCED_PURPOSE_RECIPE = 'recipe';
		const META_LLM_KEEPALIVE       = '_ahentic_llm_keepalive';
		const META_CONTEXT_SUMMARY    = '_ahentic_context_summary';
		const META_CONTEXT_USAGE      = '_ahentic_context_usage';
		const META_THOUGHT             = '_ahentic_thought';
		const META_EDITOR_REFS         = '_ahentic_editor_refs';
		const META_BROWSER_PAUSED_AT   = '_ahentic_browser_paused_at';
		const META_CONTENT_WORK        = '_ahentic_content_work';
		const META_ACTIVE_GOAL         = '_ahentic_active_goal';
		const META_JOB_RESUMABLE       = '_ahentic_job_resumable';

		const STATUS_IDLE             = 'idle';
		const STATUS_RUNNING          = 'running';
		const STATUS_AWAITING_HUMAN   = 'awaiting_human';
		const STATUS_AWAITING_BROWSER = 'awaiting_browser';
		const STATUS_ERROR            = 'error';
		const STATUS_CANCELLED        = 'cancelled';
		const STATUS_DONE             = 'done';

		const MAX_ENTRIES = 400;
		const MAX_TRACE   = 300;
		/**
		 * Events kept from the START of the trace when the cap is hit.
		 *
		 * A stuck or looping run buries its own cause: dropping only the oldest
		 * events throws away the run_start environment and the first steps, which
		 * is exactly what a bug report needs. Keep a head window plus the tail.
		 */
		const TRACE_HEAD_KEEP = 60;
		/** Recent trace events included in the polled session payload (envelope only). */
		const TRACE_PAYLOAD_LIMIT = 60;

		/**
		 * Create a new session for the current user.
		 *
		 * @param array $args Optional. mode, title.
		 * @return int|\WP_Error Session post ID or error.
		 */
		public static function create( $args = array() ) {
			$user_id = get_current_user_id();
			if ( ! $user_id ) {
				return new WP_Error( 'ahentic_unauthenticated', __( 'You must be logged in.', 'ahentic' ), array( 'status' => 401 ) );
			}

			$mode  = ( isset( $args['mode'] ) && 'ask' === $args['mode'] ) ? 'ask' : 'agent';
			$title = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : __( 'New Agent', 'ahentic' );

			$post_id = wp_insert_post(
				array(
					'post_type'    => Ahentic_Session_CPT::POST_TYPE,
					'post_status'  => 'private',
					'post_title'   => $title,
					'post_excerpt' => '',
					'post_author'  => $user_id,
					'post_content' => '',
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			update_post_meta( $post_id, self::META_STATUS, self::STATUS_IDLE );
			update_post_meta( $post_id, self::META_MODE, $mode );
			update_post_meta( $post_id, self::META_STEP_COUNT, 0 );
			update_post_meta( $post_id, self::META_TOKENS_IN, 0 );
			update_post_meta( $post_id, self::META_TOKENS_OUT, 0 );
			update_post_meta( $post_id, self::META_TOKENS_USED, 0 );
			update_post_meta( $post_id, self::META_ENTRIES, wp_slash( wp_json_encode( array() ) ) );
			update_post_meta( $post_id, self::META_TRACE, wp_slash( wp_json_encode( array() ) ) );
			update_post_meta( $post_id, self::META_AUTO_TITLE, '1' );
			update_post_meta( $post_id, self::META_SUMMARY_STATUS, '' );
			update_post_meta( $post_id, self::META_HITL_SESSION, wp_slash( wp_json_encode( array() ) ) );

			return (int) $post_id;
		}

		/**
		 * Whether the current user owns this session.
		 *
		 * @param int $session_id Session post ID.
		 * @return bool
		 */
		public static function current_user_owns( $session_id ) {
			$post = get_post( $session_id );
			if ( ! $post || Ahentic_Session_CPT::POST_TYPE !== $post->post_type ) {
				return false;
			}
			return (int) $post->post_author === get_current_user_id();
		}

		/**
		 * Load session post or WP_Error.
		 *
		 * @param int $session_id Session ID.
		 * @return \WP_Post|\WP_Error
		 */
		public static function get_post( $session_id ) {
			$post = get_post( $session_id );
			if ( ! $post || Ahentic_Session_CPT::POST_TYPE !== $post->post_type ) {
				return new WP_Error( 'ahentic_session_not_found', __( 'Session not found.', 'ahentic' ), array( 'status' => 404 ) );
			}
			return $post;
		}

		/**
		 * Get status string.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		public static function get_status( $session_id ) {
			$status = get_post_meta( $session_id, self::META_STATUS, true );
			return $status ? (string) $status : self::STATUS_IDLE;
		}

		/**
		 * Set status.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $status     New status.
		 */
		public static function set_status( $session_id, $status ) {
			update_post_meta( $session_id, self::META_STATUS, $status );
		}

		/**
		 * Get mode.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		public static function get_mode( $session_id ) {
			$mode = get_post_meta( $session_id, self::META_MODE, true );
			return 'ask' === $mode ? 'ask' : 'agent';
		}

		/**
		 * Set mode.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $mode       agent|ask.
		 */
		public static function set_mode( $session_id, $mode ) {
			update_post_meta( $session_id, self::META_MODE, 'ask' === $mode ? 'ask' : 'agent' );
		}

		/**
		 * Decode entries array.
		 *
		 * @param int $session_id Session ID.
		 * @return array
		 */
		public static function get_entries( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_ENTRIES, true );
			if ( empty( $raw ) ) {
				return array();
			}
			$decoded = json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}

			// Repair content corrupted by missing wp_slash on older saves (\uXXXX → uXXXX, \n → n).
			foreach ( $decoded as $i => $entry ) {
				if ( isset( $entry['content'] ) && is_string( $entry['content'] ) ) {
					$decoded[ $i ]['content'] = self::repair_corrupted_text( $entry['content'] );
				}
			}

			return $decoded;
		}

		/**
		 * Persist entries array (capped).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $entries    Entries.
		 */
		public static function save_entries( $session_id, array $entries ) {
			if ( count( $entries ) > self::MAX_ENTRIES ) {
				$entries = array_slice( $entries, -1 * self::MAX_ENTRIES );
			}
			// wp_slash is required: update_post_meta stripslashes, which would turn
			// JSON "\n" / "\u2019" into literal "n" / "u2019".
			$json = wp_json_encode(
				array_values( $entries ),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			update_post_meta( $session_id, self::META_ENTRIES, wp_slash( $json ) );
		}

		/**
		 * Repair text damaged by stripslashes on JSON (backslash removed from escapes).
		 *
		 * @param string $text Possibly corrupted text.
		 * @return string
		 */
		public static function repair_corrupted_text( $text ) {
			if ( ! is_string( $text ) || '' === $text ) {
				return $text;
			}

			// Only rewrite when corruption markers are present (avoid touching fine text).
			if ( ! preg_match( '/u20[1-2][0-9a-f]/i', $text ) && false === strpos( $text, 'nn' ) ) {
				// Still try single "n -" list corruption when unicode is fine.
				if ( ! preg_match( '/\*[*\w]\w*\*n\s+-/', $text ) && ! preg_match( '/[.!?:]n\s+-/', $text ) ) {
					return $text;
				}
			}

			$map = array(
				'u2018' => "\u{2018}",
				'u2019' => "\u{2019}",
				'u201C' => "\u{201C}",
				'u201D' => "\u{201D}",
				'u201c' => "\u{201C}",
				'u201d' => "\u{201D}",
				'u2013' => "\u{2013}",
				'u2014' => "\u{2014}",
				'u2026' => "\u{2026}",
				'u00a0' => "\u{00A0}",
				'u00A0' => "\u{00A0}",
			);
			$text = strtr( $text, $map );

			// Paragraph breaks: ".nnFor" / ":nn1."
			$text = preg_replace( '/(?<=[.!?:*\]])nn(?=[A-Z0-9*\-])/', "\n\n", $text );
			// List breaks after word/punctuation/markdown: "n - "
			$text = preg_replace( '/(?<=[.:?\w*\]])n(?=\s+-\s)/', "\n", $text );
			// Numbered list: "n1." after punctuation
			$text = preg_replace( '/(?<=[.!?:])n(?=\d+\.\s)/', "\n", $text );
			// "**Title**n1." style
			$text = preg_replace( '/(?<=\*)n(?=\d+\.\s)/', "\n", $text );

			return is_string( $text ) ? $text : '';
		}

		/**
		 * Append a conversation entry.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $entry      Partial entry (role, content, meta?).
		 * @return array The stored entry.
		 */
		public static function append_entry( $session_id, array $entry ) {
			$entries = self::get_entries( $session_id );
			$seq     = empty( $entries ) ? 1 : ( (int) end( $entries )['seq'] + 1 );

			$stored = array(
				'id'         => isset( $entry['id'] ) ? (string) $entry['id'] : uniqid( 'e_', true ),
				'seq'        => $seq,
				'role'       => isset( $entry['role'] ) ? (string) $entry['role'] : 'assistant',
				'content'    => isset( $entry['content'] ) ? (string) $entry['content'] : '',
				'created_at' => gmdate( 'c' ),
				'meta'       => isset( $entry['meta'] ) && is_array( $entry['meta'] ) ? $entry['meta'] : array(),
			);

			$entries[] = $stored;
			self::save_entries( $session_id, $entries );

			return $stored;
		}

		/**
		 * Paginated entries (newest-first by default for “recent”, then reverse for UI).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $args       limit, before (seq), after (seq).
		 * @return array{entries: array, has_more: bool}
		 */
		public static function get_entries_page( $session_id, $args = array() ) {
			$limit  = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 50;
			$before = isset( $args['before'] ) ? (int) $args['before'] : 0;
			$after  = isset( $args['after'] ) ? (int) $args['after'] : 0;

			$entries = self::get_entries( $session_id );

			if ( $before > 0 ) {
				$entries = array_values(
					array_filter(
						$entries,
						static function ( $e ) use ( $before ) {
							return (int) $e['seq'] < $before;
						}
					)
				);
			}

			if ( $after > 0 ) {
				$entries = array_values(
					array_filter(
						$entries,
						static function ( $e ) use ( $after ) {
							return (int) $e['seq'] > $after;
						}
					)
				);
			}

			// Return chronological for UI; when paging “before”, take the last $limit of older ones.
			$has_more = count( $entries ) > $limit;
			if ( $before > 0 || ( ! $after && $has_more ) ) {
				$entries = array_slice( $entries, -$limit );
			} else {
				$entries = array_slice( $entries, 0, $limit );
			}

			return array(
				'entries'  => $entries,
				'has_more' => $has_more,
			);
		}

		/**
		 * Public session payload for REST.
		 *
		 * @param int  $session_id   Session ID.
		 * @param bool $with_recent  Include recent messages.
		 * @param int  $recent_limit Recent message limit.
		 * @return array|\WP_Error
		 */
		public static function to_rest( $session_id, $with_recent = true, $recent_limit = 50 ) {
			$post = self::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$pending = get_post_meta( $session_id, self::META_PENDING_TOOL, true );
			$pending = $pending ? json_decode( (string) $pending, true ) : null;
			$status  = self::get_status( $session_id );

			// Expand from_memory for the browser runner only (meta stays key-only).
			if (
				is_array( $pending )
				&& self::STATUS_AWAITING_BROWSER === $status
				&& ! empty( $pending['runtime'] )
				&& 'browser' === $pending['runtime']
				&& class_exists( 'Ahentic_Session_Artifacts' )
			) {
				$pending = Ahentic_Session_Artifacts::expand_pending_for_browser( $session_id, $pending );
			}

			$payload = array(
				'id'           => (int) $session_id,
				'title'        => $post->post_title,
				'status'       => $status,
				'mode'         => self::get_mode( $session_id ),
				'excerpt'      => $post->post_excerpt,
				'tokensIn'     => (int) get_post_meta( $session_id, self::META_TOKENS_IN, true ),
				'tokensOut'    => (int) get_post_meta( $session_id, self::META_TOKENS_OUT, true ),
				'tokensUsed'   => (int) get_post_meta( $session_id, self::META_TOKENS_USED, true ),
				'contextUsage' => self::get_context_usage_for_rest( $session_id ),
				'stepCount'    => (int) get_post_meta( $session_id, self::META_STEP_COUNT, true ),
				'pendingTool'  => is_array( $pending ) ? $pending : null,
				'lastError'    => (string) get_post_meta( $session_id, self::META_ERROR, true ),
				'jobResumable' => self::get_job_resumable( $session_id ),
				'contentWork'  => self::get_content_work( $session_id ),
				'summaryStatus'=> (string) get_post_meta( $session_id, self::META_SUMMARY_STATUS, true ),
				'createdAt'    => get_post_time( 'c', true, $post ),
				'modifiedAt'   => get_post_modified_time( 'c', true, $post ),
			);

			if ( $with_recent ) {
				$page                = self::get_entries_page( $session_id, array( 'limit' => $recent_limit ) );
				$payload['messages'] = $page['entries'];
				$payload['hasMore']  = $page['has_more'];
			}

			// Recent envelopes only: the debugger pulls full event payloads from
			// /diagnostics so a ~650ms poll does not carry the whole log.
			$full_trace            = self::get_trace( $session_id );
			$payload['trace']      = self::slim_trace_for_payload( $full_trace, self::TRACE_PAYLOAD_LIMIT );
			$payload['traceCount'] = count( $full_trace );
			$payload['progress'] = self::get_progress( $session_id );
			$payload['heartbeatAt'] = self::get_heartbeat( $session_id );
			$payload['plan'] = self::get_plan( $session_id );
			$payload['thoughtProcess'] = self::get_thought( $session_id );
			$payload['editorRefs'] = self::get_editor_refs( $session_id );
			$payload['browserPausedAt'] = self::get_browser_paused_at( $session_id );
			$payload['artifacts'] = class_exists( 'Ahentic_Session_Artifacts' )
				? Ahentic_Session_Artifacts::list_pointers( $session_id )
				: array();

			// While an LLM call is in flight, nudge cron so keepalive heartbeat ticks can run
			// (sidebar polls arrive in other requests while the worker is blocked).
			if ( self::get_llm_keepalive( $session_id ) && class_exists( 'Ahentic_Step_Queue' ) ) {
				Ahentic_Step_Queue::nudge_cron();
			}

			return $payload;
		}

		/**
		 * ISO-8601 heartbeat when the orchestrator worker last proved it was alive.
		 *
		 * @param int $session_id Session ID.
		 * @return string Empty when none.
		 */
		public static function get_heartbeat( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_HEARTBEAT, true );
			return is_string( $raw ) ? $raw : '';
		}

		/**
		 * Bump worker liveness (distinct from the human-readable progress label).
		 *
		 * @param int $session_id Session ID.
		 */
		public static function touch_heartbeat( $session_id ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}
			update_post_meta( $session_id, self::META_HEARTBEAT, gmdate( 'c' ) );
		}

		/**
		 * Multi-step plan for the current run (sidebar card).
		 *
		 * @param int $session_id Session ID.
		 * @return array|null { title, steps, updatedAt }
		 */
		public static function get_plan( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_PLAN, true );
			if ( empty( $raw ) ) {
				return null;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
			} elseif ( is_array( $raw ) ) {
				$decoded = $raw;
			} else {
				return null;
			}
			if ( ! is_array( $decoded ) || empty( $decoded['steps'] ) || ! is_array( $decoded['steps'] ) ) {
				return null;
			}

			$steps = array();
			foreach ( $decoded['steps'] as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$content = isset( $step['content'] ) ? trim( (string) $step['content'] ) : '';
				if ( '' === $content ) {
					continue;
				}
				$status = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				if ( ! in_array( $status, array( 'pending', 'in_progress', 'completed', 'cancelled' ), true ) ) {
					$status = 'pending';
				}
				$steps[] = array(
					'id'      => isset( $step['id'] ) && '' !== (string) $step['id']
						? (string) $step['id']
						: (string) ( count( $steps ) + 1 ),
					'content' => $content,
					'status'  => $status,
				);
			}

			if ( empty( $steps ) ) {
				return null;
			}

			return array(
				'title'     => isset( $decoded['title'] ) ? (string) $decoded['title'] : '',
				'steps'     => $steps,
				'updatedAt' => isset( $decoded['updated_at'] )
					? (string) $decoded['updated_at']
					: ( isset( $decoded['updatedAt'] ) ? (string) $decoded['updatedAt'] : '' ),
			);
		}

		/**
		 * Persist a multi-step plan (or clear when $plan is null).
		 *
		 * @param int        $session_id Session ID.
		 * @param array|null $plan       { title?, steps: [{ id, content, status }] } or null to clear.
		 * @return bool True when meta changed.
		 */
		public static function set_plan( $session_id, $plan ) {
			if ( null === $plan ) {
				return self::clear_plan( $session_id );
			}

			if ( ! is_array( $plan ) || empty( $plan['steps'] ) || ! is_array( $plan['steps'] ) ) {
				return false;
			}

			$steps = array();
			foreach ( $plan['steps'] as $index => $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$content = isset( $step['content'] ) ? trim( wp_strip_all_tags( (string) $step['content'] ) ) : '';
				if ( '' === $content ) {
					continue;
				}
				$status = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				if ( ! in_array( $status, array( 'pending', 'in_progress', 'completed', 'cancelled' ), true ) ) {
					$status = 'pending';
				}
				$id = isset( $step['id'] ) ? trim( (string) $step['id'] ) : '';
				$id = preg_replace( '/\s+/', '-', $id );
				$id = substr( (string) $id, 0, 64 );
				if ( '' === $id ) {
					$id = (string) ( $index + 1 );
				}
				$steps[] = array(
					'id'      => $id,
					'content' => $content,
					'status'  => $status,
				);
			}

			if ( empty( $steps ) ) {
				return false;
			}

			$title = isset( $plan['title'] ) ? sanitize_text_field( (string) $plan['title'] ) : '';
			$next  = array(
				'title'      => $title,
				'steps'      => $steps,
				'updated_at' => gmdate( 'c' ),
			);

			$existing = self::get_plan( $session_id );
			if ( is_array( $existing ) ) {
				$existing_cmp = array(
					'title' => isset( $existing['title'] ) ? (string) $existing['title'] : '',
					'steps' => isset( $existing['steps'] ) ? $existing['steps'] : array(),
				);
				$next_cmp = array(
					'title' => $title,
					'steps' => $steps,
				);
				if ( wp_json_encode( $existing_cmp ) === wp_json_encode( $next_cmp ) ) {
					return false;
				}
			}

			update_post_meta(
				$session_id,
				self::META_PLAN,
				wp_slash( wp_json_encode( $next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
			);

			return true;
		}

		/**
		 * Clear the multi-step plan.
		 *
		 * @param int $session_id Session ID.
		 * @return bool True when something was cleared.
		 */
		public static function clear_plan( $session_id ) {
			$existing = get_post_meta( $session_id, self::META_PLAN, true );
			if ( empty( $existing ) ) {
				return false;
			}
			delete_post_meta( $session_id, self::META_PLAN );
			return true;
		}

		/**
		 * Current live progress label for the sidebar toast.
		 *
		 * @param int $session_id Session ID.
		 * @return array|null { label, updatedAt }
		 */
		public static function get_progress( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_PROGRESS, true );
			if ( empty( $raw ) ) {
				return null;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
			} elseif ( is_array( $raw ) ) {
				$decoded = $raw;
			} else {
				return null;
			}
			if ( ! is_array( $decoded ) || empty( $decoded['label'] ) ) {
				return null;
			}
			return array(
				'label'     => (string) $decoded['label'],
				'updatedAt' => isset( $decoded['updated_at'] ) ? (string) $decoded['updated_at'] : '',
			);
		}

		/**
		 * Set live progress (and log a progress trace event).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $label      Human-readable status.
		 * @param int    $step       Optional step number for trace.
		 */
		public static function set_progress( $session_id, $label, $step = 0 ) {
			$label = trim( (string) $label );
			if ( '' === $label ) {
				self::clear_progress( $session_id );
				return;
			}

			$payload = array(
				'label'      => $label,
				'updated_at' => gmdate( 'c' ),
			);
			update_post_meta(
				$session_id,
				self::META_PROGRESS,
				wp_slash( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
			);

			self::touch_heartbeat( $session_id );

			self::append_trace(
				$session_id,
				'progress',
				$label,
				array( 'label' => $label ),
				(int) $step
			);
		}

		/**
		 * Clear live progress.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function clear_progress( $session_id ) {
			delete_post_meta( $session_id, self::META_PROGRESS );
		}

		/**
		 * Queue a missing-ability capability request for this run (deduped by ability).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $request    Payload from Ahentic_Capability_Request::build().
		 */
		public static function queue_capability_request( $session_id, array $request ) {
			$ability = isset( $request['ability'] ) ? (string) $request['ability'] : '';
			if ( '' === $ability ) {
				return;
			}

			$pending = self::get_capability_requests( $session_id );
			foreach ( $pending as $existing ) {
				if ( isset( $existing['ability'] ) && (string) $existing['ability'] === $ability ) {
					return;
				}
			}

			$pending[] = $request;
			$json      = wp_json_encode( array_values( $pending ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			update_post_meta( $session_id, self::META_CAPABILITY_REQUESTS, wp_slash( is_string( $json ) ? $json : '[]' ) );
		}

		/**
		 * Pending capability requests for the current run.
		 *
		 * @param int $session_id Session ID.
		 * @return array<int, array>
		 */
		public static function get_capability_requests( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_CAPABILITY_REQUESTS, true );
			if ( empty( $raw ) ) {
				return array();
			}
			$decoded = json_decode( (string) $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Take and clear pending capability requests.
		 *
		 * @param int $session_id Session ID.
		 * @return array<int, array>
		 */
		public static function consume_capability_requests( $session_id ) {
			$pending = self::get_capability_requests( $session_id );
			delete_post_meta( $session_id, self::META_CAPABILITY_REQUESTS );
			return $pending;
		}

		/**
		 * Get debug trace events.
		 *
		 * @param int $session_id Session ID.
		 * @return array
		 */
		public static function get_trace( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_TRACE, true );
			if ( empty( $raw ) ) {
				return array();
			}
			$decoded = json_decode( (string) $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Persist trace array (capped).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $events     Events.
		 */
		public static function save_trace( $session_id, array $events ) {
			$total = count( $events );
			if ( $total > self::MAX_TRACE ) {
				$head   = array_slice( $events, 0, self::TRACE_HEAD_KEEP );
				$tail   = array_slice( $events, -1 * ( self::MAX_TRACE - self::TRACE_HEAD_KEEP - 1 ) );
				$middle = array_slice( $events, count( $head ), $total - count( $head ) - count( $tail ) );

				// The previous marker is itself in the dropped middle, so carry its
				// count forward — otherwise the gap only ever reports the last prune.
				$dropped = 0;
				foreach ( $middle as $event ) {
					if ( isset( $event['type'] ) && 'trace_gap' === $event['type'] ) {
						$dropped += isset( $event['data']['dropped'] ) ? (int) $event['data']['dropped'] : 0;
					} else {
						++$dropped;
					}
				}

				$events = array_merge(
					$head,
					array(
						array(
							'id'      => 'gap',
							'at'      => gmdate( 'c' ),
							'ms'      => (int) round( microtime( true ) * 1000 ),
							'run'     => 0,
							'type'    => 'trace_gap',
							'step'    => 0,
							'summary' => sprintf( '%d events dropped (trace cap)', $dropped ),
							'data'    => array( 'dropped' => $dropped ),
						),
					),
					$tail
				);
			}
			$json = wp_json_encode(
				array_values( $events ),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			update_post_meta( $session_id, self::META_TRACE, wp_slash( $json ) );
		}

		/**
		 * Append a debug trace event.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $type       Event type.
		 * @param string $summary    One-line summary.
		 * @param array  $data       Extra payload.
		 * @param int    $step       Optional step number.
		 * @return array Stored event.
		 */
		public static function append_trace( $session_id, $type, $summary = '', $data = array(), $step = 0 ) {
			$events = self::get_trace( $session_id );
			$type   = (string) $type;

			// Every event carries its run so a multi-run log can be split by the reader.
			if ( 'run_start' === $type ) {
				$run = (int) get_post_meta( $session_id, self::META_RUN_SEQ, true ) + 1;
				update_post_meta( $session_id, self::META_RUN_SEQ, $run );
			} else {
				$run = (int) get_post_meta( $session_id, self::META_RUN_SEQ, true );
			}

			$event = array(
				'id'      => uniqid( 't_', true ),
				'at'      => gmdate( 'c' ),
				// Second-precision `at` cannot separate a 90ms resume from a 6s round trip.
				'ms'      => (int) round( microtime( true ) * 1000 ),
				'run'     => $run,
				'type'    => $type,
				'step'    => (int) $step,
				'summary' => (string) $summary,
				'data'    => is_array( $data ) ? $data : array(),
			);
			$events[] = $event;
			self::save_trace( $session_id, $events );
			return $event;
		}

		/**
		 * Full diagnostics bundle for a bug report (not part of the polled payload).
		 *
		 * @param int $session_id Session ID.
		 * @return array|\WP_Error
		 */
		public static function to_diagnostics( $session_id ) {
			$post = self::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$trace = self::get_trace( $session_id );
			$model = '';
			for ( $i = count( $trace ) - 1; $i >= 0; $i-- ) {
				if ( ! empty( $trace[ $i ]['data']['model'] ) ) {
					$model = (string) $trace[ $i ]['data']['model'];
					break;
				}
			}

			return array(
				'id'          => (int) $session_id,
				'title'       => $post->post_title,
				'exportedAt'  => gmdate( 'c' ),
				'environment' => self::environment_snapshot(),
				'session'     => array(
					'status'     => self::get_status( $session_id ),
					'mode'       => self::get_mode( $session_id ),
					'model'      => $model,
					'runs'       => (int) get_post_meta( $session_id, self::META_RUN_SEQ, true ),
					'stepCount'  => (int) get_post_meta( $session_id, self::META_STEP_COUNT, true ),
					'tokensIn'   => (int) get_post_meta( $session_id, self::META_TOKENS_IN, true ),
					'tokensOut'  => (int) get_post_meta( $session_id, self::META_TOKENS_OUT, true ),
					'tokensUsed' => (int) get_post_meta( $session_id, self::META_TOKENS_USED, true ),
					'lastError'  => (string) get_post_meta( $session_id, self::META_ERROR, true ),
					'createdAt'  => get_post_time( 'c', true, $post ),
					'modifiedAt' => get_post_modified_time( 'c', true, $post ),
				),
				'state'       => array(
					'pendingTool'     => self::get_pending_tool( $session_id ),
					'verifyPending'   => self::get_verify_pending( $session_id ),
					'verifyAttempts'  => self::get_verify_attempts( $session_id ),
					'forcedTools'     => self::get_forced_tools( $session_id ),
					'browserPausedAt' => self::get_browser_paused_at( $session_id ),
					'heartbeatAt'     => self::get_heartbeat( $session_id ),
					'progress'        => self::get_progress( $session_id ),
					'contentWork'     => self::get_content_work( $session_id ),
					'jobResumable'    => self::get_job_resumable( $session_id ),
					'activeGoal'      => self::get_active_goal( $session_id ),
				),
				'trace'       => $trace,
			);
		}

		/**
		 * Host details a maintainer needs before they can read someone else's log.
		 *
		 * @return array
		 */
		public static function environment_snapshot() {
			global $wp_version;

			$ai_client = 'none';
			if ( function_exists( 'wp_ai_client_prompt' ) ) {
				$ai_client = 'core';
			} elseif ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
				$ai_client = 'sdk';
			}

			return array(
				'plugin'        => defined( 'AHENTIC_VERSION' ) ? AHENTIC_VERSION : '',
				'build'         => defined( 'AHENTIC_BUILD' ) ? AHENTIC_BUILD : '',
				'wp'            => isset( $wp_version ) ? (string) $wp_version : '',
				'php'           => PHP_VERSION,
				'aiClient'      => $ai_client,
				'multisite'     => is_multisite(),
				'memoryLimit'   => (string) ini_get( 'memory_limit' ),
				'maxExecution'  => (int) ini_get( 'max_execution_time' ),
				'objectCache'   => (bool) wp_using_ext_object_cache(),
				'cronDisabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'altCron'       => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
				'wpDebug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'activePlugins' => count( (array) get_option( 'active_plugins', array() ) ),
				'locale'        => get_locale(),
			);
		}

		/**
		 * Envelope-only trace for the polled session payload.
		 *
		 * The sidebar polls every ~650ms and only needs recent type/summary pairs for
		 * the live status line. Event `data` is the bulk of the trace, so it is served
		 * from the diagnostics route on demand instead of on every poll.
		 *
		 * @param array $events Full trace.
		 * @param int   $limit  Max events to include.
		 * @return array
		 */
		private static function slim_trace_for_payload( array $events, $limit ) {
			$limit = max( 1, (int) $limit );
			if ( count( $events ) > $limit ) {
				$events = array_slice( $events, -1 * $limit );
			}

			$out = array();
			foreach ( $events as $event ) {
				$out[] = array(
					'id'      => isset( $event['id'] ) ? $event['id'] : '',
					'at'      => isset( $event['at'] ) ? $event['at'] : '',
					'ms'      => isset( $event['ms'] ) ? (int) $event['ms'] : 0,
					'run'     => isset( $event['run'] ) ? (int) $event['run'] : 0,
					'type'    => isset( $event['type'] ) ? $event['type'] : '',
					'step'    => isset( $event['step'] ) ? (int) $event['step'] : 0,
					'summary' => isset( $event['summary'] ) ? $event['summary'] : '',
				);
			}
			return $out;
		}

		/**
		 * List sessions for current user.
		 *
		 * @param int $limit Max posts.
		 * @return array
		 */
		public static function list_for_current_user( $limit = 50 ) {
			$query = new WP_Query(
				array(
					'post_type'      => Ahentic_Session_CPT::POST_TYPE,
					'post_status'    => 'private',
					'author'         => get_current_user_id(),
					'posts_per_page' => max( 1, min( 100, (int) $limit ) ),
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			$items = array();
			foreach ( $query->posts as $post ) {
				$items[] = array(
					'id'         => (int) $post->ID,
					'title'      => $post->post_title,
					'status'     => self::get_status( $post->ID ),
					'mode'       => self::get_mode( $post->ID ),
					'tokensUsed' => (int) get_post_meta( $post->ID, self::META_TOKENS_USED, true ),
					'modifiedAt' => get_post_modified_time( 'c', true, $post ),
				);
			}

			return $items;
		}

		/**
		 * Update title if still auto-generated.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $title      New title.
		 */
		public static function maybe_set_auto_title( $session_id, $title ) {
			if ( '1' !== (string) get_post_meta( $session_id, self::META_AUTO_TITLE, true ) ) {
				return;
			}
			$clean = sanitize_text_field( $title );
			if ( '' === $clean ) {
				return;
			}
			if ( strlen( $clean ) > 60 ) {
				$clean = rtrim( substr( $clean, 0, 57 ) ) . '…';
			}
			wp_update_post(
				array(
					'ID'         => $session_id,
					'post_title' => $clean,
				)
			);
		}

		/**
		 * Mark title as manually set.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $title      Title.
		 */
		public static function set_title( $session_id, $title ) {
			wp_update_post(
				array(
					'ID'         => $session_id,
					'post_title' => sanitize_text_field( $title ),
				)
			);
			update_post_meta( $session_id, self::META_AUTO_TITLE, '0' );
		}

		/**
		 * Add token usage.
		 *
		 * @param int $session_id Session ID.
		 * @param int $in         Prompt tokens.
		 * @param int $out        Completion tokens.
		 * @param int $total      Total tokens.
		 */
		public static function add_tokens( $session_id, $in, $out, $total ) {
			$tokens_in   = (int) get_post_meta( $session_id, self::META_TOKENS_IN, true ) + max( 0, (int) $in );
			$tokens_out  = (int) get_post_meta( $session_id, self::META_TOKENS_OUT, true ) + max( 0, (int) $out );
			$tokens_used = (int) get_post_meta( $session_id, self::META_TOKENS_USED, true ) + max( 0, (int) $total );

			update_post_meta( $session_id, self::META_TOKENS_IN, $tokens_in );
			update_post_meta( $session_id, self::META_TOKENS_OUT, $tokens_out );
			update_post_meta( $session_id, self::META_TOKENS_USED, $tokens_used );

			if ( class_exists( 'Ahentic_Usage' ) ) {
				Ahentic_Usage::bump_daily( $in, $out, $total );
			}
		}

		/**
		 * Increment step count.
		 *
		 * @param int $session_id Session ID.
		 * @return int New count.
		 */
		public static function bump_step( $session_id ) {
			$count = (int) get_post_meta( $session_id, self::META_STEP_COUNT, true ) + 1;
			update_post_meta( $session_id, self::META_STEP_COUNT, $count );
			return $count;
		}

		/**
		 * Reset the run step counter (new goal / Continue resume).
		 *
		 * @param int $session_id Session ID.
		 */
		public static function reset_step_count( $session_id ) {
			update_post_meta( (int) $session_id, self::META_STEP_COUNT, 0 );
		}

		/**
		 * Set pending tool payload.
		 *
		 * @param int        $session_id Session ID.
		 * @param array|null $payload    Pending tool or null to clear.
		 */
		public static function set_pending_tool( $session_id, $payload ) {
			if ( null === $payload ) {
				delete_post_meta( $session_id, self::META_PENDING_TOOL );
				return;
			}
			update_post_meta( $session_id, self::META_PENDING_TOOL, wp_slash( wp_json_encode( $payload ) ) );
		}

		/**
		 * Get pending tool.
		 *
		 * @param int $session_id Session ID.
		 * @return array|null
		 */
		public static function get_pending_tool( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_PENDING_TOOL, true );
			if ( empty( $raw ) ) {
				return null;
			}
			$decoded = json_decode( (string) $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}

		/**
		 * Store lightweight page context from the sidebar (URL/title/body classes/editor).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $context    Page context.
		 */
		public static function set_page_context( $session_id, $context ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 || ! is_array( $context ) ) {
				return;
			}

			$url = isset( $context['url'] ) ? esc_url_raw( (string) $context['url'] ) : '';
			if ( '' === $url && isset( $context['href'] ) ) {
				$url = esc_url_raw( (string) $context['href'] );
			}

			$post_id = null;
			if ( array_key_exists( 'post_id', $context ) && null !== $context['post_id'] && '' !== $context['post_id'] ) {
				$post_id = (int) $context['post_id'];
			}

			$payload = array(
				'url'            => $url,
				'title'          => isset( $context['title'] ) ? sanitize_text_field( (string) $context['title'] ) : '',
				'pathname'       => isset( $context['pathname'] ) ? sanitize_text_field( (string) $context['pathname'] ) : '',
				'search'         => isset( $context['search'] ) ? sanitize_text_field( (string) $context['search'] ) : '',
				'isAdmin'        => ! empty( $context['isAdmin'] ) || ! empty( $context['is_admin'] ),
				'bodyClass'      => isset( $context['bodyClass'] )
					? substr( sanitize_text_field( (string) $context['bodyClass'] ), 0, 500 )
					: ( isset( $context['body_class'] ) ? substr( sanitize_text_field( (string) $context['body_class'] ), 0, 500 ) : '' ),
				'is_block_editor' => ! empty( $context['is_block_editor'] ),
				'post_id'        => $post_id,
				'post_type'      => isset( $context['post_type'] ) ? sanitize_key( (string) $context['post_type'] ) : '',
				'editor_title'   => isset( $context['editor_title'] ) ? sanitize_text_field( (string) $context['editor_title'] ) : '',
				'status'         => isset( $context['status'] ) ? sanitize_key( (string) $context['status'] ) : '',
				'is_dirty'       => ! empty( $context['is_dirty'] ),
				'is_new'         => ! empty( $context['is_new'] ),
				'blocks_count'   => isset( $context['blocks_count'] ) ? max( 0, (int) $context['blocks_count'] ) : 0,
				'updatedAt'      => gmdate( 'c' ),
			);

			update_post_meta(
				$session_id,
				self::META_PAGE_CONTEXT,
				wp_slash( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
			);
		}

		/**
		 * Get stored page context.
		 *
		 * @param int $session_id Session ID.
		 * @return array
		 */
		public static function get_page_context( $session_id ) {
			$raw = get_post_meta( (int) $session_id, self::META_PAGE_CONTEXT, true );
			if ( empty( $raw ) ) {
				return array();
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Session-scoped HITL allows (ability names).
		 *
		 * @param int $session_id Session ID.
		 * @return string[]
		 */
		public static function get_hitl_session_allows( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_HITL_SESSION, true );
			if ( empty( $raw ) ) {
				return array();
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}
			return array_values( array_filter( array_map( 'strval', $decoded ) ) );
		}

		/**
		 * Remember that this ability is allowed for the rest of the session.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $ability    Ability name.
		 */
		public static function add_hitl_session_allow( $session_id, $ability ) {
			$ability = (string) $ability;
			if ( '' === $ability ) {
				return;
			}
			if ( class_exists( 'Ahentic_Abilities' ) && method_exists( 'Ahentic_Abilities', 'is_non_preallowable' )
				&& Ahentic_Abilities::is_non_preallowable( $ability ) ) {
				return;
			}
			$allows = self::get_hitl_session_allows( $session_id );
			if ( ! in_array( $ability, $allows, true ) ) {
				$allows[] = $ability;
			}
			update_post_meta( $session_id, self::META_HITL_SESSION, wp_slash( wp_json_encode( $allows ) ) );
		}

		/**
		 * User-scoped always-allow list.
		 *
		 * @param int|null $user_id User ID (default current).
		 * @return string[]
		 */
		public static function get_hitl_always_allows( $user_id = null ) {
			$user_id = $user_id ? (int) $user_id : get_current_user_id();
			if ( ! $user_id ) {
				return array();
			}
			$raw = get_user_meta( $user_id, '_ahentic_hitl_always', true );
			if ( empty( $raw ) ) {
				return array();
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}
			return array_values( array_filter( array_map( 'strval', $decoded ) ) );
		}

		/**
		 * Persist always-allow for an ability for the current user.
		 *
		 * @param string   $ability Ability name.
		 * @param int|null $user_id User ID.
		 */
		public static function add_hitl_always_allow( $ability, $user_id = null ) {
			$ability = (string) $ability;
			$user_id = $user_id ? (int) $user_id : get_current_user_id();
			if ( '' === $ability || ! $user_id ) {
				return;
			}
			if ( class_exists( 'Ahentic_Abilities' ) && method_exists( 'Ahentic_Abilities', 'is_non_preallowable' )
				&& Ahentic_Abilities::is_non_preallowable( $ability ) ) {
				return;
			}
			$allows = self::get_hitl_always_allows( $user_id );
			if ( ! in_array( $ability, $allows, true ) ) {
				$allows[] = $ability;
			}
			update_user_meta( $user_id, '_ahentic_hitl_always', wp_json_encode( $allows ) );
		}

		/**
		 * Whether HITL can be skipped for this ability (session or always policy).
		 *
		 * Non-preallowable abilities never skip — session/always lists are ignored.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $ability    Ability name.
		 * @return bool
		 */
		public static function hitl_is_preallowed( $session_id, $ability ) {
			$ability = (string) $ability;
			if ( '' === $ability ) {
				return false;
			}
			if ( class_exists( 'Ahentic_Abilities' ) && method_exists( 'Ahentic_Abilities', 'is_non_preallowable' )
				&& Ahentic_Abilities::is_non_preallowable( $ability ) ) {
				return false;
			}
			if ( in_array( $ability, self::get_hitl_session_allows( $session_id ), true ) ) {
				return true;
			}
			$owner = (int) get_post_field( 'post_author', $session_id );
			return in_array( $ability, self::get_hitl_always_allows( $owner ), true );
		}

		/**
		 * Settings-surface snapshot list for a session (oldest → newest).
		 *
		 * @param int $session_id Session ID.
		 * @return array[]
		 */
		public static function get_settings_snapshots( $session_id ) {
			$session_id = (int) $session_id;
			if ( ! $session_id ) {
				return array();
			}
			$raw = get_post_meta( $session_id, self::META_SETTINGS_SNAPSHOTS, true );
			if ( empty( $raw ) ) {
				return array();
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}
			$out = array();
			foreach ( $decoded as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( class_exists( 'Ahentic_Settings_Snapshots' ) ) {
					$norm = Ahentic_Settings_Snapshots::normalize_entry( $row );
					if ( $norm ) {
						$out[] = $norm;
					}
				} else {
					$out[] = $row;
				}
			}
			return $out;
		}

		/**
		 * Replace the settings snapshot list for a session.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $list       Normalized entries.
		 */
		public static function set_settings_snapshots( $session_id, array $list ) {
			$session_id = (int) $session_id;
			if ( ! $session_id ) {
				return;
			}
			update_post_meta( $session_id, self::META_SETTINGS_SNAPSHOTS, wp_slash( wp_json_encode( array_values( $list ) ) ) );
		}

		/**
		 * Append one settings snapshot (capped).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $entry      Normalized entry.
		 */
		public static function push_settings_snapshot( $session_id, array $entry ) {
			$session_id = (int) $session_id;
			if ( ! $session_id ) {
				return;
			}
			$list = self::get_settings_snapshots( $session_id );
			if ( class_exists( 'Ahentic_Settings_Snapshots' ) ) {
				$list = Ahentic_Settings_Snapshots::append_capped( $list, $entry );
			} else {
				$list[] = $entry;
			}
			self::set_settings_snapshots( $session_id, $list );
		}

		/**
		 * Store last error message.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $message    Error text.
		 */
		public static function set_error( $session_id, $message ) {
			update_post_meta( $session_id, self::META_ERROR, sanitize_text_field( $message ) );
		}

		/**
		 * Clear last error.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function clear_error( $session_id ) {
			delete_post_meta( $session_id, self::META_ERROR );
		}

		/**
		 * Ephemeral thought process for the sidebar (not a durable chat entry).
		 *
		 * @param int $session_id Session ID.
		 * @return array|null { text, updatedAt }
		 */
		public static function get_thought( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_THOUGHT, true );
			if ( empty( $raw ) ) {
				return null;
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) || empty( $decoded['text'] ) ) {
				return null;
			}
			return array(
				'text'      => (string) $decoded['text'],
				'updatedAt' => isset( $decoded['updated_at'] ) ? (string) $decoded['updated_at'] : '',
			);
		}

		/**
		 * @param int    $session_id Session ID.
		 * @param string $text       Thought text.
		 */
		public static function set_thought( $session_id, $text ) {
			$text = trim( (string) $text );
			if ( '' === $text ) {
				self::clear_thought( $session_id );
				return;
			}
			update_post_meta(
				$session_id,
				self::META_THOUGHT,
				wp_slash(
					wp_json_encode(
						array(
							'text'       => $text,
							'updated_at' => gmdate( 'c' ),
						),
						JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
					)
				)
			);
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_thought( $session_id ) {
			delete_post_meta( $session_id, self::META_THOUGHT );
		}

		/**
		 * Unresolved write problems for the current run, keyed by target document.
		 *
		 * Holds findings the orchestrator measured from a write's own payload (a thin
		 * long-form body), not reads it still owes. Empty whenever writes look healthy.
		 *
		 * @param int $session_id Session ID.
		 * @return array<int, array<string, mixed>>
		 */
		public static function get_verify_pending( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_VERIFY_PENDING, true );
			if ( empty( $raw ) ) {
				return array();
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			return is_array( $decoded ) ? array_values( $decoded ) : array();
		}

		/**
		 * @param int   $session_id Session ID.
		 * @param array $items      Write findings still unresolved.
		 */
		public static function set_verify_pending( $session_id, array $items ) {
			if ( empty( $items ) ) {
				delete_post_meta( $session_id, self::META_VERIFY_PENDING );
				return;
			}
			update_post_meta(
				$session_id,
				self::META_VERIFY_PENDING,
				wp_slash( wp_json_encode( array_values( $items ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
			);
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_verify_pending( $session_id ) {
			delete_post_meta( $session_id, self::META_VERIFY_PENDING );
		}

		/**
		 * How many times we deferred finish for verification this run.
		 *
		 * @param int $session_id Session ID.
		 * @return int
		 */
		public static function get_verify_attempts( $session_id ) {
			return max( 0, (int) get_post_meta( $session_id, self::META_VERIFY_ATTEMPTS, true ) );
		}

		/**
		 * @param int $session_id Session ID.
		 * @return int New count.
		 */
		public static function bump_verify_attempts( $session_id ) {
			$n = self::get_verify_attempts( $session_id ) + 1;
			update_post_meta( $session_id, self::META_VERIFY_ATTEMPTS, $n );
			return $n;
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_verify_attempts( $session_id ) {
			delete_post_meta( $session_id, self::META_VERIFY_ATTEMPTS );
		}

		/**
		 * Stashed final reply while verification continues (so prose is not lost).
		 *
		 * @param int $session_id Session ID.
		 * @return array|null { text, model, debug }
		 */
		public static function get_pending_final( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_PENDING_FINAL, true );
			if ( empty( $raw ) ) {
				return null;
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}

		/**
		 * @param int   $session_id Session ID.
		 * @param array $payload    { text, model?, debug? }.
		 */
		public static function set_pending_final( $session_id, array $payload ) {
			$text = isset( $payload['text'] ) ? trim( (string) $payload['text'] ) : '';
			if ( '' === $text ) {
				self::clear_pending_final( $session_id );
				return;
			}
			$out = array(
				'text'  => $text,
				'model' => isset( $payload['model'] ) ? (string) $payload['model'] : '',
				'debug' => isset( $payload['debug'] ) && is_array( $payload['debug'] ) ? $payload['debug'] : array(),
			);
			update_post_meta(
				$session_id,
				self::META_PENDING_FINAL,
				wp_slash( wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
			);
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_pending_final( $session_id ) {
			delete_post_meta( $session_id, self::META_PENDING_FINAL );
		}

		/**
		 * Tools the orchestrator must run before the next free LLM think (apply / verify).
		 *
		 * @param int $session_id Session ID.
		 * @return array<int, array{name: string, input: array}>
		 */
		public static function get_forced_tools( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_FORCED_TOOLS, true );
			if ( empty( $raw ) ) {
				return array();
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}
			$out = array();
			foreach ( $decoded as $item ) {
				if ( ! is_array( $item ) || empty( $item['name'] ) ) {
					continue;
				}
				$out[] = array(
					'name'  => (string) $item['name'],
					'input' => isset( $item['input'] ) && is_array( $item['input'] ) ? $item['input'] : array(),
				);
			}
			return $out;
		}

		/**
		 * Purpose of the current forced-tools queue (apply|batch|recipe).
		 *
		 * Empty meta defaults to apply (Finish Gate / legacy callers).
		 * Prefer get_forced_tools_purpose_raw() when empty must stay empty.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		public static function get_forced_tools_purpose( $session_id ) {
			$raw = self::get_forced_tools_purpose_raw( $session_id );
			if ( self::FORCED_PURPOSE_BATCH === $raw || self::FORCED_PURPOSE_RECIPE === $raw ) {
				return $raw;
			}
			return self::FORCED_PURPOSE_APPLY;
		}

		/**
		 * Raw forced-tools purpose meta (empty when unset — does not default to apply).
		 *
		 * @param int $session_id Session ID.
		 * @return string apply|batch|recipe|'' 
		 */
		public static function get_forced_tools_purpose_raw( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_FORCED_TOOLS_PURPOSE, true );
			return is_string( $raw ) ? $raw : '';
		}

		/**
		 * @param int    $session_id Session ID.
		 * @param array  $tools      List of { name, input }.
		 * @param string $purpose    apply|batch|recipe — successful queues may finish without a wrap-up think (Job Resume).
		 */
		public static function set_forced_tools( $session_id, array $tools, $purpose = self::FORCED_PURPOSE_APPLY ) {
			$out = array();
			foreach ( $tools as $item ) {
				if ( ! is_array( $item ) || empty( $item['name'] ) ) {
					continue;
				}
				$out[] = array(
					'name'  => (string) $item['name'],
					'input' => isset( $item['input'] ) && is_array( $item['input'] ) ? $item['input'] : array(),
				);
			}
			if ( empty( $out ) ) {
				self::clear_forced_tools( $session_id );
				return;
			}
			$purpose = (string) $purpose;
			if ( self::FORCED_PURPOSE_BATCH !== $purpose && self::FORCED_PURPOSE_RECIPE !== $purpose ) {
				$purpose = self::FORCED_PURPOSE_APPLY;
			}
			update_post_meta(
				$session_id,
				self::META_FORCED_TOOLS,
				wp_slash( wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
			);
			update_post_meta( $session_id, self::META_FORCED_TOOLS_PURPOSE, $purpose );
		}

		/**
		 * Read forced tools and clear the queue list.
		 *
		 * Purpose meta is kept until clear_forced_tools() so browser-resume / remainder
		 * can still finish an apply queue.
		 *
		 * @param int $session_id Session ID.
		 * @return array<int, array{name: string, input: array}>
		 */
		public static function consume_forced_tools( $session_id ) {
			$tools = self::get_forced_tools( $session_id );
			// Keep purpose meta so browser-resume / remainder can still finish apply queues.
			delete_post_meta( $session_id, self::META_FORCED_TOOLS );
			return $tools;
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_forced_tools( $session_id ) {
			delete_post_meta( $session_id, self::META_FORCED_TOOLS );
			delete_post_meta( $session_id, self::META_FORCED_TOOLS_PURPOSE );
		}

		/**
		 * Active Subagent chain state (prior tool payloads for bind; no branded recipe ids).
		 *
		 * @param int $session_id Session ID.
		 * @return array|null
		 */
		public static function get_subagent_recipe( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_SUBAGENT_RECIPE, true );
			if ( empty( $raw ) ) {
				return null;
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}

		/**
		 * @param int   $session_id Session ID.
		 * @param array $state      Recipe state.
		 */
		public static function set_subagent_recipe( $session_id, array $state ) {
			update_post_meta(
				$session_id,
				self::META_SUBAGENT_RECIPE,
				wp_slash( wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
			);
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_subagent_recipe( $session_id ) {
			delete_post_meta( $session_id, self::META_SUBAGENT_RECIPE );
		}

		/**
		 * Whether an LLM completion is in flight (external keepalive may bump heartbeat).
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		public static function get_llm_keepalive( $session_id ) {
			return '1' === (string) get_post_meta( (int) $session_id, self::META_LLM_KEEPALIVE, true );
		}

		/**
		 * @param int  $session_id Session ID.
		 * @param bool $on         Enable or clear.
		 */
		public static function set_llm_keepalive( $session_id, $on ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}
			if ( $on ) {
				update_post_meta( $session_id, self::META_LLM_KEEPALIVE, '1' );
				return;
			}
			delete_post_meta( $session_id, self::META_LLM_KEEPALIVE );
		}

		/**
		 * Mid-run rolling summary of older turns (compaction).
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		public static function get_context_summary( $session_id ) {
			$raw = get_post_meta( (int) $session_id, self::META_CONTEXT_SUMMARY, true );
			return is_string( $raw ) ? $raw : '';
		}

		/**
		 * Persist last measured context usage snapshot.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $usage      From Prompt_Assembler::measure / for_llm.
		 */
		public static function set_context_usage( $session_id, array $usage ) {
			$session_id = (int) $session_id;
			if ( $session_id < 1 ) {
				return;
			}
			update_post_meta( $session_id, self::META_CONTEXT_USAGE, wp_slash( wp_json_encode( $usage ) ) );
		}

		/**
		 * Cached context usage, or a fresh measure when missing.
		 *
		 * @param int $session_id Session ID.
		 * @return array|null
		 */
		public static function get_context_usage_for_rest( $session_id ) {
			$session_id = (int) $session_id;
			$raw        = get_post_meta( $session_id, self::META_CONTEXT_USAGE, true );
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && isset( $decoded['budgetTokens'] ) ) {
					return $decoded;
				}
			}

			if ( ! class_exists( 'Ahentic_Prompt_Assembler' ) ) {
				return null;
			}

			$usage = Ahentic_Prompt_Assembler::measure_context_usage( $session_id, self::get_mode( $session_id ) );
			if ( is_array( $usage ) ) {
				self::set_context_usage( $session_id, $usage );
			}
			return is_array( $usage ) ? $usage : null;
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_context_usage( $session_id ) {
			delete_post_meta( (int) $session_id, self::META_CONTEXT_USAGE );
		}

		/**
		 * @param int    $session_id Session ID.
		 * @param string $summary    Summary text (empty clears).
		 */
		public static function set_context_summary( $session_id, $summary ) {
			$session_id = (int) $session_id;
			$summary    = trim( (string) $summary );
			if ( $session_id <= 0 ) {
				return;
			}
			if ( '' === $summary ) {
				delete_post_meta( $session_id, self::META_CONTEXT_SUMMARY );
				return;
			}
			if ( strlen( $summary ) > 8000 ) {
				$summary = substr( $summary, 0, 8000 );
			}
			update_post_meta( $session_id, self::META_CONTEXT_SUMMARY, wp_slash( $summary ) );
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_context_summary( $session_id ) {
			delete_post_meta( (int) $session_id, self::META_CONTEXT_SUMMARY );
		}

		/**
		 * Session-backed editor.refs map (opaque b* → clientId + doc identity).
		 *
		 * @param int $session_id Session ID.
		 * @return array|null
		 */
		public static function get_editor_refs( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_EDITOR_REFS, true );
			if ( empty( $raw ) ) {
				return null;
			}
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) || empty( $decoded['map'] ) || ! is_array( $decoded['map'] ) ) {
				return null;
			}
			return array(
				'postId'    => isset( $decoded['post_id'] ) ? (int) $decoded['post_id'] : 0,
				'nextIndex' => isset( $decoded['next_index'] ) ? (int) $decoded['next_index'] : 1,
				'map'       => $decoded['map'],
				'updatedAt' => isset( $decoded['updated_at'] ) ? (string) $decoded['updated_at'] : '',
			);
		}

		/**
		 * @param int        $session_id Session ID.
		 * @param array|null $refs       { postId, nextIndex, map: { b1: clientId } } or null to clear.
		 */
		public static function set_editor_refs( $session_id, $refs ) {
			if ( null === $refs || ( is_array( $refs ) && empty( $refs['map'] ) ) ) {
				delete_post_meta( $session_id, self::META_EDITOR_REFS );
				return;
			}
			if ( ! is_array( $refs ) ) {
				return;
			}
			$map_in = isset( $refs['map'] ) && is_array( $refs['map'] ) ? $refs['map'] : array();
			$map    = array();
			foreach ( $map_in as $ref => $client_id ) {
				$ref       = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $ref ) );
				$client_id = sanitize_text_field( (string) $client_id );
				if ( '' === $ref || '' === $client_id || ! preg_match( '/^b\d+$/', $ref ) ) {
					continue;
				}
				$map[ $ref ] = $client_id;
			}
			if ( empty( $map ) ) {
				delete_post_meta( $session_id, self::META_EDITOR_REFS );
				return;
			}
			$payload = array(
				'post_id'    => isset( $refs['postId'] ) ? (int) $refs['postId'] : ( isset( $refs['post_id'] ) ? (int) $refs['post_id'] : 0 ),
				'next_index' => isset( $refs['nextIndex'] ) ? max( 1, (int) $refs['nextIndex'] ) : ( isset( $refs['next_index'] ) ? max( 1, (int) $refs['next_index'] ) : 1 ),
				'map'        => $map,
				'updated_at' => gmdate( 'c' ),
			);
			update_post_meta(
				$session_id,
				self::META_EDITOR_REFS,
				wp_slash( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
			);
		}

		/**
		 * When browser pause started (ISO), for timed recovery.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		public static function get_browser_paused_at( $session_id ) {
			$raw = get_post_meta( $session_id, self::META_BROWSER_PAUSED_AT, true );
			return is_string( $raw ) ? $raw : '';
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function touch_browser_paused_at( $session_id ) {
			update_post_meta( $session_id, self::META_BROWSER_PAUSED_AT, gmdate( 'c' ) );
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear_browser_paused_at( $session_id ) {
			delete_post_meta( $session_id, self::META_BROWSER_PAUSED_AT );
		}

		/**
		 * Whether this run was flagged as long-form / article content work (intent gate).
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		public static function get_content_work( $session_id ) {
			return '1' === (string) get_post_meta( (int) $session_id, self::META_CONTENT_WORK, true );
		}

		/**
		 * Mark or clear the content-work intent flag for budgets / verification.
		 *
		 * @param int  $session_id Session ID.
		 * @param bool $on         True to mark.
		 */
		public static function set_content_work( $session_id, $on ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}
			if ( $on ) {
				update_post_meta( $session_id, self::META_CONTENT_WORK, '1' );
				return;
			}
			delete_post_meta( $session_id, self::META_CONTENT_WORK );
		}

		/**
		 * Persisted active user goal for pinned run context (skips resume cues).
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		public static function get_active_goal( $session_id ) {
			$raw = get_post_meta( (int) $session_id, self::META_ACTIVE_GOAL, true );
			return is_string( $raw ) ? trim( $raw ) : '';
		}

		/**
		 * @param int    $session_id Session ID.
		 * @param string $goal       Goal text.
		 */
		public static function set_active_goal( $session_id, $goal ) {
			$session_id = (int) $session_id;
			$goal       = trim( (string) $goal );
			if ( $session_id <= 0 ) {
				return;
			}
			if ( '' === $goal ) {
				delete_post_meta( $session_id, self::META_ACTIVE_GOAL );
				return;
			}
			update_post_meta( $session_id, self::META_ACTIVE_GOAL, $goal );
		}

		/**
		 * Whether Continue can resume this Session job after error / honest partial.
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		public static function get_job_resumable( $session_id ) {
			return '1' === (string) get_post_meta( (int) $session_id, self::META_JOB_RESUMABLE, true );
		}

		/**
		 * @param int  $session_id Session ID.
		 * @param bool $on         True when the job may be Continued.
		 */
		public static function set_job_resumable( $session_id, $on ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}
			if ( $on ) {
				update_post_meta( $session_id, self::META_JOB_RESUMABLE, '1' );
				return;
			}
			delete_post_meta( $session_id, self::META_JOB_RESUMABLE );
		}

		/**
		 * Transition to idle and optionally enqueue summary.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function mark_idle( $session_id ) {
			$old = self::get_status( $session_id );
			self::clear_progress( $session_id );
			self::clear_thought( $session_id );
			self::clear_verify_pending( $session_id );
			self::clear_verify_attempts( $session_id );
			self::clear_pending_final( $session_id );
			self::clear_forced_tools( $session_id );
			self::clear_subagent_recipe( $session_id );
			self::clear_context_summary( $session_id );
			self::set_llm_keepalive( $session_id, false );
			self::clear_browser_paused_at( $session_id );
			self::set_status( $session_id, self::STATUS_IDLE );

			if ( self::STATUS_IDLE !== $old && class_exists( 'Ahentic_Orchestrator' ) ) {
				$entries = self::get_entries( $session_id );
				$has_chat = false;
				foreach ( $entries as $entry ) {
					if ( in_array( $entry['role'], array( 'user', 'assistant' ), true ) && '' !== trim( (string) $entry['content'] ) ) {
						$has_chat = true;
						break;
					}
				}
				if ( $has_chat ) {
					Ahentic_Orchestrator::enqueue_summary( $session_id );
				}
			}
		}
	}
}
