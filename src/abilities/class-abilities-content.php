<?php
/**
 * Content abilities: list, get, search, create, update, and set post status.
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
		const LIST      = 'ahentic/list-content';
		const GET       = 'ahentic/get-content';
		const SEARCH    = 'ahentic/search-content';
		const LIST_POST_TYPES = 'ahentic/list-post-types';
		const REPLACE_IN_CONTENT = 'ahentic/replace-in-content';
		const LIST_REVISIONS = 'ahentic/list-revisions';
		const RESTORE_REVISION = 'ahentic/restore-revision';
		const CREATE    = 'ahentic/create-post';
		const UPDATE    = 'ahentic/update-post';
		const SET_STATUS = 'ahentic/set-post-status';

		const MAX_PER_PAGE      = 50;
		const MAX_CONTENT_CHARS = 20000;
		const MAX_WRITE_CHARS   = 500000;
		const MAX_META_KEYS     = 80;
		const MAX_META_VALUE    = 2000;
		const MAX_SNIPPET       = 200;
		const MAX_POST_TYPES    = 40;
		const MAX_REVISIONS     = 20;
		const MAX_REPLACE       = 50;

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array(
				self::LIST,
				self::GET,
				self::SEARCH,
				self::LIST_POST_TYPES,
				self::REPLACE_IN_CONTENT,
				self::LIST_REVISIONS,
				self::RESTORE_REVISION,
				self::CREATE,
				self::UPDATE,
				self::SET_STATUS,
			);
		}

		/**
		 * Write (non-readonly) ability names.
		 *
		 * @return string[]
		 */
		public static function write_names() {
			return array( self::CREATE, self::UPDATE, self::SET_STATUS, self::REPLACE_IN_CONTENT, self::RESTORE_REVISION );
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
			return array( self::CREATE, self::UPDATE, self::SET_STATUS, self::REPLACE_IN_CONTENT, self::RESTORE_REVISION );
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

			if ( self::REPLACE_IN_CONTENT === $name ) {
				$find    = isset( $input['find'] ) ? (string) $input['find'] : '';
				$replace = isset( $input['replace'] ) ? (string) $input['replace'] : '';
				$dry     = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
				if ( $dry ) {
					return sprintf(
						/* translators: 1: find string, 2: replace string */
						__( 'Preview replace “%1$s” → “%2$s” in content', 'ahentic' ),
						self::truncate_for_summary( $find ),
						self::truncate_for_summary( $replace )
					);
				}
				return sprintf(
					/* translators: 1: find string, 2: replace string */
					__( 'Replace “%1$s” → “%2$s” in content', 'ahentic' ),
					self::truncate_for_summary( $find ),
					self::truncate_for_summary( $replace )
				);
			}

			if ( self::RESTORE_REVISION === $name ) {
				$post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
				$revision_id = isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0;
				return sprintf(
					/* translators: 1: post ID, 2: revision ID */
					__( 'Restore post #%1$d from revision #%2$d', 'ahentic' ),
					$post_id,
					$revision_id
				);
			}

			if ( self::CREATE === $name ) {
				$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';
				$title     = isset( $input['title'] ) ? trim( (string) $input['title'] ) : '';
				$from_mem  = isset( $input['from_memory'] ) ? trim( (string) $input['from_memory'] ) : '';
				if ( $from_mem && $title ) {
					return sprintf(
						/* translators: 1: post type, 2: title, 3: artifact key */
						__( 'Create %1$s draft “%2$s” from artifact %3$s', 'ahentic' ),
						$post_type ? $post_type : 'post',
						$title,
						$from_mem
					);
				}
				if ( $from_mem ) {
					return sprintf(
						/* translators: 1: post type, 2: artifact key */
						__( 'Create %1$s draft from artifact %2$s', 'ahentic' ),
						$post_type ? $post_type : 'post',
						$from_mem
					);
				}
				if ( $title ) {
					return sprintf(
						/* translators: 1: post type, 2: title */
						__( 'Create %1$s draft “%2$s”', 'ahentic' ),
						$post_type ? $post_type : 'post',
						$title
					);
				}
				return sprintf(
					/* translators: %s: post type */
					__( 'Create a new %s draft', 'ahentic' ),
					$post_type ? $post_type : 'post'
				);
			}

			if ( self::SET_STATUS === $name ) {
				$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
				$title  = '';
				if ( $id > 0 ) {
					$post = get_post( $id );
					if ( $post instanceof WP_Post ) {
						$title = get_the_title( $post );
					}
				}
				if ( $title ) {
					return sprintf(
						/* translators: 1: post title, 2: post ID, 3: status */
						__( 'Set status of “%1$s” (#%2$d) to %3$s', 'ahentic' ),
						$title,
						$id,
						$status ? $status : __( 'unknown', 'ahentic' )
					);
				}
				return sprintf(
					/* translators: 1: post ID, 2: status */
					__( 'Set status of post #%1$d to %2$s', 'ahentic' ),
					$id > 0 ? $id : 0,
					$status ? $status : __( 'unknown', 'ahentic' )
				);
			}

			if ( self::UPDATE !== $name ) {
				return (string) $name;
			}

			$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
			$fields = array();
			foreach ( array( 'content', 'title', 'excerpt', 'slug', 'meta', 'from_memory' ) as $field ) {
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
				self::CREATE,
				array(
					'label'               => __( 'Create post', 'ahentic' ),
					'description'         => __( 'Creates a new post/page/CPT as a draft (default). Only use when the block editor is NOT open — if the user is already editing a post/page in Gutenberg, edit that document with ahentic-browser/update-post-document + set-blocks/insert-blocks/replace-blocks/delete-blocks instead. Pass real post content (not bracket stubs like [full article]), or from_memory with a staged artifact key. For publish/schedule use ahentic/set-post-status after creation. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'title' ),
						'properties' => array(
							'title'        => array(
								'type'        => 'string',
								'description' => __( 'Post title.', 'ahentic' ),
							),
							'post_type'    => array(
								'type'        => 'string',
								'description' => __( 'Post type (default: post).', 'ahentic' ),
							),
							'content'      => array(
								'type'        => 'string',
								'description' => __( 'post_content (HTML / block markup). Ignored when from_memory is set.', 'ahentic' ),
							),
							'from_memory'  => array(
								'type'        => 'string',
								'description' => __( 'Session artifact key (from ahentic/stage-artifact). Expands to content; wins over inline content.', 'ahentic' ),
							),
							'excerpt'      => array( 'type' => 'string' ),
							'slug'         => array( 'type' => 'string' ),
							'status'       => array(
								'type'        => 'string',
								'description' => __( 'Initial status: draft or pending only (default: draft). Use set-post-status to publish.', 'ahentic' ),
								'enum'        => array( 'draft', 'pending' ),
							),
							'meta'         => array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_create_post' ),
					'permission_callback' => $permission,
					'meta'                => $mutate_meta,
				)
			);

			wp_register_ability(
				self::UPDATE,
				array(
					'label'               => __( 'Update post', 'ahentic' ),
					'description'         => __( 'Updates an existing post or page: content, title, excerpt, slug, and post meta (exact keys from get-content; WooCommerce _price/_regular_price allowed). Content may use from_memory for a staged artifact. Does not change publish status. When the block editor is open for this post, use ahentic-browser/set-blocks/insert/replace/delete and ahentic-browser/update-post-document instead — server updates for those fields are refused. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'id' ),
						'properties' => array(
							'id'           => array(
								'type'        => 'integer',
								'description' => __( 'Post ID.', 'ahentic' ),
							),
							'content'      => array(
								'type'        => 'string',
								'description' => __( 'New post_content (HTML / block markup). Ignored when from_memory is set.', 'ahentic' ),
							),
							'from_memory'  => array(
								'type'        => 'string',
								'description' => __( 'Session artifact key (from ahentic/stage-artifact). Expands to content; wins over inline content.', 'ahentic' ),
							),
							'title'        => array(
								'type'        => 'string',
								'description' => __( 'New post title.', 'ahentic' ),
							),
							'excerpt'      => array(
								'type'        => 'string',
								'description' => __( 'New post excerpt.', 'ahentic' ),
							),
							'slug'         => array(
								'type'        => 'string',
								'description' => __( 'New post slug (post_name).', 'ahentic' ),
							),
							'meta'         => array(
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

			$destructive_meta = array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
			);

			wp_register_ability(
				self::SET_STATUS,
				array(
					'label'               => __( 'Set post status', 'ahentic' ),
					'description'         => __( 'Changes publish status for a post/page (publish, draft, pending, private, future, trash). For future, pass date. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'id', 'status' ),
						'properties' => array(
							'id'     => array(
								'type'        => 'integer',
								'description' => __( 'Post ID.', 'ahentic' ),
							),
							'status' => array(
								'type' => 'string',
								'enum' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
							),
							'date'   => array(
								'type'        => 'string',
								'description' => __( 'Optional local datetime for future status (Y-m-d H:i:s).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_set_post_status' ),
					'permission_callback' => $permission,
					'meta'                => $destructive_meta,
				)
			);

			wp_register_ability(
				self::LIST_POST_TYPES,
				array(
					'label'               => __( 'List post types', 'ahentic' ),
					'description'         => __( 'Lists agent-relevant registered post types with labels, public/REST flags, and publish+draft counts.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_post_types' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::LIST_REVISIONS,
				array(
					'label'               => __( 'List revisions', 'ahentic' ),
					'description'         => __( 'Lists recent revisions for a post (newest first).', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id' ),
						'properties' => array(
							'post_id' => array(
								'type'        => 'integer',
								'description' => __( 'Post ID.', 'ahentic' ),
							),
							'limit'   => array(
								'type'        => 'integer',
								'description' => __( 'Max revisions to return (1–20).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_revisions' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::REPLACE_IN_CONTENT,
				array(
					'label'               => __( 'Replace in content', 'ahentic' ),
					'description'         => __( 'Find-and-replace across post titles/content. Default dry_run:true previews matches; dry_run:false writes. Requires human approval in Ahentic for real runs.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'find', 'replace' ),
						'properties' => array(
							'find'      => array(
								'type'        => 'string',
								'description' => __( 'Literal substring to find (case-sensitive).', 'ahentic' ),
							),
							'replace'   => array(
								'type'        => 'string',
								'description' => __( 'Replacement substring.', 'ahentic' ),
							),
							'dry_run'   => array(
								'type'        => 'boolean',
								'description' => __( 'When true (default), preview only — never writes.', 'ahentic' ),
							),
							'post_type' => array(
								'description' => __( 'Post type or list of types (default: post, page).', 'ahentic' ),
							),
							'status'    => array(
								'description' => __( 'Post status or list (default: editable statuses).', 'ahentic' ),
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => __( 'Max posts to scan/update (1–50).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_replace_in_content' ),
					'permission_callback' => $permission,
					'meta'                => $mutate_meta,
				)
			);

			wp_register_ability(
				self::RESTORE_REVISION,
				array(
					'label'               => __( 'Restore revision', 'ahentic' ),
					'description'         => __( 'Restores a post from a WordPress revision of that post. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id', 'revision_id' ),
						'properties' => array(
							'post_id'     => array(
								'type'        => 'integer',
								'description' => __( 'Post ID to restore into.', 'ahentic' ),
							),
							'revision_id' => array(
								'type'        => 'integer',
								'description' => __( 'Revision ID that must belong to post_id.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_restore_revision' ),
					'permission_callback' => $permission,
					'meta'                => $destructive_meta,
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
				case self::LIST_POST_TYPES:
					return self::execute_list_post_types( $input );
				case self::REPLACE_IN_CONTENT:
					return self::execute_replace_in_content( $input );
				case self::LIST_REVISIONS:
					return self::execute_list_revisions( $input );
				case self::RESTORE_REVISION:
					return self::execute_restore_revision( $input );
				case self::CREATE:
					return self::execute_create_post( $input );
				case self::UPDATE:
					return self::execute_update_post( $input );
				case self::SET_STATUS:
					return self::execute_set_post_status( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown content ability.', 'ahentic' ) );
			}
		}

		/**
		 * List agent-relevant post types with counts.
		 *
		 * @param mixed $input Unused.
		 * @return array|\WP_Error
		 */
		public static function execute_list_post_types( $input = array() ) {
			unset( $input );

			$blocked = self::blocked_post_types();
			$objects = get_post_types( array(), 'objects' );
			$items   = array();

			foreach ( $objects as $name => $obj ) {
				$name = (string) $name;
				if ( '' === $name || in_array( $name, $blocked, true ) ) {
					continue;
				}
				if ( ! ( $obj instanceof WP_Post_Type ) ) {
					continue;
				}
				$show_in_rest = ! empty( $obj->show_in_rest );
				$public       = ! empty( $obj->public );
				// Prefer REST-visible types; also keep public labeled types for tours.
				if ( ! $show_in_rest && ! $public ) {
					continue;
				}

				$counts    = wp_count_posts( $name );
				$publish   = ( is_object( $counts ) && isset( $counts->publish ) ) ? (int) $counts->publish : 0;
				$draft     = ( is_object( $counts ) && isset( $counts->draft ) ) ? (int) $counts->draft : 0;
				$items[]   = array(
					'name'         => $name,
					'label'        => isset( $obj->labels->name ) ? (string) $obj->labels->name : $name,
					'public'       => $public,
					'hierarchical' => ! empty( $obj->hierarchical ),
					'show_in_rest' => $show_in_rest,
					'count'        => $publish + $draft,
				);

				if ( count( $items ) >= self::MAX_POST_TYPES ) {
					break;
				}
			}

			usort(
				$items,
				static function ( $a, $b ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
			);

			return array(
				'ok'         => true,
				'count'      => count( $items ),
				'post_types' => $items,
			);
		}

		/**
		 * Count case-sensitive literal substring occurrences.
		 *
		 * @param string $haystack Text.
		 * @param string $needle   Find string.
		 * @return int
		 */
		public static function count_literal_occurrences( $haystack, $needle ) {
			$haystack = (string) $haystack;
			$needle   = (string) $needle;
			if ( '' === $needle || '' === $haystack ) {
				return 0;
			}
			return substr_count( $haystack, $needle );
		}

		/**
		 * Apply case-sensitive literal replace.
		 *
		 * @param string $haystack Text.
		 * @param string $find     Find.
		 * @param string $replace  Replace.
		 * @return string
		 */
		public static function apply_literal_replace( $haystack, $find, $replace ) {
			$find = (string) $find;
			if ( '' === $find ) {
				return (string) $haystack;
			}
			return str_replace( $find, (string) $replace, (string) $haystack );
		}

		/**
		 * Dry-run or execute site-wide find/replace in title + content.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_replace_in_content( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$find  = isset( $input['find'] ) ? (string) $input['find'] : '';
			if ( '' === $find ) {
				return new WP_Error( 'ahentic_missing_find', __( 'A non-empty find string is required.', 'ahentic' ) );
			}
			$replace = isset( $input['replace'] ) ? (string) $input['replace'] : '';
			$dry_run = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
			$limit   = isset( $input['limit'] ) ? (int) $input['limit'] : self::MAX_REPLACE;
			$limit   = max( 1, min( self::MAX_REPLACE, $limit ) );

			$post_types = self::normalize_post_types( isset( $input['post_type'] ) ? $input['post_type'] : null );
			$statuses   = self::normalize_statuses( isset( $input['status'] ) ? $input['status'] : null );
			$blocked    = self::blocked_post_types();
			$post_types = array_values(
				array_filter(
					$post_types,
					static function ( $type ) use ( $blocked ) {
						return ! in_array( $type, $blocked, true );
					}
				)
			);
			if ( empty( $post_types ) ) {
				return new WP_Error( 'ahentic_no_post_types', __( 'No allowed post types to scan.', 'ahentic' ) );
			}

			$wpq = new WP_Query(
				array(
					's'              => $find,
					'post_type'      => $post_types,
					'post_status'    => $statuses,
					'posts_per_page' => $limit,
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			$matches  = array();
			$updated  = array();
			$skipped  = array();
			$failed   = array();
			$match_n  = 0;

			foreach ( $wpq->posts as $post ) {
				if ( ! ( $post instanceof WP_Post ) ) {
					continue;
				}
				if ( in_array( $post->post_type, $blocked, true ) ) {
					$skipped[] = array(
						'id'     => (int) $post->ID,
						'reason' => 'blocked_post_type',
					);
					continue;
				}
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					$skipped[] = array(
						'id'     => (int) $post->ID,
						'reason' => 'cannot_edit',
					);
					continue;
				}

				$title_occ   = self::count_literal_occurrences( $post->post_title, $find );
				$content_occ = self::count_literal_occurrences( $post->post_content, $find );
				$occ         = $title_occ + $content_occ;
				if ( $occ < 1 ) {
					continue;
				}
				$match_n += $occ;

				$before = self::make_snippet( $post->post_title . "\n" . $post->post_content, $find, self::MAX_SNIPPET );
				$after_title   = self::apply_literal_replace( $post->post_title, $find, $replace );
				$after_content = self::apply_literal_replace( $post->post_content, $find, $replace );
				$after         = self::make_snippet( $after_title . "\n" . $after_content, $replace !== '' ? $replace : $find, self::MAX_SNIPPET );
				$edit          = get_edit_post_link( $post->ID, 'raw' );

				$row = array(
					'id'             => (int) $post->ID,
					'title'          => get_the_title( $post ),
					'edit_url'       => $edit ? $edit : '',
					'occurrences'    => $occ,
					'before_snippet' => $before,
					'after_snippet'  => $after,
				);

				if ( $dry_run ) {
					$matches[] = $row;
					continue;
				}

				$result = wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_title'   => $after_title,
						'post_content' => $after_content,
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					$failed[] = array(
						'id'      => (int) $post->ID,
						'error'   => $result->get_error_code(),
						'message' => $result->get_error_message(),
					);
					continue;
				}
				$updated[] = $row;
			}

			if ( $dry_run ) {
				return array(
					'ok'           => true,
					'dry_run'      => true,
					'find'         => $find,
					'replace'      => $replace,
					'match_count'  => $match_n,
					'post_count'   => count( $matches ),
					'matches'      => $matches,
					'skipped'      => $skipped,
				);
			}

			return array(
				'ok'          => true,
				'dry_run'     => false,
				'find'        => $find,
				'replace'     => $replace,
				'match_count' => $match_n,
				'updated'     => $updated,
				'skipped'     => $skipped,
				'failed'      => $failed,
			);
		}

		/**
		 * List revisions for a post.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_list_revisions( $input = array() ) {
			$input   = is_array( $input ) ? $input : array();
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			if ( $post_id <= 0 ) {
				return new WP_Error( 'ahentic_missing_post_id', __( 'A valid post_id is required.', 'ahentic' ) );
			}
			$post = get_post( $post_id );
			if ( ! ( $post instanceof WP_Post ) ) {
				return new WP_Error( 'ahentic_post_not_found', __( 'Post not found.', 'ahentic' ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'ahentic_forbidden', __( 'You cannot edit this post.', 'ahentic' ) );
			}

			$limit = isset( $input['limit'] ) ? (int) $input['limit'] : self::MAX_REVISIONS;
			$limit = max( 1, min( self::MAX_REVISIONS, $limit ) );

			$revisions = wp_get_post_revisions(
				$post_id,
				array(
					'order'          => 'DESC',
					'orderby'        => 'date ID',
					'posts_per_page' => $limit,
					'check_enabled'  => false,
				)
			);

			$items = array();
			foreach ( $revisions as $revision ) {
				if ( ! ( $revision instanceof WP_Post ) ) {
					continue;
				}
				$items[] = array(
					'id'         => (int) $revision->ID,
					'author'     => (int) $revision->post_author,
					'date'       => $revision->post_date_gmt ? $revision->post_date_gmt . 'Z' : $revision->post_date,
					'is_autosave' => function_exists( 'wp_is_post_autosave' ) ? (bool) wp_is_post_autosave( $revision ) : false,
				);
			}

			return array(
				'ok'        => true,
				'post_id'   => $post_id,
				'count'     => count( $items ),
				'revisions' => $items,
			);
		}

		/**
		 * Restore a post from one of its revisions.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_restore_revision( $input = array() ) {
			$input       = is_array( $input ) ? $input : array();
			$post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			$revision_id = isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0;
			if ( $post_id <= 0 || $revision_id <= 0 ) {
				return new WP_Error( 'ahentic_missing_ids', __( 'post_id and revision_id are required.', 'ahentic' ) );
			}

			$post = get_post( $post_id );
			if ( ! ( $post instanceof WP_Post ) ) {
				return new WP_Error( 'ahentic_post_not_found', __( 'Post not found.', 'ahentic' ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'ahentic_forbidden', __( 'You cannot edit this post.', 'ahentic' ) );
			}

			$revisions = wp_get_post_revisions( $post_id, array( 'check_enabled' => false ) );
			if ( ! isset( $revisions[ $revision_id ] ) ) {
				return new WP_Error(
					'ahentic_revision_mismatch',
					__( 'That revision_id does not belong to the given post_id.', 'ahentic' )
				);
			}

			$restored = wp_restore_post_revision( $revision_id );
			if ( ! $restored || is_wp_error( $restored ) ) {
				$message = is_wp_error( $restored ) ? $restored->get_error_message() : __( 'Could not restore revision.', 'ahentic' );
				return new WP_Error( 'ahentic_restore_failed', $message );
			}

			$fresh = get_post( $post_id );
			if ( ! ( $fresh instanceof WP_Post ) ) {
				return new WP_Error( 'ahentic_post_not_found', __( 'Post not found after restore.', 'ahentic' ) );
			}

			$summary               = self::summarize_post( $fresh, true );
			$summary['ok']         = true;
			$summary['restored_from_revision'] = $revision_id;
			return $summary;
		}

		/**
		 * Truncate a string for HITL summaries.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		private static function truncate_for_summary( $text ) {
			$text = trim( (string) $text );
			if ( self::strlen( $text ) <= 40 ) {
				return $text;
			}
			return self::substr( $text, 0, 37 ) . '…';
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
		 * Create a draft post/page/CPT.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_create_post( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$title = isset( $input['title'] ) ? trim( (string) $input['title'] ) : '';
			if ( '' === $title ) {
				return new WP_Error( 'ahentic_invalid_title', __( 'Title is required.', 'ahentic' ) );
			}

			$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';
			if ( '' === $post_type ) {
				$post_type = 'post';
			}

			$type_obj = get_post_type_object( $post_type );
			if ( ! $type_obj || ( ! $type_obj->public && ! $type_obj->show_ui ) ) {
				return new WP_Error(
					'ahentic_post_type_blocked',
					__( 'This post type cannot be created via Ahentic.', 'ahentic' )
				);
			}

			$blocked_types = self::blocked_post_types();
			if ( in_array( $post_type, $blocked_types, true ) ) {
				return new WP_Error(
					'ahentic_post_type_blocked',
					__( 'This post type cannot be created via Ahentic.', 'ahentic' )
				);
			}

			$cap = isset( $type_obj->cap->create_posts ) ? $type_obj->cap->create_posts : 'edit_posts';
			if ( ! current_user_can( $cap ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You cannot create this post type.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
			if ( ! in_array( $status, array( 'draft', 'pending' ), true ) ) {
				$status = 'draft';
			}

			$args = array(
				'post_title'  => $title,
				'post_type'   => $post_type,
				'post_status' => $status,
				'post_author' => get_current_user_id(),
			);

			if ( array_key_exists( 'content', $input ) ) {
				$content = (string) $input['content'];
				$stub    = self::reject_placeholder_content( $content );
				if ( is_wp_error( $stub ) ) {
					return $stub;
				}
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
			}

			if ( array_key_exists( 'excerpt', $input ) ) {
				$args['post_excerpt'] = (string) $input['excerpt'];
			}

			if ( array_key_exists( 'slug', $input ) ) {
				$slug = sanitize_title( (string) $input['slug'] );
				if ( '' !== $slug ) {
					$args['post_name'] = $slug;
				}
			}

			$meta_input = isset( $input['meta'] ) && is_array( $input['meta'] ) ? $input['meta'] : array();
			$meta_plan  = self::plan_meta_updates( $meta_input );
			if ( is_wp_error( $meta_plan ) ) {
				return $meta_plan;
			}

			$post_id = wp_insert_post( wp_slash( $args ), true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			if ( ! $post_id ) {
				return new WP_Error( 'ahentic_create_failed', __( 'Failed to create the post.', 'ahentic' ) );
			}

			$meta_updated = array();
			$meta_skipped = isset( $meta_plan['skipped'] ) ? $meta_plan['skipped'] : array();
			foreach ( $meta_plan['set'] as $key => $value ) {
				$ok = update_post_meta( (int) $post_id, $key, $value );
				if ( false !== $ok ) {
					$meta_updated[] = $key;
				} else {
					$meta_skipped[] = array(
						'key'    => $key,
						'reason' => 'update_failed',
					);
				}
			}

			$fresh = get_post( (int) $post_id );
			if ( ! $fresh instanceof WP_Post ) {
				return new WP_Error( 'ahentic_post_reload_failed', __( 'Post created but could not be reloaded.', 'ahentic' ) );
			}

			$summary         = self::summarize_post( $fresh, true );
			$content_raw     = (string) $fresh->post_content;
			$content_chars   = strlen( $content_raw );
			$content_preview = self::substr( wp_strip_all_tags( $content_raw ), 0, 160 );
			return array(
				'ok'               => true,
				'id'               => (int) $fresh->ID,
				'status'           => $fresh->post_status,
				'post_type'        => $fresh->post_type,
				'meta_updated'     => $meta_updated,
				'meta_skipped'     => $meta_skipped,
				'post'             => $summary,
				'content_chars'    => $content_chars,
				'content_preview'  => $content_preview,
				'content_truncated' => $content_chars > 160,
				'edit_url'         => isset( $summary['edit_url'] ) ? $summary['edit_url'] : '',
				'view_url'         => isset( $summary['view_url'] ) ? $summary['view_url'] : '',
				'hint'             => __(
					'If the user opens this post in the block editor, continue body edits with ahentic-browser/* so changes appear live.',
					'ahentic'
				),
			);
		}

		/**
		 * Change post status (publish/draft/…/trash).
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_set_post_status( $input = array() ) {
			$input  = is_array( $input ) ? $input : array();
			$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
			$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
			$allowed = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );

			if ( $id <= 0 ) {
				return new WP_Error( 'ahentic_missing_id', __( 'A valid post id is required.', 'ahentic' ) );
			}
			if ( ! in_array( $status, $allowed, true ) ) {
				return new WP_Error(
					'ahentic_invalid_status',
					__( 'Status must be one of: publish, draft, pending, private, future, trash.', 'ahentic' )
				);
			}

			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error( 'ahentic_post_not_found', __( 'Post not found.', 'ahentic' ), array( 'status' => 404 ) );
			}

			if ( in_array( $post->post_type, self::blocked_post_types(), true ) ) {
				return new WP_Error(
					'ahentic_post_type_blocked',
					__( 'This post type cannot be updated via Ahentic.', 'ahentic' )
				);
			}

			if ( 'trash' === $status ) {
				if ( ! current_user_can( 'delete_post', $post->ID ) && ! current_user_can( 'manage_options' ) ) {
					return new WP_Error(
						'ahentic_ability_forbidden',
						__( 'You cannot trash this post.', 'ahentic' ),
						array( 'status' => 403 )
					);
				}
			} elseif ( ! current_user_can( 'edit_post', $post->ID ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You cannot edit this post.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
				$type_obj = get_post_type_object( $post->post_type );
				$pub_cap  = ( $type_obj && isset( $type_obj->cap->publish_posts ) ) ? $type_obj->cap->publish_posts : 'publish_posts';
				if ( ! current_user_can( $pub_cap ) && ! current_user_can( 'manage_options' ) ) {
					return new WP_Error(
						'ahentic_ability_forbidden',
						__( 'You cannot publish this post type.', 'ahentic' ),
						array( 'status' => 403 )
					);
				}
			}

			$before_status = $post->post_status;
			$args          = array(
				'ID'          => $post->ID,
				'post_status' => $status,
			);

			if ( 'future' === $status ) {
				$date = isset( $input['date'] ) ? trim( (string) $input['date'] ) : '';
				if ( '' === $date ) {
					return new WP_Error(
						'ahentic_missing_date',
						__( 'A date (Y-m-d H:i:s) is required when status is future.', 'ahentic' )
					);
				}
				$timestamp = strtotime( $date );
				if ( ! $timestamp ) {
					return new WP_Error( 'ahentic_invalid_date', __( 'Could not parse the provided date.', 'ahentic' ) );
				}
				$args['post_date']     = wp_date( 'Y-m-d H:i:s', $timestamp );
				$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
			}

			if ( 'trash' === $status ) {
				$result = wp_trash_post( $post->ID );
				if ( ! $result ) {
					return new WP_Error( 'ahentic_trash_failed', __( 'Failed to trash the post.', 'ahentic' ) );
				}
			} else {
				$result = wp_update_post( wp_slash( $args ), true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( ! $result ) {
					return new WP_Error( 'ahentic_update_failed', __( 'Failed to update post status.', 'ahentic' ) );
				}
			}

			$fresh = get_post( $post->ID );
			if ( ! $fresh instanceof WP_Post ) {
				return new WP_Error( 'ahentic_post_reload_failed', __( 'Status updated but post could not be reloaded.', 'ahentic' ) );
			}

			$summary = self::summarize_post( $fresh, true );
			return array(
				'ok'            => true,
				'id'            => (int) $fresh->ID,
				'before_status' => $before_status,
				'status'        => $fresh->post_status,
				'post'          => $summary,
				'edit_url'      => isset( $summary['edit_url'] ) ? $summary['edit_url'] : '',
				'view_url'      => isset( $summary['view_url'] ) ? $summary['view_url'] : '',
			);
		}

		/**
		 * Post types Ahentic must not create/update/status-change.
		 *
		 * @return string[]
		 */
		private static function blocked_post_types() {
			return array(
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

			if ( in_array( $post->post_type, self::blocked_post_types(), true ) ) {
				return new WP_Error(
					'ahentic_post_type_blocked',
					__( 'This post type cannot be updated via Ahentic.', 'ahentic' )
				);
			}

			$args           = array( 'ID' => $post->ID );
			$changed_fields = array();

			if ( array_key_exists( 'content', $input ) ) {
				$editor_block = self::reject_server_doc_write_while_editor_open( (int) $post->ID, 'content' );
				if ( is_wp_error( $editor_block ) ) {
					return $editor_block;
				}
				$content = (string) $input['content'];
				$stub    = self::reject_placeholder_content( $content );
				if ( is_wp_error( $stub ) ) {
					return $stub;
				}
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
				$editor_block = self::reject_server_doc_write_while_editor_open( (int) $post->ID, 'title' );
				if ( is_wp_error( $editor_block ) ) {
					return $editor_block;
				}
				$title = trim( (string) $input['title'] );
				if ( '' === $title ) {
					return new WP_Error( 'ahentic_invalid_title', __( 'Title cannot be empty.', 'ahentic' ) );
				}
				$args['post_title'] = $title;
				$changed_fields[]   = 'title';
			}

			if ( array_key_exists( 'excerpt', $input ) ) {
				$editor_block = self::reject_server_doc_write_while_editor_open( (int) $post->ID, 'excerpt' );
				if ( is_wp_error( $editor_block ) ) {
					return $editor_block;
				}
				$args['post_excerpt'] = (string) $input['excerpt'];
				$changed_fields[]     = 'excerpt';
			}

			if ( array_key_exists( 'slug', $input ) ) {
				$editor_block = self::reject_server_doc_write_while_editor_open( (int) $post->ID, 'slug' );
				if ( is_wp_error( $editor_block ) ) {
					return $editor_block;
				}
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

			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic IN() placeholders via $type_in/$status_in and ...$params.
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
		 * Whether text looks like an LLM content stub rather than real prose.
		 *
		 * @param string $text Raw or HTML content.
		 * @return bool
		 */
		public static function looks_like_content_placeholder( $text ) {
			$plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
			if ( '' === $plain ) {
				return false;
			}

			// Bracket stubs: [expanded guide content], [full article], …
			if ( preg_match( '/^\[[^\[\]]{3,160}\]$/u', $plain ) ) {
				return true;
			}

			// Whole-string meta descriptions of content that should have been written.
			if ( preg_match(
				'/^(full|complete|expanded|entire|actual|the)\b.{0,100}\b(content|article|guide|blocks?|structure|markup|html|outline)\b\.?$/iu',
				$plain
			) ) {
				return true;
			}

			if ( preg_match( '/^(placeholder|TODO|TBD|lorem ipsum)\b/iu', $plain ) ) {
				return true;
			}

			if ( self::strlen( $plain ) <= 120 && preg_match( '/\b(block structure|gutenberg (article )?blocks|expanded guide)\b/iu', $plain ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Reject placeholder body content for create/update.
		 *
		 * @param string $content Post content.
		 * @return true|\WP_Error
		 */
		private static function reject_placeholder_content( $content ) {
			if ( ! self::looks_like_content_placeholder( $content ) ) {
				return true;
			}

			return new WP_Error(
				'ahentic_placeholder_content',
				__(
					'Content looks like a placeholder stub (e.g. [full article] or “expanded guide content”). Pass the real article text or block markup unless the user asked for placeholders.',
					'ahentic'
				),
				array(
					'hint' => __(
						'Rewrite the tool input with the full prose or Gutenberg blocks. For long articles, write one section at a time.',
						'ahentic'
					),
				)
			);
		}

		/**
		 * Block server content/title/excerpt/slug writes when that post is open in the block editor.
		 *
		 * @param int    $post_id Post being updated.
		 * @param string $field   Field being written (content|title|excerpt|slug).
		 * @return true|\WP_Error
		 */
		private static function reject_server_doc_write_while_editor_open( $post_id, $field = 'content' ) {
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
			// Only block when the open editor document is this same post.
			if ( $open_id <= 0 || $open_id !== $post_id ) {
				return true;
			}

			$browser_hint = ( 'content' === $field )
				? __(
					'Call ahentic-browser tools against the open canvas. Pass real {name, attributes, innerBlocks} objects (prefer set-blocks for full rewrites). Use block refs (b1, b2), not clientId hashes. For title/excerpt/slug use ahentic-browser/update-post-document.',
					'ahentic'
				)
				: __(
					'Use ahentic-browser/update-post-document for title, excerpt, or slug while this post is open in the block editor (keeps the document dirty until save-post).',
					'ahentic'
				);

			$message = ( 'content' === $field )
				? __(
					'The block editor is open for this document. Use ahentic-browser/set-blocks, insert-blocks, replace-blocks, delete-blocks, or update-block-attributes so edits appear live — do not ahentic/update-post for the body while the editor is open.',
					'ahentic'
				)
				: __(
					'The block editor is open for this document. Use ahentic-browser/update-post-document for title, excerpt, or slug — do not ahentic/update-post for those fields while the editor is open.',
					'ahentic'
				);

			return new WP_Error(
				'ahentic_use_browser_editor',
				$message,
				array(
					'post_id'         => $post_id,
					'editor_post_id'  => $open_id,
					'is_block_editor' => true,
					'field'           => $field,
					'hint'            => $browser_hint,
				)
			);
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

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			$map = array(
				self::LIST               => __( 'Listing posts and pages…', 'ahentic' ),
				self::GET                => __( 'Reading post content…', 'ahentic' ),
				self::SEARCH             => __( 'Searching site content…', 'ahentic' ),
				self::LIST_POST_TYPES    => __( 'Listing post types…', 'ahentic' ),
				self::REPLACE_IN_CONTENT => __( 'Replacing in content…', 'ahentic' ),
				self::LIST_REVISIONS     => __( 'Listing revisions…', 'ahentic' ),
				self::RESTORE_REVISION   => __( 'Restoring a revision…', 'ahentic' ),
				self::CREATE             => __( 'Creating a draft post…', 'ahentic' ),
				self::UPDATE             => __( 'Updating post content…', 'ahentic' ),
				self::SET_STATUS         => __( 'Updating post status…', 'ahentic' ),
			);
			$name = (string) $name;
			return isset( $map[ $name ] ) ? $map[ $name ] : '';
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Content', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Content', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Content' );
}
