# Task 01 — Non-preallowable HITL + settings snapshot store + `undo-last-actions`

**Track:** A (prerequisite — blocks Tracks C, D, E)
**Source:** [site-settings.md § Prerequisite build order](../../pro__premium_only/docs/prd/site-settings.md#prerequisite-build-order) · [ADR-0007](../../docs/adr/0007-settings-writes-require-snapshot-undo.md) · [`src/abilities/CONTRACT.md` § Settings snapshot + undo](../../src/abilities/CONTRACT.md)

## Current state

- HITL policy is binary: an ability either `requires_hitl()` or it doesn't, and `hitl_is_preallowed()` only checks session/always allowlists — there's no way for an ability to say "never honor those lists."

```1232:1242:/Users/benjaminintal/Workspace/Repos/Ahentic/src/session/class-repository.php
	public static function hitl_is_preallowed( $session_id, $ability ) {
		$ability = (string) $ability;
		if ( '' === $ability ) {
			return false;
		}
		if ( in_array( $ability, self::get_hitl_session_allows( $session_id ), true ) ) {
			return true;
		}
		$owner = (int) get_post_field( 'post_author', $session_id );
		return in_array( $ability, self::get_hitl_always_allows( $owner ), true );
	}
```

- `always_allow` persists in **user meta** (`_ahentic_hitl_always`), forever, across every future session — see `add_hitl_always_allow()` at the same file, lines 1212–1223.
- There is no snapshot store, no action log, and no `undo-last-actions` ability anywhere in the codebase. `grep`-ing the whole `src/` tree for `undo`/`rollback`/`action_log` only turns up prose in `src/data/playbooks/pre-launch-gaps.json` — nothing real.
- `abilities-catalog.md` already lists `ahentic/undo-last-actions` as **v1-must** (content/session rollback) — this task's scope is the settings-scoped slice only; generalizing to full content undo is a separate, larger effort not in scope here.

## What's missing

1. A way for an ability name to be marked **non-preallowable**: `requires_hitl()` returns true as always, and `hitl_is_preallowed()` returns `false` unconditionally for it, regardless of session/always-allow state.
2. A snapshot store: before a settings-surface write executes, record the prior value (or explicit absence) keyed to the session.
3. `ahentic/undo-last-actions`: readonly-ish write ability (mutates by restoring) that reverts the most recent N snapshotted writes for the session.

## Scope

### 1. Non-preallowable flag

- Add a small static registry (or a method each module implements) — e.g. `Ahentic_Abilities::is_non_preallowable( $name )` — that Track C/D ability modules populate for their user-write and irreversible-settings-write constants.
- Wire into `Ahentic_Session_Repository::hitl_is_preallowed()`: short-circuit to `false` when the ability is non-preallowable, before checking session/always lists.
- Wire into wherever the orchestrator surfaces the HITL decision UI (`hitl_summary_for_pending()` in `class-orchestrator.php:4660` and the approval endpoint around `class-orchestrator.php:2654-2665`) so `allow_session` / `always_allow` choices are rejected outright for these abilities — return a clear error rather than silently downgrading to `allow_once`.
- No abilities need to actually use this flag yet — Track C/D/E will. Prove it with a temporary test ability or a unit test, per the PRD's "prove against a trivial existing write" instruction; do not wait for Track D to validate it.

### 2. Settings snapshot store

- New table or postmeta/session-meta structure (follow the existing session-meta pattern in `class-repository.php` — e.g. a capped list under a `_ahentic_settings_snapshots` session meta key, similar shape to `get_hitl_session_allows`).
- Each snapshot entry: `{ ability, target (e.g. setting id / option key / attachment id / user id), prior_value, prior_existed (bool), timestamp }`.
- `prior_existed = false` must be representable distinctly from `prior_value = null`/empty — this is required later for template-part undo (Task 10), where "no override existed" must restore by deleting, not by writing back blank. Don't skip this distinction even though no Track C/D ability needs it yet.
- Cap the list (e.g. last 50 entries per session) — values here are small JSON, so this is cheap, unlike a hypothetical post-content undo log.

### 3. `ahentic/undo-last-actions`

- Input: optional `count` (default 1) or explicit snapshot ids.
- For each snapshot, re-invoke the appropriate restore path: write `prior_value` back through the same validated path the original write used (not a raw DB write) — e.g. restoring a theme setting should still go through the Customizer setting's own save path, restoring an option through `update_option`, etc. Since no writable surfaces exist yet, this task can implement the restore dispatcher shape (`ability => restore_callback` map) with the map empty or stubbed, to be filled in by Tasks 08–13 as they land.
- Removes reverted entries from the snapshot list (or marks them consumed) so repeat calls don't double-undo.
- Registered per the standard module pattern in [`server-abilities.md`](../../src/abilities/server-abilities.md): `names()`, `is_readonly()` (false — it mutates), `register_category()`/`register()`, `execute()`.

## Out of scope

- Generalizing this to post-content undo (posts already have revisions; that's a different, larger effort the catalog tracks separately).
- Any actual settings/user/media write — those are Tracks C/D/E. This task only builds the plumbing and proves it against a throwaway/trivial write.

## Acceptance criteria

- [ ] An ability flagged non-preallowable always shows a fresh Allow/Deny card; choosing "always allow" on it is rejected (clear error, not silent no-op) and does not persist to `_ahentic_hitl_always`
- [ ] Snapshot entries can represent "did not exist before" distinctly from "existed with an empty/false value"
- [ ] `ahentic/undo-last-actions` reverts the most recent snapshot(s) for the session and is idempotent against repeat calls (no double-undo, no error on empty snapshot list)
- [ ] A unit/integration test proves the non-preallowable rejection and the snapshot/restore round-trip against a stubbed write, independent of any real Track C/D/E ability existing yet
