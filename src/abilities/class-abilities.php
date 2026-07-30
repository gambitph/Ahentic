<?php
/**
 * Ahentic WordPress Abilities registration + execute helper.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities' ) ) {
	/**
	 * Registers Ahentic abilities and runs them for the orchestrator.
	 */
	class Ahentic_Abilities {
		const SNAPSHOT = 'ahentic/get-site-snapshot';

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ) );
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		}

		/**
		 * Ability categories.
		 */
		public static function register_categories() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			wp_register_ability_category(
				'ahentic-site',
				array(
					'label'       => __( 'Ahentic Site', 'ahentic' ),
					'description' => __( 'Read-only site identity and stack inspection for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register v1 abilities (start with one).
		 */
		public static function register_abilities() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			wp_register_ability(
				self::SNAPSHOT,
				array(
					'label'               => __( 'Get site snapshot', 'ahentic' ),
					'description'         => __( 'Returns site identity, environment, theme, and active plugins.', 'ahentic' ),
					'category'            => 'ahentic-site',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'site_name'           => array( 'type' => 'string' ),
							'tagline'             => array( 'type' => 'string' ),
							'home_url'            => array( 'type' => 'string' ),
							'site_url'            => array( 'type' => 'string' ),
							'locale'              => array( 'type' => 'string' ),
							'timezone'            => array( 'type' => 'string' ),
							'environment'         => array( 'type' => 'string' ),
							'wp_version'          => array( 'type' => 'string' ),
							'php_version'         => array( 'type' => 'string' ),
							'is_multisite'        => array( 'type' => 'boolean' ),
							'theme'               => array( 'type' => 'object' ),
							'plugins'             => array( 'type' => 'array' ),
							'active_plugin_count' => array( 'type' => 'integer' ),
							'admin_links'         => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( __CLASS__, 'execute_get_site_snapshot' ),
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array(
						'annotations' => array(
							'readonly'   => true,
							'idempotent' => true,
						),
						'show_in_rest' => false,
					),
				)
			);
		}

		/**
		 * Names of abilities Ahentic can run in the agent loop today.
		 *
		 * @return string[]
		 */
		public static function available_for_agent() {
			return array( self::SNAPSHOT );
		}

		/**
		 * Execute an ability by name (Abilities API or direct fallback).
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Input args.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			$name  = (string) $name;
			$input = is_array( $input ) ? $input : array();

			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to run this ability.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			if ( function_exists( 'wp_get_ability' ) ) {
				$ability = wp_get_ability( $name );
				if ( $ability && is_object( $ability ) && method_exists( $ability, 'execute' ) ) {
					return $ability->execute( $input );
				}
			}

			// Fallback when Abilities API is missing or ability not registered yet.
			if ( self::SNAPSHOT === $name ) {
				return self::execute_get_site_snapshot( $input );
			}

			return new WP_Error(
				'ahentic_ability_unknown',
				sprintf(
					/* translators: %s: ability name */
					__( 'Unknown or unavailable ability: %s', 'ahentic' ),
					$name
				)
			);
		}

		/**
		 * Build a thin site snapshot.
		 *
		 * @param mixed $input Unused.
		 * @return array
		 */
		public static function execute_get_site_snapshot( $input = array() ) {
			unset( $input );

			$theme   = wp_get_theme();
			$plugins = self::list_active_plugins();

			return array(
				'site_name'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'tagline'             => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
				'home_url'            => home_url( '/' ),
				'site_url'            => site_url( '/' ),
				'locale'              => get_locale(),
				'timezone'            => wp_timezone_string(),
				'environment'         => self::guess_environment(),
				'wp_version'          => get_bloginfo( 'version' ),
				'php_version'         => PHP_VERSION,
				'is_multisite'        => is_multisite(),
				'theme'               => array(
					'stylesheet' => $theme->get_stylesheet(),
					'name'       => $theme->get( 'Name' ),
					'version'    => $theme->get( 'Version' ),
				),
				'plugins'             => $plugins,
				'active_plugin_count' => count( $plugins ),
				'admin_links'         => self::get_admin_links(),
			);
		}

		/**
		 * Canonical wp-admin deep links for agent replies (built with admin_url()).
		 *
		 * @return array<string, array{label: string, url: string}>
		 */
		public static function get_admin_links() {
			$links = array(
				'dashboard'         => array(
					'label' => __( 'Dashboard', 'ahentic' ),
					'url'   => admin_url( 'index.php' ),
				),
				'posts'             => array(
					'label' => __( 'Posts', 'ahentic' ),
					'url'   => admin_url( 'edit.php' ),
				),
				'pages'             => array(
					'label' => __( 'Pages', 'ahentic' ),
					'url'   => admin_url( 'edit.php?post_type=page' ),
				),
				'media'             => array(
					'label' => __( 'Media', 'ahentic' ),
					'url'   => admin_url( 'upload.php' ),
				),
				'comments'          => array(
					'label' => __( 'Comments', 'ahentic' ),
					'url'   => admin_url( 'edit-comments.php' ),
				),
				'plugins'           => array(
					'label' => __( 'Plugins', 'ahentic' ),
					'url'   => admin_url( 'plugins.php' ),
				),
				'plugin-install'    => array(
					'label' => __( 'Add Plugins', 'ahentic' ),
					'url'   => admin_url( 'plugin-install.php' ),
				),
				'themes'            => array(
					'label' => __( 'Themes', 'ahentic' ),
					'url'   => admin_url( 'themes.php' ),
				),
				'theme-editor'      => array(
					'label' => __( 'Theme File Editor', 'ahentic' ),
					'url'   => admin_url( 'theme-editor.php' ),
				),
				'users'             => array(
					'label' => __( 'Users', 'ahentic' ),
					'url'   => admin_url( 'users.php' ),
				),
				'tools'             => array(
					'label' => __( 'Tools', 'ahentic' ),
					'url'   => admin_url( 'tools.php' ),
				),
				'site-health'       => array(
					'label' => __( 'Site Health', 'ahentic' ),
					'url'   => admin_url( 'site-health.php' ),
				),
				'settings-general'  => array(
					'label' => __( 'Settings → General', 'ahentic' ),
					'url'   => admin_url( 'options-general.php' ),
				),
				'settings-writing'  => array(
					'label' => __( 'Settings → Writing', 'ahentic' ),
					'url'   => admin_url( 'options-writing.php' ),
				),
				'settings-reading'  => array(
					'label' => __( 'Settings → Reading', 'ahentic' ),
					'url'   => admin_url( 'options-reading.php' ),
				),
				'settings-discussion' => array(
					'label' => __( 'Settings → Discussion', 'ahentic' ),
					'url'   => admin_url( 'options-discussion.php' ),
				),
				'settings-media'    => array(
					'label' => __( 'Settings → Media', 'ahentic' ),
					'url'   => admin_url( 'options-media.php' ),
				),
				'settings-permalinks' => array(
					'label' => __( 'Settings → Permalinks', 'ahentic' ),
					'url'   => admin_url( 'options-permalink.php' ),
				),
				'settings-privacy'  => array(
					'label' => __( 'Settings → Privacy', 'ahentic' ),
					'url'   => admin_url( 'options-privacy.php' ),
				),
				'customizer'        => array(
					'label' => __( 'Customizer', 'ahentic' ),
					'url'   => admin_url( 'customize.php' ),
				),
				'widgets'           => array(
					'label' => __( 'Widgets', 'ahentic' ),
					'url'   => admin_url( 'widgets.php' ),
				),
				'menus'             => array(
					'label' => __( 'Menus', 'ahentic' ),
					'url'   => admin_url( 'nav-menus.php' ),
				),
				'updates'           => array(
					'label' => __( 'Updates', 'ahentic' ),
					'url'   => admin_url( 'update-core.php' ),
				),
				'ahentic-settings'  => array(
					'label' => __( 'Settings → Ahentic', 'ahentic' ),
					'url'   => admin_url( 'options-general.php?page=ahentic' ),
				),
			);

			/**
			 * Filter Ahentic admin deep links exposed to the agent.
			 *
			 * @param array $links Map of key => { label, url }.
			 */
			return apply_filters( 'ahentic_admin_links', $links );
		}

		/**
		 * Compact text block of admin links for the system instruction.
		 *
		 * @return string
		 */
		public static function format_admin_links_for_prompt() {
			$lines = array();
			foreach ( self::get_admin_links() as $key => $item ) {
				if ( empty( $item['url'] ) || empty( $item['label'] ) ) {
					continue;
				}
				$lines[] = '- ' . $item['label'] . ' (' . $key . '): ' . $item['url'];
			}
			return implode( "\n", $lines );
		}

		/**
		 * Active plugins as a compact list.
		 *
		 * @return array<int, array{file: string, name: string, active: bool}>
		 */
		private static function list_active_plugins() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$all    = get_plugins();
			$active = (array) get_option( 'active_plugins', array() );
			$out    = array();

			foreach ( $active as $file ) {
				$out[] = array(
					'file'   => (string) $file,
					'name'   => isset( $all[ $file ]['Name'] ) ? (string) $all[ $file ]['Name'] : (string) $file,
					'active' => true,
				);
			}

			return $out;
		}

		/**
		 * Environment guess for the snapshot.
		 *
		 * @return string
		 */
		private static function guess_environment() {
			if ( function_exists( 'wp_get_environment_type' ) ) {
				$type = wp_get_environment_type();
				if ( $type ) {
					return (string) $type;
				}
			}

			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			$host = is_string( $host ) ? strtolower( $host ) : '';

			if ( ! $host || 'localhost' === $host || false !== strpos( $host, '.local' ) || false !== strpos( $host, '.test' ) ) {
				return 'local';
			}
			if ( false !== strpos( $host, 'staging' ) || false !== strpos( $host, 'stage.' ) || 0 === strpos( $host, 'dev.' ) ) {
				return 'staging';
			}

			return 'production';
		}
	}
}
