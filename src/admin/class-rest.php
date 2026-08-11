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
		 * Transient key for last successful text-generation probe.
		 *
		 * @var string
		 */
		const TEXT_GEN_CACHE_KEY = 'ahentic_text_gen_ok';

		/**
		 * How long a successful probe stays trusted across flakes (seconds).
		 *
		 * @var int
		 */
		const TEXT_GEN_CACHE_TTL = 300;

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
		 * Live probe of text-generation support (no cache).
		 *
		 * @return bool|null True when supported, false when confirmed unsupported /
		 *                   no client, null when the probe threw (transport / SDK flake).
		 */
		public static function probe_text_generation() {
			if ( function_exists( 'wp_ai_client_prompt' ) ) {
				try {
					$builder = wp_ai_client_prompt( 'ping' );
					if ( is_object( $builder ) ) {
						// Snake_case APIs are exposed via __call on the Core builder.
						return (bool) $builder->is_supported_for_text_generation();
					}
				} catch ( Exception $e ) {
					return null;
				} catch ( Throwable $e ) {
					return null;
				}
			}

			if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
				try {
					$builder = \WordPress\AiClient\AiClient::prompt( 'ping' );
					if ( is_object( $builder ) && method_exists( $builder, 'isSupportedForTextGeneration' ) ) {
						return (bool) $builder->isSupportedForTextGeneration();
					}
				} catch ( Exception $e ) {
					return null;
				} catch ( Throwable $e ) {
					return null;
				}
			}

			return false;
		}

/**
 * Resolve connector readiness: last-known-good cache, then live probe.
 *
 * A warm cache skips the remote support probe so slow networks do not
 * re-hit list-models on every localize/status GET. Soft-false still
 * clears the cache when a cold probe confirms missing.
 *
 * @return array{hasConnector: bool|null, connectorStatus: string}
 */
public static function resolve_text_generation() {
	$cached = get_transient( self::TEXT_GEN_CACHE_KEY );
	if ( false !== $cached && (bool) $cached ) {
		return array(
			'hasConnector'    => true,
			'connectorStatus' => 'ready',
		);
	}

	$probe = self::probe_text_generation();

	if ( true === $probe ) {
		set_transient( self::TEXT_GEN_CACHE_KEY, true, self::TEXT_GEN_CACHE_TTL );
		return array(
			'hasConnector'    => true,
			'connectorStatus' => 'ready',
		);
	}

	if ( false === $probe ) {
		delete_transient( self::TEXT_GEN_CACHE_KEY );
		return array(
			'hasConnector'    => false,
			'connectorStatus' => 'missing',
		);
	}

	return array(
		'hasConnector'    => null,
		'connectorStatus' => 'unknown',
	);
}

		/**
		 * Whether at least one configured connector can run text generation.
		 *
		 * Bool convenience for call sites that cannot handle unknown. Uses the
		 * last-known-good cache when the live probe throws.
		 *
		 * @return bool
		 */
		public static function has_text_generation() {
			$resolved = self::resolve_text_generation();
			return true === $resolved['hasConnector'];
		}

		/**
		 * Admin URL for Settings → Connectors.
		 *
		 * @return string
		 */
		public static function connectors_url() {
			if ( file_exists( ABSPATH . 'wp-admin/options-connectors.php' ) ) {
				return admin_url( 'options-connectors.php' );
			}
			return admin_url( 'options-general.php' );
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

				$status = self::build_status_payload();
				return rest_ensure_response(
					array_merge(
						$status,
						array(
							'success'     => true,
							'needsReload' => true,
							'message'     => __( 'WordPress AI plugin installed and activated. Reloading…', 'ahentic' ),
						)
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
			/**
			 * Short-circuit the AI readiness/connector status (e2e testing only).
			 *
			 * Nothing in production hooks this — only the e2e-only mu-plugin
			 * (tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php, never shipped
			 * with the plugin) does, forcing the sidebar composer "ready" so specs
			 * can drive real chat turns against a mocked
			 * `Ahentic_AI::complete_chat()` (see pre_ahentic_ai_complete_chat)
			 * without installing/configuring a real AI provider.
			 *
			 * @param array|null $override Non-null to short-circuit with this exact payload; null (default) computes the real status.
			 */
			$override = apply_filters( 'pre_ahentic_ai_status', null );
			if ( null !== $override ) {
				return $override;
			}

			if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$installed = self::is_ai_plugin_installed();
			$active    = $installed && is_plugin_active( self::AI_PLUGIN_FILE );
			$ready     = self::is_ai_ready();

			if ( $ready ) {
				$resolved         = self::resolve_text_generation();
				$has_model        = $resolved['hasConnector'];
				$connector_status = $resolved['connectorStatus'];
			} else {
				$has_model        = false;
				$connector_status = 'missing';
			}

			return array(
				'isReady'         => $ready,
				'hasConnector'    => $has_model,
				'canGenerate'     => $ready && true === $has_model,
				'connectorStatus' => $connector_status,
				'requiredAbility' => self::REQUIRED_ABILITY,
				'pluginSlug'      => self::AI_PLUGIN_SLUG,
				'pluginFile'      => self::AI_PLUGIN_FILE,
				'pluginInstalled' => $installed,
				'pluginActive'    => $active,
				'canInstall'      => current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ),
				'pluginUrl'       => 'https://wordpress.org/plugins/ai/',
				'connectorsUrl'   => self::connectors_url(),
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
