<?php
/**
 * REST API for Ahentic.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_REST' ) ) {
	/**
	 * Registers Ahentic REST routes.
	 */
	class Ahentic_REST {
		/**
		 * WordPress.org plugin slug for the experimental AI plugin.
		 *
		 * @var string
		 */
		const AI_PLUGIN_SLUG = 'ai';

		/**
		 * Main plugin file relative to plugins directory.
		 *
		 * @var string
		 */
		const AI_PLUGIN_FILE = 'ai/ai.php';

		/**
		 * Ability used as the readiness signal for WordPress AI features.
		 *
		 * @var string
		 */
		const REQUIRED_ABILITY = 'core/read-content';

		/**
		 * Singleton instance.
		 *
		 * @var Ahentic_REST|null
		 */
		private static $instance = null;

		/**
		 * Get singleton.
		 *
		 * @return Ahentic_REST
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		private function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Whether the required AI ability is available.
		 *
		 * @return bool
		 */
		public static function is_ai_ready() {
			return function_exists( 'wp_has_ability' ) && wp_has_ability( self::REQUIRED_ABILITY );
		}

		/**
		 * Register REST routes.
		 */
		public function register_routes() {
			register_rest_route(
				'ahentic/v1',
				'/ai-plugin/status',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'can_manage_ahentic' ),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/ai-plugin/install',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'install_and_activate' ),
					'permission_callback' => array( $this, 'can_install_ai_plugin' ),
				)
			);
		}

		/**
		 * Permission: use Ahentic.
		 *
		 * @return bool
		 */
		public function can_manage_ahentic() {
			return is_user_logged_in() && current_user_can( 'manage_options' );
		}

		/**
		 * Permission: install and activate plugins.
		 *
		 * @return bool|\WP_Error
		 */
		public function can_install_ai_plugin() {
			if ( ! $this->can_manage_ahentic() ) {
				return new WP_Error(
					'ahentic_forbidden',
					__( 'You do not have permission to manage Ahentic.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
				return new WP_Error(
					'ahentic_cannot_install_plugins',
					__( 'You do not have permission to install or activate plugins.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		/**
		 * GET /ai-plugin/status
		 *
		 * @return \WP_REST_Response
		 */
		public function get_status() {
			return rest_ensure_response( self::build_status_payload() );
		}

		/**
		 * POST /ai-plugin/install — install (if needed) and activate the WordPress AI plugin.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function install_and_activate() {
			if ( self::is_ai_ready() ) {
				return rest_ensure_response(
					array_merge(
						self::build_status_payload(),
						array(
							'success' => true,
							'message' => __( 'WordPress AI is already available.', 'ahentic' ),
						)
					)
				);
			}

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$installed = self::is_ai_plugin_installed();

			if ( ! $installed ) {
				$install_result = $this->install_ai_plugin();
				if ( is_wp_error( $install_result ) ) {
					return $install_result;
				}
			}

			if ( ! is_plugin_active( self::AI_PLUGIN_FILE ) ) {
				$activate = activate_plugin( self::AI_PLUGIN_FILE, '', false, true );
				if ( is_wp_error( $activate ) ) {
					return new WP_Error(
						'ahentic_ai_activate_failed',
						sprintf(
							/* translators: %s: error message */
							__( 'Could not activate the WordPress AI plugin: %s', 'ahentic' ),
							$activate->get_error_message()
						),
						array( 'status' => 500 )
					);
				}

				return rest_ensure_response(
					array(
						'success'     => true,
						'isReady'     => self::is_ai_ready(),
						'needsReload' => true,
						'message'     => __( 'WordPress AI plugin installed and activated. Reloading…', 'ahentic' ),
						'pluginFile'  => self::AI_PLUGIN_FILE,
						'pluginSlug'  => self::AI_PLUGIN_SLUG,
					)
				);
			}

			// Plugin is active but the required ability is still missing.
			return new WP_Error(
				'ahentic_ai_ability_missing',
				sprintf(
					/* translators: %s: ability name */
					__( 'The WordPress AI plugin is active, but the required ability (%s) is not available. Check Settings → AI.', 'ahentic' ),
					self::REQUIRED_ABILITY
				),
				array( 'status' => 500 )
			);
		}

		/**
		 * Shared status payload for localize + REST.
		 *
		 * @return array
		 */
		public static function build_status_payload() {
			if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$installed = self::is_ai_plugin_installed();
			$active    = $installed && is_plugin_active( self::AI_PLUGIN_FILE );

			return array(
				'isReady'         => self::is_ai_ready(),
				'requiredAbility' => self::REQUIRED_ABILITY,
				'pluginSlug'      => self::AI_PLUGIN_SLUG,
				'pluginFile'      => self::AI_PLUGIN_FILE,
				'pluginInstalled' => $installed,
				'pluginActive'    => $active,
				'canInstall'      => current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ),
				'pluginUrl'       => 'https://wordpress.org/plugins/ai/',
			);
		}

		/**
		 * Whether the AI plugin files exist on disk.
		 *
		 * @return bool
		 */
		private static function is_ai_plugin_installed() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugins = get_plugins();
			return isset( $plugins[ self::AI_PLUGIN_FILE ] );
		}

		/**
		 * Download and install the AI plugin from WordPress.org.
		 *
		 * @return true|\WP_Error
		 */
		private function install_ai_plugin() {
			if ( ! current_user_can( 'install_plugins' ) ) {
				return new WP_Error(
					'ahentic_cannot_install_plugins',
					__( 'You do not have permission to install plugins.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => self::AI_PLUGIN_SLUG,
					'fields' => array(
						'sections' => false,
					),
				)
			);

			if ( is_wp_error( $api ) ) {
				return new WP_Error(
					'ahentic_ai_info_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not fetch the WordPress AI plugin: %s', 'ahentic' ),
						$api->get_error_message()
					),
					array( 'status' => 500 )
				);
			}

			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'ahentic_ai_install_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not install the WordPress AI plugin: %s', 'ahentic' ),
						$result->get_error_message()
					),
					array( 'status' => 500 )
				);
			}

			if ( true !== $result && is_null( $upgrader->plugin_info() ) ) {
				$errors = $skin->get_errors();
				$message = is_wp_error( $errors ) ? $errors->get_error_message() : __( 'Unknown installation error.', 'ahentic' );
				return new WP_Error(
					'ahentic_ai_install_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not install the WordPress AI plugin: %s', 'ahentic' ),
						$message
					),
					array( 'status' => 500 )
				);
			}

			return true;
		}
	}

	Ahentic_REST::instance();
}
