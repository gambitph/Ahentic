# Testing

How `/tdd` and related engineering skills should exercise Ahentic. This is the
canonical policy — `tests/e2e/README.md` covers the e2e harness's mechanics.

## Stack — a hard boundary, not a preference

| Layer | Tool | Scope |
| --- | --- | --- |
| Pure PHP functions | **PHPUnit** (`tests/unit/`, `composer test`) | Zero WordPress dependency. No `get_option`, `update_post_meta`, `$wpdb`, `current_user_can`, `wp_insert_post`, etc. — input in, output out. |
| Everything WordPress-dependent | **Playwright** (`tests/e2e/`, `npm run test:e2e`) | Ability execution, HITL, REST, sidebar/editor integration — anything that needs a real WP runtime. |

**PHPUnit never gets WordPress integration or e2e tests.** `tests/bootstrap.php`
only stubs `__()` on purpose — if a test needs more than that, the code under
test is not a pure unit and belongs in the Playwright suite instead. Do not
grow the stub file to make WP-dependent code testable in PHPUnit.

**Playwright is the only integration/e2e tier.** There is no PHPUnit
integration suite and none should be added — a real `wp-env` WordPress via
Playwright is more truthful than a partially-stubbed WordPress via PHPUnit,
and having exactly one non-unit tier keeps the boundary unambiguous.

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
composer test      # PHPUnit — pure PHP, no WordPress, seconds
npm run test:e2e   # Playwright — starts wp-env (Docker), then runs specs
npm test           # both, in that order
```

`npm run test:e2e` requires a Docker CLI + running daemon (Docker Desktop,
OrbStack, Colima) and starts an isolated wp-env instance defined by
`.wp-env.tests.json` — a separate environment/port from the `.wp-env.json`
one you might use for manual local dev. It's left running afterwards for
fast repeat runs; stop it with `npx wp-env stop --config=.wp-env.tests.json`
when done. See `tests/e2e/README.md` (including its Troubleshooting section)
for how specs authenticate and call abilities without driving a real LLM.

Both tiers run in CI (`.github/workflows/tests.yml`, jobs `phpunit` and `e2e`)
on every push/PR to `master`/`main`/`develop`.

## How e2e specs call abilities

Ahentic abilities deliberately keep `meta.show_in_rest => false` (agent tools
aren't a public HTTP surface — see `src/abilities/abilities.md`). Rather than
flipping that for testability, or driving a real chat turn through the
sidebar (slow, costly, non-deterministic) for every module, e2e specs call a
**test-only** REST route that's mounted into the wp-env container as an
mu-plugin (`tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php`, never
shipped with the plugin) and delegates straight to
`Ahentic_Abilities::execute()` — the exact seam the orchestrator itself uses.
No parallel dispatch/permission/HITL logic is reimplemented; the route is a
thin pass-through, so it can't drift from production behaviour.

A small, separate set of full browser-driven Playwright specs (using
`@wordpress/e2e-test-utils-playwright`, already a devDependency) is reserved
for sidebar UX that can't be validated at the REST layer — e.g. the HITL
approve/deny card actually rendering and being clickable. Keep that tier
small; it's the expensive, flakier one.

## Spec grouping — large modules, not one spec per ability

Group Playwright specs by `tasks/mvp-abilities` track / subsystem, not one
file per ability — with ~15 new abilities landing, per-ability specs would be
too granular to maintain.

| Spec | Covers |
| --- | --- |
| `hitl-and-undo.spec.js` | Non-preallowable HITL, settings snapshot capture, `undo-last-actions` (mvp-abilities Task 01) |
| `content-and-plugins.spec.js` | Existing content/plugin/site abilities + `list-post-types`, `analyze-plugins`, `list-themes`, `replace-in-content`, revisions (Tasks 02–06) |
| `settings-abilities.spec.js` | Settings discovery + theme/global-styles/template-part/option writes and their undo paths (Tasks 07–11) |
| `users-abilities.spec.js` | User list/create/update/delete (Task 12) |
| `media-abilities.spec.js` | Media writes + describe/generate-image (Tasks 13–15) |

Add new `describe()` blocks to the relevant existing spec file for each new
ability rather than creating a new file per ability.

## Anti-patterns

Same as the `tdd` skill in general, plus two specific to this boundary:

- **A PHPUnit test that stubs its way around a WordPress function** to keep
  testing WP-dependent code in `tests/unit/` — that behaviour belongs in a
  Playwright module spec instead.
- **A brand-new Playwright spec file per ability** — extend the module spec
  for that ability's track instead.
