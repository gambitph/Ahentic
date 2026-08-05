# Ahentic e2e suite

Playwright specs that exercise real WordPress behaviour — abilities, HITL,
REST — against a local [`wp-env`](https://www.npmjs.com/package/@wordpress/env)
instance. See [`docs/agents/testing.md`](../../docs/agents/testing.md) for the
policy this suite implements (PHPUnit vs. Playwright boundary, spec grouping).

None of this ships with the plugin — `tests/` is never copied into the build
(see `scripts/package.js`), and the mu-plugin harness only ever loads inside
the wp-env container defined by `.wp-env.tests.json`.

## Prerequisite: Docker

`wp-env` needs a Docker **CLI and a running daemon** — Docker Desktop,
[OrbStack](https://orbstack.dev/), or [Colima](https://github.com/abiosoft/colima)
all work. Verify with:

```bash
docker version
```

If that fails with something like `command not found` or `spawn docker
ENOENT`, the `docker` binary isn't on your `PATH` — install one of the above
and open a fresh terminal. If `docker version` finds the CLI but hangs or
errors talking to the daemon, the daemon itself isn't running — start Docker
Desktop / OrbStack / Colima first.

## Running

```bash
npm run test:e2e
```

This starts `wp-env --config=.wp-env.tests.json` (first run pulls Docker
images and is slow) and then runs `playwright test`. `wp-env` is left running
afterwards so repeat runs are fast — stop it explicitly when you're done:

```bash
npx wp-env stop --config=.wp-env.tests.json
# or to fully wipe its database/volumes:
npx wp-env destroy --config=.wp-env.tests.json
```

**This is a separate environment from `.wp-env.json`.** `@wordpress/env`'s
older single-config "tests environment" feature (`testsEnvironment` /
auto-started `tests-wordpress` + `tests-cli` containers) is deprecated; we
use its recommended replacement instead — a dedicated `--config` file
(`.wp-env.tests.json`) with its own isolated containers, database, and port
(`8889` by default). Running the e2e suite never touches a `.wp-env.json`
instance you might have open for manual local dev on port `8888`.

Run a single spec or filter by title the normal Playwright way:

```bash
npx playwright test tests/e2e/specs/content-and-plugins.spec.js
npx playwright test -g "get-site-snapshot"
```

## How ability specs authenticate

`tests/e2e/global-setup.js` mints a fresh WordPress application password for
the `.wp-env.tests.json` instance's default `admin` user via
`wp-env run cli --config=.wp-env.tests.json wp user application-password
create`, and writes it to `tests/e2e/.auth/admin.json` (gitignored). Specs
use `tests/e2e/utils/ability-client.js` to attach it as a `Basic` auth
header — no browser-driven login flow needed for REST-only specs.

## How ability specs call abilities

Ahentic abilities are deliberately kept off the public Abilities REST run
route (`meta.show_in_rest => false`, see
[`src/abilities/abilities.md`](../../src/abilities/abilities.md)) — agent
tools aren't meant to be a public HTTP surface. Instead,
`tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php` is mounted into the
`.wp-env.tests.json` container's `mu-plugins` directory (via that file's
`mappings`) and exposes a single test-only route,
`POST /wp-json/ahentic-e2e/v1/run-ability`, that delegates straight to
`Ahentic_Abilities::execute( $name, $input )` — the exact seam the
orchestrator's step worker calls. It adds no dispatch, permission, or HITL
logic of its own, so it can't drift from production behaviour.

```js
const { runAbility } = require( '../utils/ability-client' )

const result = await runAbility( request, 'ahentic/list-content', { post_type: 'post' } )
// result -> { ok: true, data: { ... } } or { ok: false, error, message }
```

This lets specs assert real ability behaviour (including HITL-adjacent
permission checks) without needing a real or mocked LLM to drive a chat turn
through the sidebar.

A small, separate set of full browser-driven specs is reserved for sidebar
UX that can't be validated at this layer (e.g. the HITL approve/deny card
rendering and being clickable) — see `@wordpress/e2e-test-utils-playwright`
(already a devDependency) for admin login / editor helpers when that lands.
Keep that set small; it's the expensive, flakier tier.

## Spec grouping

One spec file per `tasks/mvp-abilities` track / subsystem, not one per
ability — see `docs/agents/testing.md` for the current list and rationale.

## Files

| Path | Role |
| --- | --- |
| `../../.wp-env.tests.json` | The isolated wp-env config this suite runs against (`--config`) |
| `mu-plugins/ahentic-e2e-ability-runner.php` | Test-only REST route → `Ahentic_Abilities::execute()` |
| `global-setup.js` | Mints the e2e admin application password |
| `utils/ability-client.js` | `runAbility()` / `basicAuthHeader()` helpers used by specs |
| `specs/*.spec.js` | Module-grouped specs |
| `.auth/` | Gitignored; written by global setup |

## Troubleshooting

- **`spawn docker ENOENT` / `docker: command not found`** — Docker CLI isn't
  on `PATH`. See "Prerequisite: Docker" above.
- **`wp-env failed to start` but Docker is running** — a previous run may
  have left a stale container/port bound. Try
  `npx wp-env destroy --config=.wp-env.tests.json` then `npm run test:e2e`
  again.
- **`No e2e admin credentials found`** — you ran a spec file directly instead
  of through Playwright (which runs `global-setup.js` first). Use
  `npm run test:e2e` or `npx playwright test`.
