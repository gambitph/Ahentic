# Task M4 — Derived ability metadata (one module)

**Track:** Maintainability (area 4)  
**Source:** Grill session · anti-slop rule

## Current state

Per-module ability identity is hand-synced across `names` / `write_names` / `hitl_names` / `progress_label` (+ JS switch for browser).

## Scope

Pick **one** pilot module (browser or content). One metadata table drives names, write/HITL, and progress labels for that module.

## Out of scope

Rewriting every abilities file in one PR. Progress-label UI map in sidebar (→ M5).

## Acceptance criteria

- [x] Adding an ability in the pilot module does not require hand-syncing sibling arrays.
- [x] `AbilityPolicyTest` (and related) stay green.
- [x] No third parallel classification path left in that module.

## Notes

`register()` `$defs` schemas remain explicit product surface; write vs readonly *annotations* on registration are still hand-paired with catalog `write` (CONVERT_BLOCKS also sets `destructive`). Follow-up could set meta from `catalog()` when registering.

## Files likely touched

- `src/abilities/class-abilities-browser.php`
- `tests/unit/BrowserAbilityCatalogTest.php`
- `tests/unit/AbilityPolicyTest.php` (characterization still green)
