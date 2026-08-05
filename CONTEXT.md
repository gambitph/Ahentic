# Ahentic

Ahentic is an AI workspace inside WordPress: a trusted admin “website developer” that explores the site, writes content, and changes the install through Abilities, with a human present in the free product and unattended Agents in Premium.

## Language

### Product

**Sidebar agent**:
The interactive Ahentic chat in the React sidebar that helps a logged-in admin while they watch.
_Avoid_: chat bot, copilot (as the product name), assistant-only

**Agent (Premium)**:
A saved, trigger-driven AI worker users create; runs headless on the same orchestrator with PHP abilities only.
_Avoid_: bot, automation recipe (as the primary name), cron job

**Ask mode**:
Session mode that may only use readonly abilities; answers and explores, does not mutate the site.
_Avoid_: read-only chat, browse mode

**Agent mode**:
Session mode that may plan, call write abilities, and complete multi-step work on the site (subject to HITL and verification).
_Avoid_: edit mode, full access mode

### Runtime

**Orchestrator**:
The PHP agent loop that thinks, runs tools, pauses for humans or the browser, and persists run state. It is not the LLM.
_Avoid_: brain (in code), agent runtime (when referring to the class), tool loop owner in JS

**Session**:
One conversation / run workspace stored as an `ahentic-session` post; holds entries, status, plan, pending tool, artifacts, and page context.
_Avoid_: chat thread (as storage), conversation ID alone

**Control block**:
The structured JSON the model must emit so the orchestrator knows intention, plan, tools, and next action.
_Avoid_: native tool call, function call payload

**Plan**:
An explicit multi-step checklist the orchestrator persists and holds the model to for Agent multi-step or write work.
_Avoid_: todo list (UI-only), outline

**Ability**:
A named WordPress Abilities API unit (schema + permission + execute) that the agent may call as a tool.
_Avoid_: tool (as the registration unit), action, skill

**Server ability**:
An ability executed in PHP inside the orchestrator step worker.
_Avoid_: REST tool, backend tool

**Browser ability**:
An ability that must run in the user’s browser (sidebar JS), typically Gutenberg or same-site authenticated UI.
_Avoid_: client tool, frontend tool, editor API (as the unit name)

**HITL**:
A pause for human Allow / Deny (or policy allow) before executing a mutating or dangerous ability.
_Avoid_: confirmation dialog (alone), permission_callback (WP caps are separate)

**HITL policy**:
The persisted Allow once / Allow for this session / Always allow (and deny) rules that gate abilities by risk tier.
_Avoid_: permissions, capabilities (WP)

**Non-preallowable ability**:
An ability that always requires a fresh Allow/Deny — it rejects `allow_session` and `always_allow` even if the user has set one, because that policy would otherwise persist silently across future sessions (e.g. user account writes).
_Avoid_: extra-confirmed (vague), high-risk tool (name the mechanism, not just the vibe)

**Settings snapshot**:
The prior value (or its absence) recorded before a theme setting / option / global styles / template part / media write, keyed to the session, so `ahentic/undo-last-actions` can restore it. Surfaces with no WordPress-native revision system rely on this instead of post revisions.
_Avoid_: backup, action log (broader concept not yet built)

**Page context**:
The sidebar’s snapshot of the active tab (URL, editor open, post id, etc.) used to route tools and prompts.
_Avoid_: browser state, window context

**Working memory**:
Session-scoped store with namespaces for agent reuse (payload artifacts, editor refs, optional vars) so the model does not invent IDs or re-paste huge bodies.
_Avoid_: site knowledge, chat history (as the store)

**Artifact**:
A large staged **payload** in working memory’s `artifacts` namespace, applied later via `from_memory`.
_Avoid_: attachment, memory blob, draft file, the whole working-memory system

**Block ref**:
An opaque editor address (`b1`, `b2`, …) mapped to a live Gutenberg clientId in `editor.refs`; never a raw clientId UUID in model tool args.
_Avoid_: clientId (as agent-facing id), block uuid

**Heartbeat**:
A timestamp proving the orchestrator worker is still alive during a run, distinct from the human-readable progress label.
_Avoid_: progress label, ping (generic)

**Ephemeral thought**:
Temporary, non-interactive UI showing the model’s current thought process while a run is busy; cleared when the final reply settles.
_Avoid_: durable assistant message, chain-of-thought log (as the product name)

**Site knowledge**:
The durable site profile and facts Ahentic builds (with consent) and injects as a brief into later runs.
_Avoid_: memory (alone), embeddings store, RAG index

### Packaging

**Free**:
The Directory-safe interactive product: sidebar agent with human present, full Ask/Agent loop, server and browser abilities.
_Avoid_: lite, basic tier (as the technical name)

**Premium**:
Leverage on top of free: Agents, scale/bulk, snippet automation, unattended policies, knowledge edit UI — loaded from `pro__premium_only/`.
_Avoid_: pro features sprinkled in free tree
