<?php
/**
 * Settings-scoped snapshot store helpers + undo ability (ADR-0007).
 *
 * Pure list helpers (normalize / append / take) are PHPUnit-testable.
 * Session persistence lives on Ahentic_Session_Repository; restore callbacks
 * are registered by write abilities as Tracks C–E land.
 *
 * @package Ahentic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Settings_Snapshots' ) ) {
	/**
	 * Snapshot entry shaping, restore dispatch, undo-last-actions ability.
	 */
	class Ahentic_Settings_Snapshots {
		const UNDO = 'ahentic/undo-last-actions';

		const MAX_ENTRIES = 50;

		/**
		 * ability name => callable( array $entry ): true|\WP_Error
		 *
		 * @var array<string, callable>
		 */
		private static $restore_callbacks = array();

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array( self::UNDO );
		}

		/**
		 * Write ability names.
		 *
		 * @return string[]
		 */
		public static function write_names() {
			return array( self::UNDO );
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_readonly( $name ) {
			return ! in_array( (string) $name, self::write_names(), true );
		}

		/**
		 * Undo mutates site state by restoring — HITL not required (user-initiated restore).
		 *
		 * @return string[]
		 */
		public static function hitl_names() {
			return array();
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function requires_hitl( $name ) {
			return in_array( (string) $name, self::hitl_names(), true );
		}

		/**
		 * Register a restore callback for snapshots recorded under $ability.
		 *
		 * @param string   $ability  Ability name that produced the snapshot.
		 * @param callable $callback function( array $entry ): true|\WP_Error
		 */
		public static function register_restore( $ability, $callback ) {
			$ability = (string) $ability;
			if ( '' === $ability || ! is_callable( $callback ) ) {
				return;
			}
			self::$restore_callbacks[ $ability ] = $callback;
		}

		/**
		 * Clear restore map (PHPUnit / e2e only).
		 */
		public static function reset_restore_callbacks_for_tests() {
			self::$restore_callbacks = array();
		}

		/**
		 * Normalize a raw snapshot payload into a storeable entry.
		 *
		 * When prior_existed is false, prior_value is omitted so "did not exist"
		 * cannot be confused with "existed as null/empty".
		 *
		 * @param array $raw Raw fields.
		 * @return array|null Normalized entry or null if invalid.
		 */
		public static function normalize_entry( array $raw ) {
			$ability = isset( $raw['ability'] ) ? (string) $raw['ability'] : '';
			$target  = array_key_exists( 'target', $raw ) ? $raw['target'] : null;
			if ( '' === $ability || null === $target || '' === $target ) {
				return null;
			}

			$prior_existed = ! empty( $raw['prior_existed'] );
			$id            = isset( $raw['id'] ) && is_string( $raw['id'] ) && '' !== $raw['id']
				? (string) $raw['id']
				: self::generate_id();
			$timestamp     = isset( $raw['timestamp'] ) ? (int) $raw['timestamp'] : time();
			if ( $timestamp <= 0 ) {
				$timestamp = time();
			}

			$entry = array(
				'id'            => $id,
				'ability'       => $ability,
				'target'        => $target,
				'prior_existed' => $prior_existed,
				'timestamp'     => $timestamp,
			);

			if ( $prior_existed ) {
				$entry['prior_value'] = array_key_exists( 'prior_value', $raw ) ? $raw['prior_value'] : null;
			}

			return $entry;
		}

		/**
		 * Append an entry, dropping oldest when over $max.
		 *
		 * @param array $list Existing entries.
		 * @param array $entry Normalized entry.
		 * @param int   $max Cap (default MAX_ENTRIES).
		 * @return array
		 */
		public static function append_capped( array $list, array $entry, $max = null ) {
			$max = null === $max ? self::MAX_ENTRIES : max( 1, (int) $max );
			$list[] = $entry;
			$overflow = count( $list ) - $max;
			if ( $overflow > 0 ) {
				$list = array_slice( $list, $overflow );
			}
			return array_values( $list );
		}

		/**
		 * Take snapshots to undo (most recent first) and return remaining list.
		 *
		 * @param array    $list  Current snapshot list (oldest → newest).
		 * @param int      $count How many most-recent to take when $ids empty.
		 * @param string[] $ids   Optional explicit snapshot ids.
		 * @return array{taken: array, remaining: array}
		 */
		public static function take_for_undo( array $list, $count = 1, array $ids = array() ) {
			$ids = array_values( array_filter( array_map( 'strval', $ids ) ) );

			if ( ! empty( $ids ) ) {
				$id_set  = array_fill_keys( $ids, true );
				$taken   = array();
				$remain  = array();
				// Prefer newest-first among matches for restore order.
				for ( $i = count( $list ) - 1; $i >= 0; $i-- ) {
					$entry = $list[ $i ];
					$eid   = isset( $entry['id'] ) ? (string) $entry['id'] : '';
					if ( $eid && isset( $id_set[ $eid ] ) ) {
						$taken[] = $entry;
						unset( $id_set[ $eid ] );
					} else {
						$remain[] = $entry;
					}
				}
				$remain = array_reverse( $remain );
				return array(
					'taken'     => $taken,
					'remaining' => array_values( $remain ),
				);
			}

			$count = max( 0, (int) $count );
			if ( $count <= 0 || empty( $list ) ) {
				return array(
					'taken'     => array(),
					'remaining' => array_values( $list ),
				);
			}

			$n         = min( $count, count( $list ) );
			$taken     = array_reverse( array_slice( $list, -$n ) );
			$remaining = array_slice( $list, 0, count( $list ) - $n );

			return array(
				'taken'     => array_values( $taken ),
				'remaining' => array_values( $remaining ),
			);
		}

		/**
		 * Record a snapshot on the session (no-op without a session id).
		 *
		 * @param int   $session_id Session post ID.
		 * @param array $raw        Raw entry fields (ability, target, prior_*).
		 * @return array|null Normalized entry stored, or null on failure.
		 */
		public static function record( $session_id, array $raw ) {
			$session_id = (int) $session_id;
			$entry      = self::normalize_entry( $raw );
			if ( ! $session_id || ! $entry || ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return null;
			}
			Ahentic_Session_Repository::push_settings_snapshot( $session_id, $entry );
			return $entry;
		}

		/**
		 * Restore one snapshot entry via the registered callback for its ability.
		 *
		 * @param array $entry Snapshot entry.
		 * @return true|\WP_Error
		 */
		public static function restore_entry( array $entry ) {
			$ability = isset( $entry['ability'] ) ? (string) $entry['ability'] : '';
			if ( '' === $ability || ! isset( self::$restore_callbacks[ $ability ] ) ) {
				return new WP_Error(
					'ahentic_undo_no_restore',
					sprintf(
						/* translators: %s: ability name */
						__( 'No restore handler is registered for %s.', 'ahentic' ),
						$ability ? $ability : __( '(unknown)', 'ahentic' )
					)
				);
			}

			$result = call_user_func( self::$restore_callbacks[ $ability ], $entry );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return true;
		}

		/**
		 * Register category (reuses ahentic-session when present).
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}
			if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'ahentic-session' ) ) {
				return;
			}
			wp_register_ability_category(
				'ahentic-session',
				array(
					'label'       => __( 'Ahentic Session', 'ahentic' ),
					'description' => __( 'Session-scoped working files and artifacts for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register undo-last-actions.
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'manage_options' );
			};

			wp_register_ability(
				self::UNDO,
				array(
					'label'               => __( 'Undo last settings actions', 'ahentic' ),
					'description'         => __( 'Revert the most recent settings-surface writes Ahentic snapshotted in this session (theme settings, options, global styles, template parts, media metadata). Does not undo post content — use revisions for that.', 'ahentic' ),
					'category'            => 'ahentic-session',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'count' => array(
								'type'        => 'integer',
								'description' => __( 'How many most-recent snapshotted writes to undo (default 1). Ignored when snapshot_ids is set.', 'ahentic' ),
								'minimum'     => 1,
								'maximum'     => 50,
							),
							'snapshot_ids' => array(
								'type'        => 'array',
								'description' => __( 'Optional explicit snapshot ids to restore (instead of count).', 'ahentic' ),
								'items'       => array( 'type' => 'string' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'ok'       => array( 'type' => 'boolean' ),
							'undone'   => array( 'type' => 'integer' ),
							'restored' => array( 'type' => 'array' ),
							'message'  => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => static function ( $input ) {
						return Ahentic_Settings_Snapshots::execute( self::UNDO, is_array( $input ) ? $input : array() );
					},
					'permission_callback' => $permission,
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
		 * Dispatch.
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			$name  = (string) $name;
			$input = is_array( $input ) ? $input : array();

			if ( self::UNDO === $name ) {
				return self::execute_undo( $input );
			}

			return new WP_Error(
				'ahentic_ability_unknown',
				__( 'Unknown settings-snapshot ability.', 'ahentic' )
			);
		}

		/**
		 * Undo most recent (or listed) settings snapshots for the current session.
		 *
		 * @param array $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_undo( array $input ) {
			$session_id = 0;
			if ( class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'current_session_id' ) ) {
				$session_id = (int) Ahentic_Orchestrator::current_session_id();
			}
			if ( ! $session_id ) {
				return new WP_Error(
					'ahentic_undo_no_session',
					__( 'Undo requires an active Ahentic session.', 'ahentic' )
				);
			}

			$ids   = array();
			if ( ! empty( $input['snapshot_ids'] ) && is_array( $input['snapshot_ids'] ) ) {
				$ids = $input['snapshot_ids'];
			}
			$count = isset( $input['count'] ) ? (int) $input['count'] : 1;
			if ( $count < 1 ) {
				$count = 1;
			}

			$list   = Ahentic_Session_Repository::get_settings_snapshots( $session_id );
			$result = self::take_for_undo( $list, $count, $ids );
			$taken  = $result['taken'];

			if ( empty( $taken ) ) {
				return array(
					'ok'       => true,
					'undone'   => 0,
					'restored' => array(),
					'message'  => __( 'Nothing to undo in this session.', 'ahentic' ),
				);
			}

			$restored = array();
			$errors   = array();
			foreach ( $taken as $entry ) {
				$restore = self::restore_entry( $entry );
				if ( is_wp_error( $restore ) ) {
					$errors[] = $restore->get_error_message();
					// Put failed entry back so a later retry can try again.
					$result['remaining'][] = $entry;
					continue;
				}
				$restored[] = array(
					'id'      => isset( $entry['id'] ) ? (string) $entry['id'] : '',
					'ability' => isset( $entry['ability'] ) ? (string) $entry['ability'] : '',
					'target'  => isset( $entry['target'] ) ? $entry['target'] : null,
				);
			}

			Ahentic_Session_Repository::set_settings_snapshots( $session_id, $result['remaining'] );

			if ( ! empty( $errors ) && empty( $restored ) ) {
				return new WP_Error(
					'ahentic_undo_failed',
					implode( ' ', $errors )
				);
			}

			return array(
				'ok'       => true,
				'undone'   => count( $restored ),
				'restored' => $restored,
				'message'  => empty( $errors )
					? sprintf(
						/* translators: %d: number of actions undone */
						_n( 'Undid %d settings action.', 'Undid %d settings actions.', count( $restored ), 'ahentic' ),
						count( $restored )
					)
					: sprintf(
						/* translators: 1: undone count, 2: error text */
						__( 'Undid %1$d action(s); some restores failed: %2$s', 'ahentic' ),
						count( $restored ),
						implode( ' ', $errors )
					),
			);
		}

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			if ( self::UNDO === (string) $name ) {
				return __( 'Undoing last settings changes…', 'ahentic' );
			}
			return '';
		}

		/**
		 * @return string
		 */
		private static function generate_id() {
			if ( function_exists( 'wp_generate_uuid4' ) ) {
				return wp_generate_uuid4();
			}
			return uniqid( 'snap_', true );
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Settings_Snapshots', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Settings_Snapshots', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Settings_Snapshots' );
}
