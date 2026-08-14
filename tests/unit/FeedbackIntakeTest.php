<?php
/**
 * Pure tests for Run feedback scrub / debug-pack helpers.
 *
 * @package Ahentic
 */

require_once dirname( __DIR__, 2 ) . '/src/admin/class-feedback-intake.php';

/**
 * @covers Ahentic_Feedback_Intake
 */
class FeedbackIntakeTest extends PHPUnit\Framework\TestCase {

	public function test_compute_mint_proof_matches_prd_vector() {
		$nonce = str_repeat( 'ab', 32 );
		$proof = Ahentic_Feedback_Intake::compute_mint_proof( $nonce, 1700000000, 'test-mint-key' );
		$this->assertSame(
			'c6864bb64a35439dc9038f3ef43c6f865e6d17b3f71cdef8efb7c000e5c6f7af',
			$proof
		);
	}

	public function test_scrub_text_redacts_email_ip_url_and_secrets() {
		$raw = 'Contact me@example.com from 203.0.113.9 at https://evil.example/path with sk-abc123XYZ';
		$out = Ahentic_Feedback_Intake::scrub_text( $raw );
		$this->assertStringNotContainsString( 'me@example.com', $out );
		$this->assertStringNotContainsString( '203.0.113.9', $out );
		$this->assertStringNotContainsString( 'https://evil.example/path', $out );
		$this->assertStringNotContainsString( 'sk-abc123XYZ', $out );
		$this->assertStringContainsString( '[EMAIL]', $out );
		$this->assertStringContainsString( '[IP]', $out );
		$this->assertStringContainsString( '[URL]', $out );
		$this->assertStringContainsString( '[SECRET]', $out );
	}

	public function test_build_debug_pack_drops_heartbeat_and_scrubs_env() {
		$events = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$events[] = array(
				'type'    => 'heartbeat',
				'summary' => 'tick',
				'data'    => array(),
			);
		}
		$events[] = array(
			'type'    => 'tool',
			'summary' => 'Ran ahentic/get-site-snapshot',
			'data'    => array(
				'ability' => 'ahentic/get-site-snapshot',
				'ok'      => true,
			),
		);
		$events[] = array(
			'type'    => 'error',
			'summary' => 'Model failed',
			'data'    => array( 'message' => 'boom' ),
		);

		$pack = Ahentic_Feedback_Intake::build_debug_pack(
			array(
				'exportedAt'  => '2026-01-01T00:00:00+00:00',
				'environment' => array(
					'plugin'   => '0.1.0',
					'wp'       => '6.8',
					'php'      => '8.2',
					'aiClient' => 'core',
					'siteUrl'  => 'https://should-not-leak.example',
				),
				'session'     => array( 'status' => 'idle', 'lastError' => 'fail' ),
				'state'       => array( 'jobResumable' => true ),
				'trace'       => $events,
			)
		);

		$this->assertStringNotContainsString( 'heartbeat', $pack );
		$this->assertStringNotContainsString( 'should-not-leak', $pack );
		$this->assertStringContainsString( 'ahentic/get-site-snapshot', $pack );
		$this->assertStringContainsString( 'Model failed', $pack );
		$this->assertStringNotContainsString( 'truncated for intake pack cap', $pack );
	}

	public function test_build_debug_pack_caps_at_debug_pack_max_bytes() {
		$events = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$events[] = array(
				'type'    => 'tool',
				'summary' => 'Ran tool ' . $i . ' ' . str_repeat( 'x', 200 ),
				'data'    => array(
					'ability' => 'ahentic/example-ability',
					'ok'      => true,
					'detail'  => str_repeat( 'payload-' . $i . '-', 20 ),
				),
			);
		}

		// Force an oversized pack via a huge scrubbed summary string in events.
		for ( $i = 0; $i < 5000; $i++ ) {
			$events[] = array(
				'type'    => 'error',
				'summary' => str_repeat( 'E', 500 ),
				'data'    => array( 'message' => str_repeat( 'm', 700 ) ),
			);
		}

		$pack = Ahentic_Feedback_Intake::build_debug_pack(
			array(
				'exportedAt'  => '2026-01-01T00:00:00+00:00',
				'environment' => array( 'plugin' => '0.1.0' ),
				'session'     => array( 'status' => 'idle' ),
				'state'       => array(),
				'trace'       => $events,
			)
		);

		$this->assertLessThanOrEqual( Ahentic_Feedback_Intake::DEBUG_PACK_MAX_BYTES, strlen( $pack ) );
		$this->assertStringContainsString( 'truncated for intake pack cap', $pack );
	}

	public function test_append_user_note_to_summary_adds_labeled_block() {
		$out = Ahentic_Feedback_Intake::append_user_note_to_summary(
			'AI said the run stalled.',
			'It edited the wrong page.'
		);
		$this->assertSame(
			"AI said the run stalled.\n\nUser note: It edited the wrong page.",
			$out
		);
	}

	public function test_append_user_note_to_summary_ignores_blank_and_scrubs() {
		$this->assertSame(
			'Only AI.',
			Ahentic_Feedback_Intake::append_user_note_to_summary( 'Only AI.', "  \n\t  " )
		);
		$out = Ahentic_Feedback_Intake::append_user_note_to_summary(
			'Base',
			'Ping me@example.com'
		);
		$this->assertStringNotContainsString( 'me@example.com', $out );
		$this->assertStringContainsString( '[EMAIL]', $out );
		$this->assertStringStartsWith( "Base\n\nUser note: ", $out );
	}

	public function test_format_conversation_excerpt_lists_user_and_ahentic_turns() {
		$out = Ahentic_Feedback_Intake::format_conversation_excerpt(
			array(
				array(
					'role'    => 'user',
					'content' => 'Edit the homepage hero.',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Updated the hero copy.',
				),
				array(
					'role'    => 'tool',
					'content' => 'should be skipped',
				),
				array(
					'role'    => 'user',
					'content' => 'Also fix the CTA.',
				),
				array(
					'role'    => 'assistant',
					'content' => 'CTA label changed.',
					'meta'    => array( 'intermediate' => true ),
				),
				array(
					'role'    => 'assistant',
					'content' => 'Done — CTA now says Get started.',
				),
			)
		);

		$this->assertSame(
			"| entity | prompt/reply |\n"
			. "| --- | --- |\n"
			. "| User | Edit the homepage hero. |\n"
			. "| Ahentic | Updated the hero copy. |\n"
			. "| User | Also fix the CTA. |\n"
			. '| Ahentic | Done — CTA now says Get started. |',
			$out
		);
	}

	public function test_format_conversation_excerpt_escapes_pipes_in_cells() {
		$out = Ahentic_Feedback_Intake::format_conversation_excerpt(
			array(
				array(
					'role'    => 'user',
					'content' => 'Use A | B for the title',
				),
				array(
					'role'    => 'assistant',
					'content' => "Line one\nLine two",
				),
			)
		);

		$this->assertSame(
			"| entity | prompt/reply |\n"
			. "| --- | --- |\n"
			. "| User | Use A \\| B for the title |\n"
			. '| Ahentic | Line one Line two |',
			$out
		);
	}

	public function test_format_conversation_excerpt_scrubs_and_summarizes_long_ahentic_replies() {
		$long = 'First sentence answers the ask. '
			. 'Second sentence adds detail. '
			. str_repeat( 'More filler about the site change. ', 40 )
			. 'Contact admin@example.com for follow-up.';

		$out = Ahentic_Feedback_Intake::format_conversation_excerpt(
			array(
				array(
					'role'    => 'user',
					'content' => 'Email me@example.com about https://evil.example/path',
				),
				array(
					'role'    => 'assistant',
					'content' => $long,
				),
			)
		);

		$this->assertStringContainsString( '| User | Email [EMAIL] about [URL] |', $out );
		$this->assertStringContainsString(
			'| Ahentic | First sentence answers the ask. Second sentence adds detail.… |',
			$out
		);
		$this->assertStringNotContainsString( 'admin@example.com', $out );
		$this->assertStringNotContainsString( 'More filler about the site change.', $out );
		$prefix       = '| Ahentic | ';
		$ahentic_pos  = strpos( $out, $prefix );
		$ahentic_cell = substr( $out, $ahentic_pos + strlen( $prefix ) );
		$ahentic_cell = rtrim( $ahentic_cell, " |\n" );
		$this->assertLessThanOrEqual(
			Ahentic_Feedback_Intake::ASSISTANT_REPLY_SUMMARY_MAX + 1,
			strlen( $ahentic_cell )
		);
	}

	public function test_build_draft_summary_prompts_prioritize_intent_and_user_note() {
		$prompts = Ahentic_Feedback_Intake::build_draft_summary_prompts(
			array(
				'session' => array(
					'status'    => 'idle',
					'lastError' => '',
				),
				'state'   => array(
					'activeGoal'   => 'philippines',
					'jobResumable' => false,
				),
				'trace'   => array(
					array(
						'type' => 'tool',
						'data' => array( 'ability' => 'ahentic/update-option' ),
					),
				),
			),
			"| entity | prompt/reply |\n| --- | --- |\n| User | Set timezone to Manila. |\n| Ahentic | Updated timezone. |",
			'Timezone change succeeded but it still asked for a missing ability'
		);

		$this->assertStringContainsString( 'generalize the failure mode', $prompts['system'] );
		$this->assertStringContainsString( 'trust the user note', $prompts['system'] );
		$this->assertStringContainsString( 'false missing-ability notice after success', $prompts['system'] );
		$this->assertStringContainsString(
			'User note (highest signal when present): Timezone change succeeded but it still asked for a missing ability',
			$prompts['user']
		);
		$this->assertStringContainsString( 'Conversation:', $prompts['user'] );
		$this->assertStringContainsString( 'Set timezone to Manila', $prompts['user'] );
		$this->assertStringContainsString( 'Active goal (may be partial/stale): philippines', $prompts['user'] );
		$this->assertStringContainsString( 'Session status (context only, not the bug): idle', $prompts['user'] );
		$this->assertStringContainsString( 'Abilities used: ahentic/update-option', $prompts['user'] );
		$this->assertStringNotContainsString( 'me@example.com', $prompts['user'] );
	}

	public function test_build_draft_summary_prompts_scrubs_user_note() {
		$prompts = Ahentic_Feedback_Intake::build_draft_summary_prompts(
			array(
				'session' => array( 'status' => 'idle' ),
				'state'   => array(),
				'trace'   => array(),
			),
			'',
			'Ping me@example.com about the failure'
		);
		$this->assertStringContainsString( '[EMAIL]', $prompts['user'] );
		$this->assertStringNotContainsString( 'me@example.com', $prompts['user'] );
	}

	public function test_build_draft_summary_prompts_include_page_blocks_and_hypothesis() {
		$prompts = Ahentic_Feedback_Intake::build_draft_summary_prompts(
			array(
				'session' => array( 'status' => 'idle' ),
				'state'   => array(),
				'trace'   => array(),
			),
			"| entity | prompt/reply |\n| --- | --- |\n| User | Change the hero heading. |",
			'Heading never changed',
			array(
				'page_context'    => array(
					'pathname'        => '/wp-admin/post.php',
					'url'             => 'https://should-not-leak.example/wp-admin/post.php',
					'is_block_editor' => true,
					'post_type'       => 'page',
					'blocks_count'    => 4,
					'is_dirty'        => true,
				),
				'editor_snapshot' => array(
					'available' => true,
					'blocks'    => array(
						'count' => 1,
						'blocks' => array(
							array(
								'name'    => 'core/heading',
								'preview' => 'Welcome ping me@example.com',
							),
						),
					),
				),
				'observations'    => array(
					array(
						'code'   => 'block_editor_open',
						'detail' => 'post_type=page blocks_count=4 dirty',
					),
				),
			)
		);

		$this->assertStringContainsString( '"hypothesis"', $prompts['system'] );
		$this->assertStringContainsString( 'unconfirmed', $prompts['system'] );
		$this->assertStringContainsString( '/wp-admin/post.php', $prompts['user'] );
		$this->assertStringContainsString( 'core/heading', $prompts['user'] );
		$this->assertStringContainsString( 'block_editor_open', $prompts['user'] );
		$this->assertStringNotContainsString( 'should-not-leak.example', $prompts['user'] );
		$this->assertStringNotContainsString( 'me@example.com', $prompts['user'] );
		$this->assertStringContainsString( '[EMAIL]', $prompts['user'] );
	}

	public function test_decode_draft_payload_reads_hypothesis() {
		$parsed = Ahentic_Feedback_Intake::decode_draft_payload(
			'{"title":"Wrong page edited","summary":"User asked to change the hero.","hypothesis":"set-blocks targeted a different document."}'
		);
		$this->assertSame( 'Wrong page edited', $parsed['title'] );
		$this->assertSame( 'User asked to change the hero.', $parsed['summary'] );
		$this->assertSame( 'set-blocks targeted a different document.', $parsed['hypothesis'] );
	}

	public function test_decode_draft_payload_hypothesis_optional() {
		$parsed = Ahentic_Feedback_Intake::decode_draft_payload(
			'{"title":"Unsure Ahentic run","summary":"The run finished idle."}'
		);
		$this->assertSame( 'Unsure Ahentic run', $parsed['title'] );
		$this->assertSame( '', $parsed['hypothesis'] );
	}

	public function test_append_hypothesis_to_summary_labels_unconfirmed() {
		$out = Ahentic_Feedback_Intake::append_hypothesis_to_summary(
			'User wanted a hero change.',
			'Agent wrote via update-post while the block editor was open.'
		);
		$this->assertSame(
			"User wanted a hero change.\n\nHypothesis (unconfirmed): Agent wrote via update-post while the block editor was open.",
			$out
		);
	}

	public function test_build_debug_pack_includes_page_snapshot_and_hypothesis() {
		$pack = Ahentic_Feedback_Intake::build_debug_pack(
			array(
				'exportedAt'      => '2026-01-01T00:00:00+00:00',
				'environment'     => array( 'plugin' => '0.1.0' ),
				'session'         => array( 'status' => 'idle' ),
				'state'           => array(),
				'trace'           => array(),
				'page'            => array(
					'pathname'        => '/wp-admin/post.php',
					'url'             => 'https://should-not-leak.example/wp-admin/post.php',
					'is_block_editor' => true,
					'post_type'       => 'page',
				),
				'editor_snapshot' => array(
					'available' => true,
					'blocks'    => array(
						'blocks' => array(
							array(
								'name'    => 'core/heading',
								'preview' => 'Hello me@example.com',
							),
						),
					),
				),
				'observations'    => array(
					array(
						'code'   => 'block_editor_open',
						'detail' => 'post_type=page',
					),
				),
				'hypothesis'      => 'Wrote the wrong heading.',
			)
		);

		$this->assertStringContainsString( '/wp-admin/post.php', $pack );
		$this->assertStringContainsString( 'core/heading', $pack );
		$this->assertStringContainsString( 'Wrote the wrong heading.', $pack );
		$this->assertStringContainsString( 'block_editor_open', $pack );
		$this->assertStringNotContainsString( 'should-not-leak.example', $pack );
		$this->assertStringNotContainsString( 'me@example.com', $pack );
	}

	public function test_normalize_report_kind_defaults_to_failure() {
		$this->assertSame( Ahentic_Feedback_Intake::KIND_SUCCESS, Ahentic_Feedback_Intake::normalize_report_kind( 'success' ) );
		$this->assertSame( Ahentic_Feedback_Intake::KIND_FAILURE, Ahentic_Feedback_Intake::normalize_report_kind( 'failure' ) );
		$this->assertSame( Ahentic_Feedback_Intake::KIND_FAILURE, Ahentic_Feedback_Intake::normalize_report_kind( '' ) );
		$this->assertSame( Ahentic_Feedback_Intake::KIND_FAILURE, Ahentic_Feedback_Intake::normalize_report_kind( 'nope' ) );
	}

	public function test_duplicate_search_query_uses_kind_label() {
		$this->assertSame(
			'repo:gambitph/Ahentic label:run-feedback is:open',
			Ahentic_Feedback_Intake::duplicate_search_query()
		);
		$this->assertSame(
			'repo:gambitph/Ahentic label:run-success is:open Installed an SEO plugin',
			Ahentic_Feedback_Intake::duplicate_search_query(
				'Installed an SEO plugin',
				Ahentic_Feedback_Intake::LABEL_SUCCESS
			)
		);
	}

	public function test_sanitize_playbook_ids_kebabs_and_caps_at_two() {
		$this->assertSame(
			array( 'plugin-hygiene', 'seo-decisioning' ),
			Ahentic_Feedback_Intake::sanitize_playbook_ids(
				array( 'Plugin-Hygiene', 'seo-decisioning', 'plugin-hygiene', 'too many', '!!!' )
			)
		);
		$this->assertSame( array(), Ahentic_Feedback_Intake::sanitize_playbook_ids( 'plugin-hygiene' ) );
	}

	public function test_work_excerpt_from_trace_is_ordered_with_ok_fail() {
		$excerpt = Ahentic_Feedback_Intake::work_excerpt_from_trace(
			array(
				array(
					'type' => 'tool',
					'data' => array(
						'ability' => 'ahentic/list-plugins',
						'ok'      => true,
					),
				),
				array(
					'type' => 'heartbeat',
					'data' => array(),
				),
				array(
					'type' => 'tool',
					'data' => array(
						'ability' => 'ahentic/install-plugin',
						'ok'      => false,
					),
				),
				array(
					'type' => 'tool',
					'data' => array(
						'ability' => 'ahentic/list-plugins',
						'ok'      => true,
					),
				),
			)
		);
		$this->assertSame(
			"ahentic/list-plugins ok\nahentic/install-plugin fail\nahentic/list-plugins ok",
			$excerpt
		);
	}

	public function test_build_draft_summary_prompts_success_narrates_and_keeps_uncertain_abilities() {
		$prompts = Ahentic_Feedback_Intake::build_draft_summary_prompts(
			array(
				'session' => array(
					'status'    => 'idle',
					'lastError' => '',
				),
				'state'   => array(
					'activeGoal'   => 'Install Yoast',
					'jobResumable' => false,
				),
				'trace'   => array(
					array(
						'type' => 'tool',
						'data' => array(
							'ability' => 'ahentic/list-plugins',
							'ok'      => true,
						),
					),
					array(
						'type' => 'tool',
						'data' => array(
							'ability' => 'ahentic/install-plugin',
							'ok'      => true,
						),
					),
				),
			),
			"| entity | prompt/reply |\n| --- | --- |\n| User | Add an SEO plugin. |\n| Ahentic | Installed Yoast. |",
			'',
			array(),
			Ahentic_Feedback_Intake::KIND_SUCCESS
		);

		$this->assertStringContainsString( 'successful Ahentic runs', $prompts['system'] );
		$this->assertStringContainsString( 'correct WordPress path', $prompts['system'] );
		$this->assertStringContainsString( 'user goal', $prompts['system'] );
		$this->assertStringContainsString( 'only when you are certain', $prompts['system'] );
		$this->assertStringContainsString( 'uncertainty, keep it', $prompts['system'] );
		$this->assertStringNotContainsString( 'failure mode', $prompts['system'] );
		$this->assertStringNotContainsString( 'hypothesis', $prompts['system'] );
		$this->assertStringNotContainsString( 'Last error', $prompts['user'] );
		$this->assertStringNotContainsString( 'Job resumable', $prompts['user'] );
		$this->assertStringContainsString( 'Work (ordered abilities', $prompts['user'] );
		$this->assertStringContainsString( 'ahentic/list-plugins ok', $prompts['user'] );
		$this->assertStringContainsString( 'ahentic/install-plugin ok', $prompts['user'] );
		$this->assertStringContainsString( 'Add an SEO plugin', $prompts['user'] );
	}

	public function test_decode_draft_payload_reads_abilities() {
		$parsed = Ahentic_Feedback_Intake::decode_draft_payload(
			'{"title":"Installed an SEO plugin","summary":"User wanted SEO. Ahentic listed plugins then installed Yoast.","abilities":["ahentic/list-plugins","ahentic/install-plugin"]}'
		);
		$this->assertSame( 'Installed an SEO plugin', $parsed['title'] );
		$this->assertSame(
			array( 'ahentic/list-plugins', 'ahentic/install-plugin' ),
			$parsed['abilities']
		);
	}

	public function test_resolve_abilities_mentioned_keeps_trace_order_and_drops_inventions() {
		$trace_names = array( 'ahentic/list-plugins', 'ahentic/install-plugin', 'ahentic/get-site-snapshot' );
		$this->assertSame(
			array( 'ahentic/list-plugins', 'ahentic/install-plugin' ),
			Ahentic_Feedback_Intake::resolve_abilities_mentioned(
				array( 'ahentic/list-plugins', 'ahentic/invented', 'ahentic/install-plugin' ),
				$trace_names
			)
		);
		$this->assertSame(
			$trace_names,
			Ahentic_Feedback_Intake::resolve_abilities_mentioned( array(), $trace_names )
		);
	}

	public function test_build_intake_report_body_omits_debug_pack_on_success() {
		$failure = Ahentic_Feedback_Intake::build_intake_report_body(
			array(
				'kind'                => Ahentic_Feedback_Intake::KIND_FAILURE,
				'title'               => 'Unsure run',
				'summary'             => 'It failed.',
				'debug_pack'          => '{"trace":[]}',
				'prompt_excerpt'      => '| User | hi |',
				'duplicate_of'        => null,
				'ahentic_version'     => '0.1.0',
				'wp_version'          => '7.0',
				'abilities_mentioned' => array( 'ahentic/get-site-snapshot' ),
				'playbook_ids'        => array(),
				'client'              => array( 'php_version' => '8.2' ),
			)
		);
		$this->assertSame( 'failure', $failure['kind'] );
		$this->assertArrayHasKey( 'debug_pack', $failure );
		$this->assertSame( array(), $failure['playbook_ids'] );

		$success = Ahentic_Feedback_Intake::build_intake_report_body(
			array(
				'kind'                => Ahentic_Feedback_Intake::KIND_SUCCESS,
				'title'               => 'Installed an SEO plugin',
				'summary'             => 'Listed plugins then installed Yoast.',
				'debug_pack'          => '{"trace":[]}',
				'prompt_excerpt'      => '| User | add yoast |',
				'duplicate_of'        => 12,
				'ahentic_version'     => '0.1.0',
				'wp_version'          => '7.0',
				'abilities_mentioned' => array( 'ahentic/list-plugins' ),
				'playbook_ids'        => array( 'plugin-hygiene' ),
				'client'              => array( 'php_version' => '8.2' ),
			)
		);
		$this->assertSame( 'success', $success['kind'] );
		$this->assertArrayNotHasKey( 'debug_pack', $success );
		$this->assertSame( array( 'plugin-hygiene' ), $success['playbook_ids'] );
		$this->assertSame( 12, $success['duplicate_of'] );
	}

	public function test_fallback_draft_fields_success_is_good_run_copy() {
		$fields = Ahentic_Feedback_Intake::fallback_draft_fields( Ahentic_Feedback_Intake::KIND_SUCCESS );
		$this->assertStringContainsString( 'good', strtolower( $fields['title'] . ' ' . $fields['summary'] ) );
		$this->assertStringNotContainsString( 'debug pack', strtolower( $fields['summary'] ) );
	}

	public function test_draft_fields_from_prose_uses_kind_title() {
		$success = Ahentic_Feedback_Intake::draft_fields_from_prose(
			Ahentic_Feedback_Intake::KIND_SUCCESS,
			'Ahentic listed plugins then installed Yoast.'
		);
		$this->assertSame(
			Ahentic_Feedback_Intake::fallback_draft_fields( Ahentic_Feedback_Intake::KIND_SUCCESS )['title'],
			$success['title']
		);
		$this->assertStringContainsString( 'installed Yoast', $success['summary'] );
		$this->assertSame( array(), $success['abilities'] );

		$failure = Ahentic_Feedback_Intake::draft_fields_from_prose(
			Ahentic_Feedback_Intake::KIND_FAILURE,
			'The write never ran after get-site-snapshot.'
		);
		$this->assertSame(
			Ahentic_Feedback_Intake::fallback_draft_fields( Ahentic_Feedback_Intake::KIND_FAILURE )['title'],
			$failure['title']
		);
		$this->assertNotSame( $success['title'], $failure['title'] );

		$empty = Ahentic_Feedback_Intake::draft_fields_from_prose( Ahentic_Feedback_Intake::KIND_SUCCESS, '   ' );
		$this->assertInstanceOf( WP_Error::class, $empty );
	}

	public function test_submit_report_rejects_invalid_session() {
		$result = Ahentic_Feedback_Intake::submit_report( 0, array( 'kind' => 'success' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ahentic_feedback_bad_session', $result->get_error_code() );
	}
}
