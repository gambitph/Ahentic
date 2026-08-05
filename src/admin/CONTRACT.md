# Contract: REST (`ahentic/v1`)

**Kind:** Subsystem must-guarantee (free tree)  
**Product should:** [Sidebar PRD](../../pro__premium_only/docs/prd/sidebar.md) · [Agent runtime PRD](../../pro__premium_only/docs/prd/agent-runtime.md)  
**How-it-works:** [rest.md](./rest.md)

---

## Auth

- All routes: logged-in user with default capability **`manage_options`** (product may later filter; do not silently weaken).
- Session-scoped routes: caller must **own** the session.
- Client sends WP REST nonce + same-origin credentials.

## Session API surface (required)

| Concern | Routes |
| --- | --- |
| CRUD / poll | `GET/POST /sessions`, `GET/PATCH /sessions/{id}` |
| Messages | `GET/POST /sessions/{id}/messages` |
| HITL | `POST /sessions/{id}/approvals` |
| Browser resume | `POST /sessions/{id}/browser-results` |
| Run control | `POST …/cancel`, `…/continue`, `…/actions` |

## Behavioral invariants

1. `POST …/messages` returns quickly with `status: running` (or busy error); orchestrator continues asynchronously.
2. Busy sessions (`running`, `awaiting_browser`) reject new messages with conflict (`409` / `ahentic_session_busy`) except documented HITL redirect cases.
3. Approvals require `awaiting_human` + `pendingTool`; decisions include allow once / session / always / deny.
4. Browser results require `awaiting_browser` + matching `call_id`; mismatch → conflict.
5. `GET /sessions/{id}` is the poll target and includes status, messages/entries, plan, pendingTool, progress, **heartbeat** (or equivalent liveness), artifacts pointers, errors — enough for Sidebar PRD busy/stuck/wait UX.
6. `POST …/continue` must remain available to kick a dead-heartbeat or resume an honest partial.

## Page context

- Accepted on message create and session patch.
- Shape must support editor-open routing (URL, `is_block_editor`, `post_id`, …) as documented in how-it-works.

## Non-goals

- Exposing agent abilities as public unauthenticated REST run endpoints by default.
- Streaming tokens over a separate vendor WebSocket from the browser.
