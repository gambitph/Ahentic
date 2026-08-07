<?php
/**
 * Characterization tests for Ahentic_Prompt_Assembler (move-only extract from Orchestrator).
 *
 * Pure seams: build_chat_payload, truncate_tool_result_for_prompt, excerpt.
 */

use PHPUnit\Framework\TestCase;

class PromptAssemblerTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-prompt-assembler.php';
	}

	public function test_excerpt_truncates_with_ellipsis() {
		$long = str_repeat( 'a', 50 );
		$out  = Ahentic_Prompt_Assembler::excerpt( $long, 10 );
		$this->assertLessThan( strlen( $long ), strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
	}

	public function test_truncate_tool_result_respects_cap() {
		$body = str_repeat( 'x', 100 );
		$out  = Ahentic_Prompt_Assembler::truncate_tool_result_for_prompt( $body, 20 );
		$this->assertLessThan( strlen( $body ), strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
		// Cap is applied to the ASCII body slice; ellipsis may add UTF-8 bytes.
		$this->assertStringStartsWith( str_repeat( 'x', 19 ), $out );
	}

	public function test_build_chat_payload_appends_trailing_tool_results_to_user() {
		$entries = array(
			array(
				'role'    => 'user',
				'content' => 'List plugins',
			),
			array(
				'role'    => 'tool',
				'content' => '{"ok":true,"plugins":[]}',
				'meta'    => array( 'ability' => 'ahentic/list-plugins' ),
			),
		);

		$built = Ahentic_Prompt_Assembler::build_chat_payload( $entries );

		$this->assertSame( array(), $built['history'] );
		$this->assertStringContainsString( 'List plugins', $built['user'] );
		$this->assertStringContainsString( '[Ability result: ahentic/list-plugins]', $built['user'] );
		$this->assertStringContainsString( '{"ok":true,"plugins":[]}', $built['user'] );
		$this->assertSame( 0, $built['superseded'] );
	}

	public function test_build_chat_payload_collapses_superseded_live_editor_snapshots() {
		$entries = array(
			array(
				'role'    => 'user',
				'content' => 'Edit the post',
			),
			array(
				'role'    => 'tool',
				'content' => '{"blocks":"old"}',
				'meta'    => array( 'ability' => 'ahentic-browser/get-blocks' ),
			),
			array(
				'role'    => 'tool',
				'content' => '{"blocks":"new"}',
				'meta'    => array( 'ability' => 'ahentic-browser/get-blocks' ),
			),
		);

		$built = Ahentic_Prompt_Assembler::build_chat_payload( $entries );

		$this->assertStringContainsString( 'Superseded', $built['user'] );
		$this->assertStringContainsString( '{"blocks":"new"}', $built['user'] );
		$this->assertSame( 1, $built['superseded'] );
	}
}
