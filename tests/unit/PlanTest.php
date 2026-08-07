<?php
/**
 * Plan module pure helpers: normalize / merge from control-block debug.
 *
 * Session-backed apply / advance / complete stay in e2e (orchestrator-pipeline).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Plan normalize + merge (M3 move-only extract).
 */
class PlanTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-plan.php';
	}

	/**
	 * Object-shaped plan with title + steps normalizes statuses and ids.
	 */
	public function test_normalize_object_plan() {
		$plan = Ahentic_Plan::normalize_from_debug(
			array(
				'plan' => array(
					'title' => 'Publish post',
					'steps' => array(
						array(
							'id'      => '1',
							'content' => 'Draft',
							'status'  => 'in_progress',
						),
						array(
							'content' => 'Publish',
							'status'  => 'pending',
						),
					),
				),
			)
		);

		$this->assertSame( 'Publish post', $plan['title'] );
		$this->assertCount( 2, $plan['steps'] );
		$this->assertSame( '1', $plan['steps'][0]['id'] );
		$this->assertSame( '2', $plan['steps'][1]['id'] );
		$this->assertSame( 'in_progress', $plan['steps'][0]['status'] );
	}

	/**
	 * Bare steps array is accepted; invalid status falls back to pending.
	 */
	public function test_normalize_bare_list_and_bad_status() {
		$plan = Ahentic_Plan::normalize_from_debug(
			array(
				'plan' => array(
					array(
						'content' => 'Only step',
						'status'  => 'flying',
					),
				),
			)
		);

		$this->assertSame( '', $plan['title'] );
		$this->assertSame( 'pending', $plan['steps'][0]['status'] );
		$this->assertSame( 'Only step', $plan['steps'][0]['content'] );
	}

	/**
	 * Missing / empty plan returns null.
	 */
	public function test_normalize_rejects_empty() {
		$this->assertNull( Ahentic_Plan::normalize_from_debug( array() ) );
		$this->assertNull( Ahentic_Plan::normalize_from_debug( array( 'plan' => null ) ) );
		$this->assertNull( Ahentic_Plan::normalize_from_debug( array( 'plan' => array() ) ) );
	}

	/**
	 * Completed existing steps survive when the model omits them.
	 */
	public function test_merge_preserves_completed_omitted_steps() {
		$existing = array(
			'title' => 'Old',
			'steps' => array(
				array(
					'id'      => '1',
					'content' => 'Done work',
					'status'  => 'completed',
				),
				array(
					'id'      => '2',
					'content' => 'Next',
					'status'  => 'pending',
				),
			),
		);
		$incoming = array(
			'title' => 'New',
			'steps' => array(
				array(
					'id'      => '2',
					'content' => 'Next updated',
					'status'  => 'in_progress',
				),
				array(
					'id'      => '3',
					'content' => 'Later',
					'status'  => 'pending',
				),
			),
		);

		$merged = Ahentic_Plan::merge_with_existing( $incoming, $existing );

		$this->assertSame( 'New', $merged['title'] );
		$this->assertSame(
			array( '1', '2', '3' ),
			array_map(
				static function ( $step ) {
					return $step['id'];
				},
				$merged['steps']
			)
		);
		$this->assertSame( 'completed', $merged['steps'][0]['status'] );
		$this->assertSame( 'Next updated', $merged['steps'][1]['content'] );
	}
}
