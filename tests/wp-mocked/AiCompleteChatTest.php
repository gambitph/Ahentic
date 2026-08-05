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
 * Covers the `pre_ahentic_ai_complete_chat` mocking seam (what the e2e
 * suite's AI-mock queue relies on, see
 * tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php) and the "no provider
 * configured" branch — both previously uncovered by the pure-PHP suite
 * because they need at least `apply_filters()`/`function_exists()` to exist.
 */
class AiCompleteChatTest extends WP_Mocked_TestCase {

	/**
	 * A non-null `pre_ahentic_ai_complete_chat` result short-circuits
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

		Filters\expectApplied( 'pre_ahentic_ai_complete_chat' )
			->once()
			->andReturn( $canned );

		$result = Ahentic_AI::complete_chat( 'system prompt', array(), 'hello' );

		$this->assertSame( $canned, $result );
	}

	/**
	 * With no Core `wp_ai_client_prompt()` helper (this bootstrap loads no
	 * WordPress) but the real `wordpress/php-ai-client` Composer SDK present
	 * (a hard `require` of this plugin, see composer.json — genuinely
	 * autoloadable here, deliberately not mocked: Patchwork cannot safely
	 * redefine `class_exists()`, and honest autoloading is more truthful
	 * anyway) and no provider actually configured, complete_chat() falls
	 * through to the Composer SDK path and fails soft with an
	 * exception-wrapped WP_Error rather than a fatal.
	 */
	public function test_falls_through_to_sdk_and_fails_soft_without_a_configured_provider() {
		Filters\expectApplied( 'pre_ahentic_ai_complete_chat' )->once()->andReturn( null );
		Functions\stubTranslationFunctions();

		$result = Ahentic_AI::complete_chat( 'system prompt', array(), 'hello' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'ahentic_ai_exception', $result->get_error_code() );
	}
}
