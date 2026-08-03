<?php
/**
 * REST routes for agent sessions.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_REST_Sessions' ) ) {
	/**
	 * Session CRUD + message / HITL / browser / cancel endpoints.
	 */
	class Ahentic_REST_Sessions {
		/**
		 * Bootstrap.
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		/**
		 * Permission: manage Ahentic.
		 *
		 * @return bool
		 */
		public static function can_manage() {
			return is_user_logged_in() && current_user_can( 'manage_options' );
		}

		/**
		 * Register routes.
		 */
		public static function register_routes() {
			register_rest_route(
				'ahentic/v1',
				'/sessions',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, 'list_sessions' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
					),
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, 'create_session' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
						'args'                => array(
							'title' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'mode'  => array(
								'type' => 'string',
								'enum' => array( 'agent', 'ask' ),
							),
						),
					),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/sessions/(?P<id>\d+)',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, 'get_session' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
						'args'                => array(
							'id' => array(
								'type' => 'integer',
							),
						),
					),
					array(
						'methods'             => 'PATCH',
						'callback'            => array( __CLASS__, 'patch_session' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
					),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/sessions/(?P<id>\d+)/messages',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, 'get_messages' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
					),
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, 'post_message' ),
						'permission_callback' => array( __CLASS__, 'can_manage' ),
						'args'                => array(
							'content'     => array(
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_textarea_field',
							),
							'mode'        => array(
								'type' => 'string',
								'enum' => array( 'agent', 'ask' ),
							),
							'pageContext' => array(
								'type' => 'object',
							),
						),
					),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/sessions/(?P<id>\d+)/approvals',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_approval' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/sessions/(?P<id>\d+)/actions',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_action' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/sessions/(?P<id>\d+)/browser-results',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_browser_result' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/sessions/(?P<id>\d+)/cancel',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_cancel' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/sessions/(?P<id>\d+)/continue',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_continue' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/stats/tokens',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_token_stats' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);
		}

		/**
		 * Ensure the current user owns the session.
		 *
		 * @param int $session_id Session ID.
		 * @return true|\WP_Error
		 */
		private static function require_owned_session( $session_id ) {
			$post = Ahentic_Session_Repository::get_post( $session_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( ! Ahentic_Session_Repository::current_user_owns( $session_id ) ) {
				return new WP_Error( 'ahentic_forbidden', __( 'You cannot access this session.', 'ahentic' ), array( 'status' => 403 ) );
			}
			return true;
		}

		/**
		 * GET /sessions
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response
		 */
		public static function list_sessions( $request ) {
			$limit = (int) $request->get_param( 'limit' );
			if ( $limit <= 0 ) {
				$limit = 50;
			}
			return rest_ensure_response(
				array(
					'sessions' => Ahentic_Session_Repository::list_for_current_user( $limit ),
				)
			);
		}

		/**
		 * POST /sessions
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function create_session( $request ) {
			$id = Ahentic_Session_Repository::create(
				array(
					'title' => $request->get_param( 'title' ),
					'mode'  => $request->get_param( 'mode' ) ? $request->get_param( 'mode' ) : 'agent',
				)
			);

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			return rest_ensure_response( Ahentic_Session_Repository::to_rest( $id ) );
		}

		/**
		 * GET /sessions/{id}
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function get_session( $request ) {
			$id = (int) $request['id'];
			$ok = self::require_owned_session( $id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			return rest_ensure_response( Ahentic_Session_Repository::to_rest( $id ) );
		}

		/**
		 * PATCH /sessions/{id} — rename / mode / page context.
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function patch_session( $request ) {
			$id = (int) $request['id'];
			$ok = self::require_owned_session( $id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = array();
			}

			if ( isset( $params['title'] ) ) {
				Ahentic_Session_Repository::set_title( $id, $params['title'] );
			}
			if ( isset( $params['mode'] ) ) {
				Ahentic_Session_Repository::set_mode( $id, $params['mode'] );
			}
			if ( isset( $params['pageContext'] ) && is_array( $params['pageContext'] ) ) {
				Ahentic_Session_Repository::set_page_context( $id, $params['pageContext'] );
			}

			return rest_ensure_response( Ahentic_Session_Repository::to_rest( $id ) );
		}

		/**
		 * GET /sessions/{id}/messages
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function get_messages( $request ) {
			$id = (int) $request['id'];
			$ok = self::require_owned_session( $id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$page = Ahentic_Session_Repository::get_entries_page(
				$id,
				array(
					'limit'  => (int) $request->get_param( 'limit' ),
					'before' => (int) $request->get_param( 'before' ),
					'after'  => (int) $request->get_param( 'after' ),
				)
			);

			return rest_ensure_response( $page );
		}

		/**
		 * POST /sessions/{id}/messages
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_message( $request ) {
			$id = (int) $request['id'];
			$ok = self::require_owned_session( $id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$page_context = $request->get_param( 'pageContext' );
			if ( ! is_array( $page_context ) ) {
				$json = $request->get_json_params();
				if ( is_array( $json ) && isset( $json['pageContext'] ) && is_array( $json['pageContext'] ) ) {
					$page_context = $json['pageContext'];
				} else {
					$page_context = null;
				}
			}

			$result = Ahentic_Orchestrator::handle_user_message(
				$id,
				(string) $request->get_param( 'content' ),
				(string) $request->get_param( 'mode' ),
				$page_context
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}

		/**
		 * POST /sessions/{id}/approvals
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_approval( $request ) {
			$id = (int) $request['id'];
			$ok = self::require_owned_session( $id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = array();
			}

			$result = Ahentic_Orchestrator::handle_approval( $id, $params );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}

		/**
		 * POST /sessions/{id}/actions — start a suggested ability action.
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_action( $request ) {
			$id = (int) $request['id'];
			$ok = self::require_owned_session( $id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = array();
			}

			$result = Ahentic_Orchestrator::handle_suggested_action( $id, $params );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}

		/**
		 * POST /sessions/{id}/browser-results
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_browser_result( $request ) {
			$id = (int) $request['id'];
			$ok = self::require_owned_session( $id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = array();
			}

			$result = Ahentic_Orchestrator::handle_browser_result( $id, $params );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}

		/**
		 * POST /sessions/{id}/cancel
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_cancel( $request ) {
			$id = (int) $request['id'];
			$ok = self::require_owned_session( $id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$result = Ahentic_Orchestrator::cancel( $id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}

		/**
		 * POST /sessions/{id}/continue — stall fallback when queue/shutdown did not run.
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_continue( $request ) {
			$id = (int) $request['id'];
			$ok = self::require_owned_session( $id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$result = Ahentic_Orchestrator::continue_run( $id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}

		/**
		 * GET /stats/tokens
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response
		 */
		public static function get_token_stats( $request ) {
			$days = (int) $request->get_param( 'days' );
			if ( $days <= 0 ) {
				$days = 30;
			}
			return rest_ensure_response(
				array(
					'series' => Ahentic_Usage::get_series( $days ),
				)
			);
		}
	}
}
