<?php
/**
 * Content abilities: list, get, search, and update posts/pages (with meta).
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Content' ) ) {
	/**
	 * Content inspection and updates for the agent loop.
	 */
	class Ahentic_Abilities_Content {
		const LIST   = 'ahentic/list-content';
		const GET    = 'ahentic/get-content';
		const SEARCH = 'ahentic/search-content';
		const UPDATE = 'ahentic/update-post';

		const MAX_PER_PAGE      = 50;
		const MAX_CONTENT_CHARS = 20000;
		const MAX_WRITE_CHARS   = 500000;
		const MAX_META_KEYS     = 80;
		const MAX_META_VALUE    = 2000;
		const MAX_SNIPPET       = 200;

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array( self::LIST, self::GET, self::SEARCH, self::UPDATE );
		}

		/**
		 * Write (non-readonly) ability names.
		 *
		 * @return string[]
		 */
		public static function write_names() {
			return array( self::UPDATE );
		}

		/**
		 * Whether an ability from this module is read-only.
		 *
		 * @param string $name Ability name.
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
			return array( self::UPDATE );
		}

		/**
		 * @param string $name Ability name.
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
			if ( self::UPDATE !== $name ) {
				return (string) $name;
			}

			$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
			$fields = array();
			foreach ( array( 'content', 'title', 'excerpt', 'slug', 'meta' ) as $field ) {
				if ( array_key_exists( $field, $input ) ) {
					$fields[] = $field;
				}
			}

			$title = '';
			if ( $id > 0 ) {
				$post = get_post( $id );
				if ( $post instanceof WP_Post ) {
					$title = get_the_title( $post );
				}
			}

			$fields_label = ! empty( $fields ) ? implode( ', ', $fields ) : __( 'fields', 'ahentic' );
			if ( $title ) {
				return sprintf(
					/* translators: 1: post title, 2: post ID, 3: field list */
					__( 'Update post “%1$s” (#%2$d): %3$s', 'ahentic' ),
					$title,
					$id,
					$fields_label
				);
			}

			return sprintf(
				/* translators: 1: post ID, 2: field list */
				__( 'Update post #%1$d: %2$s', 'ahentic' ),
				$id > 0 ? $id : 0,
				$fields_label
			);
		}

		/**
		 * Register the content ability category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			wp_register_ability_category(
				'ahentic-content',
				array(
					'label'       => __( 'Ahentic Content', 'ahentic' ),
					'description' => __( 'List, read, search, and update posts and pages for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register content abilities.
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' );
			};

			$meta = array(
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'show_in_rest' => false,
			);
			$mutate_meta = array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
			);

			wp_register_ability(
				self::LIST,
				array(
					'label'               => __( 'List content', 'ahentic' ),
					'description'         => __( 'Lists posts or pages with titles, status, dates, and edit/view links.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'description' => __( 'Post type or list of types (default: post, page).', 'ahentic' ),
							),
							'status'    => array(
								'description' => __( 'Post status or list (default: any editable statuses).', 'ahentic' ),
							),
							'search'    => array(
								'type'        => 'string',
								'description' => __( 'Optional title/content search string.', 'ahentic' ),
							),
							'per_page'  => array(
								'type'        => 'integer',
								'description' => __( 'Results per page (1–50).', 'ahentic' ),
							),
							'page'      => array(
								'type'        => 'integer',
								'description' => __( 'Page number (1-based).', 'ahentic' ),
							),
							'orderby'   => array(
								'type' => 'string',
								'enum' => array( 'date', 'title', 'modified', 'ID' ),
							),
							'order'     => array(
								'type' => 'string',
								'enum' => array( 'ASC', 'DESC', 'asc', 'desc' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_content' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::GET,
				array(
					'label'               => __( 'Get content', 'ahentic' ),
					'description'         => __( 'Reads one post/page including content and (safe) post meta.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'id' ),
						'properties' => array(
							'id'                => array(
								'type'        => 'integer',
								'description' => __( 'Post ID.', 'ahentic' ),
							),
							'include_content'   => array( 'type' => 'boolean' ),
							'include_meta'      => array( 'type' => 'boolean' ),
							'content_max_chars' => array( 'type' => 'integer' ),
							'meta_keys'         => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_content' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::SEARCH,
				array(
					'label'               => __( 'Search content', 'ahentic' ),
					'description'         => __( 'Finds posts/pages matching a phrase in title, content, or meta; returns snippets and edit links.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'query' ),
						'properties' => array(
							'query'        => array(
								'type'        => 'string',
								'description' => __( 'Phrase to search for.', 'ahentic' ),
							),
							'post_type'    => array(
								'description' => __( 'Post type or list of types.', 'ahentic' ),
							),
							'status'       => array(
								'description' => __( 'Post status or list.', 'ahentic' ),
							),
							'limit'        => array( 'type' => 'integer' ),
							'search_meta'  => array( 'type' => 'boolean' ),
							'snippet_chars'=> array( 'type' => 'integer' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_search_content' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::UPDATE,
				array(
					'label'               => __( 'Update post', 'ahentic' ),
					'description'         => __( 'Updates an existing post or page: content, title, excerpt, slug, and post meta (exact keys from get-content; WooCommerce _price/_regular_price allowed). Does not change publish status. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'id' ),
						'properties' => array(
							'id'      => array(
								'type'        => 'integer',
								'description' => __( 'Post ID.', 'ahentic' ),
							),
							'content' => array(
								'type'        => 'string',
								'description' => __( 'New post_content (HTML / block markup).', 'ahentic' ),
							),
							'title'   => array(
								'type'        => 'string',
								'description' => __( 'New post title.', 'ahentic' ),
							),
							'excerpt' => array(
								'type'        => 'string',
								'description' => __( 'New post excerpt.', 'ahentic' ),
							),
							'slug'    => array(
								'type'        => 'string',
								'description' => __( 'New post slug (post_name).', 'ahentic' ),
							),
							'meta'    => array(
								'type'                 => 'object',
								'description'          => __( 'Post meta key/value pairs to set (exact keys from get-content). Underscore keys like WooCommerce _price are allowed; sensitive/system keys are blocked.', 'ahentic' ),
								'additionalProperties' => true,
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_update_post' ),
					'permission_callback' => $permission,
					'meta'                => $mutate_meta,
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
				case self::LIST:
					return self::execute_list_content( $input );
				case self::GET:
					return self::execute_get_content( $input );
				case self::SEARCH:
					return self::execute_search_content( $input );
				case self::UPDATE:
					return self::execute_update_post( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown content ability.', 'ahentic' ) );
			}
		}

		/**
		 * List posts/pages.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_list_content( $input = array() ) {
			$input = is_array( $input ) ? $input : array();

			$post_types = self::normalize_post_types( isset( $input['post_type'] ) ? $input['post_type'] : null );
			$statuses   = self::normalize_statuses( isset( $input['status'] ) ? $input['status'] : null );
			$per_page   = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
			$page       = isset( $input['page'] ) ? (int) $input['page'] : 1;
			$per_page   = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
			$page       = max( 1, $page );

			$orderby = isset( $input['orderby'] ) ? (string) $input['orderby'] : 'date';
			if ( ! in_array( $orderby, array( 'date', 'title', 'modified', 'ID' ), true ) ) {
				$orderby = 'date';
			}
			$order = isset( $input['order'] ) ? strtoupper( (string) $input['order'] ) : 'DESC';
			$order = ( 'ASC' === $order ) ? 'ASC' : 'DESC';

			$args = array(
				'post_type'              => $post_types,
				'post_status'            => $statuses,
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => $orderby,
				'order'                  => $order,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			if ( ! empty( $input['search'] ) ) {
				$args['s'] = sanitize_text_field( (string) $input['search'] );
			}

			$query = new WP_Query( $args );
			$items = array();
			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) && ! current_user_can( 'manage_options' ) ) {
					continue;
				}
				$items[] = self::summarize_post( $post, false );
			}

			return array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) $query->max_num_pages,
				'post_type'   => $post_types,
				'status'      => $statuses,
			);
		}

		/**
		 * Get one post with optional content + meta.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_get_content( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
			if ( $id <= 0 ) {
				return new WP_Error( 'ahentic_missing_id', __( 'A valid post id is required.', 'ahentic' ) );
			}

			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error( 'ahentic_post_not_found', __( 'Post not found.', 'ahentic' ), array( 'status' => 404 ) );
			}

			if ( ! current_user_can( 'edit_post', $post->ID ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'ahentic_ability_forbidden', __( 'You cannot read this post.', 'ahentic' ), array( 'status' => 403 ) );
			}

			$include_content = ! isset( $input['include_content'] ) || (bool) $input['include_content'];
			$include_meta    = ! isset( $input['include_meta'] ) || (bool) $input['include_meta'];
			$max_chars       = isset( $input['content_max_chars'] ) ? (int) $input['content_max_chars'] : self::MAX_CONTENT_CHARS;
			$max_chars       = max( 500, min( self::MAX_CONTENT_CHARS, $max_chars ) );

			$payload = self::summarize_post( $post, true );

			if ( $include_content ) {
				$raw = (string) $post->post_content;
				$truncated = false;
				if ( strlen( $raw ) > $max_chars ) {
					$raw       = substr( $raw, 0, $max_chars );
					$truncated = true;
				}
				$payload['content']           = $raw;
				$payload['content_truncated'] = $truncated;
				$payload['excerpt']           = $post->post_excerpt;
			}

			if ( $include_meta ) {
				$keys = null;
				if ( ! empty( $input['meta_keys'] ) && is_array( $input['meta_keys'] ) ) {
					$keys = array_values(
						array_filter(
							array_map( 'strval', $input['meta_keys'] )
						)
					);
				}
				$payload['meta'] = self::get_safe_meta( $post->ID, $keys );
			}

			return $payload;
		}

		/**
		 * Search title/content/(optional) meta.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_search_content( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
			if ( '' === $query ) {
				return new WP_Error( 'ahentic_missing_query', __( 'A search query is required.', 'ahentic' ) );
			}

			$post_types  = self::normalize_post_types( isset( $input['post_type'] ) ? $input['post_type'] : null );
			$statuses    = self::normalize_statuses( isset( $input['status'] ) ? $input['status'] : null );
			$limit       = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
			$limit       = max( 1, min( self::MAX_PER_PAGE, $limit ) );
			$search_meta = ! isset( $input['search_meta'] ) || (bool) $input['search_meta'];
			$snippet_len = isset( $input['snippet_chars'] ) ? (int) $input['snippet_chars'] : 160;
			$snippet_len = max( 40, min( self::MAX_SNIPPET, $snippet_len ) );

			$ids_matched     = array(); // id => list of match kinds.
			$meta_keys_by_id = array();

			// Title / content via WP_Query.
			$wpq = new WP_Query(
				array(
					's'                      => $query,
					'post_type'              => $post_types,
					'post_status'            => $statuses,
					'posts_per_page'         => $limit,
					'orderby'                => 'relevance',
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'fields'                 => 'ids',
				)
			);

			foreach ( (array) $wpq->posts as $pid ) {
				$pid                 = (int) $pid;
				$ids_matched[ $pid ] = array( 'title_or_content' );
			}

			if ( $search_meta ) {
				$meta_ids = self::search_meta_ids( $query, $post_types, $statuses, $limit );
				foreach ( $meta_ids as $pid => $keys ) {
					$pid = (int) $pid;
					if ( ! isset( $ids_matched[ $pid ] ) ) {
						$ids_matched[ $pid ] = array();
					}
					$ids_matched[ $pid ][]     = 'meta';
					$ids_matched[ $pid ]       = array_values( array_unique( $ids_matched[ $pid ] ) );
					$meta_keys_by_id[ $pid ]   = is_array( $keys ) ? $keys : array();
				}
			}

			$results = array();
			$count   = 0;
			foreach ( $ids_matched as $pid => $matched_in ) {
				if ( $count >= $limit ) {
					break;
				}
				$post = get_post( $pid );
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) && ! current_user_can( 'manage_options' ) ) {
					continue;
				}

				$haystacks = array(
					'title'   => $post->post_title,
					'content' => wp_strip_all_tags( (string) $post->post_content ),
					'excerpt' => wp_strip_all_tags( (string) $post->post_excerpt ),
				);

				$snippet_source = 'content';
				$snippet_text   = $haystacks['content'];
				foreach ( array( 'title', 'excerpt', 'content' ) as $field ) {
					if ( false !== self::stripos( $haystacks[ $field ], $query ) ) {
						$snippet_source = $field;
						$snippet_text   = $haystacks[ $field ];
						break;
					}
				}

				$item = self::summarize_post( $post, false );
				$item['matched_in']        = $matched_in;
				$item['matched_meta_keys'] = isset( $meta_keys_by_id[ $pid ] ) ? array_slice( $meta_keys_by_id[ $pid ], 0, 10 ) : array();
				$item['snippet']           = self::make_snippet( $snippet_text, $query, $snippet_len );
				$item['snippet_field']     = $snippet_source;
				$results[]                 = $item;
				++$count;
			}

			return array(
				'query'       => $query,
				'count'       => count( $results ),
				'limit'       => $limit,
				'search_meta' => $search_meta,
				'post_type'   => $post_types,
				'status'      => $statuses,
				'results'     => $results,
			);
		}

		/**
		 * Update post content / title / excerpt / slug / safe meta.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_update_post( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
			if ( $id <= 0 ) {
				return new WP_Error( 'ahentic_missing_id', __( 'A valid post id is required.', 'ahentic' ) );
			}

			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error( 'ahentic_post_not_found', __( 'Post not found.', 'ahentic' ), array( 'status' => 404 ) );
			}

			if ( ! current_user_can( 'edit_post', $post->ID ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You cannot edit this post.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			// Skip internal / non-editable types.
			$type_obj = get_post_type_object( $post->post_type );
			if ( ! $type_obj ) {
				return new WP_Error(
					'ahentic_post_type_blocked',
					__( 'This post type cannot be updated via Ahentic.', 'ahentic' )
				);
			}

			$blocked_types = array(
				'revision',
				'nav_menu_item',
				'attachment',
				'ahentic-session',
				'customize_changeset',
				'oembed_cache',
				'user_request',
				'wp_template',
				'wp_template_part',
				'wp_global_styles',
				'wp_navigation',
				'wp_font_family',
				'wp_font_face',
			);
			if ( in_array( $post->post_type, $blocked_types, true ) ) {
				return new WP_Error(
					'ahentic_post_type_blocked',
					__( 'This post type cannot be updated via Ahentic.', 'ahentic' )
				);
			}

			$args           = array( 'ID' => $post->ID );
			$changed_fields = array();

			if ( array_key_exists( 'content', $input ) ) {
				$content = (string) $input['content'];
				if ( strlen( $content ) > self::MAX_WRITE_CHARS ) {
					return new WP_Error(
						'ahentic_content_too_large',
						sprintf(
							/* translators: %d: max characters */
							__( 'Content exceeds the maximum of %d characters.', 'ahentic' ),
							self::MAX_WRITE_CHARS
						)
					);
				}
				$args['post_content'] = $content;
				$changed_fields[]     = 'content';
			}

			if ( array_key_exists( 'title', $input ) ) {
				$title = trim( (string) $input['title'] );
				if ( '' === $title ) {
					return new WP_Error( 'ahentic_invalid_title', __( 'Title cannot be empty.', 'ahentic' ) );
				}
				$args['post_title'] = $title;
				$changed_fields[]   = 'title';
			}

			if ( array_key_exists( 'excerpt', $input ) ) {
				$args['post_excerpt'] = (string) $input['excerpt'];
				$changed_fields[]     = 'excerpt';
			}

			if ( array_key_exists( 'slug', $input ) ) {
				$slug = sanitize_title( (string) $input['slug'] );
				if ( '' === $slug ) {
					return new WP_Error( 'ahentic_invalid_slug', __( 'Slug cannot be empty.', 'ahentic' ) );
				}
				$args['post_name']  = $slug;
				$changed_fields[]   = 'slug';
			}

			$meta_input = isset( $input['meta'] ) && is_array( $input['meta'] ) ? $input['meta'] : array();
			$meta_plan  = self::plan_meta_updates( $meta_input );
			if ( is_wp_error( $meta_plan ) ) {
				return $meta_plan;
			}

			if ( count( $args ) <= 1 && empty( $meta_plan['set'] ) ) {
				return self::nothing_to_update_error( $input, $meta_plan, (int) $post->ID );
			}

			$before = self::summarize_post( $post, true );
			$before['content_chars'] = strlen( (string) $post->post_content );
			$before['excerpt']       = (string) $post->post_excerpt;

			if ( count( $args ) > 1 ) {
				$result = wp_update_post( wp_slash( $args ), true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( ! $result ) {
					return new WP_Error( 'ahentic_update_failed', __( 'Failed to update the post.', 'ahentic' ) );
				}
			}

			$meta_updated = array();
			$meta_skipped = isset( $meta_plan['skipped'] ) ? $meta_plan['skipped'] : array();
			foreach ( $meta_plan['set'] as $key => $value ) {
				$prev = get_post_meta( $post->ID, $key, true );
				$ok   = update_post_meta( $post->ID, $key, $value );
				if ( false === $ok && (string) $prev !== (string) $value ) {
					$meta_skipped[] = array(
						'key'    => $key,
						'reason' => 'update_failed',
					);
					continue;
				}
				$meta_updated[] = $key;
			}

			$fresh = get_post( $post->ID );
			if ( ! $fresh instanceof WP_Post ) {
				return new WP_Error( 'ahentic_post_reload_failed', __( 'Post updated but could not be reloaded.', 'ahentic' ) );
			}

			$after                   = self::summarize_post( $fresh, true );
			$after['content_chars']  = strlen( (string) $fresh->post_content );
			$after['excerpt']        = (string) $fresh->post_excerpt;
			// Return a truncated content preview so the agent can confirm without dumping huge markup.
			$preview_max             = 400;
			$content_preview         = (string) $fresh->post_content;
			$truncated             = false;
			if ( strlen( $content_preview ) > $preview_max ) {
				$content_preview = substr( $content_preview, 0, $preview_max );
				$truncated     = true;
			}
			$after['content_preview']   = $content_preview;
			$after['content_truncated'] = $truncated;

			return array(
				'ok'             => true,
				'id'             => (int) $fresh->ID,
				'changed_fields' => $changed_fields,
				'meta_updated'   => $meta_updated,
				'meta_skipped'   => $meta_skipped,
				'before'         => $before,
				'post'           => $after,
				'edit_url'       => isset( $after['edit_url'] ) ? $after['edit_url'] : '',
				'view_url'       => isset( $after['view_url'] ) ? $after['view_url'] : '',
			);
		}

		/**
		 * Rich error when update-post received no applicable fields.
		 *
		 * @param array $input     Raw ability input.
		 * @param array $meta_plan Planned meta updates.
		 * @param int   $post_id   Post ID.
		 * @return \WP_Error
		 */
		private static function nothing_to_update_error( array $input, array $meta_plan, $post_id ) {
			$recognized = array( 'id', 'content', 'title', 'excerpt', 'slug', 'meta' );
			$ignored    = array();
			foreach ( array_keys( $input ) as $key ) {
				$key = (string) $key;
				if ( ! in_array( $key, $recognized, true ) ) {
					$ignored[] = $key;
				}
			}

			$skipped = isset( $meta_plan['skipped'] ) && is_array( $meta_plan['skipped'] ) ? $meta_plan['skipped'] : array();
			$has_meta = isset( $input['meta'] ) && is_array( $input['meta'] ) && ! empty( $input['meta'] );

			if ( $has_meta && ! empty( $skipped ) ) {
				$keys = array();
				foreach ( $skipped as $row ) {
					if ( ! empty( $row['key'] ) ) {
						$keys[] = (string) $row['key'];
					}
				}
				$message = sprintf(
					/* translators: %s: comma-separated meta keys */
					__( 'Meta keys were provided but none could be applied (%s). Use exact keys from ahentic/get-content; sensitive/system keys are blocked.', 'ahentic' ),
					implode( ', ', $keys )
				);
				$code = 'ahentic_meta_not_applied';
			} elseif ( ! empty( $ignored ) ) {
				$message = sprintf(
					/* translators: %s: comma-separated ignored keys */
					__( 'Unrecognized fields (%s). Custom values must go under meta using the exact key from ahentic/get-content (include_meta=true).', 'ahentic' ),
					implode( ', ', $ignored )
				);
				$code = 'ahentic_nothing_to_update';
			} else {
				$message = __( 'Provide at least one of: content, title, excerpt, slug, or meta (with exact keys from get-content).', 'ahentic' );
				$code    = 'ahentic_nothing_to_update';
			}

			return new WP_Error(
				$code,
				$message,
				array(
					'status'            => 400,
					'recognized_fields' => array( 'content', 'title', 'excerpt', 'slug', 'meta' ),
					'ignored_keys'      => $ignored,
					'meta_skipped'      => $skipped,
					'hint'              => __( 'Call ahentic/get-content with this post id and include_meta=true, copy the exact meta key names from the result, then retry update-post with {"id":…,"meta":{"exact_key":"…"}}.', 'ahentic' ),
					'next_tool'         => array(
						'name'  => self::GET,
						'input' => array(
							'id'           => (int) $post_id,
							'include_meta' => true,
						),
					),
				)
			);
		}

		/**
		 * WordPress internal meta keys that must not be overwritten by the agent.
		 *
		 * @return string[]
		 */
		private static function blocked_system_meta_keys() {
			return array(
				'_edit_lock',
				'_edit_last',
				'_wp_trash_meta_status',
				'_wp_trash_meta_time',
				'_wp_desired_post_slug',
				'_wp_old_slug',
				'_wp_attached_file',
				'_wp_attachment_metadata',
				'_wp_attachment_context',
			);
		}

		/**
		 * Validate and normalize post meta updates.
		 *
		 * Underscore keys are allowed (e.g. WooCommerce `_price`) unless sensitive/system-blocked.
		 *
		 * @param array $meta Raw meta map.
		 * @return array{set: array<string, mixed>, skipped: array<int, array{key: string, reason: string}>}|\WP_Error
		 */
		private static function plan_meta_updates( array $meta ) {
			$set     = array();
			$skipped = array();
			$count   = 0;
			$blocked = self::blocked_system_meta_keys();

			foreach ( $meta as $key => $value ) {
				$key = (string) $key;
				if ( '' === $key ) {
					continue;
				}

				if ( in_array( $key, $blocked, true ) ) {
					$skipped[] = array(
						'key'    => $key,
						'reason' => 'system_key',
					);
					continue;
				}

				if ( self::is_sensitive_meta_key( $key ) ) {
					$skipped[] = array(
						'key'    => $key,
						'reason' => 'sensitive_key',
					);
					continue;
				}

				if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $key ) ) {
					$skipped[] = array(
						'key'    => $key,
						'reason' => 'invalid_key',
					);
					continue;
				}

				if ( $count >= self::MAX_META_KEYS ) {
					$skipped[] = array(
						'key'    => $key,
						'reason' => 'too_many_keys',
					);
					continue;
				}

				if ( is_array( $value ) || is_object( $value ) ) {
					$encoded = wp_json_encode( $value );
					if ( false === $encoded ) {
						$skipped[] = array(
							'key'    => $key,
							'reason' => 'unencodable',
						);
						continue;
					}
					$value = $encoded;
				} elseif ( is_bool( $value ) ) {
					$value = $value ? '1' : '0';
				} elseif ( null === $value ) {
					$value = '';
				} else {
					$value = (string) $value;
				}

				if ( strlen( $value ) > self::MAX_META_VALUE ) {
					$value = substr( $value, 0, self::MAX_META_VALUE );
				}

				$set[ $key ] = $value;
				++$count;
			}

			return array(
				'set'     => $set,
				'skipped' => $skipped,
			);
		}

		/**
		 * Compact post card for list/search.
		 *
		 * @param \WP_Post $post    Post.
		 * @param bool     $detailed Extra fields.
		 * @return array
		 */
		private static function summarize_post( WP_Post $post, $detailed = false ) {
			$edit = get_edit_post_link( $post->ID, 'raw' );
			$view = get_permalink( $post->ID );

			$item = array(
				'id'         => (int) $post->ID,
				'title'      => get_the_title( $post ),
				'type'       => $post->post_type,
				'status'     => $post->post_status,
				'slug'       => $post->post_name,
				'date'       => $post->post_date_gmt ? $post->post_date_gmt . 'Z' : $post->post_date,
				'modified'   => $post->post_modified_gmt ? $post->post_modified_gmt . 'Z' : $post->post_modified,
				'edit_url'   => $edit ? $edit : '',
				'view_url'   => $view ? $view : '',
				'author_id'  => (int) $post->post_author,
			);

			if ( $detailed ) {
				$item['guid']          = $post->guid;
				$item['comment_count'] = (int) $post->comment_count;
				$item['parent']        = (int) $post->post_parent;
				$item['menu_order']    = (int) $post->menu_order;
			}

			return $item;
		}

		/**
		 * Safe post meta map (redacts secrets, truncates long values).
		 *
		 * @param int         $post_id Post ID.
		 * @param array|null  $only_keys Optional key allowlist.
		 * @return array
		 */
		private static function get_safe_meta( $post_id, $only_keys = null ) {
			$all = get_post_meta( $post_id );
			if ( ! is_array( $all ) ) {
				return array();
			}

			$out   = array();
			$count = 0;
			foreach ( $all as $key => $values ) {
				$key = (string) $key;
				if ( null !== $only_keys && ! in_array( $key, $only_keys, true ) ) {
					continue;
				}
				if ( self::is_sensitive_meta_key( $key ) ) {
					$out[ $key ] = array( '[redacted]' );
					continue;
				}

				$normalized = array();
				foreach ( (array) $values as $value ) {
					$normalized[] = self::normalize_meta_value( $value );
				}
				$out[ $key ] = $normalized;
				++$count;
				if ( $count >= self::MAX_META_KEYS ) {
					$out['_ahentic_meta_truncated'] = true;
					break;
				}
			}

			return $out;
		}

		/**
		 * Whether a meta key looks sensitive.
		 *
		 * @param string $key Meta key.
		 * @return bool
		 */
		private static function is_sensitive_meta_key( $key ) {
			$key = strtolower( $key );
			$needles = array(
				'password',
				'passwd',
				'secret',
				'token',
				'api_key',
				'apikey',
				'auth',
				'private_key',
				'salt',
				'nonce',
				'session',
				'credit_card',
				'card_number',
			);
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $key, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Normalize a meta value for JSON output.
		 *
		 * @param mixed $value Raw.
		 * @return mixed
		 */
		private static function normalize_meta_value( $value ) {
			if ( is_string( $value ) ) {
				$maybe = maybe_unserialize( $value );
				if ( $maybe !== $value ) {
					$value = $maybe;
				}
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_null( $value ) ) {
				return $value;
			}

			if ( is_string( $value ) ) {
				if ( strlen( $value ) > self::MAX_META_VALUE ) {
					return substr( $value, 0, self::MAX_META_VALUE ) . '…';
				}
				return $value;
			}

			if ( is_array( $value ) ) {
				$encoded = wp_json_encode( $value );
				if ( ! is_string( $encoded ) ) {
					return '[unserializable]';
				}
				if ( strlen( $encoded ) > self::MAX_META_VALUE ) {
					return substr( $encoded, 0, self::MAX_META_VALUE ) . '…';
				}
				return $value;
			}

			return '[object]';
		}

		/**
		 * Search postmeta for a phrase; return id => matched keys.
		 *
		 * @param string       $query      Phrase.
		 * @param string[]     $post_types Types.
		 * @param string[]     $statuses   Statuses.
		 * @param int          $limit      Max posts.
		 * @return array<int, string[]>
		 */
		private static function search_meta_ids( $query, $post_types, $statuses, $limit ) {
			global $wpdb;

			$like       = '%' . $wpdb->esc_like( $query ) . '%';
			$type_in    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$status_in  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$row_limit  = max( 20, $limit * 5 );
			$params     = array_merge( array( $like ), $post_types, $statuses, array( $row_limit ) );

			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT p.ID, pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_value LIKE %s
					AND p.post_type IN ($type_in)
					AND p.post_status IN ($status_in)
				ORDER BY p.post_modified DESC
				LIMIT %d",
				...$params
			);
			// phpcs:enable

			if ( ! is_string( $sql ) ) {
				return array();
			}

			$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$map  = array();
			if ( ! is_array( $rows ) ) {
				return $map;
			}

			foreach ( $rows as $row ) {
				$pid = (int) $row->ID;
				$key = (string) $row->meta_key;
				if ( self::is_sensitive_meta_key( $key ) ) {
					continue;
				}
				if ( ! isset( $map[ $pid ] ) ) {
					if ( count( $map ) >= $limit ) {
						continue;
					}
					$map[ $pid ] = array();
				}
				if ( count( $map[ $pid ] ) < 10 && ! in_array( $key, $map[ $pid ], true ) ) {
					$map[ $pid ][] = $key;
				}
			}

			return $map;
		}

		/**
		 * Build a short snippet around the first match.
		 *
		 * @param string $text   Source.
		 * @param string $query  Needle.
		 * @param int    $length Max length.
		 * @return string
		 */
		private static function make_snippet( $text, $query, $length ) {
			$text  = preg_replace( '/\s+/u', ' ', (string) $text );
			$text  = trim( (string) $text );
			$query = (string) $query;
			if ( '' === $text ) {
				return '';
			}

			$pos = '' !== $query ? self::stripos( $text, $query ) : false;
			if ( false === $pos ) {
				$slice = self::substr( $text, 0, $length );
				return $slice . ( self::strlen( $text ) > $length ? '…' : '' );
			}

			$start  = max( 0, (int) $pos - (int) floor( $length / 3 ) );
			$slice  = self::substr( $text, $start, $length );
			$prefix = $start > 0 ? '…' : '';
			$suffix = ( $start + $length ) < self::strlen( $text ) ? '…' : '';
			return $prefix . $slice . $suffix;
		}

		/**
		 * @param string $haystack Haystack.
		 * @param string $needle   Needle.
		 * @return int|false
		 */
		private static function stripos( $haystack, $needle ) {
			if ( function_exists( 'mb_stripos' ) ) {
				return mb_stripos( $haystack, $needle );
			}
			return stripos( $haystack, $needle );
		}

		/**
		 * @param string $text Text.
		 * @return int
		 */
		private static function strlen( $text ) {
			if ( function_exists( 'mb_strlen' ) ) {
				return mb_strlen( $text );
			}
			return strlen( $text );
		}

		/**
		 * @param string $text   Text.
		 * @param int    $start  Start.
		 * @param int    $length Length.
		 * @return string
		 */
		private static function substr( $text, $start, $length ) {
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $text, $start, $length );
			}
			return substr( $text, $start, $length );
		}

		/**
		 * @param mixed $raw Post type input.
		 * @return string[]
		 */
		private static function normalize_post_types( $raw ) {
			$types = array( 'post', 'page' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$types = array( $raw );
			} elseif ( is_array( $raw ) && ! empty( $raw ) ) {
				$types = array_values( array_filter( array_map( 'strval', $raw ) ) );
			}

			$out = array();
			foreach ( $types as $type ) {
				$type = sanitize_key( $type );
				if ( '' === $type ) {
					continue;
				}
				if ( 'any' === $type || post_type_exists( $type ) ) {
					$out[] = $type;
				}
			}

			return ! empty( $out ) ? array_values( array_unique( $out ) ) : array( 'post', 'page' );
		}

		/**
		 * @param mixed $raw Status input.
		 * @return string[]
		 */
		private static function normalize_statuses( $raw ) {
			$default = array( 'publish', 'draft', 'pending', 'private', 'future' );
			if ( null === $raw || '' === $raw ) {
				return $default;
			}
			if ( is_string( $raw ) ) {
				if ( 'any' === $raw ) {
					return $default;
				}
				$raw = array( $raw );
			}
			if ( ! is_array( $raw ) ) {
				return $default;
			}

			$out = array();
			foreach ( $raw as $status ) {
				$status = sanitize_key( (string) $status );
				if ( '' !== $status ) {
					$out[] = $status;
				}
			}
			return ! empty( $out ) ? array_values( array_unique( $out ) ) : $default;
		}
	}
}
