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

- [x] No duplicated independent regex stacks that can silently diverge.
- [x] Changing the rule is one place (or generated from one place).
- [x] Tests fail if PHP and JS disagree on representative samples.

## Files likely touched

- `src/data/content-placeholder-rules.json` (source of truth)
- `src/abilities/class-ahentic-content-placeholder.php`
- `src/abilities/class-abilities-content.php` (thin delegate)
- `src/admin/js/sidebar/content-placeholder.js`
- `src/admin/js/sidebar/editor-abilities.js`
- `tests/unit/ContentPlaceholderTest.php`
- `src/admin/js/sidebar/content-placeholder.test.js`
