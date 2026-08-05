<?php
/**
 * E2E-only test harness: runs an Ahentic ability over REST via wp-env.
 *
 * Ahentic abilities are deliberately kept off the public Abilities REST run
 * route (`meta.show_in_rest => false` — see src/abilities/abilities.md); this
 * file is NOT part of the plugin, is never packaged (see scripts/package.js),
 * and only exists inside the wp-env "tests" container via the "mu-plugins"
 * mapping in .wp-env.tests.json (a separate environment from the
 * .wp-env.json one a contributor might have open locally — see
 * tests/e2e/README.md). It gives Playwright a fast, deterministic way to
 * call `Ahentic_Abilities::execute()` — the same dispatch the orchestrator
 * itself uses — without driving a real LLM through the sidebar chat loop.
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
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
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
						'ok'                => true,
						'abilities_loaded'  => class_exists( 'Ahentic_Abilities' ),
						'abilities_api'     => function_exists( 'wp_get_ability' ),
					);
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}
);

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
				'message' => 'Ahentic_Abilities is not loaded — is the plugin active in this wp-env instance?',
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
