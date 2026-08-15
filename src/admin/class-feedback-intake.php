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
	 * Proxies mint / refresh / submit / reports to Run feedback intake and builds debug packs.
	 */
	class Ahentic_Feedback_Intake {
		/**
		 * Public intake base URL.
		 *
		 * @var string
		 */
		const DEFAULT_BASE_URL = 'https://feedback.wpahentic.com';

		/**
		 * Shared mint-proof key (must match Worker `MINT_KEY` secret).
		 *
		 * Client attestation only — ships in the plugin zip by design.
		 *
		 * @var string
		 */
		const DEFAULT_MINT_KEY = 'AhenticFeedbackMintV1.k7m2p9q4r8s1t5u0';

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
		 * Max length for optional admin user_note (keep in sync with sidebar maxLength).
		 *
		 * @var int
		 */
		const USER_NOTE_MAX_LENGTH = 1000;

		/**
		 * Max length for an unconfirmed hypothesis on a draft / report.
		 *
		 * @var int
		 */
		const HYPOTHESIS_MAX_LENGTH = 1200;

		/**
		 * Max JSON chars of editor snapshot injected into the draft prompt.
		 *
		 * @var int
		 */
		const EDITOR_SNAPSHOT_PROMPT_MAX = 4000;

		/**
		 * Max length for the anonymized conversation list posted as prompt_excerpt.
		 *
		 * @var int
		 */
		const PROMPT_EXCERPT_MAX_LENGTH = 8000;

		/**
		 * Max bytes for the Run feedback debug pack sent to intake.
		 *
		 * Under GitHub’s 25 MB attachment limit; leaves headroom under intake’s POST body cap.
		 *
		 * @var int
		 */
		const DEBUG_PACK_MAX_BYTES = 2097152; // 2 MiB

		/**
		 * Ahentic replies longer than this are summarized extractively in the conversation list.
		 *
		 * @var int
		 */
		const ASSISTANT_REPLY_SUMMARIZE_AFTER = 500;

		/**
		 * Max chars kept for a summarized Ahentic reply.
		 *
		 * @var int
		 */
		const ASSISTANT_REPLY_SUMMARY_MAX = 320;

		/**
		 * Max chars kept for a single user turn in the conversation list.
		 *
		 * @var int
		 */
		const USER_TURN_MAX_LENGTH = 1000;

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

		/** Report kind: No / debug pack. */
		const KIND_FAILURE = 'failure';

		/** Report kind: Yes / good-run narrative. */
		const KIND_SUCCESS = 'success';

		/** GitHub label for failure reports (intake LABEL_FAILURE). */
		const LABEL_FAILURE = 'run-feedback';

		/** GitHub label for success reports (intake LABEL_SUCCESS). */
		const LABEL_SUCCESS = 'run-success';

		/** Cap playbook_ids to match intake sanitizePlaybookIds (empty on success → needs-playbook). */
		const PLAYBOOK_IDS_MAX = 2;

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
		 * Shared mint-proof key for fresh opt-in (must match Worker `MINT_KEY`).
		 *
		 * @return string
		 */
		public static function mint_key() {
			$default = self::DEFAULT_MINT_KEY;
			if ( function_exists( 'apply_filters' ) ) {
				$key = apply_filters( 'ahentic_feedback_mint_key', $default );
				return is_string( $key ) && '' !== $key ? $key : $default;
			}
			return $default;
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
				'consented'    => $consented > 0,
				'hasToken'     => '' !== $token,
				'expiresAt'    => $expires_at > 0 ? $expires_at : null,
				'needsRefresh' => self::needs_refresh(),
				'intakeBase'   => self::base_url(),
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
		 * Compute mint proof (PRD HMAC formula).
		 *
		 * @param string      $nonce      Strong nonce.
		 * @param int         $issued_at  Unix seconds.
		 * @param string|null $mint_key   Optional key override (tests).
		 * @return string Lowercase hex HMAC-SHA256.
		 */
		public static function compute_mint_proof( $nonce, $issued_at, $mint_key = null ) {
			$key     = ( is_string( $mint_key ) && '' !== $mint_key ) ? $mint_key : self::mint_key();
			$message = 'ahentic-mint-v1' . "\n" . $nonce . "\n" . (string) (int) $issued_at;
			return hash_hmac( 'sha256', $message, $key );
		}

		/**
		 * POST JSON to intake. Filter `ahentic_pre_feedback_intake_request` may short-circuit.
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
			$pre = apply_filters( 'ahentic_pre_feedback_intake_request', null, $path, $body, $url );
			if ( null !== $pre ) {
				return $pre;
			}

			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 60,
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
		 * Fresh mint after mint proof + consent.
		 *
		 * @return array|\WP_Error { site_token, expires_at } or error.
		 */
		public static function mint_site_token() {
			$nonce      = self::generate_nonce();
			$issued_at  = time();
			$mint_proof = self::compute_mint_proof( $nonce, $issued_at );

			$result = self::request(
				'/v1/site-tokens',
				array(
					'nonce'      => $nonce,
					'issued_at'  => $issued_at,
					'mint_proof' => $mint_proof,
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
		 * Silent refresh when a token is present. No mint proof.
		 *
		 * @return array|\WP_Error { site_token, expires_at } or error.
		 */
		public static function refresh_site_token() {
			$token = self::get_site_token();
			if ( '' === $token ) {
				return new WP_Error(
					'ahentic_feedback_no_token',
					__( 'No site token to refresh. Opt in to Run feedback again.', 'ahentic' ),
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
					__( 'Opt in to Run feedback first.', 'ahentic' ),
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
		 * Soft-capped at DEBUG_PACK_MAX_BYTES (prefer tail). Intake files the pack as a
		 * GitHub attachment rather than pasting it into the issue body.
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

			$page = isset( $diagnostics['page'] ) && is_array( $diagnostics['page'] )
				? self::slim_page_context_for_feedback( $diagnostics['page'] )
				: array();
			if ( ! empty( $page ) ) {
				$pack['page'] = $page;
			}

			$snap = isset( $diagnostics['editor_snapshot'] ) && is_array( $diagnostics['editor_snapshot'] )
				? self::scrub_assoc( $diagnostics['editor_snapshot'] )
				: array();
			if ( ! empty( $snap ) ) {
				$pack['editor_snapshot'] = $snap;
			}

			$observations = isset( $diagnostics['observations'] ) && is_array( $diagnostics['observations'] )
				? self::scrub_assoc( $diagnostics['observations'] )
				: array();
			if ( ! empty( $observations ) ) {
				$pack['observations'] = $observations;
			}

			$hypothesis = isset( $diagnostics['hypothesis'] ) ? self::scrub_text( (string) $diagnostics['hypothesis'] ) : '';
			if ( '' !== $hypothesis ) {
				$pack['hypothesis'] = substr( $hypothesis, 0, self::HYPOTHESIS_MAX_LENGTH );
			}

			if ( function_exists( 'wp_json_encode' ) ) {
				$json = wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit bootstrap may lack wp_json_encode.
				$json = json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			}
			if ( ! is_string( $json ) ) {
				$json = '{}';
			}

			// Soft intake/export cap (full pack is filed as a GitHub attachment by intake).
			$max = self::DEBUG_PACK_MAX_BYTES;
			if ( strlen( $json ) > $max ) {
				$mark = "…[truncated for intake pack cap]\n";
				$json = $mark . substr( $json, -( $max - strlen( $mark ) ) );
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
		 * Page identity safe to file (no site URL or document title).
		 *
		 * @param array $ctx Raw page context from the sidebar or session.
		 * @return array Slim, scrubbed fields.
		 */
		public static function slim_page_context_for_feedback( $ctx ) {
			if ( ! is_array( $ctx ) ) {
				return array();
			}

			$out = array();
			foreach ( array( 'isAdmin', 'is_block_editor', 'is_dirty', 'is_new' ) as $key ) {
				if ( array_key_exists( $key, $ctx ) ) {
					$out[ $key ] = ! empty( $ctx[ $key ] );
				}
			}
			foreach ( array( 'pathname', 'search', 'post_type', 'editor_title', 'status' ) as $key ) {
				if ( isset( $ctx[ $key ] ) && ( is_string( $ctx[ $key ] ) || is_numeric( $ctx[ $key ] ) ) ) {
					$out[ $key ] = self::scrub_text( (string) $ctx[ $key ] );
				}
			}
			if ( array_key_exists( 'post_id', $ctx ) ) {
				$out['post_id'] = ( null === $ctx['post_id'] || '' === $ctx['post_id'] )
					? null
					: (int) $ctx['post_id'];
			}
			if ( isset( $ctx['blocks_count'] ) ) {
				$out['blocks_count'] = max( 0, (int) $ctx['blocks_count'] );
			}

			return $out;
		}

		/**
		 * Append an optional user note to an AI (or override) summary.
		 *
		 * @param string $summary   Existing summary.
		 * @param string $user_note Free-text note from the admin (may be empty).
		 * @return string Summary, possibly with a scrubbed "User note:" block.
		 */
		public static function append_user_note_to_summary( $summary, $user_note ) {
			$summary = (string) $summary;
			$note    = (string) $user_note;
			$note = wp_strip_all_tags( $note );
			$note = trim( $note );
			if ( '' === $note ) {
				return $summary;
			}
			$note = self::scrub_text( $note );
			$note = substr( $note, 0, self::USER_NOTE_MAX_LENGTH );
			if ( '' === $note ) {
				return $summary;
			}
			$block = 'User note: ' . $note;
			if ( '' === trim( $summary ) ) {
				return $block;
			}
			return rtrim( $summary ) . "\n\n" . $block;
		}

		/**
		 * Append an unconfirmed hypothesis after the summary (and user note).
		 *
		 * @param string $summary    Existing summary.
		 * @param string $hypothesis Draft hypothesis (may be empty).
		 * @return string Summary, possibly with a labeled hypothesis block.
		 */
		public static function append_hypothesis_to_summary( $summary, $hypothesis ) {
			$summary    = (string) $summary;
			$hypothesis = (string) $hypothesis;
			$hypothesis = wp_strip_all_tags( $hypothesis );
			$hypothesis = trim( $hypothesis );
			if ( '' === $hypothesis ) {
				return $summary;
			}
			$hypothesis = self::scrub_text( $hypothesis );
			$hypothesis = substr( $hypothesis, 0, self::HYPOTHESIS_MAX_LENGTH );
			if ( '' === $hypothesis ) {
				return $summary;
			}
			$block = 'Hypothesis (unconfirmed): ' . $hypothesis;
			if ( '' === trim( $summary ) ) {
				return $block;
			}
			return rtrim( $summary ) . "\n\n" . $block;
		}

		/**
		 * Static title/summary when the client did not draft (no LLM on file).
		 *
		 * @param string $kind success|failure.
		 * @return array{title:string,summary:string,hypothesis:string}
		 */
		public static function fallback_draft_fields( $kind = self::KIND_FAILURE ) {
			if ( self::KIND_SUCCESS === self::normalize_report_kind( $kind ) ) {
				return array(
					'title'      => __( 'Successful Ahentic run', 'ahentic' ),
					'summary'    => __( 'The reporter marked this run as good. See the conversation.', 'ahentic' ),
					'hypothesis' => '',
				);
			}
			return array(
				'title'      => __( 'Unsure Ahentic run', 'ahentic' ),
				'summary'    => __( 'The reporter was not satisfied with this run. See the debug pack and conversation.', 'ahentic' ),
				'hypothesis' => '',
			);
		}

		/**
		 * Soft fallback when the model returns prose instead of JSON.
		 *
		 * Keeps the scrubbed text as summary and uses the kind's static title.
		 * Empty prose still fails so the client can file with fallback_draft_fields.
		 *
		 * @param string $kind success|failure.
		 * @param string $text Raw model text.
		 * @return array|\WP_Error { title, summary, hypothesis, abilities }
		 */
		public static function draft_fields_from_prose( $kind, $text ) {
			$kind    = self::normalize_report_kind( $kind );
			$summary = (string) $text;
			$summary = wp_strip_all_tags( $summary );
			$summary = trim( self::scrub_text( $summary ) );
			if ( '' === $summary ) {
				return new WP_Error(
					'ahentic_feedback_bad_summary',
					__( 'Could not parse an AI summary for Run feedback.', 'ahentic' ),
					array( 'status' => 502 )
				);
			}
			$fallback = self::fallback_draft_fields( $kind );
			return array(
				'title'      => $fallback['title'],
				'summary'    => substr( $summary, 0, 4000 ),
				'hypothesis' => '',
				'abilities'  => array(),
			);
		}

		/**
		 * Normalize report kind to intake values.
		 *
		 * @param mixed $kind Raw kind.
		 * @return string success|failure.
		 */
		public static function normalize_report_kind( $kind ) {
			return self::KIND_SUCCESS === (string) $kind ? self::KIND_SUCCESS : self::KIND_FAILURE;
		}

		/**
		 * GitHub label for a report kind.
		 *
		 * @param mixed $kind Raw kind.
		 * @return string
		 */
		public static function report_label_for_kind( $kind ) {
			return self::KIND_SUCCESS === self::normalize_report_kind( $kind )
				? self::LABEL_SUCCESS
				: self::LABEL_FAILURE;
		}

		/**
		 * GitHub issue search query for duplicate detection.
		 *
		 * @param string $query Extra search terms.
		 * @param string $label run-feedback or run-success.
		 * @return string
		 */
		public static function duplicate_search_query( $query = '', $label = self::LABEL_FAILURE ) {
			$allowed = array( self::LABEL_FAILURE, self::LABEL_SUCCESS );
			$label   = (string) $label;
			if ( ! in_array( $label, $allowed, true ) ) {
				$label = self::LABEL_FAILURE;
			}

			$q     = 'repo:gambitph/Ahentic label:' . $label . ' is:open';
			$query = trim( (string) $query );
			if ( '' === $query ) {
				return $q;
			}

			$extra = preg_replace( '/[^\w\s\-]+/u', ' ', substr( $query, 0, 80 ) );
			$extra = is_string( $extra ) ? trim( $extra ) : '';
			if ( '' !== $extra ) {
				$q .= ' ' . $extra;
			}
			return $q;
		}

		/**
		 * Sanitize playbook ids for intake (lowercase kebab, cap 2).
		 *
		 * @param mixed $raw Raw ids.
		 * @return string[]
		 */
		public static function sanitize_playbook_ids( $raw ) {
			if ( ! is_array( $raw ) ) {
				return array();
			}
			$seen = array();
			$out  = array();
			foreach ( $raw as $item ) {
				if ( ! is_string( $item ) ) {
					continue;
				}
				$id = strtolower( trim( $item ) );
				$id = preg_replace( '/[^a-z0-9-]/', '', $id );
				if ( ! is_string( $id ) || '' === $id || isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$out[]       = $id;
				if ( count( $out ) >= self::PLAYBOOK_IDS_MAX ) {
					break;
				}
			}
			return $out;
		}

		/**
		 * Ordered ability lines from a diagnostics trace (name + ok/fail).
		 *
		 * @param mixed $trace Trace events.
		 * @return string
		 */
		public static function work_excerpt_from_trace( $trace ) {
			if ( ! is_array( $trace ) ) {
				return '';
			}
			$lines = array();
			foreach ( $trace as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
				$name = self::ability_name_from_trace_data( $data );
				if ( '' === $name ) {
					continue;
				}
				$ok      = ! empty( $data['ok'] );
				$lines[] = $name . ' ' . ( $ok ? 'ok' : 'fail' );
			}
			return implode( "\n", $lines );
		}

		/**
		 * Prefer LLM-pruned abilities that still exist on the trace; else the full trace list.
		 *
		 * @param mixed    $proposed   Drafted names.
		 * @param string[] $from_trace Names from the session trace (order preserved).
		 * @return string[]
		 */
		public static function resolve_abilities_mentioned( $proposed, array $from_trace ) {
			$allowed = array_fill_keys( $from_trace, true );
			$out     = array();
			if ( is_array( $proposed ) ) {
				foreach ( $proposed as $name ) {
					if ( ! is_string( $name ) || ! isset( $allowed[ $name ] ) ) {
						continue;
					}
					if ( in_array( $name, $out, true ) ) {
						continue;
					}
					$out[] = $name;
				}
			}
			return empty( $out ) ? array_values( $from_trace ) : $out;
		}

		/**
		 * Intake POST /v1/reports body without site_token.
		 *
		 * Success omits debug_pack (intake ignores/forbids requiring it).
		 *
		 * @param array $parts Field bag.
		 * @return array
		 */
		public static function build_intake_report_body( array $parts ) {
			$kind = self::normalize_report_kind( isset( $parts['kind'] ) ? $parts['kind'] : self::KIND_FAILURE );
			$body = array(
				'kind'                => $kind,
				'title'               => isset( $parts['title'] ) ? (string) $parts['title'] : '',
				'summary'             => isset( $parts['summary'] ) ? (string) $parts['summary'] : '',
				'prompt_excerpt'      => isset( $parts['prompt_excerpt'] ) ? (string) $parts['prompt_excerpt'] : '',
				'duplicate_of'        => array_key_exists( 'duplicate_of', $parts ) && is_int( $parts['duplicate_of'] )
					? $parts['duplicate_of']
					: null,
				'ahentic_version'     => isset( $parts['ahentic_version'] ) ? (string) $parts['ahentic_version'] : '',
				'wp_version'          => isset( $parts['wp_version'] ) ? (string) $parts['wp_version'] : '',
				'abilities_mentioned' => isset( $parts['abilities_mentioned'] ) && is_array( $parts['abilities_mentioned'] )
					? $parts['abilities_mentioned']
					: array(),
				'playbook_ids'        => self::sanitize_playbook_ids( isset( $parts['playbook_ids'] ) ? $parts['playbook_ids'] : array() ),
				'client'              => isset( $parts['client'] ) && is_array( $parts['client'] ) ? $parts['client'] : array(),
			);
			if ( self::KIND_SUCCESS !== $kind ) {
				$body['debug_pack'] = isset( $parts['debug_pack'] ) ? (string) $parts['debug_pack'] : '';
			}
			return $body;
		}

		/**
		 * Draft AI summary for a report. Fails visibly when AI is unavailable.
		 *
		 * Called from submit_report and the draft REST route, not from file_report.
		 *
		 * @param array  $diagnostics     Diagnostics bundle.
		 * @param string $prompt_excerpt  Anonymized conversation list (user + Ahentic turns).
		 * @param string $user_note       Optional admin note (highest signal for what went wrong).
		 * @param array  $client_context  Live page / editor snapshot / observations.
		 * @param string $kind            success|failure.
		 * @return array|\WP_Error { title, summary, hypothesis, abilities } or error.
		 */
		public static function draft_summary( array $diagnostics, $prompt_excerpt = '', $user_note = '', array $client_context = array(), $kind = self::KIND_FAILURE ) {
			$kind = self::normalize_report_kind( $kind );
			if ( ! class_exists( 'Ahentic_AI' ) ) {
				return new WP_Error(
					'ahentic_feedback_ai_unavailable',
					__( 'AI is unavailable; cannot draft a Run feedback summary.', 'ahentic' ),
					array( 'status' => 503 )
				);
			}

			$prompts = self::build_draft_summary_prompts( $diagnostics, $prompt_excerpt, $user_note, $client_context, $kind );
			$result  = Ahentic_AI::complete_chat( $prompts['system'], array(), $prompts['user'] );
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

			$parsed = self::decode_draft_payload( $text );
			if ( is_wp_error( $parsed ) ) {
				$from_prose = self::draft_fields_from_prose( $kind, $text );
				return is_wp_error( $from_prose ) ? $parsed : $from_prose;
			}

			return $parsed;
		}

		/**
		 * Build system + user prompts for Run feedback title/summary drafting.
		 *
		 * @param array  $diagnostics     Diagnostics bundle.
		 * @param string $prompt_excerpt  Anonymized conversation list.
		 * @param string $user_note       Optional admin note.
		 * @param array  $client_context  Live page / editor snapshot / observations.
		 * @param string $kind            success|failure.
		 * @return array{system:string,user:string}
		 */
		public static function build_draft_summary_prompts( array $diagnostics, $prompt_excerpt = '', $user_note = '', array $client_context = array(), $kind = self::KIND_FAILURE ) {
			$kind       = self::normalize_report_kind( $kind );
			$state      = isset( $diagnostics['state'] ) && is_array( $diagnostics['state'] ) ? $diagnostics['state'] : array();
			$session    = isset( $diagnostics['session'] ) && is_array( $diagnostics['session'] ) ? $diagnostics['session'] : array();
			$last_error = isset( $session['lastError'] ) ? (string) $session['lastError'] : '';
			$goal       = isset( $state['activeGoal'] ) ? (string) $state['activeGoal'] : '';
			$trace      = isset( $diagnostics['trace'] ) ? $diagnostics['trace'] : array();
			$abilities  = self::abilities_from_trace( $trace );
			$work       = self::work_excerpt_from_trace( $trace );

			$note = (string) $user_note;
			$note = wp_strip_all_tags( $note );
			$note = trim( self::scrub_text( $note ) );
			if ( strlen( $note ) > self::USER_NOTE_MAX_LENGTH ) {
				$note = substr( $note, 0, self::USER_NOTE_MAX_LENGTH );
			}

			if ( self::KIND_SUCCESS === $kind ) {
				$system = 'You write anonymized GitHub issue titles and summaries for successful Ahentic runs. '
					. 'The user marked this run as the correct WordPress path - treat it as a good-run specimen, not a bug. '
					. 'Other reporters will search titles to find the same kind of work - generalize the work class. '
					. 'Narrate (1) the user goal / intent from the conversation (correct stale goal fragments), '
					. '(2) the process (what Ahentic did, using the ordered work list), and (3) the result the user marked as good. '
					. 'Work lists every tool in order. In abilities, drop a name only when you are certain it did not serve this intent/goal. '
					. 'If there is any uncertainty, keep it. Never invent names. If you cannot prune confidently, repeat the full list. '
					. 'Title: <=80 chars; name the general work class (e.g. Installed an SEO plugin), not a one-off site, country, or quoted goal text. '
					. 'Summary: <=800 chars; short narration covering intent, goal, process, and result. '
					. 'No site URLs, emails, or personal names. Reply with JSON only: '
					. '{"title":"…","summary":"…","abilities":["ahentic/…"]}';
			} else {
				$system = 'You write anonymized GitHub issue titles and summaries for Ahentic Run feedback. '
					. 'Other reporters will search titles to find duplicates - generalize the failure mode. '
					. "Infer (1) the user's intent and (2) the actual product issue from the signals below. "
					. 'When the user note conflicts with session status trivia (idle/busy, resumable, empty goal fragments), trust the user note. '
					. 'Title: ≤80 chars; name the general bug class (e.g. false missing-ability notice after success), not a one-off scenario, country, or quoted goal text. '
					. 'Avoid titles that only restate idle/no result/status. '
					. 'Summary: ≤600 chars; 1–3 sentences covering intent, expected vs actual, and the misleading behavior if any. '
					. 'Hypothesis: ≤400 chars; one unconfirmed guess from live page, editor snapshot, and observations. '
					. 'Do not put the hypothesis into the title (titles are used for duplicate search). '
					. 'No site URLs, emails, or personal names. Reply with JSON only: '
					. '{"title":"…","summary":"…","hypothesis":"…"}';
			}

			if ( self::KIND_SUCCESS === $kind ) {
				$user = "Draft a good-run report title, summary, and abilities list.\n";
			} else {
				$user = "Draft a Run feedback report title, summary, and hypothesis.\n";
			}
			$user .= 'User note (highest signal when present): ' . ( '' !== $note ? $note : '(none)' ) . "\n";
			$user .= 'Conversation:\n' . self::scrub_text( (string) $prompt_excerpt ) . "\n";
			$user .= 'Active goal (may be partial/stale): ' . self::scrub_text( $goal ) . "\n";
			$user .= 'Abilities used: ' . ( ! empty( $abilities ) ? implode( ', ', $abilities ) : '(none)' ) . "\n";
			$user .= 'Work (ordered abilities, ok/fail):\n' . ( '' !== $work ? $work : '(none)' ) . "\n";
			if ( self::KIND_SUCCESS !== $kind ) {
				$user .= 'Last error: ' . self::scrub_text( $last_error ) . "\n";
				$user .= 'Session status (context only, not the bug): ' . ( isset( $session['status'] ) ? $session['status'] : '' ) . "\n";
				$user .= 'Job resumable (context only): ' . ( ! empty( $state['jobResumable'] ) ? 'yes' : 'no' ) . "\n";
			}

			if ( self::KIND_SUCCESS !== $kind ) {
				$ctx_note = self::format_client_context_for_prompt( $client_context );
				if ( '' !== $ctx_note ) {
					$user .= $ctx_note . "\n";
				}
			}

			return array(
				'system' => $system,
				'user'   => $user,
			);
		}

		/**
		 * Format live page / editor / observation signals for the draft prompt.
		 *
		 * @param array $client_context Client snapshot.
		 * @return string
		 */
		public static function format_client_context_for_prompt( array $client_context ) {
			$lines = array();

			$page = isset( $client_context['page_context'] ) && is_array( $client_context['page_context'] )
				? self::slim_page_context_for_feedback( $client_context['page_context'] )
				: array();
			if ( ! empty( $page ) ) {
				$lines[] = 'Live page: ' . self::json_encode_capped( $page, 800 );
			}

			$session_page = isset( $client_context['session_page_context'] ) && is_array( $client_context['session_page_context'] )
				? self::slim_page_context_for_feedback( $client_context['session_page_context'] )
				: array();
			if ( ! empty( $session_page ) ) {
				$lines[] = 'Session page (agent routing): ' . self::json_encode_capped( $session_page, 800 );
			}

			$observations = isset( $client_context['observations'] ) && is_array( $client_context['observations'] )
				? $client_context['observations']
				: array();
			$bits         = array();
			foreach ( $observations as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$code   = isset( $row['code'] ) ? self::scrub_text( (string) $row['code'] ) : '';
				$detail = isset( $row['detail'] ) ? self::scrub_text( (string) $row['detail'] ) : '';
				if ( '' === $code && '' === $detail ) {
					continue;
				}
				$bits[] = '' !== $code ? ( $code . ': ' . $detail ) : $detail;
			}
			if ( ! empty( $bits ) ) {
				$lines[] = 'Observations: ' . implode( '; ', $bits );
			}

			$snap = isset( $client_context['editor_snapshot'] ) && is_array( $client_context['editor_snapshot'] )
				? self::scrub_assoc( $client_context['editor_snapshot'] )
				: array();
			if ( ! empty( $snap ) ) {
				$lines[] = 'Editor snapshot: ' . self::json_encode_capped( $snap, self::EDITOR_SNAPSHOT_PROMPT_MAX );
			}

			return implode( "\n", $lines );
		}

		/**
		 * JSON-encode an array and cap length.
		 *
		 * @param array $data Data.
		 * @param int   $max  Max chars.
		 * @return string
		 */
		private static function json_encode_capped( array $data, $max ) {
			if ( function_exists( 'wp_json_encode' ) ) {
				$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit bootstrap may lack wp_json_encode.
				$json = json_encode( $data, JSON_UNESCAPED_SLASHES );
			}
			if ( ! is_string( $json ) ) {
				return '{}';
			}
			$max = (int) $max;
			if ( $max > 0 && strlen( $json ) > $max ) {
				return substr( $json, 0, $max - 1 ) . '…';
			}
			return $json;
		}

		/**
		 * Parse model JSON into title + summary + optional hypothesis.
		 *
		 * @param string $text Model text.
		 * @return array|\WP_Error { title, summary, hypothesis }
		 */
		public static function decode_draft_payload( $text ) {
			$text = trim( (string) $text );
			if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
				$data = json_decode( $m[0], true );
				if ( is_array( $data ) && ! empty( $data['title'] ) && ! empty( $data['summary'] ) ) {
					$hypothesis = isset( $data['hypothesis'] ) ? (string) $data['hypothesis'] : '';
					$abilities  = array();
					if ( isset( $data['abilities'] ) && is_array( $data['abilities'] ) ) {
						foreach ( $data['abilities'] as $name ) {
							if ( ! is_string( $name ) || false === strpos( $name, '/' ) ) {
								continue;
							}
							$abilities[] = $name;
						}
					}
					return array(
						'title'      => substr( self::scrub_text( (string) $data['title'] ), 0, 200 ),
						'summary'    => substr( self::scrub_text( (string) $data['summary'] ), 0, 4000 ),
						'hypothesis' => substr( self::scrub_text( $hypothesis ), 0, self::HYPOTHESIS_MAX_LENGTH ),
						'abilities'  => $abilities,
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
		 * Search public GitHub issues labelled for this report kind (best-effort; may return null).
		 *
		 * @param string $query Extra search terms (already scrubbed).
		 * @param string $label run-feedback or run-success.
		 * @return int|null Proposed duplicate issue number or null.
		 */
		public static function find_duplicate_issue( $query = '', $label = self::LABEL_FAILURE ) {
			$q = self::duplicate_search_query( $query, $label );

			/**
			 * Short-circuit duplicate search (tests). Return issue number, null, or WP_Error.
			 *
			 * @param mixed  $pre   Null to continue.
			 * @param string $query Search query string.
			 */
			$pre = apply_filters( 'ahentic_pre_feedback_duplicate_search', null, $q );
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
		 * Require a usable session id for draft / file / submit.
		 *
		 * @param int $session_id Session ID.
		 * @return int|\WP_Error Session id or error.
		 */
		private static function require_session( $session_id ) {
			$session_id = (int) $session_id;
			if ( $session_id <= 0 || ! class_exists( 'Ahentic_Session_Repository' ) ) {
				return new WP_Error(
					'ahentic_feedback_bad_session',
					__( 'Invalid session for Run feedback.', 'ahentic' ),
					array( 'status' => 400 )
				);
			}
			return $session_id;
		}

		/**
		 * Client snapshot plus stored session page context for draft prompts.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $args       REST / submit args.
		 * @return array
		 */
		private static function client_context_from_args( $session_id, array $args ) {
			$client_context = array(
				'page_context'    => isset( $args['page_context'] ) && is_array( $args['page_context'] )
					? $args['page_context']
					: array(),
				'editor_snapshot' => isset( $args['editor_snapshot'] ) && is_array( $args['editor_snapshot'] )
					? $args['editor_snapshot']
					: array(),
				'observations'    => isset( $args['observations'] ) && is_array( $args['observations'] )
					? $args['observations']
					: array(),
			);

			$stored = Ahentic_Session_Repository::get_page_context( $session_id );
			if ( is_array( $stored ) && ! empty( $stored ) ) {
				$client_context['session_page_context'] = $stored;
			}
			return $client_context;
		}

		/**
		 * Site token, minting on first submit (Yes/No is consent).
		 *
		 * @return string|\WP_Error Site token or error.
		 */
		private static function ensure_enrolled_token() {
			$token = self::ensure_valid_token();
			if ( ! is_wp_error( $token ) ) {
				return $token;
			}
			if ( 'ahentic_feedback_no_token' !== $token->get_error_code() ) {
				return $token;
			}
			$minted = self::mint_site_token();
			if ( is_wp_error( $minted ) ) {
				return $minted;
			}
			return isset( $minted['site_token'] ) ? (string) $minted['site_token'] : '';
		}

		/**
		 * Draft title/summary (and hypothesis on failure) for a session (LLM). Does not file.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $args {
		 *     Client snapshot.
		 *     @type string $kind            success|failure (default failure).
		 *     @type string $user_note
		 *     @type array  $page_context
		 *     @type array  $editor_snapshot
		 *     @type array  $observations
		 * }
		 * @return array|\WP_Error { title, summary, hypothesis, abilities }
		 */
		public static function draft_report( $session_id, array $args = array() ) {
			$session_id = self::require_session( $session_id );
			if ( is_wp_error( $session_id ) ) {
				return $session_id;
			}

			$diagnostics = Ahentic_Session_Repository::to_diagnostics( $session_id );
			if ( is_wp_error( $diagnostics ) ) {
				return $diagnostics;
			}

			$user_note = isset( $args['user_note'] ) ? (string) $args['user_note'] : '';
			$kind      = self::normalize_report_kind( isset( $args['kind'] ) ? $args['kind'] : self::KIND_FAILURE );
			return self::draft_summary(
				$diagnostics,
				self::conversation_excerpt( $session_id ),
				$user_note,
				self::client_context_from_args( $session_id, $args ),
				$kind
			);
		}

		/**
		 * Mint if needed, draft (fallback on LLM failure), then file.
		 *
		 * Sidebar Yes/No is consent. Draft vs file stay distinct internally (ADR-0008).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $args {
		 *     Client snapshot.
		 *     @type string $kind            success|failure (default failure).
		 *     @type string $user_note
		 *     @type array  $page_context
		 *     @type array  $editor_snapshot
		 *     @type array  $observations
		 * }
		 * @return array|\WP_Error Intake response { action, number, html_url }.
		 */
		public static function submit_report( $session_id, array $args = array() ) {
			$session_id = self::require_session( $session_id );
			if ( is_wp_error( $session_id ) ) {
				return $session_id;
			}

			$enrolled = self::ensure_enrolled_token();
			if ( is_wp_error( $enrolled ) ) {
				return $enrolled;
			}

			$diagnostics = Ahentic_Session_Repository::to_diagnostics( $session_id );
			if ( is_wp_error( $diagnostics ) ) {
				return $diagnostics;
			}

			$prompt_excerpt = self::conversation_excerpt( $session_id );
			$kind           = self::normalize_report_kind( isset( $args['kind'] ) ? $args['kind'] : self::KIND_FAILURE );
			$user_note      = isset( $args['user_note'] ) ? (string) $args['user_note'] : '';
			$drafted        = self::draft_summary(
				$diagnostics,
				$prompt_excerpt,
				$user_note,
				self::client_context_from_args( $session_id, $args ),
				$kind
			);
			if ( is_wp_error( $drafted ) ) {
				$drafted              = self::fallback_draft_fields( $kind );
				$drafted['abilities'] = array();
			}

			$file_args                   = $args;
			$file_args['kind']           = $kind;
			$file_args['prompt_excerpt'] = $prompt_excerpt;
			if ( isset( $drafted['title'] ) && is_string( $drafted['title'] ) && '' !== trim( $drafted['title'] ) ) {
				$file_args['title'] = $drafted['title'];
			}
			if ( isset( $drafted['summary'] ) && is_string( $drafted['summary'] ) && '' !== trim( $drafted['summary'] ) ) {
				$file_args['summary'] = $drafted['summary'];
			}
			if ( isset( $drafted['hypothesis'] ) && is_string( $drafted['hypothesis'] ) && '' !== trim( $drafted['hypothesis'] ) ) {
				$file_args['hypothesis'] = $drafted['hypothesis'];
			}
			if ( isset( $drafted['abilities'] ) && is_array( $drafted['abilities'] ) ) {
				$file_args['abilities'] = $drafted['abilities'];
			}

			return self::file_loaded_report( $session_id, $diagnostics, $prompt_excerpt, $file_args );
		}

		/**
		 * File a report for a session (builds pack, ensures token, POSTs).
		 *
		 * Does not call the model. submit_report drafts then calls this path.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $args {
		 *     Optional overrides.
		 *     @type string     $kind            success|failure (default failure).
		 *     @type string     $title
		 *     @type string     $summary
		 *     @type string     $hypothesis
		 *     @type string     $prompt_excerpt Optional override for the conversation list.
		 *     @type string     $user_note
		 *     @type array      $page_context
		 *     @type array      $editor_snapshot
		 *     @type array      $observations
		 *     @type array      $abilities
		 *     @type array      $playbook_ids
		 *     @type int|null   $duplicate_of
		 * }
		 * @return array|\WP_Error Intake response { action, number, html_url }.
		 */
		public static function file_report( $session_id, array $args = array() ) {
			$session_id = self::require_session( $session_id );
			if ( is_wp_error( $session_id ) ) {
				return $session_id;
			}

			$diagnostics = Ahentic_Session_Repository::to_diagnostics( $session_id );
			if ( is_wp_error( $diagnostics ) ) {
				return $diagnostics;
			}

			$prompt_excerpt = isset( $args['prompt_excerpt'] ) ? (string) $args['prompt_excerpt'] : '';
			if ( '' === $prompt_excerpt ) {
				$prompt_excerpt = self::conversation_excerpt( $session_id );
			}

			return self::file_loaded_report( $session_id, $diagnostics, $prompt_excerpt, $args );
		}

		/**
		 * File using already-loaded diagnostics (no second session read, no LLM).
		 *
		 * @param int    $session_id     Session ID.
		 * @param array  $diagnostics    From to_diagnostics().
		 * @param string $prompt_excerpt Conversation list.
		 * @param array  $args           File args (kind, title, snapshot, ...).
		 * @return array|\WP_Error Intake response { action, number, html_url }.
		 */
		private static function file_loaded_report( $session_id, array $diagnostics, $prompt_excerpt, array $args ) {
			$title      = isset( $args['title'] ) ? (string) $args['title'] : '';
			$summary    = isset( $args['summary'] ) ? (string) $args['summary'] : '';
			$hypothesis = isset( $args['hypothesis'] ) ? (string) $args['hypothesis'] : '';
			$user_note  = isset( $args['user_note'] ) ? (string) $args['user_note'] : '';
			$kind       = self::normalize_report_kind( isset( $args['kind'] ) ? $args['kind'] : self::KIND_FAILURE );
			if ( '' === $title || '' === $summary ) {
				$fallback = self::fallback_draft_fields( $kind );
				if ( '' === $title ) {
					$title = $fallback['title'];
				}
				if ( '' === $summary ) {
					$summary = $fallback['summary'];
				}
			}

			$summary = self::append_user_note_to_summary( $summary, $user_note );
			if ( self::KIND_SUCCESS !== $kind ) {
				$summary = self::append_hypothesis_to_summary( $summary, $hypothesis );
			}

			$debug_pack = '';
			if ( self::KIND_SUCCESS !== $kind ) {
				$page = isset( $args['page_context'] ) && is_array( $args['page_context'] )
					? self::slim_page_context_for_feedback( $args['page_context'] )
					: array();
				if ( empty( $page ) ) {
					$page = self::slim_page_context_for_feedback(
						Ahentic_Session_Repository::get_page_context( $session_id )
					);
				}
				$diagnostics['page']            = $page;
				$diagnostics['editor_snapshot'] = isset( $args['editor_snapshot'] ) && is_array( $args['editor_snapshot'] )
					? $args['editor_snapshot']
					: array();
				$diagnostics['observations']    = isset( $args['observations'] ) && is_array( $args['observations'] )
					? $args['observations']
					: array();
				$diagnostics['hypothesis']      = substr( self::scrub_text( $hypothesis ), 0, self::HYPOTHESIS_MAX_LENGTH );
				$debug_pack                     = self::build_debug_pack( $diagnostics );
			}

			$token = self::ensure_valid_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$duplicate_of = array_key_exists( 'duplicate_of', $args ) ? $args['duplicate_of'] : null;
			if ( null === $duplicate_of ) {
				$duplicate_of = self::find_duplicate_issue( $title, self::report_label_for_kind( $kind ) );
			}

			$env          = isset( $diagnostics['environment'] ) && is_array( $diagnostics['environment'] ) ? $diagnostics['environment'] : array();
			$trace_names  = self::abilities_from_trace( isset( $diagnostics['trace'] ) ? $diagnostics['trace'] : array() );
			$abilities    = self::resolve_abilities_mentioned(
				isset( $args['abilities'] ) ? $args['abilities'] : array(),
				$trace_names
			);
			$playbook_ids = self::sanitize_playbook_ids( isset( $args['playbook_ids'] ) ? $args['playbook_ids'] : array() );

			$body               = self::build_intake_report_body(
				array(
					'kind'                => $kind,
					'title'               => substr( self::scrub_text( $title ), 0, 200 ),
					'summary'             => substr( self::scrub_text( $summary ), 0, 4000 ),
					'debug_pack'          => $debug_pack,
					'prompt_excerpt'      => substr( self::scrub_text( $prompt_excerpt ), 0, self::PROMPT_EXCERPT_MAX_LENGTH ),
					'duplicate_of'        => is_int( $duplicate_of ) ? $duplicate_of : null,
					'ahentic_version'     => defined( 'AHENTIC_VERSION' ) ? AHENTIC_VERSION : '',
					'wp_version'          => isset( $env['wp'] ) ? (string) $env['wp'] : get_bloginfo( 'version' ),
					'abilities_mentioned' => $abilities,
					'playbook_ids'        => $playbook_ids,
					'client'              => array(
						'php_version' => isset( $env['php'] ) ? (string) $env['php'] : PHP_VERSION,
						'ai_client'   => isset( $env['aiClient'] ) ? (string) $env['aiClient'] : '',
					),
				)
			);
			$body['site_token'] = $token;

			$result = self::request( '/v1/reports', $body );
			if ( is_wp_error( $result ) ) {
				// Token expired mid-flight - try one silent refresh then retry once.
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
		 * Scrubbed conversation list for the report (all user + Ahentic turns).
		 *
		 * @param int $session_id Session ID.
		 * @return string Markdown table, or empty when no chat turns exist.
		 */
		private static function conversation_excerpt( $session_id ) {
			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			if ( ! is_array( $entries ) ) {
				return '';
			}
			return self::format_conversation_excerpt( $entries );
		}

		/**
		 * Format session entries into a scrubbed markdown conversation table.
		 *
		 * Columns: entity | prompt/reply. Includes user prompts and Ahentic
		 * replies (skips tool/event and thought-process / intermediate assistant
		 * rows). Long Ahentic replies are summarized extractively. Prefer recent
		 * turns when over budget.
		 *
		 * @param array $entries Session entries from the repository.
		 * @return string
		 */
		public static function format_conversation_excerpt( array $entries ) {
			$turns = array();
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$role = isset( $entry['role'] ) ? (string) $entry['role'] : '';
				if ( 'user' !== $role && 'assistant' !== $role ) {
					continue;
				}
				$meta = isset( $entry['meta'] ) && is_array( $entry['meta'] ) ? $entry['meta'] : array();
				if ( ! empty( $meta['thought_process'] ) || ! empty( $meta['intermediate'] ) ) {
					continue;
				}
				$content = isset( $entry['content'] ) ? trim( (string) $entry['content'] ) : '';
				if ( '' === $content ) {
					continue;
				}
				$content = self::scrub_text( $content );
				if ( 'assistant' === $role ) {
					$content = self::summarize_assistant_reply_for_feedback( $content );
				} else {
					$content = self::excerpt_text( $content, self::USER_TURN_MAX_LENGTH );
				}
				if ( '' === $content ) {
					continue;
				}
				$turns[] = array(
					'entity'  => ( 'user' === $role ) ? 'User' : 'Ahentic',
					'content' => $content,
				);
			}

			if ( empty( $turns ) ) {
				return '';
			}

			$selected = $turns;
			while ( true ) {
				$formatted = self::table_conversation_turns( $selected );
				if ( strlen( $formatted ) <= self::PROMPT_EXCERPT_MAX_LENGTH || count( $selected ) <= 1 ) {
					if ( strlen( $formatted ) > self::PROMPT_EXCERPT_MAX_LENGTH ) {
						return substr( $formatted, 0, self::PROMPT_EXCERPT_MAX_LENGTH );
					}
					return $formatted;
				}
				array_shift( $selected );
			}
		}

		/**
		 * Render conversation turns as a two-column markdown table.
		 *
		 * @param array[] $turns Rows with entity + content keys.
		 * @return string
		 */
		private static function table_conversation_turns( array $turns ) {
			$out   = array();
			$out[] = '| entity | prompt/reply |';
			$out[] = '| --- | --- |';
			foreach ( $turns as $turn ) {
				$entity  = self::escape_markdown_table_cell( (string) $turn['entity'] );
				$content = self::escape_markdown_table_cell( (string) $turn['content'] );
				$out[]   = '| ' . $entity . ' | ' . $content . ' |';
			}
			return implode( "\n", $out );
		}

		/**
		 * Keep markdown table cells on one line without breaking column pipes.
		 *
		 * @param string $text Cell text (already scrubbed).
		 * @return string
		 */
		private static function escape_markdown_table_cell( $text ) {
			$text = preg_replace( '/\s+/u', ' ', trim( (string) $text ) );
			if ( ! is_string( $text ) ) {
				$text = '';
			}
			return str_replace( '|', '\\|', $text );
		}

		/**
		 * Summarize a long Ahentic reply for the conversation list.
		 *
		 * Uses leading sentence(s) when possible; otherwise a word-boundary excerpt.
		 *
		 * @param string $content Already-scrubbed reply text.
		 * @return string
		 */
		private static function summarize_assistant_reply_for_feedback( $content ) {
			$content = trim( (string) $content );
			if ( strlen( $content ) <= self::ASSISTANT_REPLY_SUMMARIZE_AFTER ) {
				return $content;
			}

			$max = self::ASSISTANT_REPLY_SUMMARY_MAX;
			if ( preg_match( '/^(.+?[.!?])(?:\s+|$)/us', $content, $m ) ) {
				$summary = trim( $m[1] );
				$rest    = ltrim( substr( $content, strlen( $m[0] ) ) );
				if ( strlen( $summary ) < ( $max - 40 ) && '' !== $rest
					&& preg_match( '/^(.+?[.!?])(?:\s+|$)/us', $rest, $m2 ) ) {
					$candidate = $summary . ' ' . trim( $m2[1] );
					if ( strlen( $candidate ) <= $max ) {
						$summary = $candidate;
					}
				}
				if ( strlen( $summary ) <= $max ) {
					return $summary . '…';
				}
			}

			return self::excerpt_text( $content, $max );
		}

		/**
		 * Truncate text at a word boundary with an ellipsis.
		 *
		 * @param string $text Text.
		 * @param int    $max  Max length including ellipsis.
		 * @return string
		 */
		private static function excerpt_text( $text, $max ) {
			$text = (string) $text;
			$max  = (int) $max;
			if ( $max <= 1 || strlen( $text ) <= $max ) {
				return $text;
			}
			$slice = substr( $text, 0, $max - 1 );
			// Prefer breaking on whitespace so we do not clip mid-word.
			if ( preg_match( '/^(.+\S)\s+\S*$/s', $slice, $m ) && strlen( $m[1] ) >= (int) ( $max * 0.5 ) ) {
				$slice = $m[1];
			}
			return rtrim( $slice ) . '…';
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
				$name = self::ability_name_from_trace_data( $data );
				if ( '' !== $name ) {
					$names[ $name ] = true;
				}
			}
			return array_keys( $names );
		}

		/**
		 * First namespaced ability/tool name on a trace event's data bag.
		 *
		 * @param array $data Event data.
		 * @return string Empty when none.
		 */
		private static function ability_name_from_trace_data( array $data ) {
			foreach ( array( 'ability', 'name', 'tool' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) && false !== strpos( $data[ $key ], '/' ) ) {
					return $data[ $key ];
				}
			}
			return '';
		}
	}
}
