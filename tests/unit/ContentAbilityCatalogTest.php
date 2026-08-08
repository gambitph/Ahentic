<?php
/**
 * Content ability policy lists must stay derived from one catalog.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Locks Ahentic_Abilities_Content policy derivation (M2 maintainability).
 */
class ContentAbilityCatalogTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-content.php';
	}

	/**
	 * write_names and hitl_names are subsets of names — no orphan policy flags.
	 */
	public function test_policy_lists_are_subsets_of_names() {
		$names  = Ahentic_Abilities_Content::names();
		$writes = Ahentic_Abilities_Content::write_names();
		$hitl   = Ahentic_Abilities_Content::hitl_names();

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
		foreach ( Ahentic_Abilities_Content::names() as $name ) {
			$this->assertNotSame( '', Ahentic_Abilities_Content::progress_label( $name ), 'progress: ' . $name );
			$this->assertNotSame( '', Ahentic_Abilities_Content::summary( $name ), 'summary: ' . $name );
			$this->assertNotSame( $name, Ahentic_Abilities_Content::summary( $name ), 'summary fallback to raw name: ' . $name );
		}
	}

	/**
	 * Readonly ↔ write flags stay inverse for every content ability.
	 */
	public function test_readonly_matches_write_flag() {
		$writes = Ahentic_Abilities_Content::write_names();
		foreach ( Ahentic_Abilities_Content::names() as $name ) {
			$is_write = in_array( $name, $writes, true );
			$this->assertSame( ! $is_write, Ahentic_Abilities_Content::is_readonly( $name ), $name );
		}
	}

	/**
	 * HITL membership matches requires_hitl for every content ability.
	 */
	public function test_requires_hitl_matches_hitl_names() {
		$hitl = Ahentic_Abilities_Content::hitl_names();
		foreach ( Ahentic_Abilities_Content::names() as $name ) {
			$this->assertSame(
				in_array( $name, $hitl, true ),
				Ahentic_Abilities_Content::requires_hitl( $name ),
				$name
			);
		}
	}

	/**
	 * set-post-status HITL never falls back to the opaque “unknown” placeholder.
	 */
	public function test_set_status_hitl_summary_never_says_unknown() {
		$summary = Ahentic_Abilities_Content::hitl_summary(
			'ahentic/set-post-status',
			array( 'id' => 42 )
		);
		$this->assertStringNotContainsString( 'unknown', strtolower( $summary ) );
		$this->assertStringContainsString( 'unspecified status', $summary );
		$this->assertStringContainsString( '42', $summary );
	}

	/**
	 * get-content-summary is catalogued as readonly discovery (cache write is an impl detail).
	 */
	public function test_get_content_summary_is_readonly_not_hitl() {
		$name = 'ahentic/get-content-summary';
		$this->assertContains( $name, Ahentic_Abilities_Content::names() );
		$this->assertTrue( Ahentic_Abilities_Content::is_readonly( $name ) );
		$this->assertFalse( Ahentic_Abilities_Content::requires_hitl( $name ) );
		$this->assertSame( 'ahentic/get-content-summary', Ahentic_Abilities_Content::GET_SUMMARY );
	}
}
