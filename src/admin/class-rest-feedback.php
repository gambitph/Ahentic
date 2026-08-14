<?php
/**
 * REST routes for Run feedback (proxy to feedback.wpahentic.com).
 *
 * @package Ahentic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_REST_Feedback' ) ) {
	/**
	 * Registers ahentic/v1/feedback* routes.
	 */
	class Ahentic_REST_Feedback {
		/**
		 * Hook registration.
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		/**
		 * Whether the current user may manage Ahentic feedback routes.
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
				'/feedback',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_status' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/feedback/site-tokens',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_mint' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/feedback/site-tokens/refresh',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_refresh' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/feedback/draft',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_draft' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'session_id'      => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'user_note'       => array(
							'required' => false,
							'type'     => 'string',
						),
						'page_context'    => array(
							'required' => false,
							'type'     => 'object',
						),
						'editor_snapshot' => array(
							'required' => false,
							'type'     => 'object',
						),
						'observations'    => array(
							'required' => false,
							'type'     => 'array',
						),
					),
				)
			);

			register_rest_route(
				'ahentic/v1',
				'/feedback/reports',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_report' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'session_id'      => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'title'           => array(
							'required' => false,
							'type'     => 'string',
						),
						'summary'         => array(
							'required' => false,
							'type'     => 'string',
						),
						'hypothesis'      => array(
							'required' => false,
							'type'     => 'string',
						),
						'user_note'       => array(
							'required' => false,
							'type'     => 'string',
						),
						'prompt_excerpt'  => array(
							'required' => false,
							'type'     => 'string',
						),
						'page_context'    => array(
							'required' => false,
							'type'     => 'object',
						),
						'editor_snapshot' => array(
							'required' => false,
							'type'     => 'object',
						),
						'observations'    => array(
							'required' => false,
							'type'     => 'array',
						),
						'duplicate_of'    => array(
							'required' => false,
						),
					),
				)
			);
		}

		/**
		 * GET /feedback
		 *
		 * @return \WP_REST_Response
		 */
		public static function get_status() {
			return rest_ensure_response( Ahentic_Feedback_Intake::status() );
		}

		/**
		 * POST /feedback/site-tokens — fresh mint (mint proof computed server-side).
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_mint() {
			$result = Ahentic_Feedback_Intake::mint_site_token();
			if ( is_wp_error( $result ) ) {
				return self::error_response( $result );
			}
			// Never echo the raw token to the browser — status only.
			return rest_ensure_response( Ahentic_Feedback_Intake::status() );
		}

		/**
		 * POST /feedback/site-tokens/refresh
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_refresh() {
			$result = Ahentic_Feedback_Intake::refresh_site_token();
			if ( is_wp_error( $result ) ) {
				return self::error_response( $result );
			}
			return rest_ensure_response( Ahentic_Feedback_Intake::status() );
		}

		/**
		 * POST /feedback/draft — LLM title/summary/hypothesis. Does not file.
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_draft( $request ) {
			$session_id = (int) $request->get_param( 'session_id' );
			$owned      = self::require_owned_session( $session_id );
			if ( is_wp_error( $owned ) ) {
				return $owned;
			}

			$args      = self::client_snapshot_args( $request );
			$user_note = $request->get_param( 'user_note' );
			if ( is_string( $user_note ) && '' !== trim( $user_note ) ) {
				$args['user_note'] = $user_note;
			}

			$result = Ahentic_Feedback_Intake::draft_report( $session_id, $args );
			if ( is_wp_error( $result ) ) {
				return self::error_response( $result );
			}

			return rest_ensure_response(
				array(
					'title'      => isset( $result['title'] ) ? (string) $result['title'] : '',
					'summary'    => isset( $result['summary'] ) ? (string) $result['summary'] : '',
					'hypothesis' => isset( $result['hypothesis'] ) ? (string) $result['hypothesis'] : '',
				)
			);
		}

		/**
		 * POST /feedback/reports
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function post_report( $request ) {
			$session_id = (int) $request->get_param( 'session_id' );
			$owned      = self::require_owned_session( $session_id );
			if ( is_wp_error( $owned ) ) {
				return $owned;
			}

			$args  = self::client_snapshot_args( $request );
			$title = $request->get_param( 'title' );
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				$args['title'] = $title;
			}
			$summary = $request->get_param( 'summary' );
			if ( is_string( $summary ) && '' !== trim( $summary ) ) {
				$args['summary'] = $summary;
			}
			$hypothesis = $request->get_param( 'hypothesis' );
			if ( is_string( $hypothesis ) && '' !== trim( $hypothesis ) ) {
				$args['hypothesis'] = $hypothesis;
			}
			$user_note = $request->get_param( 'user_note' );
			if ( is_string( $user_note ) && '' !== trim( $user_note ) ) {
				$args['user_note'] = $user_note;
			}
			$excerpt = $request->get_param( 'prompt_excerpt' );
			if ( is_string( $excerpt ) && '' !== $excerpt ) {
				$args['prompt_excerpt'] = $excerpt;
			}
			if ( $request->offsetExists( 'duplicate_of' ) ) {
				$dup                  = $request->get_param( 'duplicate_of' );
				$args['duplicate_of'] = ( null === $dup || '' === $dup ) ? null : (int) $dup;
			}

			$result = Ahentic_Feedback_Intake::file_report( $session_id, $args );
			if ( is_wp_error( $result ) ) {
				return self::error_response( $result );
			}

			return rest_ensure_response(
				array(
					'action'   => isset( $result['action'] ) ? $result['action'] : '',
					'number'   => isset( $result['number'] ) ? (int) $result['number'] : 0,
					'html_url' => isset( $result['html_url'] ) ? $result['html_url'] : '',
				)
			);
		}

		/**
		 * Require the current user owns this session.
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
				return new WP_Error(
					'ahentic_forbidden',
					__( 'You cannot access this session.', 'ahentic' ),
					array( 'status' => 403 )
				);
			}
			return true;
		}

		/**
		 * Page / editor snapshot args from a REST request.
		 *
		 * @param \WP_REST_Request $request Request.
		 * @return array
		 */
		private static function client_snapshot_args( $request ) {
			$args = array();
			$page = $request->get_param( 'page_context' );
			if ( is_array( $page ) ) {
				$args['page_context'] = $page;
			}
			$snap = $request->get_param( 'editor_snapshot' );
			if ( is_array( $snap ) ) {
				$args['editor_snapshot'] = $snap;
			}
			$obs = $request->get_param( 'observations' );
			if ( is_array( $obs ) ) {
				$args['observations'] = $obs;
			}
			return $args;
		}

		/**
		 * Map intake / domain errors onto REST status codes.
		 *
		 * @param \WP_Error $error Error.
		 * @return \WP_Error
		 */
		private static function error_response( $error ) {
			$data   = $error->get_error_data();
			$status = 500;
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}
			// rate_limited from intake should surface as 429.
			if ( 'rate_limited' === $error->get_error_code() ) {
				$status = 429;
			}
			return new WP_Error(
				$error->get_error_code(),
				$error->get_error_message(),
				array_merge( is_array( $data ) ? $data : array(), array( 'status' => $status ) )
			);
		}
	}
}
