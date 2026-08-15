<?php
/**
 * Connector readiness status - probe outcomes and last-known-good cache.
 *
 * The sidebar "Add an AI connector…" copy is driven by
 * `window.ahentic.aiPlugin.hasConnector`, which is localized from
 * `Ahentic_REST::build_status_payload()`. That flag comes from a live
 * `is_supported_for_text_generation()` probe that can throw on slow
 * networks. Thrown failures are `unknown` (null), not "no connector";
 * a short last-known-good cache skips the remote probe on warm hits and
 * keeps the composer green across flakes. Soft-false (cold probe returns
 * false) remains confirmed missing and clears the cache.
 *
 * @package Ahentic
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once __DIR__ . '/WP_Mocked_TestCase.php';

/**
 * Pins status → hasConnector mapping for probe true / false / unknown + cache.
 */
class AiPluginStatusConnectorTest extends WP_Mocked_TestCase {

	/**
	 * In-memory stand-in for get/set/delete_transient in this test class.
	 *
	 * @var array<string, mixed>
	 */
	private $transients = array();

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->transients = array();
		Ahentic_Test_Prompt_Builder::reset();
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
	 * @param mixed $cached_text_gen Value returned from get_transient for the text-gen cache key.
	 * @return void
	 */
	private function stub_status_wordpress_bits( $cached_text_gen = false ) {
		Filters\expectApplied( 'ahentic_pre_ai_status' )->andReturn( null );
		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'get_plugins' )->justReturn( array( 'ai/ai.php' => array() ) );
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/options-connectors.php' );

		$this->transients[ Ahentic_REST::TEXT_GEN_CACHE_KEY ] = $cached_text_gen;

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients )
					? $this->transients[ $key ]
					: false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		// Do not stub file_exists() - Patchwork needs redefinable-internals for it.
	}

	/**
	 * Soft-false from a cold probe localizes as hasConnector=false
	 * (confirmed missing - show the Connectors CTA).
	 */
	public function test_soft_false_support_probe_maps_to_has_connector_false() {
		Ahentic_Test_Prompt_Builder::$supported = false;
		$this->stub_status_wordpress_bits( false );

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
		$this->assertSame( 'missing', $payload['connectorStatus'] );
		$this->assertTrue(
			empty( $this->transients[ Ahentic_REST::TEXT_GEN_CACHE_KEY ] ),
			'Confirmed missing must clear last-known-good cache'
		);
	}

	/**
	 * Warm cache skips the live probe entirely.
	 */
	public function test_warm_cache_short_circuits_live_probe() {
		Ahentic_Test_Prompt_Builder::$supported = false;
		$this->stub_status_wordpress_bits( true );

		$payload = Ahentic_REST::build_status_payload();

		$this->assertTrue( $payload['hasConnector'] );
		$this->assertTrue( $payload['canGenerate'] );
		$this->assertSame( 'ready', $payload['connectorStatus'] );
		$this->assertSame(
			0,
			Ahentic_Test_Prompt_Builder::$probe_calls,
			'Warm cache must not call the live support probe'
		);
	}

	/**
	 * Thrown probe errors are unknown, not "no connector".
	 */
	public function test_thrown_support_probe_maps_to_has_connector_unknown_without_cache() {
		Ahentic_Test_Prompt_Builder::$supported = new \RuntimeException( 'simulated network / SDK failure' );
		$this->stub_status_wordpress_bits( false );

		$this->assertFalse(
			Ahentic_REST::has_text_generation(),
			'Unknown without cache must not claim text generation'
		);

		$payload = Ahentic_REST::build_status_payload();
		$this->assertTrue( $payload['isReady'] );
		$this->assertNull(
			$payload['hasConnector'],
			'Thrown probe must localize as hasConnector=null (unknown)'
		);
		$this->assertFalse( $payload['canGenerate'] );
		$this->assertSame( 'unknown', $payload['connectorStatus'] );
	}

	/**
	 * Thrown probe with last-known-good keeps the composer green via cache hit.
	 */
	public function test_thrown_support_probe_uses_last_known_good_cache() {
		Ahentic_Test_Prompt_Builder::$supported = new \RuntimeException( 'simulated network / SDK failure' );
		$this->stub_status_wordpress_bits( true );

		$this->assertTrue(
			Ahentic_REST::has_text_generation(),
			'Warm cache must keep has_text_generation() true without probing'
		);

		$payload = Ahentic_REST::build_status_payload();
		$this->assertTrue( $payload['isReady'] );
		$this->assertTrue(
			$payload['hasConnector'],
			'Warm cache must keep hasConnector true'
		);
		$this->assertTrue( $payload['canGenerate'] );
		$this->assertSame( 'ready', $payload['connectorStatus'] );
		$this->assertSame( 0, Ahentic_Test_Prompt_Builder::$probe_calls );
	}

	/**
	 * Control: a true probe localizes as ready to chat and refreshes the cache.
	 */
	public function test_true_support_probe_maps_to_has_connector_true() {
		Ahentic_Test_Prompt_Builder::$supported = true;
		$this->stub_status_wordpress_bits( false );

		$payload = Ahentic_REST::build_status_payload();
		$this->assertTrue( $payload['isReady'] );
		$this->assertTrue( $payload['hasConnector'] );
		$this->assertTrue( $payload['canGenerate'] );
		$this->assertSame( 'ready', $payload['connectorStatus'] );
		$this->assertTrue( $this->transients[ Ahentic_REST::TEXT_GEN_CACHE_KEY ] );
	}
}
