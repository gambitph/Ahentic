<?php
/**
 * Media abilities: unused scan, describe/generate/upload, and Track E writes.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Media' ) ) {
	/**
	 * Media inspection and AI image helpers for the agent loop.
	 */
	class Ahentic_Abilities_Media {
		const FIND_UNUSED         = 'ahentic/find-unused-media';
		const DESCRIBE_IMAGE      = 'ahentic/describe-image';
		const GENERATE_IMAGE      = 'ahentic/generate-image';
		const UPLOAD_MEDIA        = 'ahentic/upload-media';
		const UPDATE_MEDIA        = 'ahentic/update-media';
		const SET_FEATURED_IMAGE  = 'ahentic/set-featured-image';
		const DELETE_MEDIA        = 'ahentic/delete-media';
		const REPLACE_MEDIA_FILE  = 'ahentic/replace-media-file';

		const MAX_SCAN   = 100;
		const MAX_REPORT = 50;

		const VISION_MIN_LONG_EDGE = 1024;
		const VISION_MAX_BYTES     = 10485760; // 10MB
		const DESCRIBE_RATE_MAX    = 10;
		const GENERATE_RATE_MAX    = 5;

		const META_DESCRIBE_COUNT  = '_ahentic_describe_image_count';
		const META_GENERATE_COUNT  = '_ahentic_generate_image_count';

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array(
				self::FIND_UNUSED,
				self::DESCRIBE_IMAGE,
				self::GENERATE_IMAGE,
				self::UPLOAD_MEDIA,
				self::UPDATE_MEDIA,
				self::SET_FEATURED_IMAGE,
				self::DELETE_MEDIA,
				self::REPLACE_MEDIA_FILE,
			);
		}

		/**
		 * Write (non-readonly) ability names.
		 *
		 * @return string[]
		 */
		public static function write_names() {
			return array(
				self::UPLOAD_MEDIA,
				self::UPDATE_MEDIA,
				self::SET_FEATURED_IMAGE,
				self::DELETE_MEDIA,
				self::REPLACE_MEDIA_FILE,
			);
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function is_readonly( $name ) {
			return ! in_array( (string) $name, self::write_names(), true );
		}

		/**
		 * Abilities that must pause for human approval before running.
		 *
		 * @return string[]
		 */
		public static function hitl_names() {
			return array(
				self::UPLOAD_MEDIA,
				self::UPDATE_MEDIA,
				self::SET_FEATURED_IMAGE,
				self::DELETE_MEDIA,
				self::REPLACE_MEDIA_FILE,
			);
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function requires_hitl( $name ) {
			return in_array( (string) $name, self::hitl_names(), true );
		}

		/**
		 * Irreversible writes that must never honor session/always allowlists.
		 *
		 * @return string[]
		 */
		public static function non_preallowable_names() {
			return array( self::REPLACE_MEDIA_FILE );
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function is_non_preallowable( $name ) {
			return in_array( (string) $name, self::non_preallowable_names(), true );
		}

		/**
		 * Human-readable summary for HITL UI.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function hitl_summary( $name, $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$name  = (string) $name;

			if ( self::UPLOAD_MEDIA === $name ) {
				if ( ! empty( $input['from_memory'] ) ) {
					return sprintf(
						/* translators: %s: artifact key */
						__( 'Upload staged image “%s” to the Media Library', 'ahentic' ),
						(string) $input['from_memory']
					);
				}
				if ( ! empty( $input['url'] ) ) {
					$host = wp_parse_url( (string) $input['url'], PHP_URL_HOST );
					return sprintf(
						/* translators: %s: hostname */
						__( 'Upload media from %s to the Media Library', 'ahentic' ),
						$host ? $host : __( 'URL', 'ahentic' )
					);
				}
				return __( 'Upload media to the Media Library', 'ahentic' );
			}

			if ( self::UPDATE_MEDIA === $name ) {
				$id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
				return sprintf(
					/* translators: %d: attachment ID */
					__( 'Update media metadata for attachment #%d', 'ahentic' ),
					$id
				);
			}

			if ( self::SET_FEATURED_IMAGE === $name ) {
				$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
				$att_id  = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
				return sprintf(
					/* translators: 1: post ID, 2: attachment ID */
					__( 'Set featured image of post #%1$d to attachment #%2$d', 'ahentic' ),
					$post_id,
					$att_id
				);
			}

			if ( self::DELETE_MEDIA === $name ) {
				$id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
				return sprintf(
					/* translators: %d: attachment ID */
					__( 'Move attachment #%d to the trash (quarantine, not permanent delete)', 'ahentic' ),
					$id
				);
			}

			if ( self::REPLACE_MEDIA_FILE === $name ) {
				$id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
				return sprintf(
					/* translators: %d: attachment ID */
					__( 'Replace the file for attachment #%d everywhere it is used site-wide. This cannot be undone — there is no undo.', 'ahentic' ),
					$id
				);
			}

			return $name;
		}

		/**
		 * Register the media ability category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			wp_register_ability_category(
				'ahentic-media',
				array(
					'label'       => __( 'Ahentic Media', 'ahentic' ),
					'description' => __( 'Inspect and generate media for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register media abilities.
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'upload_files' ) || current_user_can( 'manage_options' );
			};
			$meta       = array(
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'show_in_rest' => false,
			);

			wp_register_ability(
				self::FIND_UNUSED,
				array(
					'label'               => __( 'Find unused media', 'ahentic' ),
					'description'         => __( 'Scans image attachments and reports ones not found as featured images, site icon/logo, or in post/page content.', 'ahentic' ),
					'category'            => 'ahentic-media',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'scan_limit' => array(
								'type'        => 'integer',
								'description' => __( 'Max attachments to inspect (1–100).', 'ahentic' ),
							),
							'limit'      => array(
								'type'        => 'integer',
								'description' => __( 'Max unused items to return (1–50).', 'ahentic' ),
							),
							'mime_type'  => array(
								'type'        => 'string',
								'description' => __( 'MIME prefix to scan (default: image).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_find_unused_media' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::DESCRIBE_IMAGE,
				array(
					'label'               => __( 'Describe image', 'ahentic' ),
					'description'         => __( 'Uses AI vision to describe an attachment or image URL and suggest accessibility alt text. Readonly; does not write media.', 'ahentic' ),
					'category'            => 'ahentic-media',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'attachment_id' => array(
								'type'        => 'integer',
								'description' => __( 'Media library attachment ID (image/* only). Mutually exclusive with url.', 'ahentic' ),
							),
							'url'           => array(
								'type'        => 'string',
								'description' => __( 'http(s) image URL for the provider to fetch. Mutually exclusive with attachment_id.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_describe_image' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::GENERATE_IMAGE,
				array(
					'label'               => __( 'Generate image', 'ahentic' ),
					'description'         => __( 'Generates an image via AI and stages an image-kind session artifact (temp file pointer). Next: ahentic/upload-media with {"from_memory":"<artifact_key>"} to add it to the Media Library, then insert a core/image block (ahentic-browser/insert-blocks with index 0 for top of post) or set a featured image using the returned attachment_id/url. For post featured/inline/hero images prefer aspect_ratio 16:9 (or 4:3 if a less-wide crop is needed); use 9:16/3:4 only when the user explicitly wants tall/portrait; use 1:1 only for icons/avatars/logos/product thumbs. See playbook web-image-fit.', 'ahentic' ),
					'category'            => 'ahentic-media',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'prompt' ),
						'properties' => array(
							'prompt'       => array(
								'type'        => 'string',
								'description' => __( 'Image generation prompt (include style/mood in the text).', 'ahentic' ),
							),
							'aspect_ratio' => array(
								'type'        => 'string',
								'enum'        => array( '1:1', '16:9', '9:16', '4:3', '3:4' ),
								'description' => __( 'Output aspect ratio (default 16:9).', 'ahentic' ),
							),
							'artifact_key' => array(
								'type'        => 'string',
								'description' => __( 'Optional artifact key; auto-generated when omitted.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_generate_image' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::UPLOAD_MEDIA,
				array(
					'label'               => __( 'Upload media', 'ahentic' ),
					'description'         => __( 'Adds a file to the Media Library from a public URL or from a staged image artifact via from_memory (after generate-image). Returns attachment_id and url for insert-blocks / featured image. Requires human approval.', 'ahentic' ),
					'category'            => 'ahentic-media',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'url'          => array(
								'type'        => 'string',
								'description' => __( 'Public http(s) URL to sideload. Mutually exclusive with from_memory.', 'ahentic' ),
							),
							'from_memory'  => array(
								'type'        => 'string',
								'description' => __( 'Ready image-kind artifact key from generate-image. Mutually exclusive with url.', 'ahentic' ),
							),
							'title'        => array(
								'type'        => 'string',
								'description' => __( 'Optional attachment title.', 'ahentic' ),
							),
							'alt_text'     => array(
								'type'        => 'string',
								'description' => __( 'Optional alt text for the attachment.', 'ahentic' ),
							),
							'post_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Optional post to attach the media to (does not insert into content).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_upload_media' ),
					'permission_callback' => $permission,
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
						'show_in_rest' => false,
					),
				)
			);

			$write_meta = array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
			);

			wp_register_ability(
				self::UPDATE_MEDIA,
				array(
					'label'               => __( 'Update media', 'ahentic' ),
					'description'         => __( 'Updates attachment alt text, title, caption, and/or description. Snapshots prior values for undo-last-actions. Requires human approval.', 'ahentic' ),
					'category'            => 'ahentic-media',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'attachment_id' ),
						'properties' => array(
							'attachment_id' => array(
								'type'        => 'integer',
								'description' => __( 'Attachment ID to update.', 'ahentic' ),
							),
							'alt_text'      => array(
								'type'        => 'string',
								'description' => __( 'Image alt text (_wp_attachment_image_alt).', 'ahentic' ),
							),
							'title'         => array(
								'type'        => 'string',
								'description' => __( 'Attachment title (post_title).', 'ahentic' ),
							),
							'caption'       => array(
								'type'        => 'string',
								'description' => __( 'Caption (post_excerpt).', 'ahentic' ),
							),
							'description'   => array(
								'type'        => 'string',
								'description' => __( 'Description (post_content).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_update_media' ),
					'permission_callback' => $permission,
					'meta'                => $write_meta,
				)
			);

			wp_register_ability(
				self::SET_FEATURED_IMAGE,
				array(
					'label'               => __( 'Set featured image', 'ahentic' ),
					'description'         => __( 'Sets or clears a post’s featured image (thumbnail) when the block editor is not open for that post. Prefer ahentic-browser/set-featured-image while editing in Gutenberg. Snapshots the prior thumbnail for undo. Requires human approval.', 'ahentic' ),
					'category'            => 'ahentic-media',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id', 'attachment_id' ),
						'properties' => array(
							'post_id'       => array(
								'type'        => 'integer',
								'description' => __( 'Post ID to update.', 'ahentic' ),
							),
							'attachment_id' => array(
								'type'        => 'integer',
								'description' => __( 'Attachment ID to set as featured image. Pass 0 to clear.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_set_featured_image' ),
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' );
					},
					'meta'                => $write_meta,
				)
			);

			$destructive_meta = array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
			);

			wp_register_ability(
				self::DELETE_MEDIA,
				array(
					'label'               => __( 'Delete media', 'ahentic' ),
					'description'         => __( 'Moves an attachment to the trash (quarantine). Never permanently deletes files. Snapshots prior status for undo. Requires human approval.', 'ahentic' ),
					'category'            => 'ahentic-media',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'attachment_id' ),
						'properties' => array(
							'attachment_id' => array(
								'type'        => 'integer',
								'description' => __( 'Attachment ID to quarantine (trash).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_delete_media' ),
					'permission_callback' => $permission,
					'meta'                => $destructive_meta,
				)
			);

			wp_register_ability(
				self::REPLACE_MEDIA_FILE,
				array(
					'label'               => __( 'Replace media file', 'ahentic' ),
					'description'         => __( 'Rewrites an attachment’s file in place (URL or from_memory) and regenerates thumbnails. Changes the image everywhere it is used site-wide. Cannot be undone. Requires human approval every time (not preallowable).', 'ahentic' ),
					'category'            => 'ahentic-media',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'attachment_id' ),
						'properties' => array(
							'attachment_id' => array(
								'type'        => 'integer',
								'description' => __( 'Existing attachment whose file will be replaced.', 'ahentic' ),
							),
							'url'           => array(
								'type'        => 'string',
								'description' => __( 'Public http(s) URL to sideload as the new file. Mutually exclusive with from_memory.', 'ahentic' ),
							),
							'from_memory'   => array(
								'type'        => 'string',
								'description' => __( 'Ready image-kind artifact key. Mutually exclusive with url.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_replace_media_file' ),
					'permission_callback' => $permission,
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => true,
							'idempotent'  => false,
						),
						'show_in_rest' => false,
					),
				)
			);
		}

		/**
		 * Dispatch by ability name (fallback path).
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Input.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			switch ( $name ) {
				case self::FIND_UNUSED:
					return self::execute_find_unused_media( $input );
				case self::DESCRIBE_IMAGE:
					return self::execute_describe_image( $input );
				case self::GENERATE_IMAGE:
					return self::execute_generate_image( $input );
				case self::UPLOAD_MEDIA:
					return self::execute_upload_media( $input );
				case self::UPDATE_MEDIA:
					return self::execute_update_media( $input );
				case self::SET_FEATURED_IMAGE:
					return self::execute_set_featured_image( $input );
				case self::DELETE_MEDIA:
					return self::execute_delete_media( $input );
				case self::REPLACE_MEDIA_FILE:
					return self::execute_replace_media_file( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown media ability.', 'ahentic' ) );
			}
		}

		/**
		 * Wire snapshot restore callbacks for Track E writes.
		 */
		public static function boot_restore() {
			if ( ! class_exists( 'Ahentic_Settings_Snapshots' ) ) {
				return;
			}

			Ahentic_Settings_Snapshots::register_restore(
				self::UPDATE_MEDIA,
				array( __CLASS__, 'restore_update_media' )
			);
			Ahentic_Settings_Snapshots::register_restore(
				self::SET_FEATURED_IMAGE,
				array( __CLASS__, 'restore_set_featured_image' )
			);
			Ahentic_Settings_Snapshots::register_restore(
				self::DELETE_MEDIA,
				array( __CLASS__, 'restore_delete_media' )
			);
		}

		/**
		 * Restore prior attachment metadata from an update-media snapshot.
		 *
		 * @param array $entry Snapshot entry.
		 * @return true|\WP_Error
		 */
		public static function restore_update_media( array $entry ) {
			$id = (int) $entry['target'];
			$post = get_post( $id );
			if ( ! ( $post instanceof WP_Post ) || 'attachment' !== $post->post_type ) {
				return new WP_Error(
					'ahentic_undo_attachment_missing',
					__( 'Cannot undo media update: attachment no longer exists.', 'ahentic' )
				);
			}

			$prior = ( ! empty( $entry['prior_existed'] ) && array_key_exists( 'prior_value', $entry ) && is_array( $entry['prior_value'] ) )
				? $entry['prior_value']
				: array();

			$update = array( 'ID' => $id );
			if ( array_key_exists( 'title', $prior ) ) {
				$update['post_title'] = sanitize_text_field( (string) $prior['title'] );
			}
			if ( array_key_exists( 'caption', $prior ) ) {
				$update['post_excerpt'] = sanitize_textarea_field( (string) $prior['caption'] );
			}
			if ( array_key_exists( 'description', $prior ) ) {
				$update['post_content'] = wp_kses_post( (string) $prior['description'] );
			}
			if ( count( $update ) > 1 ) {
				$updated = wp_update_post( $update, true );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}
			if ( array_key_exists( 'alt_text', $prior ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $prior['alt_text'] ) );
			}

			return true;
		}

		/**
		 * Update attachment alt/title/caption/description with snapshot.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_update_media( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$id    = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
			if ( $id <= 0 ) {
				return new WP_Error( 'ahentic_missing_attachment_id', __( 'attachment_id is required.', 'ahentic' ) );
			}

			$post = get_post( $id );
			if ( ! ( $post instanceof WP_Post ) || 'attachment' !== $post->post_type ) {
				return new WP_Error( 'ahentic_attachment_not_found', __( 'Attachment not found.', 'ahentic' ) );
			}

			if ( ! current_user_can( 'edit_post', $id ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'ahentic_update_media_forbidden', __( 'You cannot edit this attachment.', 'ahentic' ) );
			}

			$has_alt  = array_key_exists( 'alt_text', $input );
			$has_title = array_key_exists( 'title', $input );
			$has_cap  = array_key_exists( 'caption', $input );
			$has_desc = array_key_exists( 'description', $input );
			if ( ! $has_alt && ! $has_title && ! $has_cap && ! $has_desc ) {
				return new WP_Error(
					'ahentic_update_media_empty',
					__( 'Provide at least one of alt_text, title, caption, or description.', 'ahentic' )
				);
			}

			$prior = array();
			if ( $has_alt ) {
				$prior['alt_text'] = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			}
			if ( $has_title ) {
				$prior['title'] = (string) $post->post_title;
			}
			if ( $has_cap ) {
				$prior['caption'] = (string) $post->post_excerpt;
			}
			if ( $has_desc ) {
				$prior['description'] = (string) $post->post_content;
			}

			$session_id = 0;
			if ( class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'current_session_id' ) ) {
				$session_id = (int) Ahentic_Orchestrator::current_session_id();
			}
			if ( $session_id && class_exists( 'Ahentic_Settings_Snapshots' ) ) {
				Ahentic_Settings_Snapshots::record(
					$session_id,
					array(
						'ability'       => self::UPDATE_MEDIA,
						'target'        => $id,
						'prior_existed' => true,
						'prior_value'   => $prior,
					)
				);
			}

			$update = array( 'ID' => $id );
			if ( $has_title ) {
				$update['post_title'] = sanitize_text_field( (string) $input['title'] );
			}
			if ( $has_cap ) {
				$update['post_excerpt'] = sanitize_textarea_field( (string) $input['caption'] );
			}
			if ( $has_desc ) {
				$update['post_content'] = wp_kses_post( (string) $input['description'] );
			}
			if ( count( $update ) > 1 ) {
				$result = wp_update_post( $update, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			if ( $has_alt ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
			}

			$fresh = get_post( $id );
			return array(
				'ok'            => true,
				'attachment_id' => $id,
				'alt_text'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'title'         => $fresh ? (string) $fresh->post_title : '',
				'caption'       => $fresh ? (string) $fresh->post_excerpt : '',
				'description'   => $fresh ? (string) $fresh->post_content : '',
			);
		}

		/**
		 * Restore prior featured image from a set-featured-image snapshot.
		 *
		 * @param array $entry Snapshot entry.
		 * @return true|\WP_Error
		 */
		public static function restore_set_featured_image( array $entry ) {
			$post_id = (int) $entry['target'];
			$post    = get_post( $post_id );
			if ( ! ( $post instanceof WP_Post ) ) {
				return new WP_Error(
					'ahentic_undo_post_missing',
					__( 'Cannot undo featured image: post no longer exists.', 'ahentic' )
				);
			}

			if ( empty( $entry['prior_existed'] ) ) {
				delete_post_thumbnail( $post_id );
				return true;
			}

			$prior_id = array_key_exists( 'prior_value', $entry ) ? (int) $entry['prior_value'] : 0;
			if ( $prior_id <= 0 ) {
				delete_post_thumbnail( $post_id );
				return true;
			}

			$result = set_post_thumbnail( $post_id, $prior_id );
			if ( ! $result ) {
				return new WP_Error(
					'ahentic_undo_featured_failed',
					__( 'Could not restore the prior featured image.', 'ahentic' )
				);
			}
			return true;
		}

		/**
		 * Set or clear a post featured image (server path when editor closed).
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_set_featured_image( $input = array() ) {
			$input         = is_array( $input ) ? $input : array();
			$post_id       = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : -1;

			if ( $post_id <= 0 ) {
				return new WP_Error( 'ahentic_missing_post_id', __( 'post_id is required.', 'ahentic' ) );
			}
			if ( $attachment_id < 0 ) {
				return new WP_Error( 'ahentic_missing_attachment_id', __( 'attachment_id is required (use 0 to clear).', 'ahentic' ) );
			}

			$post = get_post( $post_id );
			if ( ! ( $post instanceof WP_Post ) ) {
				return new WP_Error( 'ahentic_post_not_found', __( 'Post not found.', 'ahentic' ) );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'ahentic_featured_forbidden', __( 'You cannot edit this post.', 'ahentic' ) );
			}

			$editor_gate = self::reject_featured_while_editor_open( $post_id );
			if ( is_wp_error( $editor_gate ) ) {
				return $editor_gate;
			}

			if ( $attachment_id > 0 ) {
				$att = get_post( $attachment_id );
				if ( ! ( $att instanceof WP_Post ) || 'attachment' !== $att->post_type ) {
					return new WP_Error( 'ahentic_attachment_not_found', __( 'Attachment not found.', 'ahentic' ) );
				}
			}

			$prior_id      = (int) get_post_thumbnail_id( $post_id );
			$prior_existed = $prior_id > 0;

			$session_id = 0;
			if ( class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'current_session_id' ) ) {
				$session_id = (int) Ahentic_Orchestrator::current_session_id();
			}
			if ( $session_id && class_exists( 'Ahentic_Settings_Snapshots' ) ) {
				Ahentic_Settings_Snapshots::record(
					$session_id,
					array(
						'ability'       => self::SET_FEATURED_IMAGE,
						'target'        => $post_id,
						'prior_existed' => $prior_existed,
						'prior_value'   => $prior_existed ? $prior_id : null,
					)
				);
			}

			if ( $attachment_id <= 0 ) {
				delete_post_thumbnail( $post_id );
			} else {
				$result = set_post_thumbnail( $post_id, $attachment_id );
				if ( ! $result ) {
					return new WP_Error(
						'ahentic_set_featured_failed',
						__( 'Could not set the featured image.', 'ahentic' )
					);
				}
			}

			$current = (int) get_post_thumbnail_id( $post_id );
			return array(
				'ok'            => true,
				'post_id'       => $post_id,
				'attachment_id' => $current,
				'cleared'       => 0 === $current,
				'prior_id'      => $prior_existed ? $prior_id : 0,
			);
		}

		/**
		 * Prefer browser twin when the block editor is open for this post.
		 *
		 * @param int $post_id Post ID.
		 * @return true|\WP_Error
		 */
		private static function reject_featured_while_editor_open( $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 || ! class_exists( 'Ahentic_Orchestrator' ) || ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return true;
			}

			$session_id = (int) Ahentic_Orchestrator::current_session_id();
			if ( $session_id <= 0 ) {
				return true;
			}

			$ctx = Ahentic_Session_Repository::get_page_context( $session_id );
			if ( empty( $ctx ) || empty( $ctx['is_block_editor'] ) ) {
				return true;
			}

			$open_id = isset( $ctx['post_id'] ) ? (int) $ctx['post_id'] : 0;
			if ( $open_id <= 0 || $open_id !== $post_id ) {
				return true;
			}

			return new WP_Error(
				'ahentic_use_browser_featured_image',
				__(
					'The block editor is open for this post. Use ahentic-browser/set-featured-image so the document panel updates live — do not use the server ahentic/set-featured-image while editing.',
					'ahentic'
				),
				array(
					'post_id'        => $post_id,
					'editor_post_id' => $open_id,
					'hint'           => __( 'Call ahentic-browser/set-featured-image with attachment_id (0 to clear).', 'ahentic' ),
				)
			);
		}

		/**
		 * Restore a quarantined attachment via wp_untrash_post.
		 *
		 * @param array $entry Snapshot entry.
		 * @return true|\WP_Error
		 */
		public static function restore_delete_media( array $entry ) {
			$id   = (int) $entry['target'];
			$post = get_post( $id );
			if ( ! ( $post instanceof WP_Post ) || 'attachment' !== $post->post_type ) {
				return new WP_Error(
					'ahentic_undo_attachment_missing',
					__( 'Cannot undo media delete: attachment no longer exists.', 'ahentic' )
				);
			}

			if ( 'trash' !== $post->post_status ) {
				return true;
			}

			$result = wp_untrash_post( $id );
			if ( ! $result || is_wp_error( $result ) ) {
				return is_wp_error( $result )
					? $result
					: new WP_Error(
						'ahentic_undo_untrash_failed',
						__( 'Could not restore the attachment from trash.', 'ahentic' )
					);
			}
			return true;
		}

		/**
		 * Quarantine attachment via trash — never force-delete from disk.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_delete_media( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$id    = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
			if ( $id <= 0 ) {
				return new WP_Error( 'ahentic_missing_attachment_id', __( 'attachment_id is required.', 'ahentic' ) );
			}

			$post = get_post( $id );
			if ( ! ( $post instanceof WP_Post ) || 'attachment' !== $post->post_type ) {
				return new WP_Error( 'ahentic_attachment_not_found', __( 'Attachment not found.', 'ahentic' ) );
			}

			if ( ! current_user_can( 'delete_post', $id ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'ahentic_delete_media_forbidden', __( 'You cannot delete this attachment.', 'ahentic' ) );
			}

			if ( 'trash' === $post->post_status ) {
				return array(
					'ok'            => true,
					'attachment_id' => $id,
					'status'        => 'trash',
					'already_trash' => true,
				);
			}

			$prior_status = (string) $post->post_status;
			$file         = get_attached_file( $id );
			$file_exists  = is_string( $file ) && $file && file_exists( $file );

			$session_id = 0;
			if ( class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'current_session_id' ) ) {
				$session_id = (int) Ahentic_Orchestrator::current_session_id();
			}
			if ( $session_id && class_exists( 'Ahentic_Settings_Snapshots' ) ) {
				Ahentic_Settings_Snapshots::record(
					$session_id,
					array(
						'ability'       => self::DELETE_MEDIA,
						'target'        => $id,
						'prior_existed' => true,
						'prior_value'   => array(
							'status' => $prior_status,
						),
					)
				);
			}

			// Always quarantine via trash — never wp_delete_attachment( …, true ).
			// MEDIA_TRASH defaults to false in core; wp_trash_post still works.
			$result = wp_trash_post( $id );
			if ( ! $result || is_wp_error( $result ) ) {
				return is_wp_error( $result )
					? $result
					: new WP_Error(
						'ahentic_delete_media_failed',
						__( 'Could not move the attachment to trash.', 'ahentic' )
					);
			}

			$fresh      = get_post( $id );
			$file_after = get_attached_file( $id );
			return array(
				'ok'            => true,
				'attachment_id' => $id,
				'status'        => $fresh ? (string) $fresh->post_status : 'trash',
				'prior_status'  => $prior_status,
				'file_exists'   => is_string( $file_after ) && $file_after && file_exists( $file_after ),
				'had_file'      => $file_exists,
				'hint'          => __( 'Attachment was quarantined (trashed), not permanently deleted. Use undo-last-actions to restore.', 'ahentic' ),
			);
		}

		/**
		 * Replace an attachment file in place. No snapshot / no undo (ADR-0007 exempt).
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_replace_media_file( $input = array() ) {
			$input = is_array( $input ) ? $input : array();

			if ( ! empty( $input['from_memory'] ) && empty( $input['source_path'] ) && class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$session_id = class_exists( 'Ahentic_Orchestrator' ) ? Ahentic_Orchestrator::current_session_id() : 0;
				$resolved   = Ahentic_Session_Artifacts::apply_from_memory( $session_id, self::REPLACE_MEDIA_FILE, $input );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
				$input = isset( $resolved['input'] ) && is_array( $resolved['input'] ) ? $resolved['input'] : $input;
			}

			$id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
			if ( $id <= 0 ) {
				return new WP_Error( 'ahentic_missing_attachment_id', __( 'attachment_id is required.', 'ahentic' ) );
			}

			$post = get_post( $id );
			if ( ! ( $post instanceof WP_Post ) || 'attachment' !== $post->post_type ) {
				return new WP_Error( 'ahentic_attachment_not_found', __( 'Attachment not found.', 'ahentic' ) );
			}

			if ( ! current_user_can( 'edit_post', $id ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'ahentic_replace_media_forbidden', __( 'You cannot replace this attachment.', 'ahentic' ) );
			}

			$prepared = self::prepare_sideload_file_array( $input );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$file_array   = $prepared['file_array'];
			$tmp_to_clean = $prepared['tmp_to_clean'];

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$sideloaded = wp_handle_sideload(
				$file_array,
				array(
					'test_form' => false,
				)
			);

			if ( ! empty( $sideloaded['error'] ) ) {
				if ( $tmp_to_clean && file_exists( $tmp_to_clean ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $tmp_to_clean );
				}
				return new WP_Error(
					'ahentic_replace_sideload_failed',
					sprintf(
						/* translators: %s: sideload error */
						__( 'Media replace failed: %s', 'ahentic' ),
						(string) $sideloaded['error']
					)
				);
			}

			$new_file = isset( $sideloaded['file'] ) ? (string) $sideloaded['file'] : '';
			$new_type = isset( $sideloaded['type'] ) ? (string) $sideloaded['type'] : '';
			if ( '' === $new_file || ! file_exists( $new_file ) ) {
				return new WP_Error( 'ahentic_replace_sideload_failed', __( 'Sideload did not produce a file.', 'ahentic' ) );
			}

			$old_file = get_attached_file( $id );
			$old_meta = wp_get_attachment_metadata( $id );
			$old_meta = is_array( $old_meta ) ? $old_meta : array();

			// Remove prior intermediate sizes so regenerated thumbs don't leave orphans.
			if ( $old_file && ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ) {
				$dir = dirname( $old_file );
				foreach ( $old_meta['sizes'] as $size ) {
					if ( empty( $size['file'] ) ) {
						continue;
					}
					$thumb = trailingslashit( $dir ) . $size['file'];
					if ( file_exists( $thumb ) && $thumb !== $new_file ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						@unlink( $thumb );
					}
				}
			}

			$dest = $old_file;
			if ( is_string( $dest ) && '' !== $dest ) {
				$dir    = dirname( $dest );
				$target = $dest; // Always rewrite the existing attached path (stable URL).
				if ( $new_file !== $target ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$moved = @rename( $new_file, $target );
					if ( ! $moved ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$moved = @copy( $new_file, $target );
						if ( $moved ) {
							// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							@unlink( $new_file );
						}
					}
					if ( ! $moved ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						@unlink( $new_file );
						return new WP_Error( 'ahentic_replace_move_failed', __( 'Could not move the new file into the uploads directory.', 'ahentic' ) );
					}
					$new_file = $target;
				}
			} else {
				// No prior file — keep the sideloaded path.
				$dest = $new_file;
			}

			update_attached_file( $id, $new_file );

			$update = array( 'ID' => $id );
			if ( '' !== $new_type ) {
				$update['post_mime_type'] = $new_type;
			}
			wp_update_post( $update );

			$meta = wp_generate_attachment_metadata( $id, $new_file );
			if ( is_array( $meta ) ) {
				wp_update_attachment_metadata( $id, $meta );
			}

			// No Ahentic_Settings_Snapshots::record — ADR-0007 exempt; irreversible.

			$url_out = wp_get_attachment_url( $id );
			return array(
				'ok'            => true,
				'attachment_id' => $id,
				'url'           => $url_out ? $url_out : '',
				'mime_type'     => $new_type ? $new_type : (string) get_post_mime_type( $id ),
				'width'         => is_array( $meta ) && ! empty( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height'        => is_array( $meta ) && ! empty( $meta['height'] ) ? (int) $meta['height'] : 0,
				'hint'          => __( 'File replaced site-wide. This change cannot be undone.', 'ahentic' ),
			);
		}

		/**
		 * Build a file_array for wp_handle_sideload / media_handle_sideload from url or source_path.
		 *
		 * @param array $input Ability input (url XOR source_path).
		 * @return array{file_array: array, tmp_to_clean: string}|\WP_Error
		 */
		private static function prepare_sideload_file_array( array $input ) {
			$has_url  = isset( $input['url'] ) && is_string( $input['url'] ) && '' !== trim( $input['url'] );
			$has_path = isset( $input['source_path'] ) && is_string( $input['source_path'] ) && '' !== trim( $input['source_path'] );

			if ( $has_url === $has_path ) {
				return new WP_Error(
					'ahentic_upload_media_input',
					__( 'Provide exactly one of url or from_memory (image artifact).', 'ahentic' ),
					array(
						'hint' => __( 'After generate-image, call with {"from_memory":"<artifact_key>"}.', 'ahentic' ),
					)
				);
			}

			if ( ! current_user_can( 'upload_files' ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'ahentic_upload_forbidden', __( 'You cannot upload files.', 'ahentic' ) );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';

			$tmp_to_clean = '';
			$file_array   = array();

			if ( $has_path ) {
				$source = (string) $input['source_path'];
				if ( ! file_exists( $source ) || ! is_readable( $source ) ) {
					return new WP_Error( 'ahentic_upload_file_missing', __( 'Staged image file is missing on disk.', 'ahentic' ) );
				}

				$temp_root = trailingslashit( function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir() ) . 'ahentic-images';
				$real_src  = realpath( $source );
				$real_root = realpath( $temp_root );
				if ( ! $real_src || ! $real_root || 0 !== strpos( $real_src, $real_root ) ) {
					return new WP_Error(
						'ahentic_upload_path_denied',
						__( 'from_memory uploads must use a staged Ahentic image artifact path.', 'ahentic' )
					);
				}

				$basename = isset( $input['filename'] ) && is_string( $input['filename'] ) && '' !== $input['filename']
					? sanitize_file_name( $input['filename'] )
					: sanitize_file_name( basename( $real_src ) );
				if ( '' === $basename ) {
					$basename = 'ahentic-image.png';
				}

				$tmp_to_clean = wp_tempnam( $basename );
				if ( ! $tmp_to_clean || ! @copy( $real_src, $tmp_to_clean ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return new WP_Error( 'ahentic_upload_copy_failed', __( 'Could not prepare the staged image for upload.', 'ahentic' ) );
				}

				$file_array = array(
					'name'     => $basename,
					'tmp_name' => $tmp_to_clean,
				);
			} else {
				$url = esc_url_raw( trim( (string) $input['url'] ) );
				if ( ! preg_match( '#^https?://#i', $url ) ) {
					return new WP_Error( 'ahentic_invalid_media_url', __( 'url must be an http:// or https:// address.', 'ahentic' ) );
				}

				$parts = wp_parse_url( $url );
				$host  = isset( $parts['host'] ) ? (string) $parts['host'] : '';
				if ( class_exists( 'Ahentic_Abilities_Site' ) && ! Ahentic_Abilities_Site::host_is_publicly_fetchable( $host ) ) {
					return new WP_Error(
						'ahentic_upload_host_blocked',
						__( 'That URL host is not allowed for media uploads.', 'ahentic' )
					);
				}

				$tmp = download_url( $url, 60 );
				if ( is_wp_error( $tmp ) ) {
					return new WP_Error(
						'ahentic_upload_download_failed',
						sprintf(
							/* translators: %s: download error */
							__( 'Could not download media: %s', 'ahentic' ),
							$tmp->get_error_message()
						)
					);
				}
				$tmp_to_clean = $tmp;
				$name         = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				$name         = $name ? sanitize_file_name( $name ) : 'sideload-media';
				if ( ! pathinfo( $name, PATHINFO_EXTENSION ) ) {
					$name .= '.jpg';
				}
				$file_array = array(
					'name'     => $name,
					'tmp_name' => $tmp,
				);
			}

			return array(
				'file_array'   => $file_array,
				'tmp_to_clean' => $tmp_to_clean,
			);
		}

		/**
		 * Pick the smallest registered size with long edge ≥ 1024px, else full.
		 *
		 * @param array $meta Attachment metadata from wp_get_attachment_metadata().
		 * @return array{ size: string, file: string }
		 */
		public static function pick_vision_attachment_size( array $meta ) {
			$sizes = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
			$best  = null;
			$best_edge = null;

			foreach ( $sizes as $name => $size ) {
				if ( ! is_array( $size ) ) {
					continue;
				}
				$w = isset( $size['width'] ) ? (int) $size['width'] : 0;
				$h = isset( $size['height'] ) ? (int) $size['height'] : 0;
				$edge = max( $w, $h );
				if ( $edge < self::VISION_MIN_LONG_EDGE ) {
					continue;
				}
				if ( null === $best_edge || $edge < $best_edge ) {
					$best_edge = $edge;
					$best      = array(
						'size' => (string) $name,
						'file' => isset( $size['file'] ) ? (string) $size['file'] : '',
					);
				}
			}

			if ( null !== $best ) {
				return $best;
			}

			return array(
				'size' => 'full',
				'file' => '',
			);
		}

		/**
		 * @param int    $session_id Session.
		 * @param string $meta_key   Counter meta.
		 * @param int    $max        Max allowed.
		 * @param string $error_code Error code when exceeded.
		 * @return true|\WP_Error
		 */
		public static function enforce_session_rate_limit( $session_id, $meta_key, $max, $error_code ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return true;
			}
			$count = (int) get_post_meta( $session_id, $meta_key, true );
			if ( $count >= (int) $max ) {
				return new WP_Error(
					$error_code,
					sprintf(
						/* translators: %d: max calls */
						__( 'Rate limit reached for this session (max %d). Start a new session or continue without this ability.', 'ahentic' ),
						(int) $max
					)
				);
			}
			update_post_meta( $session_id, $meta_key, $count + 1 );
			return true;
		}

		/**
		 * Describe an image via AI vision.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_describe_image( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$has_id  = isset( $input['attachment_id'] ) && (int) $input['attachment_id'] > 0;
			$has_url = isset( $input['url'] ) && is_string( $input['url'] ) && '' !== trim( $input['url'] );

			if ( $has_id === $has_url ) {
				return new WP_Error(
					'ahentic_describe_image_input',
					__( 'Provide exactly one of attachment_id or url.', 'ahentic' )
				);
			}

			$session_id = class_exists( 'Ahentic_Orchestrator' ) ? Ahentic_Orchestrator::current_session_id() : 0;
			$rate       = self::enforce_session_rate_limit(
				$session_id,
				self::META_DESCRIBE_COUNT,
				self::DESCRIBE_RATE_MAX,
				'ahentic_describe_image_rate_limited'
			);
			if ( is_wp_error( $rate ) ) {
				return $rate;
			}

			$file_ref   = null;
			$mime_type  = '';
			$source     = $has_id ? 'attachment' : 'url';
			$attachment_id = $has_id ? (int) $input['attachment_id'] : 0;
			$url           = $has_url ? trim( (string) $input['url'] ) : '';
			$resolved_size = 'full';

			if ( $has_id ) {
				$post = get_post( $attachment_id );
				if ( ! ( $post instanceof WP_Post ) || 'attachment' !== $post->post_type ) {
					return new WP_Error( 'ahentic_attachment_not_found', __( 'Attachment not found.', 'ahentic' ) );
				}
				$mime_type = (string) $post->post_mime_type;
				if ( 0 !== strpos( $mime_type, 'image/' ) ) {
					return new WP_Error(
						'ahentic_not_an_image',
						__( 'describe-image only supports image attachments.', 'ahentic' )
					);
				}

				$meta  = wp_get_attachment_metadata( $attachment_id );
				$meta  = is_array( $meta ) ? $meta : array();
				$picked = self::pick_vision_attachment_size( $meta );
				$resolved_size = $picked['size'];
				$attached      = get_attached_file( $attachment_id );
				if ( ! is_string( $attached ) || '' === $attached || ! file_exists( $attached ) ) {
					return new WP_Error( 'ahentic_attachment_file_missing', __( 'Attachment file is missing on disk.', 'ahentic' ) );
				}

				if ( 'full' === $picked['size'] || '' === $picked['file'] ) {
					$file_ref = $attached;
				} else {
					$file_ref = trailingslashit( dirname( $attached ) ) . $picked['file'];
					if ( ! file_exists( $file_ref ) ) {
						$file_ref      = $attached;
						$resolved_size = 'full';
					}
				}

				$size = @filesize( $file_ref ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $size && $size > self::VISION_MAX_BYTES ) {
					return new WP_Error(
						'ahentic_image_too_large',
						__( 'Image file exceeds the 10MB limit for describe-image.', 'ahentic' )
					);
				}
			} else {
				if ( ! preg_match( '#^https?://#i', $url ) ) {
					return new WP_Error(
						'ahentic_invalid_image_url',
						__( 'url must be an http:// or https:// address.', 'ahentic' )
					);
				}
				$file_ref  = $url;
				$mime_type = 'image/jpeg';
			}

			if ( ! class_exists( 'Ahentic_AI' ) ) {
				return new WP_Error( 'ahentic_ai_unavailable', __( 'AI client is not available.', 'ahentic' ) );
			}

			$result = Ahentic_AI::describe_image( $file_ref, $mime_type );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$out = array(
				'ok'                   => true,
				'source'               => $source,
				'mime_type'            => $mime_type,
				'resolved_size'        => $resolved_size,
				'description'          => isset( $result['description'] ) ? (string) $result['description'] : '',
				'alt_text_suggestion'  => isset( $result['alt_text_suggestion'] ) ? (string) $result['alt_text_suggestion'] : '',
			);
			if ( $attachment_id > 0 ) {
				$out['attachment_id'] = $attachment_id;
			}
			if ( '' !== $url ) {
				$out['url'] = $url;
			}
			return $out;
		}

		/**
		 * Generate an image and stage an image-kind artifact.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_generate_image( $input = array() ) {
			$input  = is_array( $input ) ? $input : array();
			$prompt = isset( $input['prompt'] ) ? trim( (string) $input['prompt'] ) : '';
			if ( '' === $prompt ) {
				return new WP_Error( 'ahentic_missing_prompt', __( 'A prompt is required.', 'ahentic' ) );
			}

			$aspect = isset( $input['aspect_ratio'] ) ? (string) $input['aspect_ratio'] : '16:9';
			if ( ! in_array( $aspect, array( '1:1', '16:9', '9:16', '4:3', '3:4' ), true ) ) {
				$aspect = '16:9';
			}

			$session_id = class_exists( 'Ahentic_Orchestrator' ) ? Ahentic_Orchestrator::current_session_id() : 0;
			if ( $session_id <= 0 ) {
				return new WP_Error(
					'ahentic_no_session',
					__( 'generate-image requires an active Ahentic session to stage the artifact.', 'ahentic' )
				);
			}

			$rate = self::enforce_session_rate_limit(
				$session_id,
				self::META_GENERATE_COUNT,
				self::GENERATE_RATE_MAX,
				'ahentic_generate_image_rate_limited'
			);
			if ( is_wp_error( $rate ) ) {
				return $rate;
			}

			if ( ! class_exists( 'Ahentic_AI' ) ) {
				return new WP_Error( 'ahentic_ai_unavailable', __( 'AI client is not available.', 'ahentic' ) );
			}

			$generated = Ahentic_AI::generate_image( $prompt, $aspect );
			if ( is_wp_error( $generated ) ) {
				return $generated;
			}

			$data_uri  = isset( $generated['data_uri'] ) ? (string) $generated['data_uri'] : '';
			$mime_type = isset( $generated['mime_type'] ) ? (string) $generated['mime_type'] : 'image/png';
			$width     = isset( $generated['width'] ) ? (int) $generated['width'] : 0;
			$height    = isset( $generated['height'] ) ? (int) $generated['height'] : 0;

			$binary = self::decode_data_uri( $data_uri );
			if ( is_wp_error( $binary ) ) {
				return $binary;
			}

			$dir = Ahentic_Session_Artifacts::image_temp_dir();
			$ext = ( false !== strpos( $mime_type, 'jpeg' ) || false !== strpos( $mime_type, 'jpg' ) ) ? 'jpg' : 'png';
			if ( false !== strpos( $mime_type, 'webp' ) ) {
				$ext = 'webp';
			}
			$filename = 'gen-' . wp_generate_password( 12, false, false ) . '.' . $ext;
			$path     = trailingslashit( $dir ) . $filename;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $path, $binary );
			if ( false === $written ) {
				return new WP_Error( 'ahentic_image_write_failed', __( 'Could not write generated image to temp storage.', 'ahentic' ) );
			}

			$key = isset( $input['artifact_key'] ) ? (string) $input['artifact_key'] : '';
			if ( '' === $key && class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$key = 'image_' . wp_generate_password( 8, false, false );
			}
			if ( class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$key = Ahentic_Session_Artifacts::sanitize_artifact_key( $key );
			}

			$staged = Ahentic_Session_Artifacts::stage(
				$session_id,
				$key,
				array(
					'kind'    => Ahentic_Session_Artifacts::KIND_IMAGE,
					'payload' => array(
						'path'      => $path,
						'mime_type' => $mime_type,
						'width'     => $width,
						'height'    => $height,
					),
					'status'  => Ahentic_Session_Artifacts::STATUS_READY,
					'title'   => self::truncate_prompt_title( $prompt ),
					'meta'    => array(
						'prompt'       => $prompt,
						'aspect_ratio' => $aspect,
					),
				)
			);
			if ( is_wp_error( $staged ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $path );
				return $staged;
			}

			$model_facing = array(
				'ok'           => true,
				'artifact_key' => $key,
				'mime_type'    => $mime_type,
				'width'        => $width,
				'height'       => $height,
				'aspect_ratio' => $aspect,
				'prompt'       => $prompt,
			);

			/**
			 * Browser/REST one-shot may include data_uri; transcript should not.
			 * Filter returns the model-facing payload by default; REST can re-add data_uri.
			 *
			 * @param array $model_facing Model/transcript payload.
			 * @param array $extra        Extra fields (data_uri).
			 */
			$with_preview = apply_filters(
				'ahentic_generate_image_result',
				$model_facing,
				array(
					'data_uri' => $data_uri,
					'path'     => $path,
				)
			);

			return is_array( $with_preview ) ? $with_preview : $model_facing;
		}

		/**
		 * Upload media from a public URL or a local path expanded from from_memory.
		 *
		 * @param mixed $input Input (url and/or source_path after from_memory expand).
		 * @return array|\WP_Error
		 */
		public static function execute_upload_media( $input = array() ) {
			$input = is_array( $input ) ? $input : array();

			// Tool runner expands from_memory before execute; self-expand for direct/e2e calls.
			if ( ! empty( $input['from_memory'] ) && empty( $input['source_path'] ) && class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$session_id = class_exists( 'Ahentic_Orchestrator' ) ? Ahentic_Orchestrator::current_session_id() : 0;
				$resolved   = Ahentic_Session_Artifacts::apply_from_memory( $session_id, self::UPLOAD_MEDIA, $input );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
				$input = isset( $resolved['input'] ) && is_array( $resolved['input'] ) ? $resolved['input'] : $input;
			}

			$prepared = self::prepare_sideload_file_array( $input );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$file_array   = $prepared['file_array'];
			$tmp_to_clean = $prepared['tmp_to_clean'];

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			$desc    = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';

			$attachment_id = media_handle_sideload( $file_array, $post_id > 0 ? $post_id : 0, $desc ? $desc : null );
			// media_handle_sideload moves/unlinks tmp_name on success; clean only on failure.
			if ( is_wp_error( $attachment_id ) ) {
				if ( $tmp_to_clean && file_exists( $tmp_to_clean ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $tmp_to_clean );
				}
				return new WP_Error(
					'ahentic_upload_sideload_failed',
					sprintf(
						/* translators: %s: sideload error */
						__( 'Media upload failed: %s', 'ahentic' ),
						$attachment_id->get_error_message()
					)
				);
			}

			$attachment_id = (int) $attachment_id;
			if ( ! empty( $input['alt_text'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
			}
			if ( $desc && get_the_title( $attachment_id ) !== $desc ) {
				wp_update_post(
					array(
						'ID'         => $attachment_id,
						'post_title' => $desc,
					)
				);
			}

			$url_out = wp_get_attachment_url( $attachment_id );
			$mime    = get_post_mime_type( $attachment_id );
			$meta    = wp_get_attachment_metadata( $attachment_id );

			return array(
				'ok'            => true,
				'attachment_id' => $attachment_id,
				'url'           => $url_out ? $url_out : '',
				'mime_type'     => $mime ? $mime : '',
				'width'         => is_array( $meta ) && ! empty( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height'        => is_array( $meta ) && ! empty( $meta['height'] ) ? (int) $meta['height'] : 0,
				'edit_url'      => get_edit_post_link( $attachment_id, 'raw' ) ? get_edit_post_link( $attachment_id, 'raw' ) : '',
				'hint'          => __( 'Insert into the open editor with ahentic-browser/insert-blocks using a core/image block { attributes: { id, url, alt } } and index 0 for the top of the post.', 'ahentic' ),
			);
		}

		/**
		 * @param string $data_uri Data URI.
		 * @return string|\WP_Error Binary
		 */
		private static function decode_data_uri( $data_uri ) {
			$data_uri = (string) $data_uri;
			if ( '' === $data_uri ) {
				return new WP_Error( 'ahentic_empty_image', __( 'Generated image had no data.', 'ahentic' ) );
			}
			if ( preg_match( '#^data:[^;]+;base64,(.+)$#', $data_uri, $m ) ) {
				$decoded = base64_decode( $m[1], true );
				if ( false === $decoded ) {
					return new WP_Error( 'ahentic_bad_image_data', __( 'Could not decode generated image data.', 'ahentic' ) );
				}
				return $decoded;
			}
			// Already raw base64?
			$decoded = base64_decode( $data_uri, true );
			if ( false !== $decoded && '' !== $decoded ) {
				return $decoded;
			}
			return new WP_Error( 'ahentic_bad_image_data', __( 'Unrecognized generated image format.', 'ahentic' ) );
		}

		/**
		 * @param string $prompt Prompt.
		 * @return string
		 */
		private static function truncate_prompt_title( $prompt ) {
			$prompt = trim( (string) $prompt );
			if ( strlen( $prompt ) <= 60 ) {
				return $prompt;
			}
			return substr( $prompt, 0, 57 ) . '…';
		}

		/**
		 * Find image attachments that appear unused.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_find_unused_media( $input = array() ) {
			$input = is_array( $input ) ? $input : array();

			$scan_limit = isset( $input['scan_limit'] ) ? (int) $input['scan_limit'] : 50;
			$limit      = isset( $input['limit'] ) ? (int) $input['limit'] : 25;
			$scan_limit = max( 1, min( self::MAX_SCAN, $scan_limit ) );
			$limit      = max( 1, min( self::MAX_REPORT, $limit ) );

			$mime = isset( $input['mime_type'] ) ? sanitize_text_field( (string) $input['mime_type'] ) : 'image';
			if ( '' === $mime ) {
				$mime = 'image';
			}

			$used_ids = self::collect_known_used_ids();

			$query = new WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => $mime,
					'posts_per_page'         => $scan_limit,
					'orderby'                => 'date',
					'order'                  => 'ASC',
					'no_found_rows'          => false,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				)
			);

			$unused   = array();
			$scanned  = 0;
			$used_hit = 0;

			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) && ! current_user_can( 'manage_options' ) ) {
					continue;
				}

				++$scanned;
				$id = (int) $post->ID;

				if ( isset( $used_ids[ $id ] ) || self::is_referenced_in_content( $post ) ) {
					++$used_hit;
					continue;
				}

				$unused[] = self::summarize_attachment( $post );
				if ( count( $unused ) >= $limit ) {
					break;
				}
			}

			$library_url = admin_url( 'upload.php' );

			return array(
				'scanned'           => $scanned,
				'total_matching'    => (int) $query->found_posts,
				'used_in_scan'      => $used_hit,
				'unused_count'      => count( $unused ),
				'unused'            => $unused,
				'scan_limit'        => $scan_limit,
				'limit'             => $limit,
				'mime_type'         => $mime,
				'media_library_url' => $library_url,
				'notes'             => array(
					__( '“Unused” means not found as a featured image, site icon, custom logo, or in post/page content (URL / wp-image id).', 'ahentic' ),
					__( 'May still be used in widgets, page builders, CSS, emails, or external embeds — review before deleting.', 'ahentic' ),
					__( 'Oldest attachments are scanned first within the scan limit.', 'ahentic' ),
				),
			);
		}

		/**
		 * IDs known used via featured image / site chrome.
		 *
		 * @return array<int, true>
		 */
		private static function collect_known_used_ids() {
			global $wpdb;

			$used = array();

			$featured = $wpdb->get_col(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'"
			);
			if ( is_array( $featured ) ) {
				foreach ( $featured as $raw ) {
					$id = (int) $raw;
					if ( $id > 0 ) {
						$used[ $id ] = true;
					}
				}
			}

			// WooCommerce product galleries (best-effort).
			$galleries = $wpdb->get_col(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> '' LIMIT 500"
			);
			if ( is_array( $galleries ) ) {
				foreach ( $galleries as $csv ) {
					foreach ( array_filter( array_map( 'intval', explode( ',', (string) $csv ) ) ) as $id ) {
						$used[ $id ] = true;
					}
				}
			}

			$icon = (int) get_option( 'site_icon' );
			if ( $icon > 0 ) {
				$used[ $icon ] = true;
			}

			$logo = (int) get_theme_mod( 'custom_logo' );
			if ( $logo > 0 ) {
				$used[ $logo ] = true;
			}

			return $used;
		}

		/**
		 * Whether attachment URL / wp-image id appears in post content or common meta.
		 *
		 * @param \WP_Post $attachment Attachment.
		 * @return bool
		 */
		private static function is_referenced_in_content( WP_Post $attachment ) {
			global $wpdb;

			$id  = (int) $attachment->ID;
			$url = wp_get_attachment_url( $id );
			$url = is_string( $url ) ? $url : '';

			$patterns = array(
				'wp-image-' . $id,
				'attachment_' . $id,
				'{"id":' . $id,
				'"id":' . $id . ',',
			);

			if ( '' !== $url ) {
				$patterns[] = $url;
				$upload     = wp_upload_dir();
				if ( ! empty( $upload['baseurl'] ) && 0 === strpos( $url, $upload['baseurl'] ) ) {
					$rel = substr( $url, strlen( $upload['baseurl'] ) );
					if ( is_string( $rel ) && '' !== $rel ) {
						$patterns[] = $rel;
					}
				}
			}

			$file = get_post_meta( $id, '_wp_attached_file', true );
			if ( is_string( $file ) && '' !== $file ) {
				$patterns[] = $file;
			}

			$patterns = array_values( array_unique( array_filter( $patterns ) ) );

			foreach ( $patterns as $pattern ) {
				$like = '%' . $wpdb->esc_like( $pattern ) . '%';

				$found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						WHERE post_type NOT IN ('attachment','revision','nav_menu_item','customize_changeset','oembed_cache')
							AND post_status NOT IN ('trash','auto-draft')
							AND post_content LIKE %s
						LIMIT 1",
						$like
					)
				);
				if ( $found ) {
					return true;
				}

				$meta_found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_id FROM {$wpdb->postmeta}
						WHERE meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_image_alt')
							AND meta_value LIKE %s
						LIMIT 1",
						$like
					)
				);
				if ( $meta_found ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Compact attachment card.
		 *
		 * @param \WP_Post $post Attachment.
		 * @return array
		 */
		private static function summarize_attachment( WP_Post $post ) {
			$id    = (int) $post->ID;
			$url   = wp_get_attachment_url( $id );
			$edit  = get_edit_post_link( $id, 'raw' );
			$meta  = wp_get_attachment_metadata( $id );
			$bytes = 0;
			if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
				$bytes = (int) $meta['filesize'];
			} else {
				$file = get_attached_file( $id );
				if ( is_string( $file ) && $file && file_exists( $file ) ) {
					$bytes = (int) filesize( $file );
				}
			}

			$dims = '';
			if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
				$dims = (int) $meta['width'] . '×' . (int) $meta['height'];
			}

			return array(
				'id'        => $id,
				'title'     => get_the_title( $post ),
				'mime_type' => $post->post_mime_type,
				'url'       => $url ? $url : '',
				'edit_url'  => $edit ? $edit : '',
				'date'      => $post->post_date_gmt ? $post->post_date_gmt . 'Z' : $post->post_date,
				'filesize'  => $bytes,
				'dimensions'=> $dims,
				'parent_id' => (int) $post->post_parent,
			);
		}

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			$map = array(
				self::FIND_UNUSED        => __( 'Scanning media for unused images…', 'ahentic' ),
				self::DESCRIBE_IMAGE     => __( 'Describing image…', 'ahentic' ),
				self::GENERATE_IMAGE     => __( 'Generating an image…', 'ahentic' ),
				self::UPLOAD_MEDIA       => __( 'Uploading media…', 'ahentic' ),
				self::UPDATE_MEDIA       => __( 'Updating media…', 'ahentic' ),
				self::SET_FEATURED_IMAGE => __( 'Setting featured image…', 'ahentic' ),
				self::DELETE_MEDIA       => __( 'Moving media to trash…', 'ahentic' ),
				self::REPLACE_MEDIA_FILE => __( 'Replacing media file…', 'ahentic' ),
			);
			$name = (string) $name;
			return isset( $map[ $name ] ) ? $map[ $name ] : '';
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Media', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Media', 'register' ) );
	add_action( 'plugins_loaded', array( 'Ahentic_Abilities_Media', 'boot_restore' ), 20 );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Media' );
}
