# Task 06 — `ahentic/list-revisions` + `ahentic/restore-revision`

**Track:** B (independent, no infra dependency)
**Source:** [abilities-catalog.md](../../pro__premium_only/docs/abilities-catalog.md) — both `v1-must`; backs wow prompt #10 ("Undo the last changes you made") for **content**, as distinct from the Track A settings snapshot (posts already have WordPress-native revisions — this task exposes that existing mechanism, it does not build a new one)

## Current state

Not implemented. WordPress core already stores post revisions (`wp_get_post_revisions()`, `wp_restore_post_revision()`) — nothing in `src/abilities/` exposes them.

Note: `customize_changeset` is explicitly excluded from create/update in `blocked_post_types()` (`class-abilities-content.php:997`), which is unrelated to this task — that's the old Customizer changeset mechanism, not `wp_revisions`.

## What's missing

Readonly listing of a post's revision history, and a HITL-gated restore.

## Scope

### `ahentic/list-revisions`

- Input: `post_id` (required).
- `wp_get_post_revisions( $post_id )`, mapped to `{ id, author, date, is_autosave }`, newest first, capped (e.g. 20).
- Respects `edit_post` capability on the target post.

### `ahentic/restore-revision`

- Input: `post_id`, `revision_id`.
- Validates `revision_id` is actually a revision **of** `post_id` (don't trust the model to pair them correctly — cross-check `wp_get_post_revisions( $post_id )` contains it) before calling `wp_restore_post_revision()`.
- HITL — restoring silently overwrites the current draft/published content.
- Returns the same `summarize_post()` shape the content module already uses for create/update, so the model gets a consistent result (see `execute_update_post()`'s return shape in `class-abilities-content.php`).

## Out of scope

- Any new revision storage — this is a thin wrapper over core's existing mechanism.
- Diffing between revisions (`ahentic/diff-content` is a separate, `v1-should` catalog entry).

## Acceptance criteria

- [ ] `list-revisions` respects `edit_post` and returns newest-first, capped
- [ ] `restore-revision` rejects a `revision_id` that doesn't belong to the given `post_id`
- [ ] `restore-revision` is in `hitl_names()`
- [ ] Return shape matches the existing `summarize_post()` convention used by `update-post`
