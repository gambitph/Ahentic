<?php
/**
 * Shared site-wide string locator helpers (search-site + replace-in-content).
 *
 * Pure matching / validation lives here so PHPUnit can cover it without WP.
 * WordPress DB scanning stays on Ahentic_Abilities_Content.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Site_Locator' ) ) {
	/**
	 * Literal / regex query guards and match helpers for site-wide discovery.
	 */
	class Ahentic_Site_Locator {
		const MODE_LITERAL = 'literal';
		const MODE_REGEX   = 'regex';

		const MIN_QUERY_CHARS    = 3;
		const MAX_REGEX_CHARS    = 400;
		const MAX_GLOBAL_HITS    = 50;
		const MAX_HITS_PER_SURFACE = 20;
		const DEFAULT_SNIPPET    = 160;
		const DEFAULT_TIME_BUDGET_MS = 8000;

		/**
		 * High-frequency tokens that would match too widely.
		 *
		 * @return string[] Lowercase tokens.
		 */
		public static function query_blocklist() {
			return array(
				'the',
				'and',
				'for',
				'with',
				'from',
				'this',
				'that',
				'post',
				'page',
				'pages',
				'posts',
				'content',
				'block',
				'blocks',
				'http',
				'https',
				'www',
				'null',
				'true',
				'false',
				'div',
				'span',
				'class',
				'href',
				'src',
				'id',
				'name',
				'type',
				'text',
				'html',
				'wp',
				'wordpress',
			);
		}

		/**
		 * Regex patterns that are too broad / unsafe to run site-wide.
		 *
		 * @return string[]
		 */
		public static function regex_blocklist() {
			return array(
				'.*',
				'.+',
				'^',
				'$',
				'.',
				'\\s*',
				'\\S*',
				'[\\s\\S]*',
				'[\\d\\D]*',
			);
		}

		/**
		 * Normalize mode input.
		 *
		 * @param mixed $mode Raw mode.
		 * @return string literal|regex
		 */
		public static function normalize_mode( $mode ) {
			$mode = strtolower( trim( (string) $mode ) );
			return self::MODE_REGEX === $mode ? self::MODE_REGEX : self::MODE_LITERAL;
		}

		/**
		 * Validate a search/replace query before scanning.
		 *
		 * @param string $query Raw query / find / pattern.
		 * @param string $mode  literal|regex.
		 * @return true|\WP_Error
		 */
		public static function validate_query( $query, $mode = self::MODE_LITERAL ) {
			$query = (string) $query;
			$mode  = self::normalize_mode( $mode );
			$trim  = trim( $query );

			if ( '' === $trim ) {
				return new WP_Error(
					'ahentic_query_empty',
					__( 'A non-empty query is required.', 'ahentic' )
				);
			}

			if ( self::MODE_REGEX === $mode ) {
				if ( strlen( $trim ) > self::MAX_REGEX_CHARS ) {
					return new WP_Error(
						'ahentic_query_too_long',
						__( 'Regex pattern is too long. Shorten it and try again.', 'ahentic' )
					);
				}
				if ( in_array( $trim, self::regex_blocklist(), true ) ) {
					return new WP_Error(
						'ahentic_query_too_broad',
						__( 'That regex would match almost everything. Narrow the pattern.', 'ahentic' )
					);
				}
				if ( @preg_match( '/' . $trim . '/u', '' ) === false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- compile check
					return new WP_Error(
						'ahentic_regex_invalid',
						__( 'Invalid regex pattern.', 'ahentic' )
					);
				}
			}

			if ( self::strlen( $trim ) < self::MIN_QUERY_CHARS ) {
				return new WP_Error(
					'ahentic_query_too_short',
					__( 'Query is too short (minimum 3 characters). Use a more specific string.', 'ahentic' )
				);
			}

			$lower = strtolower( $trim );
			if ( in_array( $lower, self::query_blocklist(), true ) ) {
				return new WP_Error(
					'ahentic_query_blocked',
					__( 'That query is too common and would return too many hits. Use a more specific phrase.', 'ahentic' )
				);
			}

			return true;
		}

		/**
		 * Whether haystack matches query under the given mode.
		 *
		 * Literal match is case-insensitive for discovery.
		 *
		 * @param string $haystack Text.
		 * @param string $query    Needle / pattern.
		 * @param string $mode     literal|regex.
		 * @return bool
		 */
		public static function haystack_matches( $haystack, $query, $mode = self::MODE_LITERAL ) {
			$haystack = (string) $haystack;
			$query    = (string) $query;
			$mode     = self::normalize_mode( $mode );
			if ( '' === $haystack || '' === $query ) {
				return false;
			}

			if ( self::MODE_REGEX === $mode ) {
				$result = @preg_match( '/' . $query . '/u', $haystack ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return 1 === $result;
			}

			return false !== self::stripos( $haystack, $query );
		}

		/**
		 * First matched substring (exact casing from haystack when possible).
		 *
		 * @param string $haystack Text.
		 * @param string $query    Needle / pattern.
		 * @param string $mode     literal|regex.
		 * @return string Empty when no match.
		 */
		public static function first_match( $haystack, $query, $mode = self::MODE_LITERAL ) {
			$haystack = (string) $haystack;
			$query    = (string) $query;
			$mode     = self::normalize_mode( $mode );
			if ( '' === $haystack || '' === $query ) {
				return '';
			}

			if ( self::MODE_REGEX === $mode ) {
				$matches = array();
				$result  = @preg_match( '/' . $query . '/u', $haystack, $matches ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( 1 === $result && isset( $matches[0] ) && is_string( $matches[0] ) ) {
					return $matches[0];
				}
				return '';
			}

			$pos = self::stripos( $haystack, $query );
			if ( false === $pos ) {
				return '';
			}
			return self::substr( $haystack, (int) $pos, self::strlen( $query ) );
		}

		/**
		 * Count occurrences (literal case-sensitive for replace parity; regex = match count).
		 *
		 * @param string $haystack Text.
		 * @param string $query    Find / pattern.
		 * @param string $mode     literal|regex.
		 * @param bool   $literal_case_sensitive Only for literal mode (replace uses true).
		 * @return int
		 */
		public static function count_matches( $haystack, $query, $mode = self::MODE_LITERAL, $literal_case_sensitive = true ) {
			$haystack = (string) $haystack;
			$query    = (string) $query;
			$mode     = self::normalize_mode( $mode );
			if ( '' === $haystack || '' === $query ) {
				return 0;
			}

			if ( self::MODE_REGEX === $mode ) {
				$count = @preg_match_all( '/' . $query . '/u', $haystack, $m ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return is_int( $count ) ? $count : 0;
			}

			if ( $literal_case_sensitive ) {
				return substr_count( $haystack, $query );
			}

			$needle = strtolower( $query );
			$hay    = strtolower( $haystack );
			if ( '' === $needle ) {
				return 0;
			}
			return substr_count( $hay, $needle );
		}

		/**
		 * Apply find/replace (literal case-sensitive, or regex).
		 *
		 * @param string $haystack Text.
		 * @param string $find     Find / pattern.
		 * @param string $replace  Replacement.
		 * @param string $mode     literal|regex.
		 * @return string
		 */
		public static function apply_replace( $haystack, $find, $replace, $mode = self::MODE_LITERAL ) {
			$haystack = (string) $haystack;
			$find     = (string) $find;
			$replace  = (string) $replace;
			$mode     = self::normalize_mode( $mode );
			if ( '' === $find ) {
				return $haystack;
			}

			if ( self::MODE_REGEX === $mode ) {
				$out = @preg_replace( '/' . $find . '/u', $replace, $haystack ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return is_string( $out ) ? $out : $haystack;
			}

			return str_replace( $find, $replace, $haystack );
		}

		/**
		 * Whether an option key should be skipped during site search.
		 *
		 * @param string $key Option name.
		 * @return bool
		 */
		public static function is_denied_option_key( $key ) {
			$key = (string) $key;
			if ( '' === $key ) {
				return true;
			}

			$exact = array(
				'cron',
				'rewrite_rules',
				'active_plugins',
				'recently_activated',
				'uninstall_plugins',
				'auto_updater.lock',
				'auth_key',
				'auth_salt',
				'logged_in_key',
				'logged_in_salt',
				'nonce_key',
				'nonce_salt',
				'secure_auth_key',
				'secure_auth_salt',
				'secret_key',
				'db_version',
				'initial_db_version',
			);
			if ( in_array( $key, $exact, true ) ) {
				return true;
			}

			$prefixes = array(
				'_transient_',
				'_site_transient_',
				'_transient_timeout_',
				'_site_transient_timeout_',
			);
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					return true;
				}
			}

			$lower = strtolower( $key );
			foreach ( array( 'password', 'passwd', 'secret', 'api_key', 'apikey', 'private_key', 'auth_key', 'salt', 'session_token' ) as $needle ) {
				if ( false !== strpos( $lower, $needle ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether a postmeta key is sensitive (skip in search results).
		 *
		 * @param string $key Meta key.
		 * @return bool
		 */
		public static function is_sensitive_meta_key( $key ) {
			$key     = strtolower( (string) $key );
			$needles = array(
				'password',
				'passwd',
				'secret',
				'token',
				'api_key',
				'apikey',
				'auth',
				'private_key',
				'salt',
				'nonce',
				'session',
				'credit_card',
				'card_number',
			);
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $key, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Classify an option key into a hit surface label.
		 *
		 * @param string $key Option name.
		 * @return string widget|theme_mod|option
		 */
		public static function option_surface( $key ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, 'widget_' ) ) {
				return 'widget';
			}
			if ( 0 === strpos( $key, 'theme_mods_' ) ) {
				return 'theme_mod';
			}
			return 'option';
		}

		/**
		 * Walk a value tree and collect string-leaf matches.
		 *
		 * @param mixed  $value   Option / meta value (may be array).
		 * @param string $query   Query.
		 * @param string $mode    Mode.
		 * @param string $path    Dotted path prefix.
		 * @param int    $limit   Max matches to collect.
		 * @return array<int, array{path:string, match:string, snippet:string}>
		 */
		public static function walk_matches( $value, $query, $mode = self::MODE_LITERAL, $path = '', $limit = 5 ) {
			$out   = array();
			$mode  = self::normalize_mode( $mode );
			$limit = max( 1, (int) $limit );
			self::walk_matches_inner( $value, $query, $mode, $path, $limit, $out );
			return $out;
		}

		/**
		 * @param mixed  $value Value.
		 * @param string $query Query.
		 * @param string $mode  Mode.
		 * @param string $path  Path.
		 * @param int    $limit Limit.
		 * @param array  $out   Accumulator.
		 */
		private static function walk_matches_inner( $value, $query, $mode, $path, $limit, array &$out ) {
			if ( count( $out ) >= $limit ) {
				return;
			}

			if ( is_string( $value ) ) {
				// Unserialize string blobs when possible.
				$maybe = self::maybe_unserialize_string( $value );
				if ( is_array( $maybe ) || ( is_object( $maybe ) && ! ( $maybe instanceof __PHP_Incomplete_Class ) ) ) {
					self::walk_matches_inner( $maybe, $query, $mode, $path, $limit, $out );
					return;
				}
				if ( self::haystack_matches( $value, $query, $mode ) ) {
					$match = self::first_match( $value, $query, $mode );
					$out[] = array(
						'path'    => $path,
						'match'   => $match,
						'snippet' => self::make_snippet( $value, $match !== '' ? $match : $query, self::DEFAULT_SNIPPET ),
					);
				}
				return;
			}

			if ( is_object( $value ) ) {
				$value = (array) $value;
			}

			if ( ! is_array( $value ) ) {
				if ( is_int( $value ) || is_float( $value ) ) {
					$text = (string) $value;
					if ( self::haystack_matches( $text, $query, $mode ) ) {
						$match = self::first_match( $text, $query, $mode );
						$out[] = array(
							'path'    => $path,
							'match'   => $match,
							'snippet' => self::make_snippet( $text, $match !== '' ? $match : $query, self::DEFAULT_SNIPPET ),
						);
					}
				}
				return;
			}

			foreach ( $value as $key => $child ) {
				$segment = is_int( $key ) ? (string) $key : (string) $key;
				$child_path = '' === $path ? $segment : $path . '.' . $segment;
				self::walk_matches_inner( $child, $query, $mode, $child_path, $limit, $out );
				if ( count( $out ) >= $limit ) {
					return;
				}
			}
		}

		/**
		 * Build a short snippet around the first match (case-insensitive for literal query).
		 *
		 * @param string $text   Source.
		 * @param string $query  Needle for positioning (prefer exact match string).
		 * @param int    $length Max length.
		 * @return string
		 */
		public static function make_snippet( $text, $query, $length = self::DEFAULT_SNIPPET ) {
			$text   = preg_replace( '/\s+/u', ' ', (string) $text );
			$text   = trim( (string) $text );
			$query  = (string) $query;
			$length = max( 40, (int) $length );
			if ( '' === $text ) {
				return '';
			}

			$pos = '' !== $query ? self::stripos( $text, $query ) : false;
			if ( false === $pos ) {
				$slice = self::substr( $text, 0, $length );
				return $slice . ( self::strlen( $text ) > $length ? '…' : '' );
			}

			$start  = max( 0, (int) $pos - (int) floor( $length / 3 ) );
			$slice  = self::substr( $text, $start, $length );
			$prefix = $start > 0 ? '…' : '';
			$suffix = ( $start + $length ) < self::strlen( $text ) ? '…' : '';
			return $prefix . $slice . $suffix;
		}

		/**
		 * Suggested follow-up tools for a hit surface.
		 *
		 * @param string $surface Surface label.
		 * @return string[]
		 */
		public static function suggested_tools_for_surface( $surface ) {
			switch ( (string) $surface ) {
				case 'post':
				case 'template':
				case 'template_part':
					return array(
						'ahentic/get-content',
						'ahentic/update-post',
						'ahentic/replace-in-content',
					);
				case 'theme_mod':
					return array(
						'ahentic/get-settings-context',
						'ahentic/update-theme-setting',
					);
				case 'widget':
				case 'option':
					return array(
						'ahentic/get-settings-context',
						'ahentic/update-option',
					);
				default:
					return array( 'ahentic/get-content' );
			}
		}

		/**
		 * @param string $haystack Haystack.
		 * @param string $needle   Needle.
		 * @return int|false
		 */
		public static function stripos( $haystack, $needle ) {
			if ( function_exists( 'mb_stripos' ) ) {
				return mb_stripos( $haystack, $needle );
			}
			return stripos( $haystack, $needle );
		}

		/**
		 * @param string $text Text.
		 * @return int
		 */
		public static function strlen( $text ) {
			if ( function_exists( 'mb_strlen' ) ) {
				return mb_strlen( $text );
			}
			return strlen( $text );
		}

		/**
		 * @param string $text   Text.
		 * @param int    $start  Start.
		 * @param int    $length Length.
		 * @return string
		 */
		public static function substr( $text, $start, $length ) {
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $text, $start, $length );
			}
			return substr( $text, $start, $length );
		}

		/**
		 * @param string $value Maybe serialized.
		 * @return mixed
		 */
		private static function maybe_unserialize_string( $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return $value;
			}
			if ( function_exists( 'maybe_unserialize' ) ) {
				return maybe_unserialize( $value );
			}
			if ( function_exists( 'is_serialized' ) && is_serialized( $value ) ) {
				$out = @unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged
				return false === $out && 'b:0;' !== $value ? $value : $out;
			}
			return $value;
		}
	}
}
