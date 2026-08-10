<?php
/**
 * Ahentic WordPress Abilities facade + module catalog.
 *
 * Ability groups self-register via register_module() and self-hook WP registration.
 * Public methods (available_for_agent, requires_hitl, execute, …) stay the orchestrator seam.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities' ) ) {
	/**
	 * Agent-facing ability catalog facade.
	 */
	class Ahentic_Abilities {
		/**
		 * BC alias — same string as Ahentic_Abilities_Snapshot::SNAPSHOT.
		 */
		const SNAPSHOT = 'ahentic/get-site-snapshot';

		/**
		 * Registered ability group class names.
		 *
		 * @var string[]
		 */
		private static $modules = array();

		/**
		 * Register an ability group module (idempotent).
		 *
		 * @param string $class Class name implementing the group convention.
		 */
		public static function register_module( $class ) {
			$class = (string) $class;
			if ( '' === $class || ! class_exists( $class ) ) {
				return;
			}
			if ( in_array( $class, self::$modules, true ) ) {
				return;
			}
			self::$modules[] = $class;
		}

		/**
		 * Clear the module list (PHPUnit only).
		 */
		public static function reset_modules_for_tests() {
			self::$modules = array();
		}

		/**
		 * Registered module class names (PHPUnit / debugging).
		 *
		 * @return string[]
		 */
		public static function modules() {
			return self::$modules;
		}

		/**
		 * Bootstrap — modules self-hook WP; kept for call-site compatibility.
		 */
		public static function init() {
			// Intentionally empty: each module registers WP hooks at load time.
		}

		/**
		 * Names of abilities Ahentic can run in the agent loop today.
		 *
		 * @return string[]
		 */
		public static function available_for_agent() {
			$names = array();
			foreach ( self::$modules as $class ) {
				if ( ! method_exists( $class, 'names' ) ) {
					continue;
				}
				$names = array_merge( $names, $class::names() );
			}
			return $names;
		}

		/**
		 * Whether an ability must run in the browser (sidebar), not PHP.
		 *
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_browser( $name ) {
			$name = (string) $name;
			foreach ( self::$modules as $class ) {
				if ( method_exists( $class, 'is_browser' ) && $class::is_browser( $name ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether this tool call must pause for browser execution.
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Tool input.
		 * @return bool
		 */
		public static function requires_browser_runtime( $name, $input = array() ) {
			$name  = (string) $name;
			$input = is_array( $input ) ? $input : array();

			if ( self::is_browser( $name ) ) {
				return true;
			}

			foreach ( self::$modules as $class ) {
				if ( method_exists( $class, 'requires_browser_runtime' ) && $class::requires_browser_runtime( $name, $input ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Short pending-tool summary for browser pauses.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function browser_summary( $name, $input = array() ) {
			$name  = (string) $name;
			$input = is_array( $input ) ? $input : array();

			foreach ( self::$modules as $class ) {
				if ( ! method_exists( $class, 'browser_summary' ) ) {
					continue;
				}
				$summary = $class::browser_summary( $name, $input );
				if ( is_string( $summary ) && '' !== $summary ) {
					return $summary;
				}
			}

			return $name;
		}

		/**
		 * Whether an ability is annotated as read-only (lookups / searches, no site mutation).
		 *
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_readonly( $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				return false;
			}

			if ( function_exists( 'wp_get_ability' ) ) {
				$ability = wp_get_ability( $name );
				if ( $ability && is_object( $ability ) ) {
					$meta = null;
					if ( method_exists( $ability, 'get_meta' ) ) {
						$meta = $ability->get_meta();
					} elseif ( method_exists( $ability, 'get' ) ) {
						$meta = $ability->get( 'meta' );
					}
					if ( is_array( $meta ) && isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) && array_key_exists( 'readonly', $meta['annotations'] ) ) {
						return (bool) $meta['annotations']['readonly'];
					}
				}
			}

			// Fallback when Abilities API / meta is unavailable.
			foreach ( self::$modules as $class ) {
				$owns = false;
				if ( method_exists( $class, 'names' ) && in_array( $name, $class::names(), true ) ) {
					$owns = true;
				} elseif ( method_exists( $class, 'is_browser' ) && $class::is_browser( $name ) ) {
					$owns = true;
				} elseif ( method_exists( $class, 'is_artifact_ability' ) && $class::is_artifact_ability( $name ) ) {
					$owns = true;
				}
				if ( ! $owns ) {
					continue;
				}
				if ( method_exists( $class, 'is_readonly' ) ) {
					return (bool) $class::is_readonly( $name );
				}
				if ( method_exists( $class, 'requires_hitl' ) && $class::requires_hitl( $name ) ) {
					return false;
				}
				return true;
			}

			return false;
		}

		/**
		 * Abilities the orchestrator may run for a composer mode.
		 * Ask mode is limited to readonly abilities.
		 *
		 * @param string $mode agent|ask.
		 * @return string[]
		 */
		public static function available_for_mode( $mode ) {
			$names = self::available_for_agent();
			if ( 'ask' !== $mode ) {
				return $names;
			}
			return array_values(
				array_filter(
					$names,
					static function ( $name ) {
						return self::is_readonly( $name );
					}
				)
			);
		}

		/**
		 * Whether an ability must pause for HITL before execution.
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Optional tool input (fill-fields is input-aware).
		 * @return bool
		 */
		public static function requires_hitl( $name, $input = array() ) {
			$name  = (string) $name;
			$input = is_array( $input ) ? $input : array();

			// fill-fields: facade special-case so other modules keep a 1-arg requires_hitl().
			if ( class_exists( 'Ahentic_Abilities_Browser' )
				&& Ahentic_Abilities_Browser::FILL_FIELDS === $name ) {
				return Ahentic_Abilities_Browser::fill_fields_input_requires_hitl( $input );
			}

			foreach ( self::$modules as $class ) {
				if ( method_exists( $class, 'requires_hitl' ) && $class::requires_hitl( $name ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether an ability must always get a fresh Allow/Deny (never honor
		 * session / always-allow lists).
		 *
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_non_preallowable( $name ) {
			$name = (string) $name;
			foreach ( self::$modules as $class ) {
				if ( method_exists( $class, 'is_non_preallowable' ) && $class::is_non_preallowable( $name ) ) {
					return true;
				}
				if ( method_exists( $class, 'non_preallowable_names' )
					&& in_array( $name, $class::non_preallowable_names(), true ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether an HITL decision choice is allowed for this ability.
		 *
		 * Non-preallowable abilities may only use allow_once (or deny — handled elsewhere).
		 *
		 * @param string $name   Ability name.
		 * @param string $choice allow_once|allow_session|always_allow.
		 * @return bool
		 */
		public static function hitl_choice_allowed( $name, $choice ) {
			$choice = (string) $choice;
			if ( in_array( $choice, array( 'allow_session', 'always_allow' ), true )
				&& self::is_non_preallowable( $name ) ) {
				return false;
			}
			return true;
		}

		/**
		 * Human-readable summary for HITL approval UI.
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Input args.
		 * @return string
		 */
		public static function hitl_summary( $name, $input = array() ) {
			$name  = (string) $name;
			$input = is_array( $input ) ? $input : array();

			if ( ! self::requires_hitl( $name, $input ) ) {
				return $name;
			}

			foreach ( self::$modules as $class ) {
				$owns = false;
				if ( method_exists( $class, 'names' ) && in_array( $name, $class::names(), true ) ) {
					$owns = true;
				} elseif ( method_exists( $class, 'is_browser' ) && $class::is_browser( $name ) ) {
					$owns = true;
				}
				if ( ! $owns ) {
					continue;
				}
				if ( method_exists( $class, 'hitl_summary' ) ) {
					return (string) $class::hitl_summary( $name, $input );
				}
			}
			return $name;
		}

		/**
		 * Cheap identity/required-field check before pausing for HITL.
		 *
		 * Incomplete mutating calls return WP_Error to the model instead of an
		 * Allow card with an unspecified target.
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Input args.
		 * @return true|\WP_Error
		 */
		public static function hitl_preflight( $name, $input = array() ) {
			$name  = (string) $name;
			$input = is_array( $input ) ? $input : array();

			if ( ! self::requires_hitl( $name, $input ) ) {
				return true;
			}

			foreach ( self::$modules as $class ) {
				$owns = false;
				if ( method_exists( $class, 'names' ) && in_array( $name, $class::names(), true ) ) {
					$owns = true;
				} elseif ( method_exists( $class, 'is_browser' ) && $class::is_browser( $name ) ) {
					$owns = true;
				}
				if ( ! $owns ) {
					continue;
				}
				if ( method_exists( $class, 'hitl_preflight' ) ) {
					$result = $class::hitl_preflight( $name, $input );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				return true;
			}
			return true;
		}

		/**
		 * Progress label for a tool while it runs (UI heartbeat).
		 *
		 * @param string $name Ability name.
		 * @return string Empty when no module owns a label.
		 */
		public static function progress_label( $name ) {
			$name = (string) $name;
			foreach ( self::$modules as $class ) {
				if ( ! method_exists( $class, 'progress_label' ) ) {
					continue;
				}
				$label = $class::progress_label( $name );
				if ( is_string( $label ) && '' !== $label ) {
					return $label;
				}
			}
			return '';
		}

		/**
		 * Ability name → progress label map for sidebar bootstrap (optimistic UI).
		 *
		 * @return array<string, string>
		 */
		public static function progress_labels_map() {
			$map = array();
			foreach ( self::available_for_agent() as $name ) {
				$label = self::progress_label( $name );
				if ( '' !== $label ) {
					$map[ $name ] = $label;
				}
			}
			return $map;
		}

		/**
		 * Execute an ability by name (Abilities API or direct fallback).
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Input args.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			$name  = (string) $name;
			$input = is_array( $input ) ? $input : array();

			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to run this ability.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			// Models often emit content/blocks instead of payload — coerce before schema validate.
			if ( class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::STAGE === $name ) {
				$input = Ahentic_Session_Artifacts::coerce_stage_input( $input );
			}
			// Models often emit search instead of query for search-content.
			if ( class_exists( 'Ahentic_Abilities_Content' ) && Ahentic_Abilities_Content::SEARCH === $name ) {
				$input = Ahentic_Abilities_Content::coerce_search_input( $input );
			}

			if ( function_exists( 'wp_get_ability' ) ) {
				$ability = wp_get_ability( $name );
				if ( $ability && is_object( $ability ) && method_exists( $ability, 'execute' ) ) {
					return $ability->execute( $input );
				}
			}

			foreach ( self::$modules as $class ) {
				if ( ! method_exists( $class, 'execute' ) ) {
					continue;
				}
				$owns = false;
				if ( method_exists( $class, 'names' ) && in_array( $name, $class::names(), true ) ) {
					$owns = true;
				} elseif ( method_exists( $class, 'is_browser' ) && $class::is_browser( $name ) ) {
					$owns = true;
				} elseif ( method_exists( $class, 'is_artifact_ability' ) && $class::is_artifact_ability( $name ) ) {
					$owns = true;
				}
				if ( $owns ) {
					return $class::execute( $name, $input );
				}
			}

			return new WP_Error(
				'ahentic_ability_unknown',
				sprintf(
					/* translators: %s: ability name */
					__( 'Unknown or unavailable ability: %s', 'ahentic' ),
					$name
				)
			);
		}

		/**
		 * Canonical wp-admin deep links for agent replies (built with admin_url()).
		 *
		 * @return array<string, array{label: string, url: string}>
		 */
		public static function get_admin_links() {
			$links = array(
				'dashboard'         => array(
					'label' => __( 'Dashboard', 'ahentic' ),
					'url'   => admin_url( 'index.php' ),
				),
				'posts'             => array(
					'label' => __( 'Posts', 'ahentic' ),
					'url'   => admin_url( 'edit.php' ),
				),
				'pages'             => array(
					'label' => __( 'Pages', 'ahentic' ),
					'url'   => admin_url( 'edit.php?post_type=page' ),
				),
				'media'             => array(
					'label' => __( 'Media', 'ahentic' ),
					'url'   => admin_url( 'upload.php' ),
				),
				'comments'          => array(
					'label' => __( 'Comments', 'ahentic' ),
					'url'   => admin_url( 'edit-comments.php' ),
				),
				'plugins'           => array(
					'label' => __( 'Plugins', 'ahentic' ),
					'url'   => admin_url( 'plugins.php' ),
				),
				'plugin-install'    => array(
					'label' => __( 'Add Plugins', 'ahentic' ),
					'url'   => admin_url( 'plugin-install.php' ),
				),
				'themes'            => array(
					'label' => __( 'Themes', 'ahentic' ),
					'url'   => admin_url( 'themes.php' ),
				),
				'theme-editor'      => array(
					'label' => __( 'Theme File Editor', 'ahentic' ),
					'url'   => admin_url( 'theme-editor.php' ),
				),
				'users'             => array(
					'label' => __( 'Users', 'ahentic' ),
					'url'   => admin_url( 'users.php' ),
				),
				'tools'             => array(
					'label' => __( 'Tools', 'ahentic' ),
					'url'   => admin_url( 'tools.php' ),
				),
				'site-health'       => array(
					'label' => __( 'Site Health', 'ahentic' ),
					'url'   => admin_url( 'site-health.php' ),
				),
				'settings-general'  => array(
					'label' => __( 'Settings → General', 'ahentic' ),
					'url'   => admin_url( 'options-general.php' ),
				),
				'settings-writing'  => array(
					'label' => __( 'Settings → Writing', 'ahentic' ),
					'url'   => admin_url( 'options-writing.php' ),
				),
				'settings-reading'  => array(
					'label' => __( 'Settings → Reading', 'ahentic' ),
					'url'   => admin_url( 'options-reading.php' ),
				),
				'settings-discussion' => array(
					'label' => __( 'Settings → Discussion', 'ahentic' ),
					'url'   => admin_url( 'options-discussion.php' ),
				),
				'settings-media'    => array(
					'label' => __( 'Settings → Media', 'ahentic' ),
					'url'   => admin_url( 'options-media.php' ),
				),
				'settings-permalinks' => array(
					'label' => __( 'Settings → Permalinks', 'ahentic' ),
					'url'   => admin_url( 'options-permalink.php' ),
				),
				'settings-privacy'  => array(
					'label' => __( 'Settings → Privacy', 'ahentic' ),
					'url'   => admin_url( 'options-privacy.php' ),
				),
				'customizer'        => array(
					'label' => __( 'Customizer', 'ahentic' ),
					'url'   => admin_url( 'customize.php' ),
				),
				'widgets'           => array(
					'label' => __( 'Widgets', 'ahentic' ),
					'url'   => admin_url( 'widgets.php' ),
				),
				'menus'             => array(
					'label' => __( 'Menus', 'ahentic' ),
					'url'   => admin_url( 'nav-menus.php' ),
				),
				'updates'           => array(
					'label' => __( 'Updates', 'ahentic' ),
					'url'   => admin_url( 'update-core.php' ),
				),
				'ahentic-settings'  => array(
					'label' => __( 'Settings → Ahentic', 'ahentic' ),
					'url'   => admin_url( 'options-general.php?page=ahentic' ),
				),
			);

			/**
			 * Filter Ahentic admin deep links exposed to the agent.
			 *
			 * @param array $links Map of key => { label, url }.
			 */
			return apply_filters( 'ahentic_admin_links', $links );
		}

		/**
		 * Compact text block of admin links for the system instruction.
		 *
		 * @return string
		 */
		public static function format_admin_links_for_prompt() {
			$lines = array();
			foreach ( self::get_admin_links() as $key => $item ) {
				if ( empty( $item['url'] ) || empty( $item['label'] ) ) {
					continue;
				}
				$lines[] = '- ' . $item['label'] . ' (' . $key . '): ' . $item['url'];
			}
			return implode( "\n", $lines );
		}
	}
}
