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
	 * Model batch remainders are batch/recipe — empty purpose meta must not become apply,
	 * except content-work from_memory apply remainders (Job Resume forced_apply_retry path).
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

	public function test_resolve_remainder_purpose_content_from_memory_apply() {
		$set_blocks = array(
			array(
				'name'  => 'ahentic-browser/set-blocks',
				'input' => array( 'from_memory' => 'article_draft' ),
			),
		);
		$this->assertTrue( Ahentic_Subagent::remainder_is_content_from_memory_apply( $set_blocks ) );
		$this->assertSame(
			'apply',
			Ahentic_Subagent::resolve_remainder_purpose( '', true, $set_blocks, true ),
			'Content-work from_memory set-blocks remainder is apply (forced_apply_retry)'
		);
		$this->assertSame(
			'recipe',
			Ahentic_Subagent::resolve_remainder_purpose( '', true, $set_blocks, false ),
			'Without content_work, recipe flag still wins for empty purpose'
		);
		$this->assertSame(
			'batch',
			Ahentic_Subagent::resolve_remainder_purpose(
				'',
				false,
				array(
					array(
						'name'  => 'ahentic/search-content',
						'input' => array( 'query' => 'cats' ),
					),
				),
				true
			),
			'Research remainder stays batch even during content_work'
		);
		$this->assertFalse(
			Ahentic_Subagent::remainder_is_content_from_memory_apply(
				array(
					array(
						'name'  => 'ahentic-browser/set-blocks',
						'input' => array( 'blocks' => array() ),
					),
				)
			),
			'Inline set-blocks without from_memory is not content apply'
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

	/**
	 * Mini-job hop: enter only when peelable (flag + brief, no tools yet, not ask_user).
	 */
	public function test_should_enter_hop_when_mini_job_with_brief_and_empty_tools() {
		$this->assertTrue(
			Ahentic_Subagent::should_enter_hop(
				array(
					'mini_job'      => true,
					'hop_brief'     => 'Place a featured image for the draft; topic cats, landscape.',
					'next'          => 'use_tools',
					'tools_planned' => array(),
				),
				array()
			)
		);
	}

	public function test_should_enter_hop_vetoes() {
		$brief = array(
			'mini_job'  => true,
			'hop_brief' => 'Do the image work',
			'next'      => 'use_tools',
		);

		$this->assertFalse(
			Ahentic_Subagent::should_enter_hop(
				array_merge( $brief, array( 'next' => 'ask_user' ) ),
				array()
			),
			'Clarifying question stays on main'
		);

		$this->assertFalse(
			Ahentic_Subagent::should_enter_hop(
				array(
					'mini_job'  => true,
					'hop_brief' => '',
					'next'      => 'use_tools',
				),
				array()
			),
			'Empty brief — no hop'
		);

		$this->assertFalse(
			Ahentic_Subagent::should_enter_hop(
				array(
					'mini_job'  => false,
					'hop_brief' => 'x',
					'next'      => 'use_tools',
				),
				array()
			)
		);

		$this->assertFalse(
			Ahentic_Subagent::should_enter_hop(
				$brief,
				array(
					array(
						'name'  => 'ahentic/generate-image',
						'input' => array(),
					),
				)
			),
			'Tools already planned → Recipe path, not hop'
		);

		$this->assertFalse(
			Ahentic_Subagent::should_enter_hop(
				array_merge(
					$brief,
					array(
						'tools_planned' => array( 'ahentic/generate-image' ),
					)
				),
				array()
			),
			'Raw tools_planned in debug also vetoes hop'
		);
	}

		public function test_hop_brief_from_debug_trims() {
		$this->assertSame(
			'Generate then upload hero',
			Ahentic_Subagent::hop_brief_from_debug(
				array(
					'hop_brief' => "  Generate then upload hero\n",
				)
			)
		);
		$this->assertSame( '', Ahentic_Subagent::hop_brief_from_debug( array() ) );
	}

	public function test_hop_summary_payload_is_compact() {
		$summary = Ahentic_Subagent::hop_summary_payload(
			true,
			'Placed featured image',
			array(
				array(
					'ability' => 'ahentic/upload-media',
					'ok'      => true,
				),
				array(
					'ability' => 'ahentic-browser/set-featured-image',
					'ok'      => true,
				),
			)
		);
		$this->assertTrue( $summary['ok'] );
		$this->assertTrue( $summary['mini_job_hop'] );
		$this->assertSame( 'Placed featured image', $summary['summary'] );
		$this->assertCount( 2, $summary['steps'] );
		$this->assertSame( 'ahentic/upload-media', $summary['steps'][0]['ability'] );
	}

	public function test_try_begin_hop_without_repository_is_false() {
		$this->assertFalse(
			Ahentic_Subagent::try_begin_hop(
				1,
				array(
					'mini_job'  => true,
					'hop_brief' => 'do it',
					'next'      => 'use_tools',
				),
				array()
			)
		);
	}

	/**
	 * Disposition seam (#10): after_main_think decide vocabulary.
	 */
	public function test_decide_after_main_think_begin_hop_when_peelable() {
		$this->assertSame(
			'begin_hop',
			Ahentic_Subagent::decide_after_main_think(
				array(
					'in_hop'               => false,
					'finish_without_tools' => false,
					'wants_tools'          => true,
					'debug'                => array(
						'mini_job'      => true,
						'hop_brief'     => 'Place featured image',
						'next'          => 'use_tools',
						'tools_planned' => array(),
					),
					'planned'              => array(),
				)
			)
		);
	}

	public function test_decide_after_main_think_run_tools_when_not_peelable() {
		$this->assertSame(
			'run_tools',
			Ahentic_Subagent::decide_after_main_think(
				array(
					'in_hop'               => false,
					'finish_without_tools' => false,
					'wants_tools'          => true,
					'debug'                => array(
						'next' => 'use_tools',
					),
					'planned'              => array(
						array(
							'name'  => 'ahentic/get-content',
							'input' => array(),
						),
					),
				)
			)
		);
	}

	public function test_decide_after_main_think_finish_reply_without_tools() {
		$this->assertSame(
			'finish_reply',
			Ahentic_Subagent::decide_after_main_think(
				array(
					'in_hop'               => false,
					'finish_without_tools' => true,
					'wants_tools'          => false,
					'debug'                => array( 'next' => 'reply' ),
					'planned'              => array(),
				)
			)
		);
		$this->assertSame(
			'finish_reply',
			Ahentic_Subagent::decide_after_main_think(
				array(
					'in_hop'               => false,
					'finish_without_tools' => false,
					'wants_tools'          => false,
					'debug'                => array( 'next' => 'reply' ),
					'planned'              => array(),
				)
			)
		);
	}

	public function test_decide_after_main_think_hop_finish_and_abort_to_user() {
		$this->assertSame(
			'finish_hop',
			Ahentic_Subagent::decide_after_main_think(
				array(
					'in_hop'               => true,
					'finish_without_tools' => true,
					'wants_tools'          => false,
					'debug'                => array( 'next' => 'reply' ),
					'planned'              => array(),
				)
			)
		);
		$this->assertSame(
			'finish_hop',
			Ahentic_Subagent::decide_after_main_think(
				array(
					'in_hop'               => true,
					'finish_without_tools' => false,
					'wants_tools'          => false,
					'debug'                => array( 'next' => 'reply' ),
					'planned'              => array(),
				)
			)
		);
		$this->assertSame(
			'abort_to_user',
			Ahentic_Subagent::decide_after_main_think(
				array(
					'in_hop'               => true,
					'finish_without_tools' => false,
					'wants_tools'          => false,
					'debug'                => array( 'next' => 'ask_user' ),
					'planned'              => array(),
				)
			)
		);
		$this->assertSame(
			'run_tools',
			Ahentic_Subagent::decide_after_main_think(
				array(
					'in_hop'               => true,
					'finish_without_tools' => false,
					'wants_tools'          => true,
					'debug'                => array( 'next' => 'use_tools' ),
					'planned'              => array(
						array(
							'name'  => 'ahentic/generate-image',
							'input' => array(),
						),
					),
				)
			)
		);
	}

	public function test_decide_after_tools_finish_hop_vs_continue() {
		$this->assertSame( 'finish_hop', Ahentic_Subagent::decide_after_tools( true ) );
		$this->assertSame( 'continue', Ahentic_Subagent::decide_after_tools( false ) );
	}

	public function test_after_main_think_without_repository_returns_disposition() {
		$disp = Ahentic_Subagent::after_main_think(
			1,
			array(
				'in_hop'               => false,
				'finish_without_tools' => false,
				'wants_tools'          => true,
				'debug'                => array(
					'mini_job'      => true,
					'hop_brief'     => 'Place featured image',
					'next'          => 'use_tools',
					'tools_planned' => array(),
				),
				'planned'              => array(),
				'result'               => array( 'text' => 'ok' ),
			)
		);
		// Without Session Repository, begin_hop cannot persist — fall through to run_tools.
		$this->assertSame( 'run_tools', $disp['action'] );
	}

	public function test_after_tools_without_repository_continues() {
		$disp = Ahentic_Subagent::after_tools(
			1,
			array(
				'any_failed' => false,
				'reply_text' => '',
			)
		);
		$this->assertSame( 'continue', $disp['action'] );
	}

	public function test_after_resume_tool_without_repository_ok_and_fail() {
		$ok = Ahentic_Subagent::after_resume_tool(
			1,
			array(
				'ok'      => true,
				'name'    => 'ahentic/generate-image',
				'payload' => array( 'ok' => true ),
			)
		);
		$this->assertSame( 'continue', $ok['action'] );

		$fail = Ahentic_Subagent::after_resume_tool(
			1,
			array(
				'ok'     => false,
				'reason' => 'user_denied',
			)
		);
		$this->assertSame( 'aborted', $fail['action'] );
		$this->assertSame( 'user_denied', $fail['reason'] );
	}
}
