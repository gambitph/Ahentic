# Task M1 — Sidebar session state unify

**Track:** Maintainability (area 2)  
**Source:** Grill session · anti-slop rule · architecture sidebar seams

## Current state

`sidebar.js` keeps run data in many parallel `*ByTab` maps (`messagesByTab`, `statusByTab`, `progressByTab`, `pendingToolByTab`, `planByTab`, `thoughtByTab`, `traceByTab`, plus `approvingByTab` / `pollWatchByTab`). Send / stop / approve / tab remap each hand-update several maps — miss one → stuck UI.

## What's missing

One per-session record and pure sync helpers so updates go through a single path.

## Scope

- Collapse session run fields into one map, e.g. `sessionsById[id] = { messages, status, progress, pendingTool, plan, thought, trace, approving, pollWatch }`.
- Extract pure helpers (optimistic merge, stale payload, record patch/remap/omit) into `session-state.js` (or equivalent).
- Wire `sidebar.js` to that API; replace multi-setter `applySessionPayload`.
- Unit-test pure helpers.

## Out of scope

- Splitting chrome / run-driver / float React components (→ M2).
- REST / API contract changes.
- Orchestrator / abilities changes.

## Acceptance criteria

- [x] One update path for send / stop / approve / remap / apply payload (no new parallel session dictionaries for the same fields).
- [x] Pure helpers covered by unit tests.
- [x] Existing sidebar / HITL / orchestrator-pipeline e2e still green (or equivalent manual smoke if e2e env unavailable).
- [x] No permanent pass-through layer that keeps both old maps and the new record in sync.

## Files likely touched

- `src/admin/js/sidebar/sidebar.js`
- `src/admin/js/sidebar/session-state.js` (new)
- `src/admin/js/sidebar/session-state.test.js` (new)
