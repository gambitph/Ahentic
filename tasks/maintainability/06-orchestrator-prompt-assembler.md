# Task M6 — Orchestrator prompt assembler

**Track:** Maintainability (area 3a)  
**Depends:** M1 done (sidebar state stable)  
**Source:** Grill session · orchestrator CONTRACT

## Current state

`system_prompt`, `build_chat_payload`, and compaction/context lived inside `class-orchestrator.php` (~3.7k), mixed with the step loop.

## Scope

**Move-only** extract into `Ahentic_Prompt_Assembler`. Thin shim then switch call sites in the same task series. Behavior unchanged.

## Out of scope

- Redesigning prompt text / product routing law.
- Plan FSM extract (3b) or think/debug extract (3c).

## Acceptance criteria

- [x] Orchestrator calls one deep entry for prompt/payload assembly (`Ahentic_Prompt_Assembler::for_llm()`).
- [x] No permanent dual-path (old private methods removed; `excerpt()` is a one-line shim).
- [x] Prompt behavior unchanged (PHPUnit characterization + full unit suite green).

## Done

| Piece | Role |
| --- | --- |
| `class-prompt-assembler.php` | `for_llm()`, `system_prompt()`, `build_chat_payload()`, compaction/context helpers |
| `class-orchestrator.php` | Step loop calls `for_llm()`; `excerpt()` delegates |
| `tests/unit/PromptAssemblerTest.php` | Pure seams for payload / truncate / excerpt |

## Files touched

- `src/orchestrator/class-prompt-assembler.php` (new)
- `src/orchestrator/class-orchestrator.php`
- `ahentic.php` (require)
- `src/orchestrator/CONTRACT.md`, `orchestrator.md`, `control-block.md`
- `tests/unit/PromptAssemblerTest.php`
