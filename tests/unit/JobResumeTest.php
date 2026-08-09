<?php
/**
 * Job Resume run-start / resume / forced-finish seam.
 *
 * Asserts Session-shaped bag outcomes from begin_new_goal / begin_resume and
 * forced-tools finish policy. Session REST Continue wiring stays in
 * tests/e2e/specs/job-resume.spec.js.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Job_Resume deepen (#4).
 */
class JobResumeTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-job-resume.php';
	}

	/**
	 * Short resume cues are recognized; real goals are not.
	 */
	public function test_resume_cue_detection() {
		$this->assertTrue( Ahentic_Job_Resume::message_looks_like_resume_cue( 'continue' ) );
		$this->assertTrue( Ahentic_Job_Resume::message_looks_like_resume_cue( 'Keep going' ) );
		$this->assertTrue( Ahentic_Job_Resume::message_looks_like_resume_cue( 'finish it' ) );
		$this->assertTrue( Ahentic_Job_Resume::message_looks_like_resume_cue( 'please continue' ) );

		$this->assertFalse( Ahentic_Job_Resume::message_looks_like_resume_cue( 'write a 1000 word article' ) );
		$this->assertFalse(
			Ahentic_Job_Resume::message_looks_like_resume_cue( 'continue drafting the SEO guide with a new intro' )
		);
		$this->assertFalse( Ahentic_Job_Resume::message_looks_like_resume_cue( '' ) );
	}

	/**
	 * Goals that mention “continue” mid-sentence still start fresh work (not resume cues).
	 */
	public function test_begin_new_goal_rejects_continue_inside_real_goal() {
		$session = array(
			'job_resumable' => true,
			'content_work'  => true,
			'active_goal'   => 'old article',
		);
		$planned = Ahentic_Job_Resume::begin_new_goal(
			$session,
			'continue drafting the SEO guide with a new intro'
		);

		$this->assertFalse(
			Ahentic_Job_Resume::prefers_resume(
				$session,
				'continue drafting the SEO guide with a new intro'
			)
		);
		$this->assertTrue( $planned['set_active_goal'] );
		$this->assertSame(
			'continue drafting the SEO guide with a new intro',
			$planned['active_goal']
		);
		$this->assertTrue( $planned['clear_plan'] );
		$this->assertFalse( $planned['reopen_plan'] );
	}

	/**
	 * Content-work intent detection lives with resume policy.
	 */
	public function test_content_work_message_detection() {
		$this->assertTrue(
			Ahentic_Job_Resume::message_looks_like_content_work( 'write a 1000 word article about cats' )
		);
		$this->assertTrue(
			Ahentic_Job_Resume::message_looks_like_content_work( 'draft a long-form guide' )
		);
		$this->assertFalse(
			Ahentic_Job_Resume::message_looks_like_content_work( 'list my plugins' )
		);
		$this->assertFalse(
			Ahentic_Job_Resume::message_looks_like_content_work( 'continue' )
		);
	}

	/**
	 * Continuable + resume cue prefers resume over new-goal ritual.
	 */
	public function test_prefers_resume_when_continuable_cue() {
		$session = array(
			'job_resumable' => true,
			'content_work'  => true,
			'active_goal'   => 'write a 1000 word article',
		);
		$this->assertTrue( Ahentic_Job_Resume::prefers_resume( $session, 'keep going' ) );
		$this->assertFalse( Ahentic_Job_Resume::prefers_resume( $session, 'write a new page instead' ) );
		$this->assertFalse(
			Ahentic_Job_Resume::prefers_resume(
				array(
					'job_resumable' => false,
					'content_work'  => true,
					'active_goal'   => 'write a 1000 word article',
				),
				'continue'
			)
		);
	}

	/**
	 * New goal clears Plan / Continuable and pins the real request.
	 */
	public function test_begin_new_goal_pins_goal_and_clears_plan() {
		$session = array(
			'job_resumable' => true,
			'content_work'  => false,
			'active_goal'   => 'old goal',
		);
		$planned = Ahentic_Job_Resume::begin_new_goal(
			$session,
			'write a 1000 word article based on previous posts'
		);

		$this->assertFalse( $planned['job_resumable'] );
		$this->assertTrue( $planned['content_work'] );
		$this->assertSame(
			'write a 1000 word article based on previous posts',
			$planned['active_goal']
		);
		$this->assertTrue( $planned['set_active_goal'] );
		$this->assertTrue( $planned['clear_plan'] );
		$this->assertFalse( $planned['reopen_plan'] );
		$this->assertTrue( $planned['clear_context_summary'] );
		$this->assertTrue( $planned['consume_capability_requests'] );
		$this->assertFalse( $planned['touch_heartbeat'] );
		$this->assertSame( 'running', $planned['status'] );
		$this->assertSame( 0, $planned['step_count'] );
		$this->assertTrue( $planned['clear_forced_tools'] );
		$this->assertTrue( $planned['clear_verify'] );
	}

	/**
	 * Resume cue without Continuable still clears Plan but keeps prior goal + sticky content_work.
	 */
	public function test_begin_new_goal_resume_cue_keeps_goal_and_content_work() {
		$session = array(
			'job_resumable' => false,
			'content_work'  => true,
			'active_goal'   => 'write a 1000 word article based on previous posts',
		);
		$planned = Ahentic_Job_Resume::begin_new_goal( $session, 'continue' );

		$this->assertFalse( $planned['job_resumable'] );
		$this->assertTrue( $planned['content_work'], 'Sticky content_work across resume cue' );
		$this->assertSame(
			'write a 1000 word article based on previous posts',
			$planned['active_goal']
		);
		$this->assertFalse( $planned['set_active_goal'] );
		$this->assertTrue( $planned['clear_plan'] );
	}

	/**
	 * Genuine new goal turns content_work off when the message is not long-form.
	 */
	public function test_begin_new_goal_non_content_message_clears_content_work() {
		$session = array(
			'job_resumable' => false,
			'content_work'  => true,
			'active_goal'   => 'write a 1000 word article',
		);
		$planned = Ahentic_Job_Resume::begin_new_goal( $session, 'list my installed plugins' );

		$this->assertFalse( $planned['content_work'] );
		$this->assertSame( 'list my installed plugins', $planned['active_goal'] );
		$this->assertTrue( $planned['set_active_goal'] );
	}

	/**
	 * Continue ritual keeps goal / content_work, reopens Plan, does not clear context summary.
	 */
	public function test_begin_resume_keeps_job_and_reopens_plan() {
		$session = array(
			'job_resumable' => true,
			'content_work'  => true,
			'active_goal'   => 'write a 1000 word article based on previous posts',
		);
		$planned = Ahentic_Job_Resume::begin_resume( $session );

		$this->assertFalse( $planned['job_resumable'] );
		$this->assertTrue( $planned['content_work'] );
		$this->assertSame(
			'write a 1000 word article based on previous posts',
			$planned['active_goal']
		);
		$this->assertFalse( $planned['set_active_goal'] );
		$this->assertFalse( $planned['clear_plan'] );
		$this->assertTrue( $planned['reopen_plan'] );
		$this->assertFalse( $planned['clear_context_summary'] );
		$this->assertFalse( $planned['consume_capability_requests'] );
		$this->assertTrue( $planned['touch_heartbeat'] );
		$this->assertSame( 'running', $planned['status'] );
		$this->assertSame( 0, $planned['step_count'] );
		$this->assertTrue( $planned['clear_forced_tools'] );
		$this->assertTrue( $planned['clear_error'] );
	}

	/**
	 * Active goal skips resume-only user lines.
	 */
	public function test_active_goal_skips_resume_cues() {
		$entries = array(
			array( 'role' => 'user', 'content' => 'write a 1000 word article based on previous posts' ),
			array( 'role' => 'assistant', 'content' => 'Working on it.' ),
			array( 'role' => 'user', 'content' => 'continue' ),
		);

		$this->assertSame(
			'write a 1000 word article based on previous posts',
			Ahentic_Job_Resume::active_goal_from_entries( $entries )
		);
	}

	/**
	 * Explicit stored goal wins over entry scan.
	 */
	public function test_active_goal_prefers_stored() {
		$entries = array(
			array( 'role' => 'user', 'content' => 'continue' ),
		);
		$this->assertSame(
			'Stored article goal',
			Ahentic_Job_Resume::active_goal_from_entries( $entries, 'Stored article goal' )
		);
	}

	/**
	 * Forced apply failure during content work must not finish the run.
	 * Batch/recipe forced queues must never auto-finish (generate-image recipe remainder).
	 */
	public function test_forced_tools_do_not_finish_on_content_work_failure() {
		$this->assertFalse(
			Ahentic_Job_Resume::should_finish_after_forced_tools( true, true, true )
		);
		$this->assertTrue(
			Ahentic_Job_Resume::should_finish_after_forced_tools( true, false, true )
		);
		$this->assertTrue(
			Ahentic_Job_Resume::should_finish_after_forced_tools( true, true, false )
		);
		$this->assertFalse(
			Ahentic_Job_Resume::should_finish_after_forced_tools( false, true, true )
		);
		// Regression: scout/batch remainder after browser pause must return to think.
		$this->assertFalse(
			Ahentic_Job_Resume::should_finish_after_forced_tools( true, false, true, 'batch' )
		);
		// Regression: generate-image Recipe must return to think after upload/set-featured.
		$this->assertFalse(
			Ahentic_Job_Resume::should_finish_after_forced_tools( true, false, false, 'recipe' )
		);
		$this->assertTrue(
			Ahentic_Job_Resume::should_finish_after_forced_tools( true, false, false, 'apply' )
		);
	}

	/**
	 * Last update-block-attributes in a browser batch should try finish (no free think).
	 */
	public function test_try_finish_after_browser_attr_batch() {
		$this->assertTrue(
			Ahentic_Job_Resume::should_try_finish_after_browser_resume(
				'ahentic-browser/update-block-attributes',
				true,
				false,
				false
			)
		);
		$this->assertFalse(
			Ahentic_Job_Resume::should_try_finish_after_browser_resume(
				'ahentic-browser/update-block-attributes',
				true,
				true,
				false
			),
			'More forced tools remain — keep the batch going'
		);
		$this->assertFalse(
			Ahentic_Job_Resume::should_try_finish_after_browser_resume(
				'ahentic-browser/update-block-attributes',
				false,
				false,
				false
			),
			'Failed patch needs another think'
		);
		$this->assertFalse(
			Ahentic_Job_Resume::should_try_finish_after_browser_resume(
				'ahentic-browser/update-block-attributes',
				true,
				false,
				true
			),
			'Long-form content work still needs think/verify'
		);
		$this->assertFalse(
			Ahentic_Job_Resume::should_try_finish_after_browser_resume(
				'ahentic-browser/set-blocks',
				true,
				false,
				false
			),
			'set-blocks without apply purpose must not auto-finish'
		);
		$this->assertTrue(
			Ahentic_Job_Resume::should_try_finish_after_browser_resume(
				'ahentic-browser/set-blocks',
				true,
				false,
				true,
				'apply'
			),
			'Forced apply set-blocks should finish even during content work'
		);
		$this->assertTrue(
			Ahentic_Job_Resume::should_try_finish_after_browser_resume(
				'ahentic-browser/update-post-document',
				true,
				false,
				true,
				'apply'
			),
			'Forced apply title follow-up should finish'
		);
		$this->assertFalse(
			Ahentic_Job_Resume::should_try_finish_after_browser_resume(
				'ahentic-browser/set-blocks',
				true,
				true,
				true,
				'apply'
			),
			'More apply tools remain (e.g. title) — keep going'
		);
		$this->assertFalse(
			Ahentic_Job_Resume::should_try_finish_after_browser_resume(
				'ahentic-browser/get-blocks',
				true,
				false,
				false
			)
		);
	}
}
