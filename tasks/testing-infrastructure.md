# Testing infrastructure — PRD

**Status:** Proposed — confirm before implementation.
**Track:** Prerequisite (blocks `tasks/mvp-abilities` — see that folder's `00-INDEX.md`).

> **Why this lives in `tasks/`, not `pro__premium_only/docs/prd/`:** the PRD index there is a
> fixed list of *product*-behavior docs (what the user sees/does). This document is internal
> engineering process — it governs how we test, not what Ahentic does — so it follows the
> `tasks/` convention (temporary working doc, current state → scope → acceptance criteria,
> deleted once done) like `tasks/mvp-abilities/01-*.md`. The durable output of this work is
> `docs/agents/testing.md` (canonical policy) and `tests/e2e/README.md` (harness mechanics),
> both of which get rewritten as part of this task, not this file.

## Why

The original ask: ready our tests *before* starting `tasks/mvp-abilities` (~15 new abilities),
so we don't regress the orchestrator/HITL/existing abilities while building them.

A first pass at this already landed a Docker/`wp-env`-based Playwright harness (see "Current
state" below) and got it green in CI. Further discussion surfaced requirements that harness
doesn't meet — no Docker requirement for local dev, a visual debug mode, mocked AI responses so
sidebar/HITL flows are testable without a real LLM, and a second PHPUnit tier for orchestrator
logic — which change the runtime enough to warrant replacing it outright rather than patching it.
This document is the record of that revised plan, confirmed via a decision-by-decision review
before rebuilding.

## Current state (being replaced)

Already built, all e2e-specific (not `.wp-env.json`, see below):

- `.wp-env.tests.json` — Docker-based `wp-env` config, isolated port 8889
- `scripts/test-e2e.js` — starts that `wp-env` instance, then runs Playwright
- `tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php` — test-only REST route
  (`ahentic-e2e/v1/run-ability`) delegating to `Ahentic_Abilities::execute()`
- `tests/e2e/global-setup.js` — mints an application password via `wp-env run cli`
- `tests/e2e/utils/ability-client.js` — Basic-auth REST client for specs
- `tests/e2e/specs/content-and-plugins.spec.js` — one proof spec against `ahentic/get-site-snapshot`
- `.github/workflows/tests.yml` — `phpunit` job (pure-PHP only) + `e2e` job (Docker `wp-env`)
- `tests/unit/ControlBlockTest.php` — the one existing pure-PHP PHPUnit test

**Not touched by this task:** `.wp-env.json` (predates this effort; a contributor's normal
manual local WP preview/dev environment, port 8888 — stays regardless of what the automated
test suite uses).

## Decisions

Resolved via a decision-by-decision review (the `grilling` skill) rather than assumed. Recorded
here so the "why" survives past this temporary file being deleted.

| # | Decision | Chosen |
| --- | --- | --- |
| 1 | Scope of this pass | Finish/replace the existing harness **and** add a real browser-driven tier (sidebar UX), not just patch what exists |
| 2 | New PHPUnit "medium" tier mechanism | Mock WordPress functions with **Brain Monkey** (default pick over WP_Mock — actively maintained, built on Mockery; swappable later if it proves awkward) — orchestrator code runs for real, `get_option()`/`$wpdb`/etc. are stubbed. No real WordPress ever boots for PHPUnit. |
| 3 | e2e WordPress runtime (no Docker) | **`@wp-playground/cli`** (WordPress Playground: WASM PHP + SQLite, Node-only, no Docker/MySQL/Apache). Note: `@wp-now/wp-now` — what "no Docker" e2e discussions usually point to — was deprecated in favor of this package in June 2026; `@wp-playground/cli`'s `server` command is the one meant for CI/automation. |
| 4 | AI response mocking | Extend the e2e-only mu-plugin with a queue: a spec `POST`s canned responses first; a filter inside `Ahentic_AI::complete_chat()` pops from that queue instead of calling a real provider, when the filter is hooked (only true when this mu-plugin is loaded). Real orchestrator code runs end-to-end; only the LLM call is faked. |
| 5 | DB seeding | Same mu-plugin gains seed/reset REST routes (declarative fixture: posts/users/options) — one consistent test-only surface, not a second mechanism. |
| 6 | `npm run test:debug` | Maps to `playwright test --ui` (Playwright's own UI mode — Electron app, live embedded browser view, time-travel per step). Matches the Cimo/Interactions convention. |
| 7 | Fate of the Docker/`wp-env` e2e harness | **Replaced fully.** `.wp-env.tests.json`, the Docker branch of `scripts/test-e2e.js`, and the Docker-based `e2e` CI job are deleted, not kept as a fallback. `.wp-env.json` (manual dev) is untouched. |
| 8 | Ability-correctness tier vs. browser tier | **Both, as two tiers.** REST-direct (`run-ability`, no browser, no LLM) stays the default for "is this ability correct." A smaller browser-driven tier (real `page` fixture) is reserved for sidebar UX and abilities that specifically need to be observed through a live chat turn (e.g. the HITL card rendering/being clickable). |

## Scope

### 1. Swap the e2e runtime: Docker/`wp-env` → `@wp-playground/cli`

- Remove `.wp-env.tests.json` and the Docker path in `scripts/test-e2e.js`.
- New start command mounts the plugin directory and the e2e mu-plugins directory, e.g.
  `npx @wp-playground/cli@latest server --mount=.:/wordpress/wp-content/plugins/ahentic --mount=./tests/e2e/mu-plugins:/wordpress/wp-content/mu-plugins --php=8.2 --login` —
  exact flags/persistence mode (`server` vs `start`, temp vs. persisted site) to be finalized
  during implementation; `server` is the documented fit for CI/automation.
- `playwright.config.js` `baseURL` follows whatever port that command binds (default `9400`).
- `tests/e2e/global-setup.js` no longer shells out to `wp-env run cli`; use Playground's
  `--login`/auto-admin (or a blueprint) instead of minting an application password via CLI —
  confirm the simplest working auth path (see item 4 below) during implementation.

### 2. Extend the e2e mu-plugin: AI-mock queue + DB seeding

- `POST /ahentic-e2e/v1/seed-ai-responses` — spec pushes an ordered list of canned
  `complete_chat()`-shaped results; a new filter in `Ahentic_AI::complete_chat()`
  (short-circuits when hooked) pops one per call. Empty queue after seeding = fall through to
  a real provider (should never happen in a well-formed spec, but must not fatal).
- `POST /ahentic-e2e/v1/seed` — declarative fixture (posts/users/options) for a test to set up
  needed WordPress state without a slow UI walk-through.
- `POST /ahentic-e2e/v1/reset` (or per-test cleanup convention) so specs don't leak state into
  each other — decide the isolation strategy (per-test reset call vs. fresh Playground instance
  per file) during implementation.

### 3. PHPUnit "medium" tier

- Add `brain/monkey` (`require-dev` in `composer.json`).
- New `tests/unit-wp/` (or similar, name TBD) bootstrap that sets up Brain Monkey instead of the
  existing zero-stub `tests/bootstrap.php` — keep the two bootstraps clearly separate so the
  "PHPUnit never gets real WordPress" rule in `docs/agents/testing.md` still holds (mocked WP,
  never real WP, for this tier).
- One real test proving the tier: orchestrator-level logic that today has no unit coverage
  (candidate: `Ahentic_AI::complete_chat()`'s dispatch between Core/SDK/error paths, with
  `wp_ai_client_prompt`/`get_option`/etc. mocked).

### 4. Browser-driven sidebar tier

- `AhenticSidebar` Playwright fixture wrapping the sidebar: `open()`, `sendMessage()`,
  `waitForApprovalCard()`, `clickAllow()` / `clickDeny()`.
- Auth via `@wordpress/e2e-test-utils-playwright`'s `RequestUtils`/`Admin` helpers
  (cookie login + `storageState`) — already a devDependency, matches Cimo/Interactions.
- One real spec: open the sidebar, seed a mocked AI response that plans a non-preallowable
  write, send a chat message, assert the HITL card renders and Allow/Deny are clickable.

### 5. CI + docs

- `.github/workflows/tests.yml`: `e2e` job drops the Docker/`wp-env` start step, adds
  `@wp-playground/cli` instead (Node-only, no extra service container needed); add
  `npx playwright install --with-deps chromium` once the browser-driven spec exists.
- Rewrite `docs/agents/testing.md` and `tests/e2e/README.md` to describe the new runtime, the
  two PHPUnit tiers, the two e2e tiers, and the mu-plugin's expanded surface.
- Update `src/abilities/CONTRACT.md` / `server-abilities.md` testing sections if the ability
  testing recipe changes (mocked-AI-through-browser is now a documented option, not just
  REST-direct).

## Out of scope

- Migrating the existing REST-direct proof spec's *assertions* (still valid) — only its runtime
  plumbing changes.
- Full browser-driven coverage of every `tasks/mvp-abilities` ability — only one proof spec now;
  per-track browser coverage (if ever needed) is decided when that track is built.
- Non-Chromium browser targets.
- Any actual `tasks/mvp-abilities` implementation — this task only rebuilds the harness those
  tasks will run against.

## Acceptance criteria

- [x] `npm run test:e2e` starts WordPress via `@wp-playground/cli` (no Docker daemon required)
      and runs the full Playwright suite, locally and in CI
- [x] `npm run test:debug` opens Playwright UI mode against the same runtime
- [x] A spec can seed a canned AI response and observe the orchestrator act on it without any
      real provider call
- [x] A spec can seed post/user/option fixtures via the mu-plugin before asserting behavior
- [x] The existing `ahentic/get-site-snapshot` REST-direct spec still passes unmodified in
      assertions (only its runtime changed)
- [x] One real browser-driven spec exercises the sidebar end-to-end with a mocked AI response
      (`sidebar-chat.spec.js`: open → send message → mocked assistant reply renders). **Reduced
      scope from the original HITL approve/deny card proof** — that needs a mocked response that
      plans a non-preallowable write plus `AhenticSidebar` helpers for the approval card, which
      didn't exist yet; tracked as a follow-up once `tasks/mvp-abilities` HITL work
      (`01-hitl-non-preallowable-and-undo.md`) lands rather than blocking this pass on it.
- [x] `composer test` runs both PHPUnit tiers (pure-PHP + Brain Monkey-mocked) with zero Docker
      and zero real WordPress boot
- [x] `.wp-env.tests.json` and the Docker branch of `scripts/test-e2e.js` no longer exist;
      `.wp-env.json` is untouched
- [x] `docs/agents/testing.md` and `tests/e2e/README.md` describe the current (not previous)
      architecture
- [x] `.github/workflows/tests.yml` updated for the Playground CLI runtime (verify green once
      pushed — not yet confirmed in actual GitHub Actions)
