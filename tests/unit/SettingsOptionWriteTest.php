<?php
/**
 * Pure helpers for update-option (Task 11).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers denylist / allowlist / registered writability resolution.
 */
class SettingsOptionWriteTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-settings.php';
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-site.php';
	}

	/**
	 * Hard denylist is fixed and checked first.
	 */
	public function test_denylist_keys() {
		$denied = Ahentic_Abilities_Settings::option_write_denylist();
		foreach (
			array(
				'siteurl',
				'home',
				'default_role',
				'users_can_register',
				'admin_email',
			) as $key
		) {
			$this->assertContains( $key, $denied );
			$result = Ahentic_Abilities_Settings::resolve_option_writability( $key, array( $key, 'blogname' ) );
			$this->assertFalse( $result['ok'], $key );
			$this->assertSame( 'ahentic_option_denied', $result['error'], $key );
		}
	}

	/**
	 * Write allowlist is curated and distinct from the site read allowlist.
	 */
	public function test_write_allowlist_is_distinct_from_read_allowlist() {
		$write = Ahentic_Abilities_Settings::option_write_allowlist();
		$read  = Ahentic_Abilities_Site::option_allowlist();

		$this->assertNotSame( $read, $write );
		$this->assertContains( 'blogname', $write );
		$this->assertContains( 'blogdescription', $write );
		$this->assertContains( 'blog_public', $write );
		$this->assertContains( 'permalink_structure', $write );

		foreach ( Ahentic_Abilities_Settings::option_write_denylist() as $denied ) {
			$this->assertNotContains( $denied, $write, 'denylist leaked into write allowlist: ' . $denied );
		}

		// Read list includes denylist keys; write must not.
		$this->assertContains( 'siteurl', $read );
		$this->assertNotContains( 'siteurl', $write );
	}

	/**
	 * Registered (non-denylist) keys are writable even when not on the curated list.
	 */
	public function test_registered_key_is_writable() {
		$result = Ahentic_Abilities_Settings::resolve_option_writability(
			'my_plugin_setting',
			array( 'my_plugin_setting', 'other' )
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'registered', $result['via'] );
	}

	/**
	 * Allowlisted core keys are writable without registration.
	 */
	public function test_allowlisted_key_is_writable_without_registration() {
		$result = Ahentic_Abilities_Settings::resolve_option_writability( 'blogname', array() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'allowlist', $result['via'] );
	}

	/**
	 * Unregistered + unallowlisted keys are refused with a clear error.
	 */
	public function test_unregistered_key_is_refused() {
		$result = Ahentic_Abilities_Settings::resolve_option_writability(
			'ahentic_invented_raw_option',
			array( 'blogname' )
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ahentic_option_not_registered', $result['error'] );
		$this->assertStringContainsString( 'not registered', strtolower( $result['message'] ) );
	}

	/**
	 * Empty key is refused.
	 */
	public function test_empty_key_is_refused() {
		$result = Ahentic_Abilities_Settings::resolve_option_writability( '  ', array( 'blogname' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ahentic_missing_option_key', $result['error'] );
	}

	/**
	 * HITL summary names the option key.
	 */
	public function test_hitl_summary_names_key() {
		$summary = Ahentic_Abilities_Settings::hitl_summary(
			'ahentic/update-option',
			array(
				'key'   => 'blogname',
				'value' => 'X',
			)
		);
		$this->assertStringContainsString( 'blogname', $summary );
		$this->assertStringNotContainsString( 'dry run', strtolower( $summary ) );

		$dry = Ahentic_Abilities_Settings::hitl_summary(
			'ahentic/update-option',
			array(
				'key'     => 'blogname',
				'value'   => 'X',
				'dry_run' => true,
			)
		);
		$this->assertStringContainsString( 'dry run', strtolower( $dry ) );
	}
}
