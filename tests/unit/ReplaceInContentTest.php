<?php
/**
 * Pure find/replace helpers for ahentic/replace-in-content.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers literal substring replace counting / application.
 */
class ReplaceInContentTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-content.php';
	}

	public function test_count_occurrences_is_case_sensitive_literal() {
		$this->assertSame( 2, Ahentic_Abilities_Content::count_literal_occurrences( 'http://a http://b', 'http://' ) );
		$this->assertSame( 0, Ahentic_Abilities_Content::count_literal_occurrences( 'HTTP://A', 'http://' ) );
		$this->assertSame( 0, Ahentic_Abilities_Content::count_literal_occurrences( 'hello', '' ) );
	}

	public function test_apply_literal_replace_substitutes_all() {
		$this->assertSame(
			'https://a https://b',
			Ahentic_Abilities_Content::apply_literal_replace( 'http://a http://b', 'http://', 'https://' )
		);
	}
}
