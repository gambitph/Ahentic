<?php
/**
 * Build public X (Twitter) capability-request payloads for missing abilities.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Capability_Request' ) ) {
	/**
	 * Formats missing-ability tweets and intent URLs.
	 */
	class Ahentic_Capability_Request {
		const HANDLE  = 'wpahentic';
		const HASHTAG = 'ahenticrequest';

		/**
		 * Build a request card payload.
		 *
		 * Uses a short LLM call to turn the raw user ask into a clean action phrase.
		 *
		 * @param string $ability      Ability name (e.g. ahentic/delete-posts).
		 * @param string $raw_context  User message and/or intention (not used verbatim in the tweet).
		 * @return array
		 */
		public static function build( $ability, $raw_context = '' ) {
			$ability = (string) $ability;
			$label   = self::humanize_ability( $ability );
			$raw     = self::sanitize_raw_context( $raw_context );

			$summary = self::summarize_action( $ability, $label, $raw );
			$goal    = isset( $summary['text'] ) ? (string) $summary['text'] : '';

			if ( '' === $goal ) {
				$goal = sprintf(
					/* translators: %s: ability label */
					__( 'use %s on my WordPress site', 'ahentic' ),
					$label
				);
			}

			$tweet = self::compose_tweet( $label, $goal );
			$query = rawurlencode( $tweet );
			$url   = 'https://x.com/intent/tweet?text=' . $query;

			return array(
				'ability'       => $ability,
				'ability_label' => $label,
				'goal'          => $goal,
				'goal_raw'      => $raw,
				'tweet_text'    => $tweet,
				'intent_url'    => $url,
				'handle'        => self::HANDLE,
				'hashtag'       => self::HASHTAG,
				'tokens_in'     => isset( $summary['tokens_in'] ) ? (int) $summary['tokens_in'] : 0,
				'tokens_out'    => isset( $summary['tokens_out'] ) ? (int) $summary['tokens_out'] : 0,
				'tokens_total'  => isset( $summary['tokens_total'] ) ? (int) $summary['tokens_total'] : 0,
			);
		}

		/**
		 * Short LLM (or heuristic) summary of the action the user wanted.
		 *
		 * @param string $ability Ability id.
		 * @param string $label   Human label.
		 * @param string $raw     Raw context.
		 * @return array{text: string, tokens_in: int, tokens_out: int, tokens_total: int}
		 */
		public static function summarize_action( $ability, $label, $raw ) {
			$fallback = self::fallback_action_phrase( $label, $raw );
			$empty    = array(
				'text'         => $fallback,
				'tokens_in'    => 0,
				'tokens_out'   => 0,
				'tokens_total' => 0,
			);

			if ( '' === trim( (string) $raw ) ) {
				return $empty;
			}

			if ( ! class_exists( 'Ahentic_AI' ) || ! Ahentic_AI::is_available() ) {
				return $empty;
			}

			$system = 'You write short public action phrases for feature-request tweets. '
				. 'Reply with ONLY a concise verb phrase (about 3–10 words) describing what the user wanted to do. '
				. 'Start with a verb. No quotes, no hashtags, no @mentions, no trailing punctuation, no preamble. '
				. 'Examples: delete all test posts | find unused media library images | install an SEO plugin | rewrite my homepage hero';

			$user = "Missing WordPress ability: {$label} ({$ability})\n\n"
				. "Context from the session:\n{$raw}\n\n"
				. 'Action phrase:';

			$result = Ahentic_AI::complete_text( $system, $user );
			if ( is_wp_error( $result ) || empty( $result['text'] ) ) {
				return $empty;
			}

			$phrase = self::sanitize_action_phrase( (string) $result['text'], $fallback );

			return array(
				'text'         => $phrase,
				'tokens_in'    => isset( $result['tokens_in'] ) ? (int) $result['tokens_in'] : 0,
				'tokens_out'   => isset( $result['tokens_out'] ) ? (int) $result['tokens_out'] : 0,
				'tokens_total' => isset( $result['tokens_total'] ) ? (int) $result['tokens_total'] : 0,
			);
		}

		/**
		 * Compose the public tweet body (kept under a safe length).
		 *
		 * @param string $label Human ability label.
		 * @param string $goal  Short action phrase.
		 * @return string
		 */
		public static function compose_tweet( $label, $goal ) {
			$label = trim( (string) $label );
			$goal  = trim( (string) $goal );

			$prefix = sprintf(
				'Hey @%s please make a %s ability, I need it to ',
				self::HANDLE,
				$label
			);
			$suffix = ' #' . self::HASHTAG;

			$max_goal = 260 - strlen( $prefix ) - strlen( $suffix );
			if ( $max_goal < 24 ) {
				$max_goal = 24;
			}

			if ( strlen( $goal ) > $max_goal ) {
				$goal = rtrim( substr( $goal, 0, $max_goal - 1 ) ) . '…';
			}

			return $prefix . $goal . $suffix;
		}

		/**
		 * Turn ahentic/delete-posts into "delete posts".
		 *
		 * @param string $ability Ability name.
		 * @return string
		 */
		public static function humanize_ability( $ability ) {
			$ability = (string) $ability;
			$slug    = preg_replace( '#^[^/]+/#', '', $ability );
			$slug    = is_string( $slug ) ? $slug : $ability;
			$slug    = str_replace( array( '-', '_' ), ' ', $slug );
			$slug    = trim( preg_replace( '/\s+/', ' ', $slug ) );
			return $slug !== '' ? $slug : __( 'new', 'ahentic' );
		}

		/**
		 * Heuristic phrase when the summarizer is unavailable.
		 *
		 * @param string $label Ability label.
		 * @param string $raw   Raw context.
		 * @return string
		 */
		private static function fallback_action_phrase( $label, $raw ) {
			$text = (string) $raw;
			// Prefer the "User:" line when we pass structured context.
			if ( preg_match( '/(?:^|\n)User:\s*(.+?)(?=\n[A-Za-z][\w ]*:|$)/s', $text, $m ) ) {
				$text = trim( $m[1] );
			}

			$text = wp_strip_all_tags( $text );
			$text = preg_replace( '/\s+/', ' ', $text );
			$text = trim( (string) $text );

			// Drop leading politeness / filler.
			$text = preg_replace(
				'/^(please|pls|can you|could you|would you|i want to|i need to|i\'d like to|im trying to|i\'m trying to)\s+/i',
				'',
				$text
			);
			$text = trim( (string) $text );

			// First clause only.
			if ( preg_match( '/^(.+?)[.?!]/', $text, $m ) ) {
				$text = trim( $m[1] );
			}

			if ( strlen( $text ) > 80 ) {
				$text = rtrim( substr( $text, 0, 79 ) ) . '…';
			}

			if ( '' === $text ) {
				return sprintf(
					/* translators: %s: ability label */
					__( 'use %s on my site', 'ahentic' ),
					$label
				);
			}

			return lcfirst( $text );
		}

		/**
		 * Clean model output into a tweet-safe action phrase.
		 *
		 * @param string $phrase   Model text.
		 * @param string $fallback Fallback.
		 * @return string
		 */
		private static function sanitize_action_phrase( $phrase, $fallback ) {
			$phrase = wp_strip_all_tags( (string) $phrase );
			$phrase = preg_replace( '/\s+/', ' ', $phrase );
			$phrase = trim( (string) $phrase );
			$phrase = trim( $phrase, " \t\n\r\0\x0B\"'`“”‘’" );

			// If the model ignored instructions and returned a paragraph, take first line/sentence.
			if ( false !== strpos( $phrase, "\n" ) ) {
				$parts  = preg_split( '/\R/', $phrase );
				$phrase = is_array( $parts ) && isset( $parts[0] ) ? trim( $parts[0] ) : $phrase;
			}
			if ( preg_match( '/^(.+?)[.?!]/', $phrase, $m ) && strlen( $m[1] ) >= 8 ) {
				$phrase = trim( $m[1] );
			}

			$phrase = preg_replace( '/^action phrase:\s*/i', '', $phrase );
			$phrase = trim( (string) $phrase );

			if ( strlen( $phrase ) < 3 || strlen( $phrase ) > 100 ) {
				return $fallback;
			}

			return lcfirst( $phrase );
		}

		/**
		 * @param string $raw Raw context.
		 * @return string
		 */
		private static function sanitize_raw_context( $raw ) {
			$raw = wp_strip_all_tags( (string) $raw );
			$raw = preg_replace( "/[ \t]+/", ' ', $raw );
			$raw = preg_replace( "/\r\n?/", "\n", $raw );
			$raw = preg_replace( "/\n{3,}/", "\n\n", $raw );
			$raw = trim( (string) $raw );
			if ( strlen( $raw ) > 600 ) {
				$raw = rtrim( substr( $raw, 0, 599 ) ) . '…';
			}
			return $raw;
		}
	}
}
