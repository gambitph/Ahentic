<?php
/**
 * Classic nav menu abilities: list / get / list items / update (replace tree + locations).
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Menus' ) ) {
	/**
	 * Classic Appearance → Menus surface for the agent loop.
	 *
	 * Block theme `wp_navigation` CPT editing is out of scope (parity backlog).
	 */
	class Ahentic_Abilities_Menus {
		const LIST_MENUS = 'ahentic/list-menus';
		const LIST_ITEMS = 'ahentic/list-menu-items';
		const GET_MENU   = 'ahentic/get-menu';
		const UPDATE     = 'ahentic/update-menu';

		const MAX_LIST_ITEMS = 200;
		const MAX_TREE_DEPTH = 8;
		const MAX_ITEM_NODES = 200;

		/**
		 * Single policy catalog: drives names / write / HITL / progress / summary.
		 *
		 * @return array<string, array{write?:bool, hitl?:bool, progress:string, summary:string}>
		 */
		private static function catalog() {
			return array(
				self::LIST_MENUS => array(
					'progress' => __( 'Listing menus…', 'ahentic' ),
					'summary'  => __( 'List classic menus', 'ahentic' ),
				),
				self::LIST_ITEMS => array(
					'progress' => __( 'Listing menu items…', 'ahentic' ),
					'summary'  => __( 'List menu items', 'ahentic' ),
				),
				self::GET_MENU   => array(
					'progress' => __( 'Loading menu…', 'ahentic' ),
					'summary'  => __( 'Get classic menu', 'ahentic' ),
				),
				self::UPDATE     => array(
					'write'    => true,
					'hitl'     => true,
					'progress' => __( 'Updating menu…', 'ahentic' ),
					'summary'  => __( 'Update classic menu', 'ahentic' ),
				),
			);
		}

		/**
		 * @return string[]
		 */
		public static function names() {
			return array_keys( self::catalog() );
		}

		/**
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
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_readonly( $name ) {
			return ! in_array( (string) $name, self::write_names(), true );
		}

		/**
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
		 * Menus module has no irreversible-without-session-allow abilities today.
		 *
		 * @return string[]
		 */
		public static function non_preallowable_names() {
			return array();
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function is_non_preallowable( $name ) {
			return false;
		}

		/**
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
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function hitl_summary( $name, $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			if ( self::UPDATE !== (string) $name ) {
				return self::summary( $name );
			}

			$menu_ref = self::menu_ref_label( $input );
			$parts    = array();

			if ( array_key_exists( 'items', $input ) ) {
				$after = self::count_item_nodes( isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array() );
				$menu  = self::resolve_menu_object( isset( $input['menu'] ) ? $input['menu'] : null, false );
				$before = ( $menu && ! is_wp_error( $menu ) ) ? count( (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) ) : 0;
				$parts[] = sprintf(
					/* translators: 1: item count before, 2: item count after */
					__( 'items %1$d→%2$d', 'ahentic' ),
					(int) $before,
					(int) $after
				);
			}

			if ( array_key_exists( 'locations', $input ) ) {
				$locs = isset( $input['locations'] ) && is_array( $input['locations'] ) ? $input['locations'] : array();
				$parts[] = sprintf(
					/* translators: %d: theme location count */
					_n( '%d location', '%d locations', count( $locs ), 'ahentic' ),
					count( $locs )
				);
			}

			if ( empty( $parts ) ) {
				return sprintf(
					/* translators: %s: menu name or id */
					__( 'Update menu “%s”', 'ahentic' ),
					$menu_ref
				);
			}

			return sprintf(
				/* translators: 1: menu name, 2: change summary */
				__( 'Update menu “%1$s” (%2$s)', 'ahentic' ),
				$menu_ref,
				implode( ', ', $parts )
			);
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

		/**
		 * Register the menus ability category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			wp_register_ability_category(
				'ahentic-menus',
				array(
					'label'       => __( 'Ahentic Menus', 'ahentic' ),
					'description' => __( 'Classic WordPress navigation menus for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register menu abilities.
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'edit_theme_options' );
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

			$menu_prop = array(
				'type'        => array( 'integer', 'string' ),
				'description' => __( 'Menu term id, slug, or name.', 'ahentic' ),
			);

			wp_register_ability(
				self::LIST_MENUS,
				array(
					'label'               => __( 'List menus', 'ahentic' ),
					'description'         => __( 'Lists classic nav menus (term id, name, slug, item count, assigned theme locations). Does not cover block theme wp_navigation posts.', 'ahentic' ),
					'category'            => 'ahentic-menus',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_menus' ),
					'permission_callback' => $permission,
					'meta'                => $readonly_meta,
				)
			);

			wp_register_ability(
				self::LIST_ITEMS,
				array(
					'label'               => __( 'List menu items', 'ahentic' ),
					'description'         => __( 'Lists ordered items for one classic menu (id, title, type, object, object_id, url, parent, classes). Caps at 200 items — use get-menu for a nested tree when smaller.', 'ahentic' ),
					'category'            => 'ahentic-menus',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'menu' ),
						'properties' => array(
							'menu' => $menu_prop,
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_menu_items' ),
					'permission_callback' => $permission,
					'meta'                => $readonly_meta,
				)
			);

			wp_register_ability(
				self::GET_MENU,
				array(
					'label'               => __( 'Get menu', 'ahentic' ),
					'description'         => __( 'Loads one classic menu: metadata, nested item tree, and theme locations currently assigned to it.', 'ahentic' ),
					'category'            => 'ahentic-menus',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'menu' ),
						'properties' => array(
							'menu' => $menu_prop,
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_menu' ),
					'permission_callback' => $permission,
					'meta'                => $readonly_meta,
				)
			);

			wp_register_ability(
				self::UPDATE,
				array(
					'label'               => __( 'Update menu', 'ahentic' ),
					'description'         => __( 'Creates or updates a classic nav menu. Pass menu as id/slug/name — if missing by name/slug, creates that menu. When items is present it becomes the full item tree (replace semantics; omit items to leave items unchanged). When locations is present, this menu is assigned to exactly those theme location slugs (cleared from others; omit locations to leave assignments unchanged). Prefer this over create-post on nav_menu_item.', 'ahentic' ),
					'category'            => 'ahentic-menus',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'menu' ),
						'properties' => array(
							'menu'      => array(
								'type'        => array( 'integer', 'string' ),
								'description' => __( 'Existing menu id/slug/name, or a new name/slug to create on write.', 'ahentic' ),
							),
							'name'      => array(
								'type'        => 'string',
								'description' => __( 'Optional display name when renaming or creating.', 'ahentic' ),
							),
							'items'     => array(
								'type'        => 'array',
								'description' => __( 'When present: full replacement tree. Each node: title, type (custom|post_type|taxonomy), object, object_id, url, classes?, children?[].', 'ahentic' ),
								'items'       => array( 'type' => 'object' ),
							),
							'locations' => array(
								'type'        => 'array',
								'description' => __( 'When present: theme location slugs this menu should occupy exclusively (empty array clears all).', 'ahentic' ),
								'items'       => array( 'type' => 'string' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_update_menu' ),
					'permission_callback' => $permission,
					'meta'                => $mutate_meta,
				)
			);
		}

		/**
		 * @param string $name  Ability name.
		 * @param array  $input Input.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			switch ( $name ) {
				case self::LIST_MENUS:
					return self::execute_list_menus( $input );
				case self::LIST_ITEMS:
					return self::execute_list_menu_items( $input );
				case self::GET_MENU:
					return self::execute_get_menu( $input );
				case self::UPDATE:
					return self::execute_update_menu( $input );
			}
			return new WP_Error(
				'ahentic_unknown_ability',
				__( 'Unknown menus ability.', 'ahentic' ),
				array( 'status' => 404 )
			);
		}

		/**
		 * @param array $input Unused.
		 * @return array|\WP_Error
		 */
		public static function execute_list_menus( $input = array() ) {
			unset( $input );
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return self::permission_error();
			}

			$locations = self::location_map();
			$menus     = array();
			foreach ( (array) wp_get_nav_menus() as $menu ) {
				$menus[] = self::summarize_menu( $menu, $locations );
			}

			return array(
				'ok'    => true,
				'count' => count( $menus ),
				'menus' => $menus,
			);
		}

		/**
		 * @param array $input Input with menu.
		 * @return array|\WP_Error
		 */
		public static function execute_list_menu_items( $input = array() ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return self::permission_error();
			}

			$input = is_array( $input ) ? $input : array();
			$menu  = self::resolve_menu_object( isset( $input['menu'] ) ? $input['menu'] : null, false );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}

			$raw = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
			if ( false === $raw ) {
				$raw = array();
			}
			$raw = array_values( (array) $raw );

			if ( count( $raw ) > self::MAX_LIST_ITEMS ) {
				return new WP_Error(
					'ahentic_menu_too_large',
					__( 'This menu has too many items to list unboundedly.', 'ahentic' ),
					array(
						'status' => 400,
						'hint'   => sprintf(
							/* translators: %d: max items */
							__( 'Cap is %d items. Trim the menu in Appearance → Menus, or pass a smaller menu.', 'ahentic' ),
							self::MAX_LIST_ITEMS
						),
						'count'  => count( $raw ),
					)
				);
			}

			$items = array();
			foreach ( $raw as $item ) {
				$items[] = self::summarize_item_flat( $item );
			}

			return array(
				'ok'    => true,
				'menu'  => self::summarize_menu( $menu, self::location_map() ),
				'count' => count( $items ),
				'items' => $items,
			);
		}

		/**
		 * @param array $input Input with menu.
		 * @return array|\WP_Error
		 */
		public static function execute_get_menu( $input = array() ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return self::permission_error();
			}

			$input = is_array( $input ) ? $input : array();
			$menu  = self::resolve_menu_object( isset( $input['menu'] ) ? $input['menu'] : null, false );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}

			$raw = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
			if ( false === $raw ) {
				$raw = array();
			}
			$raw = array_values( (array) $raw );

			if ( count( $raw ) > self::MAX_LIST_ITEMS ) {
				return new WP_Error(
					'ahentic_menu_too_large',
					__( 'This menu has too many items to return as a tree.', 'ahentic' ),
					array(
						'status' => 400,
						'count'  => count( $raw ),
					)
				);
			}

			return array(
				'ok'        => true,
				'menu'      => self::summarize_menu( $menu, self::location_map() ),
				'locations' => self::locations_for_menu( (int) $menu->term_id ),
				'items'     => self::build_item_tree( $raw ),
			);
		}

		/**
		 * @param array $input Update payload.
		 * @return array|\WP_Error
		 */
		public static function execute_update_menu( $input = array() ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return self::permission_error();
			}

			$input = is_array( $input ) ? $input : array();
			if ( ! array_key_exists( 'menu', $input ) ) {
				return new WP_Error(
					'ahentic_menu_required',
					__( 'menu is required (id, slug, or name).', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			$has_items     = array_key_exists( 'items', $input );
			$has_locations = array_key_exists( 'locations', $input );
			$has_name      = isset( $input['name'] ) && '' !== trim( (string) $input['name'] );

			if ( ! $has_items && ! $has_locations && ! $has_name ) {
				return new WP_Error(
					'ahentic_menu_noop',
					__( 'Nothing to update: pass items, locations, and/or name.', 'ahentic' ),
					array(
						'status' => 400,
						'hint'   => __( 'Omit keys you want left unchanged; include items and/or locations to replace those surfaces.', 'ahentic' ),
					)
				);
			}

			if ( $has_items ) {
				if ( ! is_array( $input['items'] ) ) {
					return new WP_Error(
						'ahentic_invalid_menu_items',
						__( 'items must be an array (use [] to clear the menu).', 'ahentic' ),
						array( 'status' => 400 )
					);
				}
				$node_count = self::count_item_nodes( $input['items'] );
				if ( $node_count > self::MAX_ITEM_NODES ) {
					return new WP_Error(
						'ahentic_menu_too_large',
						__( 'Too many menu items in the submitted tree.', 'ahentic' ),
						array(
							'status' => 400,
							'count'  => $node_count,
							'max'    => self::MAX_ITEM_NODES,
						)
					);
				}
				$depth_err = self::assert_tree_depth( $input['items'], 0 );
				if ( is_wp_error( $depth_err ) ) {
					return $depth_err;
				}
			}

			if ( $has_locations && ! is_array( $input['locations'] ) ) {
				return new WP_Error(
					'ahentic_invalid_menu_locations',
					__( 'locations must be an array of theme location slugs (use [] to clear).', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			$menu = self::resolve_menu_object( $input['menu'], true );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}

			$created = ! empty( $menu->_ahentic_created );

			if ( $has_name ) {
				$new_name = sanitize_text_field( (string) $input['name'] );
				if ( $new_name !== (string) $menu->name ) {
					$updated = wp_update_nav_menu_object(
						(int) $menu->term_id,
						array( 'menu-name' => $new_name )
					);
					if ( is_wp_error( $updated ) ) {
						return $updated;
					}
					$menu = wp_get_nav_menu_object( (int) $menu->term_id );
				}
			}

			$items_before = count( (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) );
			$items_after  = $items_before;

			if ( $has_items ) {
				$replace = self::replace_menu_items( (int) $menu->term_id, $input['items'] );
				if ( is_wp_error( $replace ) ) {
					return $replace;
				}
				$items_after = (int) $replace['count'];
			}

			$locations_assigned = self::locations_for_menu( (int) $menu->term_id );
			if ( $has_locations ) {
				$loc_result = self::assign_menu_locations( (int) $menu->term_id, $input['locations'] );
				if ( is_wp_error( $loc_result ) ) {
					return $loc_result;
				}
				$locations_assigned = $loc_result;
			}

			$fresh = wp_get_nav_menu_object( (int) $menu->term_id );
			$raw   = wp_get_nav_menu_items( (int) $menu->term_id, array( 'post_status' => 'publish' ) );
			if ( false === $raw ) {
				$raw = array();
			}

			return array(
				'ok'          => true,
				'created'     => (bool) $created,
				'menu'        => self::summarize_menu( $fresh ? $fresh : $menu, self::location_map() ),
				'locations'   => $locations_assigned,
				'items_count' => array(
					'before' => (int) $items_before,
					'after'  => (int) $items_after,
				),
				'items'       => self::build_item_tree( array_values( (array) $raw ) ),
			);
		}

		/**
		 * Resolve a menu by id / slug / name; optionally create by name/slug.
		 *
		 * @param mixed $ref    Menu ref.
		 * @param bool  $create Create when missing string name/slug.
		 * @return \WP_Term|\WP_Error
		 */
		public static function resolve_menu_object( $ref, $create = false ) {
			if ( null === $ref || '' === $ref ) {
				return new WP_Error(
					'ahentic_menu_not_found',
					__( 'Menu not found.', 'ahentic' ),
					array( 'status' => 404 )
				);
			}

			if ( is_numeric( $ref ) ) {
				$menu = wp_get_nav_menu_object( (int) $ref );
				if ( $menu ) {
					return $menu;
				}
				return new WP_Error(
					'ahentic_menu_not_found',
					__( 'Menu not found.', 'ahentic' ),
					array(
						'status' => 404,
						'hint'   => __( 'Pass an existing menu id, or a name/slug to create via update-menu.', 'ahentic' ),
					)
				);
			}

			$ref_str = trim( (string) $ref );
			$menu    = wp_get_nav_menu_object( $ref_str );
			if ( $menu ) {
				return $menu;
			}

			// Match by name when slug lookup failed.
			foreach ( (array) wp_get_nav_menus() as $candidate ) {
				if ( 0 === strcasecmp( (string) $candidate->name, $ref_str ) ) {
					return $candidate;
				}
			}

			if ( ! $create ) {
				return new WP_Error(
					'ahentic_menu_not_found',
					__( 'Menu not found.', 'ahentic' ),
					array(
						'status' => 404,
						'hint'   => __( 'Use list-menus, or create with update-menu by passing a new menu name.', 'ahentic' ),
					)
				);
			}

			$menu_id = wp_create_nav_menu( $ref_str );
			if ( is_wp_error( $menu_id ) ) {
				return $menu_id;
			}

			$menu = wp_get_nav_menu_object( (int) $menu_id );
			if ( ! $menu ) {
				return new WP_Error(
					'ahentic_menu_create_failed',
					__( 'Could not create the menu.', 'ahentic' ),
					array( 'status' => 500 )
				);
			}
			$menu->_ahentic_created = true;
			return $menu;
		}

		/**
		 * Replace all items on a menu with the submitted nested tree.
		 *
		 * @param int   $menu_id Menu term id.
		 * @param array $items   Nested item nodes.
		 * @return array{count:int}|\WP_Error
		 */
		public static function replace_menu_items( $menu_id, array $items ) {
			$menu_id = (int) $menu_id;
			$existing = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
			if ( is_array( $existing ) ) {
				foreach ( $existing as $old ) {
					wp_delete_post( (int) $old->ID, true );
				}
			}

			$created = self::insert_item_tree( $menu_id, $items, 0, 0 );
			if ( is_wp_error( $created ) ) {
				return $created;
			}

			return array( 'count' => (int) $created );
		}

		/**
		 * @param int   $menu_id Menu term id.
		 * @param array $nodes   Sibling nodes.
		 * @param int   $parent  Parent menu item db id (0 = top).
		 * @param int   $order   Starting menu_order.
		 * @return int|\WP_Error Created count.
		 */
		private static function insert_item_tree( $menu_id, array $nodes, $parent, $order ) {
			$count = 0;
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					return new WP_Error(
						'ahentic_invalid_menu_items',
						__( 'Each menu item must be an object.', 'ahentic' ),
						array( 'status' => 400 )
					);
				}

				$built = self::build_nav_menu_item_args( $node, $parent, $order );
				if ( is_wp_error( $built ) ) {
					return $built;
				}

				$item_id = wp_update_nav_menu_item( $menu_id, 0, $built );
				if ( is_wp_error( $item_id ) ) {
					return $item_id;
				}
				if ( ! $item_id ) {
					return new WP_Error(
						'ahentic_menu_item_failed',
						__( 'Could not create a menu item.', 'ahentic' ),
						array( 'status' => 500 )
					);
				}

				++$count;
				++$order;

				$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
				if ( ! empty( $children ) ) {
					$child_count = self::insert_item_tree( $menu_id, $children, (int) $item_id, 0 );
					if ( is_wp_error( $child_count ) ) {
						return $child_count;
					}
					$count += (int) $child_count;
				}
			}
			return $count;
		}

		/**
		 * @param array $node   Item node.
		 * @param int   $parent Parent db id.
		 * @param int   $order  Menu order.
		 * @return array|\WP_Error
		 */
		private static function build_nav_menu_item_args( array $node, $parent, $order ) {
			$type = isset( $node['type'] ) ? sanitize_key( (string) $node['type'] ) : 'custom';
			if ( ! in_array( $type, array( 'custom', 'post_type', 'taxonomy' ), true ) ) {
				return new WP_Error(
					'ahentic_invalid_menu_item_type',
					__( 'Menu item type must be custom, post_type, or taxonomy.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			$title = isset( $node['title'] ) ? sanitize_text_field( (string) $node['title'] ) : '';
			$url   = isset( $node['url'] ) ? esc_url_raw( (string) $node['url'] ) : '';
			$object = isset( $node['object'] ) ? sanitize_key( (string) $node['object'] ) : '';
			$object_id = isset( $node['object_id'] ) ? (int) $node['object_id'] : 0;

			$classes = array();
			if ( isset( $node['classes'] ) ) {
				if ( is_array( $node['classes'] ) ) {
					$classes = array_map( 'sanitize_html_class', $node['classes'] );
				} elseif ( is_string( $node['classes'] ) ) {
					$classes = array_map( 'sanitize_html_class', preg_split( '/\s+/', $node['classes'] ) );
				}
			}

			$args = array(
				'menu-item-title'     => $title,
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => (int) $parent,
				'menu-item-position'  => (int) $order,
				'menu-item-classes'   => implode( ' ', array_filter( $classes ) ),
			);

			if ( 'custom' === $type ) {
				if ( '' === $url ) {
					$url = '#';
				}
				if ( '' === $title ) {
					$title = $url;
					$args['menu-item-title'] = $title;
				}
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = $url;
				return $args;
			}

			if ( $object_id <= 0 || '' === $object ) {
				return new WP_Error(
					'ahentic_invalid_menu_item',
					__( 'post_type and taxonomy items require object and object_id.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			$args['menu-item-type']      = $type;
			$args['menu-item-object']    = $object;
			$args['menu-item-object-id'] = $object_id;

			if ( '' === $title ) {
				if ( 'post_type' === $type ) {
					$post = get_post( $object_id );
					if ( $post ) {
						$args['menu-item-title'] = $post->post_title;
					}
				} elseif ( 'taxonomy' === $type ) {
					$term = get_term( $object_id, $object );
					if ( $term && ! is_wp_error( $term ) ) {
						$args['menu-item-title'] = $term->name;
					}
				}
			}

			return $args;
		}

		/**
		 * Assign this menu to the given theme locations exclusively.
		 *
		 * @param int   $menu_id   Menu term id.
		 * @param array $locations Location slugs.
		 * @return string[]|\WP_Error Assigned location slugs.
		 */
		public static function assign_menu_locations( $menu_id, array $locations ) {
			$menu_id = (int) $menu_id;
			$wanted  = array();
			foreach ( $locations as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' !== $slug ) {
					$wanted[] = $slug;
				}
			}
			$wanted = array_values( array_unique( $wanted ) );

			$registered = get_registered_nav_menus();
			foreach ( $wanted as $slug ) {
				if ( ! isset( $registered[ $slug ] ) ) {
					return new WP_Error(
						'ahentic_unknown_menu_location',
						sprintf(
							/* translators: %s: location slug */
							__( 'Unknown theme menu location: %s', 'ahentic' ),
							$slug
						),
						array(
							'status'              => 400,
							'registered_locations' => array_keys( $registered ),
						)
					);
				}
			}

			$map = get_nav_menu_locations();
			if ( ! is_array( $map ) ) {
				$map = array();
			}

			foreach ( $map as $loc => $assigned ) {
				if ( (int) $assigned === $menu_id && ! in_array( $loc, $wanted, true ) ) {
					unset( $map[ $loc ] );
				}
			}
			foreach ( $wanted as $slug ) {
				$map[ $slug ] = $menu_id;
			}

			set_theme_mod( 'nav_menu_locations', $map );
			return $wanted;
		}

		/**
		 * @param \WP_Term $menu      Menu term.
		 * @param array    $locations Location map (slug => menu id).
		 * @return array
		 */
		private static function summarize_menu( $menu, array $locations ) {
			$locs = array();
			foreach ( $locations as $slug => $id ) {
				if ( (int) $id === (int) $menu->term_id ) {
					$locs[] = (string) $slug;
				}
			}
			return array(
				'id'        => (int) $menu->term_id,
				'name'      => (string) $menu->name,
				'slug'      => (string) $menu->slug,
				'count'     => (int) $menu->count,
				'locations' => $locs,
			);
		}

		/**
		 * @param object $item Menu item.
		 * @return array
		 */
		private static function summarize_item_flat( $item ) {
			$classes = isset( $item->classes ) ? (array) $item->classes : array();
			$classes = array_values( array_filter( array_map( 'strval', $classes ) ) );
			return array(
				'id'        => (int) $item->ID,
				'title'     => (string) $item->title,
				'type'      => (string) $item->type,
				'object'    => (string) $item->object,
				'object_id' => (int) $item->object_id,
				'url'       => (string) $item->url,
				'parent'    => (int) $item->menu_item_parent,
				'classes'   => $classes,
			);
		}

		/**
		 * @param object[] $items Flat WP menu items.
		 * @return array Nested tree.
		 */
		public static function build_item_tree( array $items ) {
			$by_parent = array();
			foreach ( $items as $item ) {
				$parent = (int) $item->menu_item_parent;
				if ( ! isset( $by_parent[ $parent ] ) ) {
					$by_parent[ $parent ] = array();
				}
				$by_parent[ $parent ][] = $item;
			}

			$walk = function ( $parent_id ) use ( &$walk, $by_parent ) {
				$out = array();
				if ( empty( $by_parent[ $parent_id ] ) ) {
					return $out;
				}
				foreach ( $by_parent[ $parent_id ] as $item ) {
					$node               = self::summarize_item_flat( $item );
					$node['children'] = $walk( (int) $item->ID );
					$out[]              = $node;
				}
				return $out;
			};

			return $walk( 0 );
		}

		/**
		 * @return array<string,int>
		 */
		private static function location_map() {
			$map = get_nav_menu_locations();
			return is_array( $map ) ? $map : array();
		}

		/**
		 * @param int $menu_id Menu term id.
		 * @return string[]
		 */
		private static function locations_for_menu( $menu_id ) {
			$menu_id = (int) $menu_id;
			$out     = array();
			foreach ( self::location_map() as $slug => $id ) {
				if ( (int) $id === $menu_id ) {
					$out[] = (string) $slug;
				}
			}
			return $out;
		}

		/**
		 * @param array $items Nested items.
		 * @return int
		 */
		public static function count_item_nodes( array $items ) {
			$count = 0;
			foreach ( $items as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				++$count;
				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					$count += self::count_item_nodes( $node['children'] );
				}
			}
			return $count;
		}

		/**
		 * @param array $items Nested items.
		 * @param int   $depth Current depth.
		 * @return true|\WP_Error
		 */
		private static function assert_tree_depth( array $items, $depth ) {
			if ( $depth > self::MAX_TREE_DEPTH ) {
				return new WP_Error(
					'ahentic_menu_too_deep',
					__( 'Menu item tree exceeds the maximum nesting depth.', 'ahentic' ),
					array(
						'status' => 400,
						'max'    => self::MAX_TREE_DEPTH,
					)
				);
			}
			foreach ( $items as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
				if ( ! empty( $children ) ) {
					$err = self::assert_tree_depth( $children, $depth + 1 );
					if ( is_wp_error( $err ) ) {
						return $err;
					}
				}
			}
			return true;
		}

		/**
		 * @param array $input Ability input.
		 * @return string
		 */
		private static function menu_ref_label( array $input ) {
			if ( ! isset( $input['menu'] ) ) {
				return __( 'menu', 'ahentic' );
			}
			$ref = $input['menu'];
			if ( is_numeric( $ref ) ) {
				$menu = function_exists( 'wp_get_nav_menu_object' ) ? wp_get_nav_menu_object( (int) $ref ) : null;
				if ( $menu ) {
					return (string) $menu->name;
				}
				return '#' . (int) $ref;
			}
			$label = trim( (string) $ref );
			return '' !== $label ? $label : __( 'menu', 'ahentic' );
		}

		/**
		 * @return \WP_Error
		 */
		private static function permission_error() {
			return new WP_Error(
				'ahentic_forbidden',
				__( 'You do not have permission to manage menus.', 'ahentic' ),
				array( 'status' => 403 )
			);
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Menus', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Menus', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Menus' );
}
