<?php
/**
 * Site abilities: health, options, HTTP fetch, debug log, admin context.
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
		const HEALTH        = 'ahentic/get-site-health';
		const OPTION        = 'ahentic/get-option';
		const HTTP_FETCH    = 'ahentic/http-fetch';
		const DEBUG_LOG     = 'ahentic/get-debug-log';
		const ADMIN_CONTEXT = 'ahentic/get-admin-context';
		const LIST_THEMES   = 'ahentic/list-themes';

		const HTTP_MAX_BYTES     = 32768;
		const HTTP_TIMEOUT       = 12;
		const DEBUG_LOG_MAX_BYTES = 32768;

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
			return array(
				self::HEALTH,
				self::OPTION,
				self::HTTP_FETCH,
				self::DEBUG_LOG,
				self::ADMIN_CONTEXT,
				self::LIST_THEMES,
			);
		}

		/**
		 * Whether a theme is a block theme (shared with settings discovery later).
		 *
		 * @param \WP_Theme $theme Theme.
		 * @return bool
		 */
		public static function theme_is_block_theme( $theme ) {
			if ( ! ( $theme instanceof WP_Theme ) ) {
				return false;
			}
			if ( method_exists( $theme, 'is_block_theme' ) ) {
				return (bool) $theme->is_block_theme();
			}
			if ( function_exists( 'wp_theme_has_theme_json' ) ) {
				$stylesheet = $theme->get_stylesheet();
				return (bool) wp_theme_has_theme_json( $stylesheet );
			}
			return false;
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
					'description' => __( 'Site health, options, HTTP fetch, and debug inspection for Ahentic.', 'ahentic' ),
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

			wp_register_ability(
				self::HTTP_FETCH,
				array(
					'label'               => __( 'HTTP fetch', 'ahentic' ),
					'description'         => __( 'Fetches a URL and returns status plus a capped body excerpt. Public URLs run on the server. Same-site URLs with as_user=true run in the browser with your login (required for wp-admin).', 'ahentic' ),
					'category'            => 'ahentic-site-ops',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'url' ),
						'properties' => array(
							'url'     => array(
								'type'        => 'string',
								'description' => __( 'Absolute http(s) URL to fetch.', 'ahentic' ),
							),
							'as_user' => array(
								'type'        => 'boolean',
								'description' => __( 'When true, fetch a same-site URL in the browser using the user’s logged-in session (required for wp-admin).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_http_fetch' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::DEBUG_LOG,
				array(
					'label'               => __( 'Get debug log', 'ahentic' ),
					'description'         => __( 'Returns a capped tail of the WordPress debug.log when available, plus WP_DEBUG flags.', 'ahentic' ),
					'category'            => 'ahentic-site-ops',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'bytes' => array(
								'type'        => 'integer',
								'description' => __( 'Max bytes to read from the end of the log (default 32KB, max 64KB).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_debug_log' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::ADMIN_CONTEXT,
				array(
					'label'               => __( 'Get admin context', 'ahentic' ),
					'description'         => __( 'Interprets the current (or given) admin page URL into screen hints: page slug, post type, title, body classes.', 'ahentic' ),
					'category'            => 'ahentic-site-ops',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'url' => array(
								'type'        => 'string',
								'description' => __( 'Optional admin URL. Defaults to the sidebar page context stored for this session.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_admin_context' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::LIST_THEMES,
				array(
					'label'               => __( 'List themes', 'ahentic' ),
					'description'         => __( 'Lists installed themes with active flag and block vs classic detection.', 'ahentic' ),
					'category'            => 'ahentic-site-ops',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_themes' ),
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
				case self::HTTP_FETCH:
					return self::execute_http_fetch( $input );
				case self::DEBUG_LOG:
					return self::execute_get_debug_log( $input );
				case self::ADMIN_CONTEXT:
					return self::execute_get_admin_context( $input );
				case self::LIST_THEMES:
					return self::execute_list_themes( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown site ability.', 'ahentic' ) );
			}
		}

		/**
		 * List installed themes.
		 *
		 * @param mixed $input Unused.
		 * @return array
		 */
		public static function execute_list_themes( $input = array() ) {
			unset( $input );

			$active = get_stylesheet();
			$themes = wp_get_themes();
			$items  = array();

			foreach ( $themes as $stylesheet => $theme ) {
				if ( ! ( $theme instanceof WP_Theme ) ) {
					continue;
				}
				$parent = $theme->parent();
				$items[] = array(
					'stylesheet'    => (string) $stylesheet,
					'name'          => (string) $theme->get( 'Name' ),
					'version'       => (string) $theme->get( 'Version' ),
					'is_active'     => ( (string) $stylesheet === (string) $active ),
					'parent'        => $parent instanceof WP_Theme ? (string) $parent->get_stylesheet() : '',
					'is_block_theme' => self::theme_is_block_theme( $theme ),
				);
			}

			usort(
				$items,
				static function ( $a, $b ) {
					if ( $a['is_active'] !== $b['is_active'] ) {
						return $a['is_active'] ? -1 : 1;
					}
					return strcasecmp( $a['stylesheet'], $b['stylesheet'] );
				}
			);

			return array(
				'ok'     => true,
				'count'  => count( $items ),
				'active' => (string) $active,
				'themes' => $items,
			);
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
				'counts'          => $counts,
				'issues'          => $issues,
				'site_health_url' => admin_url( 'site-health.php' ),
				'notes'           => array(
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

		/**
		 * Whether http-fetch should run in the browser (logged-in same-site).
		 *
		 * @param array $input Ability input.
		 * @return bool
		 */
		public static function http_fetch_requires_browser( $input = array() ) {
			$input   = is_array( $input ) ? $input : array();
			$as_user = ! empty( $input['as_user'] );
			if ( ! $as_user ) {
				return false;
			}
			$url = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				return false;
			}
			return self::url_is_same_site( $url );
		}

		/**
		 * Rate-limited HTTP GET (public server-side; as_user is browser-only).
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_http_fetch( $input = array() ) {
			$input   = is_array( $input ) ? $input : array();
			$url     = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
			$as_user = ! empty( $input['as_user'] );

			if ( '' === $url ) {
				return new WP_Error( 'ahentic_missing_url', __( 'A URL is required.', 'ahentic' ) );
			}

			if ( ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'ahentic_invalid_url', __( 'That URL is not valid.', 'ahentic' ) );
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return new WP_Error( 'ahentic_invalid_url', __( 'That URL is not valid.', 'ahentic' ) );
			}

			$scheme = strtolower( (string) $parts['scheme'] );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return new WP_Error( 'ahentic_invalid_url', __( 'Only http and https URLs are allowed.', 'ahentic' ) );
			}

			$same_site = self::url_is_same_site( $url );
			if ( $as_user && ! $same_site ) {
				return new WP_Error(
					'ahentic_as_user_same_site',
					__( 'as_user is only allowed for URLs on this WordPress site.', 'ahentic' )
				);
			}
			if ( $as_user ) {
				// Interactive path should have paused for browser already. This is the headless /
				// Agents fallback until a signed probe exists.
				return array(
					'ok'               => false,
					'error'            => 'browser_required',
					'url'              => esc_url_raw( $url ),
					'same_site'        => true,
					'as_user'          => true,
					'message'          => __( 'Logged-in fetches need the Ahentic sidebar (browser session). Keep Ahentic open on a working admin page and retry — a headless probe is not available yet.', 'ahentic' ),
					'looks_like_login' => false,
					'looks_like_admin' => false,
				);
			}

			if ( ! $same_site && ! self::host_is_publicly_fetchable( (string) $parts['host'] ) ) {
				return new WP_Error(
					'ahentic_url_blocked',
					__( 'That host cannot be fetched (private or reserved).', 'ahentic' )
				);
			}

			$args = array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'User-Agent' => 'Ahentic/' . ( defined( 'AHENTIC_VERSION' ) ? AHENTIC_VERSION : '0.1' ) . '; ' . home_url( '/' ),
				),
			);

			$used_auth = false;

			$started  = microtime( true );
			$response = wp_remote_get( $url, $args );
			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $response ) ) {
				return array(
					'ok'        => false,
					'error'     => $response->get_error_code(),
					'message'   => $response->get_error_message(),
					'url'       => esc_url_raw( $url ),
					'same_site' => $same_site,
					'as_user'   => $as_user,
					'duration_ms' => $duration,
					'timed_out' => false !== strpos( strtolower( $response->get_error_message() ), 'timed out' ),
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );
			$bytes  = strlen( $body );
			$trunc  = $bytes > self::HTTP_MAX_BYTES;
			if ( $trunc ) {
				$body = substr( $body, 0, self::HTTP_MAX_BYTES );
			}

			$excerpt = self::http_body_excerpt( $body );
			$lower   = strtolower( $body );

			$looks_login = ( false !== strpos( $lower, 'name="log"' ) && false !== strpos( $lower, 'name="pwd"' ) )
				|| false !== strpos( $lower, 'id="loginform"' )
				|| false !== strpos( $lower, '/wp-login.php' );

			$looks_admin = false !== strpos( $lower, 'id="wpadminbar"' )
				|| false !== strpos( $lower, 'id="wpbody"' )
				|| false !== strpos( $lower, 'class="wp-admin' );

			$success_marker = $as_user ? ( $looks_admin && ! $looks_login && $status >= 200 && $status < 400 && $bytes > 200 )
				: ( $status >= 200 && $status < 400 && $bytes > 0 );

			return array(
				'ok'               => $success_marker,
				'url'              => esc_url_raw( $url ),
				'final_url'        => esc_url_raw( (string) wp_remote_retrieve_header( $response, 'location' ) ),
				'status'           => $status,
				'duration_ms'      => $duration,
				'body_bytes'       => $bytes,
				'truncated'       => $trunc,
				'excerpt'          => $excerpt,
				'same_site'        => $same_site,
				'as_user'          => $as_user,
				'auth_used'        => $used_auth,
				'looks_like_login' => $looks_login,
				'looks_like_admin' => $looks_admin,
				'success_marker'   => $success_marker,
				'content_type'     => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			);
		}

		/**
		 * Capped debug.log tail.
		 *
		 * @param mixed $input Input.
		 * @return array
		 */
		public static function execute_get_debug_log( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$bytes = isset( $input['bytes'] ) ? (int) $input['bytes'] : self::DEBUG_LOG_MAX_BYTES;
			$bytes = max( 1024, min( 65536, $bytes ) );

			$wp_debug     = defined( 'WP_DEBUG' ) && WP_DEBUG;
			$wp_debug_log = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
			$wp_debug_display = defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true;

			$path = self::resolve_debug_log_path();
			$base = array(
				'wp_debug'         => (bool) $wp_debug,
				'wp_debug_log'     => (bool) $wp_debug_log,
				'wp_debug_display' => $wp_debug_display,
				'path'             => $path ? self::display_path( $path ) : '',
				'path_resolved'    => (bool) $path,
			);

			if ( ! $path ) {
				return array_merge(
					$base,
					array(
						'available' => false,
						'reason'    => $wp_debug && $wp_debug_log ? 'not_found' : 'logging_disabled',
						'hint'      => __( 'Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php, reproduce the issue, then retry.', 'ahentic' ),
						'excerpt'   => '',
					)
				);
			}

			if ( ! is_readable( $path ) ) {
				return array_merge(
					$base,
					array(
						'available' => false,
						'reason'    => 'not_readable',
						'hint'      => __( 'debug.log exists but is not readable by PHP.', 'ahentic' ),
						'excerpt'   => '',
					)
				);
			}

			$size = filesize( $path );
			if ( false === $size ) {
				$size = 0;
			}

			$offset  = max( 0, (int) $size - $bytes );
			$chunk   = file_get_contents( $path, false, null, $offset, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local capped read; Plugin Check allows this.
			if ( false === $chunk ) {
				$chunk = '';
			}

			if ( $offset > 0 && '' !== $chunk ) {
				$nl = strpos( $chunk, "\n" );
				if ( false !== $nl ) {
					$chunk = substr( $chunk, $nl + 1 );
				}
			}

			$excerpt = self::redact_log_excerpt( $chunk );

			return array_merge(
				$base,
				array(
					'available'  => true,
					'exists'     => true,
					'size_bytes' => (int) $size,
					'truncated' => ( (int) $size > $bytes ),
					'excerpt'    => $excerpt,
					'mtime'      => gmdate( 'c', (int) filemtime( $path ) ),
				)
			);
		}

		/**
		 * Interpret admin / page context for the agent.
		 *
		 * @param mixed $input Input.
		 * @return array
		 */
		public static function execute_get_admin_context( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$url   = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';

			$stored = array();
			if ( class_exists( 'Ahentic_Session_Repository' ) ) {
				// Prefer the most recently stored page context for any in-flight session owner call.
				// Orchestrator passes session via a static request flag when available.
				$session_id = 0;
				if ( class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'current_session_id' ) ) {
					$session_id = (int) Ahentic_Orchestrator::current_session_id();
				}
				if ( $session_id > 0 ) {
					$stored = Ahentic_Session_Repository::get_page_context( $session_id );
				}
			}

			if ( '' === $url && ! empty( $stored['url'] ) ) {
				$url = (string) $stored['url'];
			}

			$title      = ! empty( $stored['title'] ) ? (string) $stored['title'] : '';
			$body_class = ! empty( $stored['bodyClass'] ) ? (string) $stored['bodyClass'] : ( ! empty( $stored['body_class'] ) ? (string) $stored['body_class'] : '' );
			$is_admin   = isset( $stored['isAdmin'] ) ? (bool) $stored['isAdmin'] : null;

			$parts    = $url ? wp_parse_url( $url ) : array();
			$path     = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '';
			$query    = array();
			if ( is_array( $parts ) && ! empty( $parts['query'] ) ) {
				wp_parse_str( (string) $parts['query'], $query );
			}

			if ( null === $is_admin ) {
				$is_admin = ( false !== strpos( $path, '/wp-admin' ) );
			}

			$page      = isset( $query['page'] ) ? sanitize_key( (string) $query['page'] ) : '';
			$post_type = isset( $query['post_type'] ) ? sanitize_key( (string) $query['post_type'] ) : '';
			$action    = isset( $query['action'] ) ? sanitize_key( (string) $query['action'] ) : '';
			$post_id   = isset( $query['post'] ) ? (int) $query['post'] : 0;

			$script = '';
			if ( $path ) {
				$script = basename( $path );
			}

			$screen_hints = array();
			if ( $body_class ) {
				foreach ( preg_split( '/\s+/', $body_class ) as $class ) {
					$class = trim( (string) $class );
					if ( '' === $class ) {
						continue;
					}
					if (
						0 === strpos( $class, 'toplevel_page_' )
						|| 0 === strpos( $class, 'admin_page_' )
						|| 0 === strpos( $class, 'post-type-' )
						|| 0 === strpos( $class, 'taxonomy-' )
						|| 0 === strpos( $class, 'edit-php' )
						|| 0 === strpos( $class, 'index-php' )
						|| 'wp-admin' === $class
						|| 'wp-core-ui' === $class
					) {
						$screen_hints[] = $class;
					}
					if ( count( $screen_hints ) >= 12 ) {
						break;
					}
				}
			}

			return array(
				'available'    => '' !== $url || ! empty( $stored ),
				'url'          => $url ? esc_url_raw( $url ) : '',
				'title'        => $title,
				'is_admin'     => (bool) $is_admin,
				'script'       => $script,
				'page'         => $page,
				'post_type'    => $post_type,
				'action'       => $action,
				'post_id'      => $post_id > 0 ? $post_id : null,
				'path'         => $path,
				'body_classes' => $screen_hints,
				'source'       => '' !== $url && empty( $stored['url'] ) ? 'input' : ( ! empty( $stored ) ? 'session' : 'none' ),
				'notes'        => array(
					__( 'For live page identity (URL/title/body classes), use ahentic-browser/get-current-page.', 'ahentic' ),
					__( 'For what is visible on the open tab (headings, notices, actions, fields, excerpt), use ahentic-browser/get-visible-page.', 'ahentic' ),
				),
			);
		}

		/**
		 * Resolve WP debug log path (no arbitrary user paths).
		 *
		 * @return string|null Absolute path or null.
		 */
		private static function resolve_debug_log_path() {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				if ( in_array( strtolower( (string) WP_DEBUG_LOG ), array( 'true', '1' ), true ) ) {
					$path = WP_CONTENT_DIR . '/debug.log';
					return $path;
				}
				if ( is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
					return WP_DEBUG_LOG;
				}
			}

			$default = WP_CONTENT_DIR . '/debug.log';
			return file_exists( $default ) ? $default : null;
		}

		/**
		 * Relative-ish path for display.
		 *
		 * @param string $path Absolute path.
		 * @return string
		 */
		private static function display_path( $path ) {
			$path = wp_normalize_path( (string) $path );
			$content = wp_normalize_path( WP_CONTENT_DIR );
			if ( 0 === strpos( $path, $content ) ) {
				return 'wp-content' . substr( $path, strlen( $content ) );
			}
			$abspath = wp_normalize_path( ABSPATH );
			if ( 0 === strpos( $path, $abspath ) ) {
				return ltrim( substr( $path, strlen( $abspath ) ), '/' );
			}
			return basename( $path );
		}

		/**
		 * Redact obvious secrets from log excerpts.
		 *
		 * @param string $text Log chunk.
		 * @return string
		 */
		private static function redact_log_excerpt( $text ) {
			$text = (string) $text;
			$text = preg_replace( '/(password|passwd|pwd|secret|token|authorization|api[_-]?key)\s*[:=]\s*\S+/i', '$1=[redacted]', $text );
			$text = preg_replace( '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [redacted]', $text );
			$text = preg_replace( '/(wordpress_[a-z0-9_]*cookie[^=]*=)[^;\s]+/i', '$1[redacted]', $text );
			return $text;
		}

		/**
		 * @param string $url URL.
		 * @return bool
		 */
		private static function url_is_same_site( $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host ) {
				return false;
			}
			$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			$site_host = strtolower( (string) wp_parse_url( site_url( '/' ), PHP_URL_HOST ) );
			return ( $host === $home_host || $host === $site_host );
		}

		/**
		 * Block obvious private/reserved hosts for public fetches.
		 *
		 * @param string $host Hostname.
		 * @return bool
		 */
		public static function host_is_publicly_fetchable( $host ) {
			$host = strtolower( trim( $host ) );
			if ( '' === $host ) {
				return false;
			}
			if ( 'localhost' === $host || preg_match( '/\.local$/', $host ) || preg_match( '/\.localhost$/', $host ) ) {
				return false;
			}
			if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
			}
			$resolved = gethostbyname( $host );
			if ( $resolved === $host ) {
				// Unresolved — allow and let HTTP fail (avoids blocking valid public DNS weirdness).
				return true;
			}
			return (bool) filter_var( $resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		/**
		 * Strip tags and collapse whitespace for model-sized excerpt.
		 *
		 * @param string $body Response body.
		 * @return string
		 */
		private static function http_body_excerpt( $body ) {
			$body = (string) $body;
			// Avoid leaking auth forms wholesale.
			$body = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', $body );
			$body = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $body );
			$text = wp_strip_all_tags( $body );
			$text = preg_replace( '/\s+/', ' ', $text );
			$text = trim( (string) $text );
			if ( strlen( $text ) > 4000 ) {
				$text = rtrim( substr( $text, 0, 3999 ) ) . '…';
			}
			return $text;
		}

		/**
		 * @param string $name  Ability name.
		 * @param array  $input Input.
		 * @return bool
		 */
		public static function requires_browser_runtime( $name, $input = array() ) {
			if ( self::HTTP_FETCH !== (string) $name ) {
				return false;
			}
			return self::http_fetch_requires_browser( $input );
		}

		/**
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function browser_summary( $name, $input = array() ) {
			if ( self::HTTP_FETCH !== (string) $name || ! self::http_fetch_requires_browser( $input ) ) {
				return '';
			}
			$url = isset( $input['url'] ) ? (string) $input['url'] : '';
			return $url
				? sprintf(
					/* translators: %s: URL */
					__( 'Fetch as you: %s', 'ahentic' ),
					$url
				)
				: __( 'Fetch URL as you (browser)', 'ahentic' );
		}

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			$map = array(
				self::HEALTH        => __( 'Checking site health…', 'ahentic' ),
				self::OPTION        => __( 'Reading site settings…', 'ahentic' ),
				self::HTTP_FETCH    => __( 'Fetching URL…', 'ahentic' ),
				self::DEBUG_LOG     => __( 'Reading debug log…', 'ahentic' ),
				self::ADMIN_CONTEXT => __( 'Reading admin page context…', 'ahentic' ),
				self::LIST_THEMES   => __( 'Listing themes…', 'ahentic' ),
			);
			$name = (string) $name;
			return isset( $map[ $name ] ) ? $map[ $name ] : '';
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Site', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Site', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Site' );
}
