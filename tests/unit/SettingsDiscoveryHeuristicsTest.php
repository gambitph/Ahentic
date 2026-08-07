<?php
/**
 * Pure helpers for settings discovery (Task 07).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Abilities_Settings filter / exclude / summarize heuristics.
 */
class SettingsDiscoveryHeuristicsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-settings.php';
	}

	/**
	 * Unfiltered list-settings input is refused.
	 */
	public function test_list_input_requires_a_filter() {
		$this->assertFalse( Ahentic_Abilities_Settings::list_input_is_filtered( array() ) );
		$this->assertFalse( Ahentic_Abilities_Settings::list_input_is_filtered( array( 'limit' => 10 ) ) );
		$this->assertTrue( Ahentic_Abilities_Settings::list_input_is_filtered( array( 'query' => 'header' ) ) );
		$this->assertTrue( Ahentic_Abilities_Settings::list_input_is_filtered( array( 'section' => 'colors' ) ) );
		$this->assertTrue( Ahentic_Abilities_Settings::list_input_is_filtered( array( 'prefix' => 'header_' ) ) );
	}

	/**
	 * Code-bearing Customizer settings are excluded from discovery.
	 */
	public function test_excludes_code_bearing_settings() {
		$this->assertTrue(
			Ahentic_Abilities_Settings::is_code_bearing_index_entry(
				array(
					'id'           => 'custom_css[twentytwentyfour]',
					'control_type' => 'textarea',
					'capability'   => 'edit_theme_options',
				)
			)
		);
		$this->assertFalse(
			Ahentic_Abilities_Settings::is_code_bearing_index_entry(
				array(
					'id'           => 'custom_css_extra_setting',
					'control_type' => 'text',
					'capability'   => 'edit_theme_options',
				)
			)
		);
		$this->assertTrue(
			Ahentic_Abilities_Settings::is_code_bearing_index_entry(
				array(
					'id'           => 'blocksy_custom_js',
					'control_type' => 'WP_Customize_Code_Editor_Control',
					'capability'   => 'edit_theme_options',
				)
			)
		);
		$this->assertTrue(
			Ahentic_Abilities_Settings::is_code_bearing_index_entry(
				array(
					'id'           => 'extra_html',
					'control_type' => 'textarea',
					'capability'   => 'unfiltered_html',
				)
			)
		);
		$this->assertTrue(
			Ahentic_Abilities_Settings::is_code_bearing_index_entry(
				array(
					'id'           => 'extra_css',
					'control_type' => 'textarea',
					'capability'   => 'edit_css',
				)
			)
		);
		$this->assertFalse(
			Ahentic_Abilities_Settings::is_code_bearing_index_entry(
				array(
					'id'           => 'blogname',
					'control_type' => 'text',
					'capability'   => 'edit_theme_options',
				)
			)
		);
	}

	/**
	 * Keyword / section / prefix filters narrow the index.
	 */
	public function test_filter_settings_index_by_query_section_prefix() {
		$entries = array(
			array(
				'id'      => 'header_layout',
				'label'   => 'Header layout',
				'section' => 'header',
			),
			array(
				'id'      => 'footer_layout',
				'label'   => 'Footer layout',
				'section' => 'footer',
			),
			array(
				'id'      => 'header_height',
				'label'   => 'Height',
				'section' => 'header',
			),
		);

		$by_query = Ahentic_Abilities_Settings::filter_settings_index( $entries, array( 'query' => 'footer' ) );
		$this->assertCount( 1, $by_query );
		$this->assertSame( 'footer_layout', $by_query[0]['id'] );

		$by_section = Ahentic_Abilities_Settings::filter_settings_index( $entries, array( 'section' => 'header' ) );
		$this->assertCount( 2, $by_section );

		$by_prefix = Ahentic_Abilities_Settings::filter_settings_index( $entries, array( 'prefix' => 'header_' ) );
		$this->assertCount( 2, $by_prefix );
	}

	/**
	 * Large nested values summarize by default; raw opt-in returns the blob when under cap.
	 */
	public function test_large_values_summarize_unless_raw() {
		$value = array(
			'sections' => array(
				array(
					'id'    => 'main',
					'items' => array(
						array( 'id' => 'logo', 'enabled' => true ),
						array( 'id' => 'menu', 'enabled' => true ),
					),
				),
			),
			'meta'     => array( 'version' => 1 ),
		);

		$summary = Ahentic_Abilities_Settings::value_for_get_setting( $value, false, 50 );
		$this->assertTrue( $summary['summarized'] );
		$this->assertArrayHasKey( 'shape', $summary );
		$this->assertSame( array( 'sections', 'meta' ), $summary['shape']['keys'] );
		$this->assertContains( 'logo', $summary['shape']['sections_item_ids'] );
		$this->assertContains( 'menu', $summary['shape']['sections_item_ids'] );
		$this->assertArrayNotHasKey( 'value', $summary );

		$raw = Ahentic_Abilities_Settings::value_for_get_setting( $value, true, 50000 );
		$this->assertFalse( $raw['summarized'] );
		$this->assertSame( $value, $raw['value'] );
	}

	/**
	 * Context payload reports surfaces from block vs classic without Customizer data.
	 */
	public function test_settings_context_surfaces_for_block_and_classic() {
		$classic = Ahentic_Abilities_Settings::settings_context_payload( 'astra', false );
		$this->assertSame( 'astra', $classic['stylesheet'] );
		$this->assertFalse( $classic['is_block_theme'] );
		$this->assertContains( 'theme_settings', $classic['surfaces'] );
		$this->assertNotContains( 'global_styles', $classic['surfaces'] );
		$this->assertStringContainsString( 'list-settings', $classic['routing_hint'] );

		$block = Ahentic_Abilities_Settings::settings_context_payload( 'twentytwentyfour', true );
		$this->assertTrue( $block['is_block_theme'] );
		$this->assertContains( 'global_styles', $block['surfaces'] );
		$this->assertContains( 'template_parts', $block['surfaces'] );
		$this->assertNotContains( 'theme_settings', $block['surfaces'] );
		$this->assertStringNotContainsString( 'list-settings', $block['routing_hint'] );
	}
}
