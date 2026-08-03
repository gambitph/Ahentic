# Architecture

High-level map of Ahentic for engineers. Implementation details live next to code (links below).

---

## Product surface

Ahentic is a **Cursor-like AI workspace** embedded in WordPress:

- Primary UI: **React sidebar** on wp-admin and the front-end (`manage_options`)
- Primary runtime: **PHP orchestrator** over **session CPT** state
- Tools: **WordPress Abilities** (server PHP and browser JS)

Users do not call model vendors from the browser. The sidebar talks to Ahentic REST; the orchestrator talks to the WordPress AI Client / php-ai-client.

---

## System diagram

```text
                    ┌──────────────────────────────────────┐
                    │           User (manage_options)        │
                    └──────────────────┬───────────────────┘
                                       │ Cmd/Ctrl+I
                    ┌──────────────────▼───────────────────┐
                    │         React sidebar (#ahentic-root)  │
                    │  chrome: localStorage                  │
                    │  messages/run: REST poll               │
                    └──────────────────┬───────────────────┘
                                       │ ahentic/v1
                    ┌──────────────────▼───────────────────┐
                    │         Ahentic_REST_Sessions          │
                    └──────────────────┬───────────────────┘
                                       │
         ┌─────────────────────────────┼─────────────────────────────┐
         ▼                             ▼                             ▼
┌─────────────────┐         ┌─────────────────┐         ┌─────────────────────┐
│ Session CPT     │◄────────│ Orchestrator    │────────►│ Ahentic_AI          │
│ entries, status │         │ process_step    │         │ Core / php-ai-client│
│ pending, plan,  │         │ HITL / browser  │         └─────────────────────┘
│ artifacts, …    │         └────────┬────────┘
└─────────────────┘                  │
                     ┌───────────────┼───────────────┐
                     ▼               ▼               ▼
              Server abilities  Browser pause   Artifacts
              (PHP execute)     (sidebar JS)    (from_memory)
```

---

## Request lifecycle (happy path)

1. Sidebar `POST /sessions/{id}/messages` with `content`, optional `mode`, `pageContext`.
2. Orchestrator appends the user entry, sets `status=running`, schedules a step (shutdown + queue).
3. REST returns immediately; sidebar polls `GET /sessions/{id}`.
4. Each step: LLM think → parse control block → maybe run tools → enqueue next step or finish (`idle`).
5. If a tool needs the browser: `awaiting_browser` → sidebar runs JS → `POST …/browser-results`.
6. If a tool needs approval: `awaiting_human` → sidebar Allow/Deny → `POST …/approvals`.

Details: [orchestrator.md](../src/orchestrator/orchestrator.md), [rest.md](../src/admin/rest.md), [sidebar.md](../src/admin/js/sidebar/sidebar.md).

---

## Major subsystems

| Subsystem | Responsibility | Doc |
| --- | --- | --- |
| Sidebar | UX, poll, HITL UI, browser ability runtime | [sidebar.md](../src/admin/js/sidebar/sidebar.md) |
| REST | Session CRUD + run control | [rest.md](../src/admin/rest.md) |
| Session | Persist conversation + run state | [session.md](../src/session/session.md) |
| Orchestrator | Agent loop, prompts, pauses | [orchestrator.md](../src/orchestrator/orchestrator.md) |
| Control block | Model ↔ orchestrator protocol | [control-block.md](../src/orchestrator/control-block.md) |
| Abilities | Tool surface | [abilities.md](../src/abilities/abilities.md) |
| Artifacts | Stage large payloads by key | [artifacts.md](../src/session/artifacts.md) |
| AI | Provider-agnostic generation | `src/orchestrator/class-ai.php` |
| Queue | Async steps after HTTP response | `src/orchestrator/class-queue.php` |

---

## Two runtimes for tools

| Runtime | When | How |
| --- | --- | --- |
| **Server** | WP APIs, public HTTP, session meta | `Ahentic_Abilities::execute` in the step worker |
| **Browser** | Gutenberg, DOM, logged-in same-site fetch | Orchestrator pauses → sidebar `runBrowserAbility` → result POST |

Same tool-result shape in the session transcript either way. See [server-abilities.md](../src/abilities/server-abilities.md) and [client-abilities.md](../src/abilities/client-abilities.md).

---

## Data ownership

| Data | Storage |
| --- | --- |
| Open/closed, width, theme, mode, placement, open tab ids | Browser `localStorage` (`ahentic.sidebar.v1`) |
| Messages, tool results, status, plan, pending tool, page context, artifacts, trace | `ahentic-session` CPT post meta |
| Site-wide knowledge (future / premium) | Not session chrome — separate concern |

---

## Modes

| Mode | Tools |
| --- | --- |
| **Agent** | Full ability list (writes may HITL) |
| **Ask** | Readonly abilities only |

---

## Free vs premium boundary

- Free plugin code must remain Directory-safe (Plugin Check).
- Premium features load from `pro__premium_only/` when `AHENTIC_BUILD === 'premium'`.
- Do not implement premium-only product logic in the free tree.

Private roadmap / product docs may exist under `pro__premium_only/docs/`. Prefer **`src/**` colocated docs** for how the current free agent runtime works.

---

## Extending Ahentic (cheat sheet)

| Goal | Where to start |
| --- | --- |
| New server tool | [abilities.md](../src/abilities/abilities.md) + [server-abilities.md](../src/abilities/server-abilities.md) |
| New editor/browser tool | [client-abilities.md](../src/abilities/client-abilities.md) |
| Change agent routing / caps | [orchestrator.md](../src/orchestrator/orchestrator.md), [control-block.md](../src/orchestrator/control-block.md) |
| Stage large content | [artifacts.md](../src/session/artifacts.md) |
| New REST surface | [rest.md](../src/admin/rest.md) + sidebar `api.js` |
| Local debugging | [development.md](./development.md) |
