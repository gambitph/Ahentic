# Task M2 — Sidebar shell split

**Track:** Maintainability (area 2)  
**Depends:** M1  
**Source:** Grill session

## Current state

After M1, `sidebar.js` still owned chrome, run drivers, and float/dock UI in one component.

## Scope

Split along: chrome shell · run drivers (send/poll/HITL/browser) · float chrome — behind the unified session record from M1.

## Out of scope

Changing sync protocol, REST, or session-record shape (unless a tiny fix falls out of the split).

## Acceptance criteria

- [x] `sidebar.js` is mostly composition/orchestration (~2.2k → ~1.5k; poll / browser / float / runner-lock / live-status extracted).
- [x] Behavior unchanged vs M1 (move-only extracts; unit tests green).
- [x] No new parallel session state dictionaries.

## Done

Extracted siblings under `src/admin/js/sidebar/`:

| Module | Concern |
| --- | --- |
| `sidebar-live-status.js` | Live-status label + heartbeat age |
| `sidebar-chrome-utils.js` | Shortcut / title helpers |
| `apply-session-payload.js` | REST payload → tabs + `sessionsById` |
| `session-run-constants.js` | Poll / stall timings + viewer copy |
| `use-runner-lock-effects.js` | Multi-window claim / heartbeat |
| `use-session-poll.js` | Running-session poll + stall nudge |
| `use-browser-resume.js` | Browser-ability pause/resume |
| `use-float-interaction.js` | Dock/float resize + drag |
| `float-handles.js` | Floating resize handle JSX |

Send / stop / HITL / suggested-action callbacks remain in `sidebar.js` as the shell’s run-driver orchestration (same `sessionsById` seam).

## Files touched

- `src/admin/js/sidebar/sidebar.js`
- New sibling modules listed above (+ small unit tests for pure helpers)
