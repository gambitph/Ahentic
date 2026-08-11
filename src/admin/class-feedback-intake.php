<?php
/**
 * Run feedback intake client — site token storage + HTTP proxy to feedback.wpahentic.com.
 *
 * @package Ahentic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Feedback_Intake' ) ) {
	/**
	 * Proxies mint / refresh / reports to Run feedback intake and builds debug packs.
	 */
	class Ahentic_Feedback_Intake {
		/**
		 * Public intake base URL.
		 *
		 * @var string
		 */
		const DEFAULT_BASE_URL = 'https://feedback.wpahentic.com';

		/**
		 * Cloudflare Turnstile site key (public; never the secret).
		 *
		 * @var string
		 */
		const DEFAULT_TURNSTILE_SITE_KEY = '0x4AAAAAAEMuhGeCYVM6atx5';

		/**
		 * Option: HMAC site_token from intake.
		 *
		 * @var string
		 */
		const OPTION_SITE_TOKEN = 'ahentic_feedback_site_token';

		/**
		 * Option: unix expires_at from intake.
		 *
		 * @var string
		 */
		const OPTION_EXPIRES_AT = 'ahentic_feedback_expires_at';

		/**
		 * Option: unix time of last consent (fresh mint).
		 *
		 * @var string
		 */
		const OPTION_CONSENTED_AT = 'ahentic_feedback_consented_at';

		/**
		 * Refresh when this many seconds remain before expiry (14 days).
		 *
		 * @var int
		 */
		const REFRESH_WITHIN_SECONDS = 14 * 86400;

		/**
		 * GitHub Issues API search for public run-feedback duplicates.
		 *
		 * @var string
		 */
		const GH_ISSUES_SEARCH = 'https://api.github.com/search/issues';

		/**
		 * Filterable intake base URL (no trailing slash).
		 *
		 * @return string
		 */
		public static function base_url() {
			$url = apply_filters( 'ahentic_feedback_intake_base_url', self::DEFAULT_BASE_URL );
			return untrailingslashit( is_string( $url ) && $url ? $url : self::DEFAULT_BASE_URL );
		}

		/**
		 * Cloudflare Turnstile site key for the sidebar (public).
		 *
		 * Filterable like base_url(); e2e may override via ahentic_turnstile_site_key.
		 *
		 * @return string
		 */
		public static function turnstile_site_key() {
			$key = apply_filters( 'ahentic_turnstile_site_key', self::DEFAULT_TURNSTILE_SITE_KEY );
			return is_string( $key ) && $key ? $key : self::DEFAULT_TURNSTILE_SITE_KEY;
		}

		/**
		 * Status payload for the sidebar (never includes the raw site_token).
		 *
		 * @return array
		 */
		public static function status() {
			$token      = self::get_site_token();
			$expires_at = (int) get_option( self::OPTION_EXPIRES_AT, 0 );
			$consented  = (int) get_option( self::OPTION_CONSENTED_AT, 0 );

			return array(
				'consented'        => $consented > 0,
				'hasToken'         => '' !== $token,
				'expiresAt'        => $expires_at > 0 ? $expires_at : null,
				'needsRefresh'     => self::needs_refresh(),
				'turnstileSiteKey' => self::turnstile_site_key(),
				'intakeBase'       => self::base_url(),
			);
		}

		/**
		 * Stored site_token or empty.
		 *
		 * @return string Stored site_token or empty.
		 */
		public static function get_site_token() {
			$token = get_option( self::OPTION_SITE_TOKEN, '' );
			return is_string( $token ) ? $token : '';
		}

		/**
		 * Persist mint/refresh response.
		 *
		 * @param string $site_token Token from intake.
		 * @param int    $expires_at Unix expiry.
		 * @param bool   $mark_consent Whether this was a fresh mint / consent.
		 */
		public static function store_token( $site_token, $expires_at, $mark_consent = false ) {
			update_option( self::OPTION_SITE_TOKEN, (string) $site_token, false );
			update_option( self::OPTION_EXPIRES_AT, (int) $expires_at, false );
			if ( $mark_consent ) {
				update_option( self::OPTION_CONSENTED_AT, time(), false );
			}
		}

		/**
		 * Clear stored token (forces intentional re-opt-in next time).
		 */
		public static function clear_token() {
			delete_option( self::OPTION_SITE_TOKEN );
			delete_option( self::OPTION_EXPIRES_AT );
		}

		/**
		 * Whether a stored token should be silently refreshed.
		 *
		 * @return bool
		 */
		public static function needs_refresh() {
			$token = self::get_site_token();
			if ( '' === $token ) {
				return false;
			}
			$expires_at = (int) get_option( self::OPTION_EXPIRES_AT, 0 );
			if ( $expires_at <= 0 ) {
				return true;
			}
			return ( $expires_at - time() ) <= self::REFRESH_WITHIN_SECONDS;
		}

		/**
		 * Cryptographically strong nonce for intake (≥32 bytes as base64url).
		 *
		 * @return string
		 */
		public static function generate_nonce() {
			$bytes = random_bytes( 32 );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- nonce encoding, not obfuscation.
			return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
		}

		/**
		 * POST JSON to intake. Filter `pre_ahentic_feedback_intake_request` may short-circuit.
		 *
		 * @param string $path Relative path (e.g. /v1/reports).
		 * @param array  $body Request body.
		 * @return array|\WP_Error Decoded JSON on success; WP_Error on failure.
		 */
		public static function request( $path, array $body ) {
			$url = self::base_url() . $path;

			/**
			 * Short-circuit intake HTTP (e2e / tests). Return array payload or WP_Error.
			 *
			 * @param null|array|\WP_Error $pre  Null to continue.
			 * @param string               $path Path under intake base.
			 * @param array                $body JSON body.
			 * @param string               $url  Absolute URL.
			 */
			$pre = apply_filters( 'pre_ahentic_feedback_intake_request', null, $path, $body, $url );
			if ( null !== $pre ) {
				return $pre;
			}

			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 45,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
						'User-Agent'   => 'Ahentic/' . ( defined( 'AHENTIC_VERSION' ) ? AHENTIC_VERSION : '0' ),
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_code = isset( $data['error'] ) ? (string) $data['error'] : 'intake_http_' . $code;
				$message    = isset( $data['message'] ) ? (string) $data['message'] : __( 'Run feedback intake request failed.', 'ahentic' );
				return new WP_Error(
					$error_code,
					$message,
					array(
						'status' => $code >= 400 && $code < 600 ? $code : 502,
						'intake' => $data,
					)
				);
			}

			return $data;
		}

		/**
		 * Fresh mint after Turnstile + consent.
		 *
		 * @param string $turnstile_token Cloudflare Turnstile response.
		 * @return array|\WP_Error { site_token, expires_at } or error.
		 */
		public static function mint_site_token( $turnstile_token ) {
			$turnstile_token = is_string( $turnstile_token ) ? trim( $turnstile_token ) : '';
			if ( '' === $turnstile_token ) {
				return new WP_Error(
					'ahentic_feedback_turnstile_required',
					__( 'Turnstile verification is required to opt in to Run feedback.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			$result = self::request(
				'/v1/site-tokens',
				array(
					'turnstile_token' => $turnstile_token,
					'nonce'           => self::generate_nonce(),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$token = isset( $result['site_token'] ) ? (string) $result['site_token'] : '';
			$exp   = isset( $result['expires_at'] ) ? (int) $result['expires_at'] : 0;
			if ( '' === $token || $exp <= 0 ) {
				return new WP_Error(
					'ahentic_feedback_bad_mint',
					__( 'Intake did not return a site token.', 'ahentic' ),
					array( 'status' => 502 )
				);
			}

			self::store_token( $token, $exp, true );
			return array(
				'site_token' => $token,
				'expires_at' => $exp,
			);
		}

		/**
		 * Silent refresh when a token is present. No Turnstile.
		 *
		 * @return array|\WP_Error { site_token, expires_at } or error.
		 */
		public static function refresh_site_token() {
			$token = self::get_site_token();
			if ( '' === $token ) {
				return new WP_Error(
					'ahentic_feedback_no_token',
					__( 'No site token to refresh. Opt in again with Turnstile.', 'ahentic' ),
					array( 'status' => 401 )
				);
			}

			$result = self::request(
				'/v1/site-tokens/refresh',
				array(
					'site_token' => $token,
					'nonce'      => self::generate_nonce(),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$new = isset( $result['site_token'] ) ? (string) $result['site_token'] : '';
			$exp = isset( $result['expires_at'] ) ? (int) $result['expires_at'] : 0;
			if ( '' === $new || $exp <= 0 ) {
				return new WP_Error(
					'ahentic_feedback_bad_refresh',
					__( 'Intake did not return a refreshed site token.', 'ahentic' ),
					array( 'status' => 502 )
				);
			}

			self::store_token( $new, $exp, false );
			return array(
				'site_token' => $new,
				'expires_at' => $exp,
			);
		}

		/**
		 * Ensure a strictly valid token is available (refresh if near expiry).
		 *
		 * @return string|\WP_Error Site token or error.
		 */
		public static function ensure_valid_token() {
			$token = self::get_site_token();
			if ( '' === $token ) {
				return new WP_Error(
					'ahentic_feedback_no_token',
					__( 'Opt in to Run feedback first (consent + Turnstile).', 'ahentic' ),
					array( 'status' => 401 )
				);
			}

			if ( self::needs_refresh() ) {
				$refreshed = self::refresh_site_token();
				if ( is_wp_error( $refreshed ) ) {
					// Stale token outside grace — clear so the UI re-opts in.
					if ( 'invalid_token' === $refreshed->get_error_code() ) {
						self::clear_token();
					}
					return $refreshed;
				}
				return $refreshed['site_token'];
			}

			return $token;
		}

		/**
		 * Build a decluttered debug pack string from diagnostics (does not mutate stored trace).
		 *
		 * @param array $diagnostics From Ahentic_Session_Repository::to_diagnostics().
		 * @return string
		 */
		public static function build_debug_pack( array $diagnostics ) {
			$trace = isset( $diagnostics['trace'] ) && is_array( $diagnostics['trace'] )
				? $diagnostics['trace']
				: array();

			$events = array();
			foreach ( $trace as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				$type = isset( $event['type'] ) ? (string) $event['type'] : '';
				// Drop heartbeat / progress-only noise.
				if ( in_array( $type, array( 'heartbeat', 'progress' ), true ) ) {
					continue;
				}
				$summary = isset( $event['summary'] ) ? (string) $event['summary'] : '';
				$row     = array(
					'type'    => $type,
					'summary' => self::scrub_text( $summary ),
					'at'      => isset( $event['at'] ) ? $event['at'] : null,
				);
				if ( ! empty( $event['data'] ) && is_array( $event['data'] ) ) {
					$row['data'] = self::declutter_event_data( $event['data'] );
				}
				$events[] = $row;
			}

			$pack = array(
				'exportedAt'  => isset( $diagnostics['exportedAt'] ) ? $diagnostics['exportedAt'] : gmdate( 'c' ),
				'environment' => self::safe_environment( isset( $diagnostics['environment'] ) ? $diagnostics['environment'] : array() ),
				'session'     => isset( $diagnostics['session'] ) && is_array( $diagnostics['session'] )
					? self::scrub_assoc( $diagnostics['session'] )
					: array(),
				'state'       => isset( $diagnostics['state'] ) && is_array( $diagnostics['state'] )
					? self::scrub_assoc( $diagnostics['state'] )
					: array(),
				'events'      => $events,
			);

			if ( function_exists( 'wp_json_encode' ) ) {
				$json = wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit bootstrap may lack wp_json_encode.
				$json = json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			}
			if ( ! is_string( $json ) ) {
				$json = '{}';
			}

			// Prefer tail of the run when over a soft export budget (~48k chars leaves room for GitHub framing).
			$max = 48000;
			if ( strlen( $json ) > $max ) {
				$json = "…[truncated for GitHub]\n" . substr( $json, -$max );
			}

			return $json;
		}

		/**
		 * Environment fields safe to include in a debug pack.
		 *
		 * @param array $env Environment snapshot.
		 * @return array Safe fields only (no site URL).
		 */
		private static function safe_environment( $env ) {
			if ( ! is_array( $env ) ) {
				return array();
			}
			$keep = array( 'plugin', 'wp', 'php', 'aiClient', 'theme' );
			$out  = array();
			foreach ( $keep as $key ) {
				if ( isset( $env[ $key ] ) && ( is_string( $env[ $key ] ) || is_numeric( $env[ $key ] ) ) ) {
					$out[ $key ] = $env[ $key ];
				}
			}
			return $out;
		}

		/**
		 * Shrink and scrub one trace event's data payload.
		 *
		 * @param array $data Event data.
		 * @return array Shrunk / scrubbed data.
		 */
		private static function declutter_event_data( array $data ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$k = (string) $key;
				if ( in_array( $k, array( 'prompt', 'messages', 'raw', 'body', 'content', 'html', 'css' ), true ) ) {
					if ( is_string( $value ) ) {
						$out[ $k . '_len' ]     = strlen( $value );
						$out[ $k . '_excerpt' ] = self::scrub_text( substr( $value, 0, 240 ) );
					} elseif ( is_array( $value ) ) {
						$out[ $k . '_count' ] = count( $value );
					}
					continue;
				}
				if ( is_string( $value ) ) {
					if ( strlen( $value ) > 800 ) {
						$out[ $k ] = self::scrub_text( substr( $value, 0, 400 ) ) . '…[truncated]';
					} else {
						$out[ $k ] = self::scrub_text( $value );
					}
				} elseif ( is_scalar( $value ) || null === $value ) {
					$out[ $k ] = $value;
				} elseif ( is_array( $value ) ) {
					$encoded = function_exists( 'wp_json_encode' )
						? wp_json_encode( $value )
						: json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit bootstrap may lack wp_json_encode.
					if ( is_string( $encoded ) && strlen( $encoded ) > 1200 ) {
						$out[ $k . '_bytes' ] = strlen( $encoded );
					} else {
						$out[ $k ] = self::scrub_assoc( $value );
					}
				}
			}
			return $out;
		}

		/**
		 * Recursively scrub string values in an associative array.
		 *
		 * @param array $data Associative array.
		 * @return array Scrubbed copy.
		 */
		private static function scrub_assoc( array $data ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				if ( is_string( $value ) ) {
					$out[ $key ] = self::scrub_text( $value );
				} elseif ( is_scalar( $value ) || null === $value ) {
					$out[ $key ] = $value;
				} elseif ( is_array( $value ) ) {
					$out[ $key ] = self::scrub_assoc( $value );
				}
			}
			return $out;
		}

		/**
		 * Deterministic scrub: emails, URLs that look like the site, common secrets.
		 *
		 * @param string $text Raw text.
		 * @return string
		 */
		public static function scrub_text( $text ) {
			$text = (string) $text;
			$text = preg_replace( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', '[EMAIL]', $text );
			$text = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP]', $text );
			$text = preg_replace( '/(sk-|ghp_|gho_|github_pat_)[A-Za-z0-9_\-]+/', '[SECRET]', $text );
			$text = preg_replace( '#https?://[^\s<>"\']+#i', '[URL]', $text );
			$text = preg_replace( '#/(?:Users|home)/[^\s]+#', '[PATH]', $text );

			if ( function_exists( 'home_url' ) ) {
				$home = home_url();
				if ( $home ) {
					$text = str_replace( $home, '[SITE]', $text );
				}
			}
			if ( function_exists( 'site_url' ) ) {
				$site = site_url();
				$home = function_exists( 'home_url' ) ? home_url() : '';
				if ( $site && $site !== $home ) {
					$text = str_replace( $site, '[SITE]', $text );
				}
			}

			return $text;
		}

		/**
		 * Draft AI summary for a report. Fails visibly when AI is unavailable.
		 *
		 * @param array  $diagnostics Diagnostics bundle.
		 * @param string $prompt_excerpt Anonymized user prompt excerpt.
		 * @return array|\WP_Error { title, summary } or error.
		 */
		public static function draft_summary( array $diagnostics, $prompt_excerpt = '' ) {
			if ( ! class_exists( 'Ahentic_AI' ) ) {
				return new WP_Error(
					'ahentic_feedback_ai_unavailable',
					__( 'AI is unavailable; cannot draft a Run feedback summary.', 'ahentic' ),
					array( 'status' => 503 )
				);
			}

			$state      = isset( $diagnostics['state'] ) && is_array( $diagnostics['state'] ) ? $diagnostics['state'] : array();
			$session    = isset( $diagnostics['session'] ) && is_array( $diagnostics['session'] ) ? $diagnostics['session'] : array();
			$last_error = isset( $session['lastError'] ) ? (string) $session['lastError'] : '';
			$goal       = isset( $state['activeGoal'] ) ? (string) $state['activeGoal'] : '';

			$system = 'You write short anonymized bug-report summaries for Ahentic maintainers. '
				. 'No site URLs, emails, or personal names. Reply with JSON only: '
				. '{"title":"≤80 chars","summary":"≤600 chars"}';

			$user = "Draft a Run feedback report title and summary.\n"
				. 'Status: ' . ( isset( $session['status'] ) ? $session['status'] : '' ) . "\n"
				. 'Job resumable: ' . ( ! empty( $state['jobResumable'] ) ? 'yes' : 'no' ) . "\n"
				. 'Last error: ' . self::scrub_text( $last_error ) . "\n"
				. 'Goal: ' . self::scrub_text( $goal ) . "\n"
				. 'Prompt excerpt: ' . self::scrub_text( (string) $prompt_excerpt ) . "\n";

			$result = Ahentic_AI::complete_chat( $system, array(), $user );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'ahentic_feedback_ai_unavailable',
					__( 'AI is unavailable; cannot draft a Run feedback summary.', 'ahentic' ),
					array( 'status' => 503 )
				);
			}

			$text = '';
			if ( is_array( $result ) && isset( $result['text'] ) ) {
				$text = (string) $result['text'];
			} elseif ( is_string( $result ) ) {
				$text = $result;
			}

			$parsed = self::parse_summary_json( $text );
			if ( is_wp_error( $parsed ) ) {
				// Soft fallback when the model returns prose — still fail if empty.
				$title   = __( 'Unsure Ahentic run', 'ahentic' );
				$summary = trim( self::scrub_text( wp_strip_all_tags( $text ) ) );
				if ( '' === $summary ) {
					return $parsed;
				}
				return array(
					'title'   => $title,
					'summary' => substr( $summary, 0, 4000 ),
				);
			}

			return $parsed;
		}

		/**
		 * Parse model JSON into title + summary.
		 *
		 * @param string $text Model text.
		 * @return array|\WP_Error
		 */
		private static function parse_summary_json( $text ) {
			$text = trim( (string) $text );
			if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
				$data = json_decode( $m[0], true );
				if ( is_array( $data ) && ! empty( $data['title'] ) && ! empty( $data['summary'] ) ) {
					return array(
						'title'   => substr( self::scrub_text( (string) $data['title'] ), 0, 200 ),
						'summary' => substr( self::scrub_text( (string) $data['summary'] ), 0, 4000 ),
					);
				}
			}
			return new WP_Error(
				'ahentic_feedback_bad_summary',
				__( 'Could not parse an AI summary for Run feedback.', 'ahentic' ),
				array( 'status' => 502 )
			);
		}

		/**
		 * Search public GitHub issues labelled run-feedback (best-effort; may return null).
		 *
		 * @param string $query Extra search terms (already scrubbed).
		 * @return int|null Proposed duplicate issue number or null.
		 */
		public static function find_duplicate_issue( $query = '' ) {
			$q     = 'repo:gambitph/Ahentic label:run-feedback is:open';
			$query = trim( (string) $query );
			if ( '' !== $query ) {
				$q .= ' ' . preg_replace( '/[^\w\s\-]+/u', ' ', substr( $query, 0, 80 ) );
			}

			/**
			 * Short-circuit duplicate search (tests). Return issue number, null, or WP_Error.
			 *
			 * @param mixed  $pre   Null to continue.
			 * @param string $query Search query string.
			 */
			$pre = apply_filters( 'pre_ahentic_feedback_duplicate_search', null, $q );
			if ( null !== $pre ) {
				return is_int( $pre ) ? $pre : null;
			}

			$url      = add_query_arg(
				array(
					'q'        => $q,
					'per_page' => 5,
				),
				self::GH_ISSUES_SEARCH
			);
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'Ahentic-RunFeedback',
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return null;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return null;
			}
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( empty( $data['items'][0]['number'] ) ) {
				return null;
			}
			// High bar: only propose when a single strong hit; leave null if noisy.
			$total = isset( $data['total_count'] ) ? (int) $data['total_count'] : 0;
			if ( $total > 8 ) {
				return null;
			}
			return (int) $data['items'][0]['number'];
		}

		/**
		 * File a report for a session (builds pack + summary, ensures token, POSTs).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $args {
		 *     Optional overrides.
		 *     @type string     $title
		 *     @type string     $summary
		 *     @type string     $prompt_excerpt
		 *     @type int|null   $duplicate_of
		 * }
		 * @return array|\WP_Error Intake response { action, number, html_url }.
		 */
		public static function file_report( $session_id, array $args = array() ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 || ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return new WP_Error(
					'ahentic_feedback_bad_session',
					__( 'Invalid session for Run feedback.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}

			$diagnostics = Ahentic_Session_Repository::to_diagnostics( $session_id );
			if ( is_wp_error( $diagnostics ) ) {
				return $diagnostics;
			}

			$prompt_excerpt = isset( $args['prompt_excerpt'] ) ? (string) $args['prompt_excerpt'] : '';
			if ( '' === $prompt_excerpt ) {
				$prompt_excerpt = self::latest_user_prompt_excerpt( $session_id );
			}

			$title   = isset( $args['title'] ) ? (string) $args['title'] : '';
			$summary = isset( $args['summary'] ) ? (string) $args['summary'] : '';
			if ( '' === $title || '' === $summary ) {
				$drafted = self::draft_summary( $diagnostics, $prompt_excerpt );
				if ( is_wp_error( $drafted ) ) {
					return $drafted;
				}
				if ( '' === $title ) {
					$title = $drafted['title'];
				}
				if ( '' === $summary ) {
					$summary = $drafted['summary'];
				}
			}

			$debug_pack = self::build_debug_pack( $diagnostics );
			$token      = self::ensure_valid_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$duplicate_of = array_key_exists( 'duplicate_of', $args ) ? $args['duplicate_of'] : null;
			if ( null === $duplicate_of ) {
				$duplicate_of = self::find_duplicate_issue( $title );
			}

			$env       = isset( $diagnostics['environment'] ) && is_array( $diagnostics['environment'] ) ? $diagnostics['environment'] : array();
			$abilities = self::abilities_from_trace( isset( $diagnostics['trace'] ) ? $diagnostics['trace'] : array() );

			$body = array(
				'site_token'          => $token,
				'title'               => substr( self::scrub_text( $title ), 0, 200 ),
				'summary'             => substr( self::scrub_text( $summary ), 0, 4000 ),
				'debug_pack'          => $debug_pack,
				'prompt_excerpt'      => substr( self::scrub_text( $prompt_excerpt ), 0, 2000 ),
				'duplicate_of'        => is_int( $duplicate_of ) ? $duplicate_of : null,
				'ahentic_version'     => defined( 'AHENTIC_VERSION' ) ? AHENTIC_VERSION : '',
				'wp_version'          => isset( $env['wp'] ) ? (string) $env['wp'] : get_bloginfo( 'version' ),
				'abilities_mentioned' => $abilities,
				'client'              => array(
					'php_version' => isset( $env['php'] ) ? (string) $env['php'] : PHP_VERSION,
					'ai_client'   => isset( $env['aiClient'] ) ? (string) $env['aiClient'] : '',
				),
			);

			$result = self::request( '/v1/reports', $body );
			if ( is_wp_error( $result ) ) {
				// Token expired mid-flight — try one silent refresh then retry once.
				if ( 'invalid_token' === $result->get_error_code() ) {
					$refreshed = self::refresh_site_token();
					if ( ! is_wp_error( $refreshed ) ) {
						$body['site_token'] = $refreshed['site_token'];
						$result             = self::request( '/v1/reports', $body );
					}
				}
			}

			return $result;
		}

		/**
		 * Latest user message excerpt for the report.
		 *
		 * @param int $session_id Session ID.
		 * @return string Scrubbed excerpt.
		 */
		private static function latest_user_prompt_excerpt( $session_id ) {
			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			if ( ! is_array( $entries ) ) {
				return '';
			}
			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				if ( isset( $entries[ $i ]['role'] ) && 'user' === $entries[ $i ]['role'] ) {
					$content = isset( $entries[ $i ]['content'] ) ? (string) $entries[ $i ]['content'] : '';
					return substr( self::scrub_text( $content ), 0, 2000 );
				}
			}
			return '';
		}

		/**
		 * Ability names mentioned in the session trace.
		 *
		 * @param array $trace Trace events.
		 * @return string[] Ability names.
		 */
		private static function abilities_from_trace( $trace ) {
			if ( ! is_array( $trace ) ) {
				return array();
			}
			$names = array();
			foreach ( $trace as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
				foreach ( array( 'ability', 'name', 'tool' ) as $key ) {
					if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) && false !== strpos( $data[ $key ], '/' ) ) {
						$names[ $data[ $key ] ] = true;
					}
				}
			}
			return array_keys( $names );
		}
	}
}
