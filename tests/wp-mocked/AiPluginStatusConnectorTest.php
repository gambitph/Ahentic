<?php
/**
 * Connector readiness status — false-negative feedback loop.
 *
 * The sidebar "Add an AI connector…" copy is driven by
 * `window.ahentic.aiPlugin.hasConnector`, which is localized from
 * `Ahentic_REST::build_status_payload()`. That flag is a live
 * `is_supported_for_text_generation()` probe and can false-negative.
 * The sidebar fetches `GET /ai-plugin/status` once on mount to recover
 * (see sidebar.js `syncAiPluginStatus`; upgrade-only, no open/focus retries);
 * these tests pin the PHP mapping that makes a bad boot value possible.
 *
 * @package Ahentic
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once __DIR__ . '/WP_Mocked_TestCase.php';

/**
 * Test double returned from wp_ai_client_prompt().
 */
class Ahentic_Test_Prompt_Builder {
	/** @var bool|\Throwable|callable */
	public static $supported = true;

	/**
	 * @return bool
	 */
	public function is_supported_for_text_generation() {
		if ( is_callable( self::$supported ) ) {
			return (bool) call_user_func( self::$supported );
		}
		if ( self::$supported instanceof \Throwable ) {
			throw self::$supported;
		}
		return (bool) self::$supported;
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * @param string $prompt Prompt text.
	 * @return Ahentic_Test_Prompt_Builder
	 */
	function wp_ai_client_prompt( $prompt ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return new Ahentic_Test_Prompt_Builder();
	}
}

/**
 * Pins the status → hasConnector mapping for localize-time false negatives.
 */
class AiPluginStatusConnectorTest extends WP_Mocked_TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Ahentic_Test_Prompt_Builder::$supported = true;
		Functions\stubTranslationFunctions();
		// class-rest.php calls Ahentic_REST::instance() → add_action on load.
		Functions\when( 'add_action' )->justReturn( true );
		if ( ! class_exists( 'Ahentic_REST', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/src/admin/class-rest.php';
		}
	}

	/**
	 * Stub WordPress helpers used by build_status_payload() outside the AI probe.
	 *
	 * @return void
	 */
	private function stub_status_wordpress_bits() {
		Filters\expectApplied( 'pre_ahentic_ai_status' )->andReturn( null );
		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'get_plugins' )->justReturn( array( 'ai/ai.php' => array() ) );
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/options-connectors.php' );
		// Do not stub file_exists() — Patchwork needs redefinable-internals for it.
	}

	/**
	 * Soft-false from the live support probe localizes as hasConnector=false
	 * (the boot value that triggers "Add an AI connector" until re-fetch).
	 */
	public function test_soft_false_support_probe_maps_to_has_connector_false() {
		Ahentic_Test_Prompt_Builder::$supported = false;
		$this->stub_status_wordpress_bits();

		$this->assertFalse(
			Ahentic_REST::has_text_generation(),
			'Support probe soft-false must make has_text_generation() false'
		);

		$payload = Ahentic_REST::build_status_payload();

		$this->assertTrue( $payload['isReady'], 'AI must still look ready' );
		$this->assertFalse(
			$payload['hasConnector'],
			'Soft-false probe must localize as hasConnector=false'
		);
		$this->assertFalse( $payload['canGenerate'] );
	}

	/**
	 * Thrown probe errors are swallowed to false — same localize gate.
	 */
	public function test_thrown_support_probe_maps_to_has_connector_false() {
		Ahentic_Test_Prompt_Builder::$supported = new \RuntimeException( 'simulated network / SDK failure' );
		$this->stub_status_wordpress_bits();

		$this->assertFalse( Ahentic_REST::has_text_generation() );

		$payload = Ahentic_REST::build_status_payload();
		$this->assertTrue( $payload['isReady'] );
		$this->assertFalse(
			$payload['hasConnector'],
			'Thrown probe is swallowed and localized as hasConnector=false'
		);
	}

	/**
	 * Control: a true probe localizes as ready to chat.
	 */
	public function test_true_support_probe_maps_to_has_connector_true() {
		Ahentic_Test_Prompt_Builder::$supported = true;
		$this->stub_status_wordpress_bits();

		$payload = Ahentic_REST::build_status_payload();
		$this->assertTrue( $payload['isReady'] );
		$this->assertTrue( $payload['hasConnector'] );
		$this->assertTrue( $payload['canGenerate'] );
	}
}
