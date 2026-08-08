<?php
/**
 * Pure helpers for ahentic/get-content-summary (eligibility, excerpt, cache freshness).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Decision logic for cached content summaries — no WordPress I/O.
 */
class ContentSummaryHelpersTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-content.php';
	}

	public function test_count_plain_words_ignores_markup_noise() {
		$plain = Ahentic_Abilities_Content::plain_text_from_post_content(
			"<!-- wp:paragraph --><p>One two three</p><!-- /wp:paragraph -->"
		);
		$this->assertSame( 'One two three', $plain );
		$this->assertSame( 3, Ahentic_Abilities_Content::count_plain_words( $plain ) );
	}

	public function test_eligibility_skips_blog_posts_for_list_enough() {
		$plain = str_repeat( 'word ', 320 );
		$result = Ahentic_Abilities_Content::summarize_eligibility( 'post', 'publish', $plain );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'list_enough', $result['reason'] );
	}

	public function test_eligibility_skips_short_non_posts() {
		$result = Ahentic_Abilities_Content::summarize_eligibility( 'page', 'publish', 'Only a few words here' );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'too_short', $result['reason'] );
	}

	public function test_eligibility_allows_long_pages() {
		$plain = trim( str_repeat( 'word ', 300 ) );
		$result = Ahentic_Abilities_Content::summarize_eligibility( 'page', 'publish', $plain );
		$this->assertTrue( $result['eligible'] );
		$this->assertSame( 300, Ahentic_Abilities_Content::count_plain_words( $plain ) );
	}

	public function test_eligibility_skips_trash_and_revisions() {
		$plain = trim( str_repeat( 'word ', 320 ) );
		$trash = Ahentic_Abilities_Content::summarize_eligibility( 'page', 'trash', $plain );
		$this->assertFalse( $trash['eligible'] );
		$this->assertSame( 'trash', $trash['reason'] );

		$rev = Ahentic_Abilities_Content::summarize_eligibility( 'revision', 'inherit', $plain );
		$this->assertFalse( $rev['eligible'] );
		$this->assertSame( 'revision', $rev['reason'] );
	}

	public function test_deterministic_summary_truncates_at_word_boundary() {
		$plain = 'Alpha beta gamma delta epsilon zeta';
		$summary = Ahentic_Abilities_Content::build_deterministic_summary( $plain, 20 );
		$this->assertSame( 'Alpha beta gamma', $summary );
		$this->assertLessThanOrEqual( 20, strlen( $summary ) );
	}

	public function test_summary_cache_fresh_when_at_equals_or_after_modified() {
		$this->assertTrue(
			Ahentic_Abilities_Content::is_summary_cache_fresh(
				'2026-08-08T12:00:00+00:00',
				'2026-08-08 12:00:00'
			)
		);
		$this->assertTrue(
			Ahentic_Abilities_Content::is_summary_cache_fresh(
				'2026-08-08T13:00:00+00:00',
				'2026-08-08 12:00:00'
			)
		);
		$this->assertFalse(
			Ahentic_Abilities_Content::is_summary_cache_fresh(
				'2026-08-08T11:00:00+00:00',
				'2026-08-08 12:00:00'
			)
		);
		$this->assertFalse(
			Ahentic_Abilities_Content::is_summary_cache_fresh( '', '2026-08-08 12:00:00' )
		);
	}
}
