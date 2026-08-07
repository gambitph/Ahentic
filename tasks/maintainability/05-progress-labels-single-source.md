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

- [ ] No partial hard-coded ability→label table in the sidebar that can drift from PHP.
- [ ] Optimistic Allow still shows a sensible label.

## Files likely touched

- `src/admin/js/sidebar/sidebar.js`
- Possibly REST/bootstrap data if labels are exposed to the client
