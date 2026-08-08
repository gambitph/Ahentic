<?php
/**
 * Job resume pure decisions: resume cues, sticky content_work, goal pick, forced-apply finish.
 *
 * Session-backed continue_run / fail_run wiring stays in e2e / orchestrator integration.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Job_Resume decision helpers (#3).
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
		$this->assertFalse( Ahentic_Job_Resume::message_looks_like_resume_cue( 'continue drafting the SEO guide with a new intro' ) );
		$this->assertFalse( Ahentic_Job_Resume::message_looks_like_resume_cue( '' ) );
	}

	/**
	 * Resume cue must not turn content_work off when the session already has it.
	 */
	public function test_sticky_content_work_on_resume_cue() {
		$this->assertTrue(
			Ahentic_Job_Resume::resolve_content_work_on_message( false, true, true )
		);
		$this->assertTrue(
			Ahentic_Job_Resume::resolve_content_work_on_message( true, false, false )
		);
		$this->assertFalse(
			Ahentic_Job_Resume::resolve_content_work_on_message( false, true, false )
		);
		$this->assertFalse(
			Ahentic_Job_Resume::resolve_content_work_on_message( false, false, true )
		);
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
	}
}
