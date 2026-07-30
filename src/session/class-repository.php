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
		const META_ERROR            = '_ahentic_last_error';
		const META_AUTO_TITLE       = '_ahentic_auto_title';
		const META_TRACE            = '_ahentic_trace';
		const META_PROGRESS         = '_ahentic_progress';

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

			$payload = array(
				'id'           => (int) $session_id,
				'title'        => $post->post_title,
				'status'       => self::get_status( $session_id ),
				'mode'         => self::get_mode( $session_id ),
				'excerpt'      => $post->post_excerpt,
				'tokensIn'     => (int) get_post_meta( $session_id, self::META_TOKENS_IN, true ),
				'tokensOut'    => (int) get_post_meta( $session_id, self::META_TOKENS_OUT, true ),
				'tokensUsed'   => (int) get_post_meta( $session_id, self::META_TOKENS_USED, true ),
				'stepCount'    => (int) get_post_meta( $session_id, self::META_STEP_COUNT, true ),
				'pendingTool'  => is_array( $pending ) ? $pending : null,
				'lastError'    => (string) get_post_meta( $session_id, self::META_ERROR, true ),
				'summaryStatus'=> (string) get_post_meta( $session_id, self::META_SUMMARY_STATUS, true ),
				'createdAt'    => get_post_time( 'c', true, $post ),
				'modifiedAt'   => get_post_modified_time( 'c', true, $post ),
			);

			if ( $with_recent ) {
				$page                = self::get_entries_page( $session_id, array( 'limit' => $recent_limit ) );
				$payload['messages'] = $page['entries'];
				$payload['hasMore']  = $page['has_more'];
			}

			$payload['trace'] = self::get_trace( $session_id );
			$payload['progress'] = self::get_progress( $session_id );

			return $payload;
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
			if ( count( $events ) > self::MAX_TRACE ) {
				$events = array_slice( $events, -1 * self::MAX_TRACE );
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
			$event  = array(
				'id'      => uniqid( 't_', true ),
				'at'      => gmdate( 'c' ),
				'type'    => (string) $type,
				'step'    => (int) $step,
				'summary' => (string) $summary,
				'data'    => is_array( $data ) ? $data : array(),
			);
			$events[] = $event;
			self::save_trace( $session_id, $events );
			return $event;
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
		 * Transition to idle and optionally enqueue summary.
		 *
		 * @param int $session_id Session ID.
		 */
		public static function mark_idle( $session_id ) {
			$old = self::get_status( $session_id );
			self::clear_progress( $session_id );
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
