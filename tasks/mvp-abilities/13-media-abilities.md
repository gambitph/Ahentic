# Task 13 — Media (remaining): `update-media`, `set-featured-image`, `delete-media`, `replace-media-file`

**Track:** E — needs Task 01 (snapshot store for the metadata/quarantine writes; `replace-media-file` is explicitly exempt from undo)
**Source:** [site-settings.md § Media](../../pro__premium_only/docs/prd/site-settings.md#media)

## Current state

Already shipped in `class-abilities-media.php` / artifacts:

- `ahentic/find-unused-media` (readonly)
- `ahentic/describe-image` (vision)
- `ahentic/generate-image` + `image`-kind session artifacts
- `ahentic/upload-media` (URL sideload + `from_memory` for image artifacts)

`ahentic-browser/audit-accessibility` already exists and can find missing alt text, but there is still no `update-media` write to fix alt/title/caption.

`MEDIA_TRASH` defaults to `false` in WordPress core, so an unmodified `wp_delete_attachment()` call erases files from disk on effectively every install — that footgun is why `delete-media` must quarantine.

## What's missing

Four abilities (upload is done).

## Scope

Extend `class-abilities-media.php` (or split if it grows past a few hundred lines).

### `ahentic/update-media`

- Input: `attachment_id`, any of `alt_text`, `title`, `caption`, `description`.
- `alt_text` via `update_post_meta( $id, '_wp_attachment_image_alt', ... )`; the rest via `wp_update_post()` on the attachment (title/excerpt=caption/content=description, per core’s attachment field mapping).
- Snapshot prior values via Task 01’s store.
- Standard HITL tier — closes the loop with `audit-accessibility`.

### `ahentic/set-featured-image`

- Input: `post_id`, `attachment_id`.
- `set_post_thumbnail()`. Snapshot the prior thumbnail id (or its absence) before setting.
- **Editor open:** prefer the shipped browser twin `ahentic-browser/set-featured-image` (`editor-abilities.js` / `class-abilities-browser.php`) so the document panel updates live; this server ability is the path when the block editor is **not** open for that post (or for headless / Premium Agents). Optionally refuse or hint browser when page context shows editor open for `post_id`.
- **Note:** Browser featured-image is already shipped; PHP-only is **not** sufficient while the user is editing that post in Gutenberg.

### `ahentic/delete-media`

- Input: `attachment_id`.
- **Quarantine, not purge, unconditionally.** Force trash via `wp_trash_post( $attachment_id )` regardless of `MEDIA_TRASH` — do not call `wp_delete_attachment()` with `$force_delete` true.
- Snapshot: prior status, so undo can call `wp_untrash_post()`.
- Permanent purge is out of scope (Premium/bulk).

### `ahentic/replace-media-file`

- Input: `attachment_id`, new file source (URL or staged upload, same sideload path as `upload-media`).
- Rewrites the file in place, regenerates every registered thumbnail size, and affects every reference site-wide.
- **No snapshot / no undo** — ADR-0007 exempts this. HITL must say so and say the change applies **everywhere the image is used**.
- Largest build in this task; do it last within Track E.

## Out of scope

- Permanent media purge / bulk delete (Premium).
- Re-implementing `upload-media` / `from_memory` (already shipped).
- Browser featured-image live panel (`ahentic-browser/set-featured-image` — shipped).

## Acceptance criteria

- [ ] `delete-media` always results in trash status on disk, verified with `MEDIA_TRASH` both unset and explicitly `false`
- [ ] `replace-media-file` routes through `host_is_publicly_fetchable()` and `wp_handle_sideload()` — no direct file write from fetched bytes
- [ ] `update-media` / `set-featured-image` / `delete-media` snapshot prior state and are restorable via `undo-last-actions`
- [ ] `replace-media-file`’s HITL card explicitly states there is no undo and that the change is site-wide
- [ ] `update-media`’s alt-text write is verified to actually fix what `ahentic-browser/audit-accessibility` flags (manual end-to-end check)

## Files likely touched

- `src/abilities/class-abilities-media.php`
- Task 01 snapshot APIs / `undo-last-actions` restore map
- `tests/e2e/specs/` media module
