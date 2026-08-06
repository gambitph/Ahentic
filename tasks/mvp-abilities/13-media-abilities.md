# Task 13 — Media: `update-media`, `set-featured-image`, `upload-media`, `delete-media`, `replace-media-file`

**Track:** E — needs Task 01 (snapshot store for the metadata/quarantine writes; `replace-media-file` is explicitly exempt from undo)
**Source:** [site-settings.md § Media](../../pro__premium_only/docs/prd/site-settings.md#media)

## Current state

`class-abilities-media.php` has exactly one ability — a readonly report with nothing to act on it:

```16:16:/Users/benjaminintal/Workspace/Repos/Ahentic/src/abilities/class-abilities-media.php
		const FIND_UNUSED = 'ahentic/find-unused-media';
```

`ahentic-browser/audit-accessibility` already exists and can find missing alt text, but there is no write ability to fix what it finds — the audit is currently a dead-end report.

`MEDIA_TRASH` defaults to `false` in WordPress core:

```134:135:wp-includes/default-constants.php (core, not this repo)
if ( ! defined( 'MEDIA_TRASH' ) ) {
    define( 'MEDIA_TRASH', false );
```

which means an unmodified `wp_delete_attachment()` call erases the file(s) from disk immediately on effectively every WordPress install — this is the specific footgun `delete-media`'s scope below exists to avoid.

## What's missing

All five abilities.

## Scope

Extend `class-abilities-media.php` (or split into a dedicated module if it grows past a few hundred lines — follow whichever keeps `class-abilities-media.php` readable).

### `ahentic/update-media`

- Input: `attachment_id`, any of `alt_text`, `title`, `caption`, `description`.
- `alt_text` via `update_post_meta( $id, '_wp_attachment_image_alt', ... )`; the rest via `wp_update_post()` on the attachment (title/excerpt=caption/content=description, per core's own attachment field mapping).
- Snapshot prior values via Task 01's store.
- Standard HITL tier — small, reversible, and this is the ability that closes the loop with `audit-accessibility`.

### `ahentic/set-featured-image`

- Input: `post_id`, `attachment_id`.
- `set_post_thumbnail()`. Snapshot the prior thumbnail id (or its absence) before setting.
- **Editor open:** prefer the browser twin [`ahentic-browser/set-featured-image`](./16-ability-browser-set-featured-image.md) (Task 16) so the document panel updates live; this server ability is the path when the block editor is **not** open for that post (or for headless / Premium Agents). Optionally refuse or hint browser when page context shows editor open for `post_id` — same spirit as content body routing, without inventing a second ToolRunner pipeline.

### `ahentic/upload-media`

- Input: a URL to sideload for v1.
- **Reuse the existing SSRF guard** — `Ahentic_Abilities_Site::host_is_publicly_fetchable()` (`class-abilities-site.php:812-829`) — before fetching; do not write a second, divergent host-validation check.
- Fetch and store via `wp_handle_sideload()` (or `media_sideload_image()` if attaching directly to a post), so WordPress's own MIME allowlist governs what can land on disk — never write the fetched bytes directly.
- Snapshot is trivial here (the attachment didn't exist before); undo is "delete the created attachment."
- **`from_memory` (added by [Task 15](./15-ability-generate-image.md), do not build ahead of it):** once `ahentic/generate-image` exists, `upload-media` also needs to accept `from_memory` pointing at an `image`-kind session artifact, resolving to a local temp path and sideloading from there via the same `wp_handle_sideload()` call — no separate base64/data-uri input shape needed. This is what "if the sidebar supports it, a base64/staged file reference" ends up meaning in practice; the punt below is superseded by Task 15's design once it lands.

### `ahentic/delete-media`

- Input: `attachment_id`.
- **Quarantine, not purge, unconditionally.** Force trash status via `wp_trash_post( $attachment_id )` regardless of the site's `MEDIA_TRASH` setting — do not call `wp_delete_attachment()` with `$force_delete` true, and do not rely on `MEDIA_TRASH` being enabled, since it defaults off on essentially every install.
- Snapshot: prior status, so undo can call `wp_untrash_post()`.
- Permanent purge (skipping trash) is explicitly out of scope — that's the Premium/bulk row in site-settings.md.

### `ahentic/replace-media-file`

- Input: `attachment_id`, new file source (URL or staged upload, same sideload path as `upload-media`).
- Rewrites the file in place at the attachment's existing path, regenerates every registered thumbnail size (`wp_generate_attachment_metadata()` / `wp_update_attachment_metadata()`), and must account for the image being referenced by other posts than the one currently in view.
- **No snapshot / no undo** — this is the one ability in this PRD exempt from the Task 01 undo guarantee (ADR-0007 names it explicitly). The HITL card must say so, and must say the change applies **everywhere the image is used on the site**, not just the current screen — this is the ability's main risk, not the file swap itself.
- Largest build in this task; do it last within Track E.

## Out of scope

- Permanent media purge / bulk delete (Premium).
- A generic base64/data-uri input shape on `upload-media` — the real product need for a non-URL source turned out to be [Task 15](./15-ability-generate-image.md)'s `generate-image` → artifact → `from_memory` chain, not raw base64 in the request. Build `from_memory` per Task 15 instead of a parallel base64 path.

## Acceptance criteria

- [ ] `delete-media` always results in trash status on disk, verified with `MEDIA_TRASH` both unset and explicitly `false`
- [ ] `upload-media` and `replace-media-file` both route through `host_is_publicly_fetchable()` and `wp_handle_sideload()` — no direct file write from fetched bytes
- [ ] `update-media` / `set-featured-image` / `delete-media` snapshot prior state and are restorable via `undo-last-actions`
- [ ] `replace-media-file`'s HITL card explicitly states there is no undo and that the change is site-wide
- [ ] `update-media`'s alt-text write is verified to actually fix what `ahentic-browser/audit-accessibility` flags (manual end-to-end check, not just unit coverage)
