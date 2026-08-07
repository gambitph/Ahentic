<?php
/**
 * Media ability policy lists must stay derived from one catalog.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Locks Ahentic_Abilities_Media policy derivation (anti-slop catalog guard).
 */
class MediaAbilityCatalogTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-media.php';
	}

	/**
	 * write_names and hitl_names are subsets of names — no orphan policy flags.
	 */
	public function test_policy_lists_are_subsets_of_names() {
		$names  = Ahentic_Abilities_Media::names();
		$writes = Ahentic_Abilities_Media::write_names();
		$hitl   = Ahentic_Abilities_Media::hitl_names();

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
		foreach ( Ahentic_Abilities_Media::names() as $name ) {
			$this->assertNotSame( '', Ahentic_Abilities_Media::progress_label( $name ), 'progress: ' . $name );
			$this->assertNotSame( '', Ahentic_Abilities_Media::summary( $name ), 'summary: ' . $name );
			$this->assertNotSame( $name, Ahentic_Abilities_Media::summary( $name ), 'summary fallback to raw name: ' . $name );
		}
	}

	/**
	 * Readonly ↔ write flags stay inverse for every media ability.
	 */
	public function test_readonly_matches_write_flag() {
		$writes = Ahentic_Abilities_Media::write_names();
		foreach ( Ahentic_Abilities_Media::names() as $name ) {
			$is_write = in_array( $name, $writes, true );
			$this->assertSame( ! $is_write, Ahentic_Abilities_Media::is_readonly( $name ), $name );
		}
	}

	/**
	 * HITL membership matches requires_hitl for every media ability.
	 */
	public function test_requires_hitl_matches_hitl_names() {
		$hitl = Ahentic_Abilities_Media::hitl_names();
		foreach ( Ahentic_Abilities_Media::names() as $name ) {
			$this->assertSame(
				in_array( $name, $hitl, true ),
				Ahentic_Abilities_Media::requires_hitl( $name ),
				$name
			);
		}
	}

	/**
	 * non_preallowable names are HITL writes (no orphan irreversible flags).
	 */
	public function test_non_preallowable_are_hitl_writes() {
		$names = Ahentic_Abilities_Media::names();
		$hitl  = Ahentic_Abilities_Media::hitl_names();
		foreach ( Ahentic_Abilities_Media::non_preallowable_names() as $name ) {
			$this->assertContains( $name, $names, 'non_preallowable orphan: ' . $name );
			$this->assertContains( $name, $hitl, 'non_preallowable must be HITL: ' . $name );
			$this->assertTrue( Ahentic_Abilities_Media::is_non_preallowable( $name ), $name );
		}
	}

	/**
	 * list-media / get-media are catalogued readonly browse abilities.
	 */
	public function test_list_and_get_media_are_readonly() {
		$names = Ahentic_Abilities_Media::names();
		$this->assertContains( 'ahentic/list-media', $names );
		$this->assertContains( 'ahentic/get-media', $names );
		$this->assertTrue( Ahentic_Abilities_Media::is_readonly( 'ahentic/list-media' ) );
		$this->assertTrue( Ahentic_Abilities_Media::is_readonly( 'ahentic/get-media' ) );
		$this->assertFalse( Ahentic_Abilities_Media::requires_hitl( 'ahentic/list-media' ) );
		$this->assertFalse( Ahentic_Abilities_Media::requires_hitl( 'ahentic/get-media' ) );
		$this->assertSame(
			'List media',
			Ahentic_Abilities_Media::summary( 'ahentic/list-media' )
		);
		$this->assertSame(
			'Get media',
			Ahentic_Abilities_Media::summary( 'ahentic/get-media' )
		);
	}
}
