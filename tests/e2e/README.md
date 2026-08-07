# Ahentic e2e suite

Playwright specs that exercise real WordPress behaviour — abilities, HITL,
REST, and (for a small tier) the sidebar UI itself — against a local
[WordPress Playground](https://wordpress.github.io/wordpress-playground/) instance
via [`@wp-playground/cli`](https://www.npmjs.com/package/@wp-playground/cli)
(WASM PHP + SQLite, **no Docker**). See
[`docs/agents/testing.md`](../../docs/agents/testing.md) for the policy this
suite implements (PHPUnit vs. Playwright boundary, spec grouping).

None of this ships with the plugin — `tests/` is never copied into the build
(see `scripts/package.js`), and the mu-plugin harness only ever loads inside
the Playground instance `playwright.config.js` spins up for this suite.

## Prerequisites

Just Node — no Docker, no Composer, no separate WordPress install. The
`webServer` block in `playwright.config.js` boots WordPress for you.

## Running

```bash
npm run test:e2e     # headless, once — playwright test
npm run test:debug   # Playwright UI mode — a real Chromium window + step timeline
```

Locally, `playwright.config.js`'s `webServer` reuses an already-running
instance on port `9400` if one exists (fast repeat runs); in CI it always
boots fresh. If a stale instance is misbehaving (e.g. after editing PHP that
the plugin/mu-plugin mount, since Playground snapshots the mount at boot —
see Troubleshooting), kill whatever's listening on `9400` and re-run.

Run a single spec or filter by title the normal Playwright way:

```bash
npx playwright test tests/e2e/specs/content-and-plugins.spec.js
npx playwright test -g "get-site-snapshot"
```

## Two tiers

| Tier | Fixture | When |
| --- | --- | --- |
| **REST-direct** | `request` (`@wordpress/e2e-test-utils-playwright`'s `requestUtils`) | Default. "Is this ability correct" — no browser, no LLM, fast. |
| **Browser-driven** | `ahenticSidebar` (`tests/e2e/fixtures/test.js`) | Sidebar UX that can only be observed in a live page — chat rendering, HITL cards, composer state. Keep this tier small; it's the slower, flakier one. |

## How specs authenticate

`tests/e2e/global-setup.js` uses `@wordpress/e2e-test-utils-playwright`'s
`RequestUtils.setup()` to log in as the blueprint's `admin` user (see
`tests/e2e/playground-blueprint.json`'s `login` step) and persists cookies to
`tests/e2e/.auth/admin.json` (gitignored, `storageState` in
`playwright.config.js`) — both REST-direct and browser-driven specs reuse it,
no per-spec login flow needed.

## How specs call abilities (REST-direct tier)

Ahentic abilities are deliberately kept off the public Abilities REST run
route (`meta.show_in_rest => false`, see
[`src/abilities/abilities.md`](../../src/abilities/abilities.md)) — agent
tools aren't meant to be a public HTTP surface. Instead,
`tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php` is mounted into the
Playground instance's `mu-plugins` directory (see `playwright.config.js`'s
`webServer.command`) and exposes test-only routes under `ahentic-e2e/v1`.
`POST /run-ability` delegates straight to `Ahentic_Abilities::execute( $name,
$input )` — the ability **dispatch** seam the Tool runner calls after HITL /
browser / `from_memory`. It does **not** run the Tool runner pipeline (use
`orchestrator-pipeline.spec.js` + `session-client.js` for that). The route adds
no dispatch of its own, so ability-body tests can't drift from production
`execute_*` behaviour.

```js
const { runAbility } = require( '../utils/ability-client' )

const result = await runAbility( requestUtils, 'ahentic/list-content', { post_type: 'post' } )
// result -> { ok: true, data: { ... } } or { ok: false, error, message }
```

## How the browser-driven tier mocks the LLM

Real chat turns need a real LLM response — driving one for every spec would
be slow, costly, and non-deterministic. Instead, `Ahentic_AI::complete_chat()`
(`src/orchestrator/class-ai.php`) has a `pre_ahentic_ai_complete_chat` filter
that, in production, nothing hooks (a no-op). The e2e mu-plugin hooks it and
pops from a REST-seeded queue instead of calling a real provider — the
orchestrator + Tool runner pipeline (HITL / browser / execute / assess) still
runs end-to-end; only the model call is faked. `src/admin/class-rest.php`'s
`build_status_payload()`
has an equivalent `pre_ahentic_ai_status` filter so the sidebar composer isn't
disabled for lack of a real AI plugin/connector. Specs that need a
localize-time false negative use `seedAiStatusFlake( requestUtils, n )`
(`POST /ahentic-e2e/v1/seed-ai-status-flake`) — see
`connector-status-recovery.spec.js` and `ai-plugin-status.spec.js`.

```js
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )

test( 'sends a message and renders the mocked reply', async ( { ahenticSidebar } ) => {
	await ahenticSidebar.resetAiResponses()
	await ahenticSidebar.seedAiResponses( [ mockReply( 'Hello from the mocked assistant.' ) ] )
	await ahenticSidebar.openWithSession()

	await ahenticSidebar.sendMessage( 'Hi there, Ahentic.' )

	await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Hello from the mocked assistant.' )
} )
```

**Always seed via `mockReply()`, not a bare string.** A queued response is
returned by `complete_chat()` verbatim — the orchestrator's
`<<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>>` control-block contract
(`src/orchestrator/control-block.md`) still applies, and a bare string with no
control block is not a valid turn: `run_llm_with_debug()` treats it as
unusable, burns a retry against the (by then empty) queue, and silently falls
through to a real, unconfigured provider on the next attempt.
`ahentic_e2e_normalize_ai_result()` (the mu-plugin) parses a control block out
of seeded `text` automatically via `Ahentic_AI::extract_debug_block()`, so
`mockReply()` — which builds `next: "reply"` by default — is normally all a
spec needs; pass `debugOverrides` (e.g. `{ next: 'use_tools', tools_planned:
[...] }`) for tool-driving or HITL scenarios.

Fixture data (posts/users/options) can be seeded declaratively the same way:

```js
const { seed } = require( '../utils/ability-client' )

await seed( requestUtils, { posts: [ { post_title: 'Fixture post', post_status: 'publish' } ] } )
```

## Spec grouping

One spec file per `tasks/mvp-abilities` track / subsystem, not one per
ability — see `docs/agents/testing.md` for the current list and rationale.

## Files

| Path | Role |
| --- | --- |
| `../../playwright.config.js` | Boots `@wp-playground/cli` as Playwright's `webServer`; auth/`baseURL`/storage state wiring |
| `playground-blueprint.json` | WordPress Playground blueprint: logs in as `admin`, activates the plugin |
| `mu-plugins/ahentic-e2e-ability-runner.php` | Test-only REST routes: `run-ability`, `seed-ai-responses`, `seed`, `reset`, `health` |
| `global-setup.js` | Cookie-authenticates via `RequestUtils.setup()`, persists `storageState` |
| `utils/ability-client.js` | `runAbility()` / `seedAiResponses()` / `seed()` / `resetAiResponses()` helpers |
| `utils/session-client.js` | REST-direct orchestrator helpers: `startRun` / `waitForSession` / approvals / browser-results |
| `fixtures/ahentic-sidebar.js` | `AhenticSidebar` fixture (open a session, send a message, HITL decisions) + `mockReply()` |
| `fixtures/test.js` | Extends `@wordpress/e2e-test-utils-playwright`'s `test` with the `ahenticSidebar` fixture |
| `specs/orchestrator-pipeline.spec.js` | Tool pipeline characterization (HITL / browser / Ask) for architecture refactors |
| `specs/hitl-and-undo.spec.js` | Browser HITL card + Task 01 surface |
| `specs/*.spec.js` | Module-grouped specs |
| `.auth/` | Gitignored; written by global setup |

## Troubleshooting

- **A spec sees stale plugin/mu-plugin behaviour after you edited PHP** —
  Playground mounts the plugin directory when it boots; a lingering server
  from a previous run (or a manual `@wp-playground/cli server` you started
  yourself) keeps serving whatever was on disk at that boot. Find and stop
  it (`lsof -i :9400`) and re-run.
- **`browserType.launch: Executable doesn't exist`** — run
  `npx playwright install chromium` once per machine.
- **A browser-driven spec times out with the composer stuck disabled** —
  confirm `pre_ahentic_ai_status` is still hooked in
  `tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php` and that the mu-plugin
  actually mounted (hit `ahentic-e2e/v1/health` and check `abilities_loaded`).
- **A browser-driven spec renders a real "No AI provider configured" error
  instead of the mocked reply** — the seeded response almost certainly wasn't
  a valid control block; use `mockReply()` (see above), not a bare string.
