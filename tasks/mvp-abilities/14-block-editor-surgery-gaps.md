# Task 14 — Block editor surgery gaps + editor document fields

**Track:** F (browser / open editor) — does **not** need Task 01
**Source:** Grill session (delete missing in product) · [abilities-catalog.md § Block editor surgery](../../pro__premium_only/docs/abilities-catalog.md) · [content-and-editor.md § Editor abilities scope](../../pro__premium_only/docs/prd/content-and-editor.md) · PRD: insert / update / replace / **remove** blocks

One task file, **two workstreams** (implement / PR separately if useful; both are MVP).

| Workstream | What |
|---|---|
| **1 — Block-tree surgery** | `delete-blocks`, relative `move-blocks`, playbooks, catalog/prompt steer |
| **2 — Editor document fields** | `update-post-document` (title / excerpt / slug); keep `set-featured-image` separate |

## Shared product decisions (locked)

- Jobs: surgical CRUD **and** page builds (hybrid verbs — atoms for local edits, `set-blocks` for full rewrites).
- No first-class wrap/ungroup abilities — teach via `replace-blocks` playbook.
- Leave-canvas (“move out of the content”) is a **composition**, not `move-blocks`: write the destination → usually `delete-blocks` the source unless the user wants both.
- While the editor is open, prefer editor-store doc writes over server `ahentic/update-post` for title/excerpt/slug (server body guard already blocks `content` only — excerpt/slug via server while dirty is a footgun).

---

## Current state

### Workstream 1

Shipped in `class-abilities-browser.php` + `editor-abilities.js` / `browser-abilities.js`:

- Reads: `get-editor-state`, `get-blocks`, `get-selection`, `get-block-type`, `list-block-types`, `focus-block`
- Writes: `update-block-attributes`, `replace-blocks`, `set-blocks`, `insert-blocks`, `duplicate-blocks`, `move-blocks` (index + optional `root_ref` reparent), style/convert helpers, `save-post`

**Gaps:**

- **No `delete-blocks`.** `replace-blocks` / `set-blocks` reject empty trees (`empty_blocks`), so remove is not expressible without rewriting the whole document.
- **`move-blocks` is index-only** for placement — agents struggle with “move X below Y”; need `before_ref` / `after_ref`.
- Catalog lists move/duplicate as v1-should but never names delete; `set-blocks` is implemented but under-documented in the catalog ship set.
- `editor-vs-server` playbook does not mention delete / move / leave-canvas.

### Workstream 2

- Browser: `update-post-title`, `set-featured-image` (editor store, dirty until save).
- Server: `ahentic/update-post` accepts `title` / `excerpt` / `slug` / `content` / `meta`; editor-open rejection applies to **`content` only**.
- No browser path for excerpt or slug → leave-canvas to excerpt and “improve the excerpt” while editing are inconsistent with title/featured.

---

## What's missing

1. `ahentic-browser/delete-blocks`
2. Relative targeting on `move-blocks` (`before_ref` / `after_ref`)
3. Playbooks: leave-canvas (universal) + wrap via replace
4. Catalog + orchestrator steer (delete / move vs leave-canvas / `set-blocks`)
5. `ahentic-browser/update-post-document` (allowlisted `title` | `excerpt` | `slug`) and retire or thin-wrap `update-post-title`

---

## Scope

### Workstream 1 — Block-tree surgery

#### 1. `ahentic-browser/delete-blocks`

- Register in `Ahentic_Abilities_Browser`; dispatch in `browser-abilities.js`; implement via Gutenberg `removeBlocks` in `editor-abilities.js`.
- Input: `refs` (array) **or** current selection if omitted — same pattern as `duplicate-blocks`.
- No HITL (same tier as insert/move/attr patch). Canvas stays dirty; persist only via `save-post` when asked.
- Sync block-ref registry after remove; return deleted refs (and useful ok payload).
- Do **not** allow empty `replace-blocks` as a substitute — keep `empty_blocks` rejection; delete is the explicit remove path.

#### 2. `move-blocks` relative targeting

- Accept `before_ref` **or** `after_ref` (mutually exclusive with each other; resolve to parent + index via block-editor store).
- Keep existing `index` + optional `root_ref`.
- Validation: require either (`index`) or (`before_ref`|`after_ref`); clear errors if both styles mixed wrongly.
- Update ability description / input schema in PHP registration.

#### 3. Playbooks

**Leave-canvas (universal)** — new playbook (name e.g. `leave-canvas` / `editor-leave-canvas`):

- Trigger language: move/promote/use as featured, excerpt, “out of the content,” “not in the body,” etc.
- Pattern: (1) resolve source blocks/attrs, (2) write the **destination** with the right ability, (3) **`delete-blocks`** on the source unless the user asked to keep both.
- Destinations with current coverage: featured → `set-featured-image`; excerpt/title/slug → `update-post-document` (workstream 2); server-only destinations when editor closed.
- Anti-pattern: treating leave-canvas as `move-blocks`, or full `set-blocks` rewrite just to remove one block.

**Wrap / group** — short playbook (or section): wrap/ungroup/columns via `replace-blocks` / `set-blocks` — no new ability.

Wire into `src/data/playbooks/index.json`. Update `editor-vs-server.json` related_abilities + principles for delete/move/leave-canvas/`update-post-document`.

#### 4. Catalog + prompts

- Catalog: add `delete-blocks` (**v1-must**); document `set-blocks`; promote `move-blocks` / `duplicate-blocks` guidance for surgery jobs; mention leave-canvas composition.
- Orchestrator cheatsheet / editor-open hints: remove → `delete-blocks`; reorder/reparent → `move-blocks` (prefer `before_ref`/`after_ref`); leave content → playbook composition; full rewrite → `set-blocks`.

### Workstream 2 — Editor document fields

#### `ahentic-browser/update-post-document`

- Single ability; allowlisted keys only: `title`, `excerpt`, `slug` (at least one required).
- Implement via `core/editor` `editPost({ ... })` — same dirty semantics as title/featured (does **not** save).
- Validation: non-empty `title` / `slug` when those keys are present; `excerpt` may be empty string to clear.
- Optional `post_id` mismatch guard (mirror `set-featured-image`).
- **Featured image stays** `ahentic-browser/set-featured-image` (media-shaped, already shipped).

#### Migrate `update-post-title`

- Prefer: implement title through `update-post-document`; keep `update-post-title` as a thin deprecated alias **or** remove after prompt/playbook grep is clean — pick one in implementation, don’t leave two divergent code paths.
- Update ability descriptions, playbooks, orchestrator strings that name only `update-post-title`.

#### Steer away from server footgun

- When editor is open for the post: descriptions / playbooks / hints say use `update-post-document` for title/excerpt/slug — not `ahentic/update-post`.
- Optional hardening (nice-to-have in this task): extend `reject_server_body_write_while_editor_open` (or sibling) to also reject server `title`/`excerpt`/`slug` for the open doc with a browser hint — only if low-risk; otherwise prompt/playbook steer is enough for MVP.

---

## Out of scope

- First-class `wrap-blocks` / `ungroup-blocks`
- `fill-fields`, `translate-blocks`, `audit-design-consistency`, synced patterns
- Changing `replace-blocks` to allow empty replacements
- Server media / settings tracks (C–E)
- Permanent “soft delete” clipboard / artifact for removed blocks

---

## Acceptance criteria

### Workstream 1

- [ ] Agent can remove one or more blocks by ref or selection via `delete-blocks` without rewriting the document
- [ ] `replace-blocks` with empty still fails; delete is the remove path
- [ ] `move-blocks` with `after_ref` of heading H places block immediately after H (manual or e2e)
- [ ] Leave-canvas playbook registered; “use this image as featured and remove it from the content” is expressible as set-featured + delete
- [ ] Wrap-via-replace documented in a playbook (or dedicated short playbook)
- [ ] Catalog + editor-open prompts mention delete / relative move / leave-canvas vs `move-blocks`

### Workstream 2

- [ ] `update-post-document` sets title, excerpt, and slug on the open document without saving
- [ ] Leave-canvas to excerpt works: doc write + `delete-blocks` (when workstream 1 is present)
- [ ] No divergent title implementation left (`update-post-title` alias or removed)
- [ ] Playbooks/prompts prefer browser doc fields over server `update-post` while editor is open

---

## Files likely touched

- `src/abilities/class-abilities-browser.php`
- `src/admin/js/sidebar/editor-abilities.js`
- `src/admin/js/sidebar/browser-abilities.js`
- `src/data/playbooks/index.json`
- `src/data/playbooks/editor-vs-server.json`
- new playbook(s) under `src/data/playbooks/`
- `src/orchestrator/class-orchestrator.php` (editor-open cheatsheet)
- `pro__premium_only/docs/abilities-catalog.md`
- optionally `src/abilities/class-abilities-content.php` (server reject hardening)
- `tests/e2e/specs/` (editor surgery / doc fields as appropriate)
- `src/abilities/client-abilities.md` (how-it-works, if surgery verbs are listed there)
