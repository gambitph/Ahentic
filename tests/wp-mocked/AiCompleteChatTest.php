<?php
/**
 * Ahentic_AI::complete_chat() dispatch, with WordPress functions mocked via
 * Brain Monkey instead of a real WordPress boot.
 *
 * @package Ahentic
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once __DIR__ . '/WP_Mocked_TestCase.php';

/**
 * Covers the `ahentic_pre_ai_complete_chat` mocking seam (what the e2e
 * suite's AI-mock queue relies on, see
 * tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php) and the "no provider
 * configured" branch — both previously uncovered by the pure-PHP suite
 * because they need at least `apply_filters()`/`function_exists()` to exist.
 */
class AiCompleteChatTest extends WP_Mocked_TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Ahentic_Test_Prompt_Builder::reset();
		Functions\stubTranslationFunctions();
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
	}

	/**
	 * A non-null `ahentic_pre_ai_complete_chat` result short-circuits
	 * complete_chat() before it ever reaches a real provider. If the
	 * short-circuit didn't happen, execution would fall through to
	 * function_exists()/class_exists() checks against AI client symbols that
	 * don't exist in this bootstrap, producing a WP_Error instead of $canned
	 * — so no separate assertion is needed to prove the provider was skipped.
	 */
	public function test_pre_filter_short_circuits_before_any_provider_call() {
		$canned = array(
			'text'  => 'mocked reply',
			'model' => 'ahentic-e2e-mock',
		);

		Filters\expectApplied( 'ahentic_pre_ai_complete_chat' )
			->once()
			->andReturn( $canned );

		$result = Ahentic_AI::complete_chat( 'system prompt', array(), 'hello' );

		$this->assertSame( $canned, $result );
	}

	/**
	 * Core path: a successful generate_text_result() becomes complete_chat() text.
	 */
	public function test_core_complete_chat_returns_generated_text() {
		Filters\expectApplied( 'ahentic_pre_ai_complete_chat' )->once()->andReturn( null );

		$result = Ahentic_AI::complete_chat( 'system prompt', array(), 'hello' );

		$this->assertIsArray( $result );
		$this->assertSame( 'core reply', $result['text'] );
		$this->assertSame( 1, Ahentic_Test_Prompt_Builder::$generate_calls );
	}

	/**
	 * Chat generate uses the same 120s RequestOptions ceiling as image generation.
	 */
	public function test_core_complete_chat_sets_request_timeout() {
		Filters\expectApplied( 'ahentic_pre_ai_complete_chat' )->once()->andReturn( null );

		Ahentic_AI::complete_chat( 'system prompt', array(), 'hello' );

		$this->assertSame( 120.0, Ahentic_Test_Prompt_Builder::$last_timeout );
	}

	/**
	 * List-models uses WP HTTP, not the builder RequestOptions bound after a
	 * model is chosen. Raise http_request_timeout for the whole complete_chat().
	 */
	public function test_core_complete_chat_raises_wp_http_timeout_for_list_models() {
		$timeout_cb = null;
		Functions\when( 'add_filter' )->alias(
			static function ( $tag, $cb ) use ( &$timeout_cb ) {
				if ( 'http_request_timeout' === $tag ) {
					$timeout_cb = $cb;
				}
				return true;
			}
		);
		Filters\expectApplied( 'ahentic_pre_ai_complete_chat' )->once()->andReturn( null );

		Ahentic_AI::complete_chat( 'system prompt', array(), 'hello' );

		$this->assertIsCallable( $timeout_cb );
		$this->assertSame( 120.0, (float) call_user_func( $timeout_cb, 5 ) );
	}

	/**
	 * When max_tokens empties Core's candidate set, drop that option and
	 * generate once - do not pay a second generate_text_result().
	 */
	public function test_core_complete_chat_drops_max_tokens_when_unsupported_and_generates_once() {
		Filters\expectApplied( 'ahentic_pre_ai_complete_chat' )->once()->andReturn( null );
		Ahentic_Test_Prompt_Builder::$supported_with_max_tokens = false;

		$result = Ahentic_AI::complete_chat( 'system prompt', array(), 'hello' );

		$this->assertIsArray( $result );
		$this->assertSame( 'core reply', $result['text'] );
		$this->assertSame( 1, Ahentic_Test_Prompt_Builder::$generate_calls );
		$this->assertNull( Ahentic_Test_Prompt_Builder::$last_generate_max_tokens );
	}

	/**
	 * Provider/builder WP_Error is returned as-is (fail soft, no fatal).
	 */
	public function test_core_complete_chat_returns_generate_wp_error() {
		Filters\expectApplied( 'ahentic_pre_ai_complete_chat' )->once()->andReturn( null );
		Ahentic_Test_Prompt_Builder::$generate_result = new WP_Error(
			'prompt_invalid_argument',
			'No models found that support text_generation for this prompt.'
		);

		$result = Ahentic_AI::complete_chat( 'system prompt', array(), 'hello' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'prompt_invalid_argument', $result->get_error_code() );
	}
}
