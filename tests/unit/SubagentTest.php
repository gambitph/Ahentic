<?php
/**
 * Subagent Recipe pure helpers (generic chain — no domain recipes).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers binding and compact payload helpers.
 */
class SubagentTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-subagent.php';
	}

	public function test_bind_recipe_input_from_state_fills_featured_and_inline() {
		$state = array(
			'alt'   => 'Hero',
			'steps' => array(
				array(
					'ability' => 'ahentic/upload-media',
					'ok'      => true,
					'payload' => array(
						'attachment_id' => 55,
						'url'           => 'https://example.com/x.jpg',
					),
				),
			),
		);

		// Regression: browser set-featured-image treats attachment_id 0 as "clear".
		// Tool_Runner must bind before pause_browser so this fill happens first.
		$featured = Ahentic_Subagent::bind_recipe_input_from_state(
			$state,
			array(
				'attachment_id' => 0,
				'_recipe_bind'  => 'attachment_from_prior_upload',
			)
		);
		$this->assertSame( 55, $featured['attachment_id'] );
		$this->assertArrayNotHasKey( '_recipe_bind', $featured );

		// Auto-fill placeholder 0 without explicit _recipe_bind.
		$auto = Ahentic_Subagent::bind_recipe_input_from_state(
			$state,
			array( 'attachment_id' => 0 )
		);
		$this->assertSame( 55, $auto['attachment_id'] );

		$inline = Ahentic_Subagent::bind_recipe_input_from_state(
			$state,
			array(
				'index'  => 0,
				'blocks' => array(
					array(
						'name'       => 'core/image',
						'attributes' => array(
							'id'  => 0,
							'url' => '',
							'alt' => '',
						),
					),
				),
			)
		);
		$this->assertSame( 55, $inline['blocks'][0]['attributes']['id'] );
		$this->assertSame( 'https://example.com/x.jpg', $inline['blocks'][0]['attributes']['url'] );
		$this->assertSame( 'Hero', $inline['blocks'][0]['attributes']['alt'] );
	}

	public function test_bind_recipe_input_from_upload_fills_attachment_id() {
		$state = array(
			'steps' => array(
				array(
					'ability' => 'ahentic/upload-media',
					'ok'      => true,
					'payload' => array(
						'attachment_id' => 1330,
						'url'           => 'https://example.com/f.png',
					),
				),
			),
		);

		// Log regression: model planned set-featured with from_upload, not attachment_id.
		$out = Ahentic_Subagent::bind_recipe_input_from_state(
			$state,
			array( 'from_upload' => 'featured_image' )
		);
		$this->assertSame( 1330, $out['attachment_id'] );
		$this->assertArrayNotHasKey( 'from_upload', $out );
	}

	/**
	 * Without a prior upload step, bind must not invent an id — leaving 0 would clear featured.
	 */
	public function test_bind_recipe_input_leaves_zero_without_upload_step() {
		$out = Ahentic_Subagent::bind_recipe_input_from_state(
			array( 'steps' => array() ),
			array(
				'attachment_id' => 0,
				'_recipe_bind'  => 'attachment_from_prior_upload',
			)
		);
		$this->assertSame( 0, $out['attachment_id'] );
		$this->assertArrayNotHasKey( '_recipe_bind', $out );
	}

	public function test_bind_recipe_input_from_state_noop_without_bind() {
		$out = Ahentic_Subagent::bind_recipe_input_from_state(
			array( 'steps' => array() ),
			array( 'attachment_id' => 3 )
		);
		$this->assertSame( 3, $out['attachment_id'] );
	}

	/**
	 * Model batch remainders are batch/recipe — empty purpose meta must not become apply.
	 */
	public function test_resolve_remainder_purpose_keeps_explicit_apply_only() {
		$this->assertSame(
			'apply',
			Ahentic_Subagent::resolve_remainder_purpose( 'apply', false )
		);
		$this->assertSame(
			'batch',
			Ahentic_Subagent::resolve_remainder_purpose( '', false )
		);
		$this->assertSame(
			'recipe',
			Ahentic_Subagent::resolve_remainder_purpose( '', true )
		);
		$this->assertSame(
			'batch',
			Ahentic_Subagent::resolve_remainder_purpose( 'batch', true ),
			'Explicit batch wins over recipe flag'
		);
		$this->assertSame(
			'recipe',
			Ahentic_Subagent::resolve_remainder_purpose( 'recipe', false )
		);
		// Fresh model multi-tool pause: no recipe yet → batch (call site must check
		// get_recipe before ensure_chain so empty does not become apply or false recipe).
		$this->assertNotSame(
			'apply',
			Ahentic_Subagent::resolve_remainder_purpose( '', false )
		);
	}

	public function test_compact_payload_keeps_only_safe_keys() {
		$this->assertSame(
			array(
				'ok'            => true,
				'attachment_id' => 9,
			),
			Ahentic_Subagent::compact_payload(
				array(
					'ok'            => true,
					'attachment_id' => 9,
					'huge'          => str_repeat( 'x', 100 ),
				)
			)
		);
	}

	public function test_after_tool_success_without_repository_is_noop() {
		$this->assertFalse(
			Ahentic_Subagent::after_tool_success(
				1,
				'ahentic/generate-image',
				array(
					'ok'           => true,
					'artifact_key' => 'image_x',
				),
				array(),
				0
			)
		);
	}
}
