# Ahentic agent notes

## Product & architecture docs

| Kind | Where |
| --- | --- |
| Glossary | [`CONTEXT.md`](./CONTEXT.md) |
| Product PRDs (should) | [`pro__premium_only/docs/prd/README.md`](./pro__premium_only/docs/prd/README.md) |
| Subsystem contracts | `src/**/CONTRACT.md` |
| Architecture map | [`docs/architecture.md`](./docs/architecture.md) |
| ADRs | [`docs/adr/`](./docs/adr/) |

When code and docs disagree: **PRD/contract win**.
How-it-works files under `src/**` map current implementation.

## General guidelines

- Never use the em dash "—". Use plain dash "-" instead, but if applicable, just use a comma.
- When writing commit messages, NEVER auto-add your agent name as co-author.
- Never manually modify CHANGELOG.md files or any files that are marked as auto-generated.
- When writing or substantially editing long Markdown files, put each full sentence on its own line.
  Preserve normal Markdown structure, but avoid wrapping multiple sentences onto one physical line.
- When making technical decisions, do not give much weight to development cost.
  Instead, prefer quality, simplicity, robustness, scalability, and long term maintainability.
- When doing bug fixes, always start with reproducing the bug in an E2E setting as closely aligned with how an end user would experience it as possible.
  This makes sure you find the real problem so your fix will actually solve it.
- When end-to-end testing a product, be picky about the UI you see and be obsessed with pixel perfection.
  If something clearly looks off, even if it is not directly related to what you are doing, try to get it fixed along the way.
- Apply that same high standard to engineering excellence: lint, test failures, and test flakiness.
  If you see one, even if it is not caused by what you are working on right now, still get it fixed.

## Coding standards (agents)

Maintainability / anti-slop (deepen seams, derived catalogues, no phantom tools): [`.cursor/rules/ahentic-anti-slop.mdc`](./.cursor/rules/ahentic-anti-slop.mdc) - always-on Cursor rule.

Ability add/change preflight (catalog, prompts, tests): [`docs/agents/ability-checklist.md`](./docs/agents/ability-checklist.md) - when registering, renaming, removing, or naming an ability in prompts/playbooks.

## Agent skills

### Issue tracker

GitHub Issues on this repo (`gambitph/Ahentic`) via the `gh` CLI.
See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context: root `CONTEXT.md` + `docs/adr/`.
See `docs/agents/domain.md`.

### Concept walkthroughs

Orient on one concept / feature / subsystem (HTML + chat; optional durable copy under `docs/walkthroughs/`).
See `docs/agents/explain-concept.md` and the `explain-codebase-concept` skill.

### Testing

Playwright for UI / e2e (WordPress-aligned).
See `docs/agents/testing.md`.

### Quality gate

Local review (incl. anti-slop) → test → document → lint before commit / after substantive or AI-generated changes.
Skill: `.cursor/skills/ensure-quality/` (project-agnostic; loads this repo's anti-slop rules via discovery).

## Free / premium

Agent workflow files (skills, `AGENTS.md`, `docs/agents/`, `CONTEXT.md`, `docs/adr/`) live in the **free** repo root so they apply to main plugin work and premium work checked out under `pro__premium_only/`.
