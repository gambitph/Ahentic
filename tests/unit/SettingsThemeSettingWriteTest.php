<?php
/**
 * Pure helpers for update-theme-setting (Task 08).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers nested merge + change-resolution gates for theme setting writes.
 */
class SettingsThemeSettingWriteTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-settings.php';
	}

	/**
	 * Dot + bracket paths patch nested values without clobbering siblings.
	 */
	public function test_merge_value_at_path_keeps_siblings() {
		$root = array(
			'sections' => array(
				array(
					'id'    => 'main',
					'items' => array(
						array(
							'id'      => 'logo',
							'enabled' => true,
						),
						array(
							'id'      => 'menu',
							'enabled' => true,
						),
					),
				),
			),
			'meta'     => array( 'version' => 1 ),
		);

		$merged = Ahentic_Abilities_Settings::merge_value_at_path(
			$root,
			'sections[0].items',
			array(
				array(
					'id'      => 'search',
					'enabled' => true,
				),
			)
		);

		$this->assertSame( array( 'version' => 1 ), $merged['meta'] );
		$this->assertSame( 'main', $merged['sections'][0]['id'] );
		$this->assertSame(
			array(
				array(
					'id'      => 'search',
					'enabled' => true,
				),
			),
			$merged['sections'][0]['items']
		);
	}

	/**
	 * Empty path replaces the root entirely.
	 */
	public function test_merge_value_at_path_empty_path_replaces_root() {
		$this->assertSame(
			'new',
			Ahentic_Abilities_Settings::merge_value_at_path( array( 'old' => 1 ), '', 'new' )
		);
	}

	/**
	 * Unknown setting ids are refused.
	 */
	public function test_resolve_rejects_unknown_id() {
		$result = Ahentic_Abilities_Settings::resolve_theme_setting_change(
			array(
				'id'    => 'invented_mod',
				'value' => 'x',
			),
			null,
			null
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ahentic_setting_not_found', $result['error'] );
	}

	/**
	 * Code-bearing ids return an upsell-shaped refusal even when an entry is supplied.
	 */
	public function test_resolve_refuses_code_bearing_with_upsell() {
		$result = Ahentic_Abilities_Settings::resolve_theme_setting_change(
			array(
				'id'    => 'custom_css[twentytwentyfour]',
				'value' => 'body{color:red}',
			),
			array(
				'id'           => 'custom_css[twentytwentyfour]',
				'control_type' => 'textarea',
				'capability'   => 'edit_css',
				'type'         => 'option',
			),
			''
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ahentic_code_bearing_setting', $result['error'] );
		$this->assertTrue( ! empty( $result['upsell'] ) );
		$this->assertSame( 'code-snippets', $result['upsell']['product'] );
	}

	/**
	 * Whole-object write of a nested array requires replace:true.
	 */
	public function test_resolve_rejects_whole_object_without_replace() {
		$prior = array(
			'sections' => array( array( 'id' => 'main' ) ),
			'meta'     => array( 'version' => 1 ),
		);

		$result = Ahentic_Abilities_Settings::resolve_theme_setting_change(
			array(
				'id'    => 'header_placements',
				'value' => array( 'sections' => array() ),
			),
			array(
				'id'   => 'header_placements',
				'type' => 'theme_mod',
			),
			$prior
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ahentic_theme_setting_replace_required', $result['error'] );
	}

	/**
	 * Path merge computes next value; replace:true allows whole-object write.
	 */
	public function test_resolve_path_merge_and_explicit_replace() {
		$prior = array(
			'sections' => array(
				array(
					'id'    => 'main',
					'items' => array( array( 'id' => 'logo' ) ),
				),
			),
			'meta'     => array( 'version' => 1 ),
		);

		$merged = Ahentic_Abilities_Settings::resolve_theme_setting_change(
			array(
				'id'    => 'header_placements',
				'path'  => 'sections[0].items',
				'value' => array( array( 'id' => 'search' ) ),
			),
			array(
				'id'   => 'header_placements',
				'type' => 'theme_mod',
			),
			$prior
		);

		$this->assertTrue( $merged['ok'] );
		$this->assertSame( $prior, $merged['prior'] );
		$this->assertSame( array( 'version' => 1 ), $merged['next']['meta'] );
		$this->assertSame( array( array( 'id' => 'search' ) ), $merged['next']['sections'][0]['items'] );

		$replaced = Ahentic_Abilities_Settings::resolve_theme_setting_change(
			array(
				'id'      => 'header_placements',
				'value'   => array( 'sections' => array() ),
				'replace' => true,
			),
			array(
				'id'   => 'header_placements',
				'type' => 'theme_mod',
			),
			$prior
		);

		$this->assertTrue( $replaced['ok'] );
		$this->assertSame( array( 'sections' => array() ), $replaced['next'] );
	}

	/**
	 * Scalars may be replaced without replace:true.
	 */
	public function test_resolve_allows_scalar_whole_write() {
		$result = Ahentic_Abilities_Settings::resolve_theme_setting_change(
			array(
				'id'    => 'blogname',
				'value' => 'New Title',
			),
			array(
				'id'   => 'blogname',
				'type' => 'option',
			),
			'Old Title'
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Old Title', $result['prior'] );
		$this->assertSame( 'New Title', $result['next'] );
	}

	/**
	 * Nested array priors refuse a bare scalar write without replace:true.
	 */
	public function test_resolve_rejects_scalar_overwrite_of_nested_without_replace() {
		$result = Ahentic_Abilities_Settings::resolve_theme_setting_change(
			array(
				'id'    => 'header_placements',
				'value' => 'oops',
			),
			array(
				'id'   => 'header_placements',
				'type' => 'theme_mod',
			),
			array( 'sections' => array() )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ahentic_theme_setting_replace_required', $result['error'] );
	}
}
