<?php
/**
 * Media abilities: unused scan, describe-image, generate-image, upload-media.
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
		const FIND_UNUSED    = 'ahentic/find-unused-media';
		const DESCRIBE_IMAGE = 'ahentic/describe-image';
		const GENERATE_IMAGE = 'ahentic/generate-image';
		const UPLOAD_MEDIA   = 'ahentic/upload-media';

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
			return array( self::FIND_UNUSED, self::DESCRIBE_IMAGE, self::GENERATE_IMAGE, self::UPLOAD_MEDIA );
		}

		/**
		 * Write (non-readonly) ability names.
		 *
		 * @return string[]
		 */
		public static function write_names() {
			return array( self::UPLOAD_MEDIA );
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
			return array( self::UPLOAD_MEDIA );
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function requires_hitl( $name ) {
			return in_array( (string) $name, self::hitl_names(), true );
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
			if ( self::UPLOAD_MEDIA !== (string) $name ) {
				return (string) $name;
			}
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
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown media ability.', 'ahentic' ) );
			}
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

			$has_url  = isset( $input['url'] ) && is_string( $input['url'] ) && '' !== trim( $input['url'] );
			$has_path = isset( $input['source_path'] ) && is_string( $input['source_path'] ) && '' !== trim( $input['source_path'] );

			if ( $has_url === $has_path ) {
				return new WP_Error(
					'ahentic_upload_media_input',
					__( 'Provide exactly one of url or from_memory (image artifact).', 'ahentic' ),
					array(
						'hint' => __( 'After generate-image, call upload-media with {"from_memory":"<artifact_key>"}.', 'ahentic' ),
					)
				);
			}

			if ( ! current_user_can( 'upload_files' ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'ahentic_upload_forbidden', __( 'You cannot upload files.', 'ahentic' ) );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$tmp_to_clean = '';
			$file_array   = array();

			if ( $has_path ) {
				$source = (string) $input['source_path'];
				if ( ! file_exists( $source ) || ! is_readable( $source ) ) {
					return new WP_Error( 'ahentic_upload_file_missing', __( 'Staged image file is missing on disk.', 'ahentic' ) );
				}

				// Restrict to Ahentic image temp dir when coming from artifacts.
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

				// Copy so wp_handle_sideload / media_handle_sideload can move without destroying the artifact yet.
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
				self::FIND_UNUSED    => __( 'Scanning media for unused images…', 'ahentic' ),
				self::DESCRIBE_IMAGE => __( 'Describing image…', 'ahentic' ),
				self::GENERATE_IMAGE => __( 'Generating an image…', 'ahentic' ),
				self::UPLOAD_MEDIA   => __( 'Uploading media…', 'ahentic' ),
			);
			$name = (string) $name;
			return isset( $map[ $name ] ) ? $map[ $name ] : '';
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Media', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Media', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Media' );
}
