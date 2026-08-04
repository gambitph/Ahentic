# Orchestrator

The Ahentic agent loop. It is **not** the LLM itself: it decides what to do next, calls WordPress AI for completions, runs tools via Abilities, handles HITL and browser pauses, and persists session state so runs can resume.

> **Canonical should:** [Agent runtime PRD](../../pro__premium_only/docs/prd/agent-runtime.md) · **Contract:** [CONTRACT.md](./CONTRACT.md)  
> This file is **how-it-works** (current implementation map). If it disagrees with the PRD/contract, the PRD/contract win — treat gaps as bugs.

**Code:** `class-orchestrator.php`, `class-ai.php`, `class-queue.php`, `class-usage.php`

**Related:** [Control block](./control-block.md) · [Abilities](../abilities/abilities.md) · [Sidebar](../admin/js/sidebar/sidebar.md) · [Session](../session/session.md) · [Artifacts](../session/artifacts.md) · [REST](../admin/rest.md) · [Architecture](../../docs/architecture.md)

---

## Role

| Piece | Responsibility |
| --- | --- |
| `Ahentic_Orchestrator` | Agent loop: think → tools → continue / finish |
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
  3. If next ≠ use_tools → finish_with_reply → idle
  4. Else for each tools_planned:
       - unavailable / Ask-blocked → tool error entry
       - from_memory → validate (HITL) or expand (execute/browser)
       - requires HITL → awaiting_human (stop)
       - requires browser → awaiting_browser (stop)
       - else Ahentic_Abilities::execute() → tool entry
  5. If any tool ran → enqueue another step
```

Caps (see constants on `Ahentic_Orchestrator`): max steps per run, max tools per think, max debug retries, tool-result truncation for the next prompt.

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

1. **PHP (server abilities)** — `Ahentic_Abilities::execute()` in the step worker; result → `role: tool` entry.
2. **HITL** — `pending_tool` + `awaiting_human`; sidebar Allow/Deny → `POST …/approvals` → execute or skip → continue.
3. **Browser** — `pending_tool` with `runtime: browser` + `awaiting_browser`; sidebar runs JS → `POST …/browser-results` → tool entry → continue.
4. **`from_memory`** — Orchestrator expands session [artifacts](../session/artifacts.md) into tool input before execute / browser pause (kept unexpanded during HITL so pending stays small).

HITL runs **before** browser pause when both apply (e.g. approve then run browser).

---

## Prompt assembly

Each think builds chat from session entries (`build_chat_payload`):

- Prior user/assistant turns → history
- Tool results after the latest user message → appended as “Ability results from this run…”
- **Page context** — open tab / editor routing (`format_page_context_for_prompt`)
- **Artifact pointers** — keys/status only, no bodies (`Ahentic_Session_Artifacts::format_for_prompt`)
- **Plan** — injected into the system prompt when present

Tool JSON injected into the next think is capped (~8k chars) to avoid blowing context.

---

## Queue / interactivity

- Preferred: `schedule_interactive_run` closes the HTTP response on `shutdown`, then `process_step`.
- Backup: Action Scheduler (`as_enqueue_async_action`) or `wp_schedule_single_event`.
- Stall fallback: `POST /sessions/{id}/continue` (Local / no cron).
- Run lock (`_ahentic_run_lock`) prevents overlapping steps on the same session.

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
| `POST …/continue` | `continue_run` |
| `POST …/cancel` | cancel + idle |

Defined in `src/admin/class-rest-sessions.php`.

---

## Adding behavior

- **New tools** — register Abilities; list them in the module’s `names()` and wire `Ahentic_Abilities::execute` / mode filters. See [abilities.md](../abilities/abilities.md).
- **New pause types** — follow HITL / browser patterns (`pending_tool` + status + resume REST).
- **Prompt guidance** — `system_prompt()` in `class-orchestrator.php` (keep concise; prefer ability descriptions for tool-specific rules).
- **Large drafts** — stage via artifacts + `from_memory`; do not rely on transcript alone.
