<?php
/**
 * Finish / verify gate for agent completion (ADR-0003).
 *
 * Owns thin-body assessment at tool time and the decide-before-idle path
 * (unapplied artifacts → forced apply, verify repair think, honest partial).
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Finish_Gate' ) ) {
	/**
	 * Deep module: may this Session idle with this reply?
	 *
	 * Primary interface: evaluate_reply() and assess_write_payload().
	 * Pure helpers (planned_includes_artifact_apply, forced_apply_tools_for_context,
	 * payload_body_is_thin) are part of the test surface.
	 */
	class Ahentic_Finish_Gate {

		/** Max repair-think cycles for a thin body before an honest partial finish. */
		const MAX_VERIFY_ATTEMPTS = 1;

		/** Plain-text characters a long-form body must reach before the agent may finish. */
		const LONG_FORM_MIN_CHARS = 2000;

		/**
		 * Decide whether the run may idle after a reply (Agent mode gates).
		 *
		 * Side effects on continue: stash pending final, set forced tools, progress/thought/traces.
		 * On idle: may rewrite result/debug for an honest partial finish.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $result     LLM result.
		 * @param array $debug      Control-block debug.
		 * @return array {
		 *   @type bool  $continue True when another step should run.
		 *   @type array $result   Possibly adjusted LLM result (for idle path).
		 *   @type array $debug    Possibly adjusted debug (for idle path).
		 * }
		 */
		public static function evaluate_reply( $session_id, array $result, $debug = array() ) {
			$session_id = (int) $session_id;
			$debug      = is_array( $debug ) ? $debug : array();
			$mode       = Ahentic_Session_Repository::get_mode( $session_id );

			if ( 'agent' === $mode ) {
				$unapplied = self::ready_unapplied_content_artifacts( $session_id );
				if ( ! empty( $unapplied ) ) {
					self::stash_pending_final( $session_id, $result, $debug );
					$apply_tools = self::build_forced_apply_tools( $session_id, $unapplied );
					if ( ! empty( $apply_tools ) ) {
						Ahentic_Session_Repository::set_forced_tools( $session_id, $apply_tools );
					}
					$keys = implode( ', ', $unapplied );
					Ahentic_Session_Repository::set_progress(
						$session_id,
						__( 'Applying staged draft…', 'ahentic' )
					);
					Ahentic_Session_Repository::set_thought(
						$session_id,
						sprintf(
							/* translators: %s: artifact keys */
							__( 'A draft is staged (%s) but not applied yet — applying via from_memory before finishing.', 'ahentic' ),
							$keys
						)
					);
					Ahentic_Session_Repository::append_trace(
						$session_id,
						'apply_required',
						'Ready artifacts not applied — continuing',
						array(
							'keys'  => $unapplied,
							'tools' => $apply_tools,
						),
						(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
					);
					return array(
						'continue' => true,
						'result'   => $result,
						'debug'    => $debug,
					);
				}

				if ( ! empty( Ahentic_Session_Repository::get_verify_pending( $session_id ) ) ) {
					$gate = self::run_verification_gate( $session_id, $result, $debug );
					if ( 'continue' === $gate ) {
						return array(
							'continue' => true,
							'result'   => $result,
							'debug'    => $debug,
						);
					}
					if ( is_array( $gate ) && isset( $gate['result'] ) ) {
						$result = $gate['result'];
						$debug  = isset( $gate['debug'] ) ? $gate['debug'] : $debug;
					}
				}
			}

			return array(
				'continue' => false,
				'result'   => $result,
				'debug'    => $debug,
			);
		}

		/**
		 * Mark thin writes / advance plan after a successful mutate (ADR-0003).
		 *
		 * @param int    $session_id Session ID.
		 * @param string $name       Ability.
		 * @param mixed  $payload    Tool payload.
		 * @param bool   $ok         Whether the tool succeeded.
		 * @return mixed
		 */
		public static function assess_write_payload( $session_id, $name, $payload, $ok ) {
			if ( ! $ok || ! class_exists( 'Ahentic_Abilities' ) ) {
				return $payload;
			}
			$name = (string) $name;
			if ( Ahentic_Abilities::is_readonly( $name ) ) {
				return $payload;
			}
			if ( class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::is_artifact_ability( $name ) ) {
				return $payload;
			}

			Ahentic_Orchestrator::advance_plan_after_tool( $session_id, $name );

			if ( ! is_array( $payload ) || ! self::ability_writes_body( $name ) ) {
				return $payload;
			}
			if ( ! class_exists( 'Ahentic_Session_Artifacts' ) || ! Ahentic_Session_Artifacts::session_has_content_work( $session_id ) ) {
				return $payload;
			}

			$min_chars = self::LONG_FORM_MIN_CHARS;
			$chars     = self::body_chars_from_write_payload( $payload );
			$target    = self::write_target_key( $name, $payload );

			// A later write to the same document supersedes what an earlier one reported.
			$findings = array();
			foreach ( Ahentic_Session_Repository::get_verify_pending( $session_id ) as $item ) {
				if ( isset( $item['target'] ) && (string) $item['target'] === $target ) {
					continue;
				}
				$findings[] = $item;
			}

			$thin = self::payload_body_is_thin( $payload );

			if ( $thin ) {
				$payload['thin']        = true;
				$payload['thin_reason'] = sprintf(
					/* translators: 1: measured characters, 2: required characters */
					__( 'This document holds %1$d characters of text; the long-form work requested needs at least %2$d. Keep writing — expand it with real sections instead of replying.', 'ahentic' ),
					max( 0, $chars ),
					$min_chars
				);
				$findings[] = array(
					'ability' => $name,
					'target'  => $target,
					'at'      => gmdate( 'c' ),
					'chars'   => max( 0, $chars ),
				);
			}

			Ahentic_Session_Repository::set_verify_pending( $session_id, $findings );

			if ( $thin ) {
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'verify_thin',
					sprintf( 'Thin body after %s', $name ),
					array(
						'ability' => $name,
						'target'  => $target,
						'chars'   => max( 0, $chars ),
						'minimum' => $min_chars,
					),
					(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
				);
			}

			return $payload;
		}

		/**
		 * Content artifacts that are ready but not yet applied.
		 *
		 * @param int $session_id Session ID.
		 * @return array<int, string>
		 */
		public static function ready_unapplied_content_artifacts( $session_id ) {
			if ( ! class_exists( 'Ahentic_Session_Artifacts' ) ) {
				return array();
			}
			$content_kinds = array(
				Ahentic_Session_Artifacts::KIND_BLOCKS,
				Ahentic_Session_Artifacts::KIND_HTML,
				Ahentic_Session_Artifacts::KIND_MARKDOWN,
				Ahentic_Session_Artifacts::KIND_POST_CONTENT,
			);
			$keys = array();
			foreach ( Ahentic_Session_Artifacts::list_pointers( $session_id ) as $p ) {
				$status = isset( $p['status'] ) ? (string) $p['status'] : '';
				$kind   = isset( $p['kind'] ) ? (string) $p['kind'] : '';
				$key    = isset( $p['key'] ) ? (string) $p['key'] : '';
				if ( '' === $key || Ahentic_Session_Artifacts::STATUS_READY !== $status ) {
					continue;
				}
				if ( ! in_array( $kind, $content_kinds, true ) ) {
					continue;
				}
				$keys[] = $key;
			}
			return $keys;
		}

		/**
		 * Forced mutate tools for the first ready content artifact (uses session page context).
		 *
		 * @param int                $session_id Session ID.
		 * @param array<int, string> $keys       Ready artifact keys.
		 * @return array<int, array{name: string, input: array}>
		 */
		public static function build_forced_apply_tools( $session_id, array $keys ) {
			$ctx = Ahentic_Session_Repository::get_page_context( $session_id );
			return self::forced_apply_tools_for_context( $keys, is_array( $ctx ) ? $ctx : array() );
		}

		/**
		 * Forced apply tools from an explicit page-context snapshot (pure / testable).
		 *
		 * @param array<int, string> $keys Ready artifact keys.
		 * @param array              $ctx  Page context.
		 * @return array<int, array{name: string, input: array}>
		 */
		public static function forced_apply_tools_for_context( array $keys, array $ctx ) {
			if ( empty( $keys ) ) {
				return array();
			}
			$key         = (string) $keys[0];
			$editor_open = ! empty( $ctx['is_block_editor'] );
			$post_id     = ! empty( $ctx['post_id'] ) ? (int) $ctx['post_id'] : 0;

			if ( $editor_open && class_exists( 'Ahentic_Abilities_Browser' ) ) {
				return array(
					array(
						'name'  => Ahentic_Abilities_Browser::SET_BLOCKS,
						'input' => array( 'from_memory' => $key ),
					),
				);
			}

			if ( $post_id > 0 && class_exists( 'Ahentic_Abilities_Content' ) ) {
				return array(
					array(
						'name'  => Ahentic_Abilities_Content::UPDATE,
						'input' => array(
							'id'          => $post_id,
							'from_memory' => $key,
						),
					),
				);
			}

			if ( class_exists( 'Ahentic_Abilities_Content' ) ) {
				return array(
					array(
						'name'  => Ahentic_Abilities_Content::CREATE,
						'input' => array( 'from_memory' => $key ),
					),
				);
			}

			return array();
		}

		/**
		 * Whether this tool batch already applies one of the ready artifact keys.
		 *
		 * @param array              $planned Tool calls.
		 * @param array<int, string> $keys    Ready keys.
		 * @return bool
		 */
		public static function planned_includes_artifact_apply( array $planned, array $keys ) {
			$key_lookup = array();
			foreach ( $keys as $k ) {
				$key_lookup[ (string) $k ] = true;
			}
			$apply_names = array();
			if ( class_exists( 'Ahentic_Abilities_Browser' ) ) {
				$apply_names[] = Ahentic_Abilities_Browser::SET_BLOCKS;
			}
			if ( class_exists( 'Ahentic_Abilities_Content' ) ) {
				$apply_names[] = Ahentic_Abilities_Content::CREATE;
				$apply_names[] = Ahentic_Abilities_Content::UPDATE;
			}
			foreach ( $planned as $call ) {
				if ( ! is_array( $call ) ) {
					continue;
				}
				$name  = isset( $call['name'] ) ? (string) $call['name'] : '';
				$input = isset( $call['input'] ) && is_array( $call['input'] ) ? $call['input'] : array();
				if ( ! in_array( $name, $apply_names, true ) ) {
					continue;
				}
				$mem = isset( $input['from_memory'] ) ? (string) $input['from_memory'] : '';
				if ( '' !== $mem && isset( $key_lookup[ $mem ] ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether a write payload's body is too thin for long-form work.
		 *
		 * @param array $payload Write payload.
		 * @return bool
		 */
		public static function payload_body_is_thin( array $payload ) {
			$chars = self::body_chars_from_write_payload( $payload );
			return ( $chars >= 0 && $chars < self::LONG_FORM_MIN_CHARS )
				|| self::write_payload_looks_like_placeholder( $payload );
		}

		/**
		 * Plain-text size of the document a write left behind, or -1 when unknown.
		 *
		 * @param array $payload Payload.
		 * @return int
		 */
		public static function body_chars_from_write_payload( array $payload ) {
			$sources = array( $payload );
			if ( isset( $payload['post'] ) && is_array( $payload['post'] ) ) {
				$sources[] = $payload['post'];
			}
			foreach ( $sources as $source ) {
				if ( isset( $source['text_chars'] ) ) {
					return (int) $source['text_chars'];
				}
				if ( isset( $source['content_text_chars'] ) ) {
					return (int) $source['content_text_chars'];
				}
			}
			return -1;
		}

		/**
		 * Leading placeholder prose in the body a write reported back.
		 *
		 * @param array $payload Payload.
		 * @return bool
		 */
		public static function write_payload_looks_like_placeholder( array $payload ) {
			$preview = '';
			if ( isset( $payload['content_preview'] ) ) {
				$preview = (string) $payload['content_preview'];
			} elseif ( isset( $payload['post']['content_preview'] ) ) {
				$preview = (string) $payload['post']['content_preview'];
			}
			if ( '' === trim( $preview ) ) {
				return false;
			}
			$stripped = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $preview ) : strip_tags( $preview );
			return (bool) preg_match(
				'/^\s*(lorem ipsum|placeholder|\[full article\]|todo:?\s*write|coming soon)/i',
				$stripped
			);
		}

		/**
		 * Stash candidate closing prose so verify/apply continues do not drop it.
		 *
		 * @param int   $session_id Session ID.
		 * @param array $result     LLM result.
		 * @param array $debug      Debug meta.
		 */
		public static function stash_pending_final( $session_id, array $result, $debug = array() ) {
			$text = isset( $result['text'] ) ? trim( (string) $result['text'] ) : '';
			if ( ( '' === $text || self::reply_looks_like_process( $text ) ) && is_array( $debug ) && class_exists( 'Ahentic_AI' ) ) {
				$fallback = trim( (string) Ahentic_AI::fallback_reply_from_debug( $debug ) );
				if ( '' !== $fallback && ! self::reply_looks_like_process( $fallback ) ) {
					$text = $fallback;
				}
			}
			if ( '' === $text || self::reply_looks_like_process( $text ) ) {
				return;
			}

			$existing = Ahentic_Session_Repository::get_pending_final( $session_id );
			if ( is_array( $existing ) && ! empty( $existing['text'] ) ) {
				if ( strlen( $text ) < strlen( (string) $existing['text'] ) ) {
					return;
				}
			}

			Ahentic_Session_Repository::set_pending_final(
				$session_id,
				array(
					'text'  => $text,
					'model' => isset( $result['model'] ) ? (string) $result['model'] : '',
					'debug' => is_array( $debug ) ? $debug : array(),
				)
			);
		}

		/**
		 * @param int   $session_id Session ID.
		 * @param array $result     Pending final reply.
		 * @param array $debug      Debug.
		 * @return string|array 'continue' or { result, debug }
		 */
		private static function run_verification_gate( $session_id, array $result, $debug ) {
			$findings = Ahentic_Session_Repository::get_verify_pending( $session_id );
			if ( empty( $findings ) ) {
				return array(
					'result' => $result,
					'debug'  => $debug,
				);
			}

			self::stash_pending_final( $session_id, $result, $debug );

			$attempts = Ahentic_Session_Repository::bump_verify_attempts( $session_id );
			$step     = (int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true );

			if ( $attempts > self::MAX_VERIFY_ATTEMPTS ) {
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'verify_partial',
					'Body still thin after a repair attempt — honest partial finish',
					array(
						'findings' => $findings,
						'attempts' => $attempts,
					),
					$step
				);
				Ahentic_Session_Repository::clear_verify_pending( $session_id );
				Ahentic_Session_Repository::clear_forced_tools( $session_id );

				$stashed = Ahentic_Session_Repository::get_pending_final( $session_id );
				$msg     = __(
					'I applied a draft, but the body still looks thin or like a placeholder. Send Continue and I’ll expand it.',
					'ahentic'
				);
				if ( is_array( $stashed ) && ! empty( $stashed['text'] ) && ! self::reply_looks_like_process( (string) $stashed['text'] ) ) {
					$result['text'] = trim( (string) $stashed['text'] ) . "\n\n" . $msg;
				} else {
					$result['text'] = $msg;
				}

				return array(
					'result' => $result,
					'debug'  => $debug,
				);
			}

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'verify_required',
				'Thin body — continuing to expand',
				array(
					'findings' => $findings,
					'attempts' => $attempts,
				),
				$step
			);
			Ahentic_Session_Repository::set_progress( $session_id, __( 'Expanding draft…', 'ahentic' ), $step );
			Ahentic_Session_Repository::set_thought(
				$session_id,
				__( 'The body written so far is too thin for long-form work — expanding it before finishing.', 'ahentic' )
			);
			return 'continue';
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		private static function ability_writes_body( $name ) {
			return in_array(
				(string) $name,
				array(
					'ahentic/create-post',
					'ahentic/update-post',
					'ahentic-browser/set-blocks',
					'ahentic-browser/insert-blocks',
					'ahentic-browser/replace-blocks',
					'ahentic-browser/delete-blocks',
				),
				true
			);
		}

		/**
		 * @param string $name    Ability.
		 * @param array  $payload Payload.
		 * @return string
		 */
		private static function write_target_key( $name, array $payload ) {
			if ( 0 === strpos( (string) $name, 'ahentic-browser/' ) ) {
				return 'editor';
			}
			$post_id = 0;
			if ( ! empty( $payload['id'] ) ) {
				$post_id = (int) $payload['id'];
			} elseif ( ! empty( $payload['post_id'] ) ) {
				$post_id = (int) $payload['post_id'];
			}
			return 'post:' . $post_id;
		}

		/**
		 * @param string $text Text.
		 * @return bool
		 */
		private static function reply_looks_like_process( $text ) {
			if ( class_exists( 'Ahentic_AI' ) ) {
				return Ahentic_AI::text_looks_like_process( $text );
			}
			return '' === trim( (string) $text );
		}
	}
}
