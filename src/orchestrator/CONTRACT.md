# Contract: Orchestrator & control block

**Kind:** Subsystem must-guarantee (free tree)  
**Product should:** [Agent runtime PRD](../../pro__premium_only/docs/prd/agent-runtime.md)  
**How-it-works:** [orchestrator.md](./orchestrator.md) · [control-block.md](./control-block.md)  
**ADR:** [0003](../../docs/adr/0003-agent-completion-plan-verify.md)

---

## Role

1. PHP orchestrator owns think → tools → continue / finish. Sidebar never owns the loop.
2. Generation goes through WordPress AI Client / php-ai-client only (`Ahentic_AI`).
3. No stub multi-step: progress reflects real ability results or real pauses (HITL / browser).

## Control block

- Model ↔ orchestrator protocol is the control block (`AHENTIC_DEBUG`), **not** native provider tool-calling.
- Required semantics: `intention`, `thinking`, `next`, optional `plan`, `tools_planned` when using tools.
- `next`: `reply` | `ask_user` | `use_tools` | `missing_ability` (see how-it-works for wire format).
- Missing/invalid blocks: internal retry; do not ask the user to “continue” solely for parse failure.

## Plan enforcement (product law)

| Condition | Requirement |
| --- | --- |
| Agent mode and (≥2 tools planned **or** any non-readonly tool) | Persist a plan; hold later thinks to it |
| Ask mode or single readonly tool | Plan optional |

**Gap note:** Older how-it-works may say “plan when ≥3 steps.” **This contract + Agent runtime PRD win** — implementers must align code to ≥2 tools or any write.

## Completion & verification

- Ask: `next=reply|ask_user` with no writes → `idle`.
- Agent writes / multi-step: tool success is the verification. Must **not** call a readonly ability to confirm a write, and must not treat a page snapshot as proof of any change.
- Long-form content runs: must not `idle` while a write payload reports a body under the long-form floor. One repair think, then an honest partial finish.
- Never summarize as done while `awaiting_human` or `awaiting_browser`.
- **Finish gate module:** `Ahentic_Finish_Gate` owns thin-body `assess_write_payload` (called from the Tool runner) and `evaluate_reply` before idle (unapplied artifacts → forced apply; verify repair / honest partial). The Orchestrator must not reimplement those ADR-0003 rules at call sites.

## Tool branches

Order of concerns for each planned tool (after the ability is available for the mode):

1. Availability + Ask readonly filter (Orchestrator — before the shared pipeline)  
2. `from_memory`: keep **key only** on pending; expand from session working memory at execute/browser (Working memory PRD). May auto-stage oversized payloads and rewrite to `from_memory`.  
3. HITL pause if required (`awaiting_human`)  
4. Browser pause if required (`awaiting_browser`) — **after** HITL when both apply  
5. Else server `Ahentic_Abilities::execute` → `Ahentic_Finish_Gate::assess_write_payload` → persist tool entry  

**Single pipeline module:** Steps 2–5 for agent-facing Ability runs are owned by `Ahentic_Tool_Runner` (`run()` / `record_completed_result()`). Pipeline helpers (auto-stage, `from_memory` expand, browser preflight/fallback, tool failure persist, trace shaping) live **inside** that module — not as Orchestrator public helpers. Write assessment (`assess_write_payload`) is owned by `Ahentic_Finish_Gate` and invoked from the Tool runner. Some Tool runner helpers stay `public` so Orchestrator recovery (`recover_stale_browser`) and unit tests can call them without reimplementing. The Orchestrator must **not** reimplement HITL pause, browser pause, `from_memory` expand, execute, assess, or tool-entry persist at call sites. Do not invent a second execute path in REST handlers, ability modules, or ad-hoc Orchestrator helpers.

`Ahentic_Abilities::execute` remains the ability **dispatch** seam (registration → `execute_*`). The Tool runner is the orchestrator **pipeline** around that dispatch. Orchestrator still owns step-loop concerns shared with prompts (e.g. `progress_label_for_tool`, plan advance after a tool, session `with_current_session`).

**Prompt assembly module:** `Ahentic_Prompt_Assembler` owns system prompt text, history/tool compaction, and user-turn shaping. The Orchestrator must call `for_llm()` (or the assembler’s tested helpers) — do not reimplement prompt/payload assembly at call sites.

## Browser preflight & recovery

- Preflight page context / client capability before entering `awaiting_browser`.
- Timed fallback to server equivalent or structured error (Agent runtime PRD). Indefinite silent wait is a bug.

## HITL

- Support decisions: `allow_once` | `allow_session` | `always_allow` | `deny`.
- Persist session/site allow policy as implemented; site-wide/destructive defaults always require HITL.

## Queue & locking

- Steps may run after HTTP return (shutdown / Action Scheduler / cron).
- Run lock prevents overlapping `process_step` on the same session.
- `continue` REST exists as stall fallback (dead heartbeat / honest partial resume).

## Liveness

- Maintain a **heartbeat** timestamp while a step is actually executing (including long LLM waits).
- Expose progress label + heartbeat to REST for the sidebar (Agent runtime / Sidebar PRDs).
- Dead heartbeat while `running` is a recoverable stuck state — not silent success.

## Budgets

- Default / content-aware step and max-output budgets per Agent runtime PRD (24 / 48 steps; 8k / 16k staging).
- Hitting a ceiling mid-job: honest partial + allow Continue; never fake long-form completion.
- Mid-run context compaction may summarize older turns/tools; must retain plan, artifact keys, latest user goal.

## Modes

| Mode | Tools |
| --- | --- |
| `agent` | `available_for_agent()` |
| `ask` | Readonly only; writes → error entry, no execute |

## Testing

- Control-block parsing (`Ahentic_AI`) is pure PHP and covered in PHPUnit (`tests/unit/`).
- Tool-pipeline characterization (HITL / browser / Ask / Tool runner) lives in `tests/e2e/specs/orchestrator-pipeline.spec.js` (+ browser HITL card in `hitl-and-undo.spec.js`). See [`docs/agents/testing.md`](../../docs/agents/testing.md).
- Budgets, queue/locking, and Task 01 undo surface belong in the relevant Playwright module specs, not PHPUnit.
