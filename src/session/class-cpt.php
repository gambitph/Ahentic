<?php
/**
 * Ahentic session custom post type.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Session_CPT' ) ) {
	/**
	 * Registers the ahentic-session CPT.
	 */
	class Ahentic_Session_CPT {
		/**
		 * Post type slug.
		 *
		 * @var string
		 */
		const POST_TYPE = 'ahentic-session';

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'register' ) );
		}

		/**
		 * Register CPT.
		 */
		public static function register() {
			register_post_type(
				self::POST_TYPE,
				array(
					'labels'              => array(
						'name'          => __( 'Ahentic Sessions', 'ahentic' ),
						'singular_name' => __( 'Ahentic Session', 'ahentic' ),
					),
					'public'              => false,
					'publicly_queryable'  => false,
					'show_ui'             => false,
					'show_in_menu'        => false,
					'show_in_rest'        => false,
					'exclude_from_search' => true,
					'has_archive'         => false,
					'rewrite'             => false,
					'query_var'           => false,
					'capability_type'     => 'post',
					'map_meta_cap'        => true,
					'supports'            => array( 'title', 'excerpt', 'author' ),
					'delete_with_user'    => true,
				)
			);
		}
	}
}
