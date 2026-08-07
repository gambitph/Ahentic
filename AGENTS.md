# Ahentic agent notes

## Product & architecture docs

| Kind | Where |
| --- | --- |
| Glossary | [`CONTEXT.md`](./CONTEXT.md) |
| Product PRDs (should) | [`pro__premium_only/docs/prd/README.md`](./pro__premium_only/docs/prd/README.md) |
| Subsystem contracts | `src/**/CONTRACT.md` |
| Architecture map | [`docs/architecture.md`](./docs/architecture.md) |
| ADRs | [`docs/adr/`](./docs/adr/) |

When code and docs disagree: **PRD/contract win**. How-it-works files under `src/**` map current implementation.

## Coding standards (agents)

Maintainability / anti-slop (deepen seams, derived catalogues, no phantom tools): [`.cursor/rules/ahentic-anti-slop.mdc`](./.cursor/rules/ahentic-anti-slop.mdc) — always-on Cursor rule.

## Agent skills

### Issue tracker

GitHub Issues on this repo (`gambitph/Ahentic`) via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context: root `CONTEXT.md` + `docs/adr/`. See `docs/agents/domain.md`.

### Testing

Playwright for UI / e2e (WordPress-aligned). See `docs/agents/testing.md`.

## Free / premium

Agent workflow files (skills, `AGENTS.md`, `docs/agents/`, `CONTEXT.md`, `docs/adr/`) live in the **free** repo root so they apply to main plugin work and premium work checked out under `pro__premium_only/`.
