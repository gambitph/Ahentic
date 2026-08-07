<?php
/**
 * Taxonomy abilities: list/get/create/update/delete terms + post term helpers.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Taxonomy' ) ) {
	/**
	 * Taxonomy term CRUD and post assignment helpers for the agent loop.
	 */
	class Ahentic_Abilities_Taxonomy {
		const LIST   = 'ahentic/list-terms';
		const GET    = 'ahentic/get-term';
		const CREATE = 'ahentic/create-term';
		const UPDATE = 'ahentic/update-term';
		const DELETE = 'ahentic/delete-term';

		const MAX_META_KEYS   = 40;
		const MAX_META_VALUE  = 4000;
		const MAX_DESCRIPTION = 5000;
		const MAX_LIST        = 100;
		const DEFAULT_LIST    = 50;

		/**
		 * Single policy catalog: drives names / write / HITL / non_preallowable / progress / summary.
		 *
		 * @return array<string, array{write?:bool, hitl?:bool, non_preallowable?:bool, progress:string, summary:string}>
		 */
		private static function catalog() {
			return array(
				self::LIST   => array(
					'progress' => __( 'Listing taxonomy terms…', 'ahentic' ),
					'summary'  => __( 'List taxonomy terms', 'ahentic' ),
				),
				self::GET    => array(
					'progress' => __( 'Loading taxonomy term…', 'ahentic' ),
					'summary'  => __( 'Get taxonomy term', 'ahentic' ),
				),
				self::CREATE => array(
					'write'    => true,
					'hitl'     => true,
					'progress' => __( 'Creating taxonomy term…', 'ahentic' ),
					'summary'  => __( 'Create taxonomy term', 'ahentic' ),
				),
				self::UPDATE => array(
					'write'    => true,
					'hitl'     => true,
					'progress' => __( 'Updating taxonomy term…', 'ahentic' ),
					'summary'  => __( 'Update taxonomy term', 'ahentic' ),
				),
				self::DELETE => array(
					'write'            => true,
					'hitl'             => true,
					'non_preallowable' => true,
					'progress'         => __( 'Deleting taxonomy term…', 'ahentic' ),
					'summary'          => __( 'Delete taxonomy term', 'ahentic' ),
				),
			);
		}

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array_keys( self::catalog() );
		}

		/**
		 * Write (non-readonly) ability names.
		 *
		 * @return string[]
		 */
		public static function write_names() {
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['write'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
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
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['hitl'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * @param string $name Ability name.
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
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['non_preallowable'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function is_non_preallowable( $name ) {
			return in_array( (string) $name, self::non_preallowable_names(), true );
		}

		/**
		 * Short summary for pending-tool UI / progress.
		 *
		 * @param string $name Ability.
		 * @return string
		 */
		public static function summary( $name ) {
			$catalog = self::catalog();
			$key     = (string) $name;
			if ( isset( $catalog[ $key ]['summary'] ) ) {
				return $catalog[ $key ]['summary'];
			}
			return $key;
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

			$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
			$tax_label = $taxonomy ? $taxonomy : __( 'taxonomy', 'ahentic' );

			if ( self::CREATE === $name ) {
				$term_name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
				return sprintf(
					/* translators: 1: taxonomy, 2: term name */
					__( 'Create term “%2$s” in %1$s', 'ahentic' ),
					$tax_label,
					$term_name ? $term_name : __( 'new term', 'ahentic' )
				);
			}

			if ( self::DELETE === $name ) {
				$term_ref = self::hitl_term_ref( $input, $taxonomy );
				return sprintf(
					/* translators: 1: taxonomy, 2: term label */
					__( 'Delete term “%2$s” from %1$s', 'ahentic' ),
					$tax_label,
					$term_ref
				);
			}

			if ( self::UPDATE !== $name ) {
				return self::summary( $name );
			}

			$term_ref = self::hitl_term_ref( $input, $taxonomy );
			$fields   = array();
			foreach ( array( 'name', 'slug', 'description', 'parent', 'meta' ) as $field ) {
				if ( array_key_exists( $field, $input ) ) {
					$fields[] = $field;
				}
			}
			$fields_label = ! empty( $fields ) ? implode( ', ', $fields ) : __( 'fields', 'ahentic' );

			return sprintf(
				/* translators: 1: taxonomy, 2: term label, 3: field list */
				__( 'Update term “%2$s” in %1$s: %3$s', 'ahentic' ),
				$tax_label,
				$term_ref,
				$fields_label
			);
		}

		/**
		 * Reject incomplete update/delete identity before HITL pause.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return true|\WP_Error
		 */
		public static function hitl_preflight( $name, $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$name  = (string) $name;

			if ( self::CREATE === $name ) {
				$term_name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
				if ( '' === $term_name ) {
					return new WP_Error(
						'ahentic_missing_name',
						__( 'A term name is required.', 'ahentic' ),
						array(
							'hint' => __( 'Pass taxonomy and name to ahentic/create-term.', 'ahentic' ),
						)
					);
				}
				return true;
			}

			if ( self::UPDATE === $name || self::DELETE === $name ) {
				$has_id = isset( $input['term_id'] ) && '' !== $input['term_id'] && null !== $input['term_id'] && (int) $input['term_id'] > 0;
				$term   = isset( $input['term'] ) ? trim( (string) $input['term'] ) : '';
				if ( ! $has_id && '' === $term ) {
					return new WP_Error(
						'ahentic_missing_term',
						__( 'Provide term_id or term (ID, slug, or name).', 'ahentic' ),
						array(
							'hint' => __( 'Identify the existing term with term_id or term before update-term / delete-term.', 'ahentic' ),
						)
					);
				}
			}

			return true;
		}

		/**
		 * Compact term label for HITL copy.
		 *
		 * Identity only — do not use mutable `name` (new value on update-term).
		 *
		 * @param array  $input    Input.
		 * @param string $taxonomy Taxonomy slug.
		 * @return string
		 */
		private static function hitl_term_ref( array $input, $taxonomy ) {
			if ( isset( $input['term_id'] ) && '' !== $input['term_id'] && null !== $input['term_id'] ) {
				$term_id  = (int) $input['term_id'];
				$term_ref = '#' . $term_id;
				if ( $term_id > 0 && function_exists( 'get_term' ) ) {
					$term = get_term( $term_id, $taxonomy ? $taxonomy : null );
					if ( $term && ! is_wp_error( $term ) ) {
						$term_ref = $term->name . ' (#' . (int) $term->term_id . ')';
					}
				}
				return $term_ref;
			}
			if ( isset( $input['term'] ) && '' !== $input['term'] && null !== $input['term'] ) {
				$term_label = trim( (string) $input['term'] );
				if ( '' !== $term_label ) {
					return $term_label;
				}
			}
			return __( 'unspecified term', 'ahentic' );
		}

		/**
		 * Register abilities (uses ahentic-content category when present).
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'manage_categories' ) || current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' );
			};

			$readonly_meta = array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
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

			$destructive_meta = array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
			);

			$term_ref_props = array(
				'taxonomy' => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy slug (e.g. category, post_tag).', 'ahentic' ),
				),
				'term_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Term ID (preferred when known).', 'ahentic' ),
				),
				'term'     => array(
					'description' => __( 'Term identifier: ID, slug, or name (used when term_id is omitted).', 'ahentic' ),
				),
			);

			wp_register_ability(
				self::LIST,
				array(
					'label'               => __( 'List taxonomy terms', 'ahentic' ),
					'description'         => __( 'Lists terms in a taxonomy (id, name, slug, description, parent, count). Filter by search, parent, hide_empty, or number.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy' ),
						'properties' => array(
							'taxonomy'   => array(
								'type'        => 'string',
								'description' => __( 'Taxonomy slug (e.g. category, post_tag).', 'ahentic' ),
							),
							'search'     => array(
								'type'        => 'string',
								'description' => __( 'Optional search string (name/slug).', 'ahentic' ),
							),
							'parent'     => array(
								'description' => __( 'Optional parent term ID (hierarchical taxonomies). Use 0 for top-level only.', 'ahentic' ),
							),
							'hide_empty' => array(
								'type'        => 'boolean',
								'description' => __( 'When true, omit terms with count 0 (default false).', 'ahentic' ),
							),
							'number'     => array(
								'type'        => 'integer',
								'description' => __( 'Max terms to return (default 50, max 100).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_terms' ),
					'permission_callback' => $permission,
					'meta'                => $readonly_meta,
				)
			);

			wp_register_ability(
				self::GET,
				array(
					'label'               => __( 'Get taxonomy term', 'ahentic' ),
					'description'         => __( 'Loads one taxonomy term by term_id or term (ID/slug/name). Optional safe term meta.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy' ),
						'properties' => array_merge(
							$term_ref_props,
							array(
								'include_meta' => array(
									'type'        => 'boolean',
									'description' => __( 'Include safe term meta (default false).', 'ahentic' ),
								),
							)
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_term' ),
					'permission_callback' => $permission,
					'meta'                => $readonly_meta,
				)
			);

			wp_register_ability(
				self::CREATE,
				array(
					'label'               => __( 'Create taxonomy term', 'ahentic' ),
					'description'         => __( 'Creates a taxonomy term (wp_insert_term). Pass taxonomy + name; optional slug, description, parent, meta. Requires human approval in Ahentic. Do not use update-term to create.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy', 'name' ),
						'properties' => array(
							'taxonomy'    => array(
								'type'        => 'string',
								'description' => __( 'Taxonomy slug (e.g. category, post_tag).', 'ahentic' ),
							),
							'name'        => array(
								'type'        => 'string',
								'description' => __( 'Term name.', 'ahentic' ),
							),
							'slug'        => array(
								'type'        => 'string',
								'description' => __( 'Optional term slug.', 'ahentic' ),
							),
							'description' => array(
								'type'        => 'string',
								'description' => __( 'Optional term description.', 'ahentic' ),
							),
							'parent'      => array(
								'description' => __( 'Optional parent term (ID, slug, or name). Hierarchical taxonomies only.', 'ahentic' ),
							),
							'meta'        => array(
								'type'                 => 'object',
								'description'          => __( 'Term meta key/value pairs to set. Underscore keys are allowed unless system/sensitive.', 'ahentic' ),
								'additionalProperties' => true,
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_create_term' ),
					'permission_callback' => $permission,
					'meta'                => $mutate_meta,
				)
			);

			wp_register_ability(
				self::UPDATE,
				array(
					'label'               => __( 'Update taxonomy term', 'ahentic' ),
					'description'         => __( 'Updates an existing taxonomy term (name, slug, description, parent) and optional non-private term meta. Requires an existing term — use create-term for new terms. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy' ),
						'properties' => array_merge(
							$term_ref_props,
							array(
								'name'        => array(
									'type'        => 'string',
									'description' => __( 'New term name.', 'ahentic' ),
								),
								'slug'        => array(
									'type'        => 'string',
									'description' => __( 'New term slug.', 'ahentic' ),
								),
								'description' => array(
									'type'        => 'string',
									'description' => __( 'New term description.', 'ahentic' ),
								),
								'parent'      => array(
									'description' => __( 'New parent term (ID, slug, or name). Hierarchical taxonomies only; use 0 to clear.', 'ahentic' ),
								),
								'meta'        => array(
									'type'                 => 'object',
									'description'          => __( 'Term meta key/value pairs to set (exact keys). Underscore keys are allowed unless system/sensitive.', 'ahentic' ),
									'additionalProperties' => true,
								),
							)
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_update_term' ),
					'permission_callback' => $permission,
					'meta'                => $mutate_meta,
				)
			);

			wp_register_ability(
				self::DELETE,
				array(
					'label'               => __( 'Delete taxonomy term', 'ahentic' ),
					'description'         => __( 'Deletes a taxonomy term. Refuses when the term is still assigned to posts (count > 0) — reassign or clear terms on those posts first. Always requires a fresh Allow (not session/always).', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy' ),
						'properties' => $term_ref_props,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_delete_term' ),
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
					return self::execute_list_terms( $input );
				case self::GET:
					return self::execute_get_term( $input );
				case self::CREATE:
					return self::execute_create_term( $input );
				case self::UPDATE:
					return self::execute_update_term( $input );
				case self::DELETE:
					return self::execute_delete_term( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown taxonomy ability.', 'ahentic' ) );
			}
		}

		/**
		 * List terms in a taxonomy.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_list_terms( $input = array() ) {
			$input    = is_array( $input ) ? $input : array();
			$taxonomy = self::require_taxonomy( $input );
			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}

			$tax_obj = get_taxonomy( $taxonomy );
			if ( ! $tax_obj || ! self::user_can_assign_or_edit_terms( $tax_obj ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to list terms in this taxonomy.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$number = isset( $input['number'] ) ? (int) $input['number'] : self::DEFAULT_LIST;
			$number = max( 1, min( self::MAX_LIST, $number ) );

			$args = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => ! empty( $input['hide_empty'] ),
				'number'     => $number,
				'orderby'    => 'name',
				'order'      => 'ASC',
			);

			if ( isset( $input['search'] ) && '' !== $input['search'] && null !== $input['search'] ) {
				$args['search'] = (string) $input['search'];
			}

			if ( array_key_exists( 'parent', $input ) && null !== $input['parent'] && '' !== $input['parent'] ) {
				$args['parent'] = (int) $input['parent'];
			}

			$terms = get_terms( $args );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}

			$out = array();
			foreach ( (array) $terms as $term ) {
				if ( $term instanceof WP_Term ) {
					$out[] = self::summarize_term( $term, $taxonomy, false );
				}
			}

			return array(
				'ok'       => true,
				'taxonomy' => $taxonomy,
				'count'    => count( $out ),
				'number'   => $number,
				'terms'    => $out,
			);
		}

		/**
		 * Get one term.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_get_term( $input = array() ) {
			$input    = is_array( $input ) ? $input : array();
			$taxonomy = self::require_taxonomy( $input );
			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}

			$tax_obj = get_taxonomy( $taxonomy );
			if ( ! $tax_obj || ! self::user_can_assign_or_edit_terms( $tax_obj ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to read terms in this taxonomy.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$term = self::resolve_term( $input, $taxonomy );
			if ( is_wp_error( $term ) ) {
				return $term;
			}

			$with_meta = ! empty( $input['include_meta'] );
			$card      = self::summarize_term( $term, $taxonomy, $with_meta );

			return array(
				'ok'       => true,
				'taxonomy' => $taxonomy,
				'term'     => $card,
				'edit_url' => self::term_edit_url( $term, $taxonomy ),
			);
		}

		/**
		 * Create a term.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_create_term( $input = array() ) {
			$input    = is_array( $input ) ? $input : array();
			$taxonomy = self::require_taxonomy( $input );
			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}

			$tax_obj = get_taxonomy( $taxonomy );
			if ( ! $tax_obj || ! self::user_can_edit_terms( $tax_obj ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to create terms in this taxonomy.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
			if ( '' === $name ) {
				return new WP_Error( 'ahentic_invalid_name', __( 'Term name is required.', 'ahentic' ) );
			}

			$args = array();
			if ( array_key_exists( 'slug', $input ) && '' !== $input['slug'] && null !== $input['slug'] ) {
				$slug = sanitize_title( (string) $input['slug'] );
				if ( '' === $slug ) {
					return new WP_Error( 'ahentic_invalid_slug', __( 'Term slug cannot be empty.', 'ahentic' ) );
				}
				$args['slug'] = $slug;
			}

			if ( array_key_exists( 'description', $input ) ) {
				$description = (string) $input['description'];
				if ( strlen( $description ) > self::MAX_DESCRIPTION ) {
					$description = substr( $description, 0, self::MAX_DESCRIPTION );
				}
				$args['description'] = $description;
			}

			if ( array_key_exists( 'parent', $input ) ) {
				if ( empty( $tax_obj->hierarchical ) ) {
					return new WP_Error(
						'ahentic_parent_not_hierarchical',
						__( 'Parent can only be set on hierarchical taxonomies.', 'ahentic' )
					);
				}
				$parent_id = self::resolve_parent_id( $input['parent'], $taxonomy, 0 );
				if ( is_wp_error( $parent_id ) ) {
					return $parent_id;
				}
				$args['parent'] = $parent_id;
			}

			$meta_input = isset( $input['meta'] ) && is_array( $input['meta'] ) ? $input['meta'] : array();
			$meta_plan  = self::plan_meta_updates( $meta_input );
			if ( is_wp_error( $meta_plan ) ) {
				return $meta_plan;
			}

			$result = wp_insert_term( $name, $taxonomy, $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$term_id = isset( $result['term_id'] ) ? (int) $result['term_id'] : 0;
			if ( $term_id <= 0 ) {
				return new WP_Error( 'ahentic_create_term_failed', __( 'Failed to create the term.', 'ahentic' ) );
			}

			$meta_updated = array();
			$meta_skipped = isset( $meta_plan['skipped'] ) ? $meta_plan['skipped'] : array();
			foreach ( $meta_plan['set'] as $key => $value ) {
				$ok = update_term_meta( $term_id, $key, $value );
				if ( false === $ok ) {
					$meta_skipped[] = array(
						'key'    => $key,
						'reason' => 'update_failed',
					);
					continue;
				}
				$meta_updated[] = $key;
			}

			$fresh = get_term( $term_id, $taxonomy );
			if ( ! $fresh || is_wp_error( $fresh ) ) {
				return new WP_Error( 'ahentic_term_reload_failed', __( 'Term created but could not be reloaded.', 'ahentic' ) );
			}

			return array(
				'ok'           => true,
				'taxonomy'     => $taxonomy,
				'term_id'      => (int) $fresh->term_id,
				'meta_updated' => $meta_updated,
				'meta_skipped' => $meta_skipped,
				'term'         => self::summarize_term( $fresh, $taxonomy, true ),
				'edit_url'     => self::term_edit_url( $fresh, $taxonomy ),
			);
		}

		/**
		 * Update a taxonomy term’s core fields and/or safe meta.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_update_term( $input = array() ) {
			$input    = is_array( $input ) ? $input : array();
			$taxonomy = self::require_taxonomy( $input );
			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}

			$tax_obj = get_taxonomy( $taxonomy );
			if ( ! $tax_obj || ! self::user_can_edit_terms( $tax_obj ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to edit terms in this taxonomy.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$term = self::resolve_term( $input, $taxonomy );
			if ( is_wp_error( $term ) ) {
				if ( 'ahentic_term_not_found' === $term->get_error_code() ) {
					$data = $term->get_error_data();
					if ( ! is_array( $data ) ) {
						$data = array();
					}
					$data['hint']      = __( 'Term does not exist yet. Use ahentic/create-term to create it, then update-term or assign it on a post.', 'ahentic' );
					$data['next_tool'] = array(
						'name' => self::CREATE,
					);
					return new WP_Error( $term->get_error_code(), $term->get_error_message(), $data );
				}
				return $term;
			}

			$args           = array();
			$changed_fields = array();

			if ( array_key_exists( 'name', $input ) ) {
				$name = trim( (string) $input['name'] );
				if ( '' === $name ) {
					return new WP_Error( 'ahentic_invalid_name', __( 'Term name cannot be empty.', 'ahentic' ) );
				}
				$args['name']     = $name;
				$changed_fields[] = 'name';
			}

			if ( array_key_exists( 'slug', $input ) ) {
				$slug = sanitize_title( (string) $input['slug'] );
				if ( '' === $slug ) {
					return new WP_Error( 'ahentic_invalid_slug', __( 'Term slug cannot be empty.', 'ahentic' ) );
				}
				$args['slug']     = $slug;
				$changed_fields[] = 'slug';
			}

			if ( array_key_exists( 'description', $input ) ) {
				$description = (string) $input['description'];
				if ( strlen( $description ) > self::MAX_DESCRIPTION ) {
					$description = substr( $description, 0, self::MAX_DESCRIPTION );
				}
				$args['description'] = $description;
				$changed_fields[]    = 'description';
			}

			if ( array_key_exists( 'parent', $input ) ) {
				if ( empty( $tax_obj->hierarchical ) ) {
					return new WP_Error(
						'ahentic_parent_not_hierarchical',
						__( 'Parent can only be set on hierarchical taxonomies.', 'ahentic' )
					);
				}

				$parent_id = self::resolve_parent_id( $input['parent'], $taxonomy, (int) $term->term_id );
				if ( is_wp_error( $parent_id ) ) {
					return $parent_id;
				}

				$args['parent']   = $parent_id;
				$changed_fields[] = 'parent';
			}

			$meta_input = isset( $input['meta'] ) && is_array( $input['meta'] ) ? $input['meta'] : array();
			$meta_plan  = self::plan_meta_updates( $meta_input );
			if ( is_wp_error( $meta_plan ) ) {
				return $meta_plan;
			}

			if ( empty( $args ) && empty( $meta_plan['set'] ) ) {
				$skipped  = isset( $meta_plan['skipped'] ) && is_array( $meta_plan['skipped'] ) ? $meta_plan['skipped'] : array();
				$has_meta = ! empty( $meta_input );
				if ( $has_meta && ! empty( $skipped ) ) {
					$keys = array();
					foreach ( $skipped as $row ) {
						if ( ! empty( $row['key'] ) ) {
							$keys[] = (string) $row['key'];
						}
					}
					return new WP_Error(
						'ahentic_meta_not_applied',
						sprintf(
							/* translators: %s: comma-separated meta keys */
							__( 'Meta keys were provided but none could be applied (%s).', 'ahentic' ),
							implode( ', ', $keys )
						),
						array(
							'status'       => 400,
							'meta_skipped' => $skipped,
						)
					);
				}
				return new WP_Error(
					'ahentic_nothing_to_update',
					__( 'Provide at least one of: name, slug, description, parent, or meta.', 'ahentic' )
				);
			}

			$before = self::summarize_term( $term, $taxonomy, true );

			if ( ! empty( $args ) ) {
				$result = wp_update_term( (int) $term->term_id, $taxonomy, $args );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			$meta_updated = array();
			$meta_skipped = isset( $meta_plan['skipped'] ) ? $meta_plan['skipped'] : array();
			foreach ( $meta_plan['set'] as $key => $value ) {
				$prev = get_term_meta( (int) $term->term_id, $key, true );
				$ok   = update_term_meta( (int) $term->term_id, $key, $value );
				// false can mean failure OR unchanged value — treat unchanged as success.
				if ( false === $ok && (string) $prev !== (string) $value ) {
					$meta_skipped[] = array(
						'key'    => $key,
						'reason' => 'update_failed',
					);
					continue;
				}
				$meta_updated[] = $key;
			}

			$fresh = get_term( (int) $term->term_id, $taxonomy );
			if ( ! $fresh || is_wp_error( $fresh ) ) {
				return new WP_Error( 'ahentic_term_reload_failed', __( 'Term updated but could not be reloaded.', 'ahentic' ) );
			}

			$after = self::summarize_term( $fresh, $taxonomy, true );

			return array(
				'ok'             => true,
				'taxonomy'       => $taxonomy,
				'term_id'        => (int) $fresh->term_id,
				'changed_fields' => $changed_fields,
				'meta_updated'   => $meta_updated,
				'meta_skipped'   => $meta_skipped,
				'before'         => $before,
				'term'           => $after,
				'edit_url'       => self::term_edit_url( $fresh, $taxonomy ),
			);
		}

		/**
		 * Delete a term (refuses when still assigned to posts).
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_delete_term( $input = array() ) {
			$input    = is_array( $input ) ? $input : array();
			$taxonomy = self::require_taxonomy( $input );
			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}

			$tax_obj = get_taxonomy( $taxonomy );
			if ( ! $tax_obj || ! self::user_can_delete_terms( $tax_obj ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to delete terms in this taxonomy.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$term = self::resolve_term( $input, $taxonomy );
			if ( is_wp_error( $term ) ) {
				return $term;
			}

			// Prefer live object links over $term->count (count can lag until recount).
			$object_ids = get_objects_in_term( (int) $term->term_id, $taxonomy );
			if ( is_wp_error( $object_ids ) ) {
				return $object_ids;
			}
			$count = is_array( $object_ids ) ? count( $object_ids ) : 0;
			if ( $count > 0 ) {
				return new WP_Error(
					'ahentic_term_in_use',
					sprintf(
						/* translators: 1: term name, 2: post count */
						__( 'Term “%1$s” is assigned to %2$d post(s). Reassign or remove it from those posts first (ahentic/update-post with categories, tags, or tax_input), then delete.', 'ahentic' ),
						$term->name,
						$count
					),
					array(
						'status'   => 409,
						'term_id'  => (int) $term->term_id,
						'taxonomy' => $taxonomy,
						'count'    => $count,
						'hint'     => __( 'Clear or replace the term on posts via create-post/update-post tax fields, then retry delete-term.', 'ahentic' ),
					)
				);
			}

			$before = self::summarize_term( $term, $taxonomy, false );
			$result = wp_delete_term( (int) $term->term_id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( false === $result || 0 === $result ) {
				return new WP_Error( 'ahentic_delete_term_failed', __( 'Failed to delete the term.', 'ahentic' ) );
			}

			return array(
				'ok'       => true,
				'taxonomy' => $taxonomy,
				'term_id'  => (int) $before['term_id'],
				'deleted'  => $before,
			);
		}

		/**
		 * Whether ability input includes any taxonomy-assignment keys.
		 *
		 * @param array $input Ability input.
		 * @return bool
		 */
		public static function input_has_term_assignment( array $input ) {
			return array_key_exists( 'categories', $input )
				|| array_key_exists( 'tags', $input )
				|| array_key_exists( 'tax_input', $input );
		}

		/**
		 * Assigned terms for a post, grouped by taxonomy (id/name/slug).
		 *
		 * @param int $post_id Post ID.
		 * @return array<string, array<int, array{id:int,name:string,slug:string}>>
		 */
		public static function summarize_post_terms( $post_id ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return array();
			}

			$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
			$out        = array();
			foreach ( (array) $taxonomies as $taxonomy ) {
				$taxonomy = (string) $taxonomy;
				if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$terms = get_the_terms( $post_id, $taxonomy );
				if ( ! is_array( $terms ) || empty( $terms ) ) {
					continue;
				}
				$rows = array();
				foreach ( $terms as $term ) {
					if ( ! $term instanceof WP_Term ) {
						continue;
					}
					$rows[] = array(
						'id'   => (int) $term->term_id,
						'name' => (string) $term->name,
						'slug' => (string) $term->slug,
					);
				}
				if ( ! empty( $rows ) ) {
					$out[ $taxonomy ] = $rows;
				}
			}
			return $out;
		}

		/**
		 * Apply categories / tags / tax_input to a post (replace-per-taxonomy).
		 *
		 * Present key → full set for that taxonomy (append=false). Omit → unchanged.
		 * Missing term refs return an error steering to create-term (no auto-create).
		 *
		 * @param int   $post_id Post ID.
		 * @param array $input   Ability input (may include categories, tags, tax_input).
		 * @return array{applied: array<string, int[]>}|\WP_Error
		 */
		public static function apply_post_terms( $post_id, array $input ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error( 'ahentic_post_not_found', __( 'Post not found.', 'ahentic' ), array( 'status' => 404 ) );
			}

			$plan = self::plan_post_term_assignment( $post->post_type, $input );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}

			$applied = array();
			foreach ( $plan as $taxonomy => $term_ids ) {
				$tax_obj = get_taxonomy( $taxonomy );
				if ( ! $tax_obj || ! self::user_can_assign_terms( $tax_obj, $post_id ) ) {
					return new WP_Error(
						'ahentic_ability_forbidden',
						sprintf(
							/* translators: %s: taxonomy slug */
							__( 'You do not have permission to assign terms in taxonomy “%s”.', 'ahentic' ),
							$taxonomy
						),
						array( 'status' => 403 )
					);
				}

				$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$applied[ $taxonomy ] = array_map( 'intval', $term_ids );
			}

			return array(
				'applied' => $applied,
			);
		}

		/**
		 * Build taxonomy → term ID list from categories/tags/tax_input.
		 *
		 * @param string $post_type Post type.
		 * @param array  $input     Ability input.
		 * @return array<string, int[]>|\WP_Error
		 */
		public static function plan_post_term_assignment( $post_type, array $input ) {
			$registered = get_object_taxonomies( $post_type, 'names' );
			$registered = is_array( $registered ) ? array_map( 'strval', $registered ) : array();

			$map = array();

			if ( array_key_exists( 'categories', $input ) ) {
				if ( ! in_array( 'category', $registered, true ) ) {
					return new WP_Error(
						'ahentic_taxonomy_not_for_post_type',
						__( 'This post type does not support categories.', 'ahentic' )
					);
				}
				$resolved = self::resolve_term_ref_list( $input['categories'], 'category' );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
				$map['category'] = $resolved;
			}

			if ( array_key_exists( 'tags', $input ) ) {
				if ( ! in_array( 'post_tag', $registered, true ) ) {
					return new WP_Error(
						'ahentic_taxonomy_not_for_post_type',
						__( 'This post type does not support tags.', 'ahentic' )
					);
				}
				$resolved = self::resolve_term_ref_list( $input['tags'], 'post_tag' );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
				$map['post_tag'] = $resolved;
			}

			if ( array_key_exists( 'tax_input', $input ) ) {
				$tax_input = $input['tax_input'];
				if ( ! is_array( $tax_input ) ) {
					return new WP_Error(
						'ahentic_invalid_tax_input',
						__( 'tax_input must be an object mapping taxonomy slug → list of term ids/slugs/names.', 'ahentic' )
					);
				}
				foreach ( $tax_input as $taxonomy => $refs ) {
					$taxonomy = sanitize_key( (string) $taxonomy );
					if ( '' === $taxonomy ) {
						continue;
					}
					if ( ! in_array( $taxonomy, $registered, true ) ) {
						return new WP_Error(
							'ahentic_taxonomy_not_for_post_type',
							sprintf(
								/* translators: 1: taxonomy, 2: post type */
								__( 'Taxonomy “%1$s” is not registered for post type “%2$s”.', 'ahentic' ),
								$taxonomy,
								$post_type
							)
						);
					}
					if ( ! taxonomy_exists( $taxonomy ) ) {
						return new WP_Error(
							'ahentic_taxonomy_not_found',
							sprintf(
								/* translators: %s: taxonomy slug */
								__( 'Taxonomy “%s” does not exist.', 'ahentic' ),
								$taxonomy
							),
							array( 'status' => 404 )
						);
					}
					$resolved = self::resolve_term_ref_list( $refs, $taxonomy );
					if ( is_wp_error( $resolved ) ) {
						return $resolved;
					}
					$map[ $taxonomy ] = $resolved;
				}
			}

			return $map;
		}

		/**
		 * Resolve a list of term refs (ids/slugs/names) to term IDs.
		 *
		 * @param mixed  $refs     List or single ref.
		 * @param string $taxonomy Taxonomy.
		 * @return int[]|\WP_Error
		 */
		private static function resolve_term_ref_list( $refs, $taxonomy ) {
			if ( null === $refs || '' === $refs ) {
				return array();
			}
			if ( ! is_array( $refs ) ) {
				$refs = array( $refs );
			}

			$ids = array();
			foreach ( $refs as $ref ) {
				if ( null === $ref || '' === $ref ) {
					continue;
				}
				$term = self::resolve_term(
					is_numeric( $ref )
						? array( 'term_id' => (int) $ref )
						: array( 'term' => $ref ),
					$taxonomy
				);
				if ( is_wp_error( $term ) ) {
					$ref_label = is_scalar( $ref ) ? (string) $ref : wp_json_encode( $ref );
					return new WP_Error(
						'ahentic_term_not_found',
						sprintf(
							/* translators: 1: term ref, 2: taxonomy */
							__( 'Could not find term “%1$s” in taxonomy “%2$s”. Create it with ahentic/create-term first, then assign it.', 'ahentic' ),
							$ref_label ? $ref_label : __( 'unspecified term', 'ahentic' ),
							$taxonomy
						),
						array(
							'status'    => 404,
							'taxonomy'  => $taxonomy,
							'term_ref'  => $ref_label,
							'hint'      => __( 'Call ahentic/create-term with this taxonomy and name, then retry the post write with the new term id or slug.', 'ahentic' ),
							'next_tool' => array(
								'name'  => self::CREATE,
								'input' => array(
									'taxonomy' => $taxonomy,
									'name'     => is_string( $ref ) ? $ref : '',
								),
							),
						)
					);
				}
				$ids[] = (int) $term->term_id;
			}

			return array_values( array_unique( $ids ) );
		}

		/**
		 * Validate taxonomy slug from input.
		 *
		 * @param array $input Input.
		 * @return string|\WP_Error
		 */
		private static function require_taxonomy( array $input ) {
			$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
			if ( '' === $taxonomy ) {
				return new WP_Error( 'ahentic_missing_taxonomy', __( 'A taxonomy slug is required.', 'ahentic' ) );
			}
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error(
					'ahentic_taxonomy_not_found',
					sprintf(
						/* translators: %s: taxonomy slug */
						__( 'Taxonomy “%s” does not exist.', 'ahentic' ),
						$taxonomy
					),
					array( 'status' => 404 )
				);
			}
			return $taxonomy;
		}

		/**
		 * Resolve the target term from input.
		 *
		 * @param array  $input    Input.
		 * @param string $taxonomy Taxonomy slug.
		 * @return \WP_Term|\WP_Error
		 */
		private static function resolve_term( array $input, $taxonomy ) {
			if ( isset( $input['term_id'] ) && '' !== $input['term_id'] && null !== $input['term_id'] ) {
				$term_id = (int) $input['term_id'];
				if ( $term_id <= 0 ) {
					return new WP_Error( 'ahentic_invalid_term_id', __( 'term_id must be a positive integer.', 'ahentic' ) );
				}
				$term = get_term( $term_id, $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error(
						'ahentic_term_not_found',
						__( 'Term not found in that taxonomy.', 'ahentic' ),
						array( 'status' => 404 )
					);
				}
				return $term;
			}

			if ( ! array_key_exists( 'term', $input ) || null === $input['term'] || '' === $input['term'] ) {
				return new WP_Error(
					'ahentic_missing_term',
					__( 'Provide term_id or term (ID, slug, or name).', 'ahentic' )
				);
			}

			$ref = $input['term'];
			if ( is_numeric( $ref ) ) {
				$term = get_term( (int) $ref, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}

			$ref_str = trim( (string) $ref );
			if ( '' === $ref_str ) {
				return new WP_Error( 'ahentic_missing_term', __( 'Provide term_id or term (ID, slug, or name).', 'ahentic' ) );
			}

			$by_slug = get_term_by( 'slug', sanitize_title( $ref_str ), $taxonomy );
			if ( $by_slug && ! is_wp_error( $by_slug ) ) {
				return $by_slug;
			}

			$by_name = get_term_by( 'name', $ref_str, $taxonomy );
			if ( $by_name && ! is_wp_error( $by_name ) ) {
				return $by_name;
			}

			return new WP_Error(
				'ahentic_term_not_found',
				sprintf(
					/* translators: 1: term ref, 2: taxonomy */
					__( 'Could not find term “%1$s” in taxonomy “%2$s”.', 'ahentic' ),
					$ref_str,
					$taxonomy
				),
				array( 'status' => 404 )
			);
		}

		/**
		 * Resolve parent term ID (0 clears parent).
		 *
		 * @param mixed  $parent   Parent ref.
		 * @param string $taxonomy Taxonomy.
		 * @param int    $self_id  Current term ID (reject self-parent).
		 * @return int|\WP_Error
		 */
		private static function resolve_parent_id( $parent, $taxonomy, $self_id ) {
			if ( null === $parent || '' === $parent || 0 === $parent || '0' === $parent ) {
				return 0;
			}

			if ( is_numeric( $parent ) ) {
				$parent_id = (int) $parent;
				if ( $parent_id < 0 ) {
					return new WP_Error( 'ahentic_invalid_parent', __( 'Parent term ID is invalid.', 'ahentic' ) );
				}
				if ( 0 === $parent_id ) {
					return 0;
				}
				if ( $parent_id === $self_id ) {
					return new WP_Error( 'ahentic_invalid_parent', __( 'A term cannot be its own parent.', 'ahentic' ) );
				}
				$term = get_term( $parent_id, $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error( 'ahentic_parent_not_found', __( 'Parent term not found.', 'ahentic' ), array( 'status' => 404 ) );
				}
				return $parent_id;
			}

			$ref = trim( (string) $parent );
			if ( '' === $ref ) {
				return 0;
			}

			$term = get_term_by( 'slug', sanitize_title( $ref ), $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				$term = get_term_by( 'name', $ref, $taxonomy );
			}
			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error(
					'ahentic_parent_not_found',
					sprintf(
						/* translators: %s: parent ref */
						__( 'Parent term “%s” not found.', 'ahentic' ),
						$ref
					),
					array( 'status' => 404 )
				);
			}

			if ( (int) $term->term_id === $self_id ) {
				return new WP_Error( 'ahentic_invalid_parent', __( 'A term cannot be its own parent.', 'ahentic' ) );
			}

			return (int) $term->term_id;
		}

		/**
		 * Validate and normalize meta updates.
		 *
		 * Underscore keys are allowed unless the key looks sensitive.
		 *
		 * @param array $meta Raw meta map.
		 * @return array{set: array<string, mixed>, skipped: array<int, array{key: string, reason: string}>}|\WP_Error
		 */
		private static function plan_meta_updates( array $meta ) {
			$set     = array();
			$skipped = array();
			$count   = 0;

			foreach ( $meta as $key => $value ) {
				$key = (string) $key;
				if ( '' === $key ) {
					continue;
				}

				$key_l = strtolower( $key );
				foreach ( array( 'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'private_key', 'salt' ) as $needle ) {
					if ( false !== strpos( $key_l, $needle ) ) {
						$skipped[] = array(
							'key'    => $key,
							'reason' => 'sensitive_key',
						);
						continue 2;
					}
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
		 * Compact term card for before/after.
		 *
		 * @param \WP_Term $term      Term.
		 * @param string   $taxonomy  Taxonomy.
		 * @param bool     $with_meta Include non-private meta sample.
		 * @return array
		 */
		private static function summarize_term( WP_Term $term, $taxonomy, $with_meta = false ) {
			$item = array(
				'term_id'     => (int) $term->term_id,
				'name'        => (string) $term->name,
				'slug'        => (string) $term->slug,
				'description' => (string) $term->description,
				'parent'      => (int) $term->parent,
				'count'       => (int) $term->count,
				'taxonomy'    => $taxonomy,
			);

			if ( $with_meta ) {
				$item['meta'] = self::get_safe_term_meta( (int) $term->term_id );
			}

			return $item;
		}

		/**
		 * Term meta sample (capped; skips empty keys only).
		 *
		 * @param int $term_id Term ID.
		 * @return array<string, string>
		 */
		private static function get_safe_term_meta( $term_id ) {
			$all = get_term_meta( $term_id );
			if ( ! is_array( $all ) ) {
				return array();
			}

			$out   = array();
			$count = 0;
			foreach ( $all as $key => $values ) {
				$key = (string) $key;
				if ( '' === $key ) {
					continue;
				}
				if ( $count >= self::MAX_META_KEYS ) {
					break;
				}
				$raw = is_array( $values ) ? ( isset( $values[0] ) ? $values[0] : '' ) : $values;
				if ( is_array( $raw ) || is_object( $raw ) ) {
					$raw = wp_json_encode( $raw );
					if ( false === $raw ) {
						continue;
					}
				}
				$raw = (string) $raw;
				if ( strlen( $raw ) > self::MAX_META_VALUE ) {
					$raw = substr( $raw, 0, self::MAX_META_VALUE ) . '…';
				}
				$out[ $key ] = $raw;
				++$count;
			}

			return $out;
		}

		/**
		 * Admin edit URL for a term when available.
		 *
		 * @param \WP_Term $term     Term.
		 * @param string   $taxonomy Taxonomy.
		 * @return string
		 */
		private static function term_edit_url( WP_Term $term, $taxonomy ) {
			if ( ! is_admin() && ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_categories' ) ) {
				return '';
			}

			$url = get_edit_term_link( (int) $term->term_id, $taxonomy );
			return is_string( $url ) ? $url : '';
		}

		/**
		 * Capability check for editing terms in a taxonomy.
		 *
		 * @param \WP_Taxonomy $tax_obj Taxonomy object.
		 * @return bool
		 */
		private static function user_can_edit_terms( $tax_obj ) {
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			$cap = '';
			if ( isset( $tax_obj->cap->edit_terms ) ) {
				$cap = (string) $tax_obj->cap->edit_terms;
			} elseif ( isset( $tax_obj->cap->manage_terms ) ) {
				$cap = (string) $tax_obj->cap->manage_terms;
			}

			if ( '' !== $cap && current_user_can( $cap ) ) {
				return true;
			}

			return current_user_can( 'manage_categories' );
		}

		/**
		 * Capability check for deleting terms.
		 *
		 * @param \WP_Taxonomy $tax_obj Taxonomy object.
		 * @return bool
		 */
		private static function user_can_delete_terms( $tax_obj ) {
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			$cap = '';
			if ( isset( $tax_obj->cap->delete_terms ) ) {
				$cap = (string) $tax_obj->cap->delete_terms;
			} elseif ( isset( $tax_obj->cap->edit_terms ) ) {
				$cap = (string) $tax_obj->cap->edit_terms;
			}

			if ( '' !== $cap && current_user_can( $cap ) ) {
				return true;
			}

			return current_user_can( 'manage_categories' );
		}

		/**
		 * Capability check for listing/reading terms (assign or edit).
		 *
		 * @param \WP_Taxonomy $tax_obj Taxonomy object.
		 * @return bool
		 */
		private static function user_can_assign_or_edit_terms( $tax_obj ) {
			if ( self::user_can_edit_terms( $tax_obj ) ) {
				return true;
			}
			if ( isset( $tax_obj->cap->assign_terms ) && current_user_can( (string) $tax_obj->cap->assign_terms ) ) {
				return true;
			}
			return current_user_can( 'edit_posts' );
		}

		/**
		 * Capability check for assigning terms on a post.
		 *
		 * @param \WP_Taxonomy $tax_obj Taxonomy object.
		 * @param int          $post_id Post ID.
		 * @return bool
		 */
		private static function user_can_assign_terms( $tax_obj, $post_id ) {
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}
			if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
				return false;
			}
			if ( isset( $tax_obj->cap->assign_terms ) && current_user_can( (string) $tax_obj->cap->assign_terms ) ) {
				return true;
			}
			return self::user_can_edit_terms( $tax_obj );
		}

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			$catalog = self::catalog();
			$key     = (string) $name;
			if ( isset( $catalog[ $key ]['progress'] ) ) {
				return $catalog[ $key ]['progress'];
			}
			return '';
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Taxonomy', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Taxonomy' );
}
