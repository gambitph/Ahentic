# Task 09 — `ahentic/update-global-styles`

**Track:** C — needs Task 01 (snapshot store)
**Source:** [site-settings.md § `ahentic/update-global-styles`](../../pro__premium_only/docs/prd/site-settings.md#ahentic-update-global-styles-block-themes)

## Current state

No block-theme settings ability exists. `wp_global_styles` is explicitly in `blocked_post_types()` (`class-abilities-content.php:1002`) for the generic content-write abilities, which is correct — this needs its own dedicated, schema-aware ability rather than a loosening of that block.

Core's own gate on custom CSS inside global styles is capability-based, at the exact key this ability must also block:

```3718:wp-includes/class-wp-theme-json.php (core, not this repo)
if ( isset( $input['css'] ) && current_user_can( 'edit_css' ) ) {
```

## What's missing

A write ability that merges into the theme.json **user** layer (not the theme's own theme.json, not global styles' base layer) via core's own resolver, so WordPress owns validation of colors/typography/spacing rather than Ahentic hand-rolling theme.json schema checks.

## Scope

- Input: a partial global-styles object (e.g. `{ styles: { color: {...} }, settings: {...} }`) to merge, plus `dry_run?`.
- Use `WP_Theme_JSON_Resolver::get_user_data()` to read the current user layer, merge the partial in, and persist via the same path core's global-styles REST controller uses (do not hand-write to the `wp_global_styles` post directly — go through the resolver/controller so sanitization and schema validation run).
- **Strip `css` keys before merge** — top-level `styles.css` and any `styles.blocks.{name}.css` — per the code-bearing exclusion in site-settings.md. This is the block-theme equivalent of Task 08's `custom_css[...]` block; do not skip it just because the storage mechanism differs.
- Snapshot the prior user-layer JSON (or the relevant sub-tree) via Task 01's store before merging.
- Standard HITL tier.
- Only applies when `get-settings-context` (Task 07) reports the active theme as a block theme.

## Out of scope

- Template parts (Task 10) — global styles ≠ header/footer markup; "restyle my header" and "change my header" are different jobs routed to different abilities. Make sure the ability description is explicit about this so the model doesn't reach for global-styles when the user actually wants template-part content changed.
- Style variations management (switching between theme-provided style presets) — not requested, not in this task.

## Acceptance criteria

- [ ] Writes go through `WP_Theme_JSON_Resolver` / the global-styles controller path, not a raw post update
- [ ] `css` and block-level `css` keys are stripped from any input before merge, regardless of whether the caller included them innocently or deliberately
- [ ] Snapshots the prior user-layer state before merging; restorable via `undo-last-actions`
- [ ] Refuses to run (clear error) when the active theme is not a block theme
- [ ] Ability description clearly distinguishes this from template-part content edits
