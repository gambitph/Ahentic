<?php
/**
 * search-content input aliases (search → query, per_page → limit).
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Keeps model typos from burning a full think on ability_invalid_input.
 */
class SearchContentCoerceTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-content.php';
	}

	public function test_search_alias_becomes_query() {
		$out = Ahentic_Abilities_Content::coerce_search_input(
			array(
				'search'    => 'Metro Manila commute',
				'post_type' => 'post',
			)
		);
		$this->assertSame( 'Metro Manila commute', $out['query'] );
		$this->assertArrayNotHasKey( 'search', $out );
	}

	public function test_query_wins_over_search() {
		$out = Ahentic_Abilities_Content::coerce_search_input(
			array(
				'query'  => 'keep me',
				'search' => 'ignore me',
			)
		);
		$this->assertSame( 'keep me', $out['query'] );
		$this->assertArrayNotHasKey( 'search', $out );
	}

	public function test_per_page_alias_becomes_limit() {
		$out = Ahentic_Abilities_Content::coerce_search_input(
			array(
				'query'    => 'cars',
				'per_page' => 10,
			)
		);
		$this->assertSame( 10, $out['limit'] );
		$this->assertArrayNotHasKey( 'per_page', $out );
	}
}
