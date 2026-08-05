# Task 11 — `ahentic/update-option`

**Track:** C — needs Task 01 (snapshot store)
**Source:** [site-settings.md § `ahentic/update-option`](../../pro__premium_only/docs/prd/site-settings.md#ahentic-update-option)

## Current state

Only a **read** ability exists, gated by a 21-key allowlist:

```31:55:/Users/benjaminintal/Workspace/Repos/Ahentic/src/abilities/class-abilities-site.php
		public static function option_allowlist() {
			return array(
				'blogname',
				'blogdescription',
				'siteurl',
				'home',
				'blog_public',
				// ...
				'admin_email',
				'WPLANG',
				'site_icon',
				'fresh_site',
			);
		}
```

`abilities-catalog.md` previously misclassified the write version as Premium-only; corrected — see [site-settings.md](../../pro__premium_only/docs/prd/site-settings.md) and the catalog fix already applied. This task is the interactive, HITL-confirmed write; **unattended** option changes via Agents remain Premium and are out of scope here.

## What's missing

The write ability, with a **different** writable set than the read allowlist — options have no inherent schema, so writability is scoped to what actually has validation behind it.

## Scope

- Input: `key`, `value`, `dry_run?`.
- **Writable set = registered + vetted, not the read allowlist.** An option is writable only if:
  1. It was registered via `register_setting()` (so its declared `sanitize_callback`/`type` runs — this is how a plugin's own settings screen options become adjustable), **or**
  2. It's on a small, separately curated **write** allowlist of vetted core keys (distinct from `option_allowlist()` above — do not reuse that list as-is; some of its entries are exactly what's denylisted below).
- **Hard denylist, checked before anything else, not just HITL-gated:** `siteurl`, `home`, `default_role`, `users_can_register`, `admin_email`. These are excluded at the schema/code level — a wrong `siteurl`/`home` can lock the admin out of wp-admin with no recovery path (undo can't help if the sidebar is unreachable); `default_role` + `users_can_register` together are a privilege-escalation path reached through a screen that looks like ordinary settings.
- Unregistered, unschematized raw options are refused with a clear "not registered, cannot validate" error — do not attempt to guess a sanitizer.
- Snapshot prior value via Task 01's store before writing; restorable via `undo-last-actions`.
- Standard HITL tier.

## Out of scope

- Unattended/scheduled option writes (existing Premium catalog row, unchanged by this task).
- Bulk option writes.
- Any option this ability's denylist excludes — there is no override flag; the denylist is not configurable by the model or the user through this ability.

## Acceptance criteria

- [ ] Cannot write `siteurl`, `home`, `default_role`, `users_can_register`, or `admin_email` under any input
- [ ] Refuses unregistered options rather than writing them unsanitized
- [ ] Registered options go through their real `sanitize_callback`
- [ ] Snapshots prior value before writing; restorable via `undo-last-actions`
- [ ] Write allowlist is a distinct list from `option_allowlist()` (the read list), not a reuse of it
