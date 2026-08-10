<?php
/**
 * Pure helpers for ahentic/search-site + shared replace scanner guards.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Site_Locator validation and matching (no WP DB).
 */
class SiteLocatorTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-ahentic-site-locator.php';
	}

	public function test_validate_rejects_short_literal() {
		$err = Ahentic_Site_Locator::validate_query( 'ab', 'literal' );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'ahentic_query_too_short', $err->get_error_code() );
	}

	public function test_validate_rejects_blocklisted_common_term() {
		$err = Ahentic_Site_Locator::validate_query( 'the', 'literal' );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'ahentic_query_blocked', $err->get_error_code() );
	}

	public function test_validate_rejects_broad_regex() {
		$err = Ahentic_Site_Locator::validate_query( '.*', 'regex' );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'ahentic_query_too_broad', $err->get_error_code() );
	}

	public function test_validate_rejects_invalid_regex() {
		$err = Ahentic_Site_Locator::validate_query( '(unclosed', 'regex' );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'ahentic_regex_invalid', $err->get_error_code() );
	}

	public function test_validate_accepts_phone_literal() {
		$this->assertTrue( Ahentic_Site_Locator::validate_query( '578-393-4937', 'literal' ) );
	}

	public function test_literal_match_is_case_insensitive() {
		$this->assertTrue( Ahentic_Site_Locator::haystack_matches( 'Call 578-393-4937 now', '578-393-4937', 'literal' ) );
		$this->assertTrue( Ahentic_Site_Locator::haystack_matches( 'HELLO WORLD', 'hello', 'literal' ) );
		$this->assertFalse( Ahentic_Site_Locator::haystack_matches( 'nope', '578-393-4937', 'literal' ) );
	}

	public function test_first_match_preserves_haystack_casing() {
		$this->assertSame(
			'Hello',
			Ahentic_Site_Locator::first_match( 'Say Hello there', 'hello', 'literal' )
		);
	}

	public function test_regex_match_and_first_group() {
		$this->assertTrue(
			Ahentic_Site_Locator::haystack_matches(
				'Call 578-393-4937 today',
				'578[-.\\s]?393[-.\\s]?4937',
				'regex'
			)
		);
		$this->assertSame(
			'578-393-4937',
			Ahentic_Site_Locator::first_match(
				'Call 578-393-4937 today',
				'578[-.\\s]?393[-.\\s]?4937',
				'regex'
			)
		);
	}

	public function test_apply_replace_literal_and_regex() {
		$this->assertSame(
			'https://a',
			Ahentic_Site_Locator::apply_replace( 'http://a', 'http://', 'https://', 'literal' )
		);
		$this->assertSame(
			'x0917-123-1234y',
			Ahentic_Site_Locator::apply_replace(
				'x578-393-4937y',
				'578[-.\\s]?393[-.\\s]?4937',
				'0917-123-1234',
				'regex'
			)
		);
	}

	public function test_count_matches_literal_case_sensitive_default() {
		$this->assertSame( 2, Ahentic_Site_Locator::count_matches( 'Aa Aa', 'Aa', 'literal', true ) );
		$this->assertSame( 0, Ahentic_Site_Locator::count_matches( 'Aa Aa', 'aa', 'literal', true ) );
		$this->assertSame( 2, Ahentic_Site_Locator::count_matches( 'Aa Aa', 'aa', 'literal', false ) );
		$this->assertSame( 1, Ahentic_Site_Locator::count_matches( 'only Once here', 'Once', 'literal', true ) );
	}

	public function test_option_denylist_and_surface() {
		$this->assertTrue( Ahentic_Site_Locator::is_denied_option_key( '_transient_foo' ) );
		$this->assertTrue( Ahentic_Site_Locator::is_denied_option_key( 'auth_key' ) );
		$this->assertTrue( Ahentic_Site_Locator::is_denied_option_key( 'cron' ) );
		$this->assertFalse( Ahentic_Site_Locator::is_denied_option_key( 'theme_mods_twentytwentyfour' ) );
		$this->assertFalse( Ahentic_Site_Locator::is_denied_option_key( 'widget_text' ) );

		$this->assertSame( 'widget', Ahentic_Site_Locator::option_surface( 'widget_text' ) );
		$this->assertSame( 'theme_mod', Ahentic_Site_Locator::option_surface( 'theme_mods_foo' ) );
		$this->assertSame( 'option', Ahentic_Site_Locator::option_surface( 'blogdescription' ) );
	}

	public function test_walk_matches_nested_array_paths() {
		$value = array(
			'footer' => array(
				'phone' => 'Call 578-393-4937',
			),
			'other'  => 'nope',
		);
		$hits = Ahentic_Site_Locator::walk_matches( $value, '578-393-4937', 'literal', '', 5 );
		$this->assertCount( 1, $hits );
		$this->assertSame( 'footer.phone', $hits[0]['path'] );
		$this->assertSame( '578-393-4937', $hits[0]['match'] );
		$this->assertStringContainsString( '578-393-4937', $hits[0]['snippet'] );
	}

	public function test_sensitive_meta_key_detection() {
		$this->assertTrue( Ahentic_Site_Locator::is_sensitive_meta_key( '_api_key' ) );
		$this->assertFalse( Ahentic_Site_Locator::is_sensitive_meta_key( '_regular_price' ) );
	}
}
