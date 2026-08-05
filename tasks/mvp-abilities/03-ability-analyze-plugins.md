# Task 03 — `ahentic/analyze-plugins`

**Track:** B (independent, no infra dependency)
**Source:** [abilities-catalog.md](../../pro__premium_only/docs/abilities-catalog.md) — `v1-must`; backs wow prompt #15 ("Find unused plugins that may be slowing us down and deactivate the safe ones") and the pre-launch-gaps / safe-cleanup playbooks

## Current state

`ahentic/list-plugins` exists and returns active/inactive plugins with basic metadata:

```328:355:/Users/benjaminintal/Workspace/Repos/Ahentic/src/abilities/class-abilities-plugins.php
		public static function execute_list_plugins( $input = array() ) {
			// ...
			$all    = get_plugins();
			$active = (array) get_option( 'active_plugins', array() );
			// ...
```

There is no analysis on top of it — no flags for unused, overlapping, or heavy plugins. `src/data/playbooks/safe-cleanup.json` and `plugin-hygiene.json` already narrate this job but have no ability to call.

## What's missing

A readonly ability that takes the plugin list and layers heuristic flags an agent can act on (with HITL `deactivate-plugin`, which already exists).

## Scope

- Add `const ANALYZE = 'ahentic/analyze-plugins';` to `Ahentic_Abilities_Plugins`, reusing `execute_list_plugins()`'s data-gathering rather than re-querying.
- Heuristics to flag per plugin (best-effort, cheap — no external calls):
  - **Inactive** — already installed but never activated, or deactivated (`active_map` miss).
  - **Overlap** — two-plus active plugins matching a known-category list (SEO, caching, security) via a small static category map; flag the set, not a single "winner."
  - **No update in N days** — compare `Version` header presence / `get_site_transient( 'update_plugins' )` for available-update signal (age of the plugin itself isn't reliably available without a wp.org API call — keep this heuristic honest about what it can and can't know; don't fabricate "last updated" data the site doesn't have).
- Output: same shape as `list-plugins` items, plus a `flags: string[]` per item (`inactive`, `overlaps_with:<slug>`, `has_available_update`) and a top-level `summary` (counts per flag).
- Must not deactivate anything itself — analysis only; `deactivate-plugin` (existing, HITL) is the follow-up action the model calls.

## Out of scope

- Third-party vulnerability/CVE scanning.
- Any network call to wp.org for freshness data beyond what `get_site_transient( 'update_plugins' )` already caches locally.

## Acceptance criteria

- [ ] Reuses `list-plugins`' data gathering rather than duplicating `get_plugins()` logic
- [ ] Every flag is derived from locally available WP data — no fabricated "last used"/"popularity" claims
- [ ] Readonly; does not deactivate or modify anything
- [ ] Registered in `names()`, `available_for_agent()`, `execute()` dispatch
