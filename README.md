# Ahentic – AI Workspace

An intelligent AI agent that understands your WordPress site and works alongside you to build, edit, troubleshoot, and manage it.

## How it works (mental model)

```text
┌─────────────────┐     REST ahentic/v1      ┌──────────────────────┐
│  React sidebar  │ ◄──────────────────────► │  Session repository  │
│  (admin + FE)   │   poll / messages /      │  (ahentic-session)   │
│                 │   approvals / browser    └──────────┬───────────┘
└────────┬────────┘                                     │
         │ browser abilities                            ▼
         │ (awaiting_browser)              ┌──────────────────────┐
         └────────────────────────────────►│  Orchestrator loop   │
                                           │  think → tools → …   │
                                           └──────────┬───────────┘
                                ┌─────────────────────┼─────────────────────┐
                                ▼                     ▼                     ▼
                         WordPress AI Client    Server abilities      Browser pause
                         (php-ai-client)        (PHP execute)         (sidebar JS)
```

1. User chats in the **sidebar**.
2. Messages and run state live on **`ahentic-session`** posts (not `localStorage`).
3. The **orchestrator** runs an async agent loop (LLM + Abilities).
4. **Server abilities** execute in PHP; **browser abilities** pause for the sidebar.
5. Large drafts can be **staged as session artifacts** and applied with `from_memory`.

## Requirements

- Node.js 18+ / npm 9+
- WordPress with **Abilities API** (6.9+) and a configured **AI provider** (Core AI Client / Connectors on WP 7.0+, or bundled `wordpress/php-ai-client`)
- PHP 8.2 recommended (see `.wp-env.json`)

## Quick start

See **[docs/development.md](docs/development.md)** for local setup, AI connectors, and debugging a run.

```bash
npm install
npm start                 # webpack watch (free build)
npx wp-env start          # optional local WP
```

Toggle the sidebar with **Cmd/Ctrl+I** (users with `manage_options`).

## Scripts

| Script | Purpose |
| --- | --- |
| `npm start` | Dev build + watch (free) |
| `npm run build` | Production free build + package |
| `npm run build:premium` | Premium package (needs `pro__premium_only/`) |
| `npm run lint` / `lint:js` / `lint:css` | Lint |
| `npm run format` | Prettier |
| `composer test` | PHPUnit — pure PHP, no WordPress |
| `npm run test:e2e` | Playwright against an isolated wp-env instance (Docker required) |

## Stack

- **JavaScript** (not TypeScript) — React sidebar via `@wordpress/element`
- **WordPress AI Client** / [php-ai-client](https://github.com/WordPress/php-ai-client)
- **WordPress Abilities API** for agent tools
- Sidebar **chrome** in `localStorage`; **conversation + run state** on the session CPT

## Repository layout

| Path | Role |
| --- | --- |
| `ahentic.php` | Bootstrap / loads PHP modules |
| `src/orchestrator/` | Agent loop, AI wrapper, step queue |
| `src/abilities/` | Ability modules (server + browser catalog) |
| `src/session/` | Session CPT, repository, artifacts |
| `src/admin/` | REST, script loader, React sidebar |
| `src/playbooks/` | Guidance playbooks for the agent |
| `build/` | Compiled assets (generated) |
| `pro__premium_only/` | Premium-only code (private repo checkout) |
| `docs/` | Cross-cutting developer docs |

## Developer documentation

### Start here

| Doc | Description |
| --- | --- |
| [CONTEXT.md](CONTEXT.md) | Glossary / ubiquitous language |
| [pro__premium_only/docs/prd/README.md](pro__premium_only/docs/prd/README.md) | Product PRDs (should) — requires Premium checkout |
| [docs/architecture.md](docs/architecture.md) | End-to-end architecture map |
| [docs/development.md](docs/development.md) | Local setup, AI, debugging |
| [docs/adr/](docs/adr/) | Architectural decisions |

When code disagrees with a PRD or `CONTRACT.md`, the **PRD/contract wins**.

### By area (next to code)

| Doc | Description |
| --- | --- |
| `src/**/CONTRACT.md` | Must-guarantee interfaces (session, orchestrator, REST, abilities) |
| [src/orchestrator/orchestrator.md](src/orchestrator/orchestrator.md) | Agent loop how-it-works |
| [src/orchestrator/control-block.md](src/orchestrator/control-block.md) | `AHENTIC_DEBUG` wire format |
| [src/admin/js/sidebar/sidebar.md](src/admin/js/sidebar/sidebar.md) | React sidebar how-it-works |
| [src/admin/rest.md](src/admin/rest.md) | REST API map |
| [src/session/session.md](src/session/session.md) | Session CPT + meta |
| [src/session/artifacts.md](src/session/artifacts.md) | Session-scoped artifacts |
| [src/abilities/abilities.md](src/abilities/abilities.md) | How to write abilities |
| [src/abilities/server-abilities.md](src/abilities/server-abilities.md) | PHP tools |
| [src/abilities/client-abilities.md](src/abilities/client-abilities.md) | Browser tools |

Cursor rules under `.cursor/rules/` encode project conventions (Abilities, AI Client, JS/React, Plugin Check, free/premium repos).

## Free vs premium

- **Free** ([gambitph/Ahentic](https://github.com/gambitph/Ahentic)) — Directory-bound plugin; must pass [Plugin Check](https://github.com/wordpress/plugin-check).
- **Premium** ([bfintal/Ahentic-Premium](https://github.com/bfintal/Ahentic-Premium)) — checked out into `pro__premium_only/`. Do not put premium logic in the free tree.

Private product docs (Premium checkout): [`pro__premium_only/docs/README.md`](pro__premium_only/docs/README.md) → start at [`prd/`](pro__premium_only/docs/prd/README.md). Colocated `src/**/*.md` files are how-it-works maps, not product law.

## License

GPL v2 or later.
