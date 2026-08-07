# Task M6 — Orchestrator think/debug (deepen API)

**Track:** Maintainability 2  
**Status:** ready  
**Depends:** M5  
**Source:** Grill Q2 — move-only then deepen

## Current state

After M5, think/debug lives in a dedicated module but may still expose a wide mirror of old private methods.

## Scope

**Deepen** behind a small public surface (e.g. `run_think( $session_id, $progress, … )` that owns debug retry; helpers for missing-ability / thought publish only if they must stay callable). Orchestrator step loop should not re-own retry policy.

## Out of scope

- Changing `MAX_DEBUG_ATTEMPTS` product behavior or control-block wire format unless a bug falls out.
- Plan module work (M3/M4).

## Acceptance criteria

- [ ] One (or few) deep entries for “usable think”; no dual-path shims left from M5.
- [ ] Pipeline e2e still green; pure helpers unit-tested where seams exist.
- [ ] Orchestrator `run_one_step` reads as composition over Prompt Assembler + Think/Debug + Plan + Tool Runner.

## Files likely touched

- Think/debug module from M5
- `src/orchestrator/class-orchestrator.php`
- Docs + tests as needed
