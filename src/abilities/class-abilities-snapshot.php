<?php
/**
 * Site snapshot ability (identity, theme, active plugins).
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Snapshot' ) ) {
	/**
	 * Readonly site snapshot for the agent loop.
	 */
	class Ahentic_Abilities_Snapshot {
		const SNAPSHOT = 'ahentic/get-site-snapshot';

		/**
		 * @return string[]
		 */
		public static function names() {
			return array( self::SNAPSHOT );
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_readonly( $name ) {
			return self::SNAPSHOT === (string) $name;
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function requires_hitl( $name ) {
			unset( $name );
			return false;
		}

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			if ( self::SNAPSHOT === (string) $name ) {
				return __( 'Reading site snapshot…', 'ahentic' );
			}
			return '';
		}

		/**
		 * Register category.
		 */
		public static function register_category() {
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
		 * Register abilities.
		 */
		public static function register() {
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
		 * @param string $name  Ability name.
		 * @param array  $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			if ( self::SNAPSHOT !== (string) $name ) {
				return new WP_Error(
					'ahentic_ability_unknown',
					sprintf(
						/* translators: %s: ability name */
						__( 'Unknown or unavailable ability: %s', 'ahentic' ),
						$name
					)
				);
			}
			return self::execute_get_site_snapshot( $input );
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
				'admin_links'         => Ahentic_Abilities::get_admin_links(),
			);
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

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Snapshot', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Snapshot', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Snapshot' );
}
