<?php
/**
 * Soft per-session cumulative token pause (pure spend seam).
 *
 * Seam: Ahentic_Usage::{evaluate_session_soft_budget,ack_session_soft_budget,
 * session_soft_budget_message,format_soft_budget_count}
 */

require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-usage.php';

use PHPUnit\Framework\TestCase;

/**
 * Covers session soft-budget gate, watermark ack, and Continuable copy.
 */
class SessionSoftBudgetTest extends TestCase {

	/**
	 * Under the first boundary: may spend.
	 */
	public function test_under_first_boundary_is_ok() {
		$gate = Ahentic_Usage::evaluate_session_soft_budget( 199999, 0 );
		$this->assertTrue( $gate['ok'] );
		$this->assertSame( 200000, $gate['threshold'] );
	}

	/**
	 * At / over the first boundary: pause with dedicated code.
	 */
	public function test_at_first_boundary_pauses() {
		$gate = Ahentic_Usage::evaluate_session_soft_budget( 200000, 0 );
		$this->assertFalse( $gate['ok'] );
		$this->assertSame( Ahentic_Usage::CODE_SESSION_SOFT_BUDGET, $gate['code'] );
		$this->assertSame( 200000, $gate['threshold'] );
	}

	/**
	 * After Continue past 200k, next pause is 400k.
	 */
	public function test_ack_raises_next_boundary() {
		$acked = Ahentic_Usage::ack_session_soft_budget( 205000 );
		$this->assertSame( 200000, $acked );

		$gate = Ahentic_Usage::evaluate_session_soft_budget( 205000, $acked );
		$this->assertTrue( $gate['ok'] );
		$this->assertSame( 400000, $gate['threshold'] );

		$gate2 = Ahentic_Usage::evaluate_session_soft_budget( 400000, $acked );
		$this->assertFalse( $gate2['ok'] );
		$this->assertSame( 400000, $gate2['threshold'] );
	}

	/**
	 * Overshooting two boundaries before Continue acks through the highest crossed.
	 */
	public function test_ack_skips_to_highest_crossed_boundary() {
		$this->assertSame( 400000, Ahentic_Usage::ack_session_soft_budget( 450000 ) );
	}

	/**
	 * Continuable copy names the threshold without an em dash.
	 */
	public function test_message_has_approx_count_and_no_em_dash() {
		$msg = Ahentic_Usage::session_soft_budget_message( 200000 );
		$this->assertStringContainsString( '~200k', $msg );
		$this->assertStringContainsString( 'Continue', $msg );
		$this->assertStringContainsString( 'Stop', $msg );
		$this->assertStringNotContainsString( '—', $msg );
	}

	/**
	 * Compact formatter.
	 */
	public function test_format_soft_budget_count() {
		$this->assertSame( '200k', Ahentic_Usage::format_soft_budget_count( 200000 ) );
		$this->assertSame( '400k', Ahentic_Usage::format_soft_budget_count( 400000 ) );
		$this->assertSame( '500', Ahentic_Usage::format_soft_budget_count( 500 ) );
	}
}
