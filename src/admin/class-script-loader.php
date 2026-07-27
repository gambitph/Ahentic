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
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_sidebar_assets' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_sidebar_assets' ) );
			add_action( 'admin_footer', array( __CLASS__, 'render_root' ) );
			add_action( 'wp_footer', array( __CLASS__, 'render_root' ) );
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

			$settings_url = admin_url( 'options-general.php?page=ahentic' );

			wp_localize_script(
				'ahentic-script',
				'ahentic',
				array(
					'version'     => AHENTIC_VERSION,
					'build'       => AHENTIC_BUILD,
					'settingsUrl' => $settings_url,
					'isAdmin'     => is_admin(),
					'context'     => array(
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
