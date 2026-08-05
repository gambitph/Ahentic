<?php
/**
 * Control-block parsing: extraction, truncation salvage, and `next` repair.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_AI::extract_debug_block() and Ahentic_AI::normalize_debug_next().
 */
class ControlBlockTest extends TestCase {

	/**
	 * Wrap JSON in the control-block markers the model is asked to emit.
	 *
	 * @param string $json  Block payload.
	 * @param string $after Optional user-facing prose.
	 * @return string
	 */
	private function block( $json, $after = '' ) {
		$out = "<<<AHENTIC_DEBUG\n" . $json . "\nAHENTIC_DEBUG>>>";
		return '' === $after ? $out : $out . "\n\n" . $after;
	}

	// ---------------------------------------------------------------------
	// Well-formed blocks.
	// ---------------------------------------------------------------------

	/**
	 * A complete block yields debug plus the trailing prose, and is not flagged truncated.
	 */
	public function test_parses_complete_block_and_keeps_reply() {
		$json = '{"intention":"Listing plugins","thinking":"Check what is installed.","tools_planned":[{"name":"ahentic/list-plugins","input":{}}],"next":"use_tools"}';

		$out = Ahentic_AI::extract_debug_block( $this->block( $json, 'Looking at your plugins now.' ) );

		$this->assertSame( 'Looking at your plugins now.', $out['text'] );
		$this->assertSame( 'Listing plugins', $out['debug']['intention'] );
		$this->assertSame( 'use_tools', $out['debug']['next'] );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( '', $out['truncated_key'] );
	}

	/**
	 * Models often emit extra closing angle brackets.
	 */
	public function test_tolerates_extra_closing_angle_brackets() {
		$raw = "<<<AHENTIC_DEBUG\n{\"intention\":\"x\",\"next\":\"reply\"}\nAHENTIC_DEBUG>>>>>\n\nDone.";

		$out = Ahentic_AI::extract_debug_block( $raw );

		$this->assertSame( 'reply', $out['debug']['next'] );
		$this->assertSame( 'Done.', $out['text'] );
		$this->assertStringNotContainsString( 'AHENTIC_DEBUG', $out['text'] );
	}

	/**
	 * Markdown fences around the JSON payload are stripped before decoding.
	 */
	public function test_tolerates_fenced_json_payload() {
		$json = "```json\n{\"intention\":\"x\",\"next\":\"reply\"}\n```";

		$out = Ahentic_AI::extract_debug_block( $this->block( $json, 'All set.' ) );

		$this->assertIsArray( $out['debug'] );
		$this->assertSame( 'reply', $out['debug']['next'] );
	}

	/**
	 * Plain prose with no block is returned untouched.
	 */
	public function test_passes_through_text_without_a_block() {
		$out = Ahentic_AI::extract_debug_block( 'Just a normal answer.' );

		$this->assertSame( 'Just a normal answer.', $out['text'] );
		$this->assertNull( $out['debug'] );
		$this->assertFalse( $out['truncated'] );
	}

	/**
	 * A block with no prose still produces user-facing text.
	 */
	public function test_debug_only_response_gets_fallback_text() {
		$json = '{"intention":"Summarizing findings","thinking":"The site looks healthy.","next":"reply"}';

		$out = Ahentic_AI::extract_debug_block( $this->block( $json ) );

		$this->assertNotSame( '', $out['text'] );
		$this->assertStringNotContainsString( 'AHENTIC_DEBUG', $out['text'] );
	}

	// ---------------------------------------------------------------------
	// Truncation salvage.
	// ---------------------------------------------------------------------

	/**
	 * A block cut off inside tools_planned keeps the completed members and reports
	 * where it stopped. The interrupted tool call must not survive.
	 */
	public function test_salvages_block_truncated_inside_tools_planned() {
		$raw = "<<<AHENTIC_DEBUG\n"
			. '{"intention":"Writing the article","thinking":"Draft the full post.","tools_planned":[{"name":"ahentic-browser/set-blocks","input":{"content":"<!-- wp:paragraph --><p>Lorem ipsum dolor';

		$out = Ahentic_AI::extract_debug_block( $raw );

		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 'tools_planned', $out['truncated_key'] );
		$this->assertSame( 'Writing the article', $out['debug']['intention'] );
		$this->assertSame( 'Draft the full post.', $out['debug']['thinking'] );
		$this->assertArrayNotHasKey( 'tools_planned', $out['debug'] );
		$this->assertStringNotContainsString( 'AHENTIC_DEBUG', $out['text'] );
		$this->assertStringNotContainsString( 'Lorem ipsum', $out['text'] );
	}

	/**
	 * Truncation inside a top-level string value keeps the earlier members.
	 */
	public function test_salvages_block_truncated_inside_string_value() {
		$raw = "<<<AHENTIC_DEBUG\n"
			. '{"intention":"Checking the editor","thinking":"I am going to look at the current blocks and then';

		$out = Ahentic_AI::extract_debug_block( $raw );

		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 'thinking', $out['truncated_key'] );
		$this->assertSame( 'Checking the editor', $out['debug']['intention'] );
		$this->assertArrayNotHasKey( 'thinking', $out['debug'] );
	}

	/**
	 * Braces and escaped quotes inside string values must not confuse the scanner.
	 */
	public function test_salvage_ignores_braces_and_quotes_inside_strings() {
		$raw = "<<<AHENTIC_DEBUG\n"
			. '{"intention":"Fix {the} thing","thinking":"He said \"hi\" and used {braces} [here]","tools_planned":[{"name":"ahentic/get-content","input":{"id":1';

		$out = Ahentic_AI::extract_debug_block( $raw );

		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 'tools_planned', $out['truncated_key'] );
		$this->assertSame( 'Fix {the} thing', $out['debug']['intention'] );
		$this->assertSame( 'He said "hi" and used {braces} [here]', $out['debug']['thinking'] );
	}

	/**
	 * An escaped backslash must not swallow the quote that follows it.
	 */
	public function test_salvage_handles_escaped_backslash_before_quote() {
		$raw = "<<<AHENTIC_DEBUG\n"
			. '{"intention":"Path is C:\\\\","thinking":"Continue with the';

		$out = Ahentic_AI::extract_debug_block( $raw );

		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 'Path is C:\\', $out['debug']['intention'] );
	}

	/**
	 * A completed nested value counts as a salvage point.
	 */
	public function test_salvages_up_to_a_completed_nested_value() {
		$raw = "<<<AHENTIC_DEBUG\n"
			. '{"plan":{"title":"Publish post","steps":[{"id":"1","content":"Draft","status":"in_progress"}]},"tools_planned":[{"name":"ahentic/create-post","input":{"title":"Half a tit';

		$out = Ahentic_AI::extract_debug_block( $raw );

		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 'tools_planned', $out['truncated_key'] );
		$this->assertSame( 'Publish post', $out['debug']['plan']['title'] );
		$this->assertArrayNotHasKey( 'tools_planned', $out['debug'] );
	}

	/**
	 * Truncation before any member completes leaves nothing to recover, but the
	 * response is still reported as truncated rather than as a missing block.
	 */
	public function test_reports_truncation_when_nothing_is_recoverable() {
		$raw = "<<<AHENTIC_DEBUG\n" . '{"intention":"Starting the very long';

		$out = Ahentic_AI::extract_debug_block( $raw );

		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 'intention', $out['truncated_key'] );
		$this->assertNull( $out['debug'] );
	}

	/**
	 * A salvaged block must not become user-facing prose. Synthesizing a reply from a
	 * block that was cut off would claim work the model never got to request.
	 */
	public function test_truncated_block_produces_no_user_facing_text() {
		$raw = "<<<AHENTIC_DEBUG\n"
			. '{"intention":"Publishing the article","thinking":"Everything is ready to go.","tools_planned":[{"name":"ahentic/create-post","input":{"title":"Half';

		$out = Ahentic_AI::extract_debug_block( $raw );

		$this->assertTrue( $out['truncated'] );
		$this->assertSame( '', $out['text'] );
		$this->assertSame( 'Publishing the article', $out['debug']['intention'] );
	}

	/**
	 * A complete debug-only block still gets fallback prose, so the guard above did
	 * not disable the normal path.
	 */
	public function test_complete_block_still_gets_fallback_text() {
		$json = '{"intention":"Summarizing findings","thinking":"The site looks healthy.","next":"reply"}';

		$out = Ahentic_AI::extract_debug_block( $this->block( $json ) );

		$this->assertSame( 'The site looks healthy.', $out['text'] );
	}

	/**
	 * A balanced block that merely lost its closing marker is not a truncation.
	 */
	public function test_missing_closer_with_balanced_json_is_not_truncated() {
		$raw = "<<<AHENTIC_DEBUG\n" . '{"intention":"x","next":"reply"}' . "\n\nDone.";

		$out = Ahentic_AI::extract_debug_block( $raw );

		$this->assertSame( 'reply', $out['debug']['next'] );
		$this->assertFalse( $out['truncated'] );
	}

	// ---------------------------------------------------------------------
	// `next` repair.
	// ---------------------------------------------------------------------

	/**
	 * Accepted values are left exactly as they are.
	 *
	 * @dataProvider provide_valid_next_values
	 * @param string $next Accepted value.
	 */
	public function test_valid_next_is_untouched( $next ) {
		$result = Ahentic_AI::normalize_debug_next( array( 'next' => $next ) );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( $next, $result['debug']['next'] );
	}

	/**
	 * The four values the agent loop accepts.
	 *
	 * @return array
	 */
	public function provide_valid_next_values() {
		return array(
			array( 'reply' ),
			array( 'ask_user' ),
			array( 'use_tools' ),
			array( 'missing_ability' ),
		);
	}

	/**
	 * Known misspellings map to an accepted value instead of costing a retry.
	 *
	 * @dataProvider provide_next_aliases
	 * @param string $raw      What the model emitted.
	 * @param string $expected Canonical value.
	 */
	public function test_maps_known_next_aliases( $raw, $expected ) {
		$result = Ahentic_AI::normalize_debug_next( array( 'next' => $raw ) );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( $expected, $result['debug']['next'] );
		$this->assertSame( 'alias', $result['reason'] );
	}

	/**
	 * Spellings seen in practice.
	 *
	 * @return array
	 */
	public function provide_next_aliases() {
		return array(
			array( 'USE_TOOLS', 'use_tools' ),
			array( ' Use Tools ', 'use_tools' ),
			array( 'tool_use', 'use_tools' ),
			array( 'run_tools', 'use_tools' ),
			array( 'done', 'reply' ),
			array( 'Final', 'reply' ),
			array( 'answer', 'reply' ),
			array( 'ask', 'ask_user' ),
			array( 'ask-user', 'ask_user' ),
			array( 'clarify', 'ask_user' ),
			array( 'missing-ability', 'missing_ability' ),
		);
	}

	/**
	 * A missing `next` alongside planned tools means the model wanted tools.
	 */
	public function test_infers_use_tools_from_tools_planned() {
		$result = Ahentic_AI::normalize_debug_next(
			array(
				'intention'     => 'Listing plugins',
				'tools_planned' => array( array( 'name' => 'ahentic/list-plugins' ) ),
			)
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'use_tools', $result['debug']['next'] );
		$this->assertSame( 'inferred_tools_planned', $result['reason'] );
	}

	/**
	 * A named missing ability outranks tool inference.
	 */
	public function test_infers_missing_ability_from_ability_needed() {
		$result = Ahentic_AI::normalize_debug_next(
			array(
				'ability_needed' => 'ahentic/update-site-title',
				'tools_planned'  => array( array( 'name' => 'ahentic/list-plugins' ) ),
			)
		);

		$this->assertSame( 'missing_ability', $result['debug']['next'] );
		$this->assertSame( 'inferred_ability_needed', $result['reason'] );
	}

	/**
	 * A complete block with no tools is a reply.
	 */
	public function test_infers_reply_when_no_tools_and_not_truncated() {
		$result = Ahentic_AI::normalize_debug_next(
			array(
				'intention' => 'Summarizing',
				'thinking'  => 'Nothing left to do.',
			)
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'reply', $result['debug']['next'] );
		$this->assertSame( 'inferred_no_tools', $result['reason'] );
	}

	/**
	 * Safety: a truncated block lost its tools to the output limit, so an absent
	 * tools_planned is not evidence the model was finished. Inferring `reply` here
	 * would silently drop the write the model was mid-way through requesting.
	 */
	public function test_does_not_infer_reply_for_truncated_block() {
		$result = Ahentic_AI::normalize_debug_next(
			array(
				'intention' => 'Writing the article',
				'thinking'  => 'Draft the full post.',
			),
			true,
			'tools_planned'
		);

		$this->assertFalse( $result['changed'] );
		$this->assertArrayNotHasKey( 'next', $result['debug'] );
	}

	/**
	 * Safety: never infer `use_tools` from a tools_planned that was itself cut off,
	 * or the loop could execute a half-recovered write.
	 */
	public function test_does_not_infer_use_tools_when_tools_planned_was_truncated() {
		$result = Ahentic_AI::normalize_debug_next(
			array(
				'intention'     => 'Applying blocks',
				'tools_planned' => array( array( 'name' => 'ahentic-browser/set-blocks' ) ),
			),
			true,
			'tools_planned'
		);

		$this->assertFalse( $result['changed'] );
	}

	/**
	 * Truncation elsewhere still allows tool inference, since the tool list is intact.
	 */
	public function test_infers_use_tools_when_truncation_was_elsewhere() {
		$result = Ahentic_AI::normalize_debug_next(
			array(
				'tools_planned' => array( array( 'name' => 'ahentic/get-content' ) ),
			),
			true,
			'thinking'
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'use_tools', $result['debug']['next'] );
	}

	/**
	 * Empty and non-array payloads pass through untouched.
	 *
	 * @dataProvider provide_empty_payloads
	 * @param mixed $debug Payload.
	 */
	public function test_empty_payloads_pass_through( $debug ) {
		$result = Ahentic_AI::normalize_debug_next( $debug );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( $debug, $result['debug'] );
	}

	/**
	 * Payloads that carry no usable block.
	 *
	 * @return array
	 */
	public function provide_empty_payloads() {
		return array(
			array( null ),
			array( array() ),
			array( 'not an array' ),
		);
	}

	/**
	 * A non-scalar `next` must not raise a conversion error.
	 */
	public function test_non_scalar_next_is_treated_as_missing() {
		$result = Ahentic_AI::normalize_debug_next(
			array(
				'next'          => array( 'use_tools' ),
				'tools_planned' => array( array( 'name' => 'ahentic/get-content' ) ),
			)
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'use_tools', $result['debug']['next'] );
	}

	/**
	 * An unrecognized value with no other signal still resolves rather than retrying.
	 */
	public function test_unknown_next_without_tools_becomes_reply() {
		$result = Ahentic_AI::normalize_debug_next( array( 'next' => 'wrap_up_now' ) );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'reply', $result['debug']['next'] );
		$this->assertSame( 'inferred_no_tools', $result['reason'] );
	}
}
