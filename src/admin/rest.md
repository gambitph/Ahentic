# REST API (`ahentic/v1`)

Session and run-control endpoints used by the sidebar. All routes require a logged-in user with **`manage_options`**. Session-scoped routes also require **ownership** of that session.

> **Contract:** [CONTRACT.md](./CONTRACT.md) · **Sidebar should:** [Sidebar PRD](../../pro__premium_only/docs/prd/sidebar.md)

**Code:** `class-rest-sessions.php`, `class-rest.php`  
**Client:** `src/admin/js/sidebar/api.js`  
**Localized:** `window.ahentic.restUrl` + `window.ahentic.restNonce` (from `Ahentic_Script_Loader`)

**Related:** [session.md](../session/session.md) · [orchestrator.md](../orchestrator/orchestrator.md) · [sidebar.md](./js/sidebar/sidebar.md)

---

## Auth

```http
X-WP-Nonce: <wp_rest nonce>
Cookie: <logged-in session>
Content-Type: application/json
```

`api.js` sends `credentials: 'same-origin'` and the localized nonce. Base URL: `rest_url( 'ahentic/v1' )` → typically `/wp-json/ahentic/v1`.

---

## Routes

### Sessions collection

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/sessions` | List current user’s sessions (`?limit=`) |
| `POST` | `/sessions` | Create session `{ title?, mode?: "agent"\|"ask" }` |

### Single session

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/sessions/{id}` | Full session payload (`to_rest`) — poll target |
| `PATCH` | `/sessions/{id}` | Update `title`, `mode`, and/or `pageContext` |

### Messages

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/sessions/{id}/messages` | Paginated entries (`before` / `after` / `limit`) |
| `POST` | `/sessions/{id}/messages` | **Start a run** |

`POST` body:

```json
{
  "content": "User message",
  "mode": "agent",
  "pageContext": { "url": "…", "is_block_editor": true, "post_id": 12 }
}
```

Returns the session immediately with `status: "running"`. The orchestrator continues asynchronously (shutdown / queue). The sidebar must **poll** `GET /sessions/{id}`.

Busy sessions (`running` / `awaiting_browser`) reject new messages with `409` / `ahentic_session_busy` (except HITL redirect via a new message which skips the pending tool).

### HITL

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/sessions/{id}/approvals` | Allow / deny pending mutating tool |

```json
{
  "decision": "allow_once" | "allow_session" | "always_allow" | "deny"
}
```

Requires `status === awaiting_human` and a `pendingTool`.

### Browser results

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/sessions/{id}/browser-results` | Resume after sidebar ran a browser ability |

```json
{
  "call_id": "uuid-from-pendingTool",
  "result": { }
}
```

or `{ "call_id": "…", "error": "message" }`.

Requires `status === awaiting_browser`. Mismatched `call_id` → `409`.

### Run control

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/sessions/{id}/cancel` | Cancel in-flight run |
| `POST` | `/sessions/{id}/continue` | Process one step if still `running` (stall fallback) |
| `POST` | `/sessions/{id}/actions` | Start a suggested ability action (often HITL) |

### Diagnostics

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/sessions/{id}/diagnostics` | Full trace + environment for a bug report |

### Stats

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/stats/tokens` | Daily token series (`days` query, default 30). Site-timezone rollup with `date`, `label`, `in`, `out`, `total` per day. |

---

## Session payload (poll shape)

`GET /sessions/{id}` and most mutating responses return `Ahentic_Session_Repository::to_rest()`:

| Field | Notes |
| --- | --- |
| `id`, `title`, `status`, `mode` | Core identity |
| `messages` | Recent entries (user/assistant/tool/event) |
| `hasMore` | Older messages available |
| `progress` | `{ label, updatedAt }` or null |
| `pendingTool` | HITL / browser pause payload or null |
| `plan` | Checklist or null |
| `artifacts` | Pointer list (no bodies) |
| `trace` | Recent debugger events, **envelope only** (`id`, `at`, `ms`, `run`, `type`, `step`, `summary`) — no event `data` |
| `traceCount` | Total events recorded, so the poll can order payloads even though `trace` is a window |
| `tokensIn`, `tokensOut`, `tokensUsed`, `stepCount` | Usage |
| `contextUsage` | Soft context budget fill + technical buckets ([usage gauge](../../pro__premium_only/docs/future-sidebar-usage.md)) |
| `lastError`, `summaryStatus` | Errors / summary job |
| `createdAt`, `modifiedAt` | ISO timestamps |

Sidebar merges this carefully to avoid clobbering optimistic UI — see [sidebar.md](./js/sidebar/sidebar.md).

---

## Diagnostics payload

`GET /sessions/{id}/diagnostics` returns the whole log for a bug report. It is deliberately
**not** part of the poll shape: the trace is always recorded in full, but the bulky event
`data` is only transferred when the debugger is open or a user exports a log.

| Field | Notes |
| --- | --- |
| `environment` | Plugin / build / WP / PHP versions, AI client path, memory + execution limits, cron constants, object cache, plugin count, locale |
| `session` | Status, mode, last model used, run count, step count, token totals, last error, timestamps |
| `state` | Pending tool, open write findings + repair attempts, forced tools, browser pause, heartbeat, progress, content-work flag |
| `trace` | Every recorded event with full `data` |

Each trace event carries `run` (which run produced it) and `ms` (epoch milliseconds), so a
reader can split a multi-run session and measure real step durations. When the cap is hit the
log keeps its **head** as well as its tail and inserts one `trace_gap` event carrying the
cumulative dropped count — a stuck run must not delete its own cause.

---

## Polling pattern

```text
POST /messages  →  status running
while status in (running, awaiting_browser):
    GET /sessions/{id} every ~600ms
    if awaiting_human → show HITL (stop treating as “busy spinner only”)
    if awaiting_browser → sidebar auto POSTs browser-results
when status idle | error | cancelled → stop poll
```

If `running` never advances locally (no cron / shutdown skipped): `POST /continue`.

---

## Error codes (common)

| Code | Typical HTTP | Meaning |
| --- | --- | --- |
| `ahentic_session_busy` | 409 | Message while running / awaiting_browser |
| `ahentic_not_awaiting` | 409 | Approval/browser POST when status wrong |
| `ahentic_no_pending` | 400 | No pending tool |
| `ahentic_call_mismatch` | 409 | Browser `call_id` ≠ pending |
| `ahentic_forbidden` | 403 | Not session owner |
| `ahentic_ai_unavailable` | 503 | No AI client configured |

Ability failures usually appear as **tool entries** in `messages` / `trace`, not as REST errors on `/messages`.

---

## Extending REST

1. Register on `rest_api_init` in `Ahentic_REST_Sessions` (or a sibling controller).
2. Keep `permission_callback` = `can_manage` (+ ownership for session ids).
3. Return `to_rest()` when the sidebar should refresh run state.
4. Add a helper in `api.js`; do not invent admin-ajax for agent-facing flows.
5. Prefer Abilities for agent tools — REST here is for **session/UI control**, not a parallel tool API.
