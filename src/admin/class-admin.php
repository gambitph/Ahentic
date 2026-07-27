<?php
/**
 * Admin page settings.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Admin' ) ) {
	/**
	 * Handles Ahentic admin screens and settings.
	 */
	class Ahentic_Admin {
		/**
		 * Settings page slug.
		 *
		 * @var string
		 */
		const SETTINGS_SLUG = 'ahentic';

		/**
		 * Constructor.
		 */
		public function __construct() {
			if ( is_admin() ) {
				add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
				add_filter( 'plugin_action_links_' . plugin_basename( AHENTIC_FILE ), array( $this, 'add_admin_action_links' ) );
			}
		}

		/**
		 * Add admin menu under Settings.
		 */
		public function add_admin_menu() {
			add_options_page(
				__( 'Ahentic Settings', 'ahentic' ),
				__( 'Ahentic', 'ahentic' ),
				'manage_options',
				self::SETTINGS_SLUG,
				array( $this, 'admin_page_callback' )
			);
		}

		/**
		 * Add a Settings link to the plugin action links.
		 *
		 * @param string[] $links Existing action links.
		 * @return string[]
		 */
		public function add_admin_action_links( $links ) {
			$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'ahentic' ) . '</a>';
			return array_merge( array( $settings_link ), $links );
		}

		/**
		 * Render the settings page.
		 */
		public function admin_page_callback() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			?>
			<div class="wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<p><?php esc_html_e( 'An intelligent AI agent that understands your WordPress site and works alongside you to build, edit, troubleshoot, and manage it.', 'ahentic' ); ?></p>
			</div>
			<?php
		}
	}

	new Ahentic_Admin();
}
