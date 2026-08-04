# Testing

How `/tdd` and related engineering skills should exercise Ahentic.

## Stack

Prefer **Playwright** for automated UI / end-to-end tests — the same stack WordPress uses for e2e. Prefer Playwright over inventing a parallel Jest/PHPUnit-only suite for sidebar and editor flows unless the seam under test is clearly a pure unit with no browser surface.

## Seams

Confirm seams with the user before writing tests (see the `tdd` skill). Good default seams for Ahentic:

- Sidebar / workspace behaviour observable in the browser
- REST / Abilities API responses the UI or agent depend on
- WordPress editor integration points the sidebar drives

Avoid tests that reach into private React state, private PHP helpers, or mock every collaborator so the assertion never could disagree with production behaviour.

## Running tests

Use whatever Playwright / `@wordpress/e2e-test-utils-playwright` (or project-local) harness is present in the repo when that lands. Until a project test script exists, agree the command with the user before the first red→green cycle rather than inventing a new runner.
