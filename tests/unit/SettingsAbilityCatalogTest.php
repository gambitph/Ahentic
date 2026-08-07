<?php
/**
 * Settings ability policy lists must stay derived from one catalog.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Locks Ahentic_Abilities_Settings policy derivation (anti-slop catalog guard).
 */
class SettingsAbilityCatalogTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-settings.php';
	}

	/**
	 * write_names and hitl_names are subsets of names — no orphan policy flags.
	 */
	public function test_policy_lists_are_subsets_of_names() {
		$names  = Ahentic_Abilities_Settings::names();
		$writes = Ahentic_Abilities_Settings::write_names();
		$hitl   = Ahentic_Abilities_Settings::hitl_names();

		$this->assertNotEmpty( $names );
		foreach ( $writes as $name ) {
			$this->assertContains( $name, $names, 'write orphan: ' . $name );
		}
		foreach ( $hitl as $name ) {
			$this->assertContains( $name, $writes, 'hitl must be a write: ' . $name );
		}
	}

	/**
	 * Every catalogued ability has progress + summary strings (no silent empty labels).
	 */
	public function test_every_name_has_progress_and_summary() {
		foreach ( Ahentic_Abilities_Settings::names() as $name ) {
			$this->assertNotSame( '', Ahentic_Abilities_Settings::progress_label( $name ), 'progress: ' . $name );
			$this->assertNotSame( '', Ahentic_Abilities_Settings::summary( $name ), 'summary: ' . $name );
			$this->assertNotSame( $name, Ahentic_Abilities_Settings::summary( $name ), 'summary fallback to raw name: ' . $name );
		}
	}

	/**
	 * Readonly ↔ write flags stay inverse for every settings ability.
	 */
	public function test_readonly_matches_write_flag() {
		$writes = Ahentic_Abilities_Settings::write_names();
		foreach ( Ahentic_Abilities_Settings::names() as $name ) {
			$is_write = in_array( $name, $writes, true );
			$this->assertSame( ! $is_write, Ahentic_Abilities_Settings::is_readonly( $name ), $name );
		}
	}

	/**
	 * Discovery abilities are readonly (Task 07); update-theme-setting is write + HITL (Task 08).
	 */
	public function test_discovery_abilities_are_readonly() {
		foreach (
			array(
				'ahentic/get-settings-context',
				'ahentic/list-settings',
				'ahentic/get-setting',
			) as $name
		) {
			$this->assertContains( $name, Ahentic_Abilities_Settings::names() );
			$this->assertTrue( Ahentic_Abilities_Settings::is_readonly( $name ), $name );
			$this->assertFalse( Ahentic_Abilities_Settings::requires_hitl( $name ), $name );
		}
	}

	/**
	 * update-theme-setting, update-global-styles, and update-option are standard HITL writes (not non-preallowable).
	 * update-template-part is HITL + non-preallowable (theme-file decoupling).
	 */
	public function test_settings_write_abilities_are_hitl() {
		foreach (
			array(
				'ahentic/update-theme-setting',
				'ahentic/update-global-styles',
				'ahentic/update-option',
			) as $name
		) {
			$this->assertContains( $name, Ahentic_Abilities_Settings::names() );
			$this->assertContains( $name, Ahentic_Abilities_Settings::write_names() );
			$this->assertFalse( Ahentic_Abilities_Settings::is_readonly( $name ) );
			$this->assertTrue( Ahentic_Abilities_Settings::requires_hitl( $name ) );
			$this->assertFalse( Ahentic_Abilities_Settings::is_non_preallowable( $name ), $name );
			$this->assertNotSame( '', Ahentic_Abilities_Settings::progress_label( $name ) );
			$this->assertNotSame( $name, Ahentic_Abilities_Settings::summary( $name ) );
		}

		$this->assertContains( 'ahentic/update-template-part', Ahentic_Abilities_Settings::names() );
		$this->assertTrue( Ahentic_Abilities_Settings::requires_hitl( 'ahentic/update-template-part' ) );
		$this->assertTrue( Ahentic_Abilities_Settings::is_non_preallowable( 'ahentic/update-template-part' ) );
		$this->assertSame( array( 'ahentic/update-template-part' ), Ahentic_Abilities_Settings::non_preallowable_names() );
	}
}
