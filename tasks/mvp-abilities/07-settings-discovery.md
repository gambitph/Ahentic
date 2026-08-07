# Task 07 — Settings discovery: `get-settings-context`, `list-settings`, `get-setting`

**Track:** C (needs Task 01 for the snapshot-store shape to exist before Task 08 lands, but these three are readonly and could start in parallel with Task 01)
**Source:** [site-settings.md § Discovery abilities](../../pro__premium_only/docs/prd/site-settings.md#discovery-abilities-readonly) · [§ Registry bootstrap cost](../../pro__premium_only/docs/prd/site-settings.md#registry-bootstrap-cost)

## Current state

Nothing. No theme-settings enumeration exists at all. `Ahentic_Abilities::execute_get_site_snapshot()` reports only the active theme's name/version, not its Customizer surface.

This task is the foundation every write in Tasks 08–11 depends on for validation (writes must reject ids not present in this index).

## What's missing

1. A way to know, cheaply, whether the active theme is block or classic, and which settings surfaces it exposes.
2. A bootstrapped, cached index of Customizer settings (classic themes) — id, label, section, control type, choices, default.
3. A way to read one setting's current value, with large/nested values summarized rather than dumped.

## Scope

### `ahentic/get-settings-context`

- Reuses Task 04's (`list-themes`) block/classic detection for the active theme — do not re-derive it.
- Reports: active theme stylesheet, `is_block_theme`, which surfaces exist for it (`theme_settings` for classic, `global_styles` + `template_parts` for block themes), and a routing hint string for the model.
- Cheap — no Customizer bootstrap needed for this one; block/classic detection alone answers it.

### `ahentic/list-settings` (classic themes only in this task — block-theme global styles listing is covered by Task 09's read path)

- **Bootstrap:** `require_once ABSPATH . 'wp-includes/class-wp-customize-manager.php'`, instantiate `WP_Customize_Manager`, fire `do_action( 'customize_register', $wp_customize )` inside a temporary `$GLOBALS['wp_customize']` assignment, restore the previous global in a `finally` block. This is expensive — on a theme like Blocksy it loads on the order of 150–200 PHP files and registers ~2,000 settings. **Never run this per orchestrator step.**
- **Cache the index, not values.** Transient key: `stylesheet + theme version + md5(active_plugins)`. Cached payload: `{ id, label, section, panel, type ('theme_mod'|'option'), control_type, choices, default }` per setting, harvested from `$wp_customize->settings()` and `$wp_customize->controls()`. Invalidate on `switch_theme`, `upgrader_process_complete` (theme), and plugin activate/deactivate hooks.
- **Values are always read live** — `list-settings` merges the cached index with a fresh `get_theme_mods()` (and `get_option()` for option-backed settings) on every call, so a stale index can misdescribe shape but never misreport a value.
- Input: `query` (keyword against label/id), `section`, `prefix` — **required to be filtered**; refuse an unfiltered call that would return the full ~2,000-entry registry, and say so in the error (steer the model to search by keyword/section instead).
- Pagination: cap page size (e.g. 50), return a cursor/offset.
- Exclude code-bearing settings from the listing per [site-settings.md § Code-bearing settings are excluded](../../pro__premium_only/docs/prd/site-settings.md#code-bearing-settings-are-excluded-not-merely-gated) — `custom_css[...]` ids, anything with `WP_Customize_Code_Editor_Control`, anything gated on `edit_css`/`unfiltered_html` capability. These must not even appear in discovery, so the model isn't tempted to route around the write-time refusal.

### `ahentic/get-setting`

- Input: one or more `ids`.
- Full current value per id. For values whose JSON exceeds a size threshold (e.g. Blocksy's `header_placements`), default to a **shape summary**: top-level keys, array lengths, nested `sections[].items[].id` list — not the raw blob — with a `raw: true` input flag to request the full value (still capped).

## Out of scope

- Any write ability (Tasks 08–11).
- Block-theme global-styles/template-part enumeration (Tasks 09–10 own their own read paths, since their storage/shape is different enough not to share this index).
- Click-to-target / selector harvesting (`WP_Customize_Partial::$selector`) — explicitly deferred to its own future PRD per site-settings.md.

## Acceptance criteria

- [x] The Customizer bootstrap runs at most once per cache-miss, not per call; a warm cache serves `list-settings` without loading theme option files
- [x] `list-settings` refuses an unfiltered/unbounded query rather than returning the whole registry
- [x] No code-bearing setting (per the exclusion list) appears in `list-settings` output
- [x] `get-setting` returns a shape summary (not a raw dump) for values above the size threshold, with an explicit opt-in for the raw blob
- [x] Cache invalidates on theme switch/update and plugin activation/deactivation
- [x] `get-settings-context` correctly identifies block vs. classic without bootstrapping the Customizer
