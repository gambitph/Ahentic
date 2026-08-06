# Testing

How `/tdd` and related engineering skills should exercise Ahentic. This is the
canonical policy — `tests/e2e/README.md` covers the e2e harness's mechanics.

## Stack — four tiers, two tools

| Tier | Tool | Bootstrap | Scope |
| --- | --- | --- | --- |
| Pure PHP | **PHPUnit** (`tests/unit/`, `phpunit.xml.dist`) | `tests/bootstrap.php` (stubs `__()` only) | Zero WordPress dependency. No `get_option`, `update_post_meta`, `$wpdb`, `current_user_can`, `wp_insert_post`, etc. — input in, output out. |
| WordPress-mocked | **PHPUnit + Brain Monkey** (`tests/wp-mocked/`, `phpunit-wp-mocked.xml.dist`) | `tests/wp-mocked/bootstrap.php` | "Medium" units where the *decision logic* matters but a real WP boot would be overkill — e.g. orchestrator dispatch. WordPress functions (`apply_filters`, `get_option`, …) are mocked with Brain Monkey; no real WordPress ever boots. |
| REST-direct e2e | **Playwright** (`tests/e2e/specs/`, `request`/`requestUtils` fixture) | `@wp-playground/cli` via `playwright.config.js`'s `webServer` | Ability execution, HITL, REST against a real (if WASM) WordPress — no browser, no LLM. The default e2e tier. |
| Browser-driven e2e | **Playwright** (`ahenticSidebar` fixture, `tests/e2e/fixtures/`) | Same Playground instance + a real Chromium `page` | Sidebar UX that can only be observed live — chat rendering, HITL cards, composer state. Small and deliberately rare — the slower, flakier tier. |

Run all four with `npm test` (`composer test` runs both PHPUnit configs,
`npm run test:e2e` runs both Playwright tiers — see "Running tests" below).

**PHPUnit's pure-PHP tier never gets WordPress.** `tests/bootstrap.php` only
stubs `__()` on purpose — if a test needs more than that but doesn't need a
*real* WordPress either, it belongs in the wp-mocked tier (Brain Monkey), not
a growing stub file.

**The wp-mocked tier mocks WordPress functions, never boots WordPress.** If a
test needs real `$wpdb` behavior, real hook execution order across multiple
files, or anything else a mock can't faithfully stand in for, it belongs in
Playwright instead — that's what "real (if WASM) WordPress" is for.

**Playwright is the only tier with a real WordPress runtime**, provided by
`@wp-playground/cli` (WordPress Playground: WASM PHP + SQLite) — no Docker.
See `tests/e2e/README.md` for the two Playwright tiers (REST-direct vs.
browser-driven) and how AI responses get mocked for the browser-driven one.

### Splitting an ability's logic across the boundary

When implementing an ability (see `src/abilities/server-abilities.md`),
prefer separating **decision logic** from **WP I/O** at the function level:

- A function that takes plain arrays/strings and returns a computed result
  (heuristic flags, a diff/dry-run preview, a snapshot-entry shape, an
  input-schema check) is pure — cover it in PHPUnit.
- The thin wrapper that actually reads/writes WordPress state around that
  function is not pure — cover its real behaviour in a Playwright module spec
  instead.

This split is what makes most of an ability's logic unit-testable even though
the ability as a whole needs WordPress.

## Seams

Confirm seams with the user before writing tests (see the `tdd` skill). Good
default seams for Ahentic:

- `Ahentic_Abilities::execute( $name, $input )` — the dispatch boundary every
  ability goes through (readonly/HITL/mode checks + the actual mutation),
  reachable in e2e specs via the harness described below.
- `Ahentic_AI::complete_chat()` — the orchestrator's one LLM call site.
  Dispatch logic (Core vs. SDK vs. unavailable) is a wp-mocked PHPUnit seam;
  the `pre_ahentic_ai_complete_chat` filter it exposes is the e2e mocking seam.
- `Ahentic_Session_Repository` HITL/session state (preallow lists, snapshot
  store, once ADR-0007 lands).
- `Ahentic_Orchestrator::handle_approval()` and other REST/Abilities API
  responses the UI or agent depend on.
- Sidebar / workspace behaviour observable in the browser (small, deliberately
  rare tier — see below).

Avoid tests that reach into private React state, private PHP helpers, or mock
every collaborator so the assertion never could disagree with production
behaviour.

## Running tests

```bash
composer test       # both PHPUnit configs — pure PHP + Brain Monkey-mocked, seconds, no WordPress
npm run test:e2e    # both Playwright tiers — @wp-playground/cli boots WordPress, then runs specs
npm run test:debug  # same, but Playwright UI mode — a real Chromium window + step timeline
npm test            # composer test, then npm run test:e2e
```

`npm run test:e2e` needs **no Docker, no Composer, no separate WordPress
install** — `playwright.config.js`'s `webServer` boots
[`@wp-playground/cli`](https://www.npmjs.com/package/@wp-playground/cli)
(WordPress Playground: WASM PHP + SQLite) itself and tears it down after the
run (it reuses an already-running instance locally for fast repeat runs; CI
always boots fresh). See `tests/e2e/README.md` (including its Troubleshooting
section) for how specs authenticate, call abilities, and mock AI responses.

All tiers run in CI (`.github/workflows/tests.yml`, jobs `phpunit` — a matrix
across the PHP range in `readme.txt`/`phpcs.xml.dist`, running both PHPUnit
configs — and `e2e`) on every push/PR to `master`/`main`/`develop`.

## How e2e specs call abilities

Ahentic abilities deliberately keep `meta.show_in_rest => false` (agent tools
aren't a public HTTP surface — see `src/abilities/abilities.md`). Rather than
flipping that for testability, or driving a real chat turn through the
sidebar (slow, costly, non-deterministic) for every module, REST-direct e2e
specs call a **test-only** REST route mounted into the Playground instance as
an mu-plugin (`tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php`, never
shipped with the plugin) that delegates straight to
`Ahentic_Abilities::execute()` — the exact seam the orchestrator itself uses.
No parallel dispatch/permission/HITL logic is reimplemented; the route is a
thin pass-through, so it can't drift from production behaviour.

A small, separate set of full browser-driven Playwright specs (the
`ahenticSidebar` fixture, built on `@wordpress/e2e-test-utils-playwright`) is
reserved for sidebar UX that can't be validated at the REST layer — e.g. a
chat turn actually rendering, or the HITL approve/deny card being clickable.
Those specs mock the LLM via the same mu-plugin's AI-response queue (see
`tests/e2e/README.md`) rather than calling a real provider. Keep this tier
small; it's the expensive, flakier one.

## Spec grouping — large modules, not one spec per ability

Group Playwright specs by `tasks/mvp-abilities` track / subsystem, not one
file per ability — with ~15 new abilities landing, per-ability specs would be
too granular to maintain.

| Spec | Covers |
| --- | --- |
| `orchestrator-pipeline.spec.js` | Characterization of the tool pipeline (readonly tools, HITL allow/deny/allow_session, browser pause/resume, Ask-mode write block) — safety net for ToolRunner / HITL-policy architecture work |
| `hitl-and-undo.spec.js` | Browser HITL card wiring + (later) Non-preallowable HITL, settings snapshot, `undo-last-actions` (mvp-abilities Task 01) |
| `content-and-plugins.spec.js` | Existing content/plugin/site abilities + `list-post-types`, `analyze-plugins`, `list-themes`, `replace-in-content`, revisions (Tasks 02–06) |
| `settings-abilities.spec.js` | Settings discovery + theme/global-styles/template-part/option writes and their undo paths (Tasks 07–11) |
| `users-abilities.spec.js` | User list/create/update/delete (Task 12) |
| `media-abilities.spec.js` | Media writes + describe/generate-image (Tasks 13–15) |
| `sidebar-chat.spec.js` | Browser-driven smoke: composer → mocked assistant bubble |

Add new `describe()` blocks to the relevant existing spec file for each new
ability rather than creating a new file per ability.

## Anti-patterns

Same as the `tdd` skill in general, plus a few specific to this boundary:

- **A PHPUnit test that hand-rolls WordPress function stubs** in `tests/unit/`
  to keep testing WP-adjacent code there — use the wp-mocked tier (Brain
  Monkey) instead if mocking is genuinely sufficient, or a Playwright module
  spec if it isn't.
- **A brand-new Playwright spec file per ability** — extend the module spec
  for that ability's track instead.
- **Seeding a browser-driven spec's mocked AI response as a bare string** —
  it skips the orchestrator's `<<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>>` control
  block and silently falls through to a real, unconfigured provider on retry.
  Use `mockReply()` (`tests/e2e/fixtures/ahentic-sidebar.js`).
- **A browser-driven spec for something a REST-direct spec could already
  prove** — if the assertion doesn't depend on rendering, prefer the faster,
  more stable REST-direct tier.
