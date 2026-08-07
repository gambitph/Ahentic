# Task 01 — Multi-window: viewer overlay when the agent is active elsewhere

**Track:** A (sidebar session safety)
**Ship with:** **v1 MVP**
**Source:** Product need — same `ahentic-session` open in two browser windows must not double-run browser steps / agent actions. Full take-over is deferred to [v3 future task](../future/multi-window-take-over.md).

> **Grill settled (2026-08-07):** first-wins; claim only while live run; `localStorage` key `ahentic.session-runner.v1` + `storage` events + ~1s heartbeat / **15s** stale; renew on visibility/focus; initiator claims before drive network; release on idle / failed drive / `pagehide`; viewers gate browser/HITL/send/suggested/continue but may Stop; overlay over content+composer; awaiting_browser hint “Keep this tab visible while this runs”; TDD seam = `session-runner-lock` helper.

## Problem

An Ahentic session can be open as a tab in more than one browser window (same browser profile). Today each window independently:

- polls the session and renders live state
- when `status === 'awaiting_browser'`, runs `runBrowserAbility(pending)` and POSTs `browser-results`

If two windows both treat themselves as the agent runner, the same pending browser step can execute twice (or race), and HITL / continue actions can collide. Conversation bodies already live on the server and stay synced via poll — the gap is **who is allowed to drive** the run in the browser.

## Current state

- Implemented: `session-runner-lock.js`, sidebar gates, `viewer-overlay.js`, awaiting_browser keep-tab hint, docs in `sidebar.md`.
- Session messages / status / plan / pending tool remain server-owned; chrome stays in `ahentic.sidebar.v1` (claims are a **separate** key).

## Scope (v1) — shipped shape

### Active-window claim

- Per-session claim in `ahentic.session-runner.v1` (`ownerId` + `heartbeatAt`).
- Only while live: `running` | `awaiting_human` | `awaiting_browser`.
- First non-stale claim wins; initiator claims before send / continue / approve / browser resume.
- Heartbeat ~1s; stale 15s; renew on `visibilitychange` / focus; `pagehide` release + stale reclaim.

### Viewer-only behavior

- Poll/sync still runs.
- Must not: browser resume, HITL, send, suggested actions, auto/manual continue.
- Stop/cancel allowed (overlay Stop + composer stop).

### Overlay + hint

- Faded session pane (content + composer) + heading “This agent is active in another window”.
- No Take over (v3).
- Active runner `awaiting_browser`: live-status hint “Keep this tab visible while this runs”.

## Out of scope (v1)

- Take over — [v3](../future/multi-window-take-over.md)
- Cross-browser / cross-device
- Server-side exclusive locks

## Acceptance criteria

- [x] With session S live in window A, opening S in window B shows the viewer overlay and does **not** run browser abilities or double-submit drive actions from B
- [x] Window B still reflects server-synced session state (messages / progress update while A drives)
- [x] Closing or abandoning A eventually allows B (or a new window) to become active (stale-claim reclaim / pagehide release)
- [x] Overlay copy communicates that the agent is active in another window; no take-over control in v1
- [x] Grill notes / claim protocol recorded in `sidebar.md` for v3 take-over reuse
- [x] Unit tests for `session-runner-lock` claim / stale / heartbeat / release
- [x] `awaiting_browser` keep-tab-visible hint on the active runner

## Files

- `src/admin/js/sidebar/session-runner-lock.js` (+ `.test.js`)
- `src/admin/js/sidebar/viewer-overlay.js`
- `src/admin/js/sidebar/sidebar.js`
- `src/admin/js/sidebar/tab-content.js`
- `src/admin/css/index.css`
- `src/admin/js/sidebar/sidebar.md`

## Related

- Future: [`../future/multi-window-take-over.md`](../future/multi-window-take-over.md) (v3)
- [`src/admin/js/sidebar/sidebar.md`](../../src/admin/js/sidebar/sidebar.md)
