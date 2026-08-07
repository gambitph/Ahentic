# Task M2 — Sidebar shell split

**Track:** Maintainability (area 2)  
**Depends:** M1  
**Source:** Grill session

## Current state

After M1, `sidebar.js` still owns chrome, run drivers, and float/dock UI in one component.

## Scope

Split along: chrome shell · run drivers (send/poll/HITL/browser) · float chrome — behind the unified session record from M1.

## Out of scope

Changing sync protocol, REST, or session-record shape (unless a tiny fix falls out of the split).

## Acceptance criteria

- [ ] `sidebar.js` is mostly composition/orchestration.
- [ ] Behavior unchanged vs M1.
- [ ] No new parallel session state dictionaries.

## Files likely touched

- `src/admin/js/sidebar/sidebar.js`
- New sibling components/modules under `src/admin/js/sidebar/`
