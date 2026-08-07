# Task M5 — Progress labels single source

**Track:** Maintainability (area 4)  
**Source:** Grill session · anti-slop rule

## Current state

`sidebar.js` `progressLabelForAbility` hard-codes a partial map that can drift from PHP `progress_label()`.

## Scope

Delete or slim the sidebar hard-coded map; prefer server/`progress_label` (or one generated map) as the source of truth for optimistic labels.

## Out of scope

Changing HITL UX copy unrelated to ability→label mapping.

## Acceptance criteria

- [x] No partial hard-coded ability→label table in the sidebar that can drift from PHP.
- [x] Optimistic Allow still shows a sensible label.

## Approach

- PHP: `Ahentic_Abilities::progress_labels_map()` built from module `progress_label()`.
- Bootstrap: `window.ahentic.abilityProgressLabels` via script loader.
- JS: `progress-label.js` reads that map; slug/`Working…` fallback only.

## Files likely touched

- `src/abilities/class-abilities.php`
- `src/admin/class-script-loader.php`
- `src/admin/js/sidebar/progress-label.js`
- `src/admin/js/sidebar/sidebar.js`
- `tests/unit/AbilityPolicyTest.php`
