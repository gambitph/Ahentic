<?php
/**
 * Site-wide daily token limit + runaway streak (pure state seam).
 *
 * Seam: Ahentic_Usage::{evaluate_gate,apply_enforcement,unlock_limits,default_limits_state}
 * Persistence / WP options / orchestrator cancel are integration — not this file.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers token limit gate, hit-day streak, unlock, and raise-and-resume rules.
 */
class UsageTokenLimitsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-usage.php';
	}

	/**
	 * Fresh state: default daily limit 1M, not runaway-locked, spend allowed.
	 */
	public function test_default_state_allows_spend_under_limit() {
		$limits = Ahentic_Usage::default_limits_state();
		$this->assertSame( 1000000, $limits['daily_limit'] );
		$this->assertFalse( $limits['runaway_locked'] );
		$this->assertSame( 0, $limits['streak'] );

		$gate = Ahentic_Usage::evaluate_gate( $limits, 100 );
		$this->assertTrue( $gate['ok'] );
	}

	/**
	 * At or over daily total blocks with daily_limit (when not runaway-locked).
	 */
	public function test_daily_limit_blocks_when_usage_at_cap() {
		$limits = Ahentic_Usage::default_limits_state();
		$limits['daily_limit'] = 1000;

		$gate = Ahentic_Usage::evaluate_gate( $limits, 1000 );
		$this->assertFalse( $gate['ok'] );
		$this->assertSame( Ahentic_Usage::CODE_DAILY_LIMIT, $gate['code'] );

		$gate2 = Ahentic_Usage::evaluate_gate( $limits, 1001 );
		$this->assertFalse( $gate2['ok'] );
		$this->assertSame( Ahentic_Usage::CODE_DAILY_LIMIT, $gate2['code'] );
	}

	/**
	 * Raising the daily limit above today's usage clears the daily block (same day).
	 */
	public function test_raise_limit_allows_resume_same_day() {
		$limits = Ahentic_Usage::default_limits_state();
		$limits['daily_limit'] = 1000;

		$this->assertFalse( Ahentic_Usage::evaluate_gate( $limits, 1000 )['ok'] );

		$limits = Ahentic_Usage::with_daily_limit( $limits, 2000 );
		$gate   = Ahentic_Usage::evaluate_gate( $limits, 1000 );
		$this->assertTrue( $gate['ok'] );
	}

	/**
	 * Raising the permanent limit must not leave a lower same-day temp boost as the cap.
	 *
	 * Regression: settings field showed 5M while gate still used sticky temp (~110k).
	 */
	public function test_raise_permanent_supersedes_lower_temp_boost() {
		$limits = Ahentic_Usage::default_limits_state();
		$limits['daily_limit'] = 1000;
		$limits                = Ahentic_Usage::with_temporary_boost_10( $limits );
		$this->assertSame( 1100, $limits['temp_limit'] );
		$this->assertSame( 1100, Ahentic_Usage::effective_daily_limit( $limits ) );

		$limits = Ahentic_Usage::with_daily_limit( $limits, 5000000 );
		$this->assertSame( 5000000, $limits['daily_limit'] );
		$this->assertSame( 0, $limits['temp_limit'] );
		$this->assertSame( '', $limits['temp_limit_day'] );
		$this->assertSame( 5000000, Ahentic_Usage::effective_daily_limit( $limits ) );

		$gate = Ahentic_Usage::evaluate_gate( $limits, 547089 );
		$this->assertTrue( $gate['ok'] );
	}

	/**
	 * Even with a stale lower temp still stored, effective cap is max(permanent, temp).
	 */
	public function test_effective_limit_never_below_permanent_while_temp_active() {
		$limits = Ahentic_Usage::default_limits_state();
		$limits['daily_limit']    = 5000000;
		$limits['temp_limit']     = 110001;
		$limits['temp_limit_day'] = Ahentic_Usage::site_tz_day();

		$this->assertSame( 5000000, Ahentic_Usage::effective_daily_limit( $limits ) );
		$this->assertTrue( Ahentic_Usage::evaluate_gate( $limits, 547089 )['ok'] );
	}

	/**
	 * Enforcement on a day increments streak; non-consecutive day resets streak to 1.
	 */
	public function test_streak_increments_on_consecutive_hit_days_and_breaks_on_gap() {
		$limits = Ahentic_Usage::default_limits_state();

		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-06', '2026-08-05' );
		$this->assertSame( 1, $limits['streak'] );
		$this->assertSame( '2026-08-06', $limits['last_hit_day'] );
		$this->assertFalse( $limits['runaway_locked'] );

		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-07', '2026-08-06' );
		$this->assertSame( 2, $limits['streak'] );
		$this->assertFalse( $limits['runaway_locked'] );

		// Gap: next hit after skipping a day → streak restarts at 1.
		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-09', '2026-08-08' );
		$this->assertSame( 1, $limits['streak'] );
		$this->assertSame( '2026-08-09', $limits['last_hit_day'] );
		$this->assertFalse( $limits['runaway_locked'] );
	}

	/**
	 * Third consecutive hit-day engages runaway lock.
	 */
	public function test_third_consecutive_hit_day_locks_runaway() {
		$limits = Ahentic_Usage::default_limits_state();
		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-06', '2026-08-05' );
		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-07', '2026-08-06' );
		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-08', '2026-08-07' );

		$this->assertSame( 3, $limits['streak'] );
		$this->assertTrue( $limits['runaway_locked'] );

		$gate = Ahentic_Usage::evaluate_gate( $limits, 0 );
		$this->assertFalse( $gate['ok'] );
		$this->assertSame( Ahentic_Usage::CODE_RUNAWAY_LOCK, $gate['code'] );
	}

	/**
	 * Re-enforcing the same calendar day does not bump streak again.
	 */
	public function test_same_day_enforcement_is_idempotent_for_streak() {
		$limits = Ahentic_Usage::default_limits_state();
		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-06', '2026-08-05' );
		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-06', '2026-08-05' );
		$this->assertSame( 1, $limits['streak'] );
	}

	/**
	 * Unlock clears runaway and resets streak; raising limit alone does not.
	 */
	public function test_unlock_resets_streak_raise_limit_does_not() {
		$limits = Ahentic_Usage::default_limits_state();
		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-06', '2026-08-05' );
		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-07', '2026-08-06' );
		$limits = Ahentic_Usage::apply_enforcement( $limits, '2026-08-08', '2026-08-07' );
		$this->assertTrue( $limits['runaway_locked'] );

		$raised = Ahentic_Usage::with_daily_limit( $limits, 5000000 );
		$this->assertTrue( $raised['runaway_locked'] );
		$this->assertSame( 3, $raised['streak'] );

		$unlocked = Ahentic_Usage::unlock_limits( $raised );
		$this->assertFalse( $unlocked['runaway_locked'] );
		$this->assertSame( 0, $unlocked['streak'] );
		$this->assertSame( '', $unlocked['last_hit_day'] );
		$this->assertTrue( Ahentic_Usage::evaluate_gate( $unlocked, 0 )['ok'] );
	}

	/**
	 * Crossing the daily cap (before → after) is detected for enforcement.
	 */
	public function test_spend_crosses_daily_cap() {
		$this->assertFalse( Ahentic_Usage::spend_crosses_limit( 900, 50, 1000 ) );
		$this->assertTrue( Ahentic_Usage::spend_crosses_limit( 900, 100, 1000 ) );
		$this->assertTrue( Ahentic_Usage::spend_crosses_limit( 1000, 1, 1000 ) );
		$this->assertFalse( Ahentic_Usage::spend_crosses_limit( 1000, 1, 2000 ) );
	}

	/**
	 * Temporary +10% boost raises effective cap for today only.
	 */
	public function test_temporary_boost_10_allows_spend_above_permanent() {
		$limits = Ahentic_Usage::default_limits_state();
		$limits['daily_limit'] = 1000;
		$boosted = Ahentic_Usage::with_temporary_boost_10( $limits );
		$this->assertSame( 1100, $boosted['temp_limit'] );
		$this->assertSame( 1000, $boosted['daily_limit'] );
		$this->assertTrue( Ahentic_Usage::evaluate_gate( $boosted, 1000 )['ok'] );
		$this->assertFalse( Ahentic_Usage::evaluate_gate( $boosted, 1100 )['ok'] );
	}

	/**
	 * Permanent +10% updates daily_limit and clears temp boost.
	 */
	public function test_permanent_boost_10_updates_daily_limit() {
		$limits = Ahentic_Usage::default_limits_state();
		$limits['daily_limit'] = 1000;
		$limits = Ahentic_Usage::with_temporary_boost_10( $limits );
		$perm   = Ahentic_Usage::with_permanent_boost_10( $limits );
		$this->assertSame( 1210, $perm['daily_limit'] ); // 1100 * 1.1
		$this->assertSame( 0, $perm['temp_limit'] );
		$this->assertSame( '', $perm['temp_limit_day'] );
	}
}
