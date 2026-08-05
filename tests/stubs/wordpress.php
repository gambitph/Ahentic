<?php
/**
 * Minimal WordPress stubs for the pure unit suite.
 *
 * Deliberately tiny. If a test needs more than this, the code under test is not a
 * pure unit and should be exercised through the e2e suite instead.
 *
 * @package Ahentic
 */

if ( ! function_exists( '__' ) ) {
	/**
	 * Pass-through translation stub.
	 *
	 * @param string $text   Text to return.
	 * @param string $domain Unused text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );
		return $text;
	}
}
