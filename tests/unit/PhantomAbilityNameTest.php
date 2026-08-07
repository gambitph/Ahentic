<?php
/**
 * Ability tokens in prompts/playbooks must name registered abilities (ship or silence).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Scans agent-facing copy for ahentic/… tokens not in the module catalog.
 */
class PhantomAbilityNameTest extends TestCase {

	/**
	 * Intentional tokens that are not catalog abilities.
	 *
	 * - new-ability / inspect-site: normalize fallback + legacy progress label
	 * - update-site-title / delete-posts / upsert-code-snippet: deliberate
	 *   missing_ability examples in prompts/playbooks (teach ability_needed shape)
	 *
	 * @var string[]
	 */
	const ALLOWLIST = array(
		'ahentic/new-ability',
		'ahentic/inspect-site',
		'ahentic/update-site-title',
		'ahentic/delete-posts',
		'ahentic/upsert-code-snippet',
	);

	/**
	 * Paths relative to repo root that may advertise tools to the model.
	 *
	 * @var string[]
	 */
	const SCAN_GLOBS = array(
		'src/orchestrator/class-prompt-assembler.php',
		'src/orchestrator/control-block.md',
		'src/data/playbooks/*.json',
	);

	/**
	 * @var string[]
	 */
	private static $known = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once __DIR__ . '/ability-modules-bootstrap.php';
		ahentic_phpunit_require_ability_modules( true );
		Ahentic_Abilities::reset_modules_for_tests();

		foreach (
			array_merge(
				ahentic_phpunit_core_ability_module_classes(),
				array( 'Ahentic_Session_Artifacts', 'Ahentic_Playbooks' )
			) as $class
		) {
			Ahentic_Abilities::register_module( $class );
		}

		self::$known = Ahentic_Abilities::available_for_agent();
		self::assertNotEmpty( self::$known, 'expected registered ability names' );
	}

	/**
	 * Every ahentic/… token in scanned agent-facing files is registered or allowlisted.
	 */
	public function test_prompt_and_playbook_ability_tokens_are_registered() {
		$root    = dirname( __DIR__, 2 );
		$known   = array_fill_keys( self::$known, true );
		$allowed = array_fill_keys( self::ALLOWLIST, true );
		$phantoms = array();

		foreach ( self::SCAN_GLOBS as $pattern ) {
			foreach ( glob( $root . '/' . $pattern ) as $path ) {
				$content = file_get_contents( $path );
				if ( false === $content ) {
					$this->fail( 'unreadable: ' . $path );
				}
				if ( ! preg_match_all( '/ahentic(?:-browser)?\/[a-z0-9-]+/', $content, $matches ) ) {
					continue;
				}
				$rel = substr( $path, strlen( $root ) + 1 );
				foreach ( array_unique( $matches[0] ) as $token ) {
					if ( isset( $known[ $token ] ) || isset( $allowed[ $token ] ) ) {
						continue;
					}
					$phantoms[] = $rel . ': ' . $token;
				}
			}
		}

		$this->assertSame( array(), $phantoms, "Phantom ability names:\n" . implode( "\n", $phantoms ) );
	}
}
