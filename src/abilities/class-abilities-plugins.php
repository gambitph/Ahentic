<?php
/**
 * Plugin abilities: list, search, install, activate, deactivate.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Plugins' ) ) {
	/**
	 * Plugin inspection and HITL install/activate/deactivate for the agent loop.
	 */
	class Ahentic_Abilities_Plugins {
		const LIST       = 'ahentic/list-plugins';
		const SEARCH     = 'ahentic/search-plugins';
		const INSTALL    = 'ahentic/install-plugin';
		const ACTIVATE   = 'ahentic/activate-plugin';
		const DEACTIVATE = 'ahentic/deactivate-plugin';

		/**
		 * @return string[]
		 */
		public static function names() {
			return array( self::LIST, self::SEARCH, self::INSTALL, self::ACTIVATE, self::DEACTIVATE );
		}

		/**
		 * Abilities that must pause for human approval before running.
		 *
		 * @return string[]
		 */
		public static function hitl_names() {
			return array( self::INSTALL, self::ACTIVATE, self::DEACTIVATE );
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function requires_hitl( $name ) {
			return in_array( (string) $name, self::hitl_names(), true );
		}

		/**
		 * Register category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}
			wp_register_ability_category(
				'ahentic-plugins',
				array(
					'label'       => __( 'Ahentic Plugins', 'ahentic' ),
					'description' => __( 'List, search, install, activate, and deactivate plugins for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register abilities.
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$can_manage = static function () {
				return current_user_can( 'activate_plugins' ) || current_user_can( 'manage_options' );
			};
			$can_install = static function () {
				return current_user_can( 'install_plugins' ) || current_user_can( 'manage_options' );
			};

			$readonly_meta = array(
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'show_in_rest' => false,
			);
			$mutate_meta   = array(
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
					'label'               => __( 'List plugins', 'ahentic' ),
					'description'         => __( 'Lists installed plugins (active and inactive) with names and versions.', 'ahentic' ),
					'category'            => 'ahentic-plugins',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'status' => array(
								'type'        => 'string',
								'enum'        => array( 'all', 'active', 'inactive' ),
								'description' => __( 'Filter by active state (default: all).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_plugins' ),
					'permission_callback' => $can_manage,
					'meta'                => $readonly_meta,
				)
			);

			wp_register_ability(
				self::SEARCH,
				array(
					'label'               => __( 'Search plugins', 'ahentic' ),
					'description'         => __( 'Searches the WordPress.org plugin directory and returns top matches with slugs and ratings.', 'ahentic' ),
					'category'            => 'ahentic-plugins',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'query' ),
						'properties' => array(
							'query' => array(
								'type'        => 'string',
								'description' => __( 'Search phrase (e.g. SEO).', 'ahentic' ),
							),
							'limit' => array(
								'type'        => 'integer',
								'description' => __( 'Max results (1–15).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_search_plugins' ),
					'permission_callback' => $can_manage,
					'meta'                => $readonly_meta,
				)
			);

			wp_register_ability(
				self::INSTALL,
				array(
					'label'               => __( 'Install plugin', 'ahentic' ),
					'description'         => __( 'Installs a plugin from WordPress.org by slug. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-plugins',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug' ),
						'properties' => array(
							'slug' => array(
								'type'        => 'string',
								'description' => __( 'Plugin directory slug (e.g. wordpress-seo).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_install_plugin' ),
					'permission_callback' => $can_install,
					'meta'                => $mutate_meta,
				)
			);

			wp_register_ability(
				self::ACTIVATE,
				array(
					'label'               => __( 'Activate plugin', 'ahentic' ),
					'description'         => __( 'Activates an installed plugin by file or slug. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-plugins',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'plugin'      => array(
								'type'        => 'string',
								'description' => __( 'Plugin file (folder/file.php) or slug.', 'ahentic' ),
							),
							'plugin_file' => array(
								'type'        => 'string',
								'description' => __( 'Plugin file (alias of plugin; matches install-plugin output).', 'ahentic' ),
							),
							'slug'        => array(
								'type'        => 'string',
								'description' => __( 'Plugin slug (alternative to plugin).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_activate_plugin' ),
					'permission_callback' => $can_manage,
					'meta'                => $mutate_meta,
				)
			);

			wp_register_ability(
				self::DEACTIVATE,
				array(
					'label'               => __( 'Deactivate plugin', 'ahentic' ),
					'description'         => __( 'Deactivates an active plugin by file or slug. Requires human approval in Ahentic.', 'ahentic' ),
					'category'            => 'ahentic-plugins',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'plugin'      => array(
								'type'        => 'string',
								'description' => __( 'Plugin file (folder/file.php) or slug.', 'ahentic' ),
							),
							'plugin_file' => array(
								'type'        => 'string',
								'description' => __( 'Plugin file (alias of plugin).', 'ahentic' ),
							),
							'slug'        => array(
								'type'        => 'string',
								'description' => __( 'Plugin slug (alternative to plugin).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_deactivate_plugin' ),
					'permission_callback' => $can_manage,
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
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			switch ( $name ) {
				case self::LIST:
					return self::execute_list_plugins( $input );
				case self::SEARCH:
					return self::execute_search_plugins( $input );
				case self::INSTALL:
					return self::execute_install_plugin( $input );
				case self::ACTIVATE:
					return self::execute_activate_plugin( $input );
				case self::DEACTIVATE:
					return self::execute_deactivate_plugin( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown plugin ability.', 'ahentic' ) );
			}
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
			if ( self::INSTALL === $name ) {
				$slug = isset( $input['slug'] ) ? (string) $input['slug'] : '';
				return sprintf(
					/* translators: %s: plugin slug */
					__( 'Install plugin “%s” from WordPress.org', 'ahentic' ),
					$slug ? $slug : __( 'unknown', 'ahentic' )
				);
			}
			if ( self::ACTIVATE === $name ) {
				$plugin = self::plugin_ref_from_input( $input );
				return sprintf(
					/* translators: %s: plugin file or slug */
					__( 'Activate plugin “%s”', 'ahentic' ),
					$plugin ? $plugin : __( 'unknown', 'ahentic' )
				);
			}
			if ( self::DEACTIVATE === $name ) {
				$plugin = self::plugin_ref_from_input( $input );
				return sprintf(
					/* translators: %s: plugin file or slug */
					__( 'Deactivate plugin “%s”', 'ahentic' ),
					$plugin ? $plugin : __( 'unknown', 'ahentic' )
				);
			}
			return $name;
		}

		/**
		 * @param mixed $input Input.
		 * @return array
		 */
		public static function execute_list_plugins( $input = array() ) {
			$input  = is_array( $input ) ? $input : array();
			$status = isset( $input['status'] ) ? (string) $input['status'] : 'all';
			if ( ! in_array( $status, array( 'all', 'active', 'inactive' ), true ) ) {
				$status = 'all';
			}

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$all    = get_plugins();
			$active = (array) get_option( 'active_plugins', array() );
			$active_map = array_fill_keys( $active, true );
			$items  = array();

			foreach ( $all as $file => $data ) {
				$is_active = isset( $active_map[ $file ] );
				if ( 'active' === $status && ! $is_active ) {
					continue;
				}
				if ( 'inactive' === $status && $is_active ) {
					continue;
				}
				$slug = self::slug_from_plugin_file( $file );
				$items[] = array(
					'file'        => (string) $file,
					'slug'        => $slug,
					'name'        => isset( $data['Name'] ) ? (string) $data['Name'] : (string) $file,
					'version'     => isset( $data['Version'] ) ? (string) $data['Version'] : '',
					'description' => isset( $data['Description'] ) ? wp_strip_all_tags( (string) $data['Description'] ) : '',
					'author'      => isset( $data['AuthorName'] ) ? (string) $data['AuthorName'] : ( isset( $data['Author'] ) ? wp_strip_all_tags( (string) $data['Author'] ) : '' ),
					'active'      => $is_active,
				);
			}

			usort(
				$items,
				static function ( $a, $b ) {
					if ( $a['active'] !== $b['active'] ) {
						return $a['active'] ? -1 : 1;
					}
					return strcasecmp( $a['name'], $b['name'] );
				}
			);

			return array(
				'status'        => $status,
				'count'         => count( $items ),
				'active_count'  => count( $active ),
				'plugins'       => $items,
				'plugins_url'   => admin_url( 'plugins.php' ),
			);
		}

		/**
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_search_plugins( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
			if ( '' === $query ) {
				return new WP_Error( 'ahentic_missing_query', __( 'A search query is required.', 'ahentic' ) );
			}

			$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 8;
			$limit = max( 1, min( 15, $limit ) );

			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}

			$api = plugins_api(
				'query_plugins',
				array(
					'search'   => $query,
					'per_page' => $limit,
					'fields'   => array(
						'short_description' => true,
						'icons'             => false,
						'active_installs'   => true,
						'sections'          => false,
					),
				)
			);

			if ( is_wp_error( $api ) ) {
				return $api;
			}

			$plugins = array();
			$list    = isset( $api->plugins ) && is_array( $api->plugins ) ? $api->plugins : array();
			foreach ( $list as $plugin ) {
				$plugin = (object) $plugin;
				$slug   = isset( $plugin->slug ) ? (string) $plugin->slug : '';
				if ( '' === $slug ) {
					continue;
				}
				$plugins[] = array(
					'slug'             => $slug,
					'name'             => isset( $plugin->name ) ? wp_strip_all_tags( (string) $plugin->name ) : $slug,
					'short_description'=> isset( $plugin->short_description ) ? wp_strip_all_tags( (string) $plugin->short_description ) : '',
					'version'          => isset( $plugin->version ) ? (string) $plugin->version : '',
					'rating'           => isset( $plugin->rating ) ? (int) $plugin->rating : 0,
					'num_ratings'      => isset( $plugin->num_ratings ) ? (int) $plugin->num_ratings : 0,
					'active_installs'  => isset( $plugin->active_installs ) ? (int) $plugin->active_installs : 0,
					'homepage'         => isset( $plugin->homepage ) ? (string) $plugin->homepage : '',
					'download_link'    => isset( $plugin->download_link ) ? (string) $plugin->download_link : '',
					'installed'        => self::is_slug_installed( $slug ),
					'active'           => self::is_slug_active( $slug ),
				);
			}

			return array(
				'query'              => $query,
				'count'              => count( $plugins ),
				'plugins'            => $plugins,
				'plugin_install_url' => admin_url( 'plugin-install.php?s=' . rawurlencode( $query ) . '&tab=search&type=term' ),
			);
		}

		/**
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_install_plugin( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$slug  = isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';
			if ( '' === $slug ) {
				return new WP_Error( 'ahentic_missing_slug', __( 'A plugin slug is required.', 'ahentic' ) );
			}

			if ( ! current_user_can( 'install_plugins' ) ) {
				return new WP_Error( 'ahentic_ability_forbidden', __( 'You cannot install plugins.', 'ahentic' ), array( 'status' => 403 ) );
			}

			if ( self::is_slug_installed( $slug ) ) {
				$file = self::plugin_file_from_slug( $slug );
				return array(
					'ok'           => true,
					'already'      => true,
					'slug'         => $slug,
					'plugin_file'  => $file,
					'active'       => self::is_slug_active( $slug ),
					'message'      => __( 'Plugin is already installed.', 'ahentic' ),
				);
			}

			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array(
						'sections' => false,
					),
				)
			);
			if ( is_wp_error( $api ) ) {
				return $api;
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( true !== $result ) {
				return new WP_Error( 'ahentic_install_failed', __( 'Plugin install failed.', 'ahentic' ) );
			}

			$file = self::plugin_file_from_slug( $slug );
			if ( ! $file && ! empty( $upgrader->plugin_info() ) ) {
				$file = (string) $upgrader->plugin_info();
			}

			return array(
				'ok'          => true,
				'already'     => false,
				'slug'        => $slug,
				'name'        => isset( $api->name ) ? wp_strip_all_tags( (string) $api->name ) : $slug,
				'plugin_file' => $file ? $file : '',
				'active'      => $file ? is_plugin_active( $file ) : false,
				'message'     => __( 'Plugin installed successfully.', 'ahentic' ),
				'plugins_url' => admin_url( 'plugins.php' ),
			);
		}

		/**
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_activate_plugin( $input = array() ) {
			$input  = is_array( $input ) ? $input : array();
			$plugin = self::plugin_ref_from_input( $input );
			if ( '' === $plugin ) {
				return new WP_Error( 'ahentic_missing_plugin', __( 'A plugin file or slug is required.', 'ahentic' ) );
			}

			if ( ! current_user_can( 'activate_plugins' ) ) {
				return new WP_Error( 'ahentic_ability_forbidden', __( 'You cannot activate plugins.', 'ahentic' ), array( 'status' => 403 ) );
			}

			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$file = $plugin;
			if ( false === strpos( $plugin, '/' ) && false === strpos( $plugin, '.php' ) ) {
				$file = self::plugin_file_from_slug( sanitize_key( $plugin ) );
				if ( ! $file ) {
					return new WP_Error(
						'ahentic_plugin_not_installed',
						sprintf(
							/* translators: %s: plugin slug */
							__( 'Plugin “%s” is not installed.', 'ahentic' ),
							$plugin
						)
					);
				}
			}

			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				return new WP_Error( 'ahentic_plugin_missing', __( 'Plugin file was not found on disk.', 'ahentic' ) );
			}

			if ( is_plugin_active( $file ) ) {
				return array(
					'ok'          => true,
					'already'     => true,
					'plugin_file' => $file,
					'active'      => true,
					'message'     => __( 'Plugin is already active.', 'ahentic' ),
				);
			}

			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'ok'          => true,
				'already'     => false,
				'plugin_file' => $file,
				'active'      => true,
				'message'     => __( 'Plugin activated successfully.', 'ahentic' ),
				'plugins_url' => admin_url( 'plugins.php' ),
			);
		}

		/**
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_deactivate_plugin( $input = array() ) {
			$input  = is_array( $input ) ? $input : array();
			$plugin = self::plugin_ref_from_input( $input );
			if ( '' === $plugin ) {
				return new WP_Error( 'ahentic_missing_plugin', __( 'A plugin file or slug is required.', 'ahentic' ) );
			}

			if ( ! current_user_can( 'activate_plugins' ) ) {
				return new WP_Error( 'ahentic_ability_forbidden', __( 'You cannot deactivate plugins.', 'ahentic' ), array( 'status' => 403 ) );
			}

			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$file = $plugin;
			if ( false === strpos( $plugin, '/' ) && false === strpos( $plugin, '.php' ) ) {
				$file = self::plugin_file_from_slug( sanitize_key( $plugin ) );
				if ( ! $file ) {
					return new WP_Error(
						'ahentic_plugin_not_installed',
						sprintf(
							/* translators: %s: plugin slug */
							__( 'Plugin “%s” is not installed.', 'ahentic' ),
							$plugin
						)
					);
				}
			}

			if ( defined( 'AHENTIC_FILE' ) && plugin_basename( AHENTIC_FILE ) === $file ) {
				return new WP_Error(
					'ahentic_cannot_deactivate_self',
					__( 'Ahentic cannot deactivate itself while you are using it.', 'ahentic' )
				);
			}

			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				return new WP_Error( 'ahentic_plugin_missing', __( 'Plugin file was not found on disk.', 'ahentic' ) );
			}

			if ( ! is_plugin_active( $file ) ) {
				return array(
					'ok'          => true,
					'already'     => true,
					'plugin_file' => $file,
					'active'      => false,
					'message'     => __( 'Plugin is already inactive.', 'ahentic' ),
				);
			}

			deactivate_plugins( $file, false, is_multisite() && is_plugin_active_for_network( $file ) );

			return array(
				'ok'          => true,
				'already'     => false,
				'plugin_file' => $file,
				'active'      => is_plugin_active( $file ),
				'message'     => __( 'Plugin deactivated successfully.', 'ahentic' ),
				'plugins_url' => admin_url( 'plugins.php' ),
			);
		}

		/**
		 * Suggested UI actions after a successful inactive install.
		 *
		 * @param array $payload Install tool result.
		 * @return array<int, array<string, mixed>>
		 */
		public static function suggested_actions_after_install( $payload ) {
			$payload = is_array( $payload ) ? $payload : array();
			if ( isset( $payload['ok'] ) && ! $payload['ok'] ) {
				return array();
			}
			if ( ! empty( $payload['active'] ) ) {
				return array();
			}
			// Require a successful-looking install payload.
			if ( empty( $payload['ok'] ) && empty( $payload['message'] ) && empty( $payload['plugin_file'] ) && empty( $payload['slug'] ) ) {
				return array();
			}

			$slug = isset( $payload['slug'] ) ? sanitize_key( (string) $payload['slug'] ) : '';
			$file = isset( $payload['plugin_file'] ) ? (string) $payload['plugin_file'] : '';
			$name = isset( $payload['name'] ) ? wp_strip_all_tags( (string) $payload['name'] ) : '';
			$url  = isset( $payload['plugins_url'] ) ? (string) $payload['plugins_url'] : admin_url( 'plugins.php' );

			if ( '' === $slug && '' === $file ) {
				return array();
			}

			if ( '' === $name ) {
				$name = $slug ? $slug : $file;
			}

			$activate_input = array();
			if ( '' !== $file ) {
				$activate_input['plugin'] = $file;
			}
			if ( '' !== $slug ) {
				$activate_input['slug'] = $slug;
			}

			$actions = array(
				array(
					'id'    => 'activate_plugin',
					'type'  => 'ability',
					'label' => sprintf(
						/* translators: %s: plugin name */
						__( 'Activate %s', 'ahentic' ),
						$name
					),
					'name'  => self::ACTIVATE,
					'input' => $activate_input,
				),
			);

			if ( $url && wp_http_validate_url( $url ) ) {
				$actions[] = array(
					'id'    => 'visit_plugins',
					'type'  => 'link',
					'label' => __( 'Visit plugins page', 'ahentic' ),
					'url'   => $url,
				);
			}

			return $actions;
		}

		/**
		 * Resolve plugin file or slug from activate input (plugin, plugin_file, or slug).
		 *
		 * @param array $input Ability input.
		 * @return string
		 */
		private static function plugin_ref_from_input( $input ) {
			$input = is_array( $input ) ? $input : array();
			foreach ( array( 'plugin', 'plugin_file', 'slug' ) as $key ) {
				if ( ! isset( $input[ $key ] ) ) {
					continue;
				}
				$value = trim( (string) $input[ $key ] );
				if ( '' !== $value ) {
					return $value;
				}
			}
			return '';
		}

		/**
		 * @param string $file Plugin file.
		 * @return string
		 */
		private static function slug_from_plugin_file( $file ) {
			$parts = explode( '/', (string) $file );
			return sanitize_key( $parts[0] );
		}

		/**
		 * @param string $slug Slug.
		 * @return string|null
		 */
		private static function plugin_file_from_slug( $slug ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug ) {
				return null;
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( array_keys( get_plugins() ) as $file ) {
				if ( self::slug_from_plugin_file( $file ) === $slug ) {
					return (string) $file;
				}
			}
			return null;
		}

		/**
		 * @param string $slug Slug.
		 * @return bool
		 */
		private static function is_slug_installed( $slug ) {
			return (bool) self::plugin_file_from_slug( $slug );
		}

		/**
		 * @param string $slug Slug.
		 * @return bool
		 */
		private static function is_slug_active( $slug ) {
			$file = self::plugin_file_from_slug( $slug );
			return $file ? is_plugin_active( $file ) : false;
		}
	}
}
