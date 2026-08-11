<?php
/**
 * Prompt / chat-payload assembler for the agent think phase.
 *
 * Owns system prompt text, history compaction, and user-turn shaping.
 * The Orchestrator calls for_llm() — do not reimplement prompt assembly at call sites.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Prompt_Assembler' ) ) {
	/**
	 * Deep module: build the LLM system + history + user payload for a think.
	 *
	 * Primary interface: for_llm(). Pure helpers (build_chat_payload, excerpt,
	 * truncate_tool_result_for_prompt, ensure_utf8, utf8_byte_slice) are part of the test surface.
	 */
	class Ahentic_Prompt_Assembler {
		const MAX_HISTORY_TURNS = 40;
		/** When history exceeds this many turns, compact older ones (PRD). */
		const COMPACT_HISTORY_THRESHOLD = 16;
		/** Keep this many recent history turns verbatim after compaction. */
		const COMPACT_KEEP_RECENT = 10;
		/** Soft char budget for history before compacting. */
		const COMPACT_CHAR_THRESHOLD = 24000;
		/** Max chars for the rolling earlier-context summary. */
		const COMPACT_SUMMARY_MAX_CHARS = 4000;
		/** Cap each tool-result payload injected into the next think prompt. */
		const MAX_TOOL_RESULT_CHARS = 8000;
		/** Cap for the newest live-editor snapshot; superseded copies are collapsed so one full read fits. */
		const MAX_TOOL_RESULT_CHARS_SNAPSHOT = 12000;
		/** Cap for tool results that already moved into chat history (before the latest user turn). */
		const MAX_TOOL_RESULT_CHARS_HISTORY = 1500;
		/** Cap when collapsing research get-content / list-content after a draft is staged. */
		const MAX_TOOL_RESULT_CHARS_RESEARCH = 500;

		/**
		 * Soft per-prompt context budget (tokens). WP AI Client does not expose model windows yet.
		 *
		 * @see pro__premium_only/docs/prd/sidebar.md (context usage ring)
		 * @see pro__premium_only/docs/prd/agent-runtime.md (budgets / compaction)
		 */
		const CONTEXT_BUDGET_TOKENS = 200000;

		/** Compact when estimated fill reaches this fraction of CONTEXT_BUDGET_TOKENS. */
		const COMPACT_FILL_RATIO = 0.85;

		/** Rough chars→tokens for fill estimates (no provider tokenizer). */
		const CHARS_PER_TOKEN = 4;

		/**
		 * Deep entry: system prompt + compacted history/user turn for one LLM think.
		 *
		 * @param int        $session_id  Session ID.
		 * @param string     $mode        Agent mode (agent|ask).
		 * @param string     $user_suffix Optional text appended to the user message (retries).
		 * @param array|null $extra_turn  Optional prior assistant turn to inject into history.
		 * @param array      $opts        Optional flags (slim_debug_retry, mini_job_hop + hop_brief,
		 *                                full_ability_catalog for missing-ability reconsider).
		 * @return array{
		 *   system: string,
		 *   history: array,
		 *   user: string,
		 *   clipped: array,
		 *   compacted: bool,
		 *   superseded: int,
		 *   context_usage: array,
		 *   slim_debug_retry?: bool,
		 *   mini_job_hop?: bool
		 * }
		 */
		public static function for_llm( $session_id, $mode, $user_suffix = '', $extra_turn = null, array $opts = array() ) {
			if ( ! empty( $opts['slim_debug_retry'] ) ) {
				$pinned = '';
				if ( $session_id ) {
					$pinned = self::pinned_run_context_for_prompt( $session_id );
				}
				$assembled = self::assemble_slim_debug_retry( $mode, $user_suffix, $pinned );
				if ( class_exists( 'Ahentic_Session_Repository' ) ) {
					Ahentic_Session_Repository::set_context_usage( $session_id, $assembled['context_usage'] );
				}
				return $assembled;
			}

			if ( ! empty( $opts['mini_job_hop'] ) ) {
				$brief  = isset( $opts['hop_brief'] ) ? (string) $opts['hop_brief'] : '';
				$pinned = '';
				if ( $session_id ) {
					$pinned = self::pinned_run_context_for_prompt( $session_id );
				}
				$abilities_index = '';
				if ( class_exists( 'Ahentic_Abilities' ) ) {
					$abilities_index = self::format_abilities_index(
						Ahentic_Abilities::available_for_mode( $mode )
					);
				}
				if ( is_string( $user_suffix ) && '' !== trim( $user_suffix ) ) {
					$brief = trim( $brief );
					$brief = ( '' !== $brief ? $brief . "\n\n" : '' ) . trim( $user_suffix );
				}
				$assembled = self::assemble_mini_job_hop( $mode, $brief, $pinned, $abilities_index );
				if ( class_exists( 'Ahentic_Session_Repository' ) ) {
					Ahentic_Session_Repository::set_context_usage( $session_id, $assembled['context_usage'] );
				}
				return $assembled;
			}

			$assembled = self::assemble_prompt( $session_id, $mode, $user_suffix, $extra_turn, true, $opts );
			if ( class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::set_context_usage( $session_id, $assembled['context_usage'] );
			}
			return $assembled;
		}

		/**
		 * Tiny system prompt for AHENTIC_DEBUG recovery (no ability index / routing packs).
		 *
		 * @param string $mode agent|ask.
		 * @return string
		 */
		public static function slim_debug_retry_system( $mode ) {
			$mode = 'ask' === $mode ? 'ask' : 'agent';
			$line = 'ask' === $mode
				? 'Mode: Ask (readonly tools only). '
				: 'Mode: Agent. ';
			return 'You are Ahentic, a WordPress workspace agent. '
				. $line
				. 'Your previous reply omitted a valid control block. '
				. 'Output exactly one <<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>> block FIRST with intention, thinking, '
				. 'tools_planned (reuse ability names you already intended; objects {"name","input"} or name strings), '
				. 'and next (reply|ask_user|use_tools only — do not use missing_ability here; this recovery prompt has no ability catalog), '
				. 'then a short user-facing reply. '
				. 'Do not invent site changes. Do not mention the debug block. '
				. 'Keep tools_planned empty when next is reply or ask_user.';
		}

		/**
		 * Assemble a slim recovery prompt (empty history; suffix carries prior prose).
		 *
		 * @param string $mode        agent|ask.
		 * @param string $user_suffix Retry instructions (+ prior text).
		 * @param string $pinned      Optional pinned run context.
		 * @return array Same shape as for_llm() plus slim_debug_retry.
		 */
		public static function assemble_slim_debug_retry( $mode, $user_suffix = '', $pinned = '' ) {
			$system = self::slim_debug_retry_system( $mode );
			$user   = '';
			if ( is_string( $pinned ) && '' !== trim( $pinned ) ) {
				$user .= trim( $pinned ) . "\n\n";
			}
			if ( is_string( $user_suffix ) && '' !== trim( $user_suffix ) ) {
				$user .= trim( $user_suffix );
			}
			if ( '' === $user ) {
				$user = 'Emit a valid AHENTIC_DEBUG control block, then a short reply.';
			}
			$format = '[Format reminder] Output exactly one <<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>> block FIRST '
				. '(intention, thinking, tools_planned, next), then the short user-facing reply.';
			$user  .= "\n\n" . $format;

			$chars = array(
				'system_prompt'     => strlen( $system ),
				'ability_schemas'   => 0,
				'chat_turns'        => strlen( $user ),
				'tool_results'      => 0,
				'page_context'      => 0,
				'plan_artifacts'    => 0,
				'compacted_summary' => 0,
			);

			return array(
				'system'           => $system,
				'history'          => array(),
				'user'             => $user,
				'clipped'          => array(),
				'compacted'        => false,
				'superseded'       => 0,
				'context_usage'    => self::usage_from_bucket_chars( $chars ),
				'slim_debug_retry' => true,
			);
		}

		/**
		 * System prompt for a mini-job hop (ability catalog, no chat / routing packs).
		 *
		 * @param string $mode            agent|ask.
		 * @param string $abilities_index Formatted ability index (may be empty in tests).
		 * @return string
		 */
		public static function mini_job_hop_system( $mode, $abilities_index = '' ) {
			$mode = 'ask' === $mode ? 'ask' : 'agent';
			$line = 'ask' === $mode
				? 'Mode: Ask (readonly tools only). '
				: 'Mode: Agent. ';
			$system = 'You are Ahentic, a WordPress workspace agent in a temporary mini-job hop. '
				. $line
				. 'Use only the ability catalog below and the hop brief on the user turn. '
				. 'Do not invent site changes. Do not ask clarifying questions unless the brief is impossible. '
				. 'Output exactly one <<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>> block FIRST with intention, thinking, '
				. 'tools_planned (objects {"name","input"} or name strings), and next '
				. '(reply|ask_user|use_tools|missing_ability), then a short user-facing reply. '
				. 'Prefer next="use_tools" with the abilities needed to finish this hop; when done, next="reply". '
				. 'Do not mention the debug block or that this is a mini-job.';

			$index = is_string( $abilities_index ) ? trim( $abilities_index ) : '';
			if ( '' !== $index ) {
				$system .= "\n\nAvailable abilities:\n" . $index;
			}
			return $system;
		}

		/**
		 * Assemble a mini-job hop prompt (empty history; main-packed brief; no size cap).
		 *
		 * @param string $mode            agent|ask.
		 * @param string $hop_brief       Main-packed brief (may be long).
		 * @param string $pinned          Optional pinned run context.
		 * @param string $abilities_index Formatted ability index.
		 * @return array Same shape as for_llm() plus mini_job_hop.
		 */
		public static function assemble_mini_job_hop( $mode, $hop_brief, $pinned = '', $abilities_index = '' ) {
			$system = self::mini_job_hop_system( $mode, $abilities_index );
			$user   = '';
			if ( is_string( $pinned ) && '' !== trim( $pinned ) ) {
				$user .= trim( $pinned ) . "\n\n";
			}
			$brief = is_string( $hop_brief ) ? trim( $hop_brief ) : '';
			if ( '' !== $brief ) {
				$user .= "Mini-job hop brief (complete this work with existing abilities):\n" . $brief;
			} else {
				$user .= 'Mini-job hop brief missing — set next="reply" and explain you cannot proceed.';
			}
			$format = '[Format reminder] Output exactly one <<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>> block FIRST '
				. '(intention, thinking, tools_planned, next), then the short user-facing reply.';
			$user  .= "\n\n" . $format;

			$chars = array(
				'system_prompt'     => strlen( $system ),
				'ability_schemas'   => is_string( $abilities_index ) ? strlen( $abilities_index ) : 0,
				'chat_turns'        => strlen( $user ),
				'tool_results'      => 0,
				'page_context'      => 0,
				'plan_artifacts'    => 0,
				'compacted_summary' => 0,
			);

			return array(
				'system'        => $system,
				'history'       => array(),
				'user'          => $user,
				'clipped'       => array(),
				'compacted'     => false,
				'superseded'    => 0,
				'context_usage' => self::usage_from_bucket_chars( $chars ),
				'mini_job_hop'  => true,
			);
		}

		/**
		 * Measure next-prompt context fill without persisting compaction side effects.
		 *
		 * @param int    $session_id Session ID.
		 * @param string $mode       Mode (agent|ask).
		 * @return array Context usage payload for REST / UI.
		 */
		public static function measure_context_usage( $session_id, $mode = '' ) {
			if ( '' === $mode && class_exists( 'Ahentic_Session_Repository' ) ) {
				$mode = Ahentic_Session_Repository::get_mode( $session_id );
			}
			if ( '' === $mode ) {
				$mode = 'agent';
			}
			$assembled = self::assemble_prompt( (int) $session_id, $mode, '', null, false );
			return $assembled['context_usage'];
		}

		/**
		 * @param int $chars Character length.
		 * @return int Estimated tokens.
		 */
		public static function chars_to_tokens( $chars ) {
			$chars = max( 0, (int) $chars );
			if ( $chars < 1 ) {
				return 0;
			}
			return (int) ceil( $chars / self::CHARS_PER_TOKEN );
		}

		/**
		 * Assemble system + history + user and estimate context buckets.
		 *
		 * @param int        $session_id      Session ID.
		 * @param string     $mode            Mode.
		 * @param string     $user_suffix     Retry suffix.
		 * @param array|null $extra_turn      Extra assistant turn.
		 * @param bool       $persist_compact Persist summary / trace when compacting.
		 * @param array      $opts            Prompt opts (e.g. full_ability_catalog).
		 * @return array Same shape as for_llm().
		 */
		private static function assemble_prompt( $session_id, $mode, $user_suffix = '', $extra_turn = null, $persist_compact = true, array $opts = array() ) {
			$sys_parts = self::system_prompt_parts( $mode, $session_id, $opts );
			$system    = self::compose_system_prompt( $sys_parts );

			$entries = class_exists( 'Ahentic_Session_Repository' )
				? Ahentic_Session_Repository::get_entries( $session_id )
				: array();
			$built   = self::build_chat_payload(
				is_array( $entries ) ? $entries : array(),
				array(
					'collapse_research' => self::session_should_collapse_research( $session_id ),
				)
			);
			$overhead_chars = strlen( $system );
			$built   = self::apply_context_compaction( $session_id, $built, $persist_compact, $overhead_chars );
			$history = $built['history'];
			$user    = $built['user'];

			$routing_len = isset( $sys_parts['routing'] ) ? strlen( (string) $sys_parts['routing'] ) : 0;
			$chars       = array(
				'system_prompt'      => strlen( $sys_parts['core'] ),
				// Mode/index/HITL + variable routing packs (usage gauge label stays ability_schemas).
				'ability_schemas'    => strlen( $sys_parts['abilities'] ) + $routing_len,
				'chat_turns'         => 0,
				'tool_results'       => 0,
				'page_context'       => 0,
				'plan_artifacts'     => strlen( $sys_parts['plan'] ),
				'compacted_summary'  => 0,
			);

			foreach ( $history as $turn ) {
				self::accumulate_turn_chars( $turn, $chars );
			}
			self::accumulate_user_payload_chars( $user, $chars );

			$page_context_note = self::format_page_context_for_prompt( $session_id );
			if ( '' !== $page_context_note ) {
				$chars['page_context'] += strlen( $page_context_note );
				$user                 .= "\n\n" . $page_context_note;
			}

			if ( class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$artifacts_note = Ahentic_Session_Artifacts::format_for_prompt( $session_id );
				if ( '' !== $artifacts_note ) {
					$chars['plan_artifacts'] += strlen( $artifacts_note );
					$user                    .= "\n\n" . $artifacts_note;
				}
			}

			$verify_note = self::verify_context_for_prompt( $session_id );
			if ( '' !== $verify_note ) {
				$chars['plan_artifacts'] += strlen( $verify_note );
				$user                    .= "\n\n" . $verify_note;
			}

			if ( class_exists( 'Ahentic_Finish_Gate' ) ) {
				$finish_nudge = Ahentic_Finish_Gate::finish_block_nudge_for_prompt( $session_id );
				if ( '' !== $finish_nudge ) {
					$chars['plan_artifacts'] += strlen( $finish_nudge );
					$user                    .= "\n\n" . $finish_nudge;
				}
			}

			$pinned = self::pinned_run_context_for_prompt( $session_id );
			if ( '' !== $pinned ) {
				$chars['plan_artifacts'] += strlen( $pinned );
				$user                    = $pinned . "\n\n" . $user;
			}

			if ( is_string( $user_suffix ) && '' !== trim( $user_suffix ) ) {
				$suffix                   = trim( $user_suffix );
				$chars['chat_turns']     += strlen( $suffix );
				$user                    .= "\n\n" . $suffix;
			}

			// Accumulated tool results push the system-prompt format spec far out of recency,
			// so the protocol is re-anchored as the last thing the model reads every turn.
			$format_reminder = '[Format reminder] Output exactly one <<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>> block FIRST '
				. '(intention, thinking, tools_planned, next), then the short user-facing reply. '
				. 'This applies to every turn, including verification and read-back steps — never reply with prose only.';
			$chars['system_prompt'] += strlen( $format_reminder );
			$user                   .= "\n\n" . $format_reminder;

			if ( is_array( $extra_turn ) && ! empty( $extra_turn['content'] ) ) {
				$extra = array(
					'role'    => 'assistant',
					'content' => (string) $extra_turn['content'],
				);
				self::accumulate_turn_chars( $extra, $chars );
				$history[] = $extra;
			}

			$usage = self::usage_from_bucket_chars( $chars );

			return array(
				'system'         => $system,
				'history'        => $history,
				'user'           => $user,
				'clipped'        => isset( $built['clipped'] ) && is_array( $built['clipped'] ) ? $built['clipped'] : array(),
				'compacted'      => ! empty( $built['compacted'] ),
				'superseded'     => isset( $built['superseded'] ) ? (int) $built['superseded'] : 0,
				'context_usage'  => $usage,
			);
		}

		/**
		 * System prompt for agent / ask modes.
		 *
		 * @param string $mode       Mode.
		 * @param int    $session_id Optional session for current plan context.
		 * @return string
		 */
		public static function system_prompt( $mode, $session_id = 0 ) {
			return self::compose_system_prompt( self::system_prompt_parts( $mode, $session_id ) );
		}

		/**
		 * Concatenate system parts in cache-friendly order: stable core → mode/HITL → variable packs → plan.
		 *
		 * @param array{core?: string, abilities?: string, routing?: string, plan?: string} $parts Parts.
		 * @return string
		 */
		public static function compose_system_prompt( array $parts ) {
			return ( isset( $parts['core'] ) ? (string) $parts['core'] : '' )
				. ( isset( $parts['abilities'] ) ? (string) $parts['abilities'] : '' )
				. ( isset( $parts['routing'] ) ? (string) $parts['routing'] : '' )
				. ( isset( $parts['plan'] ) ? (string) $parts['plan'] : '' );
		}

		/**
		 * Split system prompt into measurable buckets (stable core / abilities / routing / plan).
		 *
		 * Order is intentional for future provider prompt caching: identical prefix bytes across
		 * steps when mode is unchanged; abilities index + routing packs (same pack picker) and
		 * plan are the variable suffix.
		 *
		 * @param string $mode       Mode.
		 * @param int    $session_id Session ID.
		 * @param array  $opts       Optional. full_ability_catalog => list every mode ability + all packs.
		 * @return array{core: string, abilities: string, routing: string, plan: string}
		 */
		public static function system_prompt_parts( $mode, $session_id = 0, array $opts = array() ) {
			$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$site_url   = home_url( '/' );
			$available = Ahentic_Abilities::available_for_mode( $mode );
			$admin_map = Ahentic_Abilities::format_admin_links_for_prompt();

			$page_context = array();
			$entries      = array();
			if ( $session_id && class_exists( 'Ahentic_Session_Repository' ) ) {
				$ctx = Ahentic_Session_Repository::get_page_context( $session_id );
				if ( is_array( $ctx ) ) {
					$page_context = $ctx;
				}
				$raw = Ahentic_Session_Repository::get_entries( $session_id );
				if ( is_array( $raw ) ) {
					$entries = $raw;
				}
			}
			$has_content_work = $session_id
				&& class_exists( 'Ahentic_Session_Artifacts' )
				&& Ahentic_Session_Artifacts::session_has_content_work( $session_id );
			$recent_abilities = self::recent_ability_names_from_entries( $entries );
			$want_media = false;
			$want_http  = false;
			if ( $session_id && class_exists( 'Ahentic_Session_Repository' ) ) {
				$stored_goal   = Ahentic_Session_Repository::get_active_goal( $session_id );
				$goal_for_packs = class_exists( 'Ahentic_Job_Resume' )
					? Ahentic_Job_Resume::active_goal_from_entries( $entries, $stored_goal )
					: $stored_goal;
				$want_media = self::goal_suggests_media_pack( $goal_for_packs );
				$want_http  = self::goal_suggests_http_pack( $goal_for_packs );
			}
			$routing_packs = self::select_tool_routing_packs(
				$page_context,
				(bool) $has_content_work,
				$recent_abilities,
				$want_media,
				$want_http
			);
			$toolbox = self::resolve_think_toolbox(
				$available,
				$routing_packs,
				! empty( $opts['full_ability_catalog'] )
			);
			$routing_packs = $toolbox['packs'];
			// Same packs as routing essays — unless full_ability_catalog (missing-ability reconsider).
			$tools_list = self::format_abilities_index( $toolbox['abilities'] );
			$routing    = self::tool_routing_guidance_for_packs( $routing_packs );

			// Stable prefix (site + protocol + admin map) — keep byte-stable across steps when possible.
			$core = 'You are Ahentic, an AI workspace agent for WordPress. '
				. 'You help the user understand and improve their WordPress site. '
				. "Current site (hint only): {$site_name} ({$site_url}). "
				. 'Be concise and WordPress-specific. '
				. 'Avoid dashes in user-facing prose, especially as sentence punctuation; prefer commas, periods, or colons. Never use em dash "—" or en dash "–". '
				. 'Do not invent site changes unless a tool confirmed them. '
				. 'When you need verified site data, call tools — do not guess plugins or stack details.'
				. "\n\n"
				. 'When pointing the user to wp-admin, ALWAYS use a Markdown link with a full URL from the map below '
				. 'or from tool results (admin_links / edit URLs). Example: [Settings → General](https://example.com/wp-admin/options-general.php). '
				. 'Do not nest bold inside link brackets; do not invent /wp-admin/ paths.'
				. "\n\nAdmin link map:\n"
				. $admin_map
				. "\n\n"
				. 'Before your user-facing reply, output exactly one debug block (hidden from the user):' . "\n"
				. '<<<AHENTIC_DEBUG' . "\n"
				. '{"intention":"Short live status","thinking":"1-3 sentences","plan":{"title":"…","steps":[{"id":"1","content":"…","status":"in_progress"}]},"tools_planned":[{"name":"ahentic/…","input":{}}],"ability_needed":"ahentic/…","next":"reply|ask_user|use_tools|missing_ability"}' . "\n"
				. 'AHENTIC_DEBUG>>>' . "\n"
				. 'intention: short present-tense UI status (~10 words). '
				. 'thinking: 1–3 sentences shown in chat (never empty). '
				. 'tools_planned: ability name strings or {"name","input"} objects. '
				. 'mini_job + hop_brief: when a peelable chunk does NOT need full chat history, set mini_job=true, '
				. 'hop_brief to a self-contained brief (pack everything the hop needs — no size cap), tools_planned=[], next=use_tools. '
				. 'Orchestrator runs one slim hop think with the normal ability catalog, then returns a short summary. '
				. 'If tools are already known, batch them in tools_planned instead (Recipe — no hop). '
				. 'If the job needs full history, omit mini_job. '
				. 'ability_needed: required when next is missing_ability. '
				. 'plan: Agent mode MUST include plan.steps (≥3 steps) when intending 2+ tools OR any write; one readonly tool may omit; omit for simple Ask. '
				. 'Coarse user-facing steps; exactly one in_progress; on later thinks re-send the FULL plan (keep completed ids). '
				. 'Plan is silent UI metadata — not a substitute for thinking/chat. '
				. 'Close with AHENTIC_DEBUG>>> then a short user-visible reply (even when next is use_tools). Never mention the debug block.';

			if ( 'ask' === $mode ) {
				$abilities = ' Mode: Ask — same multi-step loop as Agent, but ONLY read-only tools (no site changes). '
					. 'Need facts → next="use_tools"; after results → more readonly tools or next="reply"|"ask_user"|"missing_ability". '
					. "Available readonly abilities: {$tools_list} "
					. 'If the user asks to change the site: do NOT call write tools; next="reply" or "ask_user", explain Ask is read-only, '
					. 'tell them to switch to Agent, and give manual steps with admin links if useful. '
					. 'ability_ask_readonly → same pattern. Missing write ability → next="missing_ability" + ability_needed + short workaround. '
					. 'Never mention X/Twitter/hashtags/@handles/request cards/sidebar feature-request UI. '
					. 'ability_unavailable → explain the gap and any workaround.';
			} else {
				$abilities = ' Mode: Agent — multi-step loop. Need facts → next="use_tools"; after results → more tools or next="reply"|"ask_user"|"missing_ability". '
					. "Available abilities: {$tools_list} "
					. 'HITL: for writes that pause for Allow/Skip (plugins, create/update/set-post-status, terms, settings, users, menus, '
					. 'ahentic-browser/save-post, convert-blocks, etc.), do NOT ask_user / “shall I…?” — next="use_tools" with that ability; '
					. 'Allow/Skip IS confirmation. Say what you will do; never claim success until a tool result confirms it. '
					. 'ask_user only for real choices tools cannot decide (e.g. missing email/role before create-user); '
					. 'when asking, keep unfinished plan steps open; do not mark the plan completed. '
					. 'user_denied / skipped=true: do NOT retry the same ability/input; adapt or ask_user once if blocked. '
					. 'No matching ability for a requested change: (A) next="use_tools" with the needed name anyway (orchestrator marks unavailable), '
					. 'or (B) next="missing_ability" + ability_needed; explain + short workaround with admin links. '
					. 'Never mention X/Twitter/hashtags/@handles/request cards/sidebar feature-request UI. '
					. 'ability_unavailable → same reply pattern.';
			}

			$plan = '';
			if ( $session_id ) {
				$plan .= self::plan_context_for_prompt( $session_id );
				if ( class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::session_has_content_work( $session_id ) ) {
					$plan .= ' CRITICAL — this run is long-form content work: you MUST use ahentic/stage-artifact '
						. '(while drafting: chunk with mode=append until complete=true; when revising a ready draft or rewriting the full article: mode=replace or a new key) '
						. 'then apply with set-blocks/create-post/update-post '
						. 'using {"from_memory":"…"} — do not finish after a thin one-section set-blocks rewrite. '
						. 'A finished article needs a full multi-section body; each write result reports its size, so keep writing when it comes back thin.';
				}
			}

			return array(
				'core'      => $core,
				'abilities' => $abilities,
				'routing'   => $routing,
				'plan'      => $plan,
			);
		}

		/**
		 * Narrow available abilities to those whose routing pack is selected for this think.
		 *
		 * Uses the same module `names()` → pack map as sticky packs. Names with no pack
		 * (snapshot, guidance, site health/options, …) stay as always-on core. Empty
		 * `$packs` fails open (returns the full list) so unit tests / edge cases never
		 * get an empty toolbox.
		 *
		 * Prompt index only — does not unregister abilities or change execute availability.
		 *
		 * @param string[] $available Ability names for the mode.
		 * @param string[] $packs     Selected routing pack ids.
		 * @return string[]
		 */
		public static function filter_available_abilities_for_packs( array $available, array $packs ) {
			$packs = array_values(
				array_unique(
					array_filter(
						array_map( 'strval', $packs ),
						static function ( $id ) {
							return '' !== $id;
						}
					)
				)
			);
			if ( ! $packs ) {
				return array_values( $available );
			}
			$map      = self::ability_name_to_routing_pack_map();
			$pack_set = array_fill_keys( $packs, true );
			$out      = array();
			foreach ( $available as $name ) {
				$name = (string) $name;
				if ( '' === $name ) {
					continue;
				}
				if ( ! isset( $map[ $name ] ) ) {
					$out[] = $name;
					continue;
				}
				if ( isset( $pack_set[ $map[ $name ] ] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * All tool-routing pack ids (ungated catalog).
		 *
		 * @return string[]
		 */
		public static function all_tool_routing_pack_ids() {
			return array( 'core', 'content', 'editor', 'admin-forms', 'media', 'plugins', 'settings', 'users', 'menus', 'http' );
		}

		/**
		 * Resolve ability names + routing packs for one think.
		 *
		 * When `$full_ability_catalog` is true (missing-ability reconsider), list every
		 * available ability and attach every routing pack — matching CONTRACT “full-catalog reconsider”.
		 *
		 * @param string[] $available              Ability names for the mode.
		 * @param string[] $page_context_packs     Packs from select_tool_routing_packs().
		 * @param bool     $full_ability_catalog   Ungate packs + ability index.
		 * @return array{abilities: string[], packs: string[]}
		 */
		public static function resolve_think_toolbox( array $available, array $page_context_packs, $full_ability_catalog = false ) {
			if ( $full_ability_catalog ) {
				return array(
					'abilities' => array_values( $available ),
					'packs'     => self::all_tool_routing_pack_ids(),
				);
			}
			return array(
				'abilities' => self::filter_available_abilities_for_packs( $available, $page_context_packs ),
				'packs'     => array_values(
					array_unique(
						array_filter(
							array_map( 'strval', $page_context_packs ),
							static function ( $id ) {
								return '' !== $id;
							}
						)
					)
				),
			);
		}

		/**
		 * Compact available abilities for the system prompt (short names, grouped by namespace).
		 *
		 * @param string[] $available Ability names.
		 * @return string
		 */
		public static function format_abilities_index( array $available ) {
			$server  = array();
			$browser = array();
			$other   = array();
			foreach ( $available as $name ) {
				$name = (string) $name;
				if ( 0 === strpos( $name, 'ahentic-browser/' ) ) {
					$browser[] = substr( $name, strlen( 'ahentic-browser/' ) );
				} elseif ( 0 === strpos( $name, 'ahentic/' ) ) {
					$server[] = substr( $name, strlen( 'ahentic/' ) );
				} else {
					$other[] = $name;
				}
			}
			$parts = array();
			if ( $server ) {
				$parts[] = 'ahentic/* (' . implode( ', ', $server ) . ')';
			}
			if ( $browser ) {
				$parts[] = 'ahentic-browser/* (' . implode( ', ', $browser ) . ')';
			}
			if ( $other ) {
				$parts[] = implode( ', ', $other );
			}
			return $parts ? ( implode( '; ', $parts ) . '.' ) : '(none).';
		}

		/**
		 * Ability names from trailing tool results since the latest user message (for sticky packs).
		 *
		 * @param array $entries Session entries.
		 * @param int   $limit   Max names to return (most recent last).
		 * @return string[]
		 */
		public static function recent_ability_names_from_entries( array $entries, $limit = 12 ) {
			$last_user = -1;
			foreach ( $entries as $i => $entry ) {
				if ( isset( $entry['role'] ) && 'user' === $entry['role'] ) {
					$last_user = (int) $i;
				}
			}
			$names = array();
			foreach ( $entries as $i => $entry ) {
				if ( (int) $i <= $last_user ) {
					continue;
				}
				if ( ! isset( $entry['role'] ) || 'tool' !== $entry['role'] ) {
					continue;
				}
				$ability = isset( $entry['meta']['ability'] ) ? (string) $entry['meta']['ability'] : '';
				if ( '' !== $ability ) {
					$names[] = $ability;
				}
			}
			$limit = max( 1, (int) $limit );
			if ( count( $names ) > $limit ) {
				$names = array_slice( $names, -1 * $limit );
			}
			return array_values( $names );
		}

		/**
		 * Choose which tool-routing packs to embed for this think.
		 *
		 * Floor from page context + content_work; recent abilities may sticky-add packs.
		 * Empty page context bootstraps content (discovery) but not editor/media essays.
		 * Media is NOT attached for every block-editor think — only sticky media tools,
		 * media admin screens, or an explicit media-ish goal.
		 *
		 * @param array    $page_context      Session page context (may be empty).
		 * @param bool     $has_content_work  Whether the session is mid long-form content work.
		 * @param string[] $recent_abilities  Trailing tool ability names this run (optional).
		 * @param bool     $want_media        Goal / caller asks for image/media work.
		 * @param bool     $want_http         Goal / caller asks for visitor-facing public URL checks.
		 * @return string[] Pack ids.
		 */
		public static function select_tool_routing_packs( array $page_context, $has_content_work = false, array $recent_abilities = array(), $want_media = false, $want_http = false ) {
			$packs = array( 'core' );
			$url   = isset( $page_context['url'] ) ? (string) $page_context['url'] : '';
			$in_editor = ! empty( $page_context['is_block_editor'] )
				|| self::url_looks_like_block_editor( $url );
			$on_content_screen = self::url_looks_like_content_screen( $url );
			$sticky            = self::packs_suggested_by_recent_abilities( $recent_abilities );
			$is_admin          = ! empty( $page_context['isAdmin'] )
				|| self::url_looks_like_wp_admin( $url );

			// Content essays: editor / posts screens / content work / unknown tab / sticky.
			if ( $in_editor || $on_content_screen || $has_content_work || empty( $page_context ) || in_array( 'content', $sticky, true ) ) {
				$packs[] = 'content';
			}

			// Editor: not on empty context alone — need editor signal, content work, or sticky tools.
			if ( $in_editor || $has_content_work || in_array( 'editor', $sticky, true ) ) {
				$packs[] = 'editor';
			}
			// Admin forms (classic Settings / plugin options): fill-first when not in the block editor.
			if ( ( $is_admin && ! $in_editor ) || in_array( 'admin-forms', $sticky, true ) ) {
				$packs[] = 'admin-forms';
			}
			// Media: sticky media abilities / media admin / media-ish goal — not bare editor.
			if (
				$want_media
				|| in_array( 'media', $sticky, true )
				|| self::url_matches_any( $url, array( 'upload.php', 'media-new.php' ) )
			) {
				$packs[] = 'media';
			}

			if ( self::url_matches_any( $url, array( 'plugins.php', 'plugin-install.php', 'plugin-editor.php' ) ) || in_array( 'plugins', $sticky, true ) ) {
				$packs[] = 'plugins';
			}
			if ( self::url_matches_any(
				$url,
				array(
					'customize.php',
					'themes.php',
					'site-editor.php',
					'options-general.php',
					'options-writing.php',
					'options-reading.php',
					'options-discussion.php',
					'options-media.php',
					'options-permalink.php',
					'options.php',
					'widgets.php',
				)
			) || in_array( 'settings', $sticky, true ) ) {
				$packs[] = 'settings';
			}
			if ( self::url_matches_any( $url, array( 'users.php', 'user-new.php', 'profile.php', 'user-edit.php' ) ) || in_array( 'users', $sticky, true ) ) {
				$packs[] = 'users';
			}
			if ( self::url_matches_any( $url, array( 'nav-menus.php' ) ) || in_array( 'menus', $sticky, true ) ) {
				$packs[] = 'menus';
			}
			if (
				$want_http
				|| self::url_matches_any( $url, array( 'site-health.php', 'tools.php' ) )
				|| in_array( 'http', $sticky, true )
			) {
				$packs[] = 'http';
			}
			// Visitor-facing checks often need search-site after the public fetch.
			if ( $want_http ) {
				$packs[] = 'content';
			}

			return array_values( array_unique( $packs ) );
		}

		/**
		 * Whether the active goal is asking for image / media library work.
		 *
		 * Routing-only heuristic for attaching the media pack (and thus listing
		 * ahentic/generate-image). Broader than playbook trigger lists on purpose:
		 * pack selection must catch common create/add/make phrasings, while playbooks
		 * remain a separate when-to-load signal via get-wordpress-guidance.
		 *
		 * @param string $goal Active goal text.
		 * @return bool
		 */
		public static function goal_suggests_media_pack( $goal ) {
			$goal = strtolower( trim( (string) $goal ) );
			if ( '' === $goal ) {
				return false;
			}
			return (bool) preg_match(
				'/\b('
				. 'featured\s+image|hero\s+image|cover\s+image|inline\s+image|'
				. 'media\s+library|alt\s+text|set[\s-]?featured|thumbnail|'
				. '(?:generate|create|make|add|draw|design)\s+(?:(?:an?|some|our|the|my)\s+)?(?:images?|pictures?|photos?|illustrations?|artwork|logos?)|'
				. 'upload\s+(?:(?:an?|some|our|the|my)\s+)?(?:images?|pictures?|photos?|media)|'
				. '(?:delete|remove|restore|untrash)\s+(?:(?:an?|some|our|the|my|unused|deleted)\s+)*(?:images?|pictures?|photos?|media)|'
				. 'unused\s+media|trashed\s+media'
				. ')\b/i',
				$goal
			);
		}

		/**
		 * Whether the active goal is asking what a visitor can see / find on the public site.
		 *
		 * Routing-only heuristic for attaching the http pack (and thus listing ahentic/http-fetch).
		 * Storage / edit asks ("where is it stored", "change the footer") should stay false.
		 *
		 * @param string $goal Active goal text.
		 * @return bool
		 */
		public static function goal_suggests_http_pack( $goal ) {
			$goal = strtolower( trim( (string) $goal ) );
			if ( '' === $goal ) {
				return false;
			}
			// Storage / edit intent: not a visitor-facing check.
			if ( preg_match( '/\bwhere\s+(?:is|are)\b[\s\S]{0,80}\b(stored|storage|coming from)\b/i', $goal ) ) {
				return false;
			}
			if ( preg_match( '/\b(edit|change|update|replace)\b[\s\S]{0,60}\b(widget|footer|header|option|theme\s*mod|post meta)\b/i', $goal ) ) {
				return false;
			}
			return (bool) preg_match(
				'/\b('
				. '(?:can|could)\s+(?:people|visitors?|customers?|users?|someone|anyone)\s+(?:(?:easily|quickly|still)\s+)?(?:find|see|reach|get|locate)|'
				. '(?:is|are)\s+(?:(?:our|the|my|this)\s+)?[\w\s\'-]{0,48}?\s*(?:visible|public|findable|discoverable)|'
				. 'what\s+(?:does|do)\s+(?:(?:our|the|my)\s+)?(?:site|homepage|home\s*page|front\s*page|landing(?:\s+page)?)\s+look|'
				. 'visitor(?:-|\s)?facing|as\s+a\s+visitor|public\s+(?:page|site|url)|live\s+site|'
				. 'find\s+(?:(?:our|the|my)\s+)?(?:phone|email|address|contact)|'
				. 'soft\s+white\s+screen|broken\s+link|404\s+on\s+(?:the\s+)?(?:live|public)'
				. ')\b/i',
				$goal
			);
		}

		/**
		 * Map recent ability names → pack ids to sticky-include.
		 *
		 * Membership is derived from each ability module’s `names()` catalog when loaded —
		 * do not hand-sync a second ability list here.
		 *
		 * @param string[] $ability_names Ability names.
		 * @return string[] Pack ids.
		 */
		public static function packs_suggested_by_recent_abilities( array $ability_names ) {
			$map   = self::ability_name_to_routing_pack_map();
			$packs = array();
			foreach ( $ability_names as $name ) {
				$name = (string) $name;
				if ( '' === $name ) {
					continue;
				}
				if ( isset( $map[ $name ] ) ) {
					$packs[] = $map[ $name ];
					continue;
				}
				// Namespace fallback when modules are not loaded (narrow unit tests).
				if ( 0 === strpos( $name, 'ahentic-browser/' ) ) {
					$packs[] = 'editor';
				}
			}
			return array_values( array_unique( $packs ) );
		}

		/**
		 * Ability name → routing pack, built from module catalogs (single source of truth).
		 *
		 * @return array<string, string> Ability name => pack id.
		 */
		private static function ability_name_to_routing_pack_map() {
			static $map = null;
			if ( null !== $map ) {
				return $map;
			}
			$map    = array();
			$groups = array(
				'content'  => array( 'Ahentic_Abilities_Content', 'Ahentic_Abilities_Taxonomy', 'Ahentic_Session_Artifacts' ),
				'editor'   => array( 'Ahentic_Abilities_Browser' ),
				'media'    => array( 'Ahentic_Abilities_Media' ),
				'plugins'  => array( 'Ahentic_Abilities_Plugins' ),
				'settings' => array( 'Ahentic_Abilities_Settings' ),
				'users'    => array( 'Ahentic_Abilities_Users' ),
				'menus'    => array( 'Ahentic_Abilities_Menus' ),
			);
			foreach ( $groups as $pack => $classes ) {
				foreach ( $classes as $class ) {
					if ( ! class_exists( $class ) || ! is_callable( array( $class, 'names' ) ) ) {
						continue;
					}
					foreach ( (array) call_user_func( array( $class, 'names' ) ) as $ability ) {
						$ability = (string) $ability;
						if ( '' !== $ability ) {
							$map[ $ability ] = $pack;
						}
					}
				}
			}
			// Site module mixes http with snapshot/health — only sticky the http pack for fetch/debug.
			if ( class_exists( 'Ahentic_Abilities_Site' ) ) {
				if ( defined( 'Ahentic_Abilities_Site::HTTP_FETCH' ) ) {
					$map[ Ahentic_Abilities_Site::HTTP_FETCH ] = 'http';
				}
				if ( defined( 'Ahentic_Abilities_Site::DEBUG_LOG' ) ) {
					$map[ Ahentic_Abilities_Site::DEBUG_LOG ] = 'http';
				}
			}
			// Admin form-first tools sticky the admin-forms pack (not the editor essay).
			if ( class_exists( 'Ahentic_Abilities_Browser' ) ) {
				if ( defined( 'Ahentic_Abilities_Browser::FILL_FIELDS' ) ) {
					$map[ Ahentic_Abilities_Browser::FILL_FIELDS ] = 'admin-forms';
				}
				if ( defined( 'Ahentic_Abilities_Browser::VISIBLE_PAGE' ) ) {
					$map[ Ahentic_Abilities_Browser::VISIBLE_PAGE ] = 'admin-forms';
				}
				if ( defined( 'Ahentic_Abilities_Browser::CURRENT_PAGE' ) ) {
					$map[ Ahentic_Abilities_Browser::CURRENT_PAGE ] = 'admin-forms';
				}
			}
			return $map;
		}

		/**
		 * @param string $url Page URL.
		 * @return bool
		 */
		private static function url_looks_like_wp_admin( $url ) {
			$url = (string) $url;
			if ( '' === $url ) {
				return false;
			}
			return false !== strpos( $url, '/wp-admin' );
		}

		/**
		 * @param string $url Page URL.
		 * @return bool
		 */
		private static function url_looks_like_block_editor( $url ) {
			$url = (string) $url;
			if ( '' === $url ) {
				return false;
			}
			return ( false !== strpos( $url, 'post.php' ) )
				|| ( false !== strpos( $url, 'post-new.php' ) )
				|| ( false !== strpos( $url, 'site-editor.php' ) );
		}

		/**
		 * Posts/pages list and related content admin screens (not necessarily the block editor).
		 *
		 * @param string $url Page URL.
		 * @return bool
		 */
		private static function url_looks_like_content_screen( $url ) {
			$url = (string) $url;
			if ( '' === $url ) {
				return false;
			}
			return self::url_matches_any(
				$url,
				array(
					'edit.php',
					'post.php',
					'post-new.php',
					'site-editor.php',
					'revision.php',
				)
			);
		}

		/**
		 * @param string   $url   Page URL.
		 * @param string[] $needles Path fragments.
		 * @return bool
		 */
		private static function url_matches_any( $url, array $needles ) {
			$url = (string) $url;
			if ( '' === $url ) {
				return false;
			}
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $url, (string) $needle ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Assemble tool-routing guidance from selected packs.
		 *
		 * @param string[] $packs Pack ids.
		 * @return string
		 */
		public static function tool_routing_guidance_for_packs( array $packs ) {
			$out = '';
			foreach ( $packs as $id ) {
				$chunk = self::tool_routing_pack( (string) $id );
				if ( '' !== $chunk ) {
					$out .= $chunk;
				}
			}
			return $out;
		}

		/**
		 * Full (ungated) tool-routing guidance — used when all packs are appropriate.
		 *
		 * @return string
		 */
		private static function tool_routing_guidance() {
			return self::tool_routing_guidance_for_packs( self::all_tool_routing_pack_ids() );
		}

		/**
		 * One routing pack body.
		 *
		 * @param string $id Pack id.
		 * @return string
		 */
		private static function tool_routing_pack( $id ) {
			switch ( (string) $id ) {
				case 'core':
					return 'Prefer ahentic/get-site-snapshot for site name/theme/plugins/admin_links; '
						. 'ahentic/get-site-health for Site Health; ahentic/get-option for allowlisted options. '
						. 'When unsure about WordPress practice (plugins vs code, SEO choice, cleanup, editor vs server), call ahentic/get-wordpress-guidance '
						. '(topic ids: plugin-hygiene, custom-code-snippets, pre-launch-gaps, seo-decisioning, safe-cleanup, editor-vs-server, editor-leave-canvas, editor-wrap-blocks, web-image-fit, post-title-headings) '
						. 'or {"query":"…"}; omit both to list the catalog. '
						. 'Prefer server ahentic/* when it fully does the job; ahentic-browser/* for the live tab / block editor / open admin forms. '
						. 'Never simulate a server ability in the browser. '
						. 'Screen identity: get-admin-context or get-current-page; visible UI: get-visible-page; fill-fields for open forms (no submit; see admin-forms pack). '
						. 'Trust the LATEST attached page context; re-call get-current-page only when stale. '
						. 'CRITICAL — never re-check writes with readonly tools; go next="reply" after ok:true. '
						. 'Always pass tools_planned as {"name","input"} objects when args are needed. '
						. 'Use edit_url / view_url from tool results. Do not claim tools not in the available list. ';

				case 'content':
					return 'Prefer ahentic/search-site with {"query":"…"} (optional mode:"regex") when finding or changing a string whose storage is unknown '
						. '(phone, email, address, footer/header text) — covers posts/template parts, post meta, and options/widgets/theme mods; returns identifiers + match + snippets. '
						. 'Visitor-facing “can people find / is X visible” questions: verify with ahentic/http-fetch on the relevant public URL(s) first (see http pack). Do not answer not-found from search-site or admin alone; use search-site for where it is stored or how to edit. '
						. 'Refuse common/short queries (min 3 chars). Prefer ahentic/search-content with {"query":"…"} or {"queries":["…","…"]} (up to 5 phrases in ONE call) for normal post/page research; '
						. 'ahentic/list-content to browse by type/status (prefer per_page 10–20, default 15, max 25 — use page:2+ or a tighter search/type when you need more). '
						. 'Research: put every list-content / search-content / search-site you need in the FIRST tools_planned, then draft or reply — '
						. 'do NOT open a second think to re-list the same filters; do NOT re-call list-content in the same run if Ability results already include a usable list unless post_type/status/search changed. '
						. 'ahentic/get-content to read one post (body + safe meta). '
						. 'For find/replace in post bodies use ahentic/replace-in-content (dry_run:true first; mode literal|regex) — not a second search-site loop. '
						. 'Internal linking: search-content or list-content for targets (title + view_url is enough for posts); '
						. 'ONE compact get-blocks (includes HTML content on paragraphs/headings) then batch ahentic-browser/update-block-attributes with final <a href> HTML in ONE tools_planned — then reply; do not get-blocks again to verify. '
						. 'For long non-posts, prefer ahentic/get-content-summary before get-content; never get-content solely to pick a link target. '
						. 'Prefer create-post / update-post / set-post-status when the block editor is NOT open. '
						. 'update-post changes content/title/excerpt/slug/meta (not status); set-post-status for publish/schedule/trash (HITL). '
						. 'Custom fields / Woo prices: get-content include_meta:true, then update with exact meta keys (e.g. _regular_price, _price) — never invent top-level "price". '
						. 'Long drafts: stage-artifact (key article_draft, kind blocks|html|post_content; payload.blocks for blocks; append while drafting, replace when revising) '
						. 'then set-blocks/create-post/update-post with from_memory — list-artifacts shows staged keys. '
						. 'If write result has thin:true, keep writing; staging alone is not done. '
						. 'placeholder_content / ahentic_use_browser_editor → fix approach, do not claim written. '
						. 'CRITICAL — post title is the H1 (update-post-document while editor open; create/update-post title when not). '
						. 'Include update-post-document (title) in the SAME tools_planned as apply when possible. Body headings start at level 2. '
						. 'get-wordpress-guidance topic post-title-headings when unsure. '
						. 'Terms: list-terms / get-term / create-term / update-term / delete-term; assign via create-post / update-post categories|tags|tax_input. ';

				case 'editor':
					return 'CRITICAL — content routing by page context: '
						. 'If is_block_editor=true, change content/title/structure with ahentic-browser/* '
						. '(update-post-document, set-blocks, insert-blocks, replace-blocks, delete-blocks, move-blocks, update-block-attributes, get-selection / get-blocks) '
						. '— not ahentic/update-post for body/title/excerpt/slug on the open document. '
						. 'create-post only for a NEW post/page or when the editor is closed; after create-post, if later context shows the editor open, continue with browser tools. '
						. 'If editor not open, prefer server create-post / update-post / set-post-status. '
						. 'Missing is_block_editor but URL looks like post.php / post-new.php / site-editor → get-editor-state before create-post. '
						. 'Do NOT save-post unless the user asks to save/publish — leave unsaved canvas edits for the user. '
						. 'CRITICAL — real block objects only: {name, attributes, innerBlocks}; never bracket stubs. Full rewrite → set-blocks. '
						. 'Remove with delete-blocks; reorder with move-blocks (before_ref/after_ref). '
						. 'Move out of body (featured image, excerpt, title) via destination ability then usually delete-blocks. '
						. 'Long articles: stage-artifact then set-blocks/create-post from_memory — do not re-paste huge drafts into tools_planned. '
						. 'get-block-type only for third-party blocks or after unknown-key failures — never first for core/paragraph|heading|button. Input is {name}, not a ref. '
						. 'Library conversion (core↔Stackable/other plugins): ahentic-browser/convert-blocks with target (namespace or exact name), not get-block-type×N + set-blocks rewrites. '
						. 'Use dry_run:true to preview; on skipped no_transform, get-block-type fields:"convert" once per unique type only. '
						. 'Rich-text attrs are HTML strings. Compact get-blocks includes capped HTML on the primary content_attr — for internal links/light text edits, ONE get-blocks then batch update-block-attributes; do not loop get-blocks → patch → get-blocks. '
						. 'Refs are short (b1, b2, …) from get-blocks/get-selection — copy exactly; never invent clientId hashes. missing refs → re-call get-blocks. '
						. 'Scoped re-read: get-blocks {"refs":[…]} when you need blocks not in the last compact tree. '
						. 'Core attrs: heading content+level (2+ for posts); paragraph content; button text+url; image url/alt/id/caption; list-item content; html content. '
						. 'Fuller cheatsheet attaches with page context when the editor is open. Prefer set-post-terms (term IDs) while editor open. ';

				case 'admin-forms':
					return 'On admin screens outside the block editor: call ahentic-browser/get-visible-page before changing options/settings that may appear as form fields. '
						. 'If the control is on the open form, prefer ahentic-browser/fill-fields (does not submit — leave Save/Update for the user). '
						. 'Ordinary fills need no Allow; password / email / role-like fields still require Allow. '
						. 'Hard-denied options (siteurl, home, default_role, users_can_register, admin_email) cannot be filled. '
						. 'After a successful fill, do NOT also call ahentic/update-option for the same change — same idea as leaving the editor canvas dirty. '
						. 'If the field is not on this screen, ask once whether to open the right admin URL (markdown link) or apply via server abilities; if neither, stop. '
						. 'Customizer / global styles / template parts stay on settings-pack server tools — not fill-fields. '
						. 'Headless Agents without a browser keep using server writes. ';

				case 'media':
					return 'Post images: put ahentic/generate-image, ahentic/upload-media (from_memory), and ONE place '
						. '(ahentic/set-featured-image or ahentic-browser/set-featured-image with attachment_id — use 0 as placeholder after upload, or from_upload with the image artifact key; inline: ahentic-browser/insert-blocks) '
						. 'in ONE tools_planned so steps can run without another full think between them — never both featured and inline. '
						. 'Also: ahentic/list-media / ahentic/get-media / ahentic/find-unused-media; ahentic/delete-media quarantines to trash; ahentic/restore-media untrashes (HITL). '
						. 'To find trashed attachments for restore, ahentic/list-media with status=trash. '						. 'Call get-wordpress-guidance topic web-image-fit before post images; default 16:9 not tall/square. '
						. 'Alt text: get-blocks compact media attrs → describe-image (attachment_id or url) → update-block-attributes with the block\'s alt key. '
						. 'Never from_memory on insert-blocks for image artifacts. ';

				case 'plugins':
					return 'Prefer ahentic/list-plugins for installed active+inactive plugins; ahentic/search-plugins to search wordpress.org (pass query like "SEO"). '
						. 'Chain install → activate: after a successful ahentic/install-plugin result with active=false, if the user wanted the plugin working '
						. '(install / set up / turn on / “help me find one”), immediately next="use_tools" with ahentic/activate-plugin using the same slug or plugin_file — '
						. 'do not stop at “installed but not active.” Only skip chaining when they clearly asked to install without activating. ';

				case 'settings':
					return 'For theme Customizer / appearance settings: call ahentic/get-settings-context first (block vs classic + surfaces). '
						. 'On classic themes use ahentic/list-settings with a required query, section, or prefix filter (never unfiltered), then ahentic/get-setting for values (large values summarize unless raw:true). '
						. 'On classic themes, change Customizer settings with ahentic/update-theme-setting '
						. '(changes:[{id,value,path?,replace?}]; HITL; merge nested values by path; whole-object replace needs replace:true; dry_run:true to preview). '
						. 'Never invent setting ids — only ids from list-settings / get-setting. Code-bearing settings (Additional CSS / code editors) are refused (Code Snippets Premium). '
						. 'On block themes, change theme.json user-layer colors/typography/spacing with ahentic/update-global-styles '
						. '({styles?,settings?,dry_run?}; HITL; merges into the user layer; strips styles.css / block css keys). '
						. 'For header/footer HTML on block themes use ahentic/update-template-part (template_part_id theme//slug + blocks/content; HITL non-preallowable; creates a DB override decoupled from theme file updates). '
						. 'When the Site Editor has that part open, use ahentic-browser block tools + save-post instead. '
						. 'When the admin form for an option is open (see admin-forms pack), prefer fill-fields over update-option. '
						. 'Otherwise change registered or vetted WordPress options with ahentic/update-option ({key,value,dry_run?}; HITL; '
						. 'hard-denies siteurl/home/default_role/users_can_register/admin_email; refuses unregistered unschematized keys). ';

				case 'users':
					return 'Prefer ahentic/list-users to browse accounts (email only when permitted); '
						. 'ahentic/create-user / ahentic/update-user / ahentic/delete-user for account changes '
						. '(HITL non-preallowable every time; no self-edit; may assign editor/author/subscriber etc. but not manage_options roles; delete requires reassign_to). ';

				case 'menus':
					return 'Prefer ahentic/list-menus / ahentic/list-menu-items / ahentic/get-menu for classic Appearance → Menus; '
						. 'ahentic/update-menu to create-or-replace the item tree and/or theme locations (HITL; never create-post on nav_menu_item). ';

				case 'http':
					return 'Visitor-facing questions (what the public can see or find): ahentic/http-fetch the relevant public URL(s) first without as_user. '
						. 'When no specific page is named, fetch the site home; otherwise fetch the named public page (and Contact only if the ask is about contact details there). '
						. 'Do not answer “not found / not visible” from search-site, admin, or the open wp-admin tab alone; use those for storage or edits after (or beside) the public fetch. '
						. 'Prefer ahentic/http-fetch to GET a URL. For public pages omit as_user. For wp-admin / logged-in same-site pages pass {"url":"…","as_user":true} — that runs in the user’s browser with their session. Judge soft white screens by success_marker/body, not status alone. '
						. 'Prefer ahentic/get-debug-log for PHP fatals when WP_DEBUG_LOG is available. ';

				default:
					return '';
			}
		}

		/**
		 * Inject the current plan into the system prompt so later thinks stay aligned.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function plan_context_for_prompt( $session_id ) {
			$plan = Ahentic_Session_Repository::get_plan( $session_id );
			if ( ! is_array( $plan ) || empty( $plan['steps'] ) ) {
				return '';
			}

			$lines = array(
				'Current multi-step plan (re-send this FULL list in debug.plan every think — keep completed steps; '
				. 'only change statuses). Chat replies stay normal prose — thought process and findings — not checklist labels:',
			);
			if ( ! empty( $plan['title'] ) ) {
				$lines[] = 'Title: ' . $plan['title'];
			}
			foreach ( $plan['steps'] as $step ) {
				$id      = isset( $step['id'] ) ? (string) $step['id'] : '';
				$status  = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
				$content = isset( $step['content'] ) ? (string) $step['content'] : '';
				$lines[] = sprintf( '- [%s] id=%s %s', $status, $id, $content );
			}
			$lines[] = 'When a step finishes, mark it completed (do not remove it), set the next one in_progress, '
				. 'and write a normal chat reply with what you learned. When all are done, mark every step completed. '
				. 'If next=ask_user (clarifying question mid-job), keep unfinished steps pending/in_progress; '
				. 'never mark every step completed while you are still waiting on the user.';

			return "\n\n" . implode( "\n", $lines );
		}

		/**
		 * Build history + latest user message for the model.
		 *
		 * Tool results since the last user message are appended to the user prompt
		 * so the next think can observe them.
		 *
		 * @param array $entries Session entries.
		 * @param array $opts    Optional. `collapse_research` (bool) collapses get-content / list-content.
		 * @return array{history: array, user: string, clipped: array, superseded: int}
		 */
		public static function build_chat_payload( array $entries, array $opts = array() ) {
			$collapse_research = ! empty( $opts['collapse_research'] );
			$latest_supersede  = self::latest_supersedable_tool_indexes( $entries );
			$last_user_entry_i = self::last_user_entry_index( $entries );
			$clipped           = array();
			$superseded        = 0;

			$normalized = array();
			foreach ( $entries as $i => $entry ) {
				if ( ! empty( $entry['meta']['error'] ) ) {
					continue;
				}
				$role = isset( $entry['role'] ) ? $entry['role'] : '';
				if ( 'user' === $role || 'assistant' === $role ) {
					if ( ! empty( $entry['meta']['thought_process'] ) || ! empty( $entry['meta']['intermediate'] ) ) {
						continue;
					}
					$normalized[] = array(
						'role'    => $role,
						'content' => (string) $entry['content'],
					);
				} elseif ( 'tool' === $role ) {
					$ability = isset( $entry['meta']['ability'] ) ? (string) $entry['meta']['ability'] : 'tool';

					if ( isset( $latest_supersede[ $ability ] ) && $latest_supersede[ $ability ] !== $i ) {
						$body = '[Superseded — a newer ' . $ability . ' result appears below.]';
						++$superseded;
					} elseif ( $collapse_research && self::ability_is_research_body( $ability ) ) {
						$raw_len = strlen( (string) $entry['content'] );
						$body    = self::compact_research_tool_body( $ability, (string) $entry['content'] );
						if ( $raw_len > strlen( $body ) ) {
							$clipped[] = array(
								'ability' => $ability,
								'len'     => $raw_len,
								'cap'     => self::MAX_TOOL_RESULT_CHARS_RESEARCH,
							);
						}
					} else {
						$raw_len     = strlen( (string) $entry['content'] );
						$is_trailing = ( $last_user_entry_i < 0 ) || ( $i > $last_user_entry_i );
						$cap         = self::tool_result_cap_for_prompt( $ability, $is_trailing );
						$body        = self::truncate_tool_result_for_prompt( (string) $entry['content'], $cap );
						if ( $raw_len > $cap ) {
							// A clipped read-back makes the model re-read what it can never see,
							// so record it: this was invisible and cost a full debugging round.
							$clipped[] = array(
								'ability' => $ability,
								'len'     => $raw_len,
								'cap'     => $cap,
							);
						}
					}

					$normalized[] = array(
						'role'    => 'tool',
						'content' => '[Ability result: ' . $ability . "]\n" . $body,
					);
				}
			}

			$last_user_i = -1;
			foreach ( $normalized as $i => $turn ) {
				if ( 'user' === $turn['role'] ) {
					$last_user_i = $i;
				}
			}

			if ( $last_user_i < 0 ) {
				return array(
					'history'    => array(),
					'user'       => '',
					'clipped'    => $clipped,
					'superseded' => $superseded,
				);
			}

			$history = array();
			for ( $i = 0; $i < $last_user_i; $i++ ) {
				$turn = $normalized[ $i ];
				if ( 'tool' === $turn['role'] ) {
					$history[] = array(
						'role'    => 'assistant',
						'content' => $turn['content'],
					);
				} else {
					$history[] = $turn;
				}
			}

			$user     = $normalized[ $last_user_i ]['content'];
			$trailing = array_slice( $normalized, $last_user_i + 1 );
			if ( ! empty( $trailing ) ) {
				$chunks = array();
				foreach ( $trailing as $turn ) {
					$chunks[] = $turn['content'];
				}
				$user .= "\n\n---\nAbility results from this run (use these facts; do not invent conflicting data). "
					. "If a result includes block ref values (b1, b2, …), pass those refs back to tools EXACTLY as printed — "
					. "never invent Gutenberg clientId UUID hashes. "
					. "ok:true means the mutate applied — but if this message (or session) still lists pending write verification "
					. "or ready unapplied artifacts, you MUST set next=\"use_tools\" for the required readonly check / from_memory apply "
					. "before next=\"reply\". Do not claim the article is finished from chat alone:\n"
					. implode( "\n\n", $chunks );
			}

			if ( count( $history ) > self::MAX_HISTORY_TURNS ) {
				$history = array_slice( $history, -1 * self::MAX_HISTORY_TURNS );
			}

			return array(
				'history'    => $history,
				'user'       => $user,
				'compacted'  => false,
				'clipped'    => $clipped,
				'superseded' => $superseded,
			);
		}

		/**
		 * Mid-run compaction: summarize older history; never drop plan / artifacts / latest goal.
		 *
		 * @param int   $session_id      Session ID.
		 * @param array $payload         From build_chat_payload.
		 * @param bool  $persist_compact Persist summary + trace (false for measure-only).
		 * @param int   $overhead_chars  System/ability/plan chars already counted toward fill.
		 * @return array{history: array, user: string, compacted?: bool}
		 */
		private static function apply_context_compaction( $session_id, array $payload, $persist_compact = true, $overhead_chars = 0 ) {
			$history = isset( $payload['history'] ) && is_array( $payload['history'] ) ? $payload['history'] : array();
			$user    = isset( $payload['user'] ) ? (string) $payload['user'] : '';
			// Prompt-shaping notes are diagnostics about the build, so they survive compaction.
			$notes   = array(
				'clipped'    => isset( $payload['clipped'] ) && is_array( $payload['clipped'] ) ? $payload['clipped'] : array(),
				'superseded' => isset( $payload['superseded'] ) ? (int) $payload['superseded'] : 0,
			);

			$chars = strlen( $user );
			foreach ( $history as $turn ) {
				$chars += isset( $turn['content'] ) ? strlen( (string) $turn['content'] ) : 0;
			}

			$fill_tokens  = self::chars_to_tokens( $chars + max( 0, (int) $overhead_chars ) );
			$fill_compact = $fill_tokens >= (int) floor( self::CONTEXT_BUDGET_TOKENS * self::COMPACT_FILL_RATIO );
			$needs         = count( $history ) > self::COMPACT_HISTORY_THRESHOLD
				|| $chars > self::COMPACT_CHAR_THRESHOLD
				|| $fill_compact;
			if ( ! $needs ) {
				return array_merge(
					$notes,
					array(
						'history'   => $history,
						'user'      => $user,
						'compacted' => false,
					)
				);
			}

			$keep_n = min( self::COMPACT_KEEP_RECENT, count( $history ) );
			$keep   = $keep_n > 0 ? array_slice( $history, -1 * $keep_n ) : array();
			$old    = $keep_n > 0 ? array_slice( $history, 0, -1 * $keep_n ) : $history;

			$summary = self::build_extractive_context_summary( $session_id, $old );
			if ( $persist_compact && class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::set_context_summary( $session_id, $summary );
			}

			$compacted_history = array();
			if ( '' !== $summary ) {
				$compacted_history[] = array(
					'role'    => 'user',
					'content' => "[Earlier in this session — compact summary; current plan, artifact keys, and latest goal are pinned separately and must not be ignored]\n"
						. $summary,
				);
				$compacted_history[] = array(
					'role'    => 'assistant',
					'content' => 'Understood. I will continue from the pinned plan, artifacts, and latest user goal.',
				);
			}

			foreach ( $keep as $turn ) {
				$compacted_history[] = $turn;
			}

			if ( count( $compacted_history ) > self::MAX_HISTORY_TURNS ) {
				$compacted_history = array_slice( $compacted_history, -1 * self::MAX_HISTORY_TURNS );
			}

			if ( $persist_compact && class_exists( 'Ahentic_Session_Repository' ) ) {
				Ahentic_Session_Repository::append_trace(
					$session_id,
					'context_compact',
					'Compacted older chat/tool context for this think',
					array(
						'old_turns'   => count( $old ),
						'kept_turns'  => count( $keep ),
						'summary_len' => strlen( $summary ),
						'fill_tokens' => $fill_tokens,
						'fill_trigger'=> $fill_compact,
					),
					(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
				);
			}

			return array_merge(
				$notes,
				array(
					'history'   => $compacted_history,
					'user'      => $user,
					'compacted' => true,
				)
			);
		}

		/**
		 * Extractive rolling summary of older turns (no extra LLM call).
		 *
		 * @param int   $session_id Session ID.
		 * @param array $turns      Older history turns.
		 * @return string
		 */
		private static function build_extractive_context_summary( $session_id, array $turns ) {
			$prior = trim( (string) Ahentic_Session_Repository::get_context_summary( $session_id ) );
			$lines = array();
			if ( '' !== $prior ) {
				$lines[] = 'Previous compact notes: ' . self::excerpt( $prior, 800 );
			}

			foreach ( $turns as $turn ) {
				if ( ! is_array( $turn ) ) {
					continue;
				}
				$role = isset( $turn['role'] ) ? (string) $turn['role'] : 'user';
				$text = isset( $turn['content'] ) ? trim( (string) $turn['content'] ) : '';
				if ( '' === $text ) {
					continue;
				}
				$label = ( 'assistant' === $role || 'model' === $role ) ? 'Assistant/tool' : 'User';
				// Tool-shaped lines get a tighter excerpt.
				$limit = ( 0 === strpos( $text, '[Ability result:' ) ) ? 280 : 420;
				$lines[] = $label . ': ' . self::excerpt( $text, $limit );
			}

			$summary = implode( "\n", $lines );
			if ( strlen( $summary ) > self::COMPACT_SUMMARY_MAX_CHARS ) {
				$summary = self::utf8_byte_suffix( $summary, self::COMPACT_SUMMARY_MAX_CHARS );
				$nl      = strpos( $summary, "\n" );
				if ( false !== $nl && $nl < 200 ) {
					$summary = self::ensure_utf8( substr( $summary, $nl + 1 ) );
				}
			}
			return trim( self::ensure_utf8( $summary ) );
		}

		/**
		 * Always-retained mid-run pins: latest goal + plan (artifacts added separately).
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function pinned_run_context_for_prompt( $session_id ) {
			$parts = array();

			$goal = self::latest_user_goal_excerpt( $session_id );
			if ( '' !== $goal ) {
				$parts[] = 'Latest user goal: ' . $goal;
			}

			$plan = Ahentic_Session_Repository::get_plan( $session_id );
			if ( is_array( $plan ) && ! empty( $plan['steps'] ) && is_array( $plan['steps'] ) ) {
				$title = isset( $plan['title'] ) ? trim( (string) $plan['title'] ) : '';
				$step_bits = array();
				foreach ( $plan['steps'] as $step ) {
					if ( ! is_array( $step ) ) {
						continue;
					}
					$status  = isset( $step['status'] ) ? (string) $step['status'] : 'pending';
					$content = isset( $step['content'] ) ? trim( (string) $step['content'] ) : '';
					if ( '' === $content ) {
						continue;
					}
					$step_bits[] = '[' . $status . '] ' . $content;
				}
				if ( ! empty( $step_bits ) ) {
					$parts[] = 'Current plan'
						. ( '' !== $title ? ' (“' . $title . '”)' : '' )
						. ': '
						. implode( '; ', $step_bits );
				}
			}

			if ( empty( $parts ) ) {
				return '';
			}

			return "---\nPinned run context (must retain — do not drop):\n- " . implode( "\n- ", $parts );
		}

		/**
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function latest_user_goal_excerpt( $session_id ) {
			$stored  = Ahentic_Session_Repository::get_active_goal( $session_id );
			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			$goal    = Ahentic_Job_Resume::active_goal_from_entries( $entries, $stored );
			if ( '' === $goal ) {
				return '';
			}
			return self::excerpt( $goal, 400 );
		}

		/**
		 * Format stored sidebar page context for the model user turn.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function format_page_context_for_prompt( $session_id ) {
			$ctx = Ahentic_Session_Repository::get_page_context( $session_id );
			if ( empty( $ctx ) || ! is_array( $ctx ) ) {
				return '';
			}

			$lines = array( '---', 'Active browser page context (user’s open tab; trust this over guessing):' );

			if ( ! empty( $ctx['url'] ) ) {
				$lines[] = '- url: ' . (string) $ctx['url'];
			}
			if ( ! empty( $ctx['title'] ) ) {
				$lines[] = '- document_title: ' . (string) $ctx['title'];
			}
			if ( array_key_exists( 'isAdmin', $ctx ) ) {
				$lines[] = '- is_admin: ' . ( ! empty( $ctx['isAdmin'] ) ? 'true' : 'false' );
			}

			$in_editor = ! empty( $ctx['is_block_editor'] );
			$lines[]   = '- is_block_editor: ' . ( $in_editor ? 'true' : 'false' );

			if ( $in_editor ) {
				$lines[] = '- post_id: ' . ( isset( $ctx['post_id'] ) && null !== $ctx['post_id'] && '' !== $ctx['post_id']
					? (string) (int) $ctx['post_id']
					: 'null (new unsaved document)' );
				if ( ! empty( $ctx['post_type'] ) ) {
					$lines[] = '- post_type: ' . (string) $ctx['post_type'];
				}
				if ( array_key_exists( 'editor_title', $ctx ) ) {
					$lines[] = '- editor_title: ' . ( '' !== (string) $ctx['editor_title']
						? (string) $ctx['editor_title']
						: '(empty)' );
				}
				if ( ! empty( $ctx['status'] ) ) {
					$lines[] = '- status: ' . (string) $ctx['status'];
				}
				$lines[] = '- is_new: ' . ( ! empty( $ctx['is_new'] ) ? 'true' : 'false' );
				$lines[] = '- is_dirty: ' . ( ! empty( $ctx['is_dirty'] ) ? 'true' : 'false' );
				$lines[] = '- blocks_count: ' . (string) (int) ( isset( $ctx['blocks_count'] ) ? $ctx['blocks_count'] : 0 );
				$lines[] = '- routing: is_block_editor=true — edit THIS open document with ahentic-browser/* '
					. '(update-post-document, set-blocks, insert-blocks, replace-blocks, delete-blocks, move-blocks, update-block-attributes). '
					. 'Do not ahentic/update-post (body, title, excerpt, or slug) for this document while the editor is open. '
					. 'Do not ahentic/create-post unless the user explicitly wants a separate post/page. '
					. 'Do not ahentic-browser/save-post unless the user explicitly asks to save/publish. '
					. 'Pass real block objects {name, attributes, innerBlocks} — never bracket stubs, plain-text descriptions, or clientId hashes. '
					. 'Block addressing uses short refs (b1, b2, …) from get-blocks/get-selection; for a full rewrite prefer set-blocks (no refs needed). '
					. 'Remove blocks with delete-blocks (not empty replace). Reorder with move-blocks (prefer before_ref/after_ref). '
					. 'Leaving the content (featured/excerpt/title/slug) is write destination then usually delete-blocks — not move-blocks.';
				$cheatsheet = self::format_core_blocks_cheatsheet_for_prompt();
				if ( '' !== $cheatsheet ) {
					$lines[] = $cheatsheet;
				}
			} else {
				$lines[] = '- routing: Block editor is not open — prefer server content abilities '
					. '(ahentic/create-post, ahentic/update-post, ahentic/set-post-status) for drafts and body edits. '
					. 'Pass real post content — never bracket stubs or shorthand placeholders.';
			}

			return implode( "\n", $lines );
		}

		/**
		 * Load curated core-block cheatsheet (distilled from Gutenberg block.json).
		 *
		 * @return array<string, mixed>
		 */
		private static function load_core_blocks_cheatsheet() {
			static $cached = null;
			if ( null !== $cached ) {
				return $cached;
			}
			$cached = array();
			$path   = plugin_dir_path( AHENTIC_FILE ) . 'src/data/core-blocks-cheatsheet.json';
			if ( ! is_readable( $path ) ) {
				return $cached;
			}
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin data file.
			if ( false === $raw || '' === $raw ) {
				return $cached;
			}
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				return $cached;
			}
			$cached = $data;
			return $cached;
		}

		/**
		 * Compact core-block cookbook + cheatsheet for editor turns.
		 *
		 * @return string
		 */
		private static function format_core_blocks_cheatsheet_for_prompt() {
			$data = self::load_core_blocks_cheatsheet();
			if ( empty( $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
				return '';
			}

			$lines   = array();
			$lines[] = '- core_blocks_cookbook (curated; source Gutenberg block-library — do not call get-block-type for these unless an update fails):';
			$lines[] = '  Rules: rich-text attrs accept HTML strings; address blocks with refs (b1, b2) from get-blocks — never clientId hashes; skip get-block-type for core/* text blocks; prefer set-blocks for full-document rewrites.';

			foreach ( $data['blocks'] as $name => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$parts = array();
				foreach ( $attrs as $attr_key => $attr_desc ) {
					$parts[] = $attr_key . '=' . (string) $attr_desc;
				}
				$rich = isset( $block['rich_text'] ) && is_array( $block['rich_text'] ) ? $block['rich_text'] : array();
				$tip  = isset( $block['tip'] ) ? (string) $block['tip'] : '';
				$line = '  • ' . (string) $name;
				if ( ! empty( $parts ) ) {
					$line .= ' — ' . implode( '; ', $parts );
				}
				if ( ! empty( $rich ) ) {
					$line .= ' [rich-text: ' . implode( ',', array_map( 'strval', $rich ) ) . ']';
				}
				if ( '' !== $tip ) {
					$line .= ' — ' . $tip;
				}
				$lines[] = $line;
			}

			if ( ! empty( $data['source'] ) ) {
				$lines[] = '  Ref: ' . (string) $data['source'];
			}

			return implode( "\n", $lines );
		}

		/**
		 * Prompt note when writes still need verification.
		 *
		 * @param int $session_id Session ID.
		 * @return string
		 */
		private static function verify_context_for_prompt( $session_id ) {
			$notes = array();

			$unapplied = Ahentic_Finish_Gate::ready_unapplied_content_artifacts( $session_id );
			if ( ! empty( $unapplied ) ) {
				$notes[] = 'Ready artifacts not yet applied: '
					. implode( ', ', $unapplied )
					. '. Set next="use_tools" with set-blocks / create-post / update-post using {"from_memory":"<key>"} before next="reply".';
			}

			$findings = Ahentic_Session_Repository::get_verify_pending( $session_id );
			if ( ! empty( $findings ) ) {
				$chars = 0;
				foreach ( $findings as $item ) {
					$chars = max( $chars, isset( $item['chars'] ) ? (int) $item['chars'] : 0 );
				}
				$notes[] = sprintf(
					'The body you have written so far is too thin for this long-form request (%1$d characters of text, minimum %2$d). '
						. 'Keep writing: expand it with real sections via set-blocks / insert-blocks / update-post. '
						. 'Do not set next="reply" until the body is complete. Do not call a readonly ability to re-check it — the write result reports the size.',
					$chars,
					Ahentic_Finish_Gate::LONG_FORM_MIN_CHARS
				);
			}

			if ( empty( $notes ) ) {
				return '';
			}
			return "---\n" . implode( "\n", $notes );
		}

		/**
		 * Abilities whose older results are collapsed to a stub when a newer result exists.
		 *
		 * Live editor / page snapshots describe one screen, so only the newest is meaningful.
		 * Explore/playbook/attr-patch results are also superseded — keeping every copy in trailing
		 * + history was measured at multi-k tokens per think (list-content, get-wordpress-guidance,
		 * repeated update-block-attributes).
		 *
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function ability_is_prompt_supersedable( $name ) {
			return in_array(
				(string) $name,
				array(
					'ahentic-browser/get-blocks',
					'ahentic-browser/get-editor-state',
					'ahentic-browser/get-selection',
					'ahentic-browser/get-current-page',
					'ahentic-browser/get-visible-page',
					'ahentic-browser/update-block-attributes',
					'ahentic/get-wordpress-guidance',
					'ahentic/list-content',
					'ahentic/get-content',
					'ahentic/get-content-summary',
					'ahentic/list-plugins',
					'ahentic/list-media',
				),
				true
			);
		}

		/**
		 * Abilities whose full bodies are research fuel (collapse after draft staged/applied).
		 *
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function ability_is_research_body( $name ) {
			return in_array(
				(string) $name,
				array(
					'ahentic/get-content',
					'ahentic/get-content-summary',
					'ahentic/list-content',
				),
				true
			);
		}

		/**
		 * Collapse a research tool JSON body to id/title/url(+snippet) for the prompt.
		 *
		 * @param string $ability Ability name.
		 * @param string $content Raw tool content.
		 * @return string
		 */
		public static function compact_research_tool_body( $ability, $content ) {
			$content = (string) $content;
			$decoded = json_decode( $content, true );
			if ( ! is_array( $decoded ) ) {
				return self::truncate_tool_result_for_prompt( $content, self::MAX_TOOL_RESULT_CHARS_RESEARCH );
			}

			if ( 'ahentic/list-content' === (string) $ability && ! empty( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
				$items = array();
				foreach ( array_slice( $decoded['items'], 0, 15 ) as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$items[] = array(
						'id'       => isset( $item['id'] ) ? (int) $item['id'] : 0,
						'title'    => isset( $item['title'] ) ? (string) $item['title'] : '',
						'view_url' => isset( $item['view_url'] ) ? (string) $item['view_url'] : '',
					);
				}
				$card = array(
					'items'   => $items,
					'note'    => 'Research collapsed — titles/urls only (draft already staged).',
				);
				$json = function_exists( 'wp_json_encode' )
					? wp_json_encode( $card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
					: json_encode( $card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				return is_string( $json ) ? $json : self::truncate_tool_result_for_prompt( $content, self::MAX_TOOL_RESULT_CHARS_RESEARCH );
			}

			$card = array(
				'id'       => isset( $decoded['id'] ) ? (int) $decoded['id'] : 0,
				'title'    => isset( $decoded['title'] ) ? (string) $decoded['title'] : '',
				'type'     => isset( $decoded['type'] ) ? (string) $decoded['type'] : '',
				'status'   => isset( $decoded['status'] ) ? (string) $decoded['status'] : '',
				'view_url' => isset( $decoded['view_url'] ) ? (string) $decoded['view_url'] : '',
				'note'     => 'Research collapsed — body omitted (draft already staged).',
			);
			$json = function_exists( 'wp_json_encode' )
				? wp_json_encode( $card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: json_encode( $card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			return is_string( $json ) ? $json : self::truncate_tool_result_for_prompt( $content, self::MAX_TOOL_RESULT_CHARS_RESEARCH );
		}

		/**
		 * Whether research get-content / list-content bodies should collapse for this think.
		 *
		 * True when a content artifact is ready or already applied — research fuel is spent.
		 *
		 * @param int $session_id Session ID.
		 * @return bool
		 */
		public static function session_should_collapse_research( $session_id ) {
			$session_id = (int) $session_id;
			if ( $session_id < 1 || ! class_exists( 'Ahentic_Session_Artifacts' ) ) {
				return false;
			}
			return Ahentic_Session_Artifacts::has_content_artifact_status(
				$session_id,
				array(
					Ahentic_Session_Artifacts::STATUS_READY,
					Ahentic_Session_Artifacts::STATUS_APPLIED,
				)
			);
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		private static function ability_is_live_editor_snapshot( $name ) {
			return in_array(
				(string) $name,
				array(
					'ahentic-browser/get-blocks',
					'ahentic-browser/get-editor-state',
					'ahentic-browser/get-selection',
				),
				true
			);
		}

		/**
		 * Char cap for a tool result in the assembled prompt.
		 *
		 * Trailing (since last user message) stays generous so the current step can see facts.
		 * History is tightly capped — stale get-blocks / guidance were measured at 1.3–1.6k tokens each.
		 *
		 * @param string $ability     Ability name.
		 * @param bool   $is_trailing Whether this result is after the latest user message.
		 * @return int
		 */
		public static function tool_result_cap_for_prompt( $ability, $is_trailing ) {
			if ( ! $is_trailing ) {
				return self::MAX_TOOL_RESULT_CHARS_HISTORY;
			}
			if ( self::ability_is_live_editor_snapshot( $ability ) ) {
				return self::MAX_TOOL_RESULT_CHARS_SNAPSHOT;
			}
			if ( 'ahentic/get-wordpress-guidance' === (string) $ability ) {
				return min( self::MAX_TOOL_RESULT_CHARS, 3500 );
			}
			// List cards are now slim, but a full page of results can still blow past
			// what a link-picking step needs in trailing context.
			if ( 'ahentic/list-content' === (string) $ability ) {
				return min( self::MAX_TOOL_RESULT_CHARS, 3500 );
			}
			return self::MAX_TOOL_RESULT_CHARS;
		}

		/**
		 * Entry index of the newest result per supersedable ability.
		 *
		 * @param array $entries Session entries.
		 * @return array<string, int>
		 */
		private static function latest_supersedable_tool_indexes( array $entries ) {
			$latest = array();
			foreach ( $entries as $i => $entry ) {
				if ( 'tool' !== ( isset( $entry['role'] ) ? $entry['role'] : '' ) ) {
					continue;
				}
				if ( ! empty( $entry['meta']['error'] ) ) {
					continue;
				}
				$ability = isset( $entry['meta']['ability'] ) ? (string) $entry['meta']['ability'] : '';
				if ( self::ability_is_prompt_supersedable( $ability ) ) {
					$latest[ $ability ] = $i;
				}
			}
			return $latest;
		}

		/**
		 * @param array $entries Session entries.
		 * @return int Index of last user entry, or -1.
		 */
		private static function last_user_entry_index( array $entries ) {
			$last = -1;
			foreach ( $entries as $i => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				if ( ! empty( $entry['meta']['error'] ) ) {
					continue;
				}
				if ( 'user' !== ( isset( $entry['role'] ) ? $entry['role'] : '' ) ) {
					continue;
				}
				if ( ! empty( $entry['meta']['thought_process'] ) || ! empty( $entry['meta']['intermediate'] ) ) {
					continue;
				}
				$last = $i;
			}
			return $last;
		}

		/**
		 * Entry index of the newest result per live-editor snapshot ability.
		 *
		 * @param array $entries Session entries.
		 * @return array<string, int|string>
		 * @deprecated Use latest_supersedable_tool_indexes(); kept for any external callers.
		 */
		private static function latest_live_editor_snapshots( array $entries ) {
			$latest = array();
			foreach ( self::latest_supersedable_tool_indexes( $entries ) as $ability => $i ) {
				if ( self::ability_is_live_editor_snapshot( $ability ) ) {
					$latest[ $ability ] = $i;
				}
			}
			return $latest;
		}

		/**
		 * Cap tool-result JSON injected into the next think prompt.
		 *
		 * Byte caps must not split multi-byte UTF-8 — Core AI json_encodes the
		 * prompt and fails with "Malformed UTF-8 characters" otherwise.
		 *
		 * @param string $content Raw tool entry content.
		 * @param int    $max     Optional cap override (0 uses the default).
		 * @return string
		 */
		public static function truncate_tool_result_for_prompt( $content, $max = 0 ) {
			$content = (string) $content;
			$max     = (int) $max > 0 ? (int) $max : self::MAX_TOOL_RESULT_CHARS;

			if ( strlen( $content ) <= $max ) {
				return self::ensure_utf8( $content );
			}
			return rtrim( self::utf8_byte_slice( $content, 0, $max - 1 ) ) . '…';
		}

		/**
		 * Truncate text for trace payloads / compact notes.
		 *
		 * @param string $text Text.
		 * @param int    $max  Max length in bytes (ellipsis may add UTF-8 bytes).
		 * @return string
		 */
		public static function excerpt( $text, $max = 120 ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
			if ( strlen( $text ) <= $max ) {
				return self::ensure_utf8( $text );
			}
			return rtrim( self::utf8_byte_slice( $text, 0, $max - 1 ) ) . '…';
		}

		/**
		 * Return a valid UTF-8 string, stripping malformed bytes.
		 *
		 * @param string $text Possibly invalid UTF-8.
		 * @return string
		 */
		public static function ensure_utf8( $text ) {
			$text = (string) $text;
			if ( '' === $text ) {
				return '';
			}
			if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $text, 'UTF-8' ) ) {
				return $text;
			}
			if ( function_exists( 'wp_check_invalid_utf8' ) ) {
				$clean = wp_check_invalid_utf8( $text, true );
				if ( is_string( $clean ) && ( '' === $clean || ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $clean, 'UTF-8' ) ) ) ) {
					return $clean;
				}
			}
			if ( function_exists( 'iconv' ) ) {
				$clean = @iconv( 'UTF-8', 'UTF-8//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional strip of invalid sequences.
				if ( is_string( $clean ) ) {
					return $clean;
				}
			}
			return (string) preg_replace( '/[\x80-\xFF]+/', '', $text );
		}

		/**
		 * Slice by byte length without splitting a UTF-8 character.
		 *
		 * @param string $text   Source.
		 * @param int    $start  Byte offset.
		 * @param int    $length Max bytes.
		 * @return string
		 */
		public static function utf8_byte_slice( $text, $start, $length ) {
			$text   = (string) $text;
			$start  = (int) $start;
			$length = (int) $length;
			if ( $length <= 0 ) {
				return '';
			}
			if ( function_exists( 'mb_strcut' ) ) {
				return (string) mb_strcut( $text, $start, $length, 'UTF-8' );
			}
			return self::ensure_utf8( substr( $text, $start, $length ) );
		}

		/**
		 * Keep the last $max bytes of $text without splitting a UTF-8 character.
		 *
		 * @param string $text Source.
		 * @param int    $max  Max bytes to keep.
		 * @return string
		 */
		public static function utf8_byte_suffix( $text, $max ) {
			$text = (string) $text;
			$max  = (int) $max;
			if ( $max <= 0 ) {
				return '';
			}
			$len = strlen( $text );
			if ( $len <= $max ) {
				return self::ensure_utf8( $text );
			}
			$start = $len - $max;
			// Skip UTF-8 continuation bytes so the suffix starts on a codepoint.
			while ( $start < $len && ( ord( $text[ $start ] ) & 0xC0 ) === 0x80 ) {
				++$start;
			}
			return self::ensure_utf8( substr( $text, $start ) );
		}

		/**
		 * Classify a history turn into a context bucket key.
		 *
		 * @param array $turn History turn.
		 * @return string Bucket key.
		 */
		public static function bucket_for_turn( array $turn ) {
			$content = isset( $turn['content'] ) ? (string) $turn['content'] : '';
			if ( 0 === strpos( $content, '[Earlier in this session' ) ) {
				return 'compacted_summary';
			}
			if ( 0 === strpos( $content, '[Ability result:' ) ) {
				return 'tool_results';
			}
			return 'chat_turns';
		}

		/**
		 * @param array $turn  History turn.
		 * @param array $chars Bucket char map (by ref).
		 */
		private static function accumulate_turn_chars( array $turn, array &$chars ) {
			$len = isset( $turn['content'] ) ? strlen( (string) $turn['content'] ) : 0;
			$key = self::bucket_for_turn( $turn );
			if ( ! isset( $chars[ $key ] ) ) {
				$chars[ $key ] = 0;
			}
			$chars[ $key ] += $len;
		}

		/**
		 * Split the latest user payload into chat vs trailing tool results.
		 *
		 * @param string $user  User payload from build_chat_payload.
		 * @param array  $chars Bucket char map (by ref).
		 */
		private static function accumulate_user_payload_chars( $user, array &$chars ) {
			$user   = (string) $user;
			$marker = "---\nAbility results from this run";
			$pos    = strpos( $user, $marker );
			if ( false === $pos ) {
				$chars['chat_turns'] += strlen( $user );
				return;
			}
			$chars['chat_turns']   += $pos;
			$chars['tool_results'] += strlen( $user ) - $pos;
		}

		/**
		 * Build REST/UI context usage from bucket character counts.
		 *
		 * @param array $chars Bucket → char length.
		 * @return array
		 */
		public static function usage_from_bucket_chars( array $chars ) {
			$order = array(
				'system_prompt',
				'ability_schemas',
				'chat_turns',
				'tool_results',
				'page_context',
				'plan_artifacts',
				'compacted_summary',
			);
			$buckets = array();
			$total_chars = 0;
			foreach ( $order as $key ) {
				$c = isset( $chars[ $key ] ) ? max( 0, (int) $chars[ $key ] ) : 0;
				$total_chars += $c;
				$buckets[ $key ] = array(
					'chars'  => $c,
					'tokens' => self::chars_to_tokens( $c ),
				);
			}

			$used   = self::chars_to_tokens( $total_chars );
			$budget = self::CONTEXT_BUDGET_TOKENS;
			$pct    = $budget > 0 ? (int) min( 100, (int) round( ( $used / $budget ) * 100 ) ) : 0;

			return array(
				'budgetTokens' => $budget,
				'usedTokens'   => $used,
				'usedChars'    => $total_chars,
				'percent'      => $pct,
				'buckets'      => $buckets,
			);
		}
	}
}
