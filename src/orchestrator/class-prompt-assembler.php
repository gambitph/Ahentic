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
	 * truncate_tool_result_for_prompt) are part of the test surface.
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
		const MAX_TOOL_RESULT_CHARS_SNAPSHOT = 24000;

		/**
		 * Deep entry: system prompt + compacted history/user turn for one LLM think.
		 *
		 * @param int        $session_id  Session ID.
		 * @param string     $mode        Agent mode (agent|ask).
		 * @param string     $user_suffix Optional text appended to the user message (retries).
		 * @param array|null $extra_turn  Optional prior assistant turn to inject into history.
		 * @return array{
		 *   system: string,
		 *   history: array,
		 *   user: string,
		 *   clipped: array,
		 *   compacted: bool,
		 *   superseded: int
		 * }
		 */
		public static function for_llm( $session_id, $mode, $user_suffix = '', $extra_turn = null ) {
			$system  = self::system_prompt( $mode, $session_id );
			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			$built   = self::build_chat_payload( $entries );
			$built   = self::apply_context_compaction( $session_id, $built );
			$history = $built['history'];
			$user    = $built['user'];

			$page_context_note = self::format_page_context_for_prompt( $session_id );
			if ( '' !== $page_context_note ) {
				$user .= "\n\n" . $page_context_note;
			}

			if ( class_exists( 'Ahentic_Session_Artifacts' ) ) {
				$artifacts_note = Ahentic_Session_Artifacts::format_for_prompt( $session_id );
				if ( '' !== $artifacts_note ) {
					$user .= "\n\n" . $artifacts_note;
				}
			}

			$verify_note = self::verify_context_for_prompt( $session_id );
			if ( '' !== $verify_note ) {
				$user .= "\n\n" . $verify_note;
			}

			$pinned = self::pinned_run_context_for_prompt( $session_id );
			if ( '' !== $pinned ) {
				$user = $pinned . "\n\n" . $user;
			}

			if ( is_string( $user_suffix ) && '' !== trim( $user_suffix ) ) {
				$user .= "\n\n" . trim( $user_suffix );
			}

			// Accumulated tool results push the system-prompt format spec far out of recency,
			// so the protocol is re-anchored as the last thing the model reads every turn.
			$user .= "\n\n" . '[Format reminder] Output exactly one <<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>> block FIRST '
				. '(intention, thinking, tools_planned, next), then the short user-facing reply. '
				. 'This applies to every turn, including verification and read-back steps — never reply with prose only.';

			if ( is_array( $extra_turn ) && ! empty( $extra_turn['content'] ) ) {
				$history[] = array(
					'role'    => 'assistant',
					'content' => (string) $extra_turn['content'],
				);
			}

			return array(
				'system'     => $system,
				'history'    => $history,
				'user'       => $user,
				'clipped'    => isset( $built['clipped'] ) && is_array( $built['clipped'] ) ? $built['clipped'] : array(),
				'compacted'  => ! empty( $built['compacted'] ),
				'superseded' => isset( $built['superseded'] ) ? (int) $built['superseded'] : 0,
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
			$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$site_url   = home_url( '/' );
			$available  = Ahentic_Abilities::available_for_mode( $mode );
			$tools_list = implode( ', ', $available );
			$admin_map  = Ahentic_Abilities::format_admin_links_for_prompt();

			$base = 'You are Ahentic, an AI workspace agent for WordPress. '
				. 'You help the user understand and improve their WordPress site. '
				. "Current site (hint only): {$site_name} ({$site_url}). "
				. 'Be concise, practical, and specific to WordPress when possible. '
				. 'Do not invent that you changed the site unless a tool confirmed it. '
				. 'When you need verified site data, call tools — do not guess plugin lists or stack details.';

			$readonly_tool_guidance = 'Prefer ahentic/get-site-snapshot when you need the site name, theme, environment, active plugins, or admin_links. '
				. 'Prefer ahentic/get-site-health for Site Health counts/issues; ahentic/get-option for allowlisted options (blog_public, blogdescription/tagline, permalink_structure, show_on_front, etc.). '
				. 'For theme Customizer / appearance settings: call ahentic/get-settings-context first (block vs classic + surfaces). '
				. 'On classic themes use ahentic/list-settings with a required query, section, or prefix filter (never unfiltered), then ahentic/get-setting for values (large values summarize unless raw:true). '
				. 'Prefer ahentic/list-plugins for installed active+inactive plugins; ahentic/search-plugins to search wordpress.org (pass query like "SEO"). '
				. 'When unsure about WordPress best practice — plugins vs custom code/theme edits, SEO plugin choice, cleanup, pre-launch gaps, or editor vs server content edits — '
				. 'call ahentic/get-wordpress-guidance before inventing a risky approach. '
				. 'Pass {"topic":"plugin-hygiene"} (ids: plugin-hygiene, custom-code-snippets, pre-launch-gaps, seo-decisioning, safe-cleanup, editor-vs-server, editor-leave-canvas, editor-wrap-blocks, web-image-fit, post-title-headings) '
				. 'or {"query":"add google analytics"}; omit both to list the catalog. Follow the returned guidance, then use site tools for facts. '
				. 'Tool priority: prefer server (ahentic/*) abilities when they can fully do the job. '
				. 'Use ahentic-browser/* only when you need the live open tab, block editor APIs, or the user’s browser session — or when no server ability exists. '
				. 'Never use the browser to simulate a server ability (e.g. do not click Install when ahentic/install-plugin exists). '
				. 'Prefer ahentic/get-admin-context or ahentic-browser/get-current-page for screen identity (“which page am I on?”, white screen / broken admin URL). '
				. 'Prefer ahentic-browser/get-visible-page when the user asks what is on the screen, to explain the UI, notices, buttons, or form fields currently visible. '
				. 'Active browser page context is attached to each turn when available (URL + is_block_editor / post_id / post_type / editor_title). '
				. 'Trust the LATEST attached page context over earlier assumptions about where the user is; only re-call get-current-page / get-editor-state if you need a fresh read. '
				. 'CRITICAL — content routing by page context: '
				. 'If is_block_editor=true, make content/title/structure changes with ahentic-browser/* '
				. '(update-post-document for title/excerpt/slug, set-blocks, insert-blocks, replace-blocks, delete-blocks, move-blocks, update-block-attributes, get-selection / get-blocks as needed) '
				. 'so edits appear live in the open canvas. Do NOT use ahentic/update-post for body, title, excerpt, or slug on that open document while the editor is open. '
				. 'Use ahentic/create-post only to create a NEW post/page that is not the open document, or when the block editor is not open. '
				. 'After create-post, if a later turn’s page context shows the user in the block editor (any document), continue content work with browser tools. '
				. 'If the block editor is not open, prefer server content abilities (create-post / update-post / set-post-status) as appropriate. '
				. 'If page context is missing is_block_editor but the URL looks like post.php / post-new.php / site-editor, call get-editor-state before create-post. '
				. 'Do NOT call ahentic-browser/save-post after editor edits unless the user explicitly asks to save, publish, update the live site, or persist changes — '
				. 'Gutenberg already keeps unsaved edits in the canvas; stop after inserting/updating blocks and let the user save. '
				. 'CRITICAL — real block objects only: insert/replace/set-blocks must pass an array of {name, attributes, innerBlocks} '
				. '(JavaScript createBlock shape). Never pass plain-text descriptions, bracket stubs like [full article], or “block structure” shorthand '
				. 'unless the user explicitly asked for placeholders. For a full article rewrite prefer ahentic-browser/set-blocks (replaces the whole document). '
				. 'To remove blocks use ahentic-browser/delete-blocks (refs or selection) — do not pass an empty tree to replace-blocks/set-blocks. '
				. 'To reorder or reparent within the canvas use move-blocks with before_ref/after_ref (preferred) or index+root_ref. '
				. 'To move content OUT of the body (featured image, excerpt, title/slug, etc.) write the destination ability then usually delete-blocks the source — that is not move-blocks. '
				. 'For long articles, prefer ahentic/stage-artifact then set-blocks/create-post with {"from_memory":"article_draft"} '
				. '(or chunked insert-blocks/replace-blocks one section per step) — do not re-paste a huge draft from chat into tools_planned. '
				. 'Use get-block-type ONLY for non-core (third-party) blocks or after an attribute update fails with unknown keys — never as a first step for core/heading, core/paragraph, core/button, etc. '
				. 'get-block-type input is {name:"core/heading"} (block name), not a block ref. '
				. 'Rich-text attributes (content/text/caption/citation): pass HTML strings; get-blocks returns them as HTML (and may include a plain preview). '
				. 'CRITICAL — block refs: get-blocks / get-selection return short refs (b1, b2, …). When calling tools that take ref / refs / after_ref / before_ref / root_ref, '
				. 'copy those refs EXACTLY from the latest get-blocks / get-selection result. Never invent refs and never send Gutenberg clientId UUID hashes. '
				. 'If a tool returns missing refs / block_not_found, re-call get-blocks (or get-selection) and use the fresh refs — do not guess. '
				. 'CRITICAL — never re-check your own writes: tool results are authoritative. After a successful create-post / update-post / '
				. 'set-blocks / insert-blocks / replace-blocks / delete-blocks / move-blocks (or any other mutate), do NOT call get-content, get-blocks, or any other readonly '
				. 'ability to confirm it landed — the write result already reports what was persisted (content_text_chars / text_chars / '
				. 'inserted_count / before). Go straight to next="reply". '
				. 'If a write result contains "thin": true, the body is too small for the long-form work requested — keep writing '
				. '(expand or restage it) instead of replying. Staging (stage-artifact) is not done — still apply with from_memory. '
				. 'A page snapshot (get-visible-page / get-current-page) shows the page as rendered when it last loaded, so it can never '
				. 'confirm a change made after that — never use it to verify a write, a plugin activation, or a setting. If a stale notice is '
				. 'still on screen, say so and tell the user to reload rather than re-reading the screen. '
				. 'If a tool returns error placeholder_content / ahentic_placeholder_content / ahentic_use_browser_editor, fix the approach '
				. '(real block objects and/or browser tools) — do not claim the article was written. '
				. 'Core block cookbook (common attrs): '
				. 'core/heading → content (HTML), level (1–6; for posts/pages prefer 2+ — title field is the H1); '
				. 'core/paragraph → content (HTML); '
				. 'core/button → text (HTML label), url; '
				. 'core/image → url, alt, id, caption (HTML); '
				. 'core/list + core/list-item → list-item content (HTML); '
				. 'core/html → content (raw HTML escape hatch). '
				. 'To add/fix image alt text in the open editor: get-blocks (image-looking blocks return compact media attrs — use the real keys shown, e.g. url/alt/id or imageUrl/imageAlt) → '
				. 'ahentic/describe-image with exactly one of attachment_id (numeric id attr) or url → then '
				. 'ahentic-browser/update-block-attributes on that ref with the block\'s alt key (often alt, mediaAlt, or imageAlt) set to alt_text_suggestion. '
				. 'Do not ask the user to describe the image when describe-image is available; do not guess alt from the page text alone. '
				. 'When the block editor is open, a fuller cheatsheet is attached with page context. '
				. 'Prefer ahentic/create-post + ahentic/update-post + ahentic/set-post-status when the block editor is NOT open (server-side drafts/publish). '
				. 'When a long draft is ready, stage it with ahentic/stage-artifact (key e.g. article_draft, kind blocks|html|post_content, '
				. 'payload: for blocks use {"blocks":[{name,attributes,innerBlocks},…]} — never put the body under content/blocks at the top level; '
				. 'while first writing a draft, chunk with mode=append + complete=false, then complete=true; '
				. 'when revising an already-ready artifact or rewriting the whole article, use mode=replace or a new key — never append onto a finished draft), '
				. 'then apply with set-blocks / create-post / update-post using {"from_memory":"article_draft"} — do not invent keys; list-artifacts shows what is staged. '
				. 'Prefer ahentic/http-fetch to GET a URL. For public pages omit as_user. For wp-admin / logged-in same-site pages pass {"url":"…","as_user":true} — that runs in the user’s browser with their session. Judge soft white screens by success_marker/body, not status alone. '
				. 'Prefer ahentic/get-debug-log for PHP fatals when WP_DEBUG_LOG is available. '
				. 'Prefer ahentic/search-content to find posts/pages by phrase (title, body, or meta); '
				. 'ahentic/list-content to browse by type/status; ahentic/get-content to read one post (body + safe meta). '
				. 'Prefer ahentic/update-post (Agent mode, editor not open) to change content/title/excerpt/slug/meta (does not change publish status); '
				. 'ahentic/set-post-status to publish/schedule/trash (HITL). '
				. 'For custom fields / WooCommerce prices: first ahentic/get-content with {"id":…,"include_meta":true}, then update using the exact meta keys under meta '
				. '(WooCommerce simple products typically use _regular_price and _price). Never invent top-level fields like "price". '
				. 'Always pass tools_planned as objects with input when a tool needs args (e.g. {"name":"ahentic/get-content","input":{"id":123}}), not bare ability name strings. '
				. 'Prefer ahentic/find-unused-media to scan the media library for images that look unused (not featured/logo/icon/in content). '
				. 'Before generating or placing post images, call ahentic/get-wordpress-guidance with topic web-image-fit (aspect ratio + framing); then generate-image → upload-media from_memory → ONE placement step — never default post images to tall or square. '
				. 'CRITICAL — post title is the H1: when drafting or rewriting posts/pages, put the article title in the post title field '
				. '(ahentic-browser/update-post-document while the editor is open; create-post / update-post title when not). '
				. 'Body headings start at core/heading level 2 — do not insert a level-1 heading that duplicates the title. '
				. 'Call ahentic/get-wordpress-guidance with topic post-title-headings when unsure. '
				. 'To place a generated image in the open post: ahentic/generate-image → ahentic/upload-media {"from_memory":"<artifact_key>"} (allow HITL) → place exactly once: either ahentic-browser/insert-blocks with a single core/image {id,url,alt} (index 0 or before_ref of the first block) OR ahentic-browser/set-featured-image when the user asked for featured/thumbnail/cover (use ahentic/set-featured-image only when the block editor is not open for that post) — never both, never insert-blocks twice for the same image. Never from_memory on insert-blocks for image artifacts. '
				. 'Prefer ahentic/update-term (Agent mode) to change an existing category/tag/custom taxonomy term: pass taxonomy plus term_id or term (ID/slug/name), then name/slug/description/parent and/or meta. '
				. 'Use edit_url / view_url / media_library_url / plugins_url from those results when linking the user. '
				. 'Do not claim you ran a tool that is not in the available list. ';

			if ( 'ask' === $mode ) {
				$base .= ' Mode: Ask — you run the same multi-step loop as Agent, but ONLY with read-only tools '
					. '(lookups and searches; no install/activate/update/delete or other site changes). '
					. 'When you need site facts, set next="use_tools" and list tools in tools_planned. After tool results appear '
					. 'in the next message, think again and either call more readonly tools or set next="reply" / "ask_user" / "missing_ability". '
					. "Available readonly abilities right now: {$tools_list}. "
					. $readonly_tool_guidance
					. 'If the user asks you to change the site (install/activate/deactivate/uninstall plugins, edit content, update settings, etc.): '
					. 'do NOT call write tools. Set next="reply" (or "ask_user" if you need a real choice), explain that Ask mode is read-only, '
					. 'tell them to switch the composer mode to Agent to make changes, and give manual steps with admin links if useful. '
					. 'If a tool result has error ability_ask_readonly, follow that pattern. '
					. 'If they need a write ability that does not exist in any mode yet, set next="missing_ability" with ability_needed '
					. '(e.g. "ahentic/update-site-title") and explain the gap with a short workaround. '
					. 'Never mention X, Twitter, hashtags, @handles, request cards, or any sidebar UI for requesting features. '
					. 'If a tool result has error ability_unavailable, explain you cannot do it yet and any workaround.';
			} else {
				$base .= ' Mode: Agent — you run a multi-step loop. When you need site facts, set next="use_tools" '
					. 'and list tools in tools_planned. After tool results appear in the next message, think again '
					. 'and either call more tools or set next="reply" / "ask_user" / "missing_ability". '
					. "Available abilities right now: {$tools_list}. "
					. $readonly_tool_guidance
					. 'HITL replaces ask_user for mutating abilities: when the concrete next step is ahentic/install-plugin, ahentic/activate-plugin, '
					. 'ahentic/deactivate-plugin, ahentic/uninstall-plugin, ahentic/create-post, ahentic/update-post, ahentic/set-post-status, ahentic/update-term, '
					. 'ahentic-browser/save-post, ahentic-browser/convert-blocks '
					. '(or any other ability that pauses for human approval), do NOT set next="ask_user" or ask “shall I install/activate/deactivate/uninstall/update it?” in chat. '
					. 'Instead set next="use_tools" and put that ability in tools_planned immediately — the product shows Allow/Skip; that IS the confirmation. '
					. 'In the short user-facing reply, say what you are about to do (e.g. install or uninstall a plugin, or update a post/term) and that they can approve below; '
					. 'never claim success until a tool result confirms it. Use ask_user only for real choices the tools cannot decide '
					. '(e.g. which of two plugins to pick when both are fine). '
					. 'If a tool result has error user_denied or skipped=true: the user skipped that action (or redirected with a new message). '
					. 'Do NOT retry the same ability/input. Adapt: try a different approach toward their goal (e.g. core blocks instead of a form plugin), '
					. 'or ask_user with one clear choice if you truly cannot proceed without them. Follow any hint and any newer user message. '
					. 'Chain install → activate: after a successful ahentic/install-plugin tool result with active=false, if the user wanted the plugin working '
					. '(install / set up / turn on / “help me find one”), immediately set next="use_tools" with ahentic/activate-plugin using the same slug or plugin_file — '
					. 'do not stop at “installed but not active; activate from Plugins.” Only skip chaining when the user clearly asked to install without activating. '
					. 'IMPORTANT — when the user asks you to create/update/delete/change something and you do not have a matching '
					. 'available ability, do NOT only give manual instructions with next="reply". Instead either: '
					. '(A) set next="use_tools" and put the needed ability name in tools_planned even if it is not in the available list '
					. '(the orchestrator will mark it unavailable), or '
					. '(B) set next="missing_ability" and ability_needed to that ability slug (e.g. "ahentic/update-site-title" or "ahentic/delete-posts"). '
					. 'In your user-facing reply: explain you cannot do it yet and give a short workaround with admin links if useful. '
					. 'Never mention X, Twitter, hashtags, @handles, tweet URLs, request cards, or sidebar UI — the product UI handles feature requests separately. '
					. 'If a tool result has error ability_unavailable, follow the same reply pattern.';
			}

			$base .= "\n\n"
				. 'When you tell the user to open a wp-admin screen, settings page, plugins list, editor, or any other area of their site, '
				. 'ALWAYS include a clickable Markdown link using a full URL from the admin link map below (or from a tool result such as admin_links / edit URLs). '
				. 'Format: [Settings → General](https://example.com/wp-admin/options-general.php). '
				. 'Do not nest bold markers inside the link brackets (wrong: [**Settings → General**](url); right: [Settings → General](url) or **[Settings → General](url)**). '
				. 'Do not only write path breadcrumbs like "Settings → General" without a link. '
				. 'Never invent /wp-admin/ paths — use the map or tool-provided URLs.'
				. "\n\nAdmin link map (use these URLs):\n"
				. $admin_map;

			$base .= "\n\n"
				. 'Before your user-facing reply, output exactly one debug block (the user will not see it) in this form:' . "\n"
				. '<<<AHENTIC_DEBUG' . "\n"
				. '{"intention":"Checking installed plugins","thinking":"1-3 sentences","plan":{"title":"Install SEO plugin","steps":[{"id":"1","content":"See what SEO plugins are installed","status":"in_progress"},{"id":"2","content":"Search for a suitable SEO plugin","status":"pending"},{"id":"3","content":"Install and activate","status":"pending"}]},"tools_planned":[{"name":"ahentic/list-plugins","input":{}}],"ability_needed":"ahentic/update-site-title","next":"reply|ask_user|use_tools|missing_ability"}' . "\n"
				. 'AHENTIC_DEBUG>>>' . "\n"
				. 'intention must be a short present-tense status the UI can show live (e.g. "Checking installed plugins", '
				. '"Searching the media library", "Summarizing findings") — not a private note. Keep it under ~10 words. '
				. 'thinking is shown to the user in the sidebar chat on every step — write 1–3 clear sentences of your thought '
				. 'process and findings (what you know, what you will check or just learned from tools). Do not leave thinking empty. '
				. 'tools_planned may be strings (ability names) or objects {"name":"ahentic/…","input":{}}. '
				. 'ability_needed is optional except when next is missing_ability (string or list of ability slugs). '
				. 'plan is orchestrator state (not a tool). In Agent mode you MUST include a non-empty plan.steps list when you '
				. 'intend 2+ tools in tools_planned OR any write (non-readonly) ability. A single readonly tool may omit plan. '
				. 'Omit plan for simple Ask answers. When you include plan, use coarse user-facing steps (not every tool name), '
				. 'keep exactly one status "in_progress", and on later thinks ALWAYS re-send the FULL plan including already '
				. 'completed/cancelled steps (same ids) — never drop finished steps from the list; only update their status. '
				. 'The plan checklist is silent UI metadata — it must NOT replace thinking or chat narration. '
				. 'Closing marker: AHENTIC_DEBUG followed by exactly three > characters. '
				. 'After the closing marker, write a short normal reply the user can read (even when next is use_tools — e.g. what you are about to check or what you just learned). '
				. 'Never mention the debug block.';

			if ( $session_id ) {
				$base .= self::plan_context_for_prompt( $session_id );
				if ( class_exists( 'Ahentic_Session_Artifacts' ) && Ahentic_Session_Artifacts::session_has_content_work( $session_id ) ) {
					$base .= ' CRITICAL — this run is long-form content work: you MUST use ahentic/stage-artifact '
						. '(while drafting: chunk with mode=append until complete=true; when revising a ready draft or rewriting the full article: mode=replace or a new key) '
						. 'then apply with set-blocks/create-post/update-post '
						. 'using {"from_memory":"…"} — do not finish after a thin one-section set-blocks rewrite. '
						. 'A finished article needs a full multi-section body; each write result reports its size, so keep writing when it comes back thin.';
				}
			}

			return $base;
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
				. 'and write a normal chat reply with what you learned. When all are done, mark every step completed.';

			return "\n\n" . implode( "\n", $lines );
		}

		/**
		 * Build history + latest user message for the model.
		 *
		 * Tool results since the last user message are appended to the user prompt
		 * so the next think can observe them.
		 *
		 * @param array $entries Session entries.
		 * @return array{history: array, user: string, clipped: array, superseded: int}
		 */
		public static function build_chat_payload( array $entries ) {
			$latest_snapshot = self::latest_live_editor_snapshots( $entries );
			$clipped         = array();
			$superseded      = 0;

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
					$ability  = isset( $entry['meta']['ability'] ) ? (string) $entry['meta']['ability'] : 'tool';
					$snapshot = isset( $latest_snapshot[ $ability ] );

					if ( $snapshot && $latest_snapshot[ $ability ] !== $i ) {
						$body = '[Superseded — a newer ' . $ability . ' result appears below.]';
						++$superseded;
					} else {
						$raw_len = strlen( (string) $entry['content'] );
						$cap     = $snapshot ? self::MAX_TOOL_RESULT_CHARS_SNAPSHOT : self::MAX_TOOL_RESULT_CHARS;
						$body    = self::truncate_tool_result_for_prompt( (string) $entry['content'], $cap );
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
		 * @param int   $session_id Session ID.
		 * @param array $payload    From build_chat_payload.
		 * @return array{history: array, user: string, compacted?: bool}
		 */
		private static function apply_context_compaction( $session_id, array $payload ) {
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

			$needs = count( $history ) > self::COMPACT_HISTORY_THRESHOLD || $chars > self::COMPACT_CHAR_THRESHOLD;
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
			Ahentic_Session_Repository::set_context_summary( $session_id, $summary );

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

			Ahentic_Session_Repository::append_trace(
				$session_id,
				'context_compact',
				'Compacted older chat/tool context for this think',
				array(
					'old_turns'  => count( $old ),
					'kept_turns' => count( $keep ),
					'summary_len'=> strlen( $summary ),
				),
				(int) get_post_meta( $session_id, Ahentic_Session_Repository::META_STEP_COUNT, true )
			);

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
				$summary = substr( $summary, -1 * self::COMPACT_SUMMARY_MAX_CHARS );
				$nl      = strpos( $summary, "\n" );
				if ( false !== $nl && $nl < 200 ) {
					$summary = substr( $summary, $nl + 1 );
				}
			}
			return trim( $summary );
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
			$entries = Ahentic_Session_Repository::get_entries( $session_id );
			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				$entry = $entries[ $i ];
				if ( ! is_array( $entry ) || 'user' !== ( isset( $entry['role'] ) ? $entry['role'] : '' ) ) {
					continue;
				}
				$text = trim( (string) ( isset( $entry['content'] ) ? $entry['content'] : '' ) );
				if ( '' === $text ) {
					continue;
				}
				return self::excerpt( $text, 400 );
			}
			return '';
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
		 * Abilities that read the current state of the open editor.
		 *
		 * These describe one document, so only the newest result is meaningful —
		 * unlike id/query-scoped reads (get-content, list-content) where each
		 * result answers a different question and must all be kept.
		 *
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
		 * Entry index of the newest result per live-editor snapshot ability.
		 *
		 * @param array $entries Session entries.
		 * @return array<string, int|string>
		 */
		private static function latest_live_editor_snapshots( array $entries ) {
			$latest = array();
			foreach ( $entries as $i => $entry ) {
				if ( 'tool' !== ( isset( $entry['role'] ) ? $entry['role'] : '' ) ) {
					continue;
				}
				if ( ! empty( $entry['meta']['error'] ) ) {
					continue;
				}
				$ability = isset( $entry['meta']['ability'] ) ? (string) $entry['meta']['ability'] : '';
				if ( self::ability_is_live_editor_snapshot( $ability ) ) {
					$latest[ $ability ] = $i;
				}
			}
			return $latest;
		}

		/**
		 * Cap tool-result JSON injected into the next think prompt.
		 *
		 * @param string $content Raw tool entry content.
		 * @param int    $max     Optional cap override (0 uses the default).
		 * @return string
		 */
		public static function truncate_tool_result_for_prompt( $content, $max = 0 ) {
			$content = (string) $content;
			$max     = (int) $max > 0 ? (int) $max : self::MAX_TOOL_RESULT_CHARS;

			if ( strlen( $content ) <= $max ) {
				return $content;
			}
			return rtrim( substr( $content, 0, $max - 1 ) ) . '…';
		}

		/**
		 * Truncate text for trace payloads.
		 *
		 * @param string $text Text.
		 * @param int    $max  Max length.
		 * @return string
		 */
		public static function excerpt( $text, $max = 120 ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
			if ( strlen( $text ) <= $max ) {
				return $text;
			}
			return rtrim( substr( $text, 0, $max - 1 ) ) . '…';
		}
	}
}
