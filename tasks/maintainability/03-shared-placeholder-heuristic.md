# Task M3 — Shared content-placeholder heuristic

**Track:** Maintainability (area 4)  
**Source:** Grill session · anti-slop rule

## Current state

Placeholder detection is duplicated: PHP `looks_like_content_placeholder` in `class-abilities-content.php` and JS `looksLikeContentPlaceholder` in `editor-abilities.js`. Drift invites slop.

## Scope

Single source of truth (shared fixture/data, generated copy, or one documented sync with tests on both consumers).

## Out of scope

Full ability catalog redesign (→ M4).

## Acceptance criteria

- [ ] No duplicated independent regex stacks that can silently diverge.
- [ ] Changing the rule is one place (or generated from one place).
- [ ] Tests fail if PHP and JS disagree on representative samples.

## Files likely touched

- `src/abilities/class-abilities-content.php`
- `src/admin/js/sidebar/editor-abilities.js`
- Shared fixture and/or unit tests
