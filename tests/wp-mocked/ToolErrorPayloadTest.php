<?php
/**
 * Tool runner error payload shaping (needs WP_Error class stub).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Tool_Runner::tool_error_payload().
 */
class ToolErrorPayloadTest extends TestCase {

	/**
	 * Load Tool runner after WP_Error stub (bootstrap already required stubs.php).
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-prompt-assembler.php';
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-tool-runner.php';
	}

	/**
	 * WP_Error becomes the agent-facing tool payload (code + message + recovery data).
	 */
	public function test_tool_error_payload_surfaces_recovery_fields() {
		$error = new WP_Error(
			'ahentic_browser_timeout',
			'Browser runtime timed out.',
			array(
				'status'   => 408,
				'fallback' => array(
					'name'  => 'ahentic/update-post',
					'input' => array( 'id' => 12 ),
				),
			)
		);

		$payload = Ahentic_Tool_Runner::tool_error_payload( $error );

		$this->assertFalse( $payload['ok'] );
		$this->assertSame( 'ahentic_browser_timeout', $payload['error'] );
		$this->assertSame( 'Browser runtime timed out.', $payload['message'] );
		$this->assertArrayNotHasKey( 'status', $payload );
		$this->assertSame( 'ahentic/update-post', $payload['fallback']['name'] );
	}
}
