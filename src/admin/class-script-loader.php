<?php
/**
 * Enqueue scripts for the admin area.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Script_Loader' ) ) {
	/**
	 * Loads Ahentic admin assets.
	 */
	class Ahentic_Script_Loader {
		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
			add_action( 'admin_footer', array( __CLASS__, 'render_root' ) );
		}

		/**
		 * Print the React mount point on admin screens.
		 */
		public static function render_root() {
			echo '<div id="ahentic-root"></div>';
		}

		/**
		 * Enqueue admin JavaScript and CSS.
		 */
		public static function enqueue_admin_assets() {
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

			wp_localize_script(
				'ahentic-script',
				'ahentic',
				array(
					'version' => AHENTIC_VERSION,
					'build'   => AHENTIC_BUILD,
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
