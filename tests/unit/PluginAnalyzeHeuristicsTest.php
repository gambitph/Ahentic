<?php
/**
 * Pure heuristics for ahentic/analyze-plugins flagging.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Abilities_Plugins::flag_plugins_for_analysis().
 */
class PluginAnalyzeHeuristicsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-plugins.php';
	}

	/**
	 * Inactive plugins get the inactive flag; active ones do not.
	 */
	public function test_flags_inactive_plugins() {
		$plugins = array(
			array( 'slug' => 'hello-dolly', 'file' => 'hello.php', 'active' => false, 'name' => 'Hello' ),
			array( 'slug' => 'akismet', 'file' => 'akismet/akismet.php', 'active' => true, 'name' => 'Akismet' ),
		);

		$result = Ahentic_Abilities_Plugins::flag_plugins_for_analysis( $plugins, array() );

		$this->assertContains( 'inactive', $result['plugins'][0]['flags'] );
		$this->assertNotContains( 'inactive', $result['plugins'][1]['flags'] );
		$this->assertSame( 1, $result['summary']['inactive'] );
	}

	/**
	 * Two active SEO-category plugins flag each other as overlaps.
	 */
	public function test_flags_category_overlap_among_active_plugins() {
		$plugins = array(
			array( 'slug' => 'wordpress-seo', 'file' => 'wordpress-seo/wp-seo.php', 'active' => true, 'name' => 'Yoast' ),
			array( 'slug' => 'seo-by-rank-math', 'file' => 'seo-by-rank-math/rank-math.php', 'active' => true, 'name' => 'Rank Math' ),
			array( 'slug' => 'akismet', 'file' => 'akismet/akismet.php', 'active' => true, 'name' => 'Akismet' ),
		);

		$result = Ahentic_Abilities_Plugins::flag_plugins_for_analysis( $plugins, array() );

		$this->assertContains( 'overlaps_with:seo-by-rank-math', $result['plugins'][0]['flags'] );
		$this->assertContains( 'overlaps_with:wordpress-seo', $result['plugins'][1]['flags'] );
		$this->assertSame( array(), array_filter( $result['plugins'][2]['flags'], static function ( $f ) {
			return 0 === strpos( $f, 'overlaps_with:' );
		} ) );
		$this->assertSame( 2, $result['summary']['overlap'] );
	}

	/**
	 * Available update signal comes only from the update_plugins response map.
	 */
	public function test_flags_available_update_from_transient_map() {
		$plugins = array(
			array( 'slug' => 'akismet', 'file' => 'akismet/akismet.php', 'active' => true, 'name' => 'Akismet' ),
		);
		$updates = array(
			'akismet/akismet.php' => (object) array( 'new_version' => '5.0' ),
		);

		$result = Ahentic_Abilities_Plugins::flag_plugins_for_analysis( $plugins, $updates );

		$this->assertContains( 'has_available_update', $result['plugins'][0]['flags'] );
		$this->assertSame( 1, $result['summary']['has_available_update'] );
	}
}
