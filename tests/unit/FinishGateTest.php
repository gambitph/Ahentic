<?php
/**
 * Finish gate pure helpers: artifact apply detection, forced tools, thin-body arithmetic.
 *
 * Full evaluate_reply / assess_write_payload paths stay in e2e (session meta).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Finish_Gate pure decision helpers (ADR-0003).
 */
class FinishGateTest extends TestCase {

	/**
	 * Load Finish gate + ability name constants.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$root = dirname( __DIR__, 2 );
		require_once $root . '/src/abilities/class-abilities-content.php';
		require_once $root . '/src/abilities/class-abilities-browser.php';
		require_once $root . '/src/orchestrator/class-finish-gate.php';
	}

	/**
	 * A planned set-blocks with from_memory matching a ready key counts as apply.
	 */
	public function test_planned_includes_set_blocks_from_memory() {
		$planned = array(
			array(
				'name'  => 'ahentic-browser/set-blocks',
				'input' => array( 'from_memory' => 'draft_a' ),
			),
		);

		$this->assertTrue(
			Ahentic_Finish_Gate::planned_includes_artifact_apply( $planned, array( 'draft_a' ) )
		);
	}

	/**
	 * Wrong key or non-apply ability does not count.
	 */
	public function test_planned_excludes_wrong_key_or_readonly() {
		$planned = array(
			array(
				'name'  => 'ahentic-browser/set-blocks',
				'input' => array( 'from_memory' => 'other' ),
			),
			array(
				'name'  => 'ahentic/list-plugins',
				'input' => array(),
			),
		);

		$this->assertFalse(
			Ahentic_Finish_Gate::planned_includes_artifact_apply( $planned, array( 'draft_a' ) )
		);
	}

	/**
	 * Editor open → force set-blocks from_memory.
	 */
	public function test_forced_apply_prefers_editor_set_blocks() {
		$tools = Ahentic_Finish_Gate::forced_apply_tools_for_context(
			array( 'draft_1' ),
			array(
				'is_block_editor' => true,
				'post_id'         => 9,
			)
		);

		$this->assertCount( 1, $tools );
		$this->assertSame( 'ahentic-browser/set-blocks', $tools[0]['name'] );
		$this->assertSame( 'draft_1', $tools[0]['input']['from_memory'] );
	}

	/**
	 * No editor but post_id → update-post.
	 */
	public function test_forced_apply_update_post_when_post_known() {
		$tools = Ahentic_Finish_Gate::forced_apply_tools_for_context(
			array( 'draft_1' ),
			array(
				'is_block_editor' => false,
				'post_id'         => 42,
			)
		);

		$this->assertSame( 'ahentic/update-post', $tools[0]['name'] );
		$this->assertSame( 42, $tools[0]['input']['id'] );
		$this->assertSame( 'draft_1', $tools[0]['input']['from_memory'] );
	}

	/**
	 * No editor and no post → create-post.
	 */
	public function test_forced_apply_create_post_when_no_post() {
		$tools = Ahentic_Finish_Gate::forced_apply_tools_for_context(
			array( 'draft_1' ),
			array()
		);

		$this->assertSame( 'ahentic/create-post', $tools[0]['name'] );
		$this->assertSame( 'draft_1', $tools[0]['input']['from_memory'] );
	}

	/**
	 * Empty keys → no tools.
	 */
	public function test_forced_apply_empty_keys() {
		$this->assertSame(
			array(),
			Ahentic_Finish_Gate::forced_apply_tools_for_context( array(), array( 'post_id' => 1 ) )
		);
	}

	/**
	 * text_chars under the long-form floor is thin.
	 */
	public function test_payload_is_thin_when_under_min_chars() {
		$payload = array( 'text_chars' => 100 );
		$this->assertTrue( Ahentic_Finish_Gate::payload_body_is_thin( $payload ) );
		$this->assertSame( 100, Ahentic_Finish_Gate::body_chars_from_write_payload( $payload ) );
	}

	/**
	 * Body at or above the floor is not thin (absent placeholder).
	 */
	public function test_payload_not_thin_when_long_enough() {
		$payload = array( 'content_text_chars' => Ahentic_Finish_Gate::LONG_FORM_MIN_CHARS );
		$this->assertFalse( Ahentic_Finish_Gate::payload_body_is_thin( $payload ) );
	}

	/**
	 * Leading lorem ipsum counts as thin even with enough chars.
	 */
	public function test_placeholder_preview_is_thin() {
		$payload = array(
			'text_chars'      => 5000,
			'content_preview' => 'Lorem ipsum dolor sit amet',
		);
		$this->assertTrue( Ahentic_Finish_Gate::payload_body_is_thin( $payload ) );
	}
}
