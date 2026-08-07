# Sidebar

The React workspace UI for Ahentic. It is the primary product surface on **wp-admin and the front-end** for users with `manage_options`. It sends user messages, polls the orchestrator, renders progress / plans / HITL, and **runs browser abilities** when the session is `awaiting_browser`.

> **Canonical should:** [Sidebar PRD](../../../../pro__premium_only/docs/prd/sidebar.md) · **REST contract:** [admin CONTRACT](../../CONTRACT.md)

**Code:** `src/admin/js/sidebar/` (mount via `Ahentic_Script_Loader` → `#ahentic-root`)

**Related:** [Orchestrator](../../../orchestrator/orchestrator.md) · [REST](../../rest.md) · [Client abilities](../../../abilities/client-abilities.md) · [Session](../../../session/session.md) · [Architecture](../../../../docs/architecture.md)

---

## Stack

- **React** via `@wordpress/element` (JSX in `.js`, not TypeScript)
- **Components / i18n** — `@wordpress/components`, `@wordpress/i18n`
- **Icons** — `lucide-react`
- **Styles** — scoped under `.ahentic`; tokens on `[data-ahentic-theme="dark"]` (primary `#5750F8` / `--ah-primary`)

Do not call AI vendors from the sidebar. Talk to WordPress REST (`ahentic/v1`) and run local browser abilities only when the orchestrator pauses.

---

## Key files

| File | Role |
| --- | --- |
| `sidebar.js` | Root UI: tabs, send, poll, browser resume, HITL |
| `tab-content.js` | Message list, composer chrome, pending cards |
| `composer.js` | Input + mode |
| `api.js` | REST helpers |
| `storage.js` | `localStorage` chrome state |
| `session-runner-lock.js` | Per-session active-runner claim (multi-window) |
| `viewer-overlay.js` | Viewer-only overlay when another window drives |
| `browser-abilities.js` | Dispatch pending browser tools |
| `editor-abilities.js` | Gutenberg / block editor implementations |
| `block-ref-registry.js` | Opaque `b1` ↔ `clientId` map (tab memory) |
| `page-context.js` / `visible-page.js` | Page identity / visible UI snapshots |
| `hitl-approval-card.js` | Allow / Deny |
| `plan-card.js` | Multi-step plan checklist |
| `debugger-panel.js` | Trace / debug |

---

## What persists where

| Data | Where |
| --- | --- |
| Open/closed, width, theme, mode, placement, float rect, open tab ids | Browser `localStorage` (`ahentic.sidebar.v1` via `storage.js`) |
| Active-runner claim per session (multi-window) | Browser `localStorage` (`ahentic.session-runner.v1` via `session-runner-lock.js`) — **not** the chrome blob |
| Messages, tool results, status, plan, pending tool, artifacts | `ahentic-session` CPT (server) |

Refreshing the page reloads chrome from localStorage and re-fetches sessions from REST. Conversation bodies are never stored in localStorage.

### Multi-window runner lock (v1)

Same session open in two windows: only one window is the **active runner** while the session is live (`running` | `awaiting_human` | `awaiting_browser`).

- First claim wins (initiator claims before send / continue / approve). Later windows are **viewers**.
- Viewers keep polling (synced transcript) but must not run browser abilities, HITL, send, suggested actions, or auto-continue. **Stop** remains available (overlay + composer).
- Heartbeat ~1s, stale after 15s; renew on `visibilitychange` / focus; best-effort `pagehide` release.
- Viewer UI: faded session pane + “This agent is active in another window” (no take-over in v1).
- While `awaiting_browser` on the active runner, live status shows hint: “Keep this tab visible while this runs”.

Take-over is deferred (v3). See `tasks/mvp-sidebar/01-multi-window-viewer-overlay.md` and `tasks/future/multi-window-take-over.md`.

---

## Talk to the orchestrator

```text
User sends message
  → optimistic local bubble
  → POST /sessions/{id}/messages  { content, mode, pageContext }
  → session status becomes running
  → poll GET /sessions/{id} (~600ms) while running | awaiting_*

awaiting_human
  → HITL card → POST /sessions/{id}/approvals

awaiting_browser
  → useEffect runs runBrowserAbility(pending)
  → POST /sessions/{id}/browser-results  { call_id, result | error }
  → apply session → poll continues / next think
```

Other routes: create/list/patch sessions, cancel, continue (stall fallback), suggested actions.

`pageContext` is collected on send (and patched on navigation) so the orchestrator knows URL / editor open / post id.

---

## Browser ability resume

When `status === 'awaiting_browser'` and `pendingTool.runtime === 'browser'`:

1. Skip when this window is a **viewer** for the session (another window holds the runner claim).
2. Deduplicate with `browserResumeRef` (`inflight` / `done`) so React re-renders do not double-run.
3. `runBrowserAbility(pending)` in `browser-abilities.js`.
4. POST result with matching `call_id`.
5. Retry on transient network errors; treat 409 / already-resumed as success and refresh session.

The sidebar does **not** invent browser tool calls — only the orchestrator schedules them via `pending_tool`.

Live status shows “Keep this tab visible while this runs” under the awaiting-browser label (active runner only).

---

## UX behaviors

- **Keyboard:** `Cmd+I` (macOS) / `Ctrl+I` (Windows/Linux) toggles the sidebar.
- **Placement:** docked left/right pushes page content (`--ahentic-sidebar-inset`); floating overlays.
- **Modes:** Agent vs Ask (Ask = readonly tools server-side).
- **Live status:** progress label + trace-derived label while busy.
- **Plans:** server `_ahentic_plan` → `plan-card.js`.
- **Stale polls:** fingerprint / meta checks avoid clobbering in-flight sends or optimistic messages.
- **Busy lock:** after send, local meta is floored to `running` and the tab stays on the poll list until a real terminal status arrives — so a raced idle snapshot cannot blank the live status or skip `awaiting_browser` resume.

---

## Styling rules

- Scope chrome under `.ahentic`.
- Prefer CSS tokens (`--ah-bg`, `--ah-fg`, `--ah-primary`, …).
- Logo: `src/admin/images/ahentic-icon.svg` (recolor with CSS mask; do not bake brand purple into the SVG).

---

## Extending the sidebar

- **New UI chrome** — components under this folder; keep orchestrator logic on the server.
- **New browser ability** — PHP stub + catalog in `Ahentic_Abilities_Browser`, handler in `browser-abilities.js` / `editor-abilities.js`. See [client-abilities.md](../../abilities/client-abilities.md).
- **New REST surface** — `class-rest-sessions.php` + `api.js`; do not add ad-hoc admin-ajax for agent-facing actions.
