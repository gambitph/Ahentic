<?php
/**
 * Pure helpers for settings snapshot entries and undo list ops (ADR-0007).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Settings_Snapshots normalize / append / take — no WordPress.
 */
class SettingsSnapshotsTest extends TestCase {

	/**
	 * Load snapshot helper class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/session/class-settings-snapshots.php';
	}

	/**
	 * Absent prior value is distinct from an empty/null prior value.
	 */
	public function test_normalize_distinguishes_absent_from_empty_value() {
		$absent = Ahentic_Settings_Snapshots::normalize_entry(
			array(
				'ability'       => 'ahentic/update-template-part',
				'target'        => 'header',
				'prior_existed' => false,
			)
		);
		$empty  = Ahentic_Settings_Snapshots::normalize_entry(
			array(
				'ability'       => 'ahentic/update-option',
				'target'        => 'blogdescription',
				'prior_value'   => '',
				'prior_existed' => true,
			)
		);

		$this->assertFalse( $absent['prior_existed'] );
		$this->assertArrayNotHasKey( 'prior_value', $absent );

		$this->assertTrue( $empty['prior_existed'] );
		$this->assertSame( '', $empty['prior_value'] );
	}

	/**
	 * Each entry gets an id + timestamp; ability and target are required.
	 */
	public function test_normalize_requires_ability_and_target_and_assigns_id() {
		$entry = Ahentic_Settings_Snapshots::normalize_entry(
			array(
				'ability'       => 'ahentic/update-theme-setting',
				'target'        => 'blogname',
				'prior_value'   => 'Old',
				'prior_existed' => true,
			)
		);

		$this->assertNotSame( '', $entry['id'] );
		$this->assertSame( 'ahentic/update-theme-setting', $entry['ability'] );
		$this->assertSame( 'blogname', $entry['target'] );
		$this->assertIsInt( $entry['timestamp'] );
		$this->assertGreaterThan( 0, $entry['timestamp'] );
	}

	/**
	 * Invalid entries are rejected (null).
	 */
	public function test_normalize_rejects_incomplete_entries() {
		$this->assertNull( Ahentic_Settings_Snapshots::normalize_entry( array() ) );
		$this->assertNull(
			Ahentic_Settings_Snapshots::normalize_entry(
				array(
					'ability' => 'ahentic/update-option',
				)
			)
		);
	}

	/**
	 * Append is capped; oldest drop when over max.
	 */
	public function test_append_caps_list_dropping_oldest() {
		$list = array();
		for ( $i = 0; $i < 52; $i++ ) {
			$entry = Ahentic_Settings_Snapshots::normalize_entry(
				array(
					'ability'       => 'ahentic/update-option',
					'target'        => 'k' . $i,
					'prior_value'   => $i,
					'prior_existed' => true,
					'id'            => 'id-' . $i,
					'timestamp'     => 1000 + $i,
				)
			);
			$list = Ahentic_Settings_Snapshots::append_capped( $list, $entry, 50 );
		}

		$this->assertCount( 50, $list );
		$this->assertSame( 'id-2', $list[0]['id'] );
		$this->assertSame( 'id-51', $list[49]['id'] );
	}

	/**
	 * Undo takes from the end (most recent); remainder stays.
	 */
	public function test_take_for_undo_pops_most_recent() {
		$list = array();
		foreach ( array( 'a', 'b', 'c' ) as $i => $letter ) {
			$list[] = Ahentic_Settings_Snapshots::normalize_entry(
				array(
					'ability'       => 'ahentic/update-option',
					'target'        => $letter,
					'prior_value'   => $letter,
					'prior_existed' => true,
					'id'            => 'id-' . $letter,
					'timestamp'     => 10 + $i,
				)
			);
		}

		$result = Ahentic_Settings_Snapshots::take_for_undo( $list, 2 );
		$this->assertCount( 2, $result['taken'] );
		$this->assertSame( 'id-c', $result['taken'][0]['id'] );
		$this->assertSame( 'id-b', $result['taken'][1]['id'] );
		$this->assertCount( 1, $result['remaining'] );
		$this->assertSame( 'id-a', $result['remaining'][0]['id'] );
	}

	/**
	 * Empty undo is a no-op (idempotent).
	 */
	public function test_take_for_undo_empty_list_is_idempotent() {
		$result = Ahentic_Settings_Snapshots::take_for_undo( array(), 3 );
		$this->assertSame( array(), $result['taken'] );
		$this->assertSame( array(), $result['remaining'] );
	}

	/**
	 * Explicit snapshot ids are consumed in the order requested (newest-first among matches).
	 */
	public function test_take_for_undo_by_ids() {
		$list = array();
		foreach ( array( 'a', 'b', 'c' ) as $i => $letter ) {
			$list[] = Ahentic_Settings_Snapshots::normalize_entry(
				array(
					'ability'       => 'ahentic/update-option',
					'target'        => $letter,
					'prior_value'   => $letter,
					'prior_existed' => true,
					'id'            => 'id-' . $letter,
					'timestamp'     => 10 + $i,
				)
			);
		}

		$result = Ahentic_Settings_Snapshots::take_for_undo( $list, 0, array( 'id-a', 'id-c' ) );
		$this->assertCount( 2, $result['taken'] );
		$ids = array_column( $result['taken'], 'id' );
		$this->assertContains( 'id-a', $ids );
		$this->assertContains( 'id-c', $ids );
		$this->assertCount( 1, $result['remaining'] );
		$this->assertSame( 'id-b', $result['remaining'][0]['id'] );
	}

	/**
	 * Restore dispatcher calls the registered callback (stub write round-trip seam).
	 */
	public function test_restore_entry_uses_registered_callback() {
		Ahentic_Settings_Snapshots::reset_restore_callbacks_for_tests();

		$restored = null;
		Ahentic_Settings_Snapshots::register_restore(
			'ahentic-test/stub-write',
			static function ( array $entry ) use ( &$restored ) {
				$restored = $entry;
				return true;
			}
		);

		$entry = Ahentic_Settings_Snapshots::normalize_entry(
			array(
				'ability'       => 'ahentic-test/stub-write',
				'target'        => 'opt',
				'prior_value'   => 'old',
				'prior_existed' => true,
				'id'            => 'id-1',
				'timestamp'     => 1,
			)
		);

		$result = Ahentic_Settings_Snapshots::restore_entry( $entry );
		$this->assertTrue( $result );
		$this->assertSame( 'old', $restored['prior_value'] );

		$missing = Ahentic_Settings_Snapshots::restore_entry(
			array(
				'ability'       => 'ahentic-test/unknown',
				'target'        => 'x',
				'prior_existed' => false,
				'id'            => 'id-2',
				'timestamp'     => 2,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'ahentic_undo_no_restore', $missing->get_error_code() );

		Ahentic_Settings_Snapshots::reset_restore_callbacks_for_tests();
	}
}
