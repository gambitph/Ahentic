<?php
/**
 * Shared content-placeholder heuristic (PHP side of src/data/content-placeholder-rules.json).
 *
 * @package Ahentic
 */

/**
 * Detect LLM content stubs vs real prose — single rules file shared with JS.
 */
class Ahentic_Content_Placeholder {

	/**
	 * Cached decoded rules.
	 *
	 * @var array|null
	 */
	private static $rules = null;

	/**
	 * Absolute path to the shared rules JSON.
	 *
	 * @return string
	 */
	public static function rules_path() {
		if ( defined( 'AHENTIC_FILE' ) ) {
			return plugin_dir_path( AHENTIC_FILE ) . 'src/data/content-placeholder-rules.json';
		}
		return dirname( __DIR__ ) . '/data/content-placeholder-rules.json';
	}

	/**
	 * Load and cache rules (+ samples) from JSON.
	 *
	 * @return array
	 */
	public static function rules() {
		if ( null !== self::$rules ) {
			return self::$rules;
		}

		$path = self::rules_path();
		if ( ! is_readable( $path ) ) {
			self::$rules = array(
				'patterns' => array(),
				'samples'  => array(
					'placeholder' => array(),
					'real'        => array(),
				),
			);
			return self::$rules;
		}

		$raw         = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin data file.
		$data        = json_decode( (string) $raw, true );
		self::$rules = is_array( $data ) ? $data : array();
		return self::$rules;
	}

	/**
	 * Reset cached rules (tests).
	 */
	public static function reset_rules_for_tests() {
		self::$rules = null;
	}

	/**
	 * Whether text looks like an LLM content stub rather than real prose.
	 *
	 * @param string $text Raw or HTML content.
	 * @return bool
	 */
	public static function looks_like( $text ) {
		$plain = self::to_plain( $text );
		if ( '' === $plain ) {
			return false;
		}

		$rules    = self::rules();
		$patterns = isset( $rules['patterns'] ) && is_array( $rules['patterns'] ) ? $rules['patterns'] : array();

		foreach ( $patterns as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['pattern'] ) ) {
				continue;
			}
			$max = isset( $entry['maxLength'] ) ? (int) $entry['maxLength'] : 0;
			if ( $max > 0 && self::strlen( $plain ) > $max ) {
				continue;
			}
			$flags   = isset( $entry['flags'] ) ? (string) $entry['flags'] : '';
			$wrapped = '/' . str_replace( '/', '\\/', (string) $entry['pattern'] ) . '/' . $flags;
			$result  = preg_match( $wrapped, $plain );
			if ( 1 === $result ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Strip tags and collapse whitespace (matches JS side).
	 *
	 * @param string $text Raw or HTML.
	 * @return string
	 */
	public static function to_plain( $text ) {
		$raw = (string) $text;
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$stripped = wp_strip_all_tags( $raw );
		} else {
			$stripped = preg_replace( '/<[^>]+>/', ' ', $raw );
		}
		return trim( preg_replace( '/\s+/u', ' ', (string) $stripped ) );
	}

	/**
	 * String length helper (mb when available).
	 *
	 * @param string $text Text.
	 * @return int Character count.
	 */
	private static function strlen( $text ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text );
		}
		return strlen( $text );
	}
}
