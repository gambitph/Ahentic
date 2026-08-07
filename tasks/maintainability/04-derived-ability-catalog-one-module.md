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

- [ ] Adding an ability in the pilot module does not require hand-syncing sibling arrays.
- [ ] `AbilityPolicyTest` (and related) stay green.
- [ ] No third parallel classification path left in that module.

## Files likely touched

- Pilot: `class-abilities-browser.php` or `class-abilities-content.php`
- Possibly thin JS registration if browser
- `tests/unit/AbilityPolicyTest.php`
