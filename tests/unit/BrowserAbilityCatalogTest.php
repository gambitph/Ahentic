<?php
/**
 * Browser ability policy lists must stay derived from one catalog.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Locks Ahentic_Abilities_Browser policy derivation (M4 maintainability).
 */
class BrowserAbilityCatalogTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-browser.php';
	}

	/**
	 * write_names and hitl_names are subsets of names — no orphan policy flags.
	 */
	public function test_policy_lists_are_subsets_of_names() {
		$names = Ahentic_Abilities_Browser::names();
		$writes = Ahentic_Abilities_Browser::write_names();
		$hitl   = Ahentic_Abilities_Browser::hitl_names();

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
		foreach ( Ahentic_Abilities_Browser::names() as $name ) {
			$this->assertNotSame( '', Ahentic_Abilities_Browser::progress_label( $name ), 'progress: ' . $name );
			$this->assertNotSame( '', Ahentic_Abilities_Browser::summary( $name ), 'summary: ' . $name );
			$this->assertNotSame( $name, Ahentic_Abilities_Browser::summary( $name ), 'summary fallback to raw name: ' . $name );
		}
	}

	/**
	 * Readonly ↔ write flags stay inverse for every browser ability.
	 */
	public function test_readonly_matches_write_flag() {
		$writes = Ahentic_Abilities_Browser::write_names();
		foreach ( Ahentic_Abilities_Browser::names() as $name ) {
			$is_write = in_array( $name, $writes, true );
			$this->assertSame( ! $is_write, Ahentic_Abilities_Browser::is_readonly( $name ), $name );
			$this->assertSame( $is_write, ! Ahentic_Abilities_Browser::is_readonly( $name ), $name );
		}
	}

	/**
	 * page_only names are a subset of names (admin-form / page tools).
	 */
	public function test_page_only_lists_are_subsets_of_names() {
		$names = Ahentic_Abilities_Browser::names();
		foreach ( Ahentic_Abilities_Browser::page_only_names() as $name ) {
			$this->assertContains( $name, $names, 'page_only orphan: ' . $name );
		}
		$this->assertTrue( Ahentic_Abilities_Browser::is_page_only( 'ahentic-browser/fill-fields' ) );
		$this->assertTrue( Ahentic_Abilities_Browser::is_page_only( 'ahentic-browser/get-visible-page' ) );
		$this->assertFalse( Ahentic_Abilities_Browser::is_page_only( 'ahentic-browser/set-blocks' ) );
	}

	/**
	 * non_preallowable names are HITL writes (no orphan irreversible flags).
	 */
	public function test_convert_blocks_hitl_summary_includes_target() {
		$this->assertSame(
			'Convert blocks toward stackable',
			Ahentic_Abilities_Browser::hitl_summary(
				'ahentic-browser/convert-blocks',
				array( 'target' => 'stackable' )
			)
		);
		$this->assertSame(
			'Convert blocks toward core/heading',
			Ahentic_Abilities_Browser::hitl_summary(
				'ahentic-browser/convert-blocks',
				array( 'target' => 'core/heading' )
			)
		);
		$this->assertSame(
			'Convert blocks toward core',
			Ahentic_Abilities_Browser::hitl_summary( 'ahentic-browser/convert-blocks', array() )
		);
	}
}
