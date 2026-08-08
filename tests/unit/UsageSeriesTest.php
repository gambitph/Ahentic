<?php
/**
 * Token usage series helpers (pure seam for settings graph + REST).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers contiguous day series building and usage percent formatting.
 */
class UsageSeriesTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-usage.php';
	}

	/**
	 * Missing days zero-fill; labels attach by index.
	 */
	public function test_build_series_zero_fills_and_labels() {
		$stats = array(
			'2026-08-06' => array(
				'in'    => 10,
				'out'   => 20,
				'total' => 30,
			),
		);
		$keys    = array( '2026-08-05', '2026-08-06', '2026-08-07' );
		$labels  = array( 'Aug 5', 'Aug 6', 'Aug 7' );
		$series  = Ahentic_Usage::build_series_from_stats( $stats, $keys, $labels );

		$this->assertCount( 3, $series );
		$this->assertSame( '2026-08-05', $series[0]['date'] );
		$this->assertSame( 'Aug 5', $series[0]['label'] );
		$this->assertSame( 0, $series[0]['total'] );
		$this->assertSame( 30, $series[1]['total'] );
		$this->assertSame( 10, $series[1]['in'] );
		$this->assertSame( 20, $series[1]['out'] );
		$this->assertSame( 0, $series[2]['total'] );
		$this->assertSame( 'Aug 7', $series[2]['label'] );
	}

	/**
	 * Contiguous site-calendar keys ending on a day, oldest first.
	 */
	public function test_day_keys_ending_on_are_contiguous_oldest_first() {
		$keys = Ahentic_Usage::day_keys_ending_on( '2026-08-07', 3 );
		$this->assertSame( array( '2026-08-05', '2026-08-06', '2026-08-07' ), $keys );
	}

	/**
	 * Percent: 2 decimals in (0,1), otherwise whole numbers; clamped 0–100.
	 */
	public function test_format_usage_pct() {
		$this->assertSame( 0, Ahentic_Usage::format_usage_pct( 0, 5000000 ) );
		$this->assertSame( 0.21, Ahentic_Usage::format_usage_pct( 10425, 5000000 ) );
		$this->assertSame( 50, Ahentic_Usage::format_usage_pct( 50, 100 ) );
		$this->assertSame( 100, Ahentic_Usage::format_usage_pct( 200, 100 ) );
		$this->assertSame( 0, Ahentic_Usage::format_usage_pct( 10, 0 ) );
	}

	/**
	 * Live bar denominator prefers the larger of input vs effective (temp boost).
	 */
	public function test_live_bar_denominator() {
		$this->assertSame( 5000000, Ahentic_Usage::live_bar_denominator( 5000000, 5000000 ) );
		$this->assertSame( 5500000, Ahentic_Usage::live_bar_denominator( 5000000, 5500000 ) );
		$this->assertSame( 6000000, Ahentic_Usage::live_bar_denominator( 6000000, 5500000 ) );
		$this->assertSame( 0, Ahentic_Usage::live_bar_denominator( 0, 100 ) );
	}

	/**
	 * Tiny usage still paints a visible bar; zero usage stays empty.
	 */
	public function test_usage_bar_width_pct() {
		$this->assertSame( 0.0, Ahentic_Usage::usage_bar_width_pct( 0, 0 ) );
		$this->assertSame( 0.5, Ahentic_Usage::usage_bar_width_pct( 0.21, 10425 ) );
		$this->assertSame( 50.0, Ahentic_Usage::usage_bar_width_pct( 50, 50 ) );
	}
}
