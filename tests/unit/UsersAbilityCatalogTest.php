<?php
/**
 * Users ability policy lists must stay derived from one catalog.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Locks Ahentic_Abilities_Users policy derivation + pure role-ceiling helper.
 */
class UsersAbilityCatalogTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-users.php';
	}

	/**
	 * write_names and hitl_names are subsets of names — no orphan policy flags.
	 */
	public function test_policy_lists_are_subsets_of_names() {
		$names  = Ahentic_Abilities_Users::names();
		$writes = Ahentic_Abilities_Users::write_names();
		$hitl   = Ahentic_Abilities_Users::hitl_names();

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
		foreach ( Ahentic_Abilities_Users::names() as $name ) {
			$this->assertNotSame( '', Ahentic_Abilities_Users::progress_label( $name ), 'progress: ' . $name );
			$this->assertNotSame( '', Ahentic_Abilities_Users::summary( $name ), 'summary: ' . $name );
			$this->assertNotSame( $name, Ahentic_Abilities_Users::summary( $name ), 'summary fallback to raw name: ' . $name );
		}
	}

	/**
	 * Readonly ↔ write flags stay inverse for every users ability.
	 */
	public function test_readonly_matches_write_flag() {
		$writes = Ahentic_Abilities_Users::write_names();
		foreach ( Ahentic_Abilities_Users::names() as $name ) {
			$is_write = in_array( $name, $writes, true );
			$this->assertSame( ! $is_write, Ahentic_Abilities_Users::is_readonly( $name ), $name );
		}
	}

	/**
	 * HITL membership matches requires_hitl for every users ability.
	 */
	public function test_requires_hitl_matches_hitl_names() {
		$hitl = Ahentic_Abilities_Users::hitl_names();
		foreach ( Ahentic_Abilities_Users::names() as $name ) {
			$this->assertSame(
				in_array( $name, $hitl, true ),
				Ahentic_Abilities_Users::requires_hitl( $name ),
				$name
			);
		}
	}

	/**
	 * non_preallowable names are HITL writes (no orphan irreversible flags).
	 */
	public function test_non_preallowable_are_hitl_writes() {
		$names = Ahentic_Abilities_Users::names();
		$hitl  = Ahentic_Abilities_Users::hitl_names();
		foreach ( Ahentic_Abilities_Users::non_preallowable_names() as $name ) {
			$this->assertContains( $name, $names, 'non_preallowable orphan: ' . $name );
			$this->assertContains( $name, $hitl, 'non_preallowable must be HITL: ' . $name );
			$this->assertTrue( Ahentic_Abilities_Users::is_non_preallowable( $name ), $name );
		}
	}

	/**
	 * CRUD set: list readonly; create/update/delete are HITL + non-preallowable.
	 */
	public function test_crud_names_and_writes_are_non_preallowable() {
		$names = Ahentic_Abilities_Users::names();
		$this->assertContains( 'ahentic/list-users', $names );
		$this->assertContains( 'ahentic/create-user', $names );
		$this->assertContains( 'ahentic/update-user', $names );
		$this->assertContains( 'ahentic/delete-user', $names );

		$this->assertTrue( Ahentic_Abilities_Users::is_readonly( 'ahentic/list-users' ) );
		$this->assertFalse( Ahentic_Abilities_Users::requires_hitl( 'ahentic/list-users' ) );
		$this->assertFalse( Ahentic_Abilities_Users::is_non_preallowable( 'ahentic/list-users' ) );

		foreach ( array( 'ahentic/create-user', 'ahentic/update-user', 'ahentic/delete-user' ) as $write ) {
			$this->assertFalse( Ahentic_Abilities_Users::is_readonly( $write ), $write );
			$this->assertTrue( Ahentic_Abilities_Users::requires_hitl( $write ), $write );
			$this->assertTrue( Ahentic_Abilities_Users::is_non_preallowable( $write ), $write );
		}

		$this->assertSame(
			array( 'ahentic/create-user', 'ahentic/update-user', 'ahentic/delete-user' ),
			Ahentic_Abilities_Users::non_preallowable_names()
		);
	}

	/**
	 * Role ceiling: proper subset only — equal ("at") and supersets ("above") refuse.
	 */
	public function test_role_is_below_ceiling_capability_comparison() {
		$admin = array( 'read', 'edit_posts', 'edit_users', 'manage_options' );
		$editor = array( 'read', 'edit_posts' );
		$author = array( 'read', 'edit_posts' ); // same as editor for this fixture → not below
		$subscriber = array( 'read' );

		$this->assertTrue( Ahentic_Abilities_Users::role_is_below_ceiling( $admin, $editor ) );
		$this->assertTrue( Ahentic_Abilities_Users::role_is_below_ceiling( $admin, $subscriber ) );
		$this->assertTrue( Ahentic_Abilities_Users::role_is_below_ceiling( $editor, $subscriber ) );

		// At: equal caps.
		$this->assertFalse( Ahentic_Abilities_Users::role_is_below_ceiling( $admin, $admin ) );
		$this->assertFalse( Ahentic_Abilities_Users::role_is_below_ceiling( $editor, $author ) );

		// Above: target has caps the operator lacks.
		$this->assertFalse( Ahentic_Abilities_Users::role_is_below_ceiling( $editor, $admin ) );
		$this->assertFalse( Ahentic_Abilities_Users::role_is_below_ceiling( $subscriber, $editor ) );
	}

	/**
	 * delete-user HITL card names both the target and the reassignment destination.
	 */
	public function test_delete_hitl_summary_names_target_and_reassign() {
		$summary = Ahentic_Abilities_Users::hitl_summary(
			'ahentic/delete-user',
			array(
				'user_id'     => 7,
				'reassign_to' => 3,
			)
		);
		$this->assertStringContainsString( '7', $summary );
		$this->assertStringContainsString( '3', $summary );
		$this->assertMatchesRegularExpression( '/reassign|content/i', $summary );
	}
}
