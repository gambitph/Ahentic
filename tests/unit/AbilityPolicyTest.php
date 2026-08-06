<?php
/**
 * Ability policy characterization: HITL / readonly / browser flags.
 *
 * These lists are the decision surface a HITL-policy module and ToolRunner
 * will call. Locking them in pure PHPUnit means an architecture extract that
 * accidentally drops create-post from HITL (or marks a browser tool as
 * server-only) fails before e2e.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers requires_hitl / is_readonly / is_browser on ability modules + facade.
 */
class AbilityPolicyTest extends TestCase {

	/**
	 * Load ability class files (no WordPress boot — methods under test are list membership).
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$root = dirname( __DIR__, 2 );
		require_once $root . '/src/abilities/class-abilities-content.php';
		require_once $root . '/src/abilities/class-abilities-plugins.php';
		require_once $root . '/src/abilities/class-abilities-browser.php';
		require_once $root . '/src/abilities/class-abilities-taxonomy.php';
		require_once $root . '/src/abilities/class-abilities.php';
	}

	/**
	 * Content writes pause for HITL; reads do not.
	 */
	public function test_content_hitl_and_readonly_flags() {
		$this->assertTrue( Ahentic_Abilities_Content::requires_hitl( 'ahentic/create-post' ) );
		$this->assertTrue( Ahentic_Abilities_Content::requires_hitl( 'ahentic/update-post' ) );
		$this->assertTrue( Ahentic_Abilities_Content::requires_hitl( 'ahentic/set-post-status' ) );

		$this->assertFalse( Ahentic_Abilities_Content::requires_hitl( 'ahentic/list-content' ) );
		$this->assertFalse( Ahentic_Abilities_Content::requires_hitl( 'ahentic/get-content' ) );
		$this->assertFalse( Ahentic_Abilities_Content::requires_hitl( 'ahentic/search-content' ) );

		$this->assertTrue( Ahentic_Abilities_Content::is_readonly( 'ahentic/list-content' ) );
		$this->assertFalse( Ahentic_Abilities_Content::is_readonly( 'ahentic/create-post' ) );
	}

	/**
	 * Plugin mutators pause for HITL.
	 */
	public function test_plugin_hitl_flags() {
		$this->assertTrue( Ahentic_Abilities_Plugins::requires_hitl( 'ahentic/install-plugin' ) );
		$this->assertTrue( Ahentic_Abilities_Plugins::requires_hitl( 'ahentic/activate-plugin' ) );
		$this->assertTrue( Ahentic_Abilities_Plugins::requires_hitl( 'ahentic/deactivate-plugin' ) );
		$this->assertTrue( Ahentic_Abilities_Plugins::requires_hitl( 'ahentic/uninstall-plugin' ) );

		$this->assertFalse( Ahentic_Abilities_Plugins::requires_hitl( 'ahentic/list-plugins' ) );
		$this->assertFalse( Ahentic_Abilities_Plugins::requires_hitl( 'ahentic/search-plugins' ) );
	}

	/**
	 * Browser catalog: save/convert need HITL; page reads are browser runtime.
	 */
	public function test_browser_runtime_and_hitl_flags() {
		$this->assertTrue( Ahentic_Abilities_Browser::is_browser( 'ahentic-browser/get-current-page' ) );
		$this->assertTrue( Ahentic_Abilities_Browser::is_browser( 'ahentic-browser/save-post' ) );
		$this->assertFalse( Ahentic_Abilities_Browser::is_browser( 'ahentic/create-post' ) );

		$this->assertTrue( Ahentic_Abilities_Browser::requires_hitl( 'ahentic-browser/save-post' ) );
		$this->assertTrue( Ahentic_Abilities_Browser::requires_hitl( 'ahentic-browser/convert-blocks' ) );
		$this->assertFalse( Ahentic_Abilities_Browser::requires_hitl( 'ahentic-browser/get-current-page' ) );
		$this->assertFalse( Ahentic_Abilities_Browser::requires_hitl( 'ahentic-browser/set-blocks' ) );
	}

	/**
	 * Facade must fan out to every domain module (ToolRunner will call this).
	 */
	public function test_facade_requires_hitl_covers_all_modules() {
		$this->assertTrue( Ahentic_Abilities::requires_hitl( 'ahentic/create-post' ) );
		$this->assertTrue( Ahentic_Abilities::requires_hitl( 'ahentic/install-plugin' ) );
		$this->assertTrue( Ahentic_Abilities::requires_hitl( 'ahentic-browser/save-post' ) );

		$this->assertFalse( Ahentic_Abilities::requires_hitl( 'ahentic/get-site-snapshot' ) );
		$this->assertFalse( Ahentic_Abilities::requires_hitl( 'ahentic-browser/get-current-page' ) );
		$this->assertFalse( Ahentic_Abilities::requires_hitl( 'ahentic/does-not-exist' ) );
	}

	/**
	 * Browser pause gate used before execute (HITL-before-browser ordering depends on this).
	 */
	public function test_facade_requires_browser_runtime() {
		$this->assertTrue( Ahentic_Abilities::requires_browser_runtime( 'ahentic-browser/get-current-page' ) );
		$this->assertTrue( Ahentic_Abilities::requires_browser_runtime( 'ahentic-browser/save-post' ) );
		$this->assertFalse( Ahentic_Abilities::requires_browser_runtime( 'ahentic/create-post' ) );
		$this->assertFalse( Ahentic_Abilities::requires_browser_runtime( 'ahentic/list-plugins' ) );
	}
}
