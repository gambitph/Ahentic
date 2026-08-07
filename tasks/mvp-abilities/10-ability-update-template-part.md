# Task 10 — `ahentic/update-template-part`

**Track:** C — needs Task 01 (snapshot store, specifically the "did not exist" distinction) and Task 07 (`get-settings-context` block-theme detection). Largest build in Track C — do it last within C. **Suggested global order:** after Task 09 (taxonomy), before Task 11.
**Source:** [site-settings.md § `ahentic/update-template-part`](../../pro__premium_only/docs/prd/site-settings.md#ahentic-update-template-part-block-themes-header-footer-etc) · [ADR-0004](../../docs/adr/0004-editor-first-content-writes.md)

## Current state

Nothing exists for block-theme header/footer editing. Two facts make this harder than it looks:

**1. Editor detection exists; ability support does not.** The sidebar already recognizes the Site Editor as an editor context:

```43:44:/Users/benjaminintal/Workspace/Repos/Ahentic/src/admin/js/sidebar/page-context.js
const looksLikeEditorUrl = /post-new\.php|post\.php|site-editor\.php/.test( pathname ) ||
    /\bblock-editor-page\b|\bsite-editor\b|\bpost-type-/.test( bodyClass )
```

but `wp_template_part` only appears in `blocked_post_types()` — there is no browser-side handling of template-part identity, title, or save anywhere in `src/admin/js/sidebar/editor-abilities.js`.

**2. Uncustomized parts have no database row.** `get_block_template()` queries the DB first and falls back to the theme's file:

```1288:1336:wp-includes/block-template-utils.php (core, not this repo)
function get_block_template( $id, $template_type = 'wp_template' ) {
    // ... DB query first ...
}
// ...
$block_template = get_block_file_template( $id, $template_type ); // fallback
```

A never-touched `parts/header.html` has no post to snapshot a "prior content" value from — the prior state is "no override," and undo from that state must **delete** the row Ahentic created, not write back an empty value.

## What's missing

1. Server-side write for when the Site Editor isn't open.
2. Editor-open routing per ADR-0004: refuse server-side, steer to browser tools.
3. Three new browser verbs `editor-abilities.js` doesn't have: template-part identity, template-part "title" equivalent, and save.
4. Snapshot handling for the file-fallback case.

## Scope

### Server-side path (editor closed)

- Input: `template_part_id` (e.g. `theme-slug//header`), block content changes (reuse the same block-tree patch shape browser abilities already use — `update-block-attributes`/`replace-blocks`-style operations — applied to the template part's parsed blocks server-side via `parse_blocks()`/`serialize_blocks()`).
- Before writing, check page context for whether the Site Editor is open for this exact template part. If so, refuse with the same `ahentic_use_browser_editor`-style error the content module already uses for post-editor routing (`server-abilities.md § Editor vs server content`), steering to the browser path below.
- **Snapshot must record existence, not just content.** Use `get_block_template()` to fetch the current effective template (file or DB). If the DB row doesn't exist, the snapshot for this write is `{ prior_existed: false }` — nothing else. Then write via `wp_update_post()` on the existing row, or create a new `wp_template_part` post if none exists (mirroring how core's Site Editor itself promotes a file template to a DB override on first edit).
- HITL summary must state plainly that this permanently decouples the part from future theme updates (the theme's own file will no longer be used for it).
- **Non-preallowable + irreversible tier** (per site-settings.md's HITL table) when this write is the **first** override (`prior_existed: false`) — the decoupling consequence is severe enough that `always_allow` shouldn't quietly cover it. Subsequent edits to an already-overridden part may use standard HITL.

### Browser-side path (editor open) — new verbs in `editor-abilities.js`

- **Identity:** extend `get-editor-state` (or add a template-part-aware branch) to report `{ type: 'wp_template_part', id, title }` when the Site Editor has a template part open, using `core/editor`'s entity APIs for the current edited entity — most of the existing block-tree reads (`get-blocks`, `get-selection`, etc.) already work here since they go through the shared `core/block-editor` store; this is specifically about **which document** is open, which today assumes a post.
- **Title-equivalent:** template parts don't have `editPost`-style titles the way posts do; expose whatever the Site Editor uses for the part's display name via `editEntityRecord( 'postType', 'wp_template_part', id, { title } )`.
- **Save:** no `saveEditedEntityRecord` call exists anywhere in `editor-abilities.js` today (checked — zero matches). Add it, gated the same way `save-post` already is: only on explicit user ask to save, HITL, not a default after every canvas edit.

## Out of scope

- Full `wp_template` (page template) editing — this task is header/footer (`wp_template_part`) scope, matching what the user actually asked for; whole-template editing can follow the same pattern later if needed, but isn't required for the MVP ask.
- Any new click-to-target UI.

## Acceptance criteria

- [ ] Server-side write refuses when the Site Editor is open for that exact template part, with a clear steer to the browser path
- [ ] Snapshot distinguishes "no override existed" from "existed with this content"; undo from a first-override state deletes the created row rather than blanking it
- [ ] First-time override on a part is non-preallowable; the HITL card states the theme-update decoupling consequence
- [ ] Browser path can identify a template part as the open editor entity (not just posts), set its title, and save via `saveEditedEntityRecord`
- [ ] Existing block-tree browser abilities (`get-blocks`, `insert-blocks`, `replace-blocks`, etc.) are confirmed working unmodified against a template part entity, not just posts
