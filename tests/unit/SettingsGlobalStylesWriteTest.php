<?php
/**
 * Pure helpers for update-global-styles (Task 09).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers css stripping and merge resolution for global styles writes.
 */
class SettingsGlobalStylesWriteTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-settings.php';
	}

	/**
	 * Top-level styles.css and block-level css keys are removed.
	 */
	public function test_strip_removes_styles_and_block_css() {
		$input = array(
			'styles'   => array(
				'css'   => 'body{color:red}',
				'color' => array( 'background' => '#fff' ),
				'blocks' => array(
					'core/paragraph' => array(
						'css'   => 'p{margin:0}',
						'color' => array( 'text' => '#111' ),
					),
					'core/heading'   => array(
						'typography' => array( 'fontSize' => '2rem' ),
					),
				),
			),
			'settings' => array(
				'color' => array( 'custom' => true ),
			),
		);

		$stripped = Ahentic_Abilities_Settings::strip_global_styles_css_keys( $input );

		$this->assertArrayNotHasKey( 'css', $stripped['styles'] );
		$this->assertSame( '#fff', $stripped['styles']['color']['background'] );
		$this->assertArrayNotHasKey( 'css', $stripped['styles']['blocks']['core/paragraph'] );
		$this->assertSame( '#111', $stripped['styles']['blocks']['core/paragraph']['color']['text'] );
		$this->assertSame( '2rem', $stripped['styles']['blocks']['core/heading']['typography']['fontSize'] );
		$this->assertTrue( $stripped['settings']['color']['custom'] );
	}

	/**
	 * Css-only input resolves to a code-bearing upsell refusal.
	 */
	public function test_resolve_css_only_is_code_bearing_refusal() {
		$result = Ahentic_Abilities_Settings::resolve_global_styles_update(
			array(
				'styles' => array( 'css' => 'body{}' ),
			),
			array()
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ahentic_code_bearing_setting', $result['error'] );
		$this->assertTrue( ! empty( $result['upsell'] ) );
		$this->assertSame( 'code-snippets', $result['upsell']['product'] );
	}

	/**
	 * Missing styles and settings is refused.
	 */
	public function test_resolve_requires_styles_or_settings() {
		$result = Ahentic_Abilities_Settings::resolve_global_styles_update( array(), array() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ahentic_missing_global_styles_partial', $result['error'] );
	}

	/**
	 * Partial merges into prior; css in the partial is stripped before merge.
	 */
	public function test_resolve_merges_partial_and_strips_css() {
		$prior = array(
			'styles'   => array(
				'color'  => array(
					'background' => '#000',
					'text'       => '#eee',
				),
				'blocks' => array(
					'core/paragraph' => array(
						'color' => array( 'text' => '#ccc' ),
					),
				),
			),
			'settings' => array(
				'layout' => array( 'contentSize' => '640px' ),
			),
		);

		$result = Ahentic_Abilities_Settings::resolve_global_styles_update(
			array(
				'styles' => array(
					'css'   => 'should-not-land',
					'color' => array( 'background' => '#fff' ),
					'blocks' => array(
						'core/paragraph' => array(
							'css' => 'p{}',
						),
					),
				),
			),
			$prior
		);

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( ! empty( $result['stripped_css'] ) );
		$this->assertSame( $prior, $result['prior'] );
		$this->assertSame( '#fff', $result['next']['styles']['color']['background'] );
		$this->assertSame( '#eee', $result['next']['styles']['color']['text'] );
		$this->assertArrayNotHasKey( 'css', $result['next']['styles'] );
		$this->assertArrayNotHasKey( 'css', $result['next']['styles']['blocks']['core/paragraph'] );
		$this->assertSame( '#ccc', $result['next']['styles']['blocks']['core/paragraph']['color']['text'] );
		$this->assertSame( '640px', $result['next']['settings']['layout']['contentSize'] );
	}
}
