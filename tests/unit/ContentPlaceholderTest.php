<?php
/**
 * Shared content-placeholder rules: PHP matches JSON samples.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Content_Placeholder against src/data/content-placeholder-rules.json.
 */
class ContentPlaceholderTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-ahentic-content-placeholder.php';
		Ahentic_Content_Placeholder::reset_rules_for_tests();
	}

	public function test_rules_file_is_readable_and_has_samples() {
		$rules = Ahentic_Content_Placeholder::rules();
		$this->assertNotEmpty( $rules['patterns'] );
		$this->assertNotEmpty( $rules['samples']['placeholder'] );
		$this->assertNotEmpty( $rules['samples']['real'] );
	}

	public function test_flags_every_shared_placeholder_sample() {
		$samples = Ahentic_Content_Placeholder::rules()['samples']['placeholder'];
		foreach ( $samples as $sample ) {
			$this->assertTrue(
				Ahentic_Content_Placeholder::looks_like( $sample ),
				'Expected placeholder: ' . $sample
			);
		}
	}

	public function test_accepts_every_shared_real_sample() {
		$samples = Ahentic_Content_Placeholder::rules()['samples']['real'];
		foreach ( $samples as $sample ) {
			$this->assertFalse(
				Ahentic_Content_Placeholder::looks_like( $sample ),
				'Expected real prose: ' . $sample
			);
		}
	}

	public function test_content_module_delegates_to_shared_helper() {
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-content.php';
		$this->assertTrue( Ahentic_Abilities_Content::looks_like_content_placeholder( '[full article]' ) );
		$this->assertFalse(
			Ahentic_Abilities_Content::looks_like_content_placeholder(
				'WordPress makes it easy to publish beautiful posts.'
			)
		);
	}
}
