<?php
/**
 * Plugin Name: Ahentic - AI Workspace
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
 * Load Composer autoloader only when Core does not already ship the AI Client.
 *
 * Loading vendor/wordpress/php-ai-client alongside wp-includes/ai-client causes
 * class conflicts (e.g. PromptBuilder::__construct() rejecting
 * WP_AI_Client_Event_Dispatcher because a second copy of the SDK was autoloaded).
 */
function ahentic_maybe_load_composer() {
	if ( function_exists( 'wp_ai_client_prompt' ) ) {
		return;
	}

	// class_exists( …, false ) — do not trigger Composer autoload while detecting Core.
	if ( class_exists( '\WordPress\AiClient\AiClient', false ) ) {
		return;
	}

	if ( class_exists( 'WP_AI_Client_Prompt_Builder', false ) ) {
		return;
	}

	$autoload = __DIR__ . '/vendor/autoload.php';
	if ( file_exists( $autoload ) ) {
		require_once $autoload;
	}
}
add_action( 'plugins_loaded', 'ahentic_maybe_load_composer', 100 );

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

require_once __DIR__ . '/src/session/class-cpt.php';
require_once __DIR__ . '/src/session/class-repository.php';
require_once __DIR__ . '/src/abilities/class-abilities.php';
require_once __DIR__ . '/src/abilities/class-abilities-snapshot.php';
require_once __DIR__ . '/src/abilities/class-ahentic-content-placeholder.php';
require_once __DIR__ . '/src/abilities/class-abilities-content.php';
require_once __DIR__ . '/src/abilities/class-abilities-media.php';
require_once __DIR__ . '/src/abilities/class-abilities-site.php';
require_once __DIR__ . '/src/abilities/class-abilities-plugins.php';
require_once __DIR__ . '/src/abilities/class-abilities-taxonomy.php';
require_once __DIR__ . '/src/abilities/class-abilities-browser.php';
require_once __DIR__ . '/src/abilities/class-capability-request.php';
require_once __DIR__ . '/src/session/class-artifacts.php';
require_once __DIR__ . '/src/session/class-settings-snapshots.php';
require_once __DIR__ . '/src/playbooks/class-playbooks.php';
require_once __DIR__ . '/src/orchestrator/class-usage.php';
require_once __DIR__ . '/src/orchestrator/class-queue.php';
require_once __DIR__ . '/src/orchestrator/class-ai.php';
require_once __DIR__ . '/src/orchestrator/class-finish-gate.php';
require_once __DIR__ . '/src/orchestrator/class-tool-runner.php';
require_once __DIR__ . '/src/orchestrator/class-prompt-assembler.php';
require_once __DIR__ . '/src/orchestrator/class-orchestrator.php';
require_once __DIR__ . '/src/admin/class-rest.php';
require_once __DIR__ . '/src/admin/class-rest-sessions.php';
require_once __DIR__ . '/src/admin/class-script-loader.php';
require_once __DIR__ . '/src/admin/class-admin.php';

Ahentic_Session_CPT::init();
Ahentic_Abilities::init();
Ahentic_Step_Queue::init();
Ahentic_REST_Sessions::init();

/**
 * Plugin activation hook.
 */
function ahentic_activate() {
	Ahentic_Session_CPT::register();
	flush_rewrite_rules( false );
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
