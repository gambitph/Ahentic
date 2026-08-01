<?php
/**
 * Browser (JS) abilities catalog — run in the sidebar, not in PHP.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Browser' ) ) {
	/**
	 * Client-runtime abilities for the agent loop.
	 */
	class Ahentic_Abilities_Browser {
		const CURRENT_PAGE = 'ahentic-browser/get-current-page';
		const VISIBLE_PAGE = 'ahentic-browser/get-visible-page';

		/**
		 * @return string[]
		 */
		public static function names() {
			return array( self::CURRENT_PAGE, self::VISIBLE_PAGE );
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_browser( $name ) {
			return in_array( (string) $name, self::names(), true );
		}

		/**
		 * Register category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}
			wp_register_ability_category(
				'ahentic-browser',
				array(
					'label'       => __( 'Ahentic Browser', 'ahentic' ),
					'description' => __( 'Client-side page inspection abilities for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register abilities (PHP stubs — orchestrator pauses for browser execution).
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'manage_options' );
			};
			$meta       = array(
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'show_in_rest' => false,
				'ahentic'      => array(
					'runtime' => 'browser',
				),
			);

			wp_register_ability(
				self::CURRENT_PAGE,
				array(
					'label'               => __( 'Get current page', 'ahentic' ),
					'description'         => __( 'Returns the URL, title, and admin screen hints for the page where the Ahentic sidebar is open. Runs in the browser.', 'ahentic' ),
					'category'            => 'ahentic-browser',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_stub' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::VISIBLE_PAGE,
				array(
					'label'               => __( 'Get visible page', 'ahentic' ),
					'description'         => __( 'Reads what is visible on the open tab: page identity, headings, admin notices, primary actions, form fields, and a capped text excerpt from the main content. Use for “what’s on this screen” / teacher mode. Runs in the browser. Prefer server abilities for site changes; this is read-only inspection.', 'ahentic' ),
					'category'            => 'ahentic-browser',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_stub' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);
		}

		/**
		 * Browser abilities must not execute in PHP.
		 *
		 * @param mixed $input Unused.
		 * @return \WP_Error
		 */
		public static function execute_stub( $input = array() ) {
			unset( $input );
			return new WP_Error(
				'ahentic_browser_runtime',
				__( 'This ability must run in the browser.', 'ahentic' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			unset( $name, $input );
			return self::execute_stub();
		}

		/**
		 * Short summary for pending-tool UI / progress.
		 *
		 * @param string $name Ability.
		 * @return string
		 */
		public static function summary( $name ) {
			if ( self::CURRENT_PAGE === $name ) {
				return __( 'Read the current browser page', 'ahentic' );
			}
			if ( self::VISIBLE_PAGE === $name ) {
				return __( 'Read what is visible on the page', 'ahentic' );
			}
			return (string) $name;
		}
	}
}
