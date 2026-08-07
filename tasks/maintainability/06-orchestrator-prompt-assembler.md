# Task M6 — Orchestrator prompt assembler

**Track:** Maintainability (area 3a)  
**Depends:** M1 done (sidebar state stable)  
**Source:** Grill session · orchestrator CONTRACT

## Current state

`system_prompt`, `build_chat_payload`, and compaction/context live inside `class-orchestrator.php` (~3.7k), mixed with the step loop.

## Scope

**Move-only** extract into e.g. `Ahentic_Prompt_Assembler`. Thin shim then switch call sites in the same task series. Behavior unchanged.

## Out of scope

- Redesigning prompt text / product routing law.
- Plan FSM extract (3b) or think/debug extract (3c).

## Acceptance criteria

- [ ] Orchestrator calls one deep entry for prompt/payload assembly.
- [ ] No permanent dual-path (old private methods + new module both maintained).
- [ ] Orchestrator pipeline e2e green; prompt behavior unchanged.

## Files likely touched

- `src/orchestrator/class-orchestrator.php`
- `src/orchestrator/class-prompt-assembler.php` (new)
- Related how-it-works / CONTRACT notes if call sites move
