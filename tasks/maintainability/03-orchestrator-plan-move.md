# Task M3 — Orchestrator plan module (move-only)

**Track:** Maintainability 2  
**Status:** ready  
**Depends:** — (after M1∥M2 preferred)  
**Source:** Deferred 3b · anti-slop god-file extract

## Current state

Plan lifecycle still lives inside `class-orchestrator.php`: apply/merge/normalize from debug, advance after tool, complete/cancel, `debug_requires_plan`, synthetic plan, plan trace helpers.

## Scope

**Move-only** extract into e.g. `Ahentic_Plan` (name TBD to match repo style). Orchestrator call sites switch to the new module. Behavior unchanged.

## Out of scope

- Redesigning plan product law / Finish Gate rules.
- Deepening the public API (→ M4).
- Think/debug extract (→ M5).

## Acceptance criteria

- [ ] Plan FSM methods no longer live as Orchestrator private sprawl (one plan module owns them).
- [ ] No permanent dual-path (old private methods + new module both maintained).
- [ ] Orchestrator pipeline e2e green; plan behavior unchanged.

## Files likely touched

- `src/orchestrator/class-plan.php` (new) or equivalent
- `src/orchestrator/class-orchestrator.php`
- `ahentic.php` (require)
- `src/orchestrator/CONTRACT.md` / `orchestrator.md` if call sites move
