# Task M2 — Content ability catalog

**Track:** Maintainability 2  
**Status:** ready  
**Can run:** ∥ M1  
**Source:** M4 browser pilot follow-through · anti-slop catalogues rule

## Current state

Browser has private `catalog()` driving `names` / `write_names` / `hitl_names` / `progress_label` / summaries. Content still hand-syncs sibling lists in `class-abilities-content.php`.

## Scope

Add a content-module `catalog()` (same deepen pattern as browser). Derive public list/label/summary helpers from it. No third parallel classification path left in the content module for those fields.

## Out of scope

- Rewriting every abilities file (media/plugins/… → later).
- Redesigning ability behavior or schemas for product reasons.
- Forcing `register()` `$defs` to be generated from catalog in this task (optional note if easy; meta annotations may stay hand-paired like browser).

## Acceptance criteria

- [ ] Adding a content ability does not require hand-syncing sibling name/write/HITL/label arrays.
- [ ] `AbilityPolicyTest` (and related) stay green; add/extend catalog characterization if useful.
- [ ] No third parallel classification path left in the content module for the derived fields.

## Files likely touched

- `src/abilities/class-abilities-content.php`
- `tests/unit/` (policy / new ContentAbilityCatalogTest if warranted)
