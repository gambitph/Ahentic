<?php
/**
 * Ability module registry seam: register_module + facade queries.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Abilities::register_module and catalog-backed facade methods.
 */
class AbilityCatalogTest extends TestCase {

	/**
	 * Load facade only (modules register themselves when required elsewhere).
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities.php';
	}

	/**
	 * Isolate the registry between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		Ahentic_Abilities::reset_modules_for_tests();
	}

	/**
	 * A registered module's names appear in available_for_agent.
	 */
	public function test_register_module_exposes_names() {
		Ahentic_Abilities::register_module( 'Ahentic_Ability_Catalog_Fake_Module' );

		$this->assertContains( 'ahentic/fake-read', Ahentic_Abilities::available_for_agent() );
		$this->assertContains( 'ahentic/fake-write', Ahentic_Abilities::available_for_agent() );
	}

	/**
	 * Facade requires_hitl / progress_label delegate to the owning module.
	 */
	public function test_facade_delegates_hitl_and_progress_label() {
		Ahentic_Abilities::register_module( 'Ahentic_Ability_Catalog_Fake_Module' );

		$this->assertTrue( Ahentic_Abilities::requires_hitl( 'ahentic/fake-write' ) );
		$this->assertFalse( Ahentic_Abilities::requires_hitl( 'ahentic/fake-read' ) );
		$this->assertSame(
			'Doing fake write…',
			Ahentic_Abilities::progress_label( 'ahentic/fake-write' )
		);
		$this->assertSame( '', Ahentic_Abilities::progress_label( 'ahentic/unknown' ) );
	}

	/**
	 * register_module is idempotent.
	 */
	public function test_register_module_idempotent() {
		Ahentic_Abilities::register_module( 'Ahentic_Ability_Catalog_Fake_Module' );
		Ahentic_Abilities::register_module( 'Ahentic_Ability_Catalog_Fake_Module' );

		$names = Ahentic_Abilities::available_for_agent();
		$this->assertSame( 1, count( array_keys( $names, 'ahentic/fake-read', true ) ) );
	}
}

/**
 * Minimal ability group for registry tests (not a real product module).
 */
class Ahentic_Ability_Catalog_Fake_Module {

	/**
	 * @return string[]
	 */
	public static function names() {
		return array( 'ahentic/fake-read', 'ahentic/fake-write' );
	}

	/**
	 * @param string $name Ability name.
	 * @return bool
	 */
	public static function requires_hitl( $name ) {
		return 'ahentic/fake-write' === (string) $name;
	}

	/**
	 * @param string $name Ability name.
	 * @return string
	 */
	public static function progress_label( $name ) {
		if ( 'ahentic/fake-write' === (string) $name ) {
			return 'Doing fake write…';
		}
		return '';
	}
}
