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

	public function test_build_debug_pack_drops_heartbeat_and_prefers_tail() {
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
	}
}
