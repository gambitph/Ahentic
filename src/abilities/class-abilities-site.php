<?php
/**
 * Site abilities: health snapshot and allowlisted options.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Site' ) ) {
	/**
	 * Readonly site inspection for the agent loop.
	 */
	class Ahentic_Abilities_Site {
		const HEALTH = 'ahentic/get-site-health';
		const OPTION = 'ahentic/get-option';

		/**
		 * Allowlisted option keys the agent may read.
		 *
		 * @return string[]
		 */
		public static function option_allowlist() {
			return array(
				'blogname',
				'blogdescription',
				'siteurl',
				'home',
				'blog_public',
				'timezone_string',
				'gmt_offset',
				'date_format',
				'time_format',
				'start_of_week',
				'permalink_structure',
				'show_on_front',
				'page_on_front',
				'page_for_posts',
				'posts_per_page',
				'default_role',
				'users_can_register',
				'admin_email',
				'WPLANG',
				'site_icon',
				'fresh_site',
			);
		}

		/**
		 * @return string[]
		 */
		public static function names() {
			return array( self::HEALTH, self::OPTION );
		}

		/**
		 * Register category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}
			wp_register_ability_category(
				'ahentic-site-ops',
				array(
					'label'       => __( 'Ahentic Site Ops', 'ahentic' ),
					'description' => __( 'Site health and allowlisted option reads for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register abilities.
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
			);

			wp_register_ability(
				self::HEALTH,
				array(
					'label'               => __( 'Get site health', 'ahentic' ),
					'description'         => __( 'Returns a compact Site Health summary (counts and notable direct checks).', 'ahentic' ),
					'category'            => 'ahentic-site-ops',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_site_health' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::OPTION,
				array(
					'label'               => __( 'Get option', 'ahentic' ),
					'description'         => __( 'Reads one allowlisted WordPress option (e.g. blog_public, blogname, blogdescription).', 'ahentic' ),
					'category'            => 'ahentic-site-ops',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'key' ),
						'properties' => array(
							'key' => array(
								'type'        => 'string',
								'description' => __( 'Option name.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_option' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);
		}

		/**
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			switch ( $name ) {
				case self::HEALTH:
					return self::execute_get_site_health( $input );
				case self::OPTION:
					return self::execute_get_option( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown site ability.', 'ahentic' ) );
			}
		}

		/**
		 * Compact Site Health summary.
		 *
		 * @param mixed $input Unused.
		 * @return array
		 */
		public static function execute_get_site_health( $input = array() ) {
			unset( $input );

			if ( ! class_exists( 'WP_Site_Health' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
			}

			$counts = array(
				'good'        => 0,
				'recommended' => 0,
				'critical'    => 0,
			);
			$raw    = get_transient( 'health-check-site-status-result' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					foreach ( array( 'good', 'recommended', 'critical' ) as $k ) {
						if ( isset( $decoded[ $k ] ) ) {
							$counts[ $k ] = (int) $decoded[ $k ];
						}
					}
				}
			}

			$issues = array();
			$health = WP_Site_Health::get_instance();
			$tests  = WP_Site_Health::get_tests();
			$direct = isset( $tests['direct'] ) && is_array( $tests['direct'] ) ? $tests['direct'] : array();

			$priority = array(
				'wordpress_version',
				'plugin_version',
				'theme_version',
				'php_version',
				'https_status',
				'ssl_support',
				'scheduled_events',
				'debug_enabled',
				'is_in_debug_mode',
			);

			$run = array();
			foreach ( $priority as $test_id ) {
				if ( isset( $direct[ $test_id ] ) ) {
					$run[ $test_id ] = $direct[ $test_id ];
				}
			}
			// Fill remaining direct tests up to a cap.
			foreach ( $direct as $test_id => $test ) {
				if ( count( $run ) >= 12 ) {
					break;
				}
				if ( ! isset( $run[ $test_id ] ) ) {
					$run[ $test_id ] = $test;
				}
			}

			foreach ( $run as $test_id => $test ) {
				$result = null;
				if ( ! empty( $test['test'] ) && is_string( $test['test'] ) && is_callable( array( $health, 'get_test_' . $test['test'] ) ) ) {
					$result = call_user_func( array( $health, 'get_test_' . $test['test'] ) );
				} elseif ( ! empty( $test['test'] ) && is_callable( $test['test'] ) ) {
					$result = call_user_func( $test['test'] );
				}
				if ( ! is_array( $result ) || empty( $result['status'] ) ) {
					continue;
				}
				$status = (string) $result['status'];
				if ( 'good' === $status ) {
					continue;
				}
				$issues[] = array(
					'id'          => (string) $test_id,
					'label'       => isset( $result['label'] ) ? wp_strip_all_tags( (string) $result['label'] ) : (string) $test_id,
					'status'      => $status,
					'description' => isset( $result['description'] ) ? wp_strip_all_tags( (string) $result['description'] ) : '',
				);
				if ( count( $issues ) >= 10 ) {
					break;
				}
			}

			return array(
				'counts'           => $counts,
				'issues'           => $issues,
				'site_health_url'  => admin_url( 'site-health.php' ),
				'notes'            => array(
					__( 'Counts come from the last Site Health run when available; issues list is a capped set of direct checks.', 'ahentic' ),
				),
			);
		}

		/**
		 * Read an allowlisted option.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_get_option( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$key   = '';
			if ( isset( $input['key'] ) ) {
				$key = (string) $input['key'];
			} elseif ( isset( $input['name'] ) ) {
				$key = (string) $input['name'];
			} elseif ( isset( $input['option'] ) ) {
				$key = (string) $input['option'];
			}
			$key = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key );

			if ( '' === $key ) {
				return new WP_Error( 'ahentic_missing_option', __( 'An option key is required.', 'ahentic' ) );
			}

			if ( ! in_array( $key, self::option_allowlist(), true ) ) {
				return new WP_Error(
					'ahentic_option_not_allowed',
					sprintf(
						/* translators: %s: option key */
						__( 'Option “%s” is not on the allowlist.', 'ahentic' ),
						$key
					),
					array(
						'status'    => 403,
						'allowlist' => self::option_allowlist(),
					)
				);
			}

			// Prefer a core/upstream ability when present (experimental AI plugins may register one).
			$upstream_names = array(
				'core/get-option',
				'wordpress/get-option',
				'wp/get-option',
			);
			foreach ( $upstream_names as $upstream ) {
				if ( function_exists( 'wp_get_ability' ) ) {
					$ability = wp_get_ability( $upstream );
					if ( $ability && is_object( $ability ) && method_exists( $ability, 'execute' ) ) {
						$remote = $ability->execute( array( 'key' => $key ) );
						if ( ! is_wp_error( $remote ) ) {
							return is_array( $remote )
								? array_merge(
									array(
										'key'    => $key,
										'source' => $upstream,
									),
									$remote
								)
								: array(
									'key'    => $key,
									'value'  => $remote,
									'source' => $upstream,
								);
						}
					}
				}
			}

			$value = get_option( $key, null );

			return array(
				'key'       => $key,
				'value'     => $value,
				'exists'    => null !== $value,
				'source'    => 'ahentic/get-option',
				'allowlist' => self::option_allowlist(),
			);
		}
	}
}
