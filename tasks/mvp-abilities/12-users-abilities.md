# Task 12 — Users: `list-users`, `create-user`, `update-user`, `delete-user`

**Track:** D — needs Task 01 (non-preallowable HITL flag; no snapshot needed here per below)
**Source:** [site-settings.md § Users](../../pro__premium_only/docs/prd/site-settings.md#users)

## Current state

Nothing exists. `abilities-catalog.md` lists `list-users` as `v1-should` and readonly-only, and the old catalog placed `update-user` in Premium as "Dangerous" — both superseded by site-settings.md's decision to ship full read/write in Free, gated by non-preallowable HITL instead of a tier restriction. No user-related ability code exists in `src/abilities/` today.

## What's missing

All four abilities, plus the specific guardrails that make full read/write safe: non-preallowable HITL (Task 01), no self-edit, no role-escalation above the operator, and mandatory reassignment on delete.

## Scope

- New module, e.g. `class-abilities-users.php`, category `ahentic-users`, following the standard pattern in [`server-abilities.md`](../../src/abilities/server-abilities.md).

### `ahentic/list-users` (readonly, upgrade from catalog's `v1-should` to `v1-must` per the corrected catalog)

- `get_users()` with role/search filtering; return id, display name, email (only if operator can `list_users` — respect core caps, don't leak email to a lesser-privileged caller), roles, registered date, post count.

### `ahentic/create-user`

- Input: `username`, `email`, `role`, optional `display_name`.
- **Role ceiling:** cannot assign a role at or above the operator's own highest role (use `get_role()` capability comparison, not a hardcoded role-name list, so custom roles are handled correctly).
- Non-preallowable HITL (Task 01's flag) — `allow_session`/`always_allow` must be rejected for this ability name.
- Uses `wp_insert_user()`; returns the created user's summary.

### `ahentic/update-user`

- Input: `user_id`, partial fields (email, display name, role, etc.).
- **No self-edit:** if `user_id === get_current_user_id()`, refuse — the acting user cannot be the target, so an agent can't be tricked into quietly changing the operator's own account.
- **No role-escalation above the operator's own role** — same ceiling check as `create-user`.
- Non-preallowable HITL.
- Does not need Task 01's settings-snapshot store for undo — user profile changes aren't in this PRD's undo guarantee; rely on the non-preallowable HITL card itself as the safety mechanism (a fresh, un-skippable confirmation every time is the mitigation here, not reversibility). Confirm this reasoning holds before shipping — if it doesn't feel sufficient once built, snapshot support can be added, but it's explicitly not required by site-settings.md.

### `ahentic/delete-user`

- Input: `user_id`, **required** `reassign_to` (must be a valid, existing, different user id — validate before calling core).
- No path that deletes the user's authored content — `reassign_to` is not optional and there is no `reassign: false`/delete-content option exposed by this ability, even though `wp_delete_user()` supports it. This is deliberate: content has no undo coverage in this PRD.
- Non-preallowable HITL; card must name both the target user and where their content is going.
- Uses `wp_delete_user( $user_id, $reassign_to )`.

## Out of scope

- Bulk user operations.
- Password resets / sending credentials — not requested, and a separate security surface.

## Acceptance criteria

- [ ] `create-user`/`update-user`/`delete-user` all reject `allow_session` and `always_allow` (Task 01's non-preallowable mechanism)
- [ ] `update-user` refuses when `user_id` equals the acting user
- [ ] `create-user`/`update-user` cannot assign a role at or above the operator's own
- [ ] `delete-user` requires a valid `reassign_to`; there is no content-deletion path
- [ ] `list-users` respects `list_users`-equivalent capability before exposing email addresses
