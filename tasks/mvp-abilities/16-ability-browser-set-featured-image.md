# Task 16 — `ahentic-browser/set-featured-image`

**Track:** E companion (browser) — pairs with Task 13’s server `ahentic/set-featured-image`. Does **not** need Track A (no settings snapshot store: the editor store owns dirty state until the user saves; undo is editor/history + eventual save, not `undo-last-actions`).
**Source:** Gap identified while scoping featured-image UX — Task 13 only covers the PHP/`set_post_thumbnail()` path. With the block editor open, a server write updates the DB but the featured-image panel / post dirty state often stay stale until reload. Same editor-first shape as title (`ahentic-browser/update-post-title`) and body (ADR-0004), applied to featured media.

## Current state

- Server ability `ahentic/set-featured-image` is **planned** in [Task 13](./13-media-abilities.md) (`set_post_thumbnail()`), not implemented yet.
- Browser catalog already has `ahentic-browser/update-post-title` via `core/editor` `editPost({ title })` in `editor-abilities.js` — the natural pattern for featured media is `editPost({ featured_media: attachmentId })` (or `0` to clear).
- No browser ability name, PHP stub, or JS handler exists for featured image today.

## What's missing

A browser ability that sets (or clears) the featured image on the **currently open** post in the block editor so the sidebar / document panel update live.

## Scope

### `ahentic-browser/set-featured-image`

- **Runtime:** browser (`meta.ahentic.runtime = browser`); PHP stub returns `ahentic_browser_runtime` like other catalog tools.
- **Input:** `attachment_id` (int; `0` or omit-with-clear flag to remove featured image). Optional `post_id` for a mismatch check against page context / editor state — if the open document isn’t that post, fail clearly rather than writing the wrong document.
- **JS:** `dispatch( 'core/editor' ).editPost({ featured_media: attachmentId })` (same store path as `update-post-title`). Do **not** call `savePost` unless the user separately asks — leave the document dirty like other canvas edits (`save-post` remains the persist gate).
- **Guards:** require block editor open (`get-editor-state` / page context); reject when not in the editor (model should use server `ahentic/set-featured-image` instead).
- **HITL:** standard mutate (same tier as Task 13’s server twin) — changing featured image is visible and reversible via editor undo / not saving, but still worth Allow/Deny when the agent drives it. List in `Ahentic_Abilities_Browser::hitl_names()` if other light editor mutators of this class are HITL’d; if `update-post-title` is not HITL, match that precedent for consistency and document the choice in the PR.
- **Routing (product):** when page context says block editor is open for post P, prefer this ability for P’s featured image; when editor is closed (or a different post), use server `ahentic/set-featured-image`. Prompt / Tool runner availability can soft-prefer; server ability may refuse or hint browser when editor-open for P (optional, same spirit as content body — don’t invent a second pipeline).

### Wire-up

1. Constant + `names()` / `write_names()` / optional `hitl_names()` in `class-abilities-browser.php`.
2. `register()` with clear “Runs in the browser” description + input schema.
3. Case in `browser-abilities.js` → helper in `editor-abilities.js`.
4. E2E: extend browser/HITL module spec (or `media-abilities` once it exists) — editor open → ability sets `featured_media` in editor store without a full page reload.

## Out of scope

- Uploading / generating the attachment (Tasks 13 / 15).
- Server `set_post_thumbnail` (Task 13).
- Auto-save / auto-publish after setting featured image.
- Site Editor template featured-image quirks (post editor only for v1).

## Acceptance criteria

- [x] With the block editor open for post P, the ability updates the live featured-image UI / editor `featured_media` without requiring a hard reload
- [x] Does not call `savePost` by itself
- [x] Rejects (clear error) when the block editor is not open for the target post
- [x] Registered as `ahentic-browser/*` stub in PHP + real handler in sidebar JS
- [x] Cross-linked from Task 13’s server `set-featured-image` section so agents don’t treat PHP-only as sufficient while editing

**HITL choice:** not listed in `hitl_names()` — matches `update-post-title` (editor undo / not-saving is the safety valve; only `save-post` / `convert-blocks` are HITL’d among light editor mutators).

## Files likely touched

- `src/abilities/class-abilities-browser.php`
- `src/admin/js/sidebar/browser-abilities.js`
- `src/admin/js/sidebar/editor-abilities.js`
- `tests/e2e/specs/` (media or browser module)
- Optionally Task 13 / catalog / client-abilities.md when implementing
