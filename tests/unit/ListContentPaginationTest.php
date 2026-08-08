<?php
/**
 * list-content page size defaults and caps.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Keeps browse defaults small so agents paginate instead of dumping archives.
 */
class ListContentPaginationTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-content.php';
	}

	public function test_default_per_page_is_fifteen() {
		$paging = Ahentic_Abilities_Content::normalize_list_pagination( array() );
		$this->assertSame( 15, $paging['per_page'] );
		$this->assertSame( 1, $paging['page'] );
		$this->assertSame( 15, Ahentic_Abilities_Content::DEFAULT_PER_PAGE );
	}

	public function test_caps_per_page_at_twenty_five() {
		$paging = Ahentic_Abilities_Content::normalize_list_pagination(
			array(
				'per_page' => 100,
				'page'     => 2,
			)
		);
		$this->assertSame( 25, $paging['per_page'] );
		$this->assertSame( 2, $paging['page'] );
		$this->assertSame( 25, Ahentic_Abilities_Content::MAX_PER_PAGE );
	}

	public function test_rejects_non_positive_page_and_per_page() {
		$paging = Ahentic_Abilities_Content::normalize_list_pagination(
			array(
				'per_page' => 0,
				'page'     => -3,
			)
		);
		$this->assertSame( 1, $paging['per_page'] );
		$this->assertSame( 1, $paging['page'] );
	}
}
