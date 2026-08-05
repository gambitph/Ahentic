# Contract: Session & artifacts

**Kind:** Subsystem must-guarantee (free tree)  
**Product should:** [Agent runtime PRD](../../pro__premium_only/docs/prd/agent-runtime.md), [Content & editor PRD](../../pro__premium_only/docs/prd/content-and-editor.md), [Working memory PRD](../../pro__premium_only/docs/prd/working-memory.md)  
**How-it-works:** [session.md](./session.md) · [artifacts.md](./artifacts.md)

---

## Ownership

1. A **session** is the sole durable store for conversation entries, run status, plan, pending tool, page context, artifacts, progress, and trace for one sidebar tab / run workspace.
2. Sessions are private `ahentic-session` posts owned by the creating user. REST must enforce `manage_options` (default audience) **and** ownership.
3. Conversation bodies **must not** be persisted in browser `localStorage`.

## Status machine

Supported statuses: `idle` | `running` | `awaiting_human` | `awaiting_browser` | `error` | `cancelled` (`done` may exist as legacy; normal completion returns to `idle`).

| Invariant |
| --- |
| New user messages are rejected while `running` or `awaiting_browser` (busy), except documented HITL skip/redirect behavior |
| `awaiting_human` / `awaiting_browser` imply non-empty `pending_tool` with stable `call_id` |
| Clearing pending tool without completing/denying/cancelling is a bug |

## Plan

- Persist `_ahentic_plan` when Agent mode requires a plan (see Agent runtime PRD: ≥2 tools or any write).
- Clear plan on each new user message.
- Keep artifacts across new messages by default so staged drafts remain usable.

## Page context

- Store latest page context from the sidebar.
- Orchestrator and content routing depend on it (editor open + post id). Missing/stale context that breaks editor-first routing is a contract violation at the system level.

## Working memory

Session-scoped namespaces (Working memory PRD):

| Namespace | Contract |
| --- | --- |
| `artifacts` | Staged payloads by key; statuses include `drafting` / `ready` / `applied` / `stale`; apply via `from_memory` **rejected** while `drafting` |
| `editor.refs` | Opaque `b*` ↔ clientId map, session-backed + validated on each browser use; miss → wipe + force re-get |
| `vars` | Optional later; must not bloat every-think prompts |

### Artifacts / `from_memory`

- Prompts and REST list **pointers** (key, title, size, status), not full bodies by default.
- `pending_tool` carries **key only** — never the full payload body.
- Expand from session meta at PHP execute / browser runner time (not stored expanded on pending).
- HITL summaries: key/title/size + short excerpt from the store.
- Apply allowlist v1: `set-blocks`, `create-post`, `update-post` (grow only via explicit allowlist).
- Orchestrator may auto-stage oversized tool/model payloads and rewrite to `from_memory`.

## Entries

- Append-only conversation log with roles `user` | `assistant` | `tool` | `event` (as used).
- Tool results must be durable enough for the next think (subject to size caps / truncation policy in how-it-works).

## Liveness fields

- Progress `{ label, updatedAt }` for human status copy.
- Heartbeat timestamp (or equivalent) updated while the worker is alive — distinct from label text changing.
- REST must expose enough for the sidebar to render busy / stuck / wait states (Sidebar PRD).

## Caps & compaction

- Step / output budgets: Agent runtime PRD (content-aware). How-it-works constants must converge to that bar.
- Mid-run rolling summary / tool compaction is allowed; **must not** drop current plan, active artifact keys, or latest user goal.
- Full article bodies live in artifacts, not entry history.
