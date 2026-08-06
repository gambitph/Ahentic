<?php
/**
 * E2E-only test harness: runs Ahentic abilities, seeds fixtures, and mocks AI
 * responses over REST for the Playwright suite.
 *
 * Ahentic abilities are deliberately kept off the public Abilities REST run
 * route (`meta.show_in_rest => false` — see src/abilities/abilities.md); this
 * file is NOT part of the plugin, is never packaged (see scripts/package.js),
 * and only exists inside the WordPress instance `@wp-playground/cli` boots
 * for the e2e suite (mounted at `wp-content/mu-plugins`, see
 * playwright.config.js's `webServer.command`). It gives Playwright:
 *
 * - A fast, deterministic way to call `Ahentic_Abilities::execute()` — the
 *   same dispatch the orchestrator itself uses — without driving a real LLM
 *   through the sidebar chat loop (`run-ability`).
 * - A queue of canned AI responses that `Ahentic_AI::complete_chat()` pops
 *   from via the `pre_ahentic_ai_complete_chat` filter instead of calling a
 *   real provider, so sidebar/chat-driven specs are deterministic
 *   (`seed-ai-responses`, `reset`).
 * - A way to seed WordPress fixture data without a slow UI walk-through
 *   (`seed`).
 *
 * @package Ahentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'ahentic-e2e/v1',
			'/run-ability',
			array(
				'methods'             => 'POST',
				'callback'            => 'ahentic_e2e_run_ability',
				'permission_callback' => 'ahentic_e2e_permission_check',
				'args'                => array(
					'name'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'input' => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			'ahentic-e2e/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					return array(
						'ok'               => true,
						'abilities_loaded' => class_exists( 'Ahentic_Abilities' ),
						'abilities_api'    => function_exists( 'wp_get_ability' ),
					);
				},
				'permission_callback' => 'ahentic_e2e_permission_check',
			)
		);

		register_rest_route(
			'ahentic-e2e/v1',
			'/seed-ai-responses',
			array(
				'methods'             => 'POST',
				'callback'            => 'ahentic_e2e_seed_ai_responses',
				'permission_callback' => 'ahentic_e2e_permission_check',
				'args'                => array(
					'responses' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'ahentic-e2e/v1',
			'/seed',
			array(
				'methods'             => 'POST',
				'callback'            => 'ahentic_e2e_seed',
				'permission_callback' => 'ahentic_e2e_permission_check',
			)
		);

		register_rest_route(
			'ahentic-e2e/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'callback'            => 'ahentic_e2e_reset',
				'permission_callback' => 'ahentic_e2e_permission_check',
			)
		);
	}
);

/**
 * Shared permission check for every e2e-only route: an authenticated admin.
 *
 * @return bool
 */
function ahentic_e2e_permission_check() {
	return current_user_can( 'manage_options' );
}

/**
 * Run a single ability and return a JSON-friendly result envelope.
 *
 * Deliberately thin: it delegates to `Ahentic_Abilities::execute()` (the same
 * seam the orchestrator's step worker calls) rather than reimplementing any
 * dispatch, permission, or HITL logic. This route only exists to let e2e
 * specs reach that seam over HTTP.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function ahentic_e2e_run_ability( WP_REST_Request $request ) {
	$name  = (string) $request->get_param( 'name' );
	$input = $request->get_param( 'input' );
	$input = is_array( $input ) ? $input : array();

	if ( ! class_exists( 'Ahentic_Abilities' ) ) {
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'error'   => 'ahentic_e2e_not_loaded',
				'message' => 'Ahentic_Abilities is not loaded — is the plugin active in this instance?',
			),
			200
		);
	}

	$result = Ahentic_Abilities::execute( $name, $input );

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'error'   => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => $result->get_error_data(),
			),
			200
		);
	}

	return new WP_REST_Response(
		array(
			'ok'   => true,
			'data' => $result,
		),
		200
	);
}

/** Option name backing the mocked-AI response queue. Not autoloaded. */
const AHENTIC_E2E_AI_QUEUE_OPTION = 'ahentic_e2e_ai_queue';

/**
 * Push canned AI responses onto the queue `pre_ahentic_ai_complete_chat`
 * (below) pops from. Order is preserved: first seeded, first consumed.
 *
 * @param WP_REST_Request $request Incoming request; `responses` is an array
 *                                 of strings (shorthand for `{ text }`) or
 *                                 partial `complete_chat()`-shaped arrays
 *                                 (e.g. `{ text, debug: { next: 'use_tools', tools_planned: [...] } }`).
 * @return WP_REST_Response
 */
function ahentic_e2e_seed_ai_responses( WP_REST_Request $request ) {
	$responses = $request->get_param( 'responses' );
	$responses = is_array( $responses ) ? $responses : array();

	// Replace (do not append): parallel specs must not share a growing global
	// queue. Each seedAiResponses() / startRun() owns the full upcoming turn
	// sequence; beforeEach reset + replace keeps the contract explicit.
	$queue = array();
	foreach ( $responses as $response ) {
		$queue[] = $response;
	}

	update_option( AHENTIC_E2E_AI_QUEUE_OPTION, $queue, false );

	return new WP_REST_Response(
		array(
			'ok'     => true,
			'queued' => count( $queue ),
		),
		200
	);
}

/**
 * Clear the mocked-AI response queue. Does not touch fixture data created via
 * `/seed` — a spec that wants a clean site should delete that explicitly.
 *
 * @return WP_REST_Response
 */
function ahentic_e2e_reset() {
	delete_option( AHENTIC_E2E_AI_QUEUE_OPTION );

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * Fill in the rest of a `complete_chat()`-shaped result from a partial one a
 * spec seeded, so `Ahentic_AI::complete_chat()` callers never see a missing
 * key regardless of how minimal the spec's fixture was.
 *
 * `Ahentic_AI::complete_chat()` returns a `pre_ahentic_ai_complete_chat`
 * override verbatim (see src/orchestrator/class-ai.php) — it does NOT run
 * `extract_debug_block()` on it the way the real provider paths do. A seeded
 * `text` containing a raw `<<<AHENTIC_DEBUG … AHENTIC_DEBUG>>>` block (see
 * `AhenticSidebar`'s `mockReply()` fixture helper) is parsed here instead, so
 * the orchestrator's control-block contract (control-block.md) is honored
 * exactly like a real model turn. Without this, `run_llm_with_debug()` sees
 * `debug: null`, treats the turn as unusable, and burns a retry attempt
 * against the (by-then empty) queue — silently falling through to a real,
 * unconfigured provider on attempt 2.
 *
 * @param mixed $partial String (shorthand for `{ text }`) or partial array.
 * @return array Full `complete_chat()`-shaped result.
 */
function ahentic_e2e_normalize_ai_result( $partial ) {
	if ( is_string( $partial ) ) {
		$partial = array( 'text' => $partial );
	} elseif ( ! is_array( $partial ) ) {
		$partial = array();
	}

	if ( ! array_key_exists( 'debug', $partial ) && ! empty( $partial['text'] ) && class_exists( 'Ahentic_AI' ) ) {
		$extracted             = Ahentic_AI::extract_debug_block( (string) $partial['text'] );
		$partial['text']       = $extracted['text'];
		$partial['debug']      = $extracted['debug'];
		$partial['truncated']  = ! empty( $extracted['truncated'] );
		if ( ! empty( $extracted['truncated_key'] ) ) {
			$partial['truncated_key'] = $extracted['truncated_key'];
		}
	}

	return array_merge(
		array(
			'text'             => '',
			'tokens_in'        => 0,
			'tokens_out'       => 0,
			'tokens_total'     => 0,
			'model'            => 'ahentic-e2e-mock',
			'debug'            => null,
			'truncated'        => false,
			'truncated_key'    => '',
			'debug_normalized' => null,
		),
		$partial
	);
}

/**
 * `pre_ahentic_ai_complete_chat` handler: pop the next queued response.
 *
 * Registered unconditionally by this mu-plugin, so the filter is only ever
 * hooked inside the e2e WordPress instance — production Ahentic never loads
 * this file, so `Ahentic_AI::complete_chat()` calls a real provider there
 * exactly as before. See src/orchestrator/class-ai.php.
 *
 * @param mixed $override Value from a higher-priority callback; passed
 *                         through unchanged if already non-null.
 * @return array|null Normalized mocked result, or null to fall through to a
 *                     real provider (an empty queue should not happen in a
 *                     well-formed spec, but must never fatal).
 */
function ahentic_e2e_ai_override( $override ) {
	if ( null !== $override ) {
		return $override;
	}

	$queue = get_option( AHENTIC_E2E_AI_QUEUE_OPTION, array() );
	if ( ! is_array( $queue ) || empty( $queue ) ) {
		return null;
	}

	$next = array_shift( $queue );
	update_option( AHENTIC_E2E_AI_QUEUE_OPTION, $queue, false );

	return ahentic_e2e_normalize_ai_result( $next );
}
add_filter( 'pre_ahentic_ai_complete_chat', 'ahentic_e2e_ai_override' );

/**
 * `pre_ahentic_ai_status` handler: always report the sidebar as ready to
 * generate, so the composer isn't disabled and browser-driven specs can send
 * real chat turns against the mocked `complete_chat()` above without an
 * actual AI plugin/connector installed. See src/admin/class-rest.php.
 *
 * @param mixed $override Value from a higher-priority callback; passed
 *                         through unchanged if already non-null.
 * @return array
 */
function ahentic_e2e_ai_status_override( $override ) {
	if ( null !== $override ) {
		return $override;
	}

	return array(
		'isReady'         => true,
		'hasConnector'    => true,
		'canGenerate'     => true,
		'requiredAbility' => 'core/read-content',
		'pluginSlug'      => 'ai',
		'pluginFile'      => 'ai/ai.php',
		'pluginInstalled' => true,
		'pluginActive'    => true,
		'canInstall'      => false,
		'pluginUrl'       => 'https://wordpress.org/plugins/ai/',
		'connectorsUrl'   => admin_url( 'options-connectors.php' ),
	);
}
add_filter( 'pre_ahentic_ai_status', 'ahentic_e2e_ai_status_override' );

/**
 * Seed WordPress fixture data (posts/users/options) so a spec doesn't need a
 * slow UI walk-through to reach a given state.
 *
 * @param WP_REST_Request $request Incoming request; any of `posts`, `users`
 *                                 (arrays of `wp_insert_post()` /
 *                                 `wp_insert_user()`-shaped arrays), and
 *                                 `options` (a `{ option_name: value }` map).
 * @return WP_REST_Response
 */
function ahentic_e2e_seed( WP_REST_Request $request ) {
	$posts   = $request->get_param( 'posts' );
	$users   = $request->get_param( 'users' );
	$options = $request->get_param( 'options' );

	$created = array(
		'posts'   => array(),
		'users'   => array(),
		'options' => array(),
	);

	if ( is_array( $posts ) ) {
		foreach ( $posts as $post_fixture ) {
			$post_id = wp_insert_post( wp_slash( (array) $post_fixture ), true );
			if ( is_wp_error( $post_id ) ) {
				return new WP_REST_Response(
					array(
						'ok'      => false,
						'error'   => 'ahentic_e2e_seed_post_failed',
						'message' => $post_id->get_error_message(),
					),
					200
				);
			}
			$created['posts'][] = $post_id;
		}
	}

	if ( is_array( $users ) ) {
		foreach ( $users as $user_fixture ) {
			$user_id = wp_insert_user( wp_slash( (array) $user_fixture ) );
			if ( is_wp_error( $user_id ) ) {
				return new WP_REST_Response(
					array(
						'ok'      => false,
						'error'   => 'ahentic_e2e_seed_user_failed',
						'message' => $user_id->get_error_message(),
					),
					200
				);
			}
			$created['users'][] = $user_id;
		}
	}

	if ( is_array( $options ) ) {
		foreach ( $options as $option_name => $option_value ) {
			update_option( (string) $option_name, $option_value );
			$created['options'][] = (string) $option_name;
		}
	}

	return new WP_REST_Response(
		array(
			'ok'      => true,
			'created' => $created,
		),
		200
	);
}
