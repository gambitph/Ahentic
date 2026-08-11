<?php
/**
 * Tool runner pipeline helpers owned by Ahentic_Tool_Runner.
 *
 * Pure helpers that must survive the Orchestrator → Tool runner ownership move
 * without behaviour change. Full run()/record_completed_result() paths stay in e2e.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Tool runner pipeline helper interface (trace, browser fallback).
 *
 * tool_error_payload needs WP_Error — covered in tests/wp-mocked/ToolErrorPayloadTest.php.
 */
class ToolRunnerPipelineTest extends TestCase {

	/**
	 * Load Tool runner (+ ability constants for fallback).
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$root = dirname( __DIR__, 2 );
		require_once $root . '/src/abilities/class-abilities-content.php';
		require_once $root . '/src/abilities/class-abilities-browser.php';
		require_once $root . '/src/orchestrator/class-prompt-assembler.php';
		require_once $root . '/src/orchestrator/class-tool-runner.php';
	}

	/**
	 * Oversized block trees are omitted from traces (count only).
	 */
	public function test_trace_tool_input_omits_blocks_body() {
		$out = Ahentic_Tool_Runner::trace_tool_input(
			array(
				'blocks' => array(
					array( 'name' => 'core/paragraph' ),
					array( 'name' => 'core/heading' ),
				),
				'title'  => 'Keep me',
			)
		);

		$this->assertSame( 'Keep me', $out['title'] );
		$this->assertTrue( $out['blocks']['_omitted'] );
		$this->assertSame( 2, $out['blocks']['count'] );
	}

	/**
	 * Long content strings are truncated for traces.
	 */
	public function test_trace_tool_input_excerpts_long_content() {
		$long = str_repeat( 'a', 400 );
		$out  = Ahentic_Tool_Runner::trace_tool_input( array( 'content' => $long ) );

		$this->assertLessThan( strlen( $long ), strlen( $out['content'] ) );
		$this->assertStringContainsString( '…', $out['content'] );
	}

	/**
	 * Local filesystem paths never appear in traces.
	 */
	public function test_trace_tool_input_redacts_source_path() {
		$out = Ahentic_Tool_Runner::trace_tool_input(
			array(
				'source_path' => '/Users/secret/photo.jpg',
			)
		);

		$this->assertSame( '[local]', $out['source_path'] );
	}

	/**
	 * set-blocks with a post_id in context falls back to update-post.
	 */
	public function test_server_fallback_set_blocks_to_update_post() {
		$fallback = Ahentic_Tool_Runner::server_fallback_for_browser(
			'ahentic-browser/set-blocks',
			array( 'from_memory' => 'draft_1' ),
			array( 'post_id' => 42 )
		);

		$this->assertIsArray( $fallback );
		$this->assertSame( 'ahentic/update-post', $fallback['name'] );
		$this->assertSame( 42, $fallback['input']['id'] );
		$this->assertSame( 'draft_1', $fallback['input']['from_memory'] );
	}

	/**
	 * update-post-title without a post creates via create-post.
	 */
	public function test_server_fallback_title_without_post_creates() {
		$fallback = Ahentic_Tool_Runner::server_fallback_for_browser(
			'ahentic-browser/update-post-title',
			array( 'title' => 'Hello' ),
			array()
		);

		$this->assertIsArray( $fallback );
		$this->assertSame( 'ahentic/create-post', $fallback['name'] );
		$this->assertSame( 'Hello', $fallback['input']['title'] );
	}

	/**
	 * update-post-document with a post id falls back to update-post.
	 */
	public function test_server_fallback_document_with_post_updates() {
		$fallback = Ahentic_Tool_Runner::server_fallback_for_browser(
			'ahentic-browser/update-post-document',
			array(
				'title'   => 'Hello',
				'excerpt' => 'Summary',
				'slug'    => 'hello',
			),
			array( 'post_id' => 42 )
		);

		$this->assertIsArray( $fallback );
		$this->assertSame( 'ahentic/update-post', $fallback['name'] );
		$this->assertSame( 42, $fallback['input']['id'] );
		$this->assertSame( 'Hello', $fallback['input']['title'] );
		$this->assertSame( 'Summary', $fallback['input']['excerpt'] );
		$this->assertSame( 'hello', $fallback['input']['slug'] );
	}

	/**
	 * Browser abilities without a server twin do not invent a fallback.
	 */
	public function test_server_fallback_null_for_unsupported() {
		$fallback = Ahentic_Tool_Runner::server_fallback_for_browser(
			'ahentic-browser/save-post',
			array(),
			array( 'post_id' => 9 )
		);

		$this->assertNull( $fallback );
	}
}
