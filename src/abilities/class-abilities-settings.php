<?php
/**
 * Settings discovery abilities: context, Customizer index, setting values.
 *
 * @package Ahentic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Settings' ) ) {
	/**
	 * Readonly theme/settings discovery for the agent loop (Track C).
	 */
	class Ahentic_Abilities_Settings {
		const GET_CONTEXT   = 'ahentic/get-settings-context';
		const LIST_SETTINGS = 'ahentic/list-settings';
		const GET_SETTING   = 'ahentic/get-setting';

		const LIST_PAGE_SIZE      = 50;
		const VALUE_SUMMARY_BYTES = 2048;
		const VALUE_RAW_MAX_BYTES = 65536;
		const INDEX_TRANSIENT_TTL = 604800; // 1 week
		const INDEX_GEN_OPTION    = 'ahentic_settings_index_gen';

		/**
		 * Single policy catalog: drives names / write / HITL / progress / summary.
		 *
		 * @return array<string, array{write?:bool, hitl?:bool, progress:string, summary:string}>
		 */
		private static function catalog() {
			return array(
				self::GET_CONTEXT   => array(
					'progress' => __( 'Reading settings context…', 'ahentic' ),
					'summary'  => __( 'Get settings context', 'ahentic' ),
				),
				self::LIST_SETTINGS => array(
					'progress' => __( 'Listing theme settings…', 'ahentic' ),
					'summary'  => __( 'List settings', 'ahentic' ),
				),
				self::GET_SETTING   => array(
					'progress' => __( 'Reading setting…', 'ahentic' ),
					'summary'  => __( 'Get setting', 'ahentic' ),
				),
			);
		}

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array_keys( self::catalog() );
		}

		/**
		 * Write (non-readonly) ability names.
		 *
		 * @return string[]
		 */
		public static function write_names() {
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['write'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * Whether the ability is readonly.
		 *
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function is_readonly( $name ) {
			return ! in_array( (string) $name, self::write_names(), true );
		}

		/**
		 * Abilities that must pause for human approval before running.
		 *
		 * @return string[]
		 */
		public static function hitl_names() {
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['hitl'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * Whether the ability requires HITL.
		 *
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function requires_hitl( $name ) {
			return in_array( (string) $name, self::hitl_names(), true );
		}

		/**
		 * Short summary for pending-tool UI / progress.
		 *
		 * @param string $name Ability.
		 * @return string
		 */
		public static function summary( $name ) {
			$catalog = self::catalog();
			$key     = (string) $name;
			if ( isset( $catalog[ $key ]['summary'] ) ) {
				return $catalog[ $key ]['summary'];
			}
			return $key;
		}

		/**
		 * Progress label while the tool runs.
		 *
		 * @param string $name Ability.
		 * @return string
		 */
		public static function progress_label( $name ) {
			$catalog = self::catalog();
			$key     = (string) $name;
			if ( isset( $catalog[ $key ]['progress'] ) ) {
				return $catalog[ $key ]['progress'];
			}
			return '';
		}

		/**
		 * Whether list-settings input includes a required filter.
		 *
		 * @param array $input Ability input.
		 * @return bool
		 */
		public static function list_input_is_filtered( array $input ) {
			foreach ( array( 'query', 'section', 'prefix' ) as $key ) {
				if ( ! isset( $input[ $key ] ) ) {
					continue;
				}
				if ( '' !== trim( (string) $input[ $key ] ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether an index entry is a code-injection surface (excluded from discovery).
		 *
		 * @param array $entry Index row.
		 * @return bool
		 */
		public static function is_code_bearing_index_entry( array $entry ) {
			$id = isset( $entry['id'] ) ? (string) $entry['id'] : '';
			// Core Additional CSS uses custom_css[{stylesheet}]; exclude that family only.
			if ( '' !== $id && ( 'custom_css' === $id || 0 === strpos( $id, 'custom_css[' ) ) ) {
				return true;
			}

			$capability = isset( $entry['capability'] ) ? (string) $entry['capability'] : '';
			if ( in_array( $capability, array( 'edit_css', 'unfiltered_html' ), true ) ) {
				return true;
			}

			$control_type = isset( $entry['control_type'] ) ? (string) $entry['control_type'] : '';
			if ( '' === $control_type ) {
				return false;
			}
			if ( false !== stripos( $control_type, 'Code_Editor' ) ) {
				return true;
			}
			if ( 'code_editor' === $control_type ) {
				return true;
			}
			return false;
		}

		/**
		 * Filter a settings index by query / section / prefix.
		 *
		 * @param array $entries Index rows.
		 * @param array $input   Ability input.
		 * @return array
		 */
		public static function filter_settings_index( array $entries, array $input ) {
			$query   = isset( $input['query'] ) ? strtolower( trim( (string) $input['query'] ) ) : '';
			$section = isset( $input['section'] ) ? strtolower( trim( (string) $input['section'] ) ) : '';
			$prefix  = isset( $input['prefix'] ) ? (string) $input['prefix'] : '';

			$out = array();
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$id    = isset( $entry['id'] ) ? (string) $entry['id'] : '';
				$label = isset( $entry['label'] ) ? (string) $entry['label'] : '';
				$sec   = isset( $entry['section'] ) ? (string) $entry['section'] : '';

				if ( '' !== $section && strtolower( $sec ) !== $section ) {
					continue;
				}
				if ( '' !== $prefix && 0 !== strpos( $id, $prefix ) ) {
					continue;
				}
				if ( '' !== $query ) {
					$hay = strtolower( $id . ' ' . $label . ' ' . $sec );
					if ( false === strpos( $hay, $query ) ) {
						continue;
					}
				}
				$out[] = $entry;
			}
			return $out;
		}

		/**
		 * Shape a get-setting value: summarize oversized blobs unless raw is requested.
		 *
		 * @param mixed $value            Live value.
		 * @param bool  $raw              Request full value.
		 * @param int   $summary_bytes    JSON size above which to summarize (when not raw).
		 * @param int   $raw_max_bytes    Hard cap for raw JSON size.
		 * @return array{summarized:bool, value?:mixed, shape?:array, truncated?:bool, bytes?:int}
		 */
		public static function value_for_get_setting( $value, $raw = false, $summary_bytes = self::VALUE_SUMMARY_BYTES, $raw_max_bytes = self::VALUE_RAW_MAX_BYTES ) {
			$json = self::json_encode( $value );
			if ( false === $json ) {
				$json = '';
			}
			$bytes = strlen( $json );

			if ( $raw ) {
				if ( $bytes > (int) $raw_max_bytes ) {
					return array(
						'summarized' => true,
						'truncated'  => true,
						'bytes'      => $bytes,
						'shape'      => self::shape_summary( $value ),
						'hint'       => __( 'Value exceeds the raw size cap; returning a shape summary instead.', 'ahentic' ),
					);
				}
				return array(
					'summarized' => false,
					'bytes'      => $bytes,
					'value'      => $value,
				);
			}

			if ( $bytes <= (int) $summary_bytes ) {
				return array(
					'summarized' => false,
					'bytes'      => $bytes,
					'value'      => $value,
				);
			}

			return array(
				'summarized' => true,
				'bytes'      => $bytes,
				'shape'      => self::shape_summary( $value ),
				'hint'       => __( 'Value is large; pass raw:true for the full blob (still capped).', 'ahentic' ),
			);
		}

		/**
		 * Compact shape summary for large nested setting values.
		 *
		 * @param mixed $value Value.
		 * @return array
		 */
		public static function shape_summary( $value ) {
			$shape = array(
				'type' => gettype( $value ),
			);

			if ( is_array( $value ) ) {
				$keys            = array_keys( $value );
				$shape['keys']   = array_map( 'strval', $keys );
				$shape['length'] = count( $value );

				$item_ids = array();
				if ( isset( $value['sections'] ) && is_array( $value['sections'] ) ) {
					$shape['sections_count'] = count( $value['sections'] );
					foreach ( $value['sections'] as $section ) {
						if ( ! is_array( $section ) ) {
							continue;
						}
						if ( empty( $section['items'] ) || ! is_array( $section['items'] ) ) {
							continue;
						}
						foreach ( $section['items'] as $item ) {
							if ( is_array( $item ) && isset( $item['id'] ) ) {
								$item_ids[] = (string) $item['id'];
							}
						}
					}
				}
				if ( ! empty( $item_ids ) ) {
					$shape['sections_item_ids'] = array_values( array_unique( $item_ids ) );
				}

				foreach ( $keys as $k ) {
					if ( is_array( $value[ $k ] ) ) {
						$shape['array_lengths'][ (string) $k ] = count( $value[ $k ] );
					}
				}
			} elseif ( is_string( $value ) ) {
				$shape['length'] = strlen( $value );
			}

			return $shape;
		}

		/**
		 * Build get-settings-context payload from theme identity (no Customizer bootstrap).
		 *
		 * @param string $stylesheet     Active stylesheet.
		 * @param bool   $is_block_theme Whether the active theme is a block theme.
		 * @return array
		 */
		public static function settings_context_payload( $stylesheet, $is_block_theme ) {
			$stylesheet     = (string) $stylesheet;
			$is_block_theme = (bool) $is_block_theme;

			if ( $is_block_theme ) {
				return array(
					'ok'             => true,
					'stylesheet'     => $stylesheet,
					'is_block_theme' => true,
					'surfaces'       => array( 'global_styles', 'template_parts' ),
					'routing_hint'   => __( 'Active theme is a block theme. Prefer global styles and template parts for theme appearance (not classic Customizer theme_settings). Use ahentic/get-settings-context before choosing a write path.', 'ahentic' ),
				);
			}

			return array(
				'ok'             => true,
				'stylesheet'     => $stylesheet,
				'is_block_theme' => false,
				'surfaces'       => array( 'theme_settings' ),
				'routing_hint'   => __( 'Active theme is classic. Discover Customizer settings with ahentic/list-settings (always pass query, section, or prefix — never unfiltered) and ahentic/get-setting for full values.', 'ahentic' ),
			);
		}

		/**
		 * Register category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}
			wp_register_ability_category(
				'ahentic-settings',
				array(
					'label'       => __( 'Ahentic Settings', 'ahentic' ),
					'description' => __( 'Theme and site settings discovery for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register abilities and cache invalidation hooks.
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
				self::GET_CONTEXT,
				array(
					'label'               => __( 'Get settings context', 'ahentic' ),
					'description'         => __( 'Reports whether the active theme is block or classic, which settings surfaces apply, and a routing hint.', 'ahentic' ),
					'category'            => 'ahentic-settings',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_settings_context' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::LIST_SETTINGS,
				array(
					'label'               => __( 'List settings', 'ahentic' ),
					'description'         => __( 'Filtered, paginated Customizer settings index for classic themes. Requires query, section, or prefix — refuses unfiltered dumps.', 'ahentic' ),
					'category'            => 'ahentic-settings',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'query'   => array(
								'type'        => 'string',
								'description' => __( 'Keyword matched against setting id, label, and section.', 'ahentic' ),
							),
							'section' => array(
								'type'        => 'string',
								'description' => __( 'Exact Customizer section id.', 'ahentic' ),
							),
							'prefix'  => array(
								'type'        => 'string',
								'description' => __( 'Setting id prefix (e.g. header_).', 'ahentic' ),
							),
							'offset'  => array(
								'type'        => 'integer',
								'description' => __( 'Pagination offset (default 0).', 'ahentic' ),
							),
							'limit'   => array(
								'type'        => 'integer',
								'description' => __( 'Page size (default 50, max 50).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_settings' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);

			wp_register_ability(
				self::GET_SETTING,
				array(
					'label'               => __( 'Get setting', 'ahentic' ),
					'description'         => __( 'Reads one or more Customizer setting values. Large nested values return a shape summary unless raw:true is set.', 'ahentic' ),
					'category'            => 'ahentic-settings',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'ids' ),
						'properties' => array(
							'ids' => array(
								'type'        => 'array',
								'description' => __( 'One or more Customizer setting ids.', 'ahentic' ),
								'items'       => array( 'type' => 'string' ),
								'minItems'    => 1,
							),
							'raw' => array(
								'type'        => 'boolean',
								'description' => __( 'When true, return the full value (still size-capped) instead of a shape summary.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_setting' ),
					'permission_callback' => $permission,
					'meta'                => $meta,
				)
			);
		}

		/**
		 * Hook cache invalidation (called once from file bootstrap).
		 */
		public static function register_cache_hooks() {
			if ( ! function_exists( 'add_action' ) ) {
				return;
			}
			add_action( 'switch_theme', array( __CLASS__, 'invalidate_index_cache' ) );
			add_action( 'activated_plugin', array( __CLASS__, 'invalidate_index_cache' ) );
			add_action( 'deactivated_plugin', array( __CLASS__, 'invalidate_index_cache' ) );
			add_action( 'upgrader_process_complete', array( __CLASS__, 'maybe_invalidate_on_theme_upgrade' ), 10, 2 );
		}

		/**
		 * Invalidate cache after a theme upgrade completes.
		 *
		 * @param mixed $upgrader Upgrader instance.
		 * @param mixed $options  Upgrade options.
		 */
		public static function maybe_invalidate_on_theme_upgrade( $upgrader, $options ) {
			unset( $upgrader );
			if ( ! is_array( $options ) ) {
				return;
			}
			if ( isset( $options['type'] ) && 'theme' === $options['type'] ) {
				self::invalidate_index_cache();
			}
		}

		/**
		 * Bump the settings-index generation so prior transients are unreachable.
		 */
		public static function invalidate_index_cache() {
			if ( ! function_exists( 'get_option' ) ) {
				return;
			}
			$gen = (int) get_option( self::INDEX_GEN_OPTION, 1 );
			update_option( self::INDEX_GEN_OPTION, $gen + 1, false );
		}

		/**
		 * Dispatch a settings ability.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			switch ( $name ) {
				case self::GET_CONTEXT:
					return self::execute_get_settings_context( $input );
				case self::LIST_SETTINGS:
					return self::execute_list_settings( $input );
				case self::GET_SETTING:
					return self::execute_get_setting( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown settings ability.', 'ahentic' ) );
			}
		}

		/**
		 * Execute get-settings-context.
		 *
		 * @param mixed $input Unused.
		 * @return array
		 */
		public static function execute_get_settings_context( $input = array() ) {
			unset( $input );
			$theme    = wp_get_theme();
			$is_block = class_exists( 'Ahentic_Abilities_Site' )
				? Ahentic_Abilities_Site::theme_is_block_theme( $theme )
				: ( method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme() );

			$payload                  = self::settings_context_payload( get_stylesheet(), $is_block );
			$payload['theme_name']    = (string) $theme->get( 'Name' );
			$payload['theme_version'] = (string) $theme->get( 'Version' );
			return $payload;
		}

		/**
		 * Execute list-settings (filtered Customizer index).
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_list_settings( $input = array() ) {
			$input = is_array( $input ) ? $input : array();

			if ( ! self::list_input_is_filtered( $input ) ) {
				return new WP_Error(
					'ahentic_settings_unfiltered',
					__( 'list-settings requires a filter: pass query, section, or prefix. Refusing to dump the full Customizer registry.', 'ahentic' ),
					array(
						'status' => 400,
						'hint'   => __( 'Search by keyword (query), Customizer section id, or setting id prefix.', 'ahentic' ),
					)
				);
			}

			$context = self::execute_get_settings_context();
			if ( ! empty( $context['is_block_theme'] ) ) {
				return array(
					'ok'       => true,
					'surface'  => 'theme_settings',
					'count'    => 0,
					'total'    => 0,
					'offset'   => 0,
					'limit'    => self::LIST_PAGE_SIZE,
					'has_more' => false,
					'settings' => array(),
					'message'  => __( 'Active theme is a block theme; classic Customizer theme_settings are not the primary surface. See get-settings-context.', 'ahentic' ),
					'context'  => $context,
				);
			}

			$index = self::get_cached_settings_index();
			if ( is_wp_error( $index ) ) {
				return $index;
			}

			$filtered = self::filter_settings_index( $index, $input );
			$total    = count( $filtered );
			$offset   = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
			$limit    = isset( $input['limit'] ) ? (int) $input['limit'] : self::LIST_PAGE_SIZE;
			if ( $limit < 1 ) {
				$limit = self::LIST_PAGE_SIZE;
			}
			if ( $limit > self::LIST_PAGE_SIZE ) {
				$limit = self::LIST_PAGE_SIZE;
			}

			$page = array_slice( $filtered, $offset, $limit );
			$rows = array();
			foreach ( $page as $entry ) {
				$row            = $entry;
				$row['value']   = self::read_live_value( $entry );
				$row['surface'] = 'theme_settings';
				$rows[]         = $row;
			}

			return array(
				'ok'          => true,
				'surface'     => 'theme_settings',
				'count'       => count( $rows ),
				'total'       => $total,
				'offset'      => $offset,
				'limit'       => $limit,
				'has_more'    => ( $offset + count( $rows ) ) < $total,
				'next_offset' => ( $offset + count( $rows ) ) < $total ? $offset + count( $rows ) : null,
				'settings'    => $rows,
			);
		}

		/**
		 * Execute get-setting (one or more ids).
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_get_setting( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$ids   = array();
			if ( isset( $input['ids'] ) && is_array( $input['ids'] ) ) {
				$ids = $input['ids'];
			} elseif ( isset( $input['id'] ) ) {
				$ids = array( $input['id'] );
			}
			$ids = array_values(
				array_filter(
					array_map(
						static function ( $id ) {
							return trim( (string) $id );
						},
						$ids
					)
				)
			);

			if ( empty( $ids ) ) {
				return new WP_Error(
					'ahentic_missing_setting_ids',
					__( 'Provide one or more setting ids.', 'ahentic' )
				);
			}

			$raw   = ! empty( $input['raw'] );
			$index = self::get_cached_settings_index();
			if ( is_wp_error( $index ) ) {
				return $index;
			}

			$by_id = array();
			foreach ( $index as $entry ) {
				if ( isset( $entry['id'] ) ) {
					$by_id[ (string) $entry['id'] ] = $entry;
				}
			}

			$settings = array();
			foreach ( $ids as $id ) {
				if ( ! isset( $by_id[ $id ] ) ) {
					$settings[] = array(
						'id'      => $id,
						'ok'      => false,
						'error'   => 'ahentic_setting_not_found',
						'message' => __( 'Setting id is not in the Customizer index (or is excluded as code-bearing).', 'ahentic' ),
					);
					continue;
				}
				$entry      = $by_id[ $id ];
				$live       = self::read_live_value( $entry );
				$pack       = self::value_for_get_setting( $live, $raw );
				$settings[] = array_merge(
					array(
						'id'      => $id,
						'ok'      => true,
						'surface' => 'theme_settings',
						'label'   => isset( $entry['label'] ) ? $entry['label'] : '',
						'type'    => isset( $entry['type'] ) ? $entry['type'] : 'theme_mod',
						'default' => isset( $entry['default'] ) ? $entry['default'] : null,
					),
					$pack
				);
			}

			return array(
				'ok'       => true,
				'count'    => count( $settings ),
				'settings' => $settings,
			);
		}

		/**
		 * Transient key for the current theme + plugins + generation.
		 *
		 * @return string
		 */
		public static function index_cache_key() {
			$theme   = wp_get_theme();
			$plugins = get_option( 'active_plugins', array() );
			if ( ! is_array( $plugins ) ) {
				$plugins = array();
			}
			$gen = (int) get_option( self::INDEX_GEN_OPTION, 1 );
			$raw = $theme->get_stylesheet() . '|' . $theme->get( 'Version' ) . '|' . md5( wp_json_encode( array_values( $plugins ) ) ) . '|' . $gen;
			return 'ahentic_csidx_' . md5( $raw );
		}

		/**
		 * Cached Customizer index (no live values). Bootstraps Customizer on miss.
		 *
		 * @return array|\WP_Error
		 */
		public static function get_cached_settings_index() {
			$key    = self::index_cache_key();
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			$built = self::bootstrap_and_harvest_index();
			if ( is_wp_error( $built ) ) {
				return $built;
			}

			set_transient( $key, $built, self::INDEX_TRANSIENT_TTL );
			return $built;
		}

		/**
		 * Instantiate WP_Customize_Manager, fire customize_register, harvest index.
		 *
		 * @return array|\WP_Error
		 */
		public static function bootstrap_and_harvest_index() {
			if ( ! class_exists( 'WP_Customize_Manager' ) ) {
				$path = ABSPATH . 'wp-includes/class-wp-customize-manager.php';
				if ( ! file_exists( $path ) ) {
					return new WP_Error(
						'ahentic_customize_unavailable',
						__( 'WP_Customize_Manager is not available.', 'ahentic' )
					);
				}
				require_once $path;
			}

			$previous = isset( $GLOBALS['wp_customize'] ) ? $GLOBALS['wp_customize'] : null;

			try {
				$wp_customize = new WP_Customize_Manager();
				// Themes register against the global; restore previous value in finally.
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required Customizer bootstrap for index harvest (site-settings PRD).
				$GLOBALS['wp_customize'] = $wp_customize;
				do_action( 'customize_register', $wp_customize );
				return self::harvest_index_from_manager( $wp_customize );
			} catch ( Exception $e ) {
				return new WP_Error(
					'ahentic_customize_bootstrap_failed',
					sprintf(
						/* translators: %s: exception message */
						__( 'Customizer bootstrap failed: %s', 'ahentic' ),
						$e->getMessage()
					)
				);
			} finally {
				if ( null === $previous ) {
					unset( $GLOBALS['wp_customize'] );
				} else {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring prior global after temporary bootstrap.
					$GLOBALS['wp_customize'] = $previous;
				}
			}
		}

		/**
		 * Harvest id/label/section/type/choices/default from a Customize manager.
		 *
		 * @param \WP_Customize_Manager $wp_customize Manager.
		 * @return array
		 */
		public static function harvest_index_from_manager( $wp_customize ) {
			$control_by_setting = array();
			if ( method_exists( $wp_customize, 'controls' ) ) {
				foreach ( $wp_customize->controls() as $control ) {
					if ( ! is_object( $control ) ) {
						continue;
					}
					$setting_keys = array();
					if ( isset( $control->settings ) && is_array( $control->settings ) ) {
						foreach ( $control->settings as $setting_obj ) {
							if ( is_object( $setting_obj ) && isset( $setting_obj->id ) ) {
								$setting_keys[] = (string) $setting_obj->id;
							} elseif ( is_string( $setting_obj ) ) {
								$setting_keys[] = $setting_obj;
							}
						}
					}
					if ( empty( $setting_keys ) && isset( $control->setting ) && is_object( $control->setting ) && isset( $control->setting->id ) ) {
						$setting_keys[] = (string) $control->setting->id;
					}
					foreach ( $setting_keys as $sid ) {
						$control_by_setting[ $sid ] = $control;
					}
				}
			}

			$sections = array();
			if ( method_exists( $wp_customize, 'sections' ) ) {
				foreach ( $wp_customize->sections() as $section_id => $section ) {
					$sections[ (string) $section_id ] = $section;
				}
			}

			$index = array();
			if ( ! method_exists( $wp_customize, 'settings' ) ) {
				return $index;
			}

			foreach ( $wp_customize->settings() as $id => $setting ) {
				$id = (string) $id;
				if ( ! is_object( $setting ) ) {
					continue;
				}

				$control      = isset( $control_by_setting[ $id ] ) ? $control_by_setting[ $id ] : null;
				$control_type = '';
				$label        = '';
				$section_id   = '';
				$panel_id     = '';
				$choices      = array();

				if ( $control ) {
					$class_name   = get_class( $control );
					$control_type = isset( $control->type ) ? (string) $control->type : '';
					if ( false !== stripos( $class_name, 'Code_Editor' ) ) {
						$control_type = $class_name;
					} elseif ( '' === $control_type ) {
						$control_type = $class_name;
					}
					$label      = isset( $control->label ) ? (string) $control->label : '';
					$section_id = isset( $control->section ) ? (string) $control->section : '';
					if ( isset( $control->choices ) && is_array( $control->choices ) ) {
						$choices = $control->choices;
					}
				}

				if ( '' !== $section_id && isset( $sections[ $section_id ] ) ) {
					$section_obj = $sections[ $section_id ];
					if ( is_object( $section_obj ) && isset( $section_obj->panel ) ) {
						$panel_id = (string) $section_obj->panel;
					}
					if ( '' === $label && is_object( $section_obj ) && isset( $section_obj->title ) ) {
						$label = (string) $section_obj->title;
					}
				}

				$capability = isset( $setting->capability ) ? (string) $setting->capability : 'edit_theme_options';
				if ( $control && isset( $control->capability ) && is_string( $control->capability ) && '' !== $control->capability ) {
					$control_cap = (string) $control->capability;
					if ( in_array( $control_cap, array( 'edit_css', 'unfiltered_html' ), true ) ) {
						$capability = $control_cap;
					}
				}
				$type    = isset( $setting->type ) ? (string) $setting->type : 'theme_mod';
				$default = isset( $setting->default ) ? $setting->default : null;

				$entry = array(
					'id'           => $id,
					'label'        => '' !== $label ? $label : $id,
					'section'      => $section_id,
					'panel'        => $panel_id,
					'type'         => $type,
					'control_type' => $control_type,
					'choices'      => $choices,
					'default'      => $default,
					'capability'   => $capability,
				);

				if ( self::is_code_bearing_index_entry( $entry ) ) {
					continue;
				}
				// Drop capability from cached public index (used only for exclusion).
				unset( $entry['capability'] );
				$index[] = $entry;
			}

			return $index;
		}

		/**
		 * Read a setting's current value live (never from the index cache).
		 *
		 * @param array $entry Index entry with id + type + default.
		 * @return mixed
		 */
		public static function read_live_value( array $entry ) {
			$id      = isset( $entry['id'] ) ? (string) $entry['id'] : '';
			$type    = isset( $entry['type'] ) ? (string) $entry['type'] : 'theme_mod';
			$default = array_key_exists( 'default', $entry ) ? $entry['default'] : null;
			if ( '' === $id ) {
				return $default;
			}

			$parsed = self::parse_setting_id( $id );
			$base   = $parsed['base'];
			$keys   = $parsed['keys'];

			if ( 'option' === $type ) {
				$root = get_option( $base, null === $default && empty( $keys ) ? false : $default );
			} else {
				$mods = get_theme_mods();
				if ( ! is_array( $mods ) ) {
					$mods = array();
				}
				$root = array_key_exists( $base, $mods ) ? $mods[ $base ] : $default;
			}

			if ( empty( $keys ) ) {
				return $root;
			}

			$cursor = $root;
			foreach ( $keys as $key ) {
				if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
					return $default;
				}
				$cursor = $cursor[ $key ];
			}
			return $cursor;
		}

		/**
		 * Parse a Customizer setting id into base + bracket keys.
		 *
		 * @param string $id Setting id.
		 * @return array{base:string, keys:string[]}
		 */
		public static function parse_setting_id( $id ) {
			$id = (string) $id;
			if ( ! preg_match( '/^([^\[]+)((?:\[[^\]]*\])*)$/', $id, $m ) ) {
				return array(
					'base' => $id,
					'keys' => array(),
				);
			}
			$keys = array();
			if ( '' !== $m[2] && preg_match_all( '/\[([^\]]*)\]/', $m[2], $km ) ) {
				$keys = $km[1];
			}
			return array(
				'base' => $m[1],
				'keys' => $keys,
			);
		}

		/**
		 * JSON-encode helper that works in the pure unit suite without wp_json_encode.
		 *
		 * @param mixed $data Data.
		 * @return string|false
		 */
		private static function json_encode( $data ) {
			if ( function_exists( 'wp_json_encode' ) ) {
				return wp_json_encode( $data );
			}
			return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Settings', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Settings', 'register' ) );
	Ahentic_Abilities_Settings::register_cache_hooks();
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Settings' );
}
