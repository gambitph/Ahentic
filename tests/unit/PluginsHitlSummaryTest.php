<?php
/**
 * Plugin HITL summaries must name the target — never “unknown”.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Locks plugin install/activate HITL copy for missing refs.
 */
class PluginsHitlSummaryTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-plugins.php';
	}

	/**
	 * Missing slug/plugin falls back to “unspecified plugin”, not “unknown”.
	 */
	public function test_hitl_summary_never_says_unknown() {
		$install = Ahentic_Abilities_Plugins::hitl_summary( 'ahentic/install-plugin', array() );
		$this->assertStringNotContainsString( 'unknown', strtolower( $install ) );
		$this->assertStringContainsString( 'unspecified plugin', $install );

		$activate = Ahentic_Abilities_Plugins::hitl_summary( 'ahentic/activate-plugin', array() );
		$this->assertStringNotContainsString( 'unknown', strtolower( $activate ) );
		$this->assertStringContainsString( 'unspecified plugin', $activate );

		$named = Ahentic_Abilities_Plugins::hitl_summary(
			'ahentic/install-plugin',
			array( 'slug' => 'wordpress-seo' )
		);
		$this->assertStringContainsString( 'wordpress-seo', $named );
		$this->assertStringNotContainsString( 'unspecified', $named );
	}

	/**
	 * Missing plugin identity fails HITL preflight.
	 */
	public function test_hitl_preflight_requires_plugin_ref() {
		$err = Ahentic_Abilities_Plugins::hitl_preflight( 'ahentic/install-plugin', array() );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'ahentic_missing_slug', $err->get_error_code() );

		$ok = Ahentic_Abilities_Plugins::hitl_preflight(
			'ahentic/install-plugin',
			array( 'slug' => 'wordpress-seo' )
		);
		$this->assertTrue( $ok );
	}
}
