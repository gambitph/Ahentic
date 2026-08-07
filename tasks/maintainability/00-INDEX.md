# Maintainability track (anti-slop / deepen seams)

Temporary working folder — delete when the track is done. Not PRD/contract.

**Source:** Grill session (maintainability 2–4) · [`.cursor/rules/ahentic-anti-slop.mdc`](../../.cursor/rules/ahentic-anti-slop.mdc)

## Locked decisions

| Topic | Decision |
| --- | --- |
| Campaign | Features continue; anti-slop on every PR; **dedicated** tasks for this track |
| Order | **M3 ∥ M1** first; **M6 after M1**; M2/M4/M5 as capacity |
| Deepen | Well-formed APIs — replace into a deep seam; no fan-out/stack |
| Extract style | M1/M3–M5 deepen; M6 move-only first |
| Anti-layering | No third parallel path; dedicated tasks replace-don’t-stack |
| Verify | Existing e2e + small pure-function unit tests |
| Deferred | Orchestrator plan module (3b), think/debug (3c), catalog for every module |

## Tasks

| # | File | Status | Notes |
| --- | --- | --- | --- |
| M1 | [01-sidebar-session-state.md](./01-sidebar-session-state.md) | done | Area 2 first slice; manual smoke OK |
| M2 | [02-sidebar-shell-split.md](./02-sidebar-shell-split.md) | done | Chrome / poll / browser / float / runner-lock extracted |
| M3 | [03-shared-placeholder-heuristic.md](./03-shared-placeholder-heuristic.md) | done | Shared JSON rules + PHP/JS consumers |
| M4 | [04-derived-ability-catalog-one-module.md](./04-derived-ability-catalog-one-module.md) | done | Browser `catalog()` pilot |
| M5 | [05-progress-labels-single-source.md](./05-progress-labels-single-source.md) | done | PHP map → `abilityProgressLabels` |
| M6 | [06-orchestrator-prompt-assembler.md](./06-orchestrator-prompt-assembler.md) | done | `for_llm()` deep entry; move-only |

## Implement order

1. **M3 ∥ M1**
2. M4 / M5 as capacity
3. **M2** after M1
4. **M6** after M1
