<?php
/**
 * Enqueue the main Ahentic sidebar script.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Script_Loader' ) ) {
	/**
	 * Loads Ahentic sidebar assets for authorized users.
	 */
	class Ahentic_Script_Loader {
		/**
		 * Capability required to use the Ahentic workspace.
		 *
		 * @var string
		 */
		const CAPABILITY = 'manage_options';

		/**
		 * Admin bar node id.
		 *
		 * @var string
		 */
		const ADMIN_BAR_ID = 'ahentic-toggle';

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_sidebar_assets' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_sidebar_assets' ) );
			add_action( 'admin_footer', array( __CLASS__, 'render_root' ) );
			add_action( 'wp_footer', array( __CLASS__, 'render_root' ) );
			add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar_node' ), 80 );
		}

		/**
		 * Whether the current user may load the Ahentic sidebar.
		 *
		 * @return bool
		 */
		public static function current_user_can_use_ahentic() {
			return is_user_logged_in() && current_user_can( self::CAPABILITY );
		}

		/**
		 * URL to the monochrome Ahentic icon SVG.
		 *
		 * @return string
		 */
		public static function icon_url() {
			return plugins_url( 'src/admin/images/ahentic-icon.svg', AHENTIC_FILE );
		}

		/**
		 * Add Ahentic toggle to the admin bar (left of "Howdy").
		 *
		 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
		 */
		public static function register_admin_bar_node( $wp_admin_bar ) {
			if ( ! self::current_user_can_use_ahentic() ) {
				return;
			}

			$wp_admin_bar->add_node(
				array(
					'id'     => self::ADMIN_BAR_ID,
					'parent' => 'top-secondary',
					'title'  => sprintf(
						'<span class="ab-icon" aria-hidden="true"><img class="ahentic-admin-bar__icon" src="%1$s" alt="" width="20" height="20" /></span><span class="ab-label">%2$s</span>',
						esc_url( self::icon_url() ),
						esc_html__( 'Ahentic', 'ahentic' )
					),
					'href'   => '#ahentic',
					'meta'   => array(
						'title' => __( 'Toggle Ahentic sidebar (Ctrl/Cmd+I)', 'ahentic' ),
						'class' => 'ahentic-admin-bar-node',
					),
				)
			);
		}

		/**
		 * Print the React mount point.
		 */
		public static function render_root() {
			if ( ! self::current_user_can_use_ahentic() ) {
				return;
			}

			echo '<div id="ahentic-root"></div>';
		}

		/**
		 * Enqueue the main sidebar JavaScript and CSS.
		 */
		public static function enqueue_sidebar_assets() {
			if ( ! self::current_user_can_use_ahentic() ) {
				return;
			}

			if ( wp_script_is( 'ahentic-script', 'enqueued' ) ) {
				return;
			}

			$build_dir = plugin_dir_path( AHENTIC_FILE ) . 'build/admin/';
			$build_url = plugin_dir_url( AHENTIC_FILE ) . 'build/admin/';

			$script_asset_path = $build_dir . 'index.asset.php';
			if ( ! file_exists( $script_asset_path ) ) {
				return;
			}

			$script_asset = include $script_asset_path;
			wp_enqueue_script(
				'ahentic-script',
				$build_url . 'index.js',
				array_values( array_diff( $script_asset['dependencies'], array( 'wp-dom-ready' ) ) ),
				$script_asset['version'],
				true
			);

			wp_set_script_translations(
				'ahentic-script',
				'ahentic',
				plugin_dir_path( AHENTIC_FILE ) . 'languages'
			);

			$settings_url = admin_url( 'options-general.php?page=' . ( class_exists( 'Ahentic_Admin' ) ? Ahentic_Admin::SETTINGS_SLUG : 'ahentic' ) );
			$docs_url     = 'https://ahentic.com/docs';
			$ai_status    = class_exists( 'Ahentic_REST' )
				? Ahentic_REST::build_status_payload()
				: array(
					'isReady'      => false,
					'hasConnector' => false,
					'canGenerate'  => false,
					'connectorsUrl' => admin_url( 'options-connectors.php' ),
				);

			wp_localize_script(
				'ahentic-script',
				'ahentic',
				array(
					'version'               => AHENTIC_VERSION,
					'build'                 => AHENTIC_BUILD,
					'settingsUrl'           => $settings_url,
					'docsUrl'               => $docs_url,
					'isAdmin'               => is_admin(),
					'homeUrl'               => esc_url_raw( home_url( '/' ) ),
					'siteUrl'               => esc_url_raw( site_url( '/' ) ),
					'iconUrl'               => self::icon_url(),
					'adminBarId'            => self::ADMIN_BAR_ID,
					'restUrl'               => esc_url_raw( rest_url( 'ahentic/v1' ) ),
					'restNonce'             => wp_create_nonce( 'wp_rest' ),
					'aiPlugin'              => $ai_status,
					'tokenLimitCodes'       => array(
						'daily'   => Ahentic_Usage::CODE_DAILY_LIMIT,
						'runaway' => Ahentic_Usage::CODE_RUNAWAY_LOCK,
					),
					'abilityProgressLabels' => class_exists( 'Ahentic_Abilities' )
						? Ahentic_Abilities::progress_labels_map()
						: array(),
					'fillFieldsOptionDenylist' => class_exists( 'Ahentic_Abilities_Browser' )
						? Ahentic_Abilities_Browser::fill_fields_option_denylist()
						: array(),
					'context'               => array(
						'wpVersion'  => get_bloginfo( 'version' ),
						'phpVersion' => PHP_VERSION,
					),
				)
			);

			$style_asset_path = $build_dir . 'index-styles.asset.php';
			if ( file_exists( $style_asset_path ) ) {
				$style_asset = include $style_asset_path;
				wp_enqueue_style(
					'ahentic-script-styles',
					$build_url . 'index-styles.css',
					$style_asset['dependencies'],
					$style_asset['version']
				);
			}
		}
	}

	new Ahentic_Script_Loader();
}
