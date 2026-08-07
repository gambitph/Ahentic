<?php
/**
 * Shared ability-module bootstrap for pure PHPUnit tests.
 *
 * @package Ahentic
 */

/**
 * Require the facade and product ability modules (idempotent per process).
 *
 * @param bool $include_artifacts_and_playbooks When true, also load session artifacts + playbooks.
 */
function ahentic_phpunit_require_ability_modules( $include_artifacts_and_playbooks = false ) {
	static $loaded_core = false;
	static $loaded_extra = false;

	$root = dirname( __DIR__, 2 );
	require_once $root . '/src/abilities/class-abilities.php';

	if ( ! $loaded_core ) {
		foreach (
			array(
				'/src/abilities/class-abilities-snapshot.php',
				'/src/abilities/class-abilities-content.php',
				'/src/abilities/class-abilities-plugins.php',
				'/src/abilities/class-abilities-browser.php',
				'/src/abilities/class-abilities-taxonomy.php',
				'/src/abilities/class-abilities-site.php',
				'/src/abilities/class-abilities-settings.php',
				'/src/abilities/class-abilities-media.php',
				'/src/session/class-settings-snapshots.php',
			) as $rel
		) {
			require_once $root . $rel;
		}
		$loaded_core = true;
	}

	if ( $include_artifacts_and_playbooks && ! $loaded_extra ) {
		require_once $root . '/src/session/class-artifacts.php';
		require_once $root . '/src/playbooks/class-playbooks.php';
		$loaded_extra = true;
	}
}

/**
 * Core product module class names (settings snapshots included; not artifacts/playbooks).
 *
 * @return string[]
 */
function ahentic_phpunit_core_ability_module_classes() {
	return array(
		'Ahentic_Abilities_Snapshot',
		'Ahentic_Abilities_Content',
		'Ahentic_Abilities_Plugins',
		'Ahentic_Abilities_Browser',
		'Ahentic_Abilities_Taxonomy',
		'Ahentic_Abilities_Site',
		'Ahentic_Abilities_Settings',
		'Ahentic_Abilities_Media',
		'Ahentic_Settings_Snapshots',
	);
}
