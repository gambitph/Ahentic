<?php
/**
 * Pure helpers for update-template-part (Task 10).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers id parse, content resolution, and prior_existed heuristics.
 */
class SettingsTemplatePartWriteTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-settings.php';
	}

	/**
	 * Valid theme//slug ids parse; invalid shapes refuse.
	 */
	public function test_parse_template_part_id() {
		$ok = Ahentic_Abilities_Settings::parse_template_part_id( 'twentytwentyfour//header' );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'twentytwentyfour', $ok['stylesheet'] );
		$this->assertSame( 'header', $ok['slug'] );
		$this->assertSame( 'twentytwentyfour//header', $ok['id'] );

		$bad = Ahentic_Abilities_Settings::parse_template_part_id( 'header-only' );
		$this->assertFalse( $bad['ok'] );
		$this->assertSame( 'ahentic_invalid_template_part_id', $bad['error'] );
	}

	/**
	 * Content and empty blocks are validated without WordPress.
	 */
	public function test_resolve_content_input() {
		$missing = Ahentic_Abilities_Settings::resolve_template_part_content_input( array() );
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'ahentic_missing_template_part_body', $missing['error'] );

		$content = Ahentic_Abilities_Settings::resolve_template_part_content_input(
			array( 'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' )
		);
		$this->assertTrue( $content['ok'] );
		$this->assertStringContainsString( 'wp:paragraph', $content['content'] );

		$empty = Ahentic_Abilities_Settings::resolve_template_part_content_input( array( 'blocks' => array() ) );
		$this->assertFalse( $empty['ok'] );
		$this->assertSame( 'ahentic_empty_template_part_blocks', $empty['error'] );
	}

	/**
	 * prior_existed follows wp_id / custom source, not mere file resolution.
	 */
	public function test_template_part_prior_existed() {
		$this->assertFalse( Ahentic_Abilities_Settings::template_part_prior_existed( null ) );

		$file = (object) array(
			'wp_id'  => 0,
			'source' => 'theme',
		);
		$this->assertFalse( Ahentic_Abilities_Settings::template_part_prior_existed( $file ) );

		$db = (object) array(
			'wp_id'  => 42,
			'source' => 'custom',
		);
		$this->assertTrue( Ahentic_Abilities_Settings::template_part_prior_existed( $db ) );
	}
}
