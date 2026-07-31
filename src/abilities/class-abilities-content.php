<?php
/**
 * Content abilities: list, get, and search posts/pages (with meta).
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Content' ) ) {
	/**
	 * Read-only content inspection for the agent loop.
	 */
	class Ahentic_Abilities_Content {
		const LIST   = 'ahentic/list-content';
		const GET    = 'ahentic/get-content';
		const SEARCH = 'ahentic/search-content';

		const MAX_PER_PAGE      = 50;
		const MAX_CONTENT_CHARS = 20000;
		const MAX_META_KEYS     = 80;
		const MAX_META_VALUE    = 2000;
		const MAX_SNIPPET       = 200;

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array( self::LIST, self::GET, self::SEARCH );
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
					'description' => __( 'List, read, and search posts and pages for Ahentic.', 'ahentic' ),
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
