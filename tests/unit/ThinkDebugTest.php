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
	 * Attempt 2+ uses a slim prompt (not a second full backpack).
	 */
	public function test_should_use_slim_debug_retry() {
		$this->assertFalse( Ahentic_Think_Debug::should_use_slim_debug_retry( 1 ) );
		$this->assertTrue( Ahentic_Think_Debug::should_use_slim_debug_retry( 2 ) );
		$this->assertTrue( Ahentic_Think_Debug::should_use_slim_debug_retry( 3 ) );
		$this->assertFalse( Ahentic_Think_Debug::should_use_slim_debug_retry( 0 ) );
	}
}
