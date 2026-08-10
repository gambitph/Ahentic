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
					'name'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'input'      => array(
						'type'     => 'object',
						'required' => false,
					),
					'session_id' => array(
						'type'     => 'integer',
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

		register_rest_route(
			'ahentic-e2e/v1',
			'/seed-ai-status-flake',
			array(
				'methods'             => 'POST',
				'callback'            => 'ahentic_e2e_seed_ai_status_flake',
				'permission_callback' => 'ahentic_e2e_permission_check',
				'args'                => array(
					'count' => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => 1,
					),
				),
			)
		);

		register_rest_route(
			'ahentic-e2e/v1',
			'/inspect-attachment',
			array(
				'methods'             => 'GET',
				'callback'            => 'ahentic_e2e_inspect_attachment',
				'permission_callback' => 'ahentic_e2e_permission_check',
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}
);

/**
 * Inspect an attachment's status / metadata / on-disk file for media e2e asserts.
 *
 * @param WP_REST_Request $request Incoming request with `id`.
 * @return WP_REST_Response
 */
function ahentic_e2e_inspect_attachment( WP_REST_Request $request ) {
	$id   = (int) $request->get_param( 'id' );
	$post = get_post( $id );
	if ( ! ( $post instanceof WP_Post ) || 'attachment' !== $post->post_type ) {
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'error'   => 'ahentic_e2e_attachment_missing',
				'message' => 'Attachment not found.',
			),
			200
		);
	}

	$file = get_attached_file( $id );
	$md5  = ( is_string( $file ) && $file && file_exists( $file ) ) ? md5_file( $file ) : '';

	return new WP_REST_Response(
		array(
			'ok'            => true,
			'id'            => $id,
			'status'        => (string) $post->post_status,
			'title'         => get_the_title( $post ),
			'caption'       => (string) $post->post_excerpt,
			'description'   => (string) $post->post_content,
			'alt_text'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'file'          => is_string( $file ) ? $file : '',
			'file_exists'   => is_string( $file ) && $file && file_exists( $file ),
			'file_md5'      => $md5 ? $md5 : '',
			'media_trash'   => defined( 'MEDIA_TRASH' ) ? (bool) MEDIA_TRASH : null,
			'thumbnail_for' => array(),
		),
		200
	);
}

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
 * Deliberately thin: it delegates to `Ahentic_Abilities::execute()` (ability
 * dispatch — what the Tool runner calls after HITL / browser / from_memory)
 * rather than reimplementing the Tool runner pipeline. This route only exists
 * to let e2e specs reach that dispatch seam over HTTP.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function ahentic_e2e_run_ability( WP_REST_Request $request ) {
	$name       = (string) $request->get_param( 'name' );
	$input      = $request->get_param( 'input' );
	$input      = is_array( $input ) ? $input : array();
	$session_id = (int) $request->get_param( 'session_id' );

	// Optional e2e-only constant for delete-media MEDIA_TRASH acceptance.
	$media_trash = $request->get_param( 'define_media_trash' );
	if ( null !== $media_trash && ! defined( 'MEDIA_TRASH' ) ) {
		define( 'MEDIA_TRASH', (bool) $media_trash );
	}

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

	$run = static function () use ( $name, $input ) {
		return Ahentic_Abilities::execute( $name, $input );
	};

	if ( $session_id > 0 && class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'with_current_session' ) ) {
		$result = Ahentic_Orchestrator::with_current_session( $session_id, $run );
	} else {
		$result = $run();
	}

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
 * Read the mocked-AI queue (supports legacy bare arrays and { generation, items }).
 *
 * @return array{generation:int,items:array} Normalized queue document.
 */
function ahentic_e2e_ai_queue_read() {
	$raw = get_option( AHENTIC_E2E_AI_QUEUE_OPTION, array() );
	if ( is_array( $raw ) && array_key_exists( 'items', $raw ) ) {
		return array(
			'generation' => isset( $raw['generation'] ) ? (int) $raw['generation'] : 0,
			'items'      => is_array( $raw['items'] ) ? array_values( $raw['items'] ) : array(),
		);
	}
	return array(
		'generation' => 0,
		'items'      => is_array( $raw ) ? array_values( $raw ) : array(),
	);
}

/**
 * Persist the mocked-AI queue document.
 *
 * @param array{generation:int,items:array} $doc Queue document.
 * @return void
 */
function ahentic_e2e_ai_queue_write( array $doc ) {
	update_option(
		AHENTIC_E2E_AI_QUEUE_OPTION,
		array(
			'generation' => isset( $doc['generation'] ) ? (int) $doc['generation'] : 0,
			'items'      => isset( $doc['items'] ) && is_array( $doc['items'] ) ? array_values( $doc['items'] ) : array(),
		),
		false
	);
}

const AHENTIC_E2E_AI_STATUS_FALSE_REMAINING = 'ahentic_e2e_ai_status_false_remaining';

/**
 * Seed N upcoming `build_status_payload()` calls to report hasConnector=false.
 * Used to reproduce localize-time false negatives; the next calls return ready.
 *
 * @param WP_REST_Request $request Incoming request with optional `count`.
 * @return WP_REST_Response
 */
function ahentic_e2e_seed_ai_status_flake( WP_REST_Request $request ) {
	$count = (int) $request->get_param( 'count' );
	if ( $count < 0 ) {
		$count = 0;
	}
	update_option( AHENTIC_E2E_AI_STATUS_FALSE_REMAINING, $count, false );

	return new WP_REST_Response(
		array(
			'ok'    => true,
			'count' => $count,
		),
		200
	);
}

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

	// Replace (do not append): bump generation so in-flight pops from a stale
	// read cannot rewrite a newer seed on reused Playground instances.
	$doc   = ahentic_e2e_ai_queue_read();
	$queue = array();
	foreach ( $responses as $response ) {
		$queue[] = $response;
	}
	ahentic_e2e_ai_queue_write(
		array(
			'generation' => (int) $doc['generation'] + 1,
			'items'      => $queue,
		)
	);

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
	delete_option( AHENTIC_E2E_AI_STATUS_FALSE_REMAINING );

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

	$doc = ahentic_e2e_ai_queue_read();
	if ( empty( $doc['items'] ) ) {
		return null;
	}

	$generation = (int) $doc['generation'];
	$next       = array_shift( $doc['items'] );
	// Re-read: if a seed bumped generation mid-flight, do not clobber the new queue.
	$latest = ahentic_e2e_ai_queue_read();
	if ( (int) $latest['generation'] === $generation ) {
		ahentic_e2e_ai_queue_write(
			array(
				'generation' => $generation,
				'items'      => $doc['items'],
			)
		);
	}

	// Specs may queue a transport-style failure without falling through to a real provider.
	if ( is_array( $next ) && isset( $next['__wp_error'] ) && is_array( $next['__wp_error'] ) ) {
		$err = $next['__wp_error'];
		$code = isset( $err['code'] ) ? (string) $err['code'] : 'ahentic_e2e_ai_error';
		$msg  = isset( $err['message'] ) ? (string) $err['message'] : 'E2E AI error';
		return new WP_Error( $code, $msg );
	}

	return ahentic_e2e_normalize_ai_result( $next );
}
add_filter( 'pre_ahentic_ai_complete_chat', 'ahentic_e2e_ai_override' );

/**
 * Always mock vision in the e2e Playground (no real provider).
 *
 * @param mixed  $override Existing override.
 * @param string $file_or_url Unused.
 * @param string $mime_type Unused.
 * @return array
 */
function ahentic_e2e_describe_image_override( $override, $file_or_url = '', $mime_type = '' ) {
	unset( $file_or_url, $mime_type );
	if ( null !== $override ) {
		return $override;
	}
	return array(
		'description'         => 'A solid blue square used in Ahentic e2e tests.',
		'alt_text_suggestion' => 'Blue square',
	);
}
add_filter( 'pre_ahentic_ai_describe_image', 'ahentic_e2e_describe_image_override', 10, 3 );

/**
 * Always mock image generation in the e2e Playground (1×1 PNG).
 *
 * @param mixed  $override Existing override.
 * @param string $prompt Unused.
 * @param string $aspect_ratio Unused.
 * @return array
 */
function ahentic_e2e_generate_image_override( $override, $prompt = '', $aspect_ratio = '' ) {
	unset( $aspect_ratio );
	if ( null !== $override ) {
		return $override;
	}
	// Distinct 1×1 blue PNG when prompt requests e2e blue (for replace-media-file).
	$b64 = ( false !== strpos( (string) $prompt, '__e2e_blue__' ) )
		? 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
		: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	return array(
		'data_uri'  => 'data:image/png;base64,' . $b64,
		'mime_type' => 'image/png',
		'width'     => 1,
		'height'    => 1,
	);
}
add_filter( 'pre_ahentic_ai_generate_image', 'ahentic_e2e_generate_image_override', 10, 3 );

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

	$remaining = (int) get_option( AHENTIC_E2E_AI_STATUS_FALSE_REMAINING, 0 );
	if ( $remaining > 0 ) {
		update_option( AHENTIC_E2E_AI_STATUS_FALSE_REMAINING, $remaining - 1, false );

		return array(
			'isReady'         => true,
			'hasConnector'    => false,
			'canGenerate'     => false,
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
 * Seed WordPress fixture data (posts/users/options/attachments) so a spec doesn't need a
 * slow UI walk-through to reach a given state.
 *
 * @param WP_REST_Request $request Incoming request; any of `posts`, `users`
 *                                 (arrays of `wp_insert_post()` /
 *                                 `wp_insert_user()`-shaped arrays),
 *                                 `options` (a `{ option_name: value }` map),
 *                                 and `attachments` (sideload fixtures).
 * @return WP_REST_Response
 */
function ahentic_e2e_seed( WP_REST_Request $request ) {
	$posts        = $request->get_param( 'posts' );
	$users        = $request->get_param( 'users' );
	$options      = $request->get_param( 'options' );
	$attachments  = $request->get_param( 'attachments' );
	$page_context = $request->get_param( 'page_context' );
	$session_id   = (int) $request->get_param( 'session_id' );

	$created = array(
		'posts'       => array(),
		'users'       => array(),
		'options'     => array(),
		'attachments' => array(),
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

	if ( is_array( $attachments ) ) {
		foreach ( $attachments as $attachment_fixture ) {
			$attachment_id = ahentic_e2e_seed_attachment( is_array( $attachment_fixture ) ? $attachment_fixture : array() );
			if ( is_wp_error( $attachment_id ) ) {
				return new WP_REST_Response(
					array(
						'ok'      => false,
						'error'   => 'ahentic_e2e_seed_attachment_failed',
						'message' => $attachment_id->get_error_message(),
					),
					200
				);
			}
			$created['attachments'][] = (int) $attachment_id;
		}
	}

	if ( is_array( $page_context ) && $session_id > 0 && class_exists( 'Ahentic_Session_Repository' ) ) {
		Ahentic_Session_Repository::set_page_context( $session_id, $page_context );
		$created['page_context_session'] = $session_id;
	}

	return new WP_REST_Response(
		array(
			'ok'      => true,
			'created' => $created,
		),
		200
	);
}

/**
 * Sideload a tiny PNG attachment for media e2e fixtures.
 *
 * @param array $fixture Optional title, alt_text, caption, description, filename, bytes_base64.
 * @return int|\WP_Error Attachment ID.
 */
function ahentic_e2e_seed_attachment( array $fixture ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Default: 1×1 red PNG.
	$default_b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	$b64         = isset( $fixture['bytes_base64'] ) && is_string( $fixture['bytes_base64'] ) && '' !== $fixture['bytes_base64']
		? $fixture['bytes_base64']
		: $default_b64;
	$bytes       = base64_decode( $b64, true );
	if ( false === $bytes || '' === $bytes ) {
		return new WP_Error( 'ahentic_e2e_bad_png', 'Invalid bytes_base64 for attachment seed.' );
	}

	$filename = isset( $fixture['filename'] ) ? sanitize_file_name( (string) $fixture['filename'] ) : '';
	if ( '' === $filename ) {
		$filename = 'ahentic-e2e-' . wp_generate_password( 8, false, false ) . '.png';
	}
	if ( ! preg_match( '/\.(png|jpe?g|gif|webp)$/i', $filename ) ) {
		$filename .= '.png';
	}

	$tmp = wp_tempnam( $filename );
	if ( ! $tmp ) {
		return new WP_Error( 'ahentic_e2e_temp_failed', 'Could not create temp file for attachment seed.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === file_put_contents( $tmp, $bytes ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $tmp );
		return new WP_Error( 'ahentic_e2e_write_failed', 'Could not write attachment seed bytes.' );
	}

	$title = isset( $fixture['title'] ) ? sanitize_text_field( (string) $fixture['title'] ) : 'E2E media';
	$file_array = array(
		'name'     => $filename,
		'tmp_name' => $tmp,
	);

	$attachment_id = media_handle_sideload( $file_array, 0, $title );
	if ( is_wp_error( $attachment_id ) ) {
		if ( file_exists( $tmp ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp );
		}
		return $attachment_id;
	}

	$attachment_id = (int) $attachment_id;
	$update        = array( 'ID' => $attachment_id );
	if ( isset( $fixture['caption'] ) ) {
		$update['post_excerpt'] = sanitize_textarea_field( (string) $fixture['caption'] );
	}
	if ( isset( $fixture['description'] ) ) {
		$update['post_content'] = wp_kses_post( (string) $fixture['description'] );
	}
	if ( count( $update ) > 1 ) {
		wp_update_post( $update );
	}
	if ( isset( $fixture['alt_text'] ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $fixture['alt_text'] ) );
	}

	return $attachment_id;
}

/**
 * E2E-only stub settings write: non-preallowable + snapshotted option mutate.
 *
 * Proves Track A plumbing (HITL non-preallowable + snapshot/undo) without
 * shipping Track C/D/E product abilities. Never packaged with the plugin.
 */
if ( ! class_exists( 'Ahentic_E2E_Stub_Settings_Write' ) ) {
	/**
	 * Stub ability module for Playwright Task 01 coverage.
	 */
	class Ahentic_E2E_Stub_Settings_Write {
		const WRITE  = 'ahentic-e2e/stub-settings-write';
		const OPTION = 'ahentic_e2e_stub_setting';

		/**
		 * @return string[]
		 */
		public static function names() {
			return array( self::WRITE );
		}

		/**
		 * @return string[]
		 */
		public static function write_names() {
			return array( self::WRITE );
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_readonly( $name ) {
			return ! in_array( (string) $name, self::write_names(), true );
		}

		/**
		 * @return string[]
		 */
		public static function hitl_names() {
			return array( self::WRITE );
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function requires_hitl( $name ) {
			return in_array( (string) $name, self::hitl_names(), true );
		}

		/**
		 * @return string[]
		 */
		public static function non_preallowable_names() {
			return array( self::WRITE );
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_non_preallowable( $name ) {
			return in_array( (string) $name, self::non_preallowable_names(), true );
		}

		/**
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function hitl_summary( $name, $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$value = isset( $input['value'] ) ? (string) $input['value'] : '';
			return sprintf(
				/* translators: %s: new stub value */
				__( 'E2E stub: set test setting to “%s”', 'ahentic' ),
				$value
			);
		}

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			if ( self::WRITE === (string) $name ) {
				return __( 'Updating e2e stub setting…', 'ahentic' );
			}
			return '';
		}

		/**
		 * Wire restore for undo-last-actions.
		 */
		public static function boot_restore() {
			if ( ! class_exists( 'Ahentic_Settings_Snapshots' ) ) {
				return;
			}
			Ahentic_Settings_Snapshots::register_restore(
				self::WRITE,
				static function ( array $entry ) {
					$target = isset( $entry['target'] ) ? (string) $entry['target'] : self::OPTION;
					if ( empty( $entry['prior_existed'] ) ) {
						delete_option( $target );
						return true;
					}
					$prior = array_key_exists( 'prior_value', $entry ) ? $entry['prior_value'] : null;
					update_option( $target, $prior );
					return true;
				}
			);
		}

		/**
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			$name  = (string) $name;
			$input = is_array( $input ) ? $input : array();
			if ( self::WRITE !== $name ) {
				return new WP_Error( 'ahentic_ability_unknown', 'Unknown e2e stub ability.' );
			}

			$value = array_key_exists( 'value', $input ) ? $input['value'] : null;
			if ( null === $value ) {
				return new WP_Error( 'ahentic_e2e_stub_missing_value', 'value is required.' );
			}

			$session_id = 0;
			if ( class_exists( 'Ahentic_Orchestrator' ) && method_exists( 'Ahentic_Orchestrator', 'current_session_id' ) ) {
				$session_id = (int) Ahentic_Orchestrator::current_session_id();
			}

			$option = self::OPTION;
			if ( $session_id ) {
				$option = self::OPTION . '_' . $session_id;
			}

			$prior_existed = false;
			$prior_value   = null;
			// get_option default sentinel — distinguish missing vs empty string.
			$sentinel = new stdClass();
			$current  = get_option( $option, $sentinel );
			if ( $sentinel !== $current ) {
				$prior_existed = true;
				$prior_value   = $current;
			}

			if ( $session_id && class_exists( 'Ahentic_Settings_Snapshots' ) ) {
				Ahentic_Settings_Snapshots::record(
					$session_id,
					array(
						'ability'       => self::WRITE,
						'target'        => $option,
						'prior_existed' => $prior_existed,
						'prior_value'   => $prior_value,
					)
				);
			}

			update_option( $option, $value );

			return array(
				'ok'            => true,
				'option'        => $option,
				'value'         => $value,
				'prior_existed' => $prior_existed,
			);
		}
	}

	add_action(
		'plugins_loaded',
		static function () {
			if ( ! class_exists( 'Ahentic_Abilities' ) ) {
				return;
			}
			Ahentic_E2E_Stub_Settings_Write::boot_restore();
			Ahentic_Abilities::register_module( 'Ahentic_E2E_Stub_Settings_Write' );
		},
		20
	);
}

/**
 * Opt the Ahentic sidebar bundle into Playwright hooks (`window.__ahenticE2E`).
 * Must run before `ahentic-script` evaluates (inline `before`).
 */
add_action(
	'admin_enqueue_scripts',
	static function () {
		if ( ! wp_script_is( 'ahentic-script', 'enqueued' ) && ! wp_script_is( 'ahentic-script', 'registered' ) ) {
			return;
		}
		wp_add_inline_script( 'ahentic-script', 'window.__AHENTIC_E2E__=true;', 'before' );
	},
	100
);
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! wp_script_is( 'ahentic-script', 'enqueued' ) && ! wp_script_is( 'ahentic-script', 'registered' ) ) {
			return;
		}
		wp_add_inline_script( 'ahentic-script', 'window.__AHENTIC_E2E__=true;', 'before' );
	},
	100
);
