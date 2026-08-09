<?php
/**
 * Characterization tests for Ahentic_Prompt_Assembler (move-only extract from Orchestrator).
 *
 * Pure seams: build_chat_payload, truncate_tool_result_for_prompt, excerpt.
 */

use PHPUnit\Framework\TestCase;

class PromptAssemblerTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-prompt-assembler.php';
		// Catalogs for sticky pack derivation (same ABSPATH bootstrap as other unit tests).
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-content.php';
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-browser.php';
	}

	public function test_excerpt_truncates_with_ellipsis() {
		$long = str_repeat( 'a', 50 );
		$out  = Ahentic_Prompt_Assembler::excerpt( $long, 10 );
		$this->assertLessThan( strlen( $long ), strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
	}

	public function test_truncate_tool_result_respects_cap() {
		$body = str_repeat( 'x', 100 );
		$out  = Ahentic_Prompt_Assembler::truncate_tool_result_for_prompt( $body, 20 );
		$this->assertLessThan( strlen( $body ), strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
		// Cap is applied to the ASCII body slice; ellipsis may add UTF-8 bytes.
		$this->assertStringStartsWith( str_repeat( 'x', 19 ), $out );
	}

	public function test_build_chat_payload_appends_trailing_tool_results_to_user() {
		$entries = array(
			array(
				'role'    => 'user',
				'content' => 'List plugins',
			),
			array(
				'role'    => 'tool',
				'content' => '{"ok":true,"plugins":[]}',
				'meta'    => array( 'ability' => 'ahentic/list-plugins' ),
			),
		);

		$built = Ahentic_Prompt_Assembler::build_chat_payload( $entries );

		$this->assertSame( array(), $built['history'] );
		$this->assertStringContainsString( 'List plugins', $built['user'] );
		$this->assertStringContainsString( '[Ability result: ahentic/list-plugins]', $built['user'] );
		$this->assertStringContainsString( '{"ok":true,"plugins":[]}', $built['user'] );
		$this->assertSame( 0, $built['superseded'] );
	}

	public function test_build_chat_payload_collapses_superseded_live_editor_snapshots() {
		$entries = array(
			array(
				'role'    => 'user',
				'content' => 'Edit the post',
			),
			array(
				'role'    => 'tool',
				'content' => '{"blocks":"old"}',
				'meta'    => array( 'ability' => 'ahentic-browser/get-blocks' ),
			),
			array(
				'role'    => 'tool',
				'content' => '{"blocks":"new"}',
				'meta'    => array( 'ability' => 'ahentic-browser/get-blocks' ),
			),
		);

		$built = Ahentic_Prompt_Assembler::build_chat_payload( $entries );

		$this->assertStringContainsString( 'Superseded', $built['user'] );
		$this->assertStringContainsString( '{"blocks":"new"}', $built['user'] );
		$this->assertSame( 1, $built['superseded'] );
	}

	public function test_build_chat_payload_supersedes_guidance_and_attr_updates() {
		$entries = array(
			array(
				'role'    => 'user',
				'content' => 'Fix headings then image',
			),
			array(
				'role'    => 'tool',
				'content' => str_repeat( 'G', 2000 ),
				'meta'    => array( 'ability' => 'ahentic/get-wordpress-guidance' ),
			),
			array(
				'role'    => 'tool',
				'content' => '{"ok":true,"old":true}',
				'meta'    => array( 'ability' => 'ahentic-browser/update-block-attributes' ),
			),
			array(
				'role'    => 'tool',
				'content' => '{"ok":true,"new":true}',
				'meta'    => array( 'ability' => 'ahentic-browser/update-block-attributes' ),
			),
			array(
				'role'    => 'tool',
				'content' => str_repeat( 'H', 2000 ),
				'meta'    => array( 'ability' => 'ahentic/get-wordpress-guidance' ),
			),
		);

		$built = Ahentic_Prompt_Assembler::build_chat_payload( $entries );

		$this->assertSame( 2, $built['superseded'] );
		$this->assertSame( 1, substr_count( $built['user'], str_repeat( 'H', 2000 ) ) );
		$this->assertStringNotContainsString( str_repeat( 'G', 2000 ), $built['user'] );
		$this->assertStringContainsString( '{"ok":true,"new":true}', $built['user'] );
		$this->assertStringContainsString( 'Superseded — a newer ahentic-browser/update-block-attributes', $built['user'] );
	}

	public function test_build_chat_payload_tightens_history_tool_results() {
		$fat = str_repeat( 'x', 5000 );
		$entries = array(
			array(
				'role'    => 'user',
				'content' => 'First ask',
			),
			array(
				'role'    => 'tool',
				'content' => $fat,
				'meta'    => array( 'ability' => 'ahentic-browser/get-blocks' ),
			),
			array(
				'role'    => 'user',
				'content' => 'Follow-up',
			),
		);

		$built = Ahentic_Prompt_Assembler::build_chat_payload( $entries );

		$tool_turn = null;
		foreach ( $built['history'] as $turn ) {
			$content = isset( $turn['content'] ) ? (string) $turn['content'] : '';
			if ( 0 === strpos( $content, '[Ability result: ahentic-browser/get-blocks]' ) ) {
				$tool_turn = $content;
				break;
			}
		}
		$this->assertNotNull( $tool_turn );
		$this->assertLessThanOrEqual(
			Ahentic_Prompt_Assembler::MAX_TOOL_RESULT_CHARS_HISTORY + 80,
			strlen( $tool_turn )
		);
		$this->assertStringContainsString( '…', $tool_turn );
		$this->assertSame( 'Follow-up', $built['user'] );
	}

	/**
	 * After research is done (ready draft), get-content bodies collapse to id/title/url cards.
	 */
	public function test_build_chat_payload_collapses_research_when_flagged() {
		$fat_body = str_repeat( 'Paragraph about commuting. ', 200 );
		$entries  = array(
			array(
				'role'    => 'user',
				'content' => 'Write the article',
			),
			array(
				'role'    => 'tool',
				'content' => json_encode(
					array(
						'id'       => 1168,
						'title'    => 'Commute tips',
						'type'     => 'post',
						'status'   => 'publish',
						'view_url' => 'https://example.com/commute/',
						'content'  => $fat_body,
					)
				),
				'meta'    => array( 'ability' => 'ahentic/get-content' ),
			),
			array(
				'role'    => 'tool',
				'content' => '{"ok":true,"key":"article_draft","status":"ready"}',
				'meta'    => array( 'ability' => 'ahentic/stage-artifact' ),
			),
		);

		$full = Ahentic_Prompt_Assembler::build_chat_payload( $entries );
		$this->assertStringContainsString( 'Paragraph about commuting', $full['user'] );

		$collapsed = Ahentic_Prompt_Assembler::build_chat_payload(
			$entries,
			array( 'collapse_research' => true )
		);
		$this->assertStringContainsString( 'Commute tips', $collapsed['user'] );
		$this->assertStringContainsString( '"id":1168', $collapsed['user'] );
		$this->assertStringNotContainsString( 'Paragraph about commuting', $collapsed['user'] );
		$this->assertLessThan(
			Ahentic_Prompt_Assembler::MAX_TOOL_RESULT_CHARS_RESEARCH + 100,
			strlen( self::extract_ability_result_body( $collapsed['user'], 'ahentic/get-content' ) )
		);
	}

	/**
	 * list-content research rows collapse to id/title/url (no fat excerpts).
	 */
	public function test_compact_research_tool_body_collapses_list_content() {
		$items = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$items[] = array(
				'id'       => $i,
				'title'    => 'Post ' . $i,
				'view_url' => 'https://example.com/' . $i . '/',
				'excerpt'  => str_repeat( 'Long excerpt body. ', 80 ),
			);
		}
		$raw = json_encode( array( 'items' => $items, 'total' => 3 ) );
		$out = Ahentic_Prompt_Assembler::compact_research_tool_body( 'ahentic/list-content', $raw );

		$this->assertStringContainsString( 'Post 1', $out );
		$this->assertStringContainsString( '"id":1', $out );
		$this->assertStringNotContainsString( 'Long excerpt body', $out );
		$this->assertLessThanOrEqual(
			Ahentic_Prompt_Assembler::MAX_TOOL_RESULT_CHARS_RESEARCH + 80,
			strlen( $out )
		);
	}

	/**
	 * Research-collapse catalog stays aligned with get/list (+ summary) content reads.
	 */
	public function test_ability_is_research_body_catalog() {
		$this->assertTrue( Ahentic_Prompt_Assembler::ability_is_research_body( 'ahentic/get-content' ) );
		$this->assertTrue( Ahentic_Prompt_Assembler::ability_is_research_body( 'ahentic/list-content' ) );
		$this->assertTrue( Ahentic_Prompt_Assembler::ability_is_research_body( 'ahentic/get-content-summary' ) );
		$this->assertFalse( Ahentic_Prompt_Assembler::ability_is_research_body( 'ahentic/stage-artifact' ) );
		$this->assertFalse( Ahentic_Prompt_Assembler::ability_is_research_body( 'ahentic-browser/get-blocks' ) );
	}

	/**
	 * Collapse gate is off for invalid sessions (pointer walk needs a real session).
	 */
	public function test_session_should_collapse_research_rejects_invalid_session() {
		$this->assertFalse( Ahentic_Prompt_Assembler::session_should_collapse_research( 0 ) );
		$this->assertFalse( Ahentic_Prompt_Assembler::session_should_collapse_research( -1 ) );
	}

	/**
	 * @param string $user    Assembled user payload.
	 * @param string $ability Ability name.
	 * @return string
	 */
	private static function extract_ability_result_body( $user, $ability ) {
		$marker = '[Ability result: ' . $ability . "]\n";
		$pos    = strpos( $user, $marker );
		if ( false === $pos ) {
			return '';
		}
		$start = $pos + strlen( $marker );
		$next  = strpos( $user, "\n\n[Ability result:", $start );
		if ( false === $next ) {
			return substr( $user, $start );
		}
		return substr( $user, $start, $next - $start );
	}

	public function test_tool_result_cap_for_prompt_history_vs_trailing() {
		$this->assertSame(
			Ahentic_Prompt_Assembler::MAX_TOOL_RESULT_CHARS_HISTORY,
			Ahentic_Prompt_Assembler::tool_result_cap_for_prompt( 'ahentic-browser/get-blocks', false )
		);
		$this->assertSame(
			Ahentic_Prompt_Assembler::MAX_TOOL_RESULT_CHARS_SNAPSHOT,
			Ahentic_Prompt_Assembler::tool_result_cap_for_prompt( 'ahentic-browser/get-blocks', true )
		);
		$this->assertLessThanOrEqual(
			3500,
			Ahentic_Prompt_Assembler::tool_result_cap_for_prompt( 'ahentic/get-wordpress-guidance', true )
		);
		$this->assertLessThanOrEqual(
			3500,
			Ahentic_Prompt_Assembler::tool_result_cap_for_prompt( 'ahentic/list-content', true )
		);
		$this->assertSame(
			Ahentic_Prompt_Assembler::MAX_TOOL_RESULT_CHARS_HISTORY,
			Ahentic_Prompt_Assembler::tool_result_cap_for_prompt( 'ahentic/list-content', false )
		);
	}

	public function test_build_chat_payload_caps_trailing_list_content() {
		$fat = str_repeat( 'L', 6000 );
		$entries = array(
			array(
				'role'    => 'user',
				'content' => 'Add internal links',
			),
			array(
				'role'    => 'tool',
				'content' => $fat,
				'meta'    => array( 'ability' => 'ahentic/list-content' ),
			),
		);

		$built = Ahentic_Prompt_Assembler::build_chat_payload( $entries );

		$this->assertNotEmpty( $built['clipped'] );
		$this->assertSame( 'ahentic/list-content', $built['clipped'][0]['ability'] );
		$this->assertSame( 3500, $built['clipped'][0]['cap'] );
		$this->assertStringContainsString( '[Ability result: ahentic/list-content]', $built['user'] );
		$this->assertStringContainsString( '…', $built['user'] );
		$this->assertStringNotContainsString( str_repeat( 'L', 4000 ), $built['user'] );
	}

	public function test_chars_to_tokens_rounds_up() {
		$this->assertSame( 0, Ahentic_Prompt_Assembler::chars_to_tokens( 0 ) );
		$this->assertSame( 1, Ahentic_Prompt_Assembler::chars_to_tokens( 1 ) );
		$this->assertSame( 1, Ahentic_Prompt_Assembler::chars_to_tokens( 4 ) );
		$this->assertSame( 2, Ahentic_Prompt_Assembler::chars_to_tokens( 5 ) );
	}

	public function test_usage_from_bucket_chars_reports_percent_against_budget() {
		$chars = array(
			'system_prompt'     => 400, // 100 tokens
			'ability_schemas'   => 0,
			'chat_turns'        => 0,
			'tool_results'      => 0,
			'page_context'      => 0,
			'plan_artifacts'    => 0,
			'compacted_summary' => 0,
		);
		$usage = Ahentic_Prompt_Assembler::usage_from_bucket_chars( $chars );

		$this->assertSame( Ahentic_Prompt_Assembler::CONTEXT_BUDGET_TOKENS, $usage['budgetTokens'] );
		$this->assertSame( 100, $usage['usedTokens'] );
		$this->assertSame( 100, $usage['buckets']['system_prompt']['tokens'] );
		$this->assertSame( 0, $usage['percent'] ); // 100 / 200000 rounds to 0%
	}

	public function test_bucket_for_turn_classifies_tool_and_summary() {
		$this->assertSame(
			'compacted_summary',
			Ahentic_Prompt_Assembler::bucket_for_turn(
				array( 'content' => '[Earlier in this session — compact summary]' )
			)
		);
		$this->assertSame(
			'tool_results',
			Ahentic_Prompt_Assembler::bucket_for_turn(
				array( 'content' => '[Ability result: ahentic/list-plugins]\n{}' )
			)
		);
		$this->assertSame(
			'chat_turns',
			Ahentic_Prompt_Assembler::bucket_for_turn(
				array( 'content' => 'Hello' )
			)
		);
	}

	public function test_compact_fill_ratio_threshold() {
		$threshold = (int) floor(
			Ahentic_Prompt_Assembler::CONTEXT_BUDGET_TOKENS * Ahentic_Prompt_Assembler::COMPACT_FILL_RATIO
		);
		$this->assertSame( 170000, $threshold );
	}

	public function test_select_tool_routing_packs_gates_by_page_context() {
		$editor = Ahentic_Prompt_Assembler::select_tool_routing_packs(
			array(
				'is_block_editor' => true,
				'url'             => 'https://example.com/wp-admin/post.php?post=1&action=edit',
			),
			false
		);
		$this->assertSame( array( 'core', 'content', 'editor', 'media' ), $editor );

		$dashboard = Ahentic_Prompt_Assembler::select_tool_routing_packs(
			array(
				'is_block_editor' => false,
				'url'             => 'https://example.com/wp-admin/index.php',
			),
			false
		);
		// Dashboard: core only — content/editor essays are not always-on.
		$this->assertSame( array( 'core' ), $dashboard );
		$this->assertNotContains( 'menus', $dashboard );
		$this->assertNotContains( 'users', $dashboard );

		$plugins = Ahentic_Prompt_Assembler::select_tool_routing_packs(
			array(
				'is_block_editor' => false,
				'url'             => 'https://example.com/wp-admin/plugins.php',
			),
			false
		);
		$this->assertContains( 'plugins', $plugins );
		$this->assertContains( 'core', $plugins );
		$this->assertNotContains( 'editor', $plugins );
		$this->assertNotContains( 'content', $plugins );
	}

	public function test_empty_page_context_bootstraps_content_not_editor_media() {
		$packs = Ahentic_Prompt_Assembler::select_tool_routing_packs( array(), false );
		$this->assertSame( array( 'core', 'content' ), $packs );
		$this->assertNotContains( 'editor', $packs );
		$this->assertNotContains( 'media', $packs );
	}

	public function test_posts_list_screen_adds_content_pack() {
		$packs = Ahentic_Prompt_Assembler::select_tool_routing_packs(
			array(
				'is_block_editor' => false,
				'url'             => 'https://example.com/wp-admin/edit.php',
			),
			false
		);
		$this->assertContains( 'core', $packs );
		$this->assertContains( 'content', $packs );
		$this->assertNotContains( 'editor', $packs );
	}

	public function test_recent_abilities_sticky_editor_and_content_packs() {
		$packs = Ahentic_Prompt_Assembler::select_tool_routing_packs(
			array(
				'is_block_editor' => false,
				'url'             => 'https://example.com/wp-admin/index.php',
			),
			false,
			array( 'ahentic/list-content', 'ahentic-browser/set-blocks' )
		);
		$this->assertContains( 'content', $packs );
		$this->assertContains( 'editor', $packs );
		// Sticky editor alone must not force media (media needs media abilities / editor screen / content work).
		$this->assertNotContains( 'media', $packs );
	}

	public function test_compose_system_prompt_orders_stable_prefix_then_variable_suffix() {
		$out = Ahentic_Prompt_Assembler::compose_system_prompt(
			array(
				'core'      => '[CORE]',
				'abilities' => '[ABILITIES]',
				'routing'   => '[ROUTING]',
				'plan'      => '[PLAN]',
			)
		);
		$this->assertSame( '[CORE][ABILITIES][ROUTING][PLAN]', $out );
		// PHPUnit assertLessThan( $expected, $actual ): actual < expected.
		$this->assertLessThan( strpos( $out, '[PLAN]' ), strpos( $out, '[ROUTING]' ) );
		$this->assertLessThan( strpos( $out, '[ROUTING]' ), strpos( $out, '[ABILITIES]' ) );
	}

	public function test_recent_ability_names_from_entries_reads_trailing_tools() {
		$names = Ahentic_Prompt_Assembler::recent_ability_names_from_entries(
			array(
				array(
					'role'    => 'user',
					'content' => 'Link posts',
				),
				array(
					'role'    => 'tool',
					'content' => '{}',
					'meta'    => array( 'ability' => 'ahentic/list-content' ),
				),
				array(
					'role'    => 'tool',
					'content' => '{}',
					'meta'    => array( 'ability' => 'ahentic-browser/get-blocks' ),
				),
			)
		);
		$this->assertSame( array( 'ahentic/list-content', 'ahentic-browser/get-blocks' ), $names );
	}

	public function test_format_abilities_index_groups_namespaces() {
		$out = Ahentic_Prompt_Assembler::format_abilities_index(
			array(
				'ahentic/list-content',
				'ahentic/get-content',
				'ahentic-browser/set-blocks',
			)
		);
		$this->assertStringContainsString( 'ahentic/* (list-content, get-content)', $out );
		$this->assertStringContainsString( 'ahentic-browser/* (set-blocks)', $out );
	}

	public function test_tool_routing_guidance_for_packs_is_smaller_than_full() {
		$editor = Ahentic_Prompt_Assembler::tool_routing_guidance_for_packs(
			array( 'core', 'content', 'editor', 'media' )
		);
		$full = Ahentic_Prompt_Assembler::tool_routing_guidance_for_packs(
			array( 'core', 'content', 'editor', 'media', 'plugins', 'settings', 'users', 'menus', 'http' )
		);
		$this->assertLessThan( strlen( $full ), strlen( $editor ) );
		$this->assertStringNotContainsString( 'Prefer ahentic/list-menus', $editor );
		$this->assertStringContainsString( 'CRITICAL — content routing by page context', $editor );
	}

	public function test_editor_routing_guidance_stays_under_token_budget() {
		// Regression guard from measured post-fix baseline (~2846 tokens for editor packs).
		// Fail if ungated prose creeps back into the default content/editor path.
		$packs = Ahentic_Prompt_Assembler::select_tool_routing_packs(
			array(
				'is_block_editor' => true,
				'url'             => 'https://example.com/wp-admin/post.php?post=1&action=edit',
			),
			false
		);
		$guidance = Ahentic_Prompt_Assembler::tool_routing_guidance_for_packs( $packs );
		$tokens   = Ahentic_Prompt_Assembler::chars_to_tokens( strlen( $guidance ) );

		$this->assertSame( array( 'core', 'content', 'editor', 'media' ), $packs );
		$this->assertLessThanOrEqual( 2700, $tokens );
		$this->assertStringNotContainsString( 'Prefer ahentic/list-users', $guidance );
		$this->assertStringNotContainsString( 'Prefer ahentic/list-menus', $guidance );
		$this->assertStringNotContainsString( 'Prefer ahentic/list-plugins', $guidance );
		$this->assertStringContainsString( 'prefer per_page 10–20', $guidance );
		$this->assertStringContainsString( 'do NOT re-call list-content', $guidance );
		$this->assertStringContainsString( 'get-blocks with {"refs"', $guidance );
		$this->assertStringContainsString( 'ahentic/get-content-summary', $guidance );
		$this->assertStringContainsString( 'update-block-attributes', $guidance );
		$this->assertStringContainsString( 'never get-content solely to pick a link target', $guidance );
		$this->assertStringContainsString( 'batch all update-block-attributes in one tools_planned', $guidance );
		$this->assertStringContainsString( 'ONE tools_planned with final HTML', $guidance );
		$this->assertStringContainsString( 'ahentic/generate-image', $guidance );
		$this->assertStringContainsString( 'upload-media', $guidance );
		$this->assertStringContainsString( 'set-featured-image', $guidance );
	}

	public function test_content_work_adds_editor_pack_off_editor_screen() {
		$packs = Ahentic_Prompt_Assembler::select_tool_routing_packs(
			array(
				'is_block_editor' => false,
				'url'             => 'https://example.com/wp-admin/index.php',
			),
			true
		);
		$this->assertContains( 'editor', $packs );
		$this->assertContains( 'content', $packs );
		$this->assertNotContains( 'media', $packs, 'content_work alone must not pull the media essay' );
	}
}
