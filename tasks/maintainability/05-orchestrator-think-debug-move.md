# Task M5 — Orchestrator think/debug (move-only)

**Track:** Maintainability 2  
**Status:** ready  
**Depends:** M3 preferred (plan stable); can follow M4  
**Source:** Deferred 3c · anti-slop god-file extract

## Current state

Think/debug retry still lives in `class-orchestrator.php`: `run_llm_with_debug`, debug usability checks, missing-ability queue/resolve, thought-process ensure/publish, related trace helpers.

## Scope

**Move-only** extract into e.g. `Ahentic_Think_Debug` / `Ahentic_Llm_Debug` (name TBD). Orchestrator step loop calls the module for “run a think with debug recovery.” Behavior unchanged.

## Out of scope

- Redesigning control-block product law or retry budgets.
- Deepening the public API (→ M6).
- Prompt assembly (already `Ahentic_Prompt_Assembler`).

## Acceptance criteria

- [ ] Debug/retry/thought helpers no longer sprawl as Orchestrator privates.
- [ ] No permanent dual-path.
- [ ] Orchestrator pipeline e2e green; think/debug behavior unchanged.

## Files likely touched

- New orchestrator class under `src/orchestrator/`
- `src/orchestrator/class-orchestrator.php`
- `ahentic.php` (require)
- CONTRACT / how-it-works if call sites move
