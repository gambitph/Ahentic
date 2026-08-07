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

		require_once __DIR__ . '/ability-modules-bootstrap.php';
		Ahentic_Abilities::reset_modules_for_tests();
		ahentic_phpunit_require_ability_modules();

		// Explicit re-register: another unit test may have loaded a module before the facade.
		foreach ( array_merge( ahentic_phpunit_core_ability_module_classes(), array( 'Ahentic_AbilityPolicy_Test_Module' ) ) as $module ) {
			Ahentic_Abilities::register_module( $module );
		}
	}

	/**
	 * Content writes pause for HITL; reads do not.
	 */
	public function test_content_hitl_and_readonly_flags() {
		$this->assertTrue( Ahentic_Abilities_Content::requires_hitl( 'ahentic/create-post' ) );
		$this->assertTrue( Ahentic_Abilities_Content::requires_hitl( 'ahentic/update-post' ) );
		$this->assertTrue( Ahentic_Abilities_Content::requires_hitl( 'ahentic/set-post-status' ) );
		$this->assertTrue( Ahentic_Abilities_Content::requires_hitl( 'ahentic/replace-in-content' ) );
		$this->assertTrue( Ahentic_Abilities_Content::requires_hitl( 'ahentic/restore-revision' ) );

		$this->assertFalse( Ahentic_Abilities_Content::requires_hitl( 'ahentic/list-content' ) );
		$this->assertFalse( Ahentic_Abilities_Content::requires_hitl( 'ahentic/get-content' ) );
		$this->assertFalse( Ahentic_Abilities_Content::requires_hitl( 'ahentic/search-content' ) );
		$this->assertFalse( Ahentic_Abilities_Content::requires_hitl( 'ahentic/list-post-types' ) );
		$this->assertFalse( Ahentic_Abilities_Content::requires_hitl( 'ahentic/list-revisions' ) );

		$this->assertTrue( Ahentic_Abilities_Content::is_readonly( 'ahentic/list-content' ) );
		$this->assertTrue( Ahentic_Abilities_Content::is_readonly( 'ahentic/list-post-types' ) );
		$this->assertTrue( Ahentic_Abilities_Content::is_readonly( 'ahentic/list-revisions' ) );
		$this->assertFalse( Ahentic_Abilities_Content::is_readonly( 'ahentic/create-post' ) );
		$this->assertFalse( Ahentic_Abilities_Content::is_readonly( 'ahentic/replace-in-content' ) );
		$this->assertFalse( Ahentic_Abilities_Content::is_readonly( 'ahentic/restore-revision' ) );
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
		$this->assertFalse( Ahentic_Abilities_Plugins::requires_hitl( 'ahentic/analyze-plugins' ) );
		$this->assertTrue( Ahentic_Abilities_Plugins::is_readonly( 'ahentic/analyze-plugins' ) );
	}

	/**
	 * Site list-themes and media vision/generation are readonly (no HITL).
	 */
	public function test_site_and_media_track_b_readonly_flags() {
		$this->assertContains( 'ahentic/list-themes', Ahentic_Abilities_Site::names() );
		$this->assertContains( 'ahentic/describe-image', Ahentic_Abilities_Media::names() );
		$this->assertContains( 'ahentic/generate-image', Ahentic_Abilities_Media::names() );
		$this->assertContains( 'ahentic/upload-media', Ahentic_Abilities_Media::names() );
		$this->assertTrue( Ahentic_Abilities_Media::is_readonly( 'ahentic/describe-image' ) );
		$this->assertTrue( Ahentic_Abilities_Media::is_readonly( 'ahentic/generate-image' ) );
		$this->assertFalse( Ahentic_Abilities_Media::is_readonly( 'ahentic/upload-media' ) );
		$this->assertFalse( Ahentic_Abilities_Media::requires_hitl( 'ahentic/describe-image' ) );
		$this->assertFalse( Ahentic_Abilities_Media::requires_hitl( 'ahentic/generate-image' ) );
		$this->assertTrue( Ahentic_Abilities_Media::requires_hitl( 'ahentic/upload-media' ) );
	}

	/**
	 * Track E media writes: standard HITL; replace-media-file is non-preallowable.
	 */
	public function test_media_track_e_write_policy() {
		$writes = array(
			'ahentic/update-media',
			'ahentic/set-featured-image',
			'ahentic/delete-media',
			'ahentic/replace-media-file',
		);

		foreach ( $writes as $name ) {
			$this->assertContains( $name, Ahentic_Abilities_Media::names() );
			$this->assertContains( $name, Ahentic_Abilities_Media::write_names() );
			$this->assertFalse( Ahentic_Abilities_Media::is_readonly( $name ) );
			$this->assertTrue( Ahentic_Abilities_Media::requires_hitl( $name ) );
			$this->assertTrue( Ahentic_Abilities::requires_hitl( $name ) );
			$this->assertContains( $name, Ahentic_Abilities::available_for_agent() );
			$this->assertNotContains( $name, Ahentic_Abilities::available_for_mode( 'ask' ) );
		}

		$this->assertFalse( Ahentic_Abilities_Media::is_non_preallowable( 'ahentic/update-media' ) );
		$this->assertFalse( Ahentic_Abilities_Media::is_non_preallowable( 'ahentic/set-featured-image' ) );
		$this->assertFalse( Ahentic_Abilities_Media::is_non_preallowable( 'ahentic/delete-media' ) );
		$this->assertTrue( Ahentic_Abilities_Media::is_non_preallowable( 'ahentic/replace-media-file' ) );
		$this->assertTrue( Ahentic_Abilities::is_non_preallowable( 'ahentic/replace-media-file' ) );
		$this->assertFalse( Ahentic_Abilities::hitl_choice_allowed( 'ahentic/replace-media-file', 'allow_session' ) );
		$this->assertFalse( Ahentic_Abilities::hitl_choice_allowed( 'ahentic/replace-media-file', 'always_allow' ) );
		$this->assertTrue( Ahentic_Abilities::hitl_choice_allowed( 'ahentic/replace-media-file', 'allow_once' ) );
		$this->assertTrue( Ahentic_Abilities::hitl_choice_allowed( 'ahentic/update-media', 'allow_session' ) );
	}

	/**
	 * replace-media-file HITL card must name irreversibility and site-wide blast radius.
	 */
	public function test_replace_media_file_hitl_summary_warns_no_undo_site_wide() {
		$summary = Ahentic_Abilities_Media::hitl_summary(
			'ahentic/replace-media-file',
			array( 'attachment_id' => 42 )
		);
		$this->assertMatchesRegularExpression( '/no undo|cannot be undone|irreversible/i', $summary );
		$this->assertMatchesRegularExpression( '/everywhere|site[- ]wide|all references/i', $summary );
		$this->assertStringContainsString( '42', $summary );
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
	 * set-featured-image is a browser write like update-post-document (no HITL; editor undo / not-saving).
	 */
	public function test_browser_set_featured_image_policy() {
		$name = 'ahentic-browser/set-featured-image';

		$this->assertContains( $name, Ahentic_Abilities_Browser::names() );
		$this->assertContains( $name, Ahentic_Abilities_Browser::write_names() );
		$this->assertTrue( Ahentic_Abilities_Browser::is_browser( $name ) );
		$this->assertFalse( Ahentic_Abilities_Browser::is_readonly( $name ) );
		$this->assertFalse( Ahentic_Abilities_Browser::requires_hitl( $name ) );
		$this->assertTrue( Ahentic_Abilities::requires_browser_runtime( $name ) );
		$this->assertFalse( Ahentic_Abilities::requires_hitl( $name ) );
		$this->assertSame(
			'Updating the featured image…',
			Ahentic_Abilities_Browser::progress_label( $name )
		);
	}

	/**
	 * delete-blocks / update-post-document are browser writes without HITL.
	 */
	public function test_browser_delete_blocks_and_update_post_document_policy() {
		foreach ( array( 'ahentic-browser/delete-blocks', 'ahentic-browser/update-post-document' ) as $name ) {
			$this->assertContains( $name, Ahentic_Abilities_Browser::names() );
			$this->assertContains( $name, Ahentic_Abilities_Browser::write_names() );
			$this->assertTrue( Ahentic_Abilities_Browser::is_browser( $name ) );
			$this->assertFalse( Ahentic_Abilities_Browser::is_readonly( $name ) );
			$this->assertFalse( Ahentic_Abilities_Browser::requires_hitl( $name ) );
			$this->assertTrue( Ahentic_Abilities::requires_browser_runtime( $name ) );
			$this->assertFalse( Ahentic_Abilities::requires_hitl( $name ) );
		}
		$this->assertSame(
			'Deleting blocks…',
			Ahentic_Abilities_Browser::progress_label( 'ahentic-browser/delete-blocks' )
		);
		$this->assertSame(
			'Updating the editor document…',
			Ahentic_Abilities_Browser::progress_label( 'ahentic-browser/update-post-document' )
		);
	}

	/**
	 * Facade must fan out to every domain module (ToolRunner will call this).
	 */
	public function test_facade_requires_hitl_covers_all_modules() {
		$this->assertTrue( Ahentic_Abilities::requires_hitl( 'ahentic/create-post' ) );
		$this->assertTrue( Ahentic_Abilities::requires_hitl( 'ahentic/install-plugin' ) );
		$this->assertTrue( Ahentic_Abilities::requires_hitl( 'ahentic-browser/save-post' ) );
		$this->assertTrue( Ahentic_Abilities::requires_hitl( 'ahentic/upload-media' ) );

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

	/**
	 * Snapshot module is in the catalog.
	 */
	public function test_snapshot_in_available_for_agent() {
		$this->assertContains( 'ahentic/get-site-snapshot', Ahentic_Abilities::available_for_agent() );
		$this->assertSame(
			'Reading site snapshot…',
			Ahentic_Abilities::progress_label( 'ahentic/get-site-snapshot' )
		);
	}

	/**
	 * Sidebar bootstrap map is derived from PHP progress_label (M5 — no JS hard-coded table).
	 */
	public function test_progress_labels_map_matches_catalog() {
		$map = Ahentic_Abilities::progress_labels_map();
		$this->assertArrayHasKey( 'ahentic/create-post', $map );
		$this->assertArrayHasKey( 'ahentic-browser/save-post', $map );
		$this->assertSame(
			Ahentic_Abilities::progress_label( 'ahentic/create-post' ),
			$map['ahentic/create-post']
		);
		$this->assertSame(
			Ahentic_Abilities::progress_label( 'ahentic-browser/save-post' ),
			$map['ahentic-browser/save-post']
		);
		$this->assertSame(
			Ahentic_Abilities_Browser::progress_label( 'ahentic-browser/convert-blocks' ),
			$map['ahentic-browser/convert-blocks']
		);
	}

	/**
	 * Non-preallowable abilities ignore session/always allowlists at the catalog seam.
	 */
	public function test_facade_is_non_preallowable_from_modules() {
		$this->assertTrue( Ahentic_Abilities::is_non_preallowable( 'ahentic-test/non-preallowable-write' ) );
		$this->assertFalse( Ahentic_Abilities::is_non_preallowable( 'ahentic/create-post' ) );
		$this->assertFalse( Ahentic_Abilities::is_non_preallowable( 'ahentic/does-not-exist' ) );
	}

	/**
	 * Session / always_allow choices are forbidden for non-preallowable abilities.
	 */
	public function test_hitl_choice_rejected_for_non_preallowable() {
		$this->assertTrue( Ahentic_Abilities::hitl_choice_allowed( 'ahentic/create-post', 'allow_once' ) );
		$this->assertTrue( Ahentic_Abilities::hitl_choice_allowed( 'ahentic/create-post', 'allow_session' ) );
		$this->assertTrue( Ahentic_Abilities::hitl_choice_allowed( 'ahentic/create-post', 'always_allow' ) );

		$this->assertTrue( Ahentic_Abilities::hitl_choice_allowed( 'ahentic-test/non-preallowable-write', 'allow_once' ) );
		$this->assertFalse( Ahentic_Abilities::hitl_choice_allowed( 'ahentic-test/non-preallowable-write', 'allow_session' ) );
		$this->assertFalse( Ahentic_Abilities::hitl_choice_allowed( 'ahentic-test/non-preallowable-write', 'always_allow' ) );
	}

	/**
	 * undo-last-actions is registered on the settings-snapshots module (write, no HITL).
	 */
	public function test_undo_last_actions_policy() {
		$name = 'ahentic/undo-last-actions';
		$this->assertContains( $name, Ahentic_Settings_Snapshots::names() );
		$this->assertFalse( Ahentic_Settings_Snapshots::is_readonly( $name ) );
		$this->assertFalse( Ahentic_Settings_Snapshots::requires_hitl( $name ) );
		$this->assertContains( $name, Ahentic_Abilities::available_for_agent() );
		$this->assertSame(
			'Undoing last settings changes…',
			Ahentic_Abilities::progress_label( $name )
		);
	}
}

/**
 * Temporary catalog module so non-preallowable can be proven without Track D abilities.
 */
class Ahentic_AbilityPolicy_Test_Module {
	const WRITE = 'ahentic-test/non-preallowable-write';

	/**
	 * @return string[]
	 */
	public static function names() {
		return array( self::WRITE );
	}

	/**
	 * @return string[]
	 */
	public static function non_preallowable_names() {
		return array( self::WRITE );
	}

	/**
	 * @param string $name Ability name.
	 * @return bool
	 */
	public static function is_non_preallowable( $name ) {
		return in_array( (string) $name, self::non_preallowable_names(), true );
	}

	/**
	 * @param string $name Ability name.
	 * @return bool
	 */
	public static function requires_hitl( $name ) {
		return self::WRITE === (string) $name;
	}
}
