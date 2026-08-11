<?php
/**
 * Pure helpers for ahentic/http-fetch page evidence (excerpt + public signals).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Head/tail excerpt and public-page signals — no WordPress I/O / HTTP.
 */
class HttpFetchHelpersTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-site.php';
	}

	public function test_http_body_excerpt_keeps_short_text() {
		$html = '<p>Call us at the front desk.</p>';
		$this->assertSame( 'Call us at the front desk.', Ahentic_Abilities_Site::http_body_excerpt( $html ) );
	}

	public function test_http_body_excerpt_keeps_head_and_tail_when_truncated() {
		$head = 'HEAD-START-' . str_repeat( 'A', 2500 );
		$mid  = 'MID-ONLY-MARKER-' . str_repeat( 'M', 3000 );
		$tail = 'FOOTER-MARKER-578-393-4937';
		$html = '<div>' . $head . $mid . $tail . '</div>';

		$excerpt = Ahentic_Abilities_Site::http_body_excerpt( $html );
		$this->assertLessThanOrEqual( Ahentic_Abilities_Site::HTTP_EXCERPT_MAX, strlen( $excerpt ) );
		$this->assertStringContainsString( 'HEAD-START-', $excerpt );
		$this->assertStringContainsString( 'FOOTER-MARKER-578-393-4937', $excerpt );
		$this->assertStringContainsString( '…', $excerpt );
		$this->assertStringNotContainsString( 'MID-ONLY-MARKER-', $excerpt );
	}

	public function test_http_page_signals_finds_mailto_tel_and_emails() {
		$html = '<footer>'
			. '<a href="mailto:hello@example.com">Email</a> '
			. '<a href="tel:+15783934937">Call</a> '
			. 'Reach sales@example.org anytime.'
			. '</footer>';

		$signals = Ahentic_Abilities_Site::http_page_signals( $html );
		$this->assertContains( 'hello@example.com', $signals['emails'] );
		$this->assertContains( 'sales@example.org', $signals['emails'] );
		$this->assertContains( 'mailto:hello@example.com', $signals['mailto_links'] );
		$this->assertContains( 'tel:+15783934937', $signals['tel_links'] );
	}
}
