# Sessions

An Ahentic **session** is one agent conversation / run workspace. It is stored as a private custom post (`ahentic-session`) owned by the current user. The sidebar tabs map to session ids; message bodies are **not** kept in `localStorage`.

> **Contract:** [CONTRACT.md](./CONTRACT.md) · **Glossary:** [CONTEXT.md](../../CONTEXT.md)

**Code:** `class-cpt.php`, `class-repository.php`, `class-artifacts.php`

**Related:** [artifacts.md](./artifacts.md) · [orchestrator.md](../orchestrator/orchestrator.md) · [rest.md](../admin/rest.md) · [sidebar.md](../admin/js/sidebar/sidebar.md)

---

## CPT

| | |
| --- | --- |
| Post type | `ahentic-session` |
| Status | `private` |
| Author | Creating user (ownership checks on REST) |
| UI | Not a normal admin list UI focus — driven via REST + sidebar |

Registration: `Ahentic_Session_CPT`.

---

## Statuses

| Status | Meaning |
| --- | --- |
| `idle` | Ready for a new user message |
| `running` | Orchestrator step queued or in progress |
| `awaiting_human` | HITL — Allow/Deny pending mutating tool |
| `awaiting_browser` | Sidebar must run a browser ability and POST result |
| `error` | Failed run (`lastError` set) |
| `cancelled` | User cancelled |
| `done` | Reserved / legacy terminal (runs normally return to `idle`) |

Constants: `Ahentic_Session_Repository::STATUS_*`.

---

## Post meta map

| Meta key | Purpose |
| --- | --- |
| `_ahentic_status` | Run status |
| `_ahentic_mode` | `agent` \| `ask` |
| `_ahentic_entries` | Conversation + tool JSON entries (capped) |
| `_ahentic_trace` | Debugger / progress events (capped; keeps head + tail) |
| `_ahentic_run_seq` | Run counter stamped onto every trace event as `run` |
| `_ahentic_progress` | Live `{ label, updatedAt }` for sidebar |
| `_ahentic_pending_tool` | In-flight HITL or browser tool payload |
| `_ahentic_plan` | Multi-step plan card |
| `_ahentic_page_context` | Open-tab snapshot (URL, editor, post id, …) |
| `_ahentic_artifacts` | Session artifacts store (see [artifacts.md](./artifacts.md)) |
| `_ahentic_step_count` | Steps consumed this run |
| `_ahentic_tokens_*` | Token counters (session spend) |
| `_ahentic_context_usage` | Soft context-budget fill snapshot (`contextUsage` on REST) |
| `_ahentic_context_summary` | Mid-run compaction rolling summary |
| `_ahentic_hitl_session_allows` | Per-session HITL allow-list |
| `_ahentic_capability_requests` | Missing-ability request queue |
| `_ahentic_last_error` | Last error message |
| `_ahentic_auto_title` | Whether title was auto-set from first message |
| `_ahentic_summary_*` / `_ahentic_knowledge_*` | Post-run summary / knowledge classification |

Repository API: `Ahentic_Session_Repository::{get,set}_*` helpers. Prefer those over raw `update_post_meta` from new code.

---

## Entries (conversation log)

Entries are JSON objects in `_ahentic_entries` (max ~400). Typical roles:

| Role | Content |
| --- | --- |
| `user` | User message text |
| `assistant` | Model reply / thought process shown in chat |
| `tool` | Ability result JSON (+ `meta.ability`, `meta.ok`, …) |
| `event` | Occasional UI/system events |

The orchestrator rebuilds the next LLM prompt from entries (`build_chat_payload`): history + trailing tool results after the latest user message (tool bodies truncated for context).

---

## Pending tool

When status is `awaiting_human` or `awaiting_browser`, `_ahentic_pending_tool` holds something like:

```json
{
  "name": "ahentic/create-post",
  "input": { "title": "…", "from_memory": "article_draft" },
  "summary": "Create post draft “…”",
  "call_id": "uuid",
  "runtime": "browser"
}
```

- HITL: `from_memory` may remain unexpanded until Allow (keeps pending small).
- Browser: input is expanded before pause when applicable; `artifact_key` may be set for apply-on-success.
- Cleared when the tool completes, is denied/skipped, or the run cancels.

REST exposes this as `pendingTool` (camelCase).

---

## Page context

Updated from the sidebar on message send / navigation patches, and refreshed when browser page-read tools succeed.

Used to:

- Inject “Active browser page context” into each think
- Route editor-open vs server content tools
- Block server body writes while the same post is open in Gutenberg

---

## Plan

Optional checklist from the model’s control block (`debug.plan`). Stored in `_ahentic_plan`, shown in the sidebar plan card, re-injected into the system prompt so later thinks keep statuses aligned.

Cleared on each new user message (`handle_user_message`). **Artifacts are kept** across new messages by default so “now put that draft in the editor” still works.

Step statuses are settled when the run ends so the card never reads as live on an idle session: a finished run marks the remaining steps `completed`, and a user Stop (`cancel`) marks them `cancelled`.

---

## Artifacts

Large staged payloads (drafts, block trees) live in `_ahentic_artifacts`. Pointers appear on REST as `artifacts` and in the LLM prompt without bodies. Full contract: [artifacts.md](./artifacts.md).

---

## REST session payload (`to_rest`)

CamelCase fields for the sidebar, including:

`id`, `title`, `status`, `mode`, `messages`, `hasMore`, `trace`, `traceCount`, `progress`, `plan`, `pendingTool`, `artifacts`, `tokensIn` / `tokensOut` / `tokensUsed`, `contextUsage` (soft 200k budget fill + buckets), `stepCount`, `lastError`, `summaryStatus`, timestamps.

### Context usage (`contextUsage`)

Soft fill estimate for the **next** LLM prompt (not cumulative spend). Measured by `Ahentic_Prompt_Assembler`, cached in `_ahentic_context_usage`, refreshed on each think. Drives the composer context ring and ≥85% fill compaction. Design: [future-sidebar-usage.md](../../pro__premium_only/docs/future-sidebar-usage.md).

`trace` here is a **recent window of event envelopes** (no event `data`) because the sidebar polls
it every ~650ms. The complete log lives on `to_diagnostics()` / `GET /sessions/{id}/diagnostics`:
recording stays verbose for everyone, but nobody pays to transfer it until they ask for it.

---

## Ownership & security

- REST requires `manage_options` **and** session ownership (`current_user_owns`).
- Sessions are private posts; do not expose them on the public REST CPT API for anonymous access.
- Ability permission callbacks still apply when tools run.

---

## Caps

| Cap | Approx. |
| --- | --- |
| Entries retained | 400 |
| Trace events | 300 (first 60 always kept; middle collapses into one `trace_gap`) |
| Trace events in the poll payload | 60, envelope only — full events via `/diagnostics` |
| Artifact items / size | see artifacts.md |
| Steps per run | orchestrator `MAX_STEPS_PER_RUN` |

---

## Mental model for new code

- **Need something for the next think?** Prefer a tool result entry, page context, plan, or artifact pointer — not a new ad-hoc option.
- **Need chrome only?** Sidebar `storage.js` / localStorage.
- **Need durable draft for later apply?** Artifacts + `from_memory`, not chat scraping.
