# Maintainability track 2 (anti-slop / deepen seams)

Temporary working folder — delete when the track is done. Not PRD/contract.

**Source:** Post–M1–M6 grill · AI-slop detector triage · [`.cursor/rules/ahentic-anti-slop.mdc`](../../.cursor/rules/ahentic-anti-slop.mdc)

**Track status:** M1–M5 done; next M6.

## Locked decisions

| Topic | Decision |
| --- | --- |
| Order | **M1 ∥ M2** first; then plan move → plan deepen; then think/debug move → deepen |
| Catalog pilot | **Content** first; media catalog later (when touching media / MVP 13) |
| Extract style | Plan + think/debug: **move-only first**, then **deepen API** as its own task |
| Editor DRY | Shared **prelude** only (not full mutate runner) |
| Anti-layering | Replace into a deep seam; no dual-path / parallel lists |
| Verify | Existing e2e + small pure-function / characterization tests |

## Tasks

| # | File | Status | Notes |
| --- | --- | --- | --- |
| M1 | ~~editor-ability-dry~~ | done (removed) | Prelude helpers + unit tests |
| M2 | ~~content-ability-catalog~~ | done (removed) | Content `catalog()` like browser |
| M3 | ~~orchestrator-plan-move~~ | done (removed) | `Ahentic_Plan` move-only |
| M4 | ~~orchestrator-plan-deepen~~ | done (removed) | Deep plan API (`sync_after_think` / …) |
| M5 | ~~orchestrator-think-debug-move~~ | done (removed) | `Ahentic_Think_Debug` move-only |
| M6 | [06-orchestrator-think-debug-deepen.md](./06-orchestrator-think-debug-deepen.md) | ready | Deepen think/debug API after M5 |

## Implement order

1. ~~M1 ∥ M2~~
2. ~~M3 → M4~~
3. ~~M5~~ → **M6**

## Deferred (not in this track)

- Media (and other modules) `catalog()` rollout  
- Accidental empty-catch audit  
- Bulk file/function-length chasing  
- Float-handles / HITL markup dedupe  
- `excerpt` dedupe across Tool Runner / assembler  
