<?php
/**
 * Plugin Name: Ahentic - AI Workspace for WordPress
 * Plugin URI: https://ahentic.com
 * Description: An intelligent AI agent that understands your WordPress site and works alongside you to build, edit, troubleshoot, and manage it.
 * Author: Gambit Technologies, Inc
 * Author URI: http://gambit.ph
 * License: GPLv2 or later
 * Text Domain: ahentic
 * Version: 0.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

defined( 'AHENTIC_FILE' ) || define( 'AHENTIC_FILE', __FILE__ );
defined( 'AHENTIC_VERSION' ) || define( 'AHENTIC_VERSION', '0.1.0' );
defined( 'AHENTIC_BUILD' ) || define( 'AHENTIC_BUILD', 'free' );

/**
 * Load plugin translations.
 */
function ahentic_load_textdomain() {
	load_plugin_textdomain(
		'ahentic',
		false,
		dirname( plugin_basename( AHENTIC_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'ahentic_load_textdomain' );

require_once __DIR__ . '/src/admin/class-script-loader.php';
require_once __DIR__ . '/src/admin/class-admin.php';

/**
 * Plugin activation hook.
 */
function ahentic_activate() {
	// Reserved for activation tasks (notices, defaults, etc.).
}
register_activation_hook( __FILE__, 'ahentic_activate' );

if ( AHENTIC_BUILD === 'premium' ) {
	/**
	 * Premium initialize code.
	 */
	if ( file_exists( plugin_dir_path( __FILE__ ) . 'pro__premium_only/index.php' ) ) {
		require_once plugin_dir_path( __FILE__ ) . 'pro__premium_only/index.php';
	}
}
