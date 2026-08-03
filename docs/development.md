# Local development

How to run Ahentic locally, connect an AI provider, and debug an agent run.

**Related:** [architecture.md](./architecture.md) · [README](../README.md)

---

## Prerequisites

- Node.js 18+, npm 9+
- Docker (if using `@wordpress/env`)
- A WordPress site with the plugin loaded (wp-env or your own stack)
- An AI provider configured for the WordPress AI Client / Connectors

---

## Install & build assets

```bash
git clone <free-repo>
cd Ahentic
npm install
npm start                 # webpack watch — free build
```

Compiled assets land under `build/`. Keep `npm start` running while iterating on the sidebar.

### Premium checkout (optional)

```bash
# Private repo into pro__premium_only/
npm run start:premium     # from free root, or follow premium README
```

Free `npm start` sets `AHENTIC_BUILD` to `free` via `scripts/update-build-type.js`.

---

## WordPress environment

### Option A: wp-env

```bash
npx wp-env start
```

Config: [`.wp-env.json`](../.wp-env.json) (PHP 8.2, this plugin mounted).

- Admin: typically `http://localhost:8888/wp-admin` (see wp-env output)
- Default credentials: `admin` / `password` (wp-env defaults)

### Option B: existing site

Symlink or copy the plugin into `wp-content/plugins/ahentic`, activate it, run `npm start` so `build/` stays fresh.

---

## AI provider setup

Ahentic does **not** hardcode a vendor. Generation goes through:

1. Core helpers (`wp_ai_client_prompt`) when available (WP 7.0+), else
2. Composer `wordpress/php-ai-client` (loaded only if Core SDK is not already present)

On the site:

1. Install/configure whatever your stack uses for **AI Connectors** / WordPress AI (Settings → AI / Connectors — exact UI depends on WP version and companion plugins).
2. Ensure at least one provider/model is available.
3. Open Ahentic (`Cmd/Ctrl+I`) and send a message. If no client is available, the orchestrator returns `ahentic_ai_unavailable`.

Code entry: `src/orchestrator/class-ai.php`.

---

## Using the sidebar

1. Log in as a user with `manage_options`.
2. Toggle via admin bar **Ahentic** or **Cmd/Ctrl+I**.
3. Choose **Agent** (writes + HITL) or **Ask** (readonly tools).
4. Prefer testing editor tools with a post/page open in the block editor.

Chrome (open state, width, tabs) persists in `localStorage`. Messages persist on the session CPT — refresh should reload conversation from REST.

---

## Debugging a run

### In the UI

- Live **progress** label under the latest turn
- **Plan** card when the model emits a multi-step plan
- **Debugger** panel (trace events: `step_start`, `llm_request`, `tool_executed`, `hitl_pause`, `browser_pause`, …)

### Session REST

```bash
# Replace nonce / cookies with a logged-in browser session, or use WP-CLI eval / Application Passwords if configured.
curl -s "http://localhost:8888/wp-json/ahentic/v1/sessions/{id}" \
  -H "X-WP-Nonce: …"
```

Useful fields: `status`, `progress`, `pendingTool`, `trace`, `messages`, `plan`, `artifacts`, `lastError`.

See [rest.md](../src/admin/rest.md).

### Common statuses

| Status | What to do |
| --- | --- |
| `running` | Wait / poll; check trace for last step |
| `awaiting_human` | Allow/Deny in sidebar |
| `awaiting_browser` | Sidebar should auto-run; check console if stuck |
| `error` | Read `lastError` + trace |
| Stuck `running` | `POST …/sessions/{id}/continue` (stall fallback) |

### PHP / queue

- Interactive path: process on `shutdown` after the message response (`Ahentic_Step_Queue::schedule_interactive_run`).
- Fallback: Action Scheduler or WP-Cron single event.
- Local sites without working cron often need **continue** or a triggered cron spawn.

---

## Lint & package

```bash
npm run lint
npm run format
npm run build          # free zip via scripts/package.js
```

Free builds should stay Plugin Check clean. Do not ship `pro__premium_only/` in the free package.

---

## Project conventions (short)

- **JS only** in the sidebar (no new `.ts`/`.tsx` sources).
- Prefer **Abilities** for agent-facing actions; prefer **AI Client** over vendor SDKs.
- Colocate docs next to code under `src/**`; cross-cutting guides under `docs/`.
- Cursor rules: `.cursor/rules/ahentic-*.mdc`.

---

## Where to read next

1. [architecture.md](./architecture.md)
2. [orchestrator.md](../src/orchestrator/orchestrator.md)
3. [control-block.md](../src/orchestrator/control-block.md)
4. [abilities.md](../src/abilities/abilities.md)
5. [session.md](../src/session/session.md)
