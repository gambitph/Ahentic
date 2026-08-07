<?php
/**
 * Menus ability policy lists must stay derived from one catalog.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Locks Ahentic_Abilities_Menus policy derivation + tree helpers.
 */
class MenusAbilityCatalogTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-menus.php';
	}

	/**
	 * write_names and hitl_names are subsets of names — no orphan policy flags.
	 */
	public function test_policy_lists_are_subsets_of_names() {
		$names  = Ahentic_Abilities_Menus::names();
		$writes = Ahentic_Abilities_Menus::write_names();
		$hitl   = Ahentic_Abilities_Menus::hitl_names();

		$this->assertNotEmpty( $names );
		foreach ( $writes as $name ) {
			$this->assertContains( $name, $names, 'write orphan: ' . $name );
		}
		foreach ( $hitl as $name ) {
			$this->assertContains( $name, $writes, 'hitl must be a write: ' . $name );
		}
	}

	/**
	 * Every catalogued ability has progress + summary strings.
	 */
	public function test_every_name_has_progress_and_summary() {
		foreach ( Ahentic_Abilities_Menus::names() as $name ) {
			$this->assertNotSame( '', Ahentic_Abilities_Menus::progress_label( $name ), 'progress: ' . $name );
			$this->assertNotSame( '', Ahentic_Abilities_Menus::summary( $name ), 'summary: ' . $name );
			$this->assertNotSame( $name, Ahentic_Abilities_Menus::summary( $name ), 'summary fallback: ' . $name );
		}
	}

	/**
	 * Readonly ↔ write flags stay inverse.
	 */
	public function test_readonly_matches_write_flag() {
		$writes = Ahentic_Abilities_Menus::write_names();
		foreach ( Ahentic_Abilities_Menus::names() as $name ) {
			$is_write = in_array( $name, $writes, true );
			$this->assertSame( ! $is_write, Ahentic_Abilities_Menus::is_readonly( $name ), $name );
		}
	}

	/**
	 * Classic menu set: three reads + one HITL write (not non-preallowable).
	 */
	public function test_menu_ability_set() {
		$names = Ahentic_Abilities_Menus::names();
		$this->assertContains( 'ahentic/list-menus', $names );
		$this->assertContains( 'ahentic/list-menu-items', $names );
		$this->assertContains( 'ahentic/get-menu', $names );
		$this->assertContains( 'ahentic/update-menu', $names );

		$this->assertTrue( Ahentic_Abilities_Menus::is_readonly( 'ahentic/list-menus' ) );
		$this->assertTrue( Ahentic_Abilities_Menus::is_readonly( 'ahentic/list-menu-items' ) );
		$this->assertTrue( Ahentic_Abilities_Menus::is_readonly( 'ahentic/get-menu' ) );

		$this->assertTrue( Ahentic_Abilities_Menus::requires_hitl( 'ahentic/update-menu' ) );
		$this->assertFalse( Ahentic_Abilities_Menus::is_non_preallowable( 'ahentic/update-menu' ) );
		$this->assertSame( array(), Ahentic_Abilities_Menus::non_preallowable_names() );
	}

	/**
	 * Nested item counter is pure (no WP).
	 */
	public function test_count_item_nodes() {
		$tree = array(
			array(
				'title'    => 'A',
				'type'     => 'custom',
				'url'      => '/',
				'children' => array(
					array(
						'title' => 'B',
						'type'  => 'custom',
						'url'   => '/b',
					),
				),
			),
			array(
				'title' => 'C',
				'type'  => 'custom',
				'url'   => '/c',
			),
		);
		$this->assertSame( 3, Ahentic_Abilities_Menus::count_item_nodes( $tree ) );
		$this->assertSame( 0, Ahentic_Abilities_Menus::count_item_nodes( array() ) );
	}
}
