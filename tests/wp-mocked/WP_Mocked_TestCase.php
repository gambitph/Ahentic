<?php
/**
 * Base test case for the WordPress-functions-mocked ("medium") PHPUnit tier.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Wraps Brain Monkey's setUp()/tearDown() so every test gets a fresh,
 * isolated set of WordPress function mocks/expectations — one test's
 * `Functions\when()` never leaks into the next.
 */
abstract class WP_Mocked_TestCase extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Brain\Monkey\setUp();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		Brain\Monkey\tearDown();
		parent::tearDown();
	}
}
