# Client-side (browser) abilities

Abilities that must run in the **user’s open tab** (Gutenberg store, DOM, credentialed same-site fetch). PHP registers the catalog for the model; the **Tool runner** pauses; the sidebar **executes** JS and POSTs the result.

**PHP catalog:** `class-abilities-browser.php`  
**JS runtime:** `src/admin/js/sidebar/browser-abilities.js`, `editor-abilities.js`, `block-ref-registry.js`, …

**Related:** [Abilities overview](./abilities.md) · [Server abilities](./server-abilities.md) · [Sidebar](../admin/js/sidebar/sidebar.md) · [Orchestrator](../orchestrator/orchestrator.md)

---

## Why a pause

PHP cannot:

- Read the live block canvas / selection
- Call `wp.data` / `createBlock` in the user’s editor
- Use the admin cookie jar for “fetch as the logged-in user” the way the open tab can

So browser abilities are **stubs in PHP** (`execute` → `WP_Error` `ahentic_browser_runtime`) and real work happens in the sidebar.

---

## End-to-end flow

```text
Model plans ahentic-browser/get-blocks (or set-blocks, …)
  → Ahentic_Tool_Runner::run( … )
       → optional HITL first (save-post, convert-blocks, fill-fields)
       → optional from_memory expansion (set-blocks)
       → pending_tool { name, input, runtime: "browser", call_id }
       → status awaiting_browser

Sidebar useEffect
  → runBrowserAbility(pending)
  → POST /sessions/{id}/browser-results { call_id, result | error }

Orchestrator → Ahentic_Tool_Runner::record_completed_result
  → role:tool entry (same shape as PHP tools)
  → assess + mark artifact applied if artifact_key set
  → status running → enqueue next think
```

Also: `ahentic/http-fetch` with `as_user: true` uses the same pause path even though it is registered under site abilities.

---

## PHP side (catalog)

`Ahentic_Abilities_Browser`:

| Method | Purpose |
| --- | --- |
| `names()` | All `ahentic-browser/*` ids |
| `write_names()` / `is_readonly()` | Ask mode filter |
| `hitl_names()` | e.g. `save-post`, `convert-blocks`, `fill-fields` |
| `register()` | `wp_register_ability` with `meta.ahentic.runtime = browser` |
| `execute()` / `execute_stub()` | Always error — must not run in PHP |
| `summary()` / `hitl_summary()` | Progress / Allow copy |

Namespace: **`ahentic-browser/`** (not `ahentic/`) so routing is obvious in prompts and code.

`Ahentic_Abilities::requires_browser_runtime( $name, $input )` returns true for catalog browser tools (and conditional http-fetch).

---

## JS side (runtime)

### Dispatcher — `browser-abilities.js`

`runBrowserAbility( pending )` switches on `pending.name` and returns `{ result }` or `{ error }`.

Add a new `case` when you add a PHP ability.

### Editor helpers — `editor-abilities.js`

Talk to `window.wp` (block editor). Conventions:

- Agent-facing ids are opaque refs (`b1`, `b2`, …) via `block-ref-registry.js` — **never** ask the model to copy Gutenberg `clientId` UUIDs.
- Reject placeholder block payloads (`[full article]`, etc.).
- Prefer structured `{ name, attributes, innerBlocks }` for createBlock.
- Cap tree size on reads; truncate huge attributes.
- After `set-blocks` / `insert-blocks` / `replace-blocks`, verify applied `clientId`s appear in live `getBlocks()` — otherwise return `{ ok: false, error: 'write_not_applied' }` (background tabs can soft-fail: dispatch runs, canvas unchanged, `applied` text would otherwise look successful).
- `useBrowserResume` waits until `document.visibilityState === 'visible'` before running a pending browser ability.
- `convert-blocks` accepts `target` (namespace like `stackable` or exact name like `core/heading`; default `core`). Prefer Gutenberg transforms; use `dry_run` to preview. Do not rewrite the document with `set-blocks` for library conversion.

### Page helpers

- `page-context.js` — URL, admin, editor open, post id, dirty, …
- `visible-page.js` — headings, notices, excerpt of what’s on screen

### Registry lifetime

`block-ref-registry` resolves refs in the tab. Product law: session **working memory** `editor.refs` is session-backed and **validated on every use**; miss → wipe + re-get-blocks (see [Working memory PRD](../../pro__premium_only/docs/prd/working-memory.md)). Payload drafts stay in the `artifacts` namespace, not the ref map.

---

## How to add a browser ability

1. **Constant + lists** in `Ahentic_Abilities_Browser` (`names`, `write_names`, `hitl_names` if needed).
2. **Register** with clear description (“Runs in the browser.”), input schema, `meta.ahentic.runtime = browser`, stub execute callback.
3. **Summary** string for progress UI.
4. **JS handler** in `browser-abilities.js` → implement in `editor-abilities.js` or a small helper.
5. **Prompt** — ability description is primary; add orchestrator guidance only if routing is subtle (editor vs server).
6. **Optional `from_memory`** — if the tool should apply a staged artifact, teach `Ahentic_Session_Artifacts::apply_from_memory`; the Tool runner expands before browser pause (see `set-blocks`).

### HITL + browser

If the action is destructive / persist (save, convert):

- List in `hitl_names()`.
- The Tool runner pauses for Allow first, then sets `awaiting_browser` with expanded input — do not add a parallel pause path.

---

## Block addressing contract

```text
get-blocks → { ref: "b1", name, preview, content_attr, attribute_keys, innerBlocks, … } (full attributes omitted unless include_attributes:true; image-looking blocks still get compact media attrs)
get-blocks + refs → { scoped: true, blocks: [only those refs…], attributes included by default }
get-selection → { ref: "b1", name, attributes, innerBlocks, … } (selection is small/deliberate, always full)
later tools → { ref: "b1" } or { refs: ["b1","b2"] }
JS → resolveToClientIds → live Gutenberg clientIds
```

If refs are missing/stale, return a structured error (`missing` refs + message to re-read blocks). Do not silently no-op.

Full document rewrite: prefer `ahentic-browser/set-blocks` (no target refs). Long drafts: `ahentic/stage-artifact` then `set-blocks` + `from_memory`.

Write abilities must report what they left behind so the orchestrator never spends a turn reading it back. `set-blocks`, `insert-blocks`, `replace-blocks`, and `delete-blocks` return `text_chars`: the plain-text size of the **whole** document after the write, not just the blocks written, so chunked drafting accumulates instead of looking thin on every section.

Remove blocks with `delete-blocks` (refs or selection). Reorder/reparent with `move-blocks` (`before_ref`/`after_ref` preferred). Title/excerpt/slug while the editor is open: `update-post-document` (title-only alias: `update-post-title`). When drafting, the post title is the H1 — body headings start at level 2 (playbook `post-title-headings`). Leaving the content for featured/excerpt/etc. is destination write + usually `delete-blocks` — see playbook `editor-leave-canvas`.

---

## Server vs browser routing (content)

| Situation | Prefer |
| --- | --- |
| Block editor open for this document | `ahentic-browser/*` (live canvas) |
| Title / excerpt / slug while editor open | `ahentic-browser/update-post-document` (not server `update-post`) |
| Featured image while editor open for that post | `ahentic-browser/set-featured-image` (not server `set_post_thumbnail` — panel stays live) |
| Categories/tags while editor open | `ahentic-browser/set-post-terms` (term IDs; panel stays live); taxonomy-only `ahentic/update-post` also allowed |
| Editor not open | `ahentic/create-post`, `update-post`, `set-post-status`, server `ahentic/set-featured-image`, term CRUD + post tax fields |
| Need logged-in HTML of wp-admin | `http-fetch` + `as_user: true` |
| Public URL | `http-fetch` without `as_user` |

Page context on each think tells the model which branch to use; server `update-post` should refuse body **and** title/excerpt/slug writes when the same post is open in the editor.

---

## Testing a new client ability

1. Plan the ability in Agent mode with the editor open; session should go `awaiting_browser`.
2. Confirm the sidebar runs once (no double POST) and the tool entry appears.
3. Ask mode: writes absent/blocked; readonly browser tools still run.
4. HITL tools: Allow then browser run; Deny skips without JS side effects.
5. Refs: get-blocks → mutate with returned `b1`; after reload, expect miss → re-get.
6. `from_memory` (if supported): stage blocks → set-blocks with key only → canvas updates without re-pasting the draft in `tools_planned`.
