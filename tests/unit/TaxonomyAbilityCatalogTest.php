<?php
/**
 * Taxonomy ability policy lists must stay derived from one catalog.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Locks Ahentic_Abilities_Taxonomy policy derivation (anti-slop catalog guard).
 */
class TaxonomyAbilityCatalogTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-taxonomy.php';
	}

	/**
	 * write_names and hitl_names are subsets of names — no orphan policy flags.
	 */
	public function test_policy_lists_are_subsets_of_names() {
		$names  = Ahentic_Abilities_Taxonomy::names();
		$writes = Ahentic_Abilities_Taxonomy::write_names();
		$hitl   = Ahentic_Abilities_Taxonomy::hitl_names();

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
		foreach ( Ahentic_Abilities_Taxonomy::names() as $name ) {
			$this->assertNotSame( '', Ahentic_Abilities_Taxonomy::progress_label( $name ), 'progress: ' . $name );
			$this->assertNotSame( '', Ahentic_Abilities_Taxonomy::summary( $name ), 'summary: ' . $name );
			$this->assertNotSame( $name, Ahentic_Abilities_Taxonomy::summary( $name ), 'summary fallback to raw name: ' . $name );
		}
	}

	/**
	 * Readonly ↔ write flags stay inverse for every taxonomy ability.
	 */
	public function test_readonly_matches_write_flag() {
		$writes = Ahentic_Abilities_Taxonomy::write_names();
		foreach ( Ahentic_Abilities_Taxonomy::names() as $name ) {
			$is_write = in_array( $name, $writes, true );
			$this->assertSame( ! $is_write, Ahentic_Abilities_Taxonomy::is_readonly( $name ), $name );
		}
	}

	/**
	 * HITL membership matches requires_hitl for every taxonomy ability.
	 */
	public function test_requires_hitl_matches_hitl_names() {
		$hitl = Ahentic_Abilities_Taxonomy::hitl_names();
		foreach ( Ahentic_Abilities_Taxonomy::names() as $name ) {
			$this->assertSame(
				in_array( $name, $hitl, true ),
				Ahentic_Abilities_Taxonomy::requires_hitl( $name ),
				$name
			);
		}
	}

	/**
	 * non_preallowable names are HITL writes (no orphan irreversible flags).
	 */
	public function test_non_preallowable_are_hitl_writes() {
		$names = Ahentic_Abilities_Taxonomy::names();
		$hitl  = Ahentic_Abilities_Taxonomy::hitl_names();
		foreach ( Ahentic_Abilities_Taxonomy::non_preallowable_names() as $name ) {
			$this->assertContains( $name, $names, 'non_preallowable orphan: ' . $name );
			$this->assertContains( $name, $hitl, 'non_preallowable must be HITL: ' . $name );
			$this->assertTrue( Ahentic_Abilities_Taxonomy::is_non_preallowable( $name ), $name );
		}
	}

	/**
	 * CRUD set is present; delete-term is the only non-preallowable taxonomy write.
	 */
	public function test_crud_names_and_delete_is_non_preallowable() {
		$names = Ahentic_Abilities_Taxonomy::names();
		$this->assertContains( 'ahentic/list-terms', $names );
		$this->assertContains( 'ahentic/get-term', $names );
		$this->assertContains( 'ahentic/create-term', $names );
		$this->assertContains( 'ahentic/update-term', $names );
		$this->assertContains( 'ahentic/delete-term', $names );

		$this->assertTrue( Ahentic_Abilities_Taxonomy::is_readonly( 'ahentic/list-terms' ) );
		$this->assertTrue( Ahentic_Abilities_Taxonomy::is_readonly( 'ahentic/get-term' ) );
		$this->assertTrue( Ahentic_Abilities_Taxonomy::requires_hitl( 'ahentic/create-term' ) );
		$this->assertTrue( Ahentic_Abilities_Taxonomy::requires_hitl( 'ahentic/update-term' ) );
		$this->assertTrue( Ahentic_Abilities_Taxonomy::requires_hitl( 'ahentic/delete-term' ) );
		$this->assertFalse( Ahentic_Abilities_Taxonomy::is_non_preallowable( 'ahentic/create-term' ) );
		$this->assertFalse( Ahentic_Abilities_Taxonomy::is_non_preallowable( 'ahentic/update-term' ) );
		$this->assertTrue( Ahentic_Abilities_Taxonomy::is_non_preallowable( 'ahentic/delete-term' ) );
		$this->assertSame( array( 'ahentic/delete-term' ), Ahentic_Abilities_Taxonomy::non_preallowable_names() );
	}
}
