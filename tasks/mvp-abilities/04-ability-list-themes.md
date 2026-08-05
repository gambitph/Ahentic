# Task 04 — `ahentic/list-themes`

**Track:** B (independent, no infra dependency)
**Source:** [abilities-catalog.md](../../pro__premium_only/docs/abilities-catalog.md) — `v1-must`

## Current state

Not implemented. `Ahentic_Abilities::execute_get_site_snapshot()` already returns the **active** theme's stylesheet/name/version:

```470:474:/Users/benjaminintal/Workspace/Repos/Ahentic/src/abilities/class-abilities.php
			'theme'               => array(
				'stylesheet' => $theme->get_stylesheet(),
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
			),
```

There's no ability to see **installed-but-inactive** themes, or the active theme's block-vs-classic status (which the settings-surface work in Tracks C now depends on — `get-settings-context` in Task 07 should not re-derive theme enumeration; this ability is the natural place for it).

## What's missing

A readonly ability listing installed themes, which is active, and each theme's block/classic status — the latter is a hard dependency for `ahentic/get-settings-context` (Task 07).

## Scope

- New constant on `Ahentic_Abilities_Site` (or a small dedicated module) — `const LIST_THEMES = 'ahentic/list-themes';`.
- Use `wp_get_themes()`, marking the active one via `get_stylesheet()` comparison.
- Per theme: `stylesheet`, `name`, `version`, `is_active`, `parent` (if a child theme), and `is_block_theme` (`wp_theme_has_theme_json()` / `WP_Theme::is_block_theme()` depending on WP version available).
- Keep it small — most sites have 2–5 themes installed; no pagination needed.

## Out of scope

- Anything about the active theme's *settings* (Customizer surface, template parts) — that's Task 07 (`get-settings-context` should call into this ability or share its block/classic detection rather than duplicating it).

## Acceptance criteria

- [ ] Lists all installed themes with active flag and block/classic detection
- [ ] `is_block_theme` detection is reused (not duplicated) by Task 07's `get-settings-context`
- [ ] Readonly; registered in `names()`, `available_for_agent()`, `execute()` dispatch
