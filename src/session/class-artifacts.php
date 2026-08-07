<?php
/**
 * Session-scoped artifacts — staged payloads for later tool apply.
 *
 * @see src/session/artifacts.md
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Session_Artifacts' ) ) {
	/**
	 * Persist and expand session working-file artifacts.
	 */
	class Ahentic_Session_Artifacts {
		const META_KEY = '_ahentic_artifacts';

		const MAX_ITEMS          = 10;
		const MAX_PAYLOAD_BYTES  = 200000;
		const MAX_KEY_LENGTH     = 64;

		const KIND_BLOCKS        = 'blocks';
		const KIND_HTML          = 'html';
		const KIND_MARKDOWN      = 'markdown';
		const KIND_POST_CONTENT  = 'post_content';
		const KIND_JSON          = 'json';
		const KIND_IMAGE         = 'image';

		const STATUS_DRAFTING = 'drafting';
		const STATUS_READY    = 'ready';
		const STATUS_APPLIED  = 'applied';
		const STATUS_STALE    = 'stale';
		const STATUS_EMPTY    = 'empty';

		const STAGE  = 'ahentic/stage-artifact';
		const LIST   = 'ahentic/list-artifacts';
		const DELETE = 'ahentic/delete-artifact';

		/**
		 * Ability names for the agent loop.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array( self::STAGE, self::LIST, self::DELETE );
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function is_artifact_ability( $name ) {
			return in_array( (string) $name, self::names(), true );
		}

		/**
		 * Session-only helpers are treated as readonly for Ask mode (no site mutation).
		 *
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function is_readonly( $name ) {
			return self::is_artifact_ability( $name );
		}

		/**
		 * Register category + abilities.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}
			wp_register_ability_category(
				'ahentic-session',
				array(
					'label'       => __( 'Ahentic Session', 'ahentic' ),
					'description' => __( 'Session-scoped working files and artifacts for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register stage / list / delete abilities.
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'manage_options' );
			};

			$meta = array(
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => false,
				),
				'show_in_rest' => false,
			);

			wp_register_ability(
				self::STAGE,
				array(
					'label'               => __( 'Stage artifact', 'ahentic' ),
					'description'         => __( 'Stores a session-scoped artifact (draft blocks, HTML, etc.) for later apply via from_memory on set-blocks / create-post / update-post. Does not publish or edit the site — only stages for this session. Prefer this for long drafts so later tools can use {"from_memory":"key"} instead of re-pasting the body. While chunking a new draft use mode=append + complete=false, then complete=true. For a full rewrite of an already-ready key, use mode=replace (or a new key) — do not append onto a finished draft.', 'ahentic' ),
					'category'            => 'ahentic-session',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'key', 'kind' ),
						'properties' => array(
							'key'      => array(
								'type'        => 'string',
								'description' => __( 'Stable id (snake_case), e.g. article_draft. Max 64 chars.', 'ahentic' ),
							),
							'kind'     => array(
								'type'        => 'string',
								'enum'        => array( 'blocks', 'html', 'markdown', 'post_content', 'json' ),
								'description' => __( 'How payload is interpreted when applied.', 'ahentic' ),
							),
							'title'    => array(
								'type'        => 'string',
								'description' => __( 'Short label for prompts / UI.', 'ahentic' ),
							),
							'payload'  => array(
								'description' => __( 'Preferred body. For kind=blocks: { "blocks": [ {name, attributes, innerBlocks}, … ] } or a bare blocks array. For html/markdown/post_content: { "content": "…" } or a string. For json: any object.', 'ahentic' ),
							),
							'content'  => array(
								'description' => __( 'Alias for payload (common model mistake). Same shapes as payload; for kind=blocks a blocks array is accepted.', 'ahentic' ),
							),
							'blocks'   => array(
								'description' => __( 'Alias for payload.blocks when kind=blocks — top-level blocks array without wrapping in payload.', 'ahentic' ),
							),
							'mode'     => array(
								'type'        => 'string',
								'enum'        => array( 'replace', 'append' ),
								'description' => __( 'replace (default) overwrites the payload. append merges chunks only while the key is still drafting; append on a ready/applied key is treated as replace so rewrites do not duplicate content.', 'ahentic' ),
							),
							'complete' => array(
								'type'        => 'boolean',
								'description' => __( 'When false, status stays drafting and from_memory apply is rejected. Default true (ready). With mode=append, complete=true and an empty/omitted body marks an existing drafting artifact ready (not a ready/applied one).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_stage' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::LIST,
				array(
					'label'               => __( 'List artifacts', 'ahentic' ),
					'description'         => __( 'Lists session artifact pointers (key, kind, status, title, size) — bodies omitted.', 'ahentic' ),
					'category'            => 'ahentic-session',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list' ),
					'permission_callback' => $permission,
					'meta'                => array(
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
						'show_in_rest' => false,
					),
				)
			);

			wp_register_ability(
				self::DELETE,
				array(
					'label'               => __( 'Delete artifact', 'ahentic' ),
					'description'         => __( 'Removes a session artifact by key.', 'ahentic' ),
					'category'            => 'ahentic-session',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'key' ),
						'properties' => array(
							'key' => array(
								'type'        => 'string',
								'description' => __( 'Artifact key to delete.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_delete' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);
		}

		/**
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_stage( $input = array() ) {
			$input      = self::coerce_stage_input( is_array( $input ) ? $input : array() );
			$session_id = self::current_session_id();
			if ( $session_id <= 0 ) {
				return new WP_Error( 'ahentic_no_session', __( 'No active session for staging.', 'ahentic' ) );
			}

			$key      = isset( $input['key'] ) ? (string) $input['key'] : '';
			$kind     = isset( $input['kind'] ) ? (string) $input['kind'] : '';
			$title    = isset( $input['title'] ) ? (string) $input['title'] : '';
			$payload  = array_key_exists( 'payload', $input ) ? $input['payload'] : null;
			$mode     = isset( $input['mode'] ) ? (string) $input['mode'] : 'replace';
			$complete = ! array_key_exists( 'complete', $input ) || (bool) $input['complete'];

			$step = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

			$result = self::stage(
				$session_id,
				$key,
				array(
					'kind'     => $kind,
					'title'    => $title,
					'payload'  => $payload,
					'mode'     => $mode,
					'complete' => $complete,
					'meta'     => array(
						'source' => self::STAGE,
						'step'   => $step,
					),
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$item   = self::get_item( $session_id, $key );
			$status = $item && isset( $item['status'] ) ? (string) $item['status'] : ( $complete ? self::STATUS_READY : self::STATUS_DRAFTING );
			$msg    = self::STATUS_DRAFTING === $status
				? sprintf(
					/* translators: %s: artifact key */
					__( 'Drafting artifact “%s” (not ready). Keep appending chunks, then stage again with complete=true before from_memory.', 'ahentic' ),
					$key
				)
				: sprintf(
					/* translators: %s: artifact key */
					__( 'Staged artifact “%s”. Apply later with from_memory on set-blocks / create-post / update-post.', 'ahentic' ),
					$key
				);

			return array(
				'ok'      => true,
				'key'     => $key,
				'kind'    => $item ? $item['kind'] : $kind,
				'status'  => $status,
				'title'   => $item ? $item['title'] : $title,
				'meta'    => $item && isset( $item['meta'] ) ? $item['meta'] : array(),
				'message' => $msg,
			);
		}

		/**
		 * @param mixed $input Unused.
		 * @return array|\WP_Error
		 */
		public static function execute_list( $input = array() ) {
			unset( $input );
			$session_id = self::current_session_id();
			if ( $session_id <= 0 ) {
				return new WP_Error( 'ahentic_no_session', __( 'No active session.', 'ahentic' ) );
			}
			$pointers = self::list_pointers( $session_id );
			return array(
				'ok'        => true,
				'artifacts' => $pointers,
				'count'     => count( $pointers ),
			);
		}

		/**
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_delete( $input = array() ) {
			$input      = is_array( $input ) ? $input : array();
			$session_id = self::current_session_id();
			if ( $session_id <= 0 ) {
				return new WP_Error( 'ahentic_no_session', __( 'No active session.', 'ahentic' ) );
			}
			$key = isset( $input['key'] ) ? (string) $input['key'] : '';
			$key = self::sanitize_key( $key );
			if ( '' === $key ) {
				return new WP_Error( 'ahentic_invalid_artifact_key', __( 'A valid artifact key is required.', 'ahentic' ) );
			}
			if ( ! self::get_item( $session_id, $key ) ) {
				return new WP_Error(
					'artifact_missing',
					sprintf(
						/* translators: %s: artifact key */
						__( 'Artifact “%s” was not found.', 'ahentic' ),
						$key
					)
				);
			}
			self::delete( $session_id, $key );
			return array(
				'ok'      => true,
				'deleted' => $key,
			);
		}

		/**
		 * @param mixed $name  Ability name.
		 * @param mixed $input Input.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			switch ( (string) $name ) {
				case self::STAGE:
					return self::execute_stage( $input );
				case self::LIST:
					return self::execute_list( $input );
				case self::DELETE:
					return self::execute_delete( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown artifact ability.', 'ahentic' ) );
			}
		}

		/**
		 * Full store for a session.
		 *
		 * @param int $session_id Session ID.
		 * @return array{version:int,updated_at:string,items:array}
		 */
		public static function get( $session_id ) {
			$session_id = (int) $session_id;
			$empty      = array(
				'version'    => 1,
				'updated_at' => '',
				'items'      => array(),
			);
			if ( $session_id <= 0 ) {
				return $empty;
			}
			$raw = get_post_meta( $session_id, self::META_KEY, true );
			if ( empty( $raw ) ) {
				return $empty;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
			} elseif ( is_array( $raw ) ) {
				$decoded = $raw;
			} else {
				return $empty;
			}
			if ( ! is_array( $decoded ) ) {
				return $empty;
			}
			$items = isset( $decoded['items'] ) && is_array( $decoded['items'] ) ? $decoded['items'] : array();
			return array(
				'version'    => isset( $decoded['version'] ) ? (int) $decoded['version'] : 1,
				'updated_at' => isset( $decoded['updated_at'] ) ? (string) $decoded['updated_at'] : '',
				'items'      => $items,
			);
		}

		/**
		 * Compact pointers for prompts / REST (no payloads).
		 *
		 * @param int $session_id Session ID.
		 * @return array<int, array<string, mixed>>
		 */
		public static function list_pointers( $session_id ) {
			$store     = self::get( $session_id );
			$pointers  = array();
			foreach ( $store['items'] as $key => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$meta    = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
				$kind    = isset( $item['kind'] ) ? (string) $item['kind'] : '';
				$payload = isset( $item['payload'] ) ? $item['payload'] : null;
				$pointers[] = array(
					'key'         => (string) $key,
					'kind'        => $kind,
					'status'      => isset( $item['status'] ) ? (string) $item['status'] : '',
					'title'       => isset( $item['title'] ) ? (string) $item['title'] : '',
					'bytes'       => self::resolve_artifact_bytes(
						$kind,
						$payload,
						isset( $meta['bytes'] ) ? (int) $meta['bytes'] : 0
					),
					'block_count' => isset( $meta['block_count'] ) ? (int) $meta['block_count'] : null,
					'step'        => isset( $meta['step'] ) ? (int) $meta['step'] : null,
					'updated_at'  => isset( $meta['updated_at'] ) ? (string) $meta['updated_at'] : '',
				);
			}
			return $pointers;
		}

		/**
		 * Prompt-friendly multiline summary (bodies omitted).
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		public static function format_for_prompt( $session_id ) {
			$pointers = self::list_pointers( $session_id );
			if ( empty( $pointers ) ) {
				return '';
			}
			$lines = array(
				'---',
				'Session artifacts (bodies omitted):',
				'- Text drafts (blocks/html/markdown/post_content): apply with from_memory on set-blocks / create-post / update-post.',
				'- Image artifacts: call ahentic/upload-media with {"from_memory":"<key>"} first (HITL), then ahentic-browser/insert-blocks with a core/image block using the returned attachment_id + url. Do NOT pass from_memory to insert-blocks for images.',
			);
			foreach ( $pointers as $p ) {
				$parts = array(
					$p['key'],
					$p['status'] ? $p['status'] : '?',
					$p['kind'] ? $p['kind'] : '?',
				);
				if ( ! empty( $p['title'] ) ) {
					$parts[] = '"' . $p['title'] . '"';
				}
				if ( ! empty( $p['block_count'] ) ) {
					$parts[] = (int) $p['block_count'] . ' blocks';
				} elseif ( ! empty( $p['bytes'] ) ) {
					$parts[] = (int) $p['bytes'] . ' bytes';
				}
				if ( ! empty( $p['step'] ) ) {
					$parts[] = 'staged step ' . (int) $p['step'];
				}
				$lines[] = '- ' . implode( ' · ', $parts );
			}
			$lines[] = 'Prefer {"from_memory":"<key>"} for text drafts when applying a ready artifact. Do not apply while status is drafting — finish with complete=true first. Staging is not publishing — still call the mutate ability.';
			return implode( "\n", $lines );
		}

		/**
		 * @param int    $session_id Session ID.
		 * @param string $key        Artifact key.
		 * @return array|null
		 */
		public static function get_item( $session_id, $key ) {
			$key   = self::sanitize_key( $key );
			$store = self::get( $session_id );
			if ( '' === $key || empty( $store['items'][ $key ] ) || ! is_array( $store['items'][ $key ] ) ) {
				return null;
			}
			return $store['items'][ $key ];
		}

		/**
		 * Coerce common model aliases onto the canonical stage-artifact input shape.
		 *
		 * Models often emit top-level `content` or `blocks` instead of required `payload`.
		 *
		 * @param array $input Raw tool input.
		 * @return array
		 */
		public static function coerce_stage_input( array $input ) {
			if ( array_key_exists( 'payload', $input ) && null !== $input['payload'] && '' !== $input['payload'] ) {
				// Still drop alias keys so schema/validators do not see duplicates.
				unset( $input['content'], $input['blocks'] );
				return $input;
			}

			$kind = isset( $input['kind'] ) ? (string) $input['kind'] : '';

			if ( isset( $input['blocks'] ) && is_array( $input['blocks'] ) ) {
				$input['payload'] = array( 'blocks' => $input['blocks'] );
			} elseif ( array_key_exists( 'content', $input ) ) {
				$content = $input['content'];
				if ( self::KIND_BLOCKS === $kind && is_array( $content ) ) {
					$input['payload'] = self::looks_like_block_list( $content )
						? array( 'blocks' => $content )
						: $content;
				} else {
					$input['payload'] = $content;
				}
			}

			unset( $input['content'], $input['blocks'] );
			return $input;
		}

		/**
		 * Whether a stage body is empty (used for complete=true finalize without new chunks).
		 *
		 * @param string $kind    Kind.
		 * @param mixed  $payload Raw payload.
		 * @return bool
		 */
		private static function is_empty_stage_payload( $kind, $payload ) {
			if ( null === $payload || '' === $payload ) {
				return true;
			}
			if ( is_array( $payload ) ) {
				if ( empty( $payload ) ) {
					return true;
				}
				if ( self::KIND_BLOCKS === $kind ) {
					if ( isset( $payload['blocks'] ) && is_array( $payload['blocks'] ) && empty( $payload['blocks'] ) ) {
						return true;
					}
				}
				if ( isset( $payload['content'] ) && '' === trim( (string) $payload['content'] ) && 1 === count( $payload ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Upsert an artifact (drafting or ready).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $key        Key.
		 * @param array  $item       kind, title, payload, meta, mode, complete|status.
		 * @return true|\WP_Error
		 */
		public static function stage( $session_id, $key, array $item ) {
			$session_id = (int) $session_id;
			$key        = self::sanitize_key( $key );
			if ( $session_id <= 0 ) {
				return new WP_Error( 'ahentic_no_session', __( 'Invalid session.', 'ahentic' ) );
			}
			if ( '' === $key ) {
				return new WP_Error( 'ahentic_invalid_artifact_key', __( 'A valid artifact key is required (snake_case, max 64 chars).', 'ahentic' ) );
			}

			$kind = isset( $item['kind'] ) ? (string) $item['kind'] : '';
			if ( ! in_array( $kind, self::allowed_kinds(), true ) ) {
				return new WP_Error(
					'ahentic_invalid_artifact_kind',
					__( 'Invalid artifact kind. Use blocks, html, markdown, post_content, json, or image.', 'ahentic' )
				);
			}

			$mode = isset( $item['mode'] ) ? (string) $item['mode'] : 'replace';
			if ( ! in_array( $mode, array( 'replace', 'append' ), true ) ) {
				$mode = 'replace';
			}

			$complete = ! array_key_exists( 'complete', $item ) || (bool) $item['complete'];
			$payload  = array_key_exists( 'payload', $item ) ? $item['payload'] : null;

			$store           = self::get( $session_id );
			$existing        = isset( $store['items'][ $key ] ) && is_array( $store['items'][ $key ] ) ? $store['items'][ $key ] : null;
			$existing_status = $existing && isset( $existing['status'] ) ? (string) $existing['status'] : '';

			// Append only merges while drafting. Once ready/applied/stale, a new append is a
			// rewrite cycle — replace the base so revisions do not concatenate duplicates.
			if (
				'append' === $mode
				&& $existing
				&& self::STATUS_DRAFTING !== $existing_status
			) {
				$mode = 'replace';
			}

			// Finalize drafting → ready without a dummy chunk (only while already drafting).
			if (
				$complete
				&& 'append' === $mode
				&& self::is_empty_stage_payload( $kind, $payload )
				&& $existing
				&& self::STATUS_DRAFTING === $existing_status
				&& ! empty( $existing['payload'] )
			) {
				$item['payload']  = $existing['payload'];
				$item['mode']     = 'replace';
				$item['complete'] = true;
				$payload          = $existing['payload'];
				$mode             = 'replace';
			} else {
				$payload = self::normalize_payload( $kind, $payload );
				if ( is_wp_error( $payload ) ) {
					return $payload;
				}
			}

			if ( 'append' === $mode && $existing ) {
				$existing_kind = isset( $existing['kind'] ) ? (string) $existing['kind'] : '';
				if ( $existing_kind && $existing_kind !== $kind ) {
					return new WP_Error(
						'ahentic_artifact_kind_mismatch',
						__( 'Cannot append: artifact kind does not match the existing key.', 'ahentic' )
					);
				}
				$merged = self::merge_payloads( $kind, isset( $existing['payload'] ) ? $existing['payload'] : null, $payload );
				if ( is_wp_error( $merged ) ) {
					return $merged;
				}
				$payload = $merged;
			}

			$encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) ) {
				return new WP_Error( 'ahentic_artifact_encode', __( 'Could not encode artifact payload.', 'ahentic' ) );
			}
			$bytes = self::resolve_artifact_bytes( $kind, $payload, strlen( $encoded ) );
			if ( $bytes > self::MAX_PAYLOAD_BYTES && self::KIND_IMAGE !== $kind ) {
				return new WP_Error(
					'ahentic_artifact_too_large',
					sprintf(
						/* translators: %d: max bytes */
						__( 'Artifact payload exceeds the maximum of %d bytes.', 'ahentic' ),
						self::MAX_PAYLOAD_BYTES
					)
				);
			}
			// Image payloads are path pointers; cap the encoded pointer, not the file on disk.
			if ( self::KIND_IMAGE === $kind && strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
				return new WP_Error(
					'ahentic_artifact_too_large',
					sprintf(
						/* translators: %d: max bytes */
						__( 'Artifact payload exceeds the maximum of %d bytes.', 'ahentic' ),
						self::MAX_PAYLOAD_BYTES
					)
				);
			}

			if ( ! $existing && count( $store['items'] ) >= self::MAX_ITEMS ) {
				return new WP_Error(
					'ahentic_artifact_limit',
					sprintf(
						/* translators: %d: max items */
						__( 'This session already has %d artifacts. Delete one before staging another.', 'ahentic' ),
						self::MAX_ITEMS
					)
				);
			}

			$now      = gmdate( 'c' );
			$old_meta = $existing && isset( $existing['meta'] ) && is_array( $existing['meta'] ) ? $existing['meta'] : array();
			$in_meta  = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();

			$title = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
			if ( '' === $title && $existing && ! empty( $existing['title'] ) ) {
				$title = (string) $existing['title'];
			}
			if ( '' === $title && self::KIND_BLOCKS === $kind ) {
				$title = self::guess_title_from_blocks( $payload );
			}

			$complete = ! array_key_exists( 'complete', $item ) || (bool) $item['complete'];
			$status   = isset( $item['status'] ) ? (string) $item['status'] : ( $complete ? self::STATUS_READY : self::STATUS_DRAFTING );
			if ( ! in_array( $status, array( self::STATUS_DRAFTING, self::STATUS_READY ), true ) ) {
				$status = $complete ? self::STATUS_READY : self::STATUS_DRAFTING;
			}

			$meta = array(
				'source'      => isset( $in_meta['source'] ) ? sanitize_text_field( (string) $in_meta['source'] ) : 'orchestrator',
				'step'        => isset( $in_meta['step'] ) ? (int) $in_meta['step'] : ( isset( $old_meta['step'] ) ? (int) $old_meta['step'] : 0 ),
				'bytes'       => $bytes,
				'block_count' => self::KIND_BLOCKS === $kind ? self::count_blocks( $payload ) : null,
				'created_at'  => isset( $old_meta['created_at'] ) ? (string) $old_meta['created_at'] : $now,
				'updated_at'  => $now,
			);

			$store['items'][ $key ] = array(
				'kind'    => $kind,
				'title'   => $title,
				'status'  => $status,
				'payload' => $payload,
				'meta'    => $meta,
			);
			$store['version']    = 1;
			$store['updated_at'] = $now;

			return self::persist( $session_id, $store );
		}

		/**
		 * @param int    $session_id Session ID.
		 * @param string $key        Key.
		 * @param string $status     Status.
		 * @return true|\WP_Error
		 */
		public static function set_status( $session_id, $key, $status ) {
			$key    = self::sanitize_key( $key );
			$status = (string) $status;
			if ( ! in_array( $status, array( self::STATUS_DRAFTING, self::STATUS_READY, self::STATUS_APPLIED, self::STATUS_STALE, self::STATUS_EMPTY ), true ) ) {
				return new WP_Error( 'ahentic_invalid_artifact_status', __( 'Invalid artifact status.', 'ahentic' ) );
			}
			$store = self::get( $session_id );
			if ( empty( $store['items'][ $key ] ) || ! is_array( $store['items'][ $key ] ) ) {
				return new WP_Error( 'artifact_missing', __( 'Artifact not found.', 'ahentic' ) );
			}
			$store['items'][ $key ]['status'] = $status;
			if ( ! isset( $store['items'][ $key ]['meta'] ) || ! is_array( $store['items'][ $key ]['meta'] ) ) {
				$store['items'][ $key ]['meta'] = array();
			}
			$store['items'][ $key ]['meta']['updated_at'] = gmdate( 'c' );
			$store['updated_at'] = gmdate( 'c' );
			return self::persist( $session_id, $store );
		}

		/**
		 * @param int    $session_id Session ID.
		 * @param string $key        Key.
		 */
		public static function delete( $session_id, $key ) {
			$key   = self::sanitize_key( $key );
			$store = self::get( $session_id );
			if ( '' === $key || ! isset( $store['items'][ $key ] ) ) {
				return;
			}
			self::unlink_image_payload( isset( $store['items'][ $key ] ) ? $store['items'][ $key ] : null );
			unset( $store['items'][ $key ] );
			$store['updated_at'] = gmdate( 'c' );
			self::persist( $session_id, $store );
		}

		/**
		 * @param int $session_id Session ID.
		 */
		public static function clear( $session_id ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}
			$store = self::get( $session_id );
			if ( ! empty( $store['items'] ) && is_array( $store['items'] ) ) {
				foreach ( $store['items'] as $item ) {
					self::unlink_image_payload( $item );
				}
			}
			delete_post_meta( $session_id, self::META_KEY );
		}

		/**
		 * Remove temp file for an image-kind artifact pointer.
		 *
		 * @param mixed $item Artifact item.
		 */
		private static function unlink_image_payload( $item ) {
			if ( ! is_array( $item ) ) {
				return;
			}
			if ( self::KIND_IMAGE !== ( isset( $item['kind'] ) ? (string) $item['kind'] : '' ) ) {
				return;
			}
			$payload = isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array();
			$path    = isset( $payload['path'] ) ? (string) $payload['path'] : '';
			if ( '' === $path || ! is_string( $path ) ) {
				return;
			}
			$temp_root = trailingslashit( function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir() ) . 'ahentic-images/';
			// Only unlink files under our temp directory.
			$real_path = realpath( $path );
			$real_root = realpath( $temp_root );
			if ( ! $real_path || ! $real_root ) {
				return;
			}
			if ( 0 !== strpos( $real_path, $real_root ) ) {
				return;
			}
			if ( is_file( $real_path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup
				@unlink( $real_path );
			}
		}

		/**
		 * Absolute directory for generated image temp files.
		 *
		 * @return string
		 */
		public static function image_temp_dir() {
			$base = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
			$dir  = trailingslashit( $base ) . 'ahentic-images';
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			return $dir;
		}

		/**
		 * Validate from_memory without expanding (for early fail before HITL).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $ability    Ability name.
		 * @param array  $input      Tool input.
		 * @return true|\WP_Error
		 */
		public static function validate_from_memory( $session_id, $ability, array $input ) {
			if ( empty( $input['from_memory'] ) ) {
				return true;
			}
			$expanded = self::apply_from_memory( $session_id, $ability, $input );
			return is_wp_error( $expanded ) ? $expanded : true;
		}

		/**
		 * Expand input.from_memory into ability-specific fields. from_memory wins over inline body.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $ability    Ability name.
		 * @param array  $input      Tool input.
		 * @return array{input: array, artifact_key: string}|array|\WP_Error
		 *         When no from_memory: returns ['input'=>$input,'artifact_key'=>''].
		 *         On success with from_memory: same shape with expanded input.
		 */
		public static function apply_from_memory( $session_id, $ability, array $input ) {
			if ( empty( $input['from_memory'] ) ) {
				return array(
					'input'        => $input,
					'artifact_key' => '',
				);
			}

			$key  = self::sanitize_key( (string) $input['from_memory'] );
			$item = self::get_item( $session_id, $key );
			if ( ! $item ) {
				return new WP_Error(
					'artifact_missing',
					sprintf(
						/* translators: %s: artifact key */
						__( 'Artifact “%s” was not found. Stage it with ahentic/stage-artifact first.', 'ahentic' ),
						$key ? $key : (string) $input['from_memory']
					)
				);
			}

			$status = isset( $item['status'] ) ? (string) $item['status'] : '';
			if ( self::STATUS_DRAFTING === $status ) {
				return new WP_Error(
					'artifact_drafting',
					sprintf(
						/* translators: %s: artifact key */
						__( 'Artifact “%s” is still drafting. Finish staging with complete=true before applying via from_memory.', 'ahentic' ),
						$key
					),
					array(
						'key'    => $key,
						'status' => $status,
					)
				);
			}
			if ( self::STATUS_READY !== $status ) {
				return new WP_Error(
					'artifact_not_ready',
					sprintf(
						/* translators: 1: artifact key, 2: status */
						__( 'Artifact “%1$s” is not ready to apply (status: %2$s). Restage it or pick another key.', 'ahentic' ),
						$key,
						$status ? $status : 'unknown'
					),
					array(
						'key'    => $key,
						'status' => $status,
					)
				);
			}

			$kind    = isset( $item['kind'] ) ? (string) $item['kind'] : '';
			$payload = isset( $item['payload'] ) ? $item['payload'] : null;
			$ability = (string) $ability;

			$out = $input;
			unset( $out['from_memory'] );

			if ( class_exists( 'Ahentic_Abilities_Browser' ) && Ahentic_Abilities_Browser::SET_BLOCKS === $ability ) {
				if ( self::KIND_BLOCKS !== $kind ) {
					return self::kind_mismatch( $key, $kind, $ability, array( self::KIND_BLOCKS ) );
				}
				$blocks = self::payload_blocks( $payload );
				if ( is_wp_error( $blocks ) ) {
					return $blocks;
				}
				$out['blocks'] = $blocks;
				return array(
					'input'        => $out,
					'artifact_key' => $key,
				);
			}

			if ( class_exists( 'Ahentic_Abilities_Media' ) && in_array(
				$ability,
				array( Ahentic_Abilities_Media::UPLOAD_MEDIA, Ahentic_Abilities_Media::REPLACE_MEDIA_FILE ),
				true
			) ) {
				if ( self::KIND_IMAGE !== $kind ) {
					return self::kind_mismatch( $key, $kind, $ability, array( self::KIND_IMAGE ) );
				}
				if ( ! is_array( $payload ) ) {
					return new WP_Error(
						'ahentic_invalid_artifact_payload',
						__( 'image artifacts need { path, mime_type, width, height }.', 'ahentic' )
					);
				}
				$path = isset( $payload['path'] ) ? (string) $payload['path'] : '';
				if ( '' === $path ) {
					return new WP_Error(
						'ahentic_image_path_missing',
						__( 'Image artifact has no file path (it may already have been uploaded).', 'ahentic' )
					);
				}
				$out['source_path'] = $path;
				if ( ! empty( $payload['mime_type'] ) ) {
					$out['mime_type'] = (string) $payload['mime_type'];
				}
				if ( empty( $out['title'] ) && ! empty( $item['title'] ) ) {
					$out['title'] = (string) $item['title'];
				}
				unset( $out['url'] );
				return array(
					'input'        => $out,
					'artifact_key' => $key,
				);
			}

			$is_create = class_exists( 'Ahentic_Abilities_Content' ) && Ahentic_Abilities_Content::CREATE === $ability;
			$is_update = class_exists( 'Ahentic_Abilities_Content' ) && Ahentic_Abilities_Content::UPDATE === $ability;
			if ( $is_create || $is_update ) {
				$content = self::payload_to_post_content( $kind, $payload );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				$out['content'] = $content;
				if ( $is_create && empty( $out['title'] ) && ! empty( $item['title'] ) ) {
					$out['title'] = (string) $item['title'];
				}
				return array(
					'input'        => $out,
					'artifact_key' => $key,
				);
			}

			return new WP_Error(
				'artifact_unsupported_ability',
				sprintf(
					/* translators: %s: ability name */
					__( 'from_memory is not supported for ability %s.', 'ahentic' ),
					$ability
				)
			);
		}

		/**
		 * Mark artifact applied after a successful mutate that used from_memory.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $key        Key.
		 */
		public static function mark_applied( $session_id, $key ) {
			$key = self::sanitize_key( $key );
			if ( '' === $key ) {
				return;
			}
			$item = self::get_item( $session_id, $key );
			self::set_status( $session_id, $key, self::STATUS_APPLIED );
			// Image artifacts point at temp files — unlink once applied (e.g. after upload-media).
			if ( is_array( $item ) && self::KIND_IMAGE === ( isset( $item['kind'] ) ? (string) $item['kind'] : '' ) ) {
				self::unlink_image_payload( $item );
				$store = self::get( $session_id );
				if ( isset( $store['items'][ $key ]['payload'] ) && is_array( $store['items'][ $key ]['payload'] ) ) {
					$store['items'][ $key ]['payload']['path'] = '';
					$store['updated_at'] = gmdate( 'c' );
					self::persist( $session_id, $store );
				}
			}
		}

		/**
		 * Whether this ability supports from_memory expansion.
		 *
		 * @param string $ability Ability name.
		 * @return bool
		 */
		public static function ability_supports_from_memory( $ability ) {
			$ability = (string) $ability;
			if ( class_exists( 'Ahentic_Abilities_Browser' ) && Ahentic_Abilities_Browser::SET_BLOCKS === $ability ) {
				return true;
			}
			if ( class_exists( 'Ahentic_Abilities_Media' )
				&& in_array(
					$ability,
					array( Ahentic_Abilities_Media::UPLOAD_MEDIA, Ahentic_Abilities_Media::REPLACE_MEDIA_FILE ),
					true
				) ) {
				return true;
			}
			if ( class_exists( 'Ahentic_Abilities_Content' ) ) {
				return in_array( $ability, array( Ahentic_Abilities_Content::CREATE, Ahentic_Abilities_Content::UPDATE ), true );
			}
			return false;
		}

		/**
		 * @return string[]
		 */
		private static function allowed_kinds() {
			return array(
				self::KIND_BLOCKS,
				self::KIND_HTML,
				self::KIND_MARKDOWN,
				self::KIND_POST_CONTENT,
				self::KIND_JSON,
				self::KIND_IMAGE,
			);
		}

		/**
		 * Byte size for artifact meta / HITL.
		 *
		 * Image artifacts store a path pointer; report the temp file size when readable,
		 * otherwise fall back to the encoded payload length (blocks/html/etc.).
		 *
		 * @param string $kind        Artifact kind.
		 * @param mixed  $payload     Normalized payload.
		 * @param int    $encoded_len strlen of JSON-encoded payload (fallback).
		 * @return int
		 */
		public static function resolve_artifact_bytes( $kind, $payload, $encoded_len = 0 ) {
			$encoded_len = max( 0, (int) $encoded_len );
			if ( self::KIND_IMAGE === (string) $kind && is_array( $payload ) ) {
				$path = isset( $payload['path'] ) ? (string) $payload['path'] : '';
				if ( '' !== $path && is_readable( $path ) ) {
					$size = filesize( $path );
					if ( false !== $size ) {
						return (int) $size;
					}
				}
			}
			return $encoded_len;
		}

		/**
		 * Public key sanitizer for orchestrator / REST.
		 *
		 * @param string $key Raw key.
		 * @return string
		 */
		public static function sanitize_artifact_key( $key ) {
			return self::sanitize_key( $key );
		}

		/**
		 * Whether this session is doing content / long-form work (higher step budget).
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		public static function session_has_content_work( $session_id ) {
			// Intent gate (e.g. “finish this article”) — before any artifact exists.
			if ( class_exists( 'Ahentic_Session_Repository' ) && Ahentic_Session_Repository::get_content_work( $session_id ) ) {
				return true;
			}
			$pointers = self::list_pointers( $session_id );
			$content_kinds = array( self::KIND_BLOCKS, self::KIND_HTML, self::KIND_MARKDOWN, self::KIND_POST_CONTENT );
			foreach ( $pointers as $p ) {
				$kind = isset( $p['kind'] ) ? (string) $p['kind'] : '';
				if ( in_array( $kind, $content_kinds, true ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Pointer + short excerpt for HITL / pending cards (no full body).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $key        Key.
		 * @return array|null
		 */
		public static function pointer_with_excerpt( $session_id, $key ) {
			$key  = self::sanitize_key( $key );
			$item = self::get_item( $session_id, $key );
			if ( ! $item ) {
				return null;
			}
			$meta    = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
			$kind    = isset( $item['kind'] ) ? (string) $item['kind'] : '';
			$payload = isset( $item['payload'] ) ? $item['payload'] : null;
			$bytes   = self::resolve_artifact_bytes(
				$kind,
				$payload,
				isset( $meta['bytes'] ) ? (int) $meta['bytes'] : 0
			);
			$out     = array(
				'key'     => $key,
				'title'   => isset( $item['title'] ) ? (string) $item['title'] : '',
				'kind'    => $kind,
				'status'  => isset( $item['status'] ) ? (string) $item['status'] : '',
				'bytes'   => $bytes,
				'excerpt' => self::excerpt_from_payload( $kind, $payload, 160 ),
			);
			if ( self::KIND_IMAGE === $kind && is_array( $payload ) ) {
				if ( ! empty( $payload['width'] ) ) {
					$out['width'] = (int) $payload['width'];
				}
				if ( ! empty( $payload['height'] ) ) {
					$out['height'] = (int) $payload['height'];
				}
				if ( ! empty( $payload['mime_type'] ) ) {
					$out['mime_type'] = (string) $payload['mime_type'];
				}
			}
			return $out;
		}

		/**
		 * Expand pending tool input for the browser runner (REST response only).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $pending    Pending tool.
		 * @return array
		 */
		public static function expand_pending_for_browser( $session_id, array $pending ) {
			$input = isset( $pending['input'] ) && is_array( $pending['input'] ) ? $pending['input'] : array();
			$name  = isset( $pending['name'] ) ? (string) $pending['name'] : '';
			if ( empty( $input['from_memory'] ) || ! $name ) {
				return $pending;
			}
			$resolved = self::apply_from_memory( $session_id, $name, $input );
			if ( is_wp_error( $resolved ) ) {
				$pending['memory_error'] = $resolved->get_error_message();
				return $pending;
			}
			$pending['input']        = isset( $resolved['input'] ) && is_array( $resolved['input'] ) ? $resolved['input'] : $input;
			$pending['artifact_key'] = isset( $resolved['artifact_key'] ) ? (string) $resolved['artifact_key'] : '';
			return $pending;
		}

		/**
		 * Merge append chunks into an existing payload.
		 *
		 * @param string $kind Existing kind.
		 * @param mixed  $existing Existing payload.
		 * @param mixed  $incoming Incoming payload.
		 * @return array|\WP_Error
		 */
		private static function merge_payloads( $kind, $existing, $incoming ) {
			if ( self::KIND_BLOCKS === $kind ) {
				$a = self::payload_blocks( $existing );
				$b = self::payload_blocks( $incoming );
				if ( is_wp_error( $a ) ) {
					return $a;
				}
				if ( is_wp_error( $b ) ) {
					return $b;
				}
				return array( 'blocks' => array_merge( $a, $b ) );
			}
			if ( in_array( $kind, array( self::KIND_HTML, self::KIND_MARKDOWN, self::KIND_POST_CONTENT ), true ) ) {
				$a = '';
				$b = '';
				if ( is_array( $existing ) && isset( $existing['content'] ) ) {
					$a = (string) $existing['content'];
				} elseif ( is_string( $existing ) ) {
					$a = $existing;
				}
				if ( is_array( $incoming ) && isset( $incoming['content'] ) ) {
					$b = (string) $incoming['content'];
				} elseif ( is_string( $incoming ) ) {
					$b = $incoming;
				}
				$sep = ( '' !== $a && '' !== $b ) ? "\n\n" : '';
				return array( 'content' => $a . $sep . $b );
			}
			return new WP_Error(
				'ahentic_artifact_append_unsupported',
				__( 'Append mode is only supported for blocks, html, markdown, and post_content artifacts.', 'ahentic' )
			);
		}

		/**
		 * Short text excerpt from a payload for HITL cards.
		 *
		 * @param string $kind    Kind.
		 * @param mixed  $payload Payload.
		 * @param int    $max     Max chars.
		 * @return string
		 */
		private static function excerpt_from_payload( $kind, $payload, $max = 160 ) {
			$text = '';
			if ( self::KIND_BLOCKS === $kind ) {
				$blocks = self::payload_blocks( $payload );
				if ( ! is_wp_error( $blocks ) && ! empty( $blocks[0] ) && is_array( $blocks[0] ) ) {
					$attrs = isset( $blocks[0]['attributes'] ) && is_array( $blocks[0]['attributes'] ) ? $blocks[0]['attributes'] : array();
					if ( ! empty( $attrs['content'] ) ) {
						$text = wp_strip_all_tags( (string) $attrs['content'] );
					} elseif ( ! empty( $blocks[0]['name'] ) ) {
						$text = (string) $blocks[0]['name'];
					}
				}
			} elseif ( is_array( $payload ) && isset( $payload['content'] ) ) {
				$text = wp_strip_all_tags( (string) $payload['content'] );
			} elseif ( is_string( $payload ) ) {
				$text = wp_strip_all_tags( $payload );
			}
			return self::excerpt( $text, $max );
		}

		private static function sanitize_key( $key ) {
			$key = strtolower( trim( (string) $key ) );
			$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );
			if ( ! is_string( $key ) ) {
				return '';
			}
			if ( strlen( $key ) > self::MAX_KEY_LENGTH ) {
				$key = substr( $key, 0, self::MAX_KEY_LENGTH );
			}
			return $key;
		}

		/**
		 * @param string $kind    Kind.
		 * @param mixed  $payload Raw payload.
		 * @return mixed|\WP_Error
		 */
		private static function normalize_payload( $kind, $payload ) {
			if ( self::KIND_BLOCKS === $kind ) {
				if ( is_string( $payload ) ) {
					$decoded = json_decode( $payload, true );
					$payload = is_array( $decoded ) ? $decoded : $payload;
				}
				if ( is_array( $payload ) && isset( $payload['blocks'] ) && is_array( $payload['blocks'] ) ) {
					$blocks = $payload['blocks'];
				} elseif ( is_array( $payload ) && self::looks_like_block_list( $payload ) ) {
					$blocks = $payload;
				} else {
					return new WP_Error(
						'ahentic_invalid_artifact_payload',
						__( 'blocks artifacts need payload.blocks as an array of {name, attributes, innerBlocks}.', 'ahentic' )
					);
				}
				if ( empty( $blocks ) ) {
					return new WP_Error( 'ahentic_invalid_artifact_payload', __( 'blocks payload cannot be empty.', 'ahentic' ) );
				}
				return array( 'blocks' => array_values( $blocks ) );
			}

			if ( in_array( $kind, array( self::KIND_HTML, self::KIND_MARKDOWN, self::KIND_POST_CONTENT ), true ) ) {
				if ( is_string( $payload ) ) {
					$content = $payload;
				} elseif ( is_array( $payload ) && isset( $payload['content'] ) ) {
					$content = (string) $payload['content'];
				} else {
					return new WP_Error(
						'ahentic_invalid_artifact_payload',
						__( 'html/markdown/post_content artifacts need a string or { "content": "…" }.', 'ahentic' )
					);
				}
				if ( '' === trim( $content ) ) {
					return new WP_Error( 'ahentic_invalid_artifact_payload', __( 'Artifact content cannot be empty.', 'ahentic' ) );
				}
				return array( 'content' => $content );
			}

			if ( self::KIND_IMAGE === $kind ) {
				if ( ! is_array( $payload ) ) {
					return new WP_Error(
						'ahentic_invalid_artifact_payload',
						__( 'image artifacts need { path, mime_type, width, height }.', 'ahentic' )
					);
				}
				$path = isset( $payload['path'] ) ? (string) $payload['path'] : '';
				$mime = isset( $payload['mime_type'] ) ? (string) $payload['mime_type'] : '';
				if ( '' === $path || '' === $mime ) {
					return new WP_Error(
						'ahentic_invalid_artifact_payload',
						__( 'image artifacts need path and mime_type.', 'ahentic' )
					);
				}
				return array(
					'path'      => $path,
					'mime_type' => $mime,
					'width'     => isset( $payload['width'] ) ? (int) $payload['width'] : 0,
					'height'    => isset( $payload['height'] ) ? (int) $payload['height'] : 0,
				);
			}

			if ( self::KIND_JSON === $kind ) {
				if ( null === $payload ) {
					return new WP_Error( 'ahentic_invalid_artifact_payload', __( 'json artifact payload is required.', 'ahentic' ) );
				}
				return $payload;
			}

			return new WP_Error( 'ahentic_invalid_artifact_kind', __( 'Invalid artifact kind.', 'ahentic' ) );
		}

		/**
		 * @param mixed $payload Payload.
		 * @return array|\WP_Error
		 */
		private static function payload_blocks( $payload ) {
			if ( ! is_array( $payload ) ) {
				return new WP_Error( 'artifact_kind_mismatch', __( 'Artifact payload is not blocks.', 'ahentic' ) );
			}
			if ( isset( $payload['blocks'] ) && is_array( $payload['blocks'] ) ) {
				return array_values( $payload['blocks'] );
			}
			if ( self::looks_like_block_list( $payload ) ) {
				return array_values( $payload );
			}
			return new WP_Error( 'artifact_kind_mismatch', __( 'Artifact has no blocks array.', 'ahentic' ) );
		}

		/**
		 * @param string $kind    Kind.
		 * @param mixed  $payload Payload.
		 * @return string|\WP_Error
		 */
		private static function payload_to_post_content( $kind, $payload ) {
			if ( self::KIND_BLOCKS === $kind ) {
				$blocks = self::payload_blocks( $payload );
				if ( is_wp_error( $blocks ) ) {
					return $blocks;
				}
				return self::serialize_agent_blocks( $blocks );
			}
			if ( in_array( $kind, array( self::KIND_HTML, self::KIND_MARKDOWN, self::KIND_POST_CONTENT ), true ) ) {
				if ( is_array( $payload ) && isset( $payload['content'] ) ) {
					return (string) $payload['content'];
				}
				if ( is_string( $payload ) ) {
					return $payload;
				}
			}
			return self::kind_mismatch( '', $kind, 'create-post/update-post', array( self::KIND_BLOCKS, self::KIND_HTML, self::KIND_MARKDOWN, self::KIND_POST_CONTENT ) );
		}

		/**
		 * Convert agent block objects to serialized block markup.
		 *
		 * @param array $blocks Blocks.
		 * @return string
		 */
		public static function serialize_agent_blocks( array $blocks ) {
			$wp_blocks = array();
			foreach ( $blocks as $block ) {
				$converted = self::agent_block_to_wp( $block );
				if ( $converted ) {
					$wp_blocks[] = $converted;
				}
			}
			if ( function_exists( 'serialize_blocks' ) ) {
				return serialize_blocks( $wp_blocks );
			}
			$out = '';
			foreach ( $wp_blocks as $block ) {
				if ( function_exists( 'serialize_block' ) ) {
					$out .= serialize_block( $block );
				}
			}
			return $out;
		}

		/**
		 * @param mixed $block Agent block.
		 * @return array|null
		 */
		private static function agent_block_to_wp( $block ) {
			if ( ! is_array( $block ) ) {
				return null;
			}
			$name = isset( $block['name'] ) ? (string) $block['name'] : ( isset( $block['blockName'] ) ? (string) $block['blockName'] : '' );
			if ( '' === $name ) {
				return null;
			}
			$attrs = array();
			if ( isset( $block['attributes'] ) && is_array( $block['attributes'] ) ) {
				$attrs = $block['attributes'];
			} elseif ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$attrs = $block['attrs'];
			}
			$inner_raw = array();
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$inner_raw = $block['innerBlocks'];
			}
			$inner = array();
			foreach ( $inner_raw as $child ) {
				$converted = self::agent_block_to_wp( $child );
				if ( $converted ) {
					$inner[] = $converted;
				}
			}
			$inner_content = array();
			if ( ! empty( $inner ) ) {
				foreach ( $inner as $i => $child ) {
					if ( $i > 0 ) {
						$inner_content[] = '';
					}
					$inner_content[] = null;
				}
			} else {
				$inner_content = array( '' );
			}
			return array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => $inner,
				'innerContent' => $inner_content,
			);
		}

		/**
		 * @param array $payload Normalized blocks payload.
		 * @return int
		 */
		private static function count_blocks( $payload ) {
			$blocks = self::payload_blocks( $payload );
			if ( is_wp_error( $blocks ) ) {
				return 0;
			}
			$count = 0;
			$walk  = static function ( $list ) use ( &$walk, &$count ) {
				foreach ( $list as $block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}
					++$count;
					if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
						$walk( $block['innerBlocks'] );
					}
				}
			};
			$walk( $blocks );
			return $count;
		}

		/**
		 * @param array $payload Blocks payload.
		 * @return string
		 */
		private static function guess_title_from_blocks( $payload ) {
			$blocks = self::payload_blocks( $payload );
			if ( is_wp_error( $blocks ) ) {
				return '';
			}
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$name = isset( $block['name'] ) ? (string) $block['name'] : '';
				$attrs = isset( $block['attributes'] ) && is_array( $block['attributes'] ) ? $block['attributes'] : array();
				if ( 'core/heading' === $name && ! empty( $attrs['content'] ) ) {
					$text = wp_strip_all_tags( (string) $attrs['content'] );
					return sanitize_text_field( self::excerpt( $text, 80 ) );
				}
			}
			return '';
		}

		/**
		 * @param array $list Candidate list.
		 * @return bool
		 */
		private static function looks_like_block_list( array $list ) {
			if ( empty( $list ) ) {
				return false;
			}
			$is_list = function_exists( 'array_is_list' )
				? array_is_list( $list )
				: ( array_keys( $list ) === range( 0, count( $list ) - 1 ) );
			if ( ! $is_list ) {
				return false;
			}
			$first = $list[0];
			return is_array( $first ) && ( isset( $first['name'] ) || isset( $first['blockName'] ) );
		}

		/**
		 * @param string   $key      Key.
		 * @param string   $kind     Kind.
		 * @param string   $ability  Ability.
		 * @param string[] $allowed  Allowed kinds.
		 * @return \WP_Error
		 */
		private static function kind_mismatch( $key, $kind, $ability, array $allowed ) {
			return new WP_Error(
				'artifact_kind_mismatch',
				sprintf(
					/* translators: 1: kind, 2: ability, 3: allowed kinds */
					__( 'Artifact kind “%1$s” cannot be applied to %2$s (allowed: %3$s).', 'ahentic' ),
					$kind ? $kind : 'unknown',
					$ability,
					implode( ', ', $allowed )
				),
				array(
					'key'     => $key,
					'kind'    => $kind,
					'ability' => $ability,
				)
			);
		}

		/**
		 * @param int   $session_id Session ID.
		 * @param array $store      Store.
		 * @return true|\WP_Error
		 */
		private static function persist( $session_id, array $store ) {
			$json = wp_json_encode( $store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) ) {
				return new WP_Error( 'ahentic_artifact_encode', __( 'Could not save artifacts.', 'ahentic' ) );
			}
			update_post_meta( $session_id, self::META_KEY, wp_slash( $json ) );
			return true;
		}

		/**
		 * @return int
		 */
		private static function current_session_id() {
			if ( class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'current_session_id' ) ) {
				return (int) Ahentic_Orchestrator::current_session_id();
			}
			return 0;
		}

		/**
		 * @param string $text Text.
		 * @param int    $max  Max length.
		 * @return string
		 */
		private static function excerpt( $text, $max = 80 ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
			if ( strlen( $text ) <= $max ) {
				return $text;
			}
			return rtrim( substr( $text, 0, $max - 1 ) ) . '…';
		}

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			$map = array(
				self::STAGE  => __( 'Staging session artifact…', 'ahentic' ),
				self::LIST   => __( 'Listing session artifacts…', 'ahentic' ),
				self::DELETE => __( 'Deleting session artifact…', 'ahentic' ),
			);
			$name = (string) $name;
			return isset( $map[ $name ] ) ? $map[ $name ] : '';
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Session_Artifacts', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Session_Artifacts', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Session_Artifacts' );
}
