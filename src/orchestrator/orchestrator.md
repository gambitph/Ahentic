# Orchestrator

The Ahentic agent loop. It is **not** the LLM itself: it decides what to do next, calls WordPress AI for completions, runs tools via Abilities, handles HITL and browser pauses, and persists session state so runs can resume.

> **Canonical should:** [Agent runtime PRD](../../pro__premium_only/docs/prd/agent-runtime.md) · **Contract:** [CONTRACT.md](./CONTRACT.md)  
> This file is **how-it-works** (current implementation map). If it disagrees with the PRD/contract, the PRD/contract win — treat gaps as bugs.

**Code:** `class-orchestrator.php`, `class-think-debug.php`, `class-plan.php`, `class-prompt-assembler.php`, `class-tool-runner.php`, `class-finish-gate.php`, `class-subagent.php`, `class-ai.php`, `class-queue.php`, `class-usage.php`

**Related:** [Control block](./control-block.md) · [Abilities](../abilities/abilities.md) · [Sidebar](../admin/js/sidebar/sidebar.md) · [Session](../session/session.md) · [Artifacts](../session/artifacts.md) · [REST](../admin/rest.md) · [Architecture](../../docs/architecture.md)

---

## Role

| Piece | Responsibility |
| --- | --- |
| `Ahentic_Orchestrator` | Agent loop: think → tools → continue / finish |
| `Ahentic_Think_Debug` | Think with debug recovery (`run_think` / disposition / thought publish) |
| `Ahentic_Plan` | Plan card lifecycle (`sync_after_think` / `ensure_after_think` / advance / complete / cancel / reopen) |
| `Ahentic_Job_Resume` | New goal vs resume same job (`begin_new_goal` / `begin_resume`) + forced-apply finish policy |
| `Ahentic_Tool_Runner` | One Ability through HITL / browser / execute (owns pipeline helpers; shared by step loop + approval resume) |
| `Ahentic_Finish_Gate` | Thin-body assess + decide-before-idle (forced apply / verify repair / partial finish) |
| `Ahentic_AI` | Thin wrapper around Core AI Client / `wordpress/php-ai-client` |
| `Ahentic_Step_Queue` | Async steps (shutdown + Action Scheduler / cron fallback) |
| Session repository | Entries, status, pending tool, plan, page context, artifacts |

The React sidebar does **not** drive each think/tool step. It posts a message, then **polls** session status / progress / messages while PHP runs the loop.

---

## Session statuses

| Status | Meaning |
| --- | --- |
| `idle` | Ready for a new user message |
| `running` | A step is queued or in progress |
| `awaiting_human` | HITL — Allow / Deny a mutating ability |
| `awaiting_browser` | Sidebar must run a browser ability and POST the result |
| `error` / `cancelled` | Terminal failure / user cancel |

Stored on the `ahentic-session` CPT (`_ahentic_status`, etc.).

---

## Run lifecycle

```text
POST /sessions/{id}/messages
  → append user entry, status=running, clear plan (artifacts kept by default)
  → enqueue step + schedule_interactive_run (shutdown)
  → return immediately

process_step → run_one_step:
  1. LLM think (system prompt + history + page context + artifact pointers)
  2. Parse <<<AHENTIC_DEBUG … AHENTIC_DEBUG>>> control block
  3. If next ≠ use_tools → Finish_Gate::evaluate_reply → (continue | finish_with_reply → idle)
  4. Else for each tools_planned:
       - unavailable / Ask-blocked → tool error entry (Orchestrator)
       - else Ahentic_Tool_Runner::run() →
           from_memory / HITL pause / browser pause / execute + Finish_Gate::assess + persist
       - paused_hitl | paused_browser → stop step
  5. If any tool continued → enqueue another step
```

Caps: step / tool / debug / truncation on `Ahentic_Orchestrator`; long-form floor + verify attempts on `Ahentic_Finish_Gate`.

---

## Control block (not native tool-calling)

The model must emit a JSON control block before the user-facing reply. Full field reference: **[control-block.md](./control-block.md)**.

```text
<<<AHENTIC_DEBUG
{"intention":"…","thinking":"…","plan":{…},"tools_planned":[…],"next":"reply|ask_user|use_tools|missing_ability"}
AHENTIC_DEBUG>>>
```

| Field | Role |
| --- | --- |
| `intention` | Short live status label |
| `thinking` | Shown in chat as thought process |
| `plan` | Optional multi-step checklist (session meta + sidebar card) |
| `tools_planned` | Ability names or `{ "name", "input" }` |
| `next` | What the orchestrator does after this think |

Missing / invalid debug blocks are retried internally (not shown to the user).

---

## Tool execution branches

All agent-facing Ability runs go through **`Ahentic_Tool_Runner`** (do not copy this pipeline into new call sites):

1. **`run()`** — auto-stage / `from_memory` → HITL pause → browser pause → `Ahentic_Abilities::execute` → `Ahentic_Finish_Gate::assess_write_payload` → `role: tool` entry. Used by the step loop, HITL Allow resume, and suggested actions.
2. **HITL** — `pending_tool` + `awaiting_human`; sidebar Allow/Deny → `POST …/approvals` → Tool runner (`skip_hitl`) or skip → continue.
3. **Browser** — `pending_tool` with `runtime: browser` + `awaiting_browser`; sidebar runs JS → `POST …/browser-results` → `record_completed_result()` → continue.
4. **`from_memory`** — Pending HITL / browser keep **key only**; expand at PHP execute or in REST for the browser runner. May auto-stage oversized inline bodies first.

HITL runs **before** browser pause when both apply (e.g. approve then run browser).

`Ahentic_Abilities::execute` is the ability **dispatch** (module `execute_*`). The Tool runner owns pause order and persist.

---

## Prompt assembly

Each think goes through `Ahentic_Prompt_Assembler::for_llm()` (system + compacted history/user turn):

- Prior user/assistant turns → history (`build_chat_payload`)
- Tool results after the latest user message → appended as “Ability results from this run…”
- **Page context** — open tab / editor routing
- **Artifact pointers** — keys/status only, no bodies (`Ahentic_Session_Artifacts::format_for_prompt`)
- **Plan** — injected into the system prompt when present

Tool JSON injected into the next think is capped (~8k chars) to avoid blowing context.

### Subagent (cheap mode)

Isolatable work may skip or slim fat main thinks via `Ahentic_Subagent` ([future-subagent.md](../../pro__premium_only/docs/future-subagent.md)):

- **Recipe:** deepen `forced_tools` — when the model already planned several **existing** abilities, run them without `for_llm()` between steps; HITL/browser pauses preserve batch remainder; `bind_recipe_input` before pause fills placeholders from earlier step payloads.
- **Mini-job hop:** main sets `mini_job=true` + `hop_brief` with empty `tools_planned` → one slim think (`assemble_mini_job_hop`: ability catalog + brief + pinned goal/plan, no chat history) → tools via Tool runner → short summary entry → main continues. Vetoes: ask_user, empty brief, tools already planned (Recipe wins), full-history jobs (omit `mini_job`).
- Does **not** invent follow-up abilities or domain chains.

---

## Queue / interactivity

- Preferred: `schedule_interactive_run` closes the HTTP response on `shutdown`, then `process_step`.
- Backup: Action Scheduler (`as_enqueue_async_action`) or `wp_schedule_single_event`.
- Stall fallback: `POST /sessions/{id}/continue` (Local / no cron). Also resumes mid-failure / honest-partial jobs when `jobResumable` is set (`resume_job`). Composer resume cues (“continue”) take the same path while a job is recoverable.
- **Job Resume:** `Ahentic_Job_Resume` owns run-start ritual (`begin_new_goal` / `begin_resume`) and forced-apply-finish decisions. Failures leave Plan + Artifacts + active goal Continuable; forced apply failures during content work return to think instead of `final_reply`.
- Run lock (`_ahentic_run_lock`) prevents overlapping steps on the same session.
- **LLM liveness:** while `complete_chat` runs, keepalive ticks + optional WP HTTP curl progress bump `heartbeatAt` (and refresh the run lock). Sidebar polls nudge cron so ticks can fire in other requests.
- **Context compaction:** when history is large **or** estimated next-prompt fill ≥ 85% of the soft **200k** budget, older turns become an extractive rolling summary; pinned goal + plan (+ artifact pointers) stay on the user prompt. Fill + technical buckets are exposed as `contextUsage` on session REST for the composer ring ([Sidebar PRD](../../pro__premium_only/docs/prd/sidebar.md)).

---

## Modes

| Mode | Tools |
| --- | --- |
| **Agent** | All abilities in `available_for_agent()` |
| **Ask** | Readonly abilities only (`is_readonly`); writes return `ability_ask_readonly` |

Mode is stored on the session and can be overridden per message.

---

## Entry points (REST → orchestrator)

| Route | Handler |
| --- | --- |
| `POST …/messages` | `handle_user_message` |
| `POST …/approvals` | `handle_approval` |
| `POST …/browser-results` | `handle_browser_result` |
| `POST …/actions` | `handle_suggested_action` |
| `POST …/continue` | `continue_run` / `resume_job` |
| `POST …/cancel` | cancel + idle |

Defined in `src/admin/class-rest-sessions.php`.

---

## Adding behavior

- **New tools** — register Abilities; list them in the module’s `names()` and wire `Ahentic_Abilities` dispatch / mode filters. Agent runs go through `Ahentic_Tool_Runner` automatically — do **not** add a parallel HITL/browser/execute path in the Orchestrator. See [abilities.md](../abilities/abilities.md).
- **New pause types** — extend the Tool runner (or follow HITL / browser patterns already there); do not fork a second pipeline.
- **Prompt guidance** — `Ahentic_Prompt_Assembler::for_llm()` / `system_prompt()` in `class-prompt-assembler.php` (keep concise; prefer ability descriptions for tool-specific rules).
- **Large drafts** — stage via artifacts + `from_memory`; do not rely on transcript alone.
