<?php
/**
 * Settings discovery and write abilities: context, Customizer index, theme settings, global styles.
 *
 * @package Ahentic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Settings' ) ) {
	/**
	 * Theme/settings discovery and writes for the agent loop (Track C):
	 * Customizer theme settings + block-theme global styles.
	 */
	class Ahentic_Abilities_Settings {
		const GET_CONTEXT           = 'ahentic/get-settings-context';
		const LIST_SETTINGS         = 'ahentic/list-settings';
		const GET_SETTING           = 'ahentic/get-setting';
		const UPDATE_THEME_SETTING  = 'ahentic/update-theme-setting';
		const UPDATE_GLOBAL_STYLES  = 'ahentic/update-global-styles';

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
				self::GET_CONTEXT          => array(
					'progress' => __( 'Reading settings context…', 'ahentic' ),
					'summary'  => __( 'Get settings context', 'ahentic' ),
				),
				self::LIST_SETTINGS        => array(
					'progress' => __( 'Listing theme settings…', 'ahentic' ),
					'summary'  => __( 'List settings', 'ahentic' ),
				),
				self::GET_SETTING          => array(
					'progress' => __( 'Reading setting…', 'ahentic' ),
					'summary'  => __( 'Get setting', 'ahentic' ),
				),
				self::UPDATE_THEME_SETTING => array(
					'write'    => true,
					'hitl'     => true,
					'progress' => __( 'Updating theme setting…', 'ahentic' ),
					'summary'  => __( 'Update theme setting', 'ahentic' ),
				),
				self::UPDATE_GLOBAL_STYLES => array(
					'write'    => true,
					'hitl'     => true,
					'progress' => __( 'Updating global styles…', 'ahentic' ),
					'summary'  => __( 'Update global styles', 'ahentic' ),
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
		 * Human-readable summary for HITL UI.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function hitl_summary( $name, $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$key   = (string) $name;

			if ( self::UPDATE_GLOBAL_STYLES === $key ) {
				$parts = array();
				if ( isset( $input['styles'] ) && is_array( $input['styles'] ) ) {
					$parts[] = 'styles';
				}
				if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
					$parts[] = 'settings';
				}
				$surface = empty( $parts ) ? 'global styles' : implode( '+', $parts );

				if ( ! empty( $input['dry_run'] ) ) {
					return sprintf(
						/* translators: %s: styles and/or settings */
						__( 'Preview global styles update (%s, dry run)', 'ahentic' ),
						$surface
					);
				}
				return sprintf(
					/* translators: %s: styles and/or settings */
					__( 'Update block-theme global styles (%s)', 'ahentic' ),
					$surface
				);
			}

			if ( self::UPDATE_THEME_SETTING !== $key ) {
				return self::summary( $name );
			}

			$changes = isset( $input['changes'] ) && is_array( $input['changes'] ) ? $input['changes'] : array();
			$ids     = array();
			foreach ( $changes as $change ) {
				if ( ! is_array( $change ) || empty( $change['id'] ) ) {
					continue;
				}
				$ids[] = (string) $change['id'];
			}
			$ids = array_values( array_unique( $ids ) );
			$n   = count( $ids );

			if ( ! empty( $input['dry_run'] ) ) {
				if ( 0 === $n ) {
					return __( 'Preview theme setting changes (dry run)', 'ahentic' );
				}
				if ( 1 === $n ) {
					return sprintf(
						/* translators: %s: setting id */
						__( 'Preview theme setting change for “%s” (dry run)', 'ahentic' ),
						$ids[0]
					);
				}
				return sprintf(
					/* translators: %d: number of settings */
					__( 'Preview changes to %d theme settings (dry run)', 'ahentic' ),
					$n
				);
			}

			if ( 0 === $n ) {
				return __( 'Update theme setting(s)', 'ahentic' );
			}
			if ( 1 === $n ) {
				return sprintf(
					/* translators: %s: setting id */
					__( 'Update theme setting “%s”', 'ahentic' ),
					$ids[0]
				);
			}
			return sprintf(
				/* translators: %d: number of settings */
				__( 'Update %d theme settings', 'ahentic' ),
				$n
			);
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
		 * Structured refusal for code-bearing Customizer settings (Code Snippets upsell).
		 *
		 * @param string $id Setting id.
		 * @return array
		 */
		public static function code_bearing_refusal( $id = '' ) {
			return array(
				'ok'      => false,
				'error'   => 'ahentic_code_bearing_setting',
				'message' => __( 'This setting is a code-injection surface (CSS/JS/HTML). Free Ahentic does not write it — use Code Snippets (Premium) or a page-scoped Custom HTML block when that fits.', 'ahentic' ),
				'id'      => (string) $id,
				'upsell'  => array(
					'product' => 'code-snippets',
					'hint'    => __( 'Site-wide or conditional CSS/JS/HTML belongs in Code Snippets (Premium).', 'ahentic' ),
				),
			);
		}

		/**
		 * Whether a global-styles partial includes code-bearing css keys.
		 *
		 * @param array $partial Input-shaped { styles?, settings? }.
		 * @return bool
		 */
		public static function global_styles_partial_has_css( array $partial ) {
			if ( isset( $partial['styles'] ) && is_array( $partial['styles'] ) && array_key_exists( 'css', $partial['styles'] ) ) {
				return true;
			}
			if ( ! isset( $partial['styles']['blocks'] ) || ! is_array( $partial['styles']['blocks'] ) ) {
				return false;
			}
			foreach ( $partial['styles']['blocks'] as $block_styles ) {
				if ( is_array( $block_styles ) && array_key_exists( 'css', $block_styles ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Strip theme.json user-layer css keys (styles.css and styles.blocks.{name}.css).
		 *
		 * @param array $partial Input-shaped { styles?, settings? }.
		 * @return array Cleaned partial (same shape).
		 */
		public static function strip_global_styles_css_keys( array $partial ) {
			$out = $partial;
			if ( isset( $out['styles'] ) && is_array( $out['styles'] ) ) {
				unset( $out['styles']['css'] );
				if ( isset( $out['styles']['blocks'] ) && is_array( $out['styles']['blocks'] ) ) {
					foreach ( $out['styles']['blocks'] as $name => $block_styles ) {
						if ( ! is_array( $block_styles ) ) {
							continue;
						}
						unset( $block_styles['css'] );
						$out['styles']['blocks'][ $name ] = $block_styles;
					}
				}
			}
			return $out;
		}

		/**
		 * Whether a cleaned partial still has styles/settings content to merge.
		 *
		 * @param array $partial Cleaned partial.
		 * @return bool
		 */
		public static function global_styles_partial_has_content( array $partial ) {
			if ( isset( $partial['styles'] ) && is_array( $partial['styles'] ) && ! empty( $partial['styles'] ) ) {
				return true;
			}
			if ( isset( $partial['settings'] ) && is_array( $partial['settings'] ) && ! empty( $partial['settings'] ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Pure resolve: strip css, deep-merge into prior user-layer JSON.
		 *
		 * @param array $partial Requested { styles?, settings? }.
		 * @param array $prior   Current user-layer raw data (may be empty).
		 * @return array{ok:bool, error?:string, message?:string, upsell?:array, prior?:array, next?:array, stripped_css?:bool}
		 */
		public static function resolve_global_styles_update( array $partial, array $prior ) {
			$has_styles   = isset( $partial['styles'] ) && is_array( $partial['styles'] );
			$has_settings = isset( $partial['settings'] ) && is_array( $partial['settings'] );

			if ( ! $has_styles && ! $has_settings ) {
				return array(
					'ok'      => false,
					'error'   => 'ahentic_missing_global_styles_partial',
					'message' => __( 'Provide a partial with styles and/or settings to merge into the theme.json user layer.', 'ahentic' ),
				);
			}

			$had_css = self::global_styles_partial_has_css( $partial );
			$clean   = self::strip_global_styles_css_keys( $partial );

			if ( ! self::global_styles_partial_has_content( $clean ) ) {
				if ( $had_css ) {
					return self::code_bearing_refusal( 'styles.css' );
				}
				return array(
					'ok'      => false,
					'error'   => 'ahentic_missing_global_styles_partial',
					'message' => __( 'Provide a partial with styles and/or settings to merge into the theme.json user layer.', 'ahentic' ),
				);
			}

			$next = is_array( $prior ) ? $prior : array();
			if ( isset( $clean['styles'] ) && is_array( $clean['styles'] ) ) {
				$prior_styles     = isset( $next['styles'] ) && is_array( $next['styles'] ) ? $next['styles'] : array();
				$next['styles']   = array_replace_recursive( $prior_styles, $clean['styles'] );
			}
			if ( isset( $clean['settings'] ) && is_array( $clean['settings'] ) ) {
				$prior_settings     = isset( $next['settings'] ) && is_array( $next['settings'] ) ? $next['settings'] : array();
				$next['settings']   = array_replace_recursive( $prior_settings, $clean['settings'] );
			}

			return array(
				'ok'           => true,
				'prior'        => is_array( $prior ) ? $prior : array(),
				'next'         => $next,
				'stripped_css' => $had_css,
			);
		}

		/**
		 * Resolve one update-theme-setting change against an index entry and prior value.
		 *
		 * Pure decision helper: unknown id, code-bearing refusal, nested replace gate,
		 * and path merge. Does not sanitize or persist.
		 *
		 * @param array      $change      { id, path?, value, replace? }.
		 * @param array|null $index_entry Index row or null when absent from registry.
		 * @param mixed      $prior       Current live value (may be null).
		 * @return array{ok:bool, error?:string, message?:string, upsell?:array, id?:string, prior?:mixed, next?:mixed, path?:string, replace?:bool}
		 */
		public static function resolve_theme_setting_change( array $change, $index_entry, $prior ) {
			$id = isset( $change['id'] ) ? trim( (string) $change['id'] ) : '';
			if ( '' === $id ) {
				return array(
					'ok'      => false,
					'error'   => 'ahentic_missing_setting_id',
					'message' => __( 'Each change requires a setting id.', 'ahentic' ),
				);
			}

			if ( null === $index_entry || ! is_array( $index_entry ) ) {
				// Still refuse code-bearing ids by id pattern even when absent from the filtered index.
				if ( self::is_code_bearing_index_entry( array( 'id' => $id ) ) ) {
					return self::code_bearing_refusal( $id );
				}
				return array(
					'ok'      => false,
					'id'      => $id,
					'error'   => 'ahentic_setting_not_found',
					'message' => __( 'Setting id is not in the Customizer index (or is excluded as code-bearing).', 'ahentic' ),
				);
			}

			if ( self::is_code_bearing_index_entry( $index_entry ) ) {
				return self::code_bearing_refusal( $id );
			}

			$path    = isset( $change['path'] ) ? trim( (string) $change['path'] ) : '';
			$replace = ! empty( $change['replace'] );
			$value   = array_key_exists( 'value', $change ) ? $change['value'] : null;

			if ( '' === $path && ! $replace && is_array( $prior ) ) {
				return array(
					'ok'      => false,
					'id'      => $id,
					'error'   => 'ahentic_theme_setting_replace_required',
					'message' => __( 'Refusing whole-object replacement of a nested theme setting. Pass path to merge into the existing structure, or set replace:true to overwrite deliberately.', 'ahentic' ),
					'hint'    => __( 'Example path: sections[0].items', 'ahentic' ),
				);
			}

			if ( '' !== $path ) {
				$next = self::merge_value_at_path( $prior, $path, $value );
			} else {
				$next = $value;
			}

			return array(
				'ok'      => true,
				'id'      => $id,
				'path'    => $path,
				'replace' => $replace,
				'prior'   => $prior,
				'next'    => $next,
				'type'    => isset( $index_entry['type'] ) ? (string) $index_entry['type'] : 'theme_mod',
			);
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
					'routing_hint'   => __( 'Active theme is a block theme. Prefer ahentic/update-global-styles for theme.json user-layer colors/typography/spacing (not template-part HTML), and template parts for header/footer markup. Classic Customizer theme_settings are not the primary surface. Call ahentic/get-settings-context before choosing a write path.', 'ahentic' ),
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

			$write_permission = static function () {
				return current_user_can( 'edit_theme_options' );
			};

			wp_register_ability(
				self::UPDATE_THEME_SETTING,
				array(
					'label'               => __( 'Update theme setting', 'ahentic' ),
					'description'         => __( 'Writes classic-theme Customizer settings (theme_mod or option-backed) through the setting’s sanitize/validate callbacks. Nested values merge by path; whole-object replace requires replace:true. Refuses code-bearing settings. Snapshot + undo via ahentic/undo-last-actions.', 'ahentic' ),
					'category'            => 'ahentic-settings',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'changes' ),
						'properties' => array(
							'changes' => array(
								'type'        => 'array',
								'description' => __( 'One or more setting changes.', 'ahentic' ),
								'minItems'    => 1,
								'items'       => array(
									'type'       => 'object',
									'required'   => array( 'id', 'value' ),
									'properties' => array(
										'id'      => array(
											'type'        => 'string',
											'description' => __( 'Customizer setting id (must exist in the cached index).', 'ahentic' ),
										),
										'path'    => array(
											'type'        => 'string',
											'description' => __( 'Optional nested path to merge into (e.g. sections[0].items).', 'ahentic' ),
										),
										'value'   => array(
											'description' => __( 'New value (or value at path).', 'ahentic' ),
										),
										'replace' => array(
											'type'        => 'boolean',
											'description' => __( 'When true, allow whole-object replacement of a nested array setting without path.', 'ahentic' ),
										),
									),
								),
							),
							'dry_run' => array(
								'type'        => 'boolean',
								'description' => __( 'When true, report the prior/next diff without writing.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_update_theme_setting' ),
					'permission_callback' => $write_permission,
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
						'show_in_rest' => false,
					),
				)
			);

			wp_register_ability(
				self::UPDATE_GLOBAL_STYLES,
				array(
					'label'               => __( 'Update global styles', 'ahentic' ),
					'description'         => __( 'Merges a partial into the active block theme’s theme.json user layer (colors, typography, spacing, settings) via WordPress global styles. Does not edit template-part HTML (header/footer markup) — that is a separate template-part ability, not this one. Strips styles.css and block-level css keys (code-bearing). Snapshot + undo via ahentic/undo-last-actions. Refuses classic themes.', 'ahentic' ),
					'category'            => 'ahentic-settings',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'styles'  => array(
								'type'        => 'object',
								'description' => __( 'Partial styles object to merge into the user layer (e.g. { color: { background: "#fff" } }). css keys are stripped.', 'ahentic' ),
							),
							'settings' => array(
								'type'        => 'object',
								'description' => __( 'Partial settings object to merge into the user layer.', 'ahentic' ),
							),
							'dry_run' => array(
								'type'        => 'boolean',
								'description' => __( 'When true, report the prior/next user-layer diff without writing.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_update_global_styles' ),
					'permission_callback' => $write_permission,
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
						'show_in_rest' => false,
					),
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
				case self::UPDATE_THEME_SETTING:
					return self::execute_update_theme_setting( $input );
				case self::UPDATE_GLOBAL_STYLES:
					return self::execute_update_global_styles( $input );
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
		 * Run $callback with a live WP_Customize_Manager after customize_register.
		 *
		 * @param callable $callback function( \WP_Customize_Manager $wp_customize ): mixed
		 * @return mixed|\WP_Error
		 */
		public static function with_customize_manager( $callback ) {
			if ( ! is_callable( $callback ) ) {
				return new WP_Error(
					'ahentic_customize_unavailable',
					__( 'Customizer callback is not callable.', 'ahentic' )
				);
			}

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
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required Customizer bootstrap (site-settings PRD).
				$GLOBALS['wp_customize'] = $wp_customize;
				do_action( 'customize_register', $wp_customize );
				return call_user_func( $callback, $wp_customize );
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
		 * Instantiate WP_Customize_Manager, fire customize_register, harvest index.
		 *
		 * @return array|\WP_Error
		 */
		public static function bootstrap_and_harvest_index() {
			return self::with_customize_manager(
				static function ( $wp_customize ) {
					return Ahentic_Abilities_Settings::harvest_index_from_manager( $wp_customize );
				}
			);
		}

		/**
		 * Whether a live Customizer setting is a code-injection surface.
		 *
		 * @param \WP_Customize_Manager $wp_customize Manager.
		 * @param string                $id          Setting id.
		 * @return bool
		 */
		public static function is_code_bearing_live_setting( $wp_customize, $id ) {
			$id = (string) $id;
			if ( self::is_code_bearing_index_entry( array( 'id' => $id ) ) ) {
				return true;
			}
			if ( ! is_object( $wp_customize ) || ! method_exists( $wp_customize, 'get_setting' ) ) {
				return false;
			}

			$setting = $wp_customize->get_setting( $id );
			if ( ! is_object( $setting ) ) {
				return false;
			}

			$capability = isset( $setting->capability ) ? (string) $setting->capability : '';
			if ( in_array( $capability, array( 'edit_css', 'unfiltered_html' ), true ) ) {
				return true;
			}

			if ( ! method_exists( $wp_customize, 'controls' ) ) {
				return false;
			}

			foreach ( $wp_customize->controls() as $control ) {
				if ( ! is_object( $control ) ) {
					continue;
				}
				$linked = false;
				if ( isset( $control->settings ) && is_array( $control->settings ) ) {
					foreach ( $control->settings as $setting_obj ) {
						$sid = '';
						if ( is_object( $setting_obj ) && isset( $setting_obj->id ) ) {
							$sid = (string) $setting_obj->id;
						} elseif ( is_string( $setting_obj ) ) {
							$sid = $setting_obj;
						}
						if ( $sid === $id ) {
							$linked = true;
							break;
						}
					}
				}
				if ( ! $linked && isset( $control->setting ) && is_object( $control->setting ) && isset( $control->setting->id ) ) {
					$linked = ( (string) $control->setting->id === $id );
				}
				if ( ! $linked ) {
					continue;
				}

				$class_name   = get_class( $control );
				$control_type = isset( $control->type ) ? (string) $control->type : '';
				$control_cap  = isset( $control->capability ) ? (string) $control->capability : '';
				$entry        = array(
					'id'           => $id,
					'control_type' => ( false !== stripos( $class_name, 'Code_Editor' ) ) ? $class_name : $control_type,
					'capability'   => $control_cap ? $control_cap : $capability,
				);
				if ( self::is_code_bearing_index_entry( $entry ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Sanitize (and optionally validate + save) a value through a live Customizer setting.
		 *
		 * @param \WP_Customize_Manager $wp_customize Manager.
		 * @param string                $id          Setting id.
		 * @param mixed                 $value       Value to apply.
		 * @param bool                  $save        When true, persist via Setting::save().
		 * @return array|\WP_Error { ok, id, value } on success.
		 */
		public static function apply_theme_setting_via_customize( $wp_customize, $id, $value, $save = true ) {
			$id = (string) $id;
			if ( ! is_object( $wp_customize ) || ! method_exists( $wp_customize, 'get_setting' ) ) {
				return new WP_Error(
					'ahentic_customize_unavailable',
					__( 'WP_Customize_Manager is not available.', 'ahentic' )
				);
			}

			if ( self::is_code_bearing_live_setting( $wp_customize, $id ) ) {
				$refusal = self::code_bearing_refusal( $id );
				return new WP_Error(
					$refusal['error'],
					$refusal['message'],
					array(
						'upsell' => $refusal['upsell'],
						'id'     => $id,
					)
				);
			}

			$setting = $wp_customize->get_setting( $id );
			if ( ! is_object( $setting ) ) {
				return new WP_Error(
					'ahentic_setting_not_found',
					__( 'Setting id is not registered on the live Customizer.', 'ahentic' ),
					array( 'id' => $id )
				);
			}

			if ( method_exists( $setting, 'validate' ) ) {
				$validity = $setting->validate( $value );
				if ( is_wp_error( $validity ) && $validity->has_errors() ) {
					return $validity;
				}
			}

			if ( ! method_exists( $setting, 'sanitize' ) ) {
				return new WP_Error(
					'ahentic_theme_setting_sanitize_unavailable',
					__( 'Customizer setting has no sanitize() method.', 'ahentic' ),
					array( 'id' => $id )
				);
			}

			$sanitized = $setting->sanitize( $value );
			if ( is_null( $sanitized ) || is_wp_error( $sanitized ) ) {
				if ( is_wp_error( $sanitized ) ) {
					return $sanitized;
				}
				return new WP_Error(
					'ahentic_theme_setting_sanitize_failed',
					__( 'Theme setting sanitize_callback rejected the value.', 'ahentic' ),
					array( 'id' => $id )
				);
			}

			if ( ! $save ) {
				return array(
					'ok'    => true,
					'id'    => $id,
					'value' => $sanitized,
				);
			}

			if ( method_exists( $wp_customize, 'set_post_value' ) ) {
				$wp_customize->set_post_value( $id, $value );
			}

			if ( ! method_exists( $setting, 'save' ) ) {
				return new WP_Error(
					'ahentic_theme_setting_save_unavailable',
					__( 'Customizer setting has no save() method.', 'ahentic' ),
					array( 'id' => $id )
				);
			}

			$saved = $setting->save();
			if ( false === $saved ) {
				return new WP_Error(
					'ahentic_theme_setting_save_failed',
					__( 'Could not save the theme setting (capability check failed or value missing).', 'ahentic' ),
					array( 'id' => $id )
				);
			}

			return array(
				'ok'    => true,
				'id'    => $id,
				'value' => $sanitized,
			);
		}

		/**
		 * Execute update-theme-setting.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_update_theme_setting( $input = array() ) {
			$input   = is_array( $input ) ? $input : array();
			$dry_run = ! empty( $input['dry_run'] );
			$changes = isset( $input['changes'] ) && is_array( $input['changes'] ) ? $input['changes'] : array();

			if ( empty( $changes ) ) {
				return new WP_Error(
					'ahentic_missing_theme_setting_changes',
					__( 'Provide at least one change with id and value.', 'ahentic' )
				);
			}

			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new WP_Error(
					'ahentic_theme_setting_forbidden',
					__( 'You need edit_theme_options to update theme settings.', 'ahentic' )
				);
			}

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

			$resolved     = array();
			$failures     = array();
			$working      = array(); // id => last resolved next (chains multi-path edits).
			$existed_at   = array(); // id => prior_existed at first touch (original store state).
			$original_pri = array(); // id => original prior before any change in this batch.

			foreach ( $changes as $i => $change ) {
				if ( ! is_array( $change ) ) {
					$failures[] = array(
						'ok'      => false,
						'error'   => 'ahentic_invalid_change',
						'message' => __( 'Each change must be an object.', 'ahentic' ),
						'index'   => (int) $i,
					);
					continue;
				}

				$id    = isset( $change['id'] ) ? trim( (string) $change['id'] ) : '';
				$entry = ( '' !== $id && isset( $by_id[ $id ] ) ) ? $by_id[ $id ] : null;
				$prior = null;
				if ( is_array( $entry ) ) {
					if ( ! array_key_exists( $id, $working ) ) {
						$original_pri[ $id ] = self::read_live_value( $entry );
						$existed_at[ $id ]   = self::setting_value_exists( $entry );
						$working[ $id ]      = $original_pri[ $id ];
					}
					$prior = $working[ $id ];
				}

				$result = self::resolve_theme_setting_change( $change, $entry, $prior );
				if ( empty( $result['ok'] ) ) {
					$result['index'] = (int) $i;
					$failures[]      = $result;
					continue;
				}
				$result['index']         = (int) $i;
				$result['prior_existed'] = ! empty( $existed_at[ $id ] );
				// Snapshot always restores the pre-batch original, not an intermediate.
				$result['snapshot_prior'] = $original_pri[ $id ];
				$working[ $id ]           = $result['next'];
				$resolved[]               = $result;
			}

			if ( ! empty( $failures ) ) {
				$first = $failures[0];
				$code  = isset( $first['error'] ) ? (string) $first['error'] : 'ahentic_theme_setting_change_failed';
				$msg   = isset( $first['message'] ) ? (string) $first['message'] : __( 'Theme setting change failed.', 'ahentic' );
				$data  = array(
					'failures' => $failures,
					'status'   => 400,
				);
				if ( isset( $first['upsell'] ) ) {
					$data['upsell'] = $first['upsell'];
				}
				if ( isset( $first['hint'] ) ) {
					$data['hint'] = $first['hint'];
				}
				return new WP_Error( $code, $msg, $data );
			}

			// One write (and one snapshot) per setting id — final chained next.
			$by_final = array();
			foreach ( $resolved as $item ) {
				$by_final[ $item['id'] ] = $item;
			}
			$resolved = array_values( $by_final );

			$applied = self::with_customize_manager(
				static function ( $wp_customize ) use ( $resolved, $dry_run ) {
					$session_id = 0;
					if ( ! $dry_run && class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'current_session_id' ) ) {
						$session_id = (int) Ahentic_Orchestrator::current_session_id();
					}

					$out = array();
					foreach ( $resolved as $item ) {
						$id   = $item['id'];
						$next = $item['next'];

						if ( Ahentic_Abilities_Settings::is_code_bearing_live_setting( $wp_customize, $id ) ) {
							return Ahentic_Abilities_Settings::code_bearing_refusal( $id );
						}

						$prior_existed = ! empty( $item['prior_existed'] );
						$snap_prior    = array_key_exists( 'snapshot_prior', $item ) ? $item['snapshot_prior'] : $item['prior'];
						$type          = isset( $item['type'] ) ? (string) $item['type'] : 'theme_mod';

						if ( ! $dry_run && $session_id && class_exists( 'Ahentic_Settings_Snapshots' ) ) {
							$raw = array(
								'ability'       => Ahentic_Abilities_Settings::UPDATE_THEME_SETTING,
								'target'        => array(
									'id'   => $id,
									'type' => $type,
								),
								'prior_existed' => $prior_existed,
							);
							if ( $prior_existed ) {
								$raw['prior_value'] = array(
									'value' => $snap_prior,
								);
							}
							Ahentic_Settings_Snapshots::record( $session_id, $raw );
						}

						$write = Ahentic_Abilities_Settings::apply_theme_setting_via_customize(
							$wp_customize,
							$id,
							$next,
							! $dry_run
						);
						if ( is_wp_error( $write ) ) {
							return $write;
						}

						$out[] = array(
							'ok'            => true,
							'id'            => $id,
							'path'          => isset( $item['path'] ) ? $item['path'] : '',
							'replace'       => ! empty( $item['replace'] ),
							'prior'         => $snap_prior,
							'prior_existed' => $prior_existed,
							'next'          => isset( $write['value'] ) ? $write['value'] : $next,
							'dry_run'       => $dry_run,
						);
					}
					return $out;
				}
			);

			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			if ( is_array( $applied ) && isset( $applied['ok'] ) && false === $applied['ok'] && isset( $applied['error'] ) ) {
				// code_bearing_refusal array bubbled from inside the manager callback.
				return new WP_Error(
					(string) $applied['error'],
					isset( $applied['message'] ) ? (string) $applied['message'] : '',
					array(
						'upsell' => isset( $applied['upsell'] ) ? $applied['upsell'] : null,
						'id'     => isset( $applied['id'] ) ? $applied['id'] : '',
					)
				);
			}

			return array(
				'ok'       => true,
				'dry_run'  => $dry_run,
				'count'    => count( $applied ),
				'changes'  => $applied,
				'surface'  => 'theme_settings',
			);
		}

		/**
		 * Execute update-global-styles (block themes only).
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_update_global_styles( $input = array() ) {
			$input   = is_array( $input ) ? $input : array();
			$dry_run = ! empty( $input['dry_run'] );

			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new WP_Error(
					'ahentic_global_styles_forbidden',
					__( 'You need edit_theme_options to update global styles.', 'ahentic' )
				);
			}

			$theme    = wp_get_theme();
			$is_block = class_exists( 'Ahentic_Abilities_Site' )
				? Ahentic_Abilities_Site::theme_is_block_theme( $theme )
				: ( method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme() );

			if ( ! $is_block ) {
				return new WP_Error(
					'ahentic_not_block_theme',
					__( 'ahentic/update-global-styles only applies to block themes. Active theme is classic — use ahentic/update-theme-setting for Customizer settings, or switch to a block theme.', 'ahentic' ),
					array(
						'hint'   => __( 'Call ahentic/get-settings-context first to confirm surfaces.', 'ahentic' ),
						'status' => 400,
					)
				);
			}

			if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
				return new WP_Error(
					'ahentic_global_styles_unavailable',
					__( 'WordPress global styles APIs are not available on this site.', 'ahentic' )
				);
			}

			$user_cpt      = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( $theme, false );
			$prior_existed = ! empty( $user_cpt['ID'] );
			$prior_post_id = $prior_existed ? (int) $user_cpt['ID'] : 0;

			$user_json = WP_Theme_JSON_Resolver::get_user_data();
			$prior     = ( is_object( $user_json ) && method_exists( $user_json, 'get_raw_data' ) )
				? $user_json->get_raw_data()
				: array();
			if ( ! is_array( $prior ) ) {
				$prior = array();
			}

			$partial = array();
			if ( isset( $input['styles'] ) && is_array( $input['styles'] ) ) {
				$partial['styles'] = $input['styles'];
			}
			if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
				$partial['settings'] = $input['settings'];
			}

			$resolved = self::resolve_global_styles_update( $partial, $prior );
			if ( empty( $resolved['ok'] ) ) {
				$code = isset( $resolved['error'] ) ? (string) $resolved['error'] : 'ahentic_global_styles_failed';
				$msg  = isset( $resolved['message'] ) ? (string) $resolved['message'] : __( 'Global styles update failed.', 'ahentic' );
				$data = array( 'status' => 400 );
				if ( isset( $resolved['upsell'] ) ) {
					$data['upsell'] = $resolved['upsell'];
				}
				if ( isset( $resolved['id'] ) ) {
					$data['id'] = $resolved['id'];
				}
				return new WP_Error( $code, $msg, $data );
			}

			$next = $resolved['next'];

			// Let WP_Theme_JSON own schema sanitization when available.
			if ( class_exists( 'WP_Theme_JSON' ) ) {
				$theme_json = new WP_Theme_JSON( $next, 'custom' );
				$next       = $theme_json->get_raw_data();
			}

			$stylesheet = get_stylesheet();
			$response   = array(
				'ok'            => true,
				'dry_run'       => $dry_run,
				'surface'       => 'global_styles',
				'stylesheet'    => $stylesheet,
				'prior_existed' => $prior_existed,
				'prior'         => array(
					'styles'   => isset( $prior['styles'] ) ? $prior['styles'] : new stdClass(),
					'settings' => isset( $prior['settings'] ) ? $prior['settings'] : new stdClass(),
				),
				'next'          => array(
					'styles'   => isset( $next['styles'] ) ? $next['styles'] : new stdClass(),
					'settings' => isset( $next['settings'] ) ? $next['settings'] : new stdClass(),
				),
				'stripped_css'  => ! empty( $resolved['stripped_css'] ),
			);

			if ( $dry_run ) {
				return $response;
			}

			$session_id = 0;
			if ( class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'current_session_id' ) ) {
				$session_id = (int) Ahentic_Orchestrator::current_session_id();
			}

			if ( $session_id && class_exists( 'Ahentic_Settings_Snapshots' ) ) {
				$raw = array(
					'ability'       => self::UPDATE_GLOBAL_STYLES,
					'target'        => array(
						'stylesheet' => $stylesheet,
						'post_id'    => $prior_post_id,
					),
					'prior_existed' => $prior_existed,
				);
				if ( $prior_existed ) {
					$raw['prior_value'] = array(
						'styles'   => isset( $prior['styles'] ) && is_array( $prior['styles'] ) ? $prior['styles'] : array(),
						'settings' => isset( $prior['settings'] ) && is_array( $prior['settings'] ) ? $prior['settings'] : array(),
					);
				}
				Ahentic_Settings_Snapshots::record( $session_id, $raw );
			}

			$persisted = self::persist_user_global_styles(
				isset( $next['styles'] ) && is_array( $next['styles'] ) ? $next['styles'] : array(),
				isset( $next['settings'] ) && is_array( $next['settings'] ) ? $next['settings'] : array()
			);
			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			$response['post_id'] = isset( $persisted['post_id'] ) ? (int) $persisted['post_id'] : 0;
			return $response;
		}

		/**
		 * Persist merged user-layer styles/settings via the global-styles REST controller.
		 *
		 * @param array $styles   Full styles object to write.
		 * @param array $settings Full settings object to write.
		 * @return array{post_id:int}|\WP_Error
		 */
		public static function persist_user_global_styles( array $styles, array $settings ) {
			if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! class_exists( 'WP_REST_Global_Styles_Controller' ) || ! class_exists( 'WP_REST_Request' ) ) {
				return new WP_Error(
					'ahentic_global_styles_unavailable',
					__( 'WordPress global styles APIs are not available on this site.', 'ahentic' )
				);
			}

			$user_cpt = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
			if ( empty( $user_cpt['ID'] ) ) {
				return new WP_Error(
					'ahentic_global_styles_create_failed',
					__( 'Could not create or load the wp_global_styles user layer post.', 'ahentic' )
				);
			}

			$id      = (int) $user_cpt['ID'];
			$request = new WP_REST_Request( 'POST', '/wp/v2/global-styles/' . $id );
			$request->set_param( 'id', $id );
			$request->set_param( 'styles', $styles );
			$request->set_param( 'settings', $settings );

			$controller = new WP_REST_Global_Styles_Controller();
			$perm       = $controller->update_item_permissions_check( $request );
			if ( is_wp_error( $perm ) ) {
				return $perm;
			}

			$result = $controller->update_item( $request );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( method_exists( 'WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
				WP_Theme_JSON_Resolver::clean_cached_data();
			}

			return array( 'post_id' => $id );
		}

		/**
		 * Wire snapshot restore for theme setting + global styles writes.
		 */
		public static function boot_restore() {
			if ( ! class_exists( 'Ahentic_Settings_Snapshots' ) ) {
				return;
			}
			Ahentic_Settings_Snapshots::register_restore(
				self::UPDATE_THEME_SETTING,
				array( __CLASS__, 'restore_update_theme_setting' )
			);
			Ahentic_Settings_Snapshots::register_restore(
				self::UPDATE_GLOBAL_STYLES,
				array( __CLASS__, 'restore_update_global_styles' )
			);
		}

		/**
		 * Restore prior global styles user layer (or delete override if none existed).
		 *
		 * @param array $entry Snapshot entry.
		 * @return true|\WP_Error
		 */
		public static function restore_update_global_styles( array $entry ) {
			$target     = isset( $entry['target'] ) && is_array( $entry['target'] ) ? $entry['target'] : array();
			$stylesheet = isset( $target['stylesheet'] ) ? (string) $target['stylesheet'] : get_stylesheet();

			if ( $stylesheet && function_exists( 'get_stylesheet' ) && get_stylesheet() !== $stylesheet ) {
				return new WP_Error(
					'ahentic_undo_global_styles_theme_mismatch',
					__( 'Cannot undo global styles: snapshot is for a different stylesheet than the active theme.', 'ahentic' )
				);
			}

			if ( empty( $entry['prior_existed'] ) ) {
				return self::delete_user_global_styles_post( $stylesheet );
			}

			$prior_pack = array_key_exists( 'prior_value', $entry ) && is_array( $entry['prior_value'] )
				? $entry['prior_value']
				: array();
			$styles     = isset( $prior_pack['styles'] ) && is_array( $prior_pack['styles'] ) ? $prior_pack['styles'] : array();
			$settings   = isset( $prior_pack['settings'] ) && is_array( $prior_pack['settings'] ) ? $prior_pack['settings'] : array();

			$persisted = self::persist_user_global_styles( $styles, $settings );
			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}
			return true;
		}

		/**
		 * Delete the wp_global_styles CPT for a stylesheet (first-write undo).
		 *
		 * @param string $stylesheet Theme stylesheet.
		 * @return true|\WP_Error
		 */
		public static function delete_user_global_styles_post( $stylesheet = '' ) {
			if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
				return new WP_Error(
					'ahentic_global_styles_unavailable',
					__( 'WordPress global styles APIs are not available on this site.', 'ahentic' )
				);
			}

			$theme = '' !== (string) $stylesheet ? wp_get_theme( $stylesheet ) : wp_get_theme();
			$cpt   = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( $theme, false );
			if ( empty( $cpt['ID'] ) ) {
				return true;
			}

			$deleted = wp_delete_post( (int) $cpt['ID'], true );
			if ( ! $deleted ) {
				return new WP_Error(
					'ahentic_undo_global_styles_delete_failed',
					__( 'Could not delete the global styles override on undo.', 'ahentic' )
				);
			}

			if ( method_exists( 'WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
				WP_Theme_JSON_Resolver::clean_cached_data();
			}
			return true;
		}

		/**
		 * Restore a prior theme setting value through Customizer sanitize/save.
		 *
		 * @param array $entry Snapshot entry.
		 * @return true|\WP_Error
		 */
		public static function restore_update_theme_setting( array $entry ) {
			$target = isset( $entry['target'] ) ? $entry['target'] : null;
			$id     = '';
			$type   = 'theme_mod';
			if ( is_array( $target ) ) {
				$id   = isset( $target['id'] ) ? (string) $target['id'] : '';
				$type = isset( $target['type'] ) ? (string) $target['type'] : 'theme_mod';
			} elseif ( is_string( $target ) || is_numeric( $target ) ) {
				$id = (string) $target;
			}

			if ( '' === $id ) {
				return new WP_Error(
					'ahentic_undo_theme_setting_missing_target',
					__( 'Cannot undo theme setting: missing setting id.', 'ahentic' )
				);
			}

			if ( empty( $entry['prior_existed'] ) ) {
				return self::clear_theme_setting_value( $id, $type );
			}

			$prior_pack = array_key_exists( 'prior_value', $entry ) ? $entry['prior_value'] : null;
			$value      = null;
			if ( is_array( $prior_pack ) && array_key_exists( 'value', $prior_pack ) ) {
				$value = $prior_pack['value'];
			} else {
				$value = $prior_pack;
			}

			$result = self::with_customize_manager(
				static function ( $wp_customize ) use ( $id, $value ) {
					return Ahentic_Abilities_Settings::apply_theme_setting_via_customize(
						$wp_customize,
						$id,
						$value,
						true
					);
				}
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return true;
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
		 * Whether a setting's stored value exists (distinct from default / empty).
		 *
		 * Used for ADR-0007 snapshots so undo can remove a first-time write.
		 *
		 * @param array $entry Index entry with id + type.
		 * @return bool
		 */
		public static function setting_value_exists( array $entry ) {
			$id   = isset( $entry['id'] ) ? (string) $entry['id'] : '';
			$type = isset( $entry['type'] ) ? (string) $entry['type'] : 'theme_mod';
			if ( '' === $id ) {
				return false;
			}

			$parsed = self::parse_setting_id( $id );
			$base   = $parsed['base'];
			$keys   = $parsed['keys'];

			if ( 'option' === $type ) {
				if ( ! function_exists( 'get_option' ) ) {
					return false;
				}
				$sentinel = new stdClass();
				$root     = get_option( $base, $sentinel );
				if ( $sentinel === $root ) {
					return false;
				}
				if ( empty( $keys ) ) {
					return true;
				}
				$cursor = $root;
				foreach ( $keys as $key ) {
					if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
						return false;
					}
					$cursor = $cursor[ $key ];
				}
				return true;
			}

			if ( ! function_exists( 'get_theme_mods' ) ) {
				return false;
			}
			$mods = get_theme_mods();
			if ( ! is_array( $mods ) || ! array_key_exists( $base, $mods ) ) {
				return false;
			}
			if ( empty( $keys ) ) {
				return true;
			}
			$cursor = $mods[ $base ];
			foreach ( $keys as $key ) {
				if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
					return false;
				}
				$cursor = $cursor[ $key ];
			}
			return true;
		}

		/**
		 * Clear a Customizer-backed value (undo when prior_existed was false).
		 *
		 * @param string $id   Setting id.
		 * @param string $type theme_mod|option.
		 * @return true|\WP_Error
		 */
		public static function clear_theme_setting_value( $id, $type = 'theme_mod' ) {
			$id   = (string) $id;
			$type = (string) $type;
			$parsed = self::parse_setting_id( $id );
			$base   = $parsed['base'];
			$keys   = $parsed['keys'];

			if ( 'option' === $type ) {
				if ( empty( $keys ) ) {
					delete_option( $base );
					return true;
				}
				$root = get_option( $base, null );
				if ( ! is_array( $root ) ) {
					return true;
				}
				$cursor = &$root;
				$last   = count( $keys ) - 1;
				for ( $i = 0; $i < $last; $i++ ) {
					$key = $keys[ $i ];
					if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
						return true;
					}
					$cursor = &$cursor[ $key ];
				}
				unset( $cursor[ $keys[ $last ] ] );
				unset( $cursor );
				update_option( $base, $root );
				return true;
			}

			if ( empty( $keys ) ) {
				remove_theme_mod( $base );
				return true;
			}

			$mods = get_theme_mods();
			if ( ! is_array( $mods ) || ! array_key_exists( $base, $mods ) ) {
				return true;
			}
			$root = $mods[ $base ];
			if ( ! is_array( $root ) ) {
				remove_theme_mod( $base );
				return true;
			}
			$cursor = &$root;
			$last   = count( $keys ) - 1;
			for ( $i = 0; $i < $last; $i++ ) {
				$key = $keys[ $i ];
				if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
					return true;
				}
				$cursor = &$cursor[ $key ];
			}
			unset( $cursor[ $keys[ $last ] ] );
			unset( $cursor );
			set_theme_mod( $base, $root );
			return true;
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
		 * Parse a nested value path into successive keys (dot + bracket notation).
		 *
		 * Examples: "sections[0].items" → ["sections", "0", "items"]; "" → [].
		 *
		 * @param string $path Path.
		 * @return string[]
		 */
		public static function parse_value_path( $path ) {
			$path = trim( (string) $path );
			if ( '' === $path ) {
				return array();
			}

			$keys = array();
			// Split on dots that are not inside brackets.
			$parts = preg_split( '/\.(?![^\[]*\])/', $path );
			if ( ! is_array( $parts ) ) {
				return array();
			}
			foreach ( $parts as $part ) {
				$part = (string) $part;
				if ( '' === $part ) {
					continue;
				}
				if ( preg_match( '/^([^\[]+)((?:\[[^\]]*\])+)$/', $part, $m ) ) {
					$keys[] = $m[1];
					if ( preg_match_all( '/\[([^\]]*)\]/', $m[2], $km ) ) {
						foreach ( $km[1] as $bracket ) {
							$keys[] = (string) $bracket;
						}
					}
					continue;
				}
				if ( preg_match( '/^\[([^\]]*)\]$/', $part, $m ) ) {
					$keys[] = (string) $m[1];
					continue;
				}
				$keys[] = $part;
			}
			return $keys;
		}

		/**
		 * Set $value at $path within $root without clobbering sibling keys.
		 *
		 * Empty path replaces the entire root. Intermediate missing containers
		 * become arrays. Numeric-looking path segments are kept as string keys
		 * unless the parent is already a list (so PHP array indices stay ints).
		 *
		 * @param mixed  $root  Existing value.
		 * @param string $path  Dot/bracket path (e.g. sections[0].items).
		 * @param mixed  $value New value at that path.
		 * @return mixed
		 */
		public static function merge_value_at_path( $root, $path, $value ) {
			$keys = self::parse_value_path( $path );
			if ( empty( $keys ) ) {
				return $value;
			}

			$out = is_array( $root ) ? $root : array();
			$cursor = &$out;
			$last   = count( $keys ) - 1;

			for ( $i = 0; $i < $last; $i++ ) {
				$key = self::normalize_path_key( $keys[ $i ], $cursor );
				if ( ! is_array( $cursor ) ) {
					$cursor = array();
				}
				if ( ! array_key_exists( $key, $cursor ) || ! is_array( $cursor[ $key ] ) ) {
					$cursor[ $key ] = array();
				}
				$cursor = &$cursor[ $key ];
			}

			if ( ! is_array( $cursor ) ) {
				$cursor = array();
			}
			$final_key            = self::normalize_path_key( $keys[ $last ], $cursor );
			$cursor[ $final_key ] = $value;
			unset( $cursor );

			return $out;
		}

		/**
		 * Coerce a path segment to int when the parent looks like a list and the segment is numeric.
		 *
		 * @param string $segment Path segment.
		 * @param mixed  $parent  Parent value.
		 * @return int|string
		 */
		private static function normalize_path_key( $segment, $parent ) {
			$segment = (string) $segment;
			if ( is_array( $parent ) && ctype_digit( $segment ) ) {
				return (int) $segment;
			}
			return $segment;
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
	add_action( 'plugins_loaded', array( 'Ahentic_Abilities_Settings', 'boot_restore' ), 20 );
	Ahentic_Abilities_Settings::register_cache_hooks();
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Settings' );
}
