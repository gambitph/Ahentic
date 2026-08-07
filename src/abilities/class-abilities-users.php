<?php
/**
 * User abilities: list / create / update / delete with role ceiling + HITL.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Users' ) ) {
	/**
	 * User CRUD for the agent loop (non-preallowable writes).
	 */
	class Ahentic_Abilities_Users {
		const LIST   = 'ahentic/list-users';
		const CREATE = 'ahentic/create-user';
		const UPDATE = 'ahentic/update-user';
		const DELETE = 'ahentic/delete-user';

		const MAX_LIST     = 100;
		const DEFAULT_LIST = 50;

		/**
		 * Single policy catalog: drives names / write / HITL / non_preallowable / progress / summary.
		 *
		 * @return array<string, array{write?:bool, hitl?:bool, non_preallowable?:bool, progress:string, summary:string}>
		 */
		private static function catalog() {
			return array(
				self::LIST   => array(
					'progress' => __( 'Listing users…', 'ahentic' ),
					'summary'  => __( 'List users', 'ahentic' ),
				),
				self::CREATE => array(
					'write'            => true,
					'hitl'             => true,
					'non_preallowable' => true,
					'progress'         => __( 'Creating user…', 'ahentic' ),
					'summary'          => __( 'Create user', 'ahentic' ),
				),
				self::UPDATE => array(
					'write'            => true,
					'hitl'             => true,
					'non_preallowable' => true,
					'progress'         => __( 'Updating user…', 'ahentic' ),
					'summary'          => __( 'Update user', 'ahentic' ),
				),
				self::DELETE => array(
					'write'            => true,
					'hitl'             => true,
					'non_preallowable' => true,
					'progress'         => __( 'Deleting user…', 'ahentic' ),
					'summary'          => __( 'Delete user', 'ahentic' ),
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
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function requires_hitl( $name ) {
			return in_array( (string) $name, self::hitl_names(), true );
		}

		/**
		 * Irreversible / privilege writes that must never honor session/always allowlists.
		 *
		 * @return string[]
		 */
		public static function non_preallowable_names() {
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['non_preallowable'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function is_non_preallowable( $name ) {
			return in_array( (string) $name, self::non_preallowable_names(), true );
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
		 * Human-readable summary for HITL UI.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function hitl_summary( $name, $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$name  = (string) $name;

			if ( self::CREATE === $name ) {
				$username = isset( $input['username'] ) ? trim( (string) $input['username'] ) : '';
				$role     = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';
				return sprintf(
					/* translators: 1: username, 2: role */
					__( 'Create user “%1$s” with role %2$s', 'ahentic' ),
					$username ? $username : __( 'new user', 'ahentic' ),
					$role ? $role : __( 'default', 'ahentic' )
				);
			}

			if ( self::UPDATE === $name ) {
				$user_ref = self::hitl_user_ref( $input );
				$fields   = array();
				foreach ( array( 'email', 'display_name', 'role' ) as $field ) {
					if ( array_key_exists( $field, $input ) ) {
						$fields[] = $field;
					}
				}
				$fields_label = ! empty( $fields ) ? implode( ', ', $fields ) : __( 'fields', 'ahentic' );
				return sprintf(
					/* translators: 1: user label, 2: field list */
					__( 'Update user %1$s: %2$s', 'ahentic' ),
					$user_ref,
					$fields_label
				);
			}

			if ( self::DELETE === $name ) {
				$target   = self::hitl_user_ref( $input );
				$reassign = isset( $input['reassign_to'] ) ? (int) $input['reassign_to'] : 0;
				$to_ref   = $reassign > 0 ? self::hitl_user_ref( array( 'user_id' => $reassign ) ) : __( 'unknown', 'ahentic' );
				return sprintf(
					/* translators: 1: target user, 2: reassignment user */
					__( 'Delete user %1$s and reassign their content to %2$s', 'ahentic' ),
					$target,
					$to_ref
				);
			}

			return self::summary( $name );
		}

		/**
		 * Compact user label for HITL copy.
		 *
		 * @param array $input Input with user_id and/or username.
		 * @return string
		 */
		private static function hitl_user_ref( array $input ) {
			$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
			if ( $user_id > 0 ) {
				if ( function_exists( 'get_userdata' ) && class_exists( 'WP_User' ) ) {
					$user = get_userdata( $user_id );
					if ( $user instanceof WP_User ) {
						return $user->display_name . ' (#' . (int) $user->ID . ')';
					}
				}
				return '#' . $user_id;
			}
			if ( isset( $input['username'] ) && '' !== trim( (string) $input['username'] ) ) {
				return trim( (string) $input['username'] );
			}
			return __( 'unknown', 'ahentic' );
		}

		/**
		 * Register the users ability category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			wp_register_ability_category(
				'ahentic-users',
				array(
					'label'       => __( 'Ahentic Users', 'ahentic' ),
					'description' => __( 'List and manage WordPress users for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register user abilities.
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'list_users' )
					|| current_user_can( 'create_users' )
					|| current_user_can( 'edit_users' )
					|| current_user_can( 'delete_users' )
					|| current_user_can( 'manage_options' );
			};

			$readonly_meta = array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => false,
			);

			$mutate_meta = array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
			);

			$destructive_meta = array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
			);

			wp_register_ability(
				self::LIST,
				array(
					'label'               => __( 'List users', 'ahentic' ),
					'description'         => __( 'Lists WordPress users (id, display name, roles, registered date, post count). Optional role and search filters. Email is included only when the operator can list_users.', 'ahentic' ),
					'category'            => 'ahentic-users',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'role'   => array(
								'type'        => 'string',
								'description' => __( 'Optional role slug filter (e.g. editor, author).', 'ahentic' ),
							),
							'search' => array(
								'type'        => 'string',
								'description' => __( 'Optional search string (login, email, display name).', 'ahentic' ),
							),
							'number' => array(
								'type'        => 'integer',
								'description' => __( 'Max users to return (default 50, max 100).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_list_users' ),
					'permission_callback' => $permission,
					'meta'                => $readonly_meta,
				)
			);

			wp_register_ability(
				self::CREATE,
				array(
					'label'               => __( 'Create user', 'ahentic' ),
					'description'         => __( 'Creates a WordPress user (wp_insert_user). Requires username, email, and role. Role must be strictly below the operator’s own (capability comparison). Always requires a fresh Allow (not session/always). Does not send credentials.', 'ahentic' ),
					'category'            => 'ahentic-users',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'username', 'email', 'role' ),
						'properties' => array(
							'username'     => array(
								'type'        => 'string',
								'description' => __( 'Login username.', 'ahentic' ),
							),
							'email'        => array(
								'type'        => 'string',
								'description' => __( 'User email address.', 'ahentic' ),
							),
							'role'         => array(
								'type'        => 'string',
								'description' => __( 'Role slug to assign (must be below the operator’s role ceiling).', 'ahentic' ),
							),
							'display_name' => array(
								'type'        => 'string',
								'description' => __( 'Optional display name.', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_create_user' ),
					'permission_callback' => $permission,
					'meta'                => $mutate_meta,
				)
			);

			wp_register_ability(
				self::UPDATE,
				array(
					'label'               => __( 'Update user', 'ahentic' ),
					'description'         => __( 'Updates an existing user (email, display_name, role). Refuses self-edit (acting user cannot be the target). Role changes must stay below the operator’s ceiling. Always requires a fresh Allow (not session/always).', 'ahentic' ),
					'category'            => 'ahentic-users',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'user_id' ),
						'properties' => array(
							'user_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Target user ID (must not be the acting user).', 'ahentic' ),
							),
							'email'        => array(
								'type'        => 'string',
								'description' => __( 'New email address.', 'ahentic' ),
							),
							'display_name' => array(
								'type'        => 'string',
								'description' => __( 'New display name.', 'ahentic' ),
							),
							'role'         => array(
								'type'        => 'string',
								'description' => __( 'New role slug (must be below the operator’s role ceiling).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_update_user' ),
					'permission_callback' => $permission,
					'meta'                => $mutate_meta,
				)
			);

			wp_register_ability(
				self::DELETE,
				array(
					'label'               => __( 'Delete user', 'ahentic' ),
					'description'         => __( 'Deletes a user and reassigns their content. reassign_to (a different existing user id) is required — there is no delete-content path. Always requires a fresh Allow (not session/always).', 'ahentic' ),
					'category'            => 'ahentic-users',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'user_id', 'reassign_to' ),
						'properties' => array(
							'user_id'     => array(
								'type'        => 'integer',
								'description' => __( 'User ID to delete.', 'ahentic' ),
							),
							'reassign_to' => array(
								'type'        => 'integer',
								'description' => __( 'Existing user ID that receives the deleted user’s content (required, must differ).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_delete_user' ),
					'permission_callback' => $permission,
					'meta'                => $destructive_meta,
				)
			);
		}

		/**
		 * Dispatch by ability name (fallback path).
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Input.
		 * @return mixed|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			switch ( $name ) {
				case self::LIST:
					return self::execute_list_users( $input );
				case self::CREATE:
					return self::execute_create_user( $input );
				case self::UPDATE:
					return self::execute_update_user( $input );
				case self::DELETE:
					return self::execute_delete_user( $input );
				default:
					return new WP_Error( 'ahentic_ability_unknown', __( 'Unknown user ability.', 'ahentic' ) );
			}
		}

		/**
		 * List users.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_list_users( $input = array() ) {
			$input = is_array( $input ) ? $input : array();

			if ( ! current_user_can( 'list_users' ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to list users.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$number = isset( $input['number'] ) ? (int) $input['number'] : self::DEFAULT_LIST;
			$number = max( 1, min( self::MAX_LIST, $number ) );

			$args = array(
				'number'  => $number,
				'orderby' => 'registered',
				'order'   => 'DESC',
				'fields'  => 'all_with_meta',
			);

			if ( isset( $input['role'] ) && '' !== $input['role'] && null !== $input['role'] ) {
				$role = sanitize_key( (string) $input['role'] );
				if ( '' === $role || ! get_role( $role ) ) {
					return new WP_Error(
						'ahentic_invalid_role',
						__( 'Unknown role.', 'ahentic' ),
						array(
							'status' => 400,
							'hint'   => __( 'Pass a registered role slug such as subscriber, contributor, author, or editor.', 'ahentic' ),
						)
					);
				}
				$args['role'] = $role;
			}

			if ( isset( $input['search'] ) && '' !== $input['search'] && null !== $input['search'] ) {
				$search         = sanitize_text_field( (string) $input['search'] );
				$args['search'] = '*' . $search . '*';
				$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
			}

			$users         = get_users( $args );
			$include_email = current_user_can( 'list_users' );
			$out           = array();
			foreach ( $users as $user ) {
				if ( $user instanceof WP_User ) {
					$out[] = self::summarize_user( $user, $include_email );
				}
			}

			return array(
				'ok'            => true,
				'count'         => count( $out ),
				'number'        => $number,
				'include_email' => $include_email,
				'users'         => $out,
			);
		}

		/**
		 * Create a user.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_create_user( $input = array() ) {
			$input = is_array( $input ) ? $input : array();

			if ( ! current_user_can( 'create_users' ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to create users.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$username = isset( $input['username'] ) ? sanitize_user( (string) $input['username'], true ) : '';
			$email    = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
			$role     = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';

			if ( '' === $username ) {
				return new WP_Error(
					'ahentic_invalid_username',
					__( 'A valid username is required.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}
			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error(
					'ahentic_invalid_email',
					__( 'A valid email address is required.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}
			if ( '' === $role ) {
				return new WP_Error(
					'ahentic_invalid_role',
					__( 'A role slug is required.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			$ceiling = self::assert_role_below_ceiling( $role );
			if ( is_wp_error( $ceiling ) ) {
				return $ceiling;
			}

			if ( username_exists( $username ) ) {
				return new WP_Error(
					'ahentic_username_exists',
					__( 'That username is already registered.', 'ahentic' ),
					array(
						'status' => 400,
						'hint'   => __( 'Choose a different username.', 'ahentic' ),
					)
				);
			}
			if ( email_exists( $email ) ) {
				return new WP_Error(
					'ahentic_email_exists',
					__( 'That email address is already registered.', 'ahentic' ),
					array(
						'status' => 400,
						'hint'   => __( 'Choose a different email.', 'ahentic' ),
					)
				);
			}

			$userdata = array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 24, true, true ),
				'role'       => $role,
			);

			if ( isset( $input['display_name'] ) && '' !== trim( (string) $input['display_name'] ) ) {
				$userdata['display_name'] = sanitize_text_field( (string) $input['display_name'] );
			}

			$user_id = wp_insert_user( $userdata );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$user = get_userdata( (int) $user_id );
			if ( ! $user instanceof WP_User ) {
				return new WP_Error(
					'ahentic_user_create_failed',
					__( 'User was created but could not be loaded.', 'ahentic' ),
					array( 'status' => 500 )
				);
			}

			return array(
				'ok'   => true,
				'user' => self::summarize_user( $user, current_user_can( 'list_users' ) ),
			);
		}

		/**
		 * Update a user.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_update_user( $input = array() ) {
			$input = is_array( $input ) ? $input : array();

			if ( ! current_user_can( 'edit_users' ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to update users.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
			if ( $user_id <= 0 ) {
				return new WP_Error(
					'ahentic_invalid_user',
					__( 'A valid user_id is required.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			if ( $user_id === (int) get_current_user_id() ) {
				return new WP_Error(
					'ahentic_user_self_edit',
					__( 'You cannot update your own account through this ability.', 'ahentic' ),
					array(
						'status' => 403,
						'hint'   => __( 'Ask the operator to edit their profile in wp-admin, or target a different user_id.', 'ahentic' ),
					)
				);
			}

			$user = get_userdata( $user_id );
			if ( ! $user instanceof WP_User ) {
				return new WP_Error(
					'ahentic_user_not_found',
					__( 'User not found.', 'ahentic' ),
					array( 'status' => 404 )
				);
			}

			$has_field = false;
			$userdata  = array( 'ID' => $user_id );

			if ( array_key_exists( 'email', $input ) ) {
				$has_field = true;
				$email     = sanitize_email( (string) $input['email'] );
				if ( '' === $email || ! is_email( $email ) ) {
					return new WP_Error(
						'ahentic_invalid_email',
						__( 'A valid email address is required.', 'ahentic' ),
						array( 'status' => 400 )
					);
				}
				$existing = email_exists( $email );
				if ( $existing && (int) $existing !== $user_id ) {
					return new WP_Error(
						'ahentic_email_exists',
						__( 'That email address is already registered.', 'ahentic' ),
						array( 'status' => 400 )
					);
				}
				$userdata['user_email'] = $email;
			}

			if ( array_key_exists( 'display_name', $input ) ) {
				$has_field                = true;
				$userdata['display_name'] = sanitize_text_field( (string) $input['display_name'] );
			}

			$new_role = null;
			if ( array_key_exists( 'role', $input ) ) {
				$has_field = true;
				$new_role  = sanitize_key( (string) $input['role'] );
				if ( '' === $new_role ) {
					return new WP_Error(
						'ahentic_invalid_role',
						__( 'A valid role slug is required.', 'ahentic' ),
						array( 'status' => 400 )
					);
				}
				$ceiling = self::assert_role_below_ceiling( $new_role );
				if ( is_wp_error( $ceiling ) ) {
					return $ceiling;
				}
			}

			if ( ! $has_field ) {
				return new WP_Error(
					'ahentic_nothing_to_update',
					__( 'Provide at least one of email, display_name, or role.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			$result = wp_update_user( $userdata );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( null !== $new_role ) {
				$user->set_role( $new_role );
			}

			$fresh = get_userdata( $user_id );
			if ( ! $fresh instanceof WP_User ) {
				return new WP_Error(
					'ahentic_user_update_failed',
					__( 'User was updated but could not be reloaded.', 'ahentic' ),
					array( 'status' => 500 )
				);
			}

			return array(
				'ok'   => true,
				'user' => self::summarize_user( $fresh, current_user_can( 'list_users' ) ),
			);
		}

		/**
		 * Delete a user and reassign content.
		 *
		 * @param mixed $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute_delete_user( $input = array() ) {
			$input = is_array( $input ) ? $input : array();

			if ( ! current_user_can( 'delete_users' ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'ahentic_ability_forbidden',
					__( 'You do not have permission to delete users.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$user_id     = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
			$reassign_to = isset( $input['reassign_to'] ) ? (int) $input['reassign_to'] : 0;

			if ( $user_id <= 0 ) {
				return new WP_Error(
					'ahentic_invalid_user',
					__( 'A valid user_id is required.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			if ( $reassign_to <= 0 ) {
				return new WP_Error(
					'ahentic_reassign_required',
					__( 'reassign_to is required: content must move to another existing user.', 'ahentic' ),
					array(
						'status' => 400,
						'hint'   => __( 'Pass reassign_to as a different valid user id. There is no delete-content option.', 'ahentic' ),
					)
				);
			}

			if ( $user_id === $reassign_to ) {
				return new WP_Error(
					'ahentic_reassign_same_user',
					__( 'reassign_to must be a different user than user_id.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			if ( $user_id === (int) get_current_user_id() ) {
				return new WP_Error(
					'ahentic_user_self_delete',
					__( 'You cannot delete your own account through this ability.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			$target = get_userdata( $user_id );
			if ( ! $target instanceof WP_User ) {
				return new WP_Error(
					'ahentic_user_not_found',
					__( 'User not found.', 'ahentic' ),
					array( 'status' => 404 )
				);
			}

			$reassign = get_userdata( $reassign_to );
			if ( ! $reassign instanceof WP_User ) {
				return new WP_Error(
					'ahentic_reassign_not_found',
					__( 'reassign_to user was not found.', 'ahentic' ),
					array(
						'status' => 400,
						'hint'   => __( 'Pass an existing user id for reassign_to.', 'ahentic' ),
					)
				);
			}

			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}

			$summary = self::summarize_user( $target, current_user_can( 'list_users' ) );
			$deleted = wp_delete_user( $user_id, $reassign_to );
			if ( ! $deleted ) {
				return new WP_Error(
					'ahentic_user_delete_failed',
					__( 'Could not delete the user.', 'ahentic' ),
					array( 'status' => 500 )
				);
			}

			return array(
				'ok'          => true,
				'deleted'     => $summary,
				'reassign_to' => self::summarize_user( $reassign, current_user_can( 'list_users' ) ),
			);
		}

		/**
		 * Pure ceiling check: target role caps must be a proper subset of operator caps.
		 *
		 * Refuses "at" (equal caps) and "above" (target has caps the operator lacks).
		 *
		 * @param string[] $operator_caps Granted capability names the operator holds via roles.
		 * @param string[] $target_caps   Granted capability names on the proposed role.
		 * @return bool True when the role is strictly below the ceiling.
		 */
		public static function role_is_below_ceiling( array $operator_caps, array $target_caps ) {
			$operator_caps = array_values( array_unique( array_map( 'strval', $operator_caps ) ) );
			$target_caps   = array_values( array_unique( array_map( 'strval', $target_caps ) ) );

			// Above: target has any capability the operator lacks.
			if ( ! empty( array_diff( $target_caps, $operator_caps ) ) ) {
				return false;
			}

			// At: same capability set (not a strict subset).
			if ( empty( array_diff( $operator_caps, $target_caps ) ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Granted capability keys for a role slug (true grants only).
		 *
		 * @param string $role_slug Role slug.
		 * @return string[]|null Null when the role does not exist.
		 */
		public static function granted_caps_for_role( $role_slug ) {
			$role_slug = sanitize_key( (string) $role_slug );
			if ( '' === $role_slug || ! function_exists( 'get_role' ) ) {
				return null;
			}
			$role = get_role( $role_slug );
			if ( ! $role ) {
				return null;
			}
			$caps = array();
			foreach ( (array) $role->capabilities as $cap => $grant ) {
				if ( $grant ) {
					$caps[] = (string) $cap;
				}
			}
			sort( $caps );
			return $caps;
		}

		/**
		 * Union of granted caps from the current user's assigned roles.
		 *
		 * @return string[]
		 */
		public static function operator_role_caps() {
			$user = wp_get_current_user();
			$caps = array();
			foreach ( (array) $user->roles as $role_slug ) {
				$role_caps = self::granted_caps_for_role( $role_slug );
				if ( null !== $role_caps ) {
					$caps = array_merge( $caps, $role_caps );
				}
			}
			$caps = array_values( array_unique( $caps ) );
			sort( $caps );
			return $caps;
		}

		/**
		 * Validate that $role_slug is strictly below the operator's role ceiling.
		 *
		 * @param string $role_slug Proposed role.
		 * @return true|\WP_Error
		 */
		public static function assert_role_below_ceiling( $role_slug ) {
			$target_caps = self::granted_caps_for_role( $role_slug );
			if ( null === $target_caps ) {
				return new WP_Error(
					'ahentic_invalid_role',
					__( 'Unknown role.', 'ahentic' ),
					array(
						'status' => 400,
						'hint'   => __( 'Pass a registered role slug. Custom roles are compared by capabilities, not by name.', 'ahentic' ),
					)
				);
			}

			$operator_caps = self::operator_role_caps();
			if ( empty( $operator_caps ) ) {
				return new WP_Error(
					'ahentic_role_ceiling',
					__( 'Cannot assign roles: the operator has no role capabilities to compare.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}

			if ( ! self::role_is_below_ceiling( $operator_caps, $target_caps ) ) {
				return new WP_Error(
					'ahentic_role_ceiling',
					__( 'That role is at or above the operator’s own role and cannot be assigned.', 'ahentic' ),
					array(
						'status' => 403,
						'hint'   => __( 'Choose a role whose capabilities are a strict subset of yours (capability comparison, not role-name rank).', 'ahentic' ),
					)
				);
			}

			return true;
		}

		/**
		 * Build a safe user summary card.
		 *
		 * @param \WP_User $user          User.
		 * @param bool     $include_email Whether to include email.
		 * @return array<string, mixed>
		 */
		public static function summarize_user( $user, $include_email = false ) {
			$card = array(
				'id'           => (int) $user->ID,
				'username'     => (string) $user->user_login,
				'display_name' => (string) $user->display_name,
				'roles'        => array_values( (array) $user->roles ),
				'registered'   => (string) $user->user_registered,
				'post_count'   => (int) count_user_posts( (int) $user->ID, 'post', true ),
			);
			if ( $include_email ) {
				$card['email'] = (string) $user->user_email;
			}
			return $card;
		}

		/**
		 * @param string $name Ability name.
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
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Users', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Users', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Users' );
}
