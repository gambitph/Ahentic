# Task M4 — Orchestrator plan module (deepen API)

**Track:** Maintainability 2  
**Status:** ready  
**Depends:** M3  
**Source:** Grill Q2 — move-only then deepen

## Current state

After M3, plan logic lives in a dedicated module but the surface may still mirror old private Orchestrator method shapes (many entry points, little intentional API).

## Scope

**Deepen** the plan module behind a small, well-named public surface (e.g. apply-from-debug, advance-after-tool, complete-on-finish, cancel-on-stop, requires-plan / ensure-synthetic). Collapse pass-through / awkward shims. Call sites in Orchestrator (and Tool Runner if any) use the deep API only.

## Out of scope

- Changing plan product law (when a plan is required, Finish Gate interactions) unless a tiny bug falls out of the deepen.
- Think/debug work (→ M5/M6).

## Acceptance criteria

- [ ] One clear public API for plan lifecycle; Orchestrator does not reimplement plan merge/normalize.
- [ ] No dual-path leftovers from M3 shims.
- [ ] Pipeline e2e still green; unit tests on pure plan helpers where seams exist.

## Files likely touched

- `src/orchestrator/class-plan.php` (or whatever M3 created)
- `src/orchestrator/class-orchestrator.php`
- Docs/CONTRACT notes if the public seam is named
- Tests under `tests/unit/` or `tests/wp-mocked/` as appropriate
