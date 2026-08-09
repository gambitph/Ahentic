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

	public function test_normalize_search_queries_single_and_multi() {
		$this->assertSame(
			array( 'private cars' ),
			Ahentic_Abilities_Content::normalize_search_queries(
				array( 'query' => 'private cars' )
			)
		);
		$this->assertSame(
			array( 'private cars', 'commute costs', 'parking' ),
			Ahentic_Abilities_Content::normalize_search_queries(
				array(
					'queries' => array( 'private cars', 'commute costs', '', 'parking', 'PRIVATE CARS' ),
				)
			)
		);
		$this->assertSame(
			array( 'keep', 'also' ),
			Ahentic_Abilities_Content::normalize_search_queries(
				array(
					'query'   => 'keep',
					'queries' => array( 'also', 'keep' ),
				)
			)
		);
		$this->assertSame(
			array(),
			Ahentic_Abilities_Content::normalize_search_queries( array() )
		);
	}

	public function test_normalize_search_queries_caps_at_max() {
		$many = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$many[] = 'q' . $i;
		}
		$out = Ahentic_Abilities_Content::normalize_search_queries( array( 'queries' => $many ) );
		$this->assertCount( Ahentic_Abilities_Content::MAX_SEARCH_QUERIES, $out );
		$this->assertSame( 'q1', $out[0] );
	}

	public function test_merge_search_content_batches_dedupes_by_id() {
		$merged = Ahentic_Abilities_Content::merge_search_content_batches(
			array(
				array(
					'query'   => 'cars',
					'results' => array(
						array( 'id' => 10, 'title' => 'Cars A' ),
						array( 'id' => 11, 'title' => 'Cars B' ),
					),
				),
				array(
					'query'   => 'parking',
					'results' => array(
						array( 'id' => 11, 'title' => 'Cars B again' ),
						array( 'id' => 12, 'title' => 'Parking' ),
					),
				),
			)
		);
		$this->assertCount( 3, $merged );
		$this->assertSame( 10, $merged[0]['id'] );
		$this->assertSame( array( 'cars' ), $merged[0]['matched_queries'] );
		$this->assertSame( 11, $merged[1]['id'] );
		$this->assertSame( array( 'cars', 'parking' ), $merged[1]['matched_queries'] );
		$this->assertSame( 'Cars B', $merged[1]['title'], 'First-seen item wins' );
		$this->assertSame( 12, $merged[2]['id'] );
	}
}
