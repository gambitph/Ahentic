<?php
/**
 * Think/debug pure helpers: usable next, missing-ability signals, ability name normalize, progress labels.
 *
 * Session-backed retry / queue / publish stay in e2e (orchestrator-pipeline).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Think_Debug pure helpers (M6 deepen).
 */
class ThinkDebugTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-think-debug.php';
	}

	/**
	 * Only reply|ask_user|use_tools|missing_ability drive the loop.
	 */
	public function test_is_usable_requires_known_next() {
		$this->assertFalse( Ahentic_Think_Debug::is_usable( null ) );
		$this->assertFalse( Ahentic_Think_Debug::is_usable( array() ) );
		$this->assertFalse( Ahentic_Think_Debug::is_usable( array( 'next' => 'continue' ) ) );
		$this->assertTrue( Ahentic_Think_Debug::is_usable( array( 'next' => 'use_tools' ) ) );
		$this->assertTrue( Ahentic_Think_Debug::is_usable( array( 'next' => 'missing_ability' ) ) );
	}

	/**
	 * missing_ability next, or reply/ask_user with ability_needed, signal a gap.
	 */
	public function test_signals_missing_ability() {
		$this->assertTrue(
			Ahentic_Think_Debug::signals_missing_ability( array( 'next' => 'missing_ability' ) )
		);
		$this->assertTrue(
			Ahentic_Think_Debug::signals_missing_ability(
				array(
					'next'            => 'reply',
					'ability_needed'  => 'ahentic/foo',
				)
			)
		);
		$this->assertFalse(
			Ahentic_Think_Debug::signals_missing_ability(
				array(
					'next' => 'use_tools',
				)
			)
		);
	}

	/**
	 * Freeform labels become ahentic/slug; already-namespaced names lowercased.
	 */
	public function test_normalize_ability_name() {
		$this->assertSame( 'ahentic/create-post', Ahentic_Think_Debug::normalize_ability_name( 'Create Post' ) );
		$this->assertSame( 'ahentic/list-plugins', Ahentic_Think_Debug::normalize_ability_name( 'ahentic/list-plugins' ) );
		$this->assertSame( '', Ahentic_Think_Debug::normalize_ability_name( '' ) );
	}

	/**
	 * Intention wins over thinking for the live progress label.
	 */
	public function test_progress_label_from_debug_prefers_intention() {
		$label = Ahentic_Think_Debug::progress_label_from_debug(
			array(
				'intention' => 'listing plugins',
				'thinking'  => 'I will look around the site carefully.',
			),
			'fallback'
		);
		$this->assertStringStartsWith( 'Listing plugins', $label );
		$this->assertStringEndsWith( '…', $label );
	}

	/**
	 * Thought process prefers thinking, then reply text, then intention.
	 */
	public function test_resolve_thought_process_for_chat() {
		$this->assertSame(
			'Deep thought',
			Ahentic_Think_Debug::resolve_thought_process_for_chat(
				array( 'text' => 'Reply' ),
				array(
					'thinking'  => 'Deep thought',
					'intention' => 'Intent',
				)
			)
		);
		$this->assertSame(
			'Reply only',
			Ahentic_Think_Debug::resolve_thought_process_for_chat(
				array( 'text' => 'Reply only' ),
				array( 'intention' => 'Intent' )
			)
		);
	}

	/**
	 * Post-think disposition: unusable / missing / continue (no session side effects).
	 */
	public function test_disposition_for_debug() {
		$this->assertSame( 'finish_unusable', Ahentic_Think_Debug::disposition_for_debug( array() ) );
		$this->assertSame(
			'finish_unusable',
			Ahentic_Think_Debug::disposition_for_debug( array( 'next' => 'continue' ) )
		);
		$this->assertSame(
			'finish_missing',
			Ahentic_Think_Debug::disposition_for_debug(
				array(
					'next'           => 'missing_ability',
					'ability_needed' => 'ahentic/foo',
				)
			)
		);
		$this->assertSame(
			'finish_missing',
			Ahentic_Think_Debug::disposition_for_debug(
				array(
					'next'           => 'reply',
					'ability_needed' => 'ahentic/foo',
				)
			)
		);
		$this->assertSame(
			'continue',
			Ahentic_Think_Debug::disposition_for_debug( array( 'next' => 'use_tools' ) )
		);
		$this->assertSame(
			'continue',
			Ahentic_Think_Debug::disposition_for_debug( array( 'next' => 'reply' ) )
		);
	}

	/**
	 * Classify missing-ability claims against an available catalog before finishing.
	 */
	public function test_classify_missing_ability_claim() {
		$available = array(
			'ahentic-browser/update-block-attributes',
			'ahentic/search-content',
		);

		$this->assertSame(
			'none',
			Ahentic_Think_Debug::classify_missing_ability_claim(
				array( 'next' => 'use_tools' ),
				$available
			)
		);
		$this->assertSame(
			'available',
			Ahentic_Think_Debug::classify_missing_ability_claim(
				array(
					'next'           => 'missing_ability',
					'ability_needed' => 'ahentic-browser/update-block-attributes',
				),
				$available
			)
		);
		$this->assertSame(
			'vague',
			Ahentic_Think_Debug::classify_missing_ability_claim(
				array( 'next' => 'missing_ability' ),
				$available
			)
		);
		$this->assertSame(
			'vague',
			Ahentic_Think_Debug::classify_missing_ability_claim(
				array(
					'next'           => 'missing_ability',
					'ability_needed' => 'ahentic/new-ability',
				),
				$available
			)
		);
		$this->assertSame(
			'unknown',
			Ahentic_Think_Debug::classify_missing_ability_claim(
				array(
					'next'           => 'missing_ability',
					'ability_needed' => 'ahentic/teleport-posts',
				),
				$available
			)
		);
	}

	/**
	 * Missing-ability action: use existing tools, reconsider once, then finish only for real gaps.
	 */
	public function test_missing_ability_action() {
		$available = array( 'ahentic-browser/update-block-attributes' );

		$this->assertSame(
			'none',
			Ahentic_Think_Debug::missing_ability_action( array( 'next' => 'reply' ), $available, 0 )
		);
		$this->assertSame(
			'use_available',
			Ahentic_Think_Debug::missing_ability_action(
				array(
					'next'           => 'missing_ability',
					'ability_needed' => 'ahentic-browser/update-block-attributes',
				),
				$available,
				0
			)
		);
		$this->assertSame(
			'reconsider',
			Ahentic_Think_Debug::missing_ability_action(
				array(
					'next'           => 'missing_ability',
					'ability_needed' => 'ahentic/teleport-posts',
				),
				$available,
				0
			)
		);
		$this->assertSame(
			'finish_missing',
			Ahentic_Think_Debug::missing_ability_action(
				array(
					'next'           => 'missing_ability',
					'ability_needed' => 'ahentic/teleport-posts',
				),
				$available,
				1
			)
		);
		$this->assertSame(
			'finish_reply',
			Ahentic_Think_Debug::missing_ability_action(
				array( 'next' => 'missing_ability' ),
				$available,
				1
			)
		);
	}

	/**
	 * When tools_planned already names the available ability, rewrite promotes to use_tools
	 * and keeps the planned input (never invents empty args).
	 */
	public function test_rewrite_debug_to_use_tools_preserves_planned_input() {
		$debug = Ahentic_Think_Debug::rewrite_debug_to_use_tools(
			array(
				'next'           => 'missing_ability',
				'ability_needed' => 'ahentic/update-option',
				'tools_planned'  => array(
					array(
						'name'  => 'ahentic/update-option',
						'input' => array(
							'key'   => 'timezone_string',
							'value' => 'Asia/Manila',
						),
					),
				),
			),
			'ahentic/update-option'
		);
		$this->assertSame( 'use_tools', $debug['next'] );
		$this->assertSame(
			array(
				array(
					'name'  => 'ahentic/update-option',
					'input' => array(
						'key'   => 'timezone_string',
						'value' => 'Asia/Manila',
					),
				),
			),
			$debug['tools_planned']
		);
		$this->assertArrayNotHasKey( 'ability_needed', $debug );
	}

	/**
	 * Named available ability with no planned call must NOT inject input:[].
	 * Empty forced calls produce useless HITL cards (e.g. Update option “option”).
	 */
	public function test_rewrite_debug_to_use_tools_does_not_inject_empty_input() {
		$debug = Ahentic_Think_Debug::rewrite_debug_to_use_tools(
			array(
				'next'           => 'missing_ability',
				'ability_needed' => 'ahentic-browser/update-block-attributes',
				'thinking'       => 'Need an editor control.',
			),
			'ahentic-browser/update-block-attributes'
		);
		$this->assertSame( 'missing_ability', $debug['next'] );
		$this->assertSame( 'ahentic-browser/update-block-attributes', $debug['ability_needed'] );
		$planned = isset( $debug['tools_planned'] ) ? $debug['tools_planned'] : array();
		$this->assertSame( array(), $planned );
	}

	/**
	 * After reconsider, available-but-unplanned claims become a normal reply
	 * (not an empty forced tool call).
	 */
	public function test_finish_available_missing_without_planned_tools() {
		$debug = Ahentic_Think_Debug::finish_available_missing_without_plan(
			array(
				'next'           => 'missing_ability',
				'ability_needed' => 'ahentic/update-option',
				'tools_planned'  => array(),
			),
			array( 'ahentic/update-option' )
		);
		$this->assertSame( 'reply', $debug['next'] );
		$this->assertArrayNotHasKey( 'ability_needed', $debug );

		$with_plan = Ahentic_Think_Debug::finish_available_missing_without_plan(
			array(
				'next'           => 'missing_ability',
				'ability_needed' => 'ahentic/update-option',
				'tools_planned'  => array(
					array(
						'name'  => 'ahentic/update-option',
						'input' => array(
							'key'   => 'blogname',
							'value' => 'X',
						),
					),
				),
			),
			array( 'ahentic/update-option' )
		);
		$this->assertSame( 'use_tools', $with_plan['next'] );
		$this->assertSame( 'blogname', $with_plan['tools_planned'][0]['input']['key'] );
	}

	/**
	 * Attempt 2+ uses a slim prompt (not a second full backpack).
	 */
	public function test_should_use_slim_debug_retry() {
		$this->assertFalse( Ahentic_Think_Debug::should_use_slim_debug_retry( 1 ) );
		$this->assertTrue( Ahentic_Think_Debug::should_use_slim_debug_retry( 2 ) );
		$this->assertTrue( Ahentic_Think_Debug::should_use_slim_debug_retry( 3 ) );
		$this->assertFalse( Ahentic_Think_Debug::should_use_slim_debug_retry( 0 ) );
	}
}
