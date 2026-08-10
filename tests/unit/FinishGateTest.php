<?php
/**
 * Finish gate pure helpers: artifact apply detection, forced tools, thin-body arithmetic,
 * and phase disposition for forced apply (post_tools vs pre_idle).
 *
 * Full decide_continue / evaluate_reply / assess_write_payload paths stay in e2e (session meta).
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
	 * Pre-idle forces apply whenever Ready keys exist (planned batch irrelevant).
	 */
	public function test_should_force_apply_pre_idle_ignores_planned() {
		$planned = array(
			array(
				'name'  => 'ahentic-browser/set-blocks',
				'input' => array( 'from_memory' => 'draft_a' ),
			),
		);

		$this->assertTrue(
			Ahentic_Finish_Gate::should_force_apply(
				Ahentic_Finish_Gate::PHASE_PRE_IDLE,
				'agent',
				array( 'draft_a' ),
				$planned
			)
		);
	}

	/**
	 * Post-tools skips force when the batch already planned apply for a Ready key.
	 */
	public function test_should_force_apply_post_tools_skips_when_planned() {
		$planned = array(
			array(
				'name'  => 'ahentic-browser/set-blocks',
				'input' => array( 'from_memory' => 'draft_a' ),
			),
		);

		$this->assertFalse(
			Ahentic_Finish_Gate::should_force_apply(
				Ahentic_Finish_Gate::PHASE_POST_TOOLS,
				'agent',
				array( 'draft_a' ),
				$planned
			)
		);
	}

	/**
	 * Post-tools forces apply when Ready keys exist and the batch did not apply them.
	 */
	public function test_should_force_apply_post_tools_when_unapplied() {
		$planned = array(
			array(
				'name'  => 'ahentic/create-artifact',
				'input' => array(),
			),
		);

		$this->assertTrue(
			Ahentic_Finish_Gate::should_force_apply(
				Ahentic_Finish_Gate::PHASE_POST_TOOLS,
				'agent',
				array( 'draft_a' ),
				$planned
			)
		);
	}

	/**
	 * Ask mode never forces apply (either phase).
	 */
	public function test_should_force_apply_false_in_ask_mode() {
		$this->assertFalse(
			Ahentic_Finish_Gate::should_force_apply(
				Ahentic_Finish_Gate::PHASE_POST_TOOLS,
				'ask',
				array( 'draft_a' ),
				array()
			)
		);
		$this->assertFalse(
			Ahentic_Finish_Gate::should_force_apply(
				Ahentic_Finish_Gate::PHASE_PRE_IDLE,
				'ask',
				array( 'draft_a' ),
				array()
			)
		);
	}

	/**
	 * Empty Ready set → no force.
	 */
	public function test_should_force_apply_false_without_unapplied() {
		$this->assertFalse(
			Ahentic_Finish_Gate::should_force_apply(
				Ahentic_Finish_Gate::PHASE_POST_TOOLS,
				'agent',
				array(),
				array()
			)
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
	 * Artifact title from meta is queued with set-blocks (no PHP-invented copy).
	 */
	public function test_forced_apply_includes_document_title_when_known() {
		$tools = Ahentic_Finish_Gate::forced_apply_tools_for_context(
			array( 'draft_1' ),
			array(
				'is_block_editor' => true,
				'post_id'         => 9,
			),
			array( 'draft_1' => 'Why private cars remain appealing' )
		);

		$this->assertCount( 2, $tools );
		$this->assertSame( 'ahentic-browser/set-blocks', $tools[0]['name'] );
		$this->assertSame( 'ahentic-browser/update-post-document', $tools[1]['name'] );
		$this->assertSame( 'Why private cars remain appealing', $tools[1]['input']['title'] );
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
	 * Known title rides on update-post input (no separate invent step).
	 */
	public function test_forced_apply_update_post_includes_title_when_known() {
		$tools = Ahentic_Finish_Gate::forced_apply_tools_for_context(
			array( 'draft_1' ),
			array(
				'is_block_editor' => false,
				'post_id'         => 42,
			),
			array( 'draft_1' => 'Commute tips' )
		);

		$this->assertSame( 'ahentic/update-post', $tools[0]['name'] );
		$this->assertSame( 'Commute tips', $tools[0]['input']['title'] );
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
	 * Known title rides on create-post input.
	 */
	public function test_forced_apply_create_post_includes_title_when_known() {
		$tools = Ahentic_Finish_Gate::forced_apply_tools_for_context(
			array( 'draft_1' ),
			array(),
			array( 'draft_1' => 'New piece' )
		);

		$this->assertSame( 'ahentic/create-post', $tools[0]['name'] );
		$this->assertSame( 'New piece', $tools[0]['input']['title'] );
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

	/**
	 * Regression: successful set-blocks with a long body must not trip thin.
	 *
	 * The WP 7 RichTextData counting bug made the browser report text_chars=0
	 * despite inserted_count>0; Finish Gate then forced endless rebuilds.
	 */
	public function test_set_blocks_payload_not_thin_when_text_chars_above_floor() {
		$payload = array(
			'ok'             => true,
			'inserted_count' => 28,
			'text_chars'     => 6842,
		);
		$this->assertFalse( Ahentic_Finish_Gate::payload_body_is_thin( $payload ) );
		$this->assertSame( 6842, Ahentic_Finish_Gate::body_chars_from_write_payload( $payload ) );
	}

	/**
	 * Arithmetically, text_chars=0 is under the floor — but with inserted_count it is a
	 * measurement miss, not empty copy (assess_write_payload must not stamp thin).
	 */
	public function test_zero_chars_is_thin_arithmetically_but_measure_failure_when_inserted() {
		$payload = array(
			'ok'             => true,
			'inserted_count' => 27,
			'text_chars'     => 0,
		);
		$this->assertTrue( Ahentic_Finish_Gate::payload_body_is_thin( $payload ) );
		$this->assertTrue( Ahentic_Finish_Gate::write_payload_looks_like_measure_failure( $payload ) );
	}

	/**
	 * Empty apply (no blocks) with zero chars is not a measurement failure.
	 */
	public function test_zero_chars_without_inserts_is_not_measure_failure() {
		$payload = array(
			'ok'             => true,
			'inserted_count' => 0,
			'text_chars'     => 0,
		);
		$this->assertFalse( Ahentic_Finish_Gate::write_payload_looks_like_measure_failure( $payload ) );
	}

	/**
	 * Non-zero measurement is never a measure failure.
	 */
	public function test_nonzero_chars_is_not_measure_failure() {
		$payload = array(
			'inserted_count' => 10,
			'text_chars'     => 50,
		);
		$this->assertFalse( Ahentic_Finish_Gate::write_payload_looks_like_measure_failure( $payload ) );
	}

	/**
	 * Featured-goal heuristic (finish block, not Subagent recipes).
	 */
	public function test_goal_needs_featured_image() {
		$this->assertTrue( Ahentic_Finish_Gate::goal_needs_featured_image( 'Add internal links and a featured image' ) );
		$this->assertFalse( Ahentic_Finish_Gate::goal_needs_featured_image( 'Only fix typos' ) );
	}

	/**
	 * Featured placement counts only successful set with a positive attachment id.
	 */
	public function test_featured_placement_done_requires_positive_attachment() {
		$cleared = array(
			array(
				'role'    => 'tool',
				'content' => json_encode(
					array(
						'ok'             => true,
						'featured_media' => 0,
						'cleared'        => true,
					)
				),
				'meta'    => array(
					'ability' => 'ahentic-browser/set-featured-image',
					'ok'      => true,
				),
			),
		);
		$this->assertFalse( Ahentic_Finish_Gate::featured_placement_done_in_entries( $cleared ) );

		$generate_only = array(
			array(
				'role'    => 'tool',
				'content' => json_encode( array( 'ok' => true, 'artifact_key' => 'image_x' ) ),
				'meta'    => array(
					'ability' => 'ahentic/generate-image',
					'ok'      => true,
				),
			),
		);
		$this->assertFalse( Ahentic_Finish_Gate::featured_placement_done_in_entries( $generate_only ) );

		$set = array(
			array(
				'role'    => 'tool',
				'content' => json_encode(
					array(
						'ok'             => true,
						'featured_media' => 99,
					)
				),
				'meta'    => array(
					'ability' => 'ahentic-browser/set-featured-image',
					'ok'      => true,
				),
			),
		);
		$this->assertTrue( Ahentic_Finish_Gate::featured_placement_done_in_entries( $set ) );
	}
}
