<?php
/**
 * Orchestrator tool-call normalization: phantom ability name remaps.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Orchestrator::normalize_tool_calls().
 */
class OrchestratorNormalizeToolsTest extends TestCase {

	/**
	 * Load Orchestrator (normalize_tool_calls is pure).
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-orchestrator.php';
	}

	/**
	 * Models invent set-option by symmetry with get-option; remap to update-option.
	 */
	public function test_normalize_remaps_set_option_to_update_option() {
		$out = Ahentic_Orchestrator::normalize_tool_calls(
			array(
				array(
					'name'  => 'ahentic/set-option',
					'input' => array(
						'key'   => 'timezone_string',
						'value' => 'Asia/Manila',
					),
				),
			)
		);

		$this->assertSame( 'ahentic/update-option', $out[0]['name'] );
		$this->assertSame( 'timezone_string', $out[0]['input']['key'] );
		$this->assertSame( 'Asia/Manila', $out[0]['input']['value'] );
	}

	/**
	 * String-only planned tools also remap.
	 */
	public function test_normalize_remaps_set_option_string_item() {
		$out = Ahentic_Orchestrator::normalize_tool_calls( array( 'ahentic/set-option' ) );
		$this->assertSame( 'ahentic/update-option', $out[0]['name'] );
		$this->assertSame( array(), $out[0]['input'] );
	}

	/**
	 * Real update-option calls are unchanged.
	 */
	public function test_normalize_leaves_update_option_alone() {
		$out = Ahentic_Orchestrator::normalize_tool_calls(
			array(
				array(
					'name'  => 'ahentic/update-option',
					'input' => array( 'key' => 'blogname', 'value' => 'Hi' ),
				),
			)
		);
		$this->assertSame( 'ahentic/update-option', $out[0]['name'] );
	}
}
