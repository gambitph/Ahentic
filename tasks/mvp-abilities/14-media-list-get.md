# Task 14 — `ahentic/list-media` / `ahentic/get-media`

**Track:** E follow-on — Media **read** completeness. Write path already shipped (`upload-media`, `update-media`, `delete-media`, `replace-media-file`, `set-featured-image`, describe/generate).
**Source:** Parity grilling 2026-08-07 — library browse was the remaining media hole vs usual Media Library control.

## Current state

Agents can upload/update/delete and scan unused media, and `describe-image` by id/url, but there is no first-class **browse / inspect** pair. Models guess attachment ids or over-use `find-unused-media` / content search.

## What's missing

Readonly list + get for attachments, aligned with existing media module patterns (safe fields, caps).

## Scope

### `ahentic/list-media` (readonly)

- Filters: search, mime type / `image` shortcut, parent post id, date range, pagination (`page` / `per_page` with a hard max).
- Return: id, title, mime, url (or sizes summary for images), alt, date, author id, parent id — lean rows.
- Cap: `upload_files` / `read` as appropriate for the attachment; do not leak private attachments the operator cannot read.

### `ahentic/get-media` (readonly)

- Input: `id` (required).
- Return: list fields plus caption, description, full size URLs / `media_details` summary, and whether it appears to be featured somewhere **only if cheap** (optional; do not N+1 the whole site — omit or best-effort).
- Missing id → clear not-found error.

Wire into prompts: prefer `list-media` / `get-media` before inventing attachment ids; keep `find-unused-media` for hygiene reports.

## Out of scope

- Permanent purge (still quarantine/`delete-media` only).
- Editing files (already `replace-media-file` / `update-media`).
- Folders / media-library plugin taxonomies beyond generic term APIs from Task 09 if those taxonomies exist.

## Acceptance criteria

- [ ] `list-media` and `get-media` registered on the media module catalog
- [ ] Pagination / caps prevent unbounded or unauthorized dumps
- [ ] Prompts steer browse → get before blind id use
- [ ] E2E or unit coverage for list filter + get not-found
- [ ] Ability checklist green
