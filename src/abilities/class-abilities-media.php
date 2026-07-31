<?php
/**
 * Media abilities: find unused images in the library.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Media' ) ) {
	/**
	 * Read-only media inspection for the agent loop.
	 */
	class Ahentic_Abilities_Media {
		const FIND_UNUSED = 'ahentic/find-unused-media';

		const MAX_SCAN   = 100;
		const MAX_REPORT = 50;

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array( self::FIND_UNUSED );
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
					'description' => __( 'Inspect the media library for Ahentic.', 'ahentic' ),
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
					'permission_callback' => static function () {
						return current_user_can( 'upload_files' ) || current_user_can( 'manage_options' );
					},
					'meta'                => array(
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
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
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown media ability.', 'ahentic' ) );
			}
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
	}
}
