# Architecture

High-level map of Ahentic for engineers.

**Canonical product should:** [`pro__premium_only/docs/prd/README.md`](../pro__premium_only/docs/prd/README.md)  
**Glossary:** [`CONTEXT.md`](../CONTEXT.md) · **ADRs:** [`docs/adr/`](./adr/)  
**Subsystem contracts:** `src/**/CONTRACT.md`  
**How-it-works:** colocated `src/**/*.md` (implementation map — must not invent product law)  
**Concept walkthroughs (pedagogical):** [`docs/walkthroughs/`](./walkthroughs/) — beginner cross-stack tours; not product law (see [`docs/agents/explain-concept.md`](./agents/explain-concept.md))

---

## Product surface

Ahentic is a **website developer** workspace in WordPress (Ask + Agent under that north star):

- Primary UI: **React sidebar** on wp-admin and the front-end (default `manage_options`)
- Primary runtime: **PHP orchestrator** over **session CPT** state
- Tools: **WordPress Abilities** (server PHP and browser JS)
- Public Help: **`docs.wpahentic.com`** (static Cloudflare Pages from [`docs-site/`](../docs-site/); see that README for hosting)

Users do not call model vendors from the browser. The sidebar talks to Ahentic REST; the orchestrator talks to the WordPress AI Client / php-ai-client.

Free = interactive (human present). Premium = Agents / automation / snippets — see [Free vs premium PRD](../pro__premium_only/docs/prd/free-vs-premium.md).

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
│ pending, plan,  │         │                 │         └─────────────────────┘
│ artifacts, …    │         └────────┬────────┘
└─────────────────┘                  │
                                     ▼
                            ┌─────────────────┐
                            │ Tool runner     │
                            │ HITL / browser  │
                            │ → execute/assess│
                            └────────┬────────┘
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
4. Each step: LLM think → parse control block → maybe Tool runner (`Ahentic_Tool_Runner`) → enqueue next step or finish (`idle`).
5. If a tool needs the browser: Tool runner sets `awaiting_browser` → sidebar runs JS → `POST …/browser-results` → `record_completed_result`.
6. If a tool needs approval: Tool runner sets `awaiting_human` → sidebar Allow/Deny → `POST …/approvals` → Tool runner resumes (`skip_hitl`).

Runtime laws (completion, plan, verify, HITL, browser preflight): [Agent runtime PRD](../pro__premium_only/docs/prd/agent-runtime.md).

---

## Major subsystems

| Subsystem | Responsibility | Contract | How-it-works |
| --- | --- | --- | --- |
| Sidebar | UX, poll, HITL UI, browser ability runtime | — | [sidebar.md](../src/admin/js/sidebar/sidebar.md) · [PRD](../pro__premium_only/docs/prd/sidebar.md) |
| REST | Session CRUD + run control | [CONTRACT](../src/admin/CONTRACT.md) | [rest.md](../src/admin/rest.md) |
| Session | Persist conversation + run state | [CONTRACT](../src/session/CONTRACT.md) | [session.md](../src/session/session.md) |
| Orchestrator | Agent loop, prompts, finish gates | [CONTRACT](../src/orchestrator/CONTRACT.md) | [orchestrator.md](../src/orchestrator/orchestrator.md) |
| Tool runner | Ability pipeline (HITL / browser / execute / assess) | (orchestrator contract) | [orchestrator.md](../src/orchestrator/orchestrator.md) · `class-tool-runner.php` |
| Control block | Model ↔ orchestrator protocol | (orchestrator contract) | [control-block.md](../src/orchestrator/control-block.md) |
| Abilities | Tool surface | [CONTRACT](../src/abilities/CONTRACT.md) | [abilities.md](../src/abilities/abilities.md) |
| Artifacts | Stage large payloads by key | (session contract) | [artifacts.md](../src/session/artifacts.md) |
| AI | Provider-agnostic generation | — | `src/orchestrator/class-ai.php` |
| Queue | Async steps after HTTP response | — | `src/orchestrator/class-queue.php` |

---

## Two runtimes for tools

| Runtime | When | How |
| --- | --- | --- |
| **Server** | WP APIs, public HTTP, session meta | Tool runner → `Ahentic_Abilities::execute` (ability dispatch) |
| **Browser** | Gutenberg, DOM, logged-in same-site fetch | Tool runner pauses → sidebar `runBrowserAbility` → result POST → `record_completed_result` |

Agent-facing runs must go through the Tool runner — do not call `Ahentic_Abilities::execute` from a second orchestrator/REST path that reimplements HITL or browser pauses. (E2E `run-ability` may call `execute` directly to test ability bodies in isolation.)

Content routing: editor open for post P → browser; else server — [Content & editor PRD](../pro__premium_only/docs/prd/content-and-editor.md).

---

## Data ownership

| Data | Storage |
| --- | --- |
| Open/closed, width, theme, mode, placement, open tab ids | Browser `localStorage` (`ahentic.sidebar.v1`) |
| Messages, tool results, status, plan, pending tool, page context, artifacts, trace, token spend, contextUsage | `ahentic-session` CPT post meta |
| Site knowledge | Option `ahentic_site_knowledge` — [PRD](../pro__premium_only/docs/prd/site-knowledge.md) |

---

## Modes

| Mode | Tools / completion |
| --- | --- |
| **Agent** | Full ability list; plan+verify for multi-step/writes; HITL for risk tiers |
| **Ask** | Readonly abilities only; done when answer delivered |

---

## Free vs premium boundary

- Free plugin code must remain Directory-safe (Plugin Check).
- Premium features load from `pro__premium_only/` when `AHENTIC_BUILD === 'premium'`.
- Product packaging: [free-vs-premium PRD](../pro__premium_only/docs/prd/free-vs-premium.md).

---

## Extending Ahentic (cheat sheet)

| Goal | Where to start |
| --- | --- |
| Change product behavior | Relevant [PRD](../pro__premium_only/docs/prd/README.md) first |
| New server tool | [abilities CONTRACT](../src/abilities/CONTRACT.md) + [server-abilities.md](../src/abilities/server-abilities.md) |
| New editor/browser tool | [client-abilities.md](../src/abilities/client-abilities.md) |
| Change agent routing / caps | [orchestrator CONTRACT](../src/orchestrator/CONTRACT.md) · [Agent runtime PRD](../pro__premium_only/docs/prd/agent-runtime.md) |
| User skills (`SKILL.md`, not shipped) | [Future: Skills](../pro__premium_only/docs/future-skills.md) |
| Stage large content | [artifacts.md](../src/session/artifacts.md) |
| New REST surface | [admin CONTRACT](../src/admin/CONTRACT.md) + sidebar `api.js` |
| Local debugging | [development.md](./development.md) |
