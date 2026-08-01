<?php
/**
 * Taxonomy abilities: update term fields and safe term meta.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Taxonomy' ) ) {
	/**
	 * Taxonomy term mutations for the agent loop.
	 */
	class Ahentic_Abilities_Taxonomy {
		const UPDATE = 'ahentic/update-term';

		const MAX_META_KEYS   = 40;
		const MAX_META_VALUE  = 4000;
		const MAX_DESCRIPTION = 5000;

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array( self::UPDATE );
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

			$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
			$term_ref = '';
			if ( isset( $input['term_id'] ) && '' !== $input['term_id'] && null !== $input['term_id'] ) {
				$term_ref = '#' . (int) $input['term_id'];
				$term     = get_term( (int) $input['term_id'], $taxonomy ? $taxonomy : null );
				if ( $term && ! is_wp_error( $term ) ) {
					$term_ref = $term->name . ' (#' . (int) $term->term_id . ')';
				}
			} elseif ( isset( $input['term'] ) && '' !== $input['term'] && null !== $input['term'] ) {
				$term_ref = (string) $input['term'];
			}

			$fields = array();
			foreach ( array( 'name', 'slug', 'description', 'parent', 'meta' ) as $field ) {
				if ( array_key_exists( $field, $input ) ) {
					$fields[] = $field;
				}
			}
			$fields_label = ! empty( $fields ) ? implode( ', ', $fields ) : __( 'fields', 'ahentic' );

			return sprintf(
				/* translators: 1: taxonomy, 2: term label, 3: field list */
				__( 'Update term “%2$s” in %1$s: %3$s', 'ahentic' ),
				$taxonomy ? $taxonomy : __( 'taxonomy', 'ahentic' ),
				$term_ref ? $term_ref : __( 'unknown', 'ahentic' ),
				$fields_label
			);
		}

		/**
		 * Register abilities (uses ahentic-content category when present).
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'manage_categories' ) || current_user_can( 'manage_options' );
			};

			$mutate_meta = array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
			);

			wp_register_ability(
				self::UPDATE,
				array(
					'label'               => __( 'Update taxonomy term', 'ahentic' ),
					'description'         => __( 'Updates an existing taxonomy term (name, slug, description, parent) and optional non-private term meta. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy' ),
						'properties' => array(
							'taxonomy'    => array(
								'type'        => 'string',
								'description' => __( 'Taxonomy slug (e.g. category, post_tag).', 'ahentic' ),
							),
							'term_id'     => array(
								'type'        => 'integer',
								'description' => __( 'Term ID (preferred when known).', 'ahentic' ),
							),
							'term'        => array(
								'description' => __( 'Term identifier: ID, slug, or name (used when term_id is omitted).', 'ahentic' ),
							),
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
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_update_term' ),
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
				case self::UPDATE:
					return self::execute_update_term( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown taxonomy ability.', 'ahentic' ) );
			}
		}

		/**
		 * Update a taxonomy term’s core fields and/or safe meta.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_update_term( $input = array() ) {
			$input    = is_array( $input ) ? $input : array();
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
				return $term;
			}

			$args          = array();
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
				'ok'              => true,
				'taxonomy'        => $taxonomy,
				'term_id'         => (int) $fresh->term_id,
				'changed_fields'  => $changed_fields,
				'meta_updated'    => $meta_updated,
				'meta_skipped'    => $meta_skipped,
				'before'          => $before,
				'term'            => $after,
				'edit_url'        => self::term_edit_url( $fresh, $taxonomy ),
			);
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
		 * @param mixed  $parent     Parent ref.
		 * @param string $taxonomy   Taxonomy.
		 * @param int    $self_id    Current term ID (reject self-parent).
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
	}
}
