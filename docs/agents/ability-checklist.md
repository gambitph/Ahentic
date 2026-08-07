# Adding or changing an ability

Use when registering, renaming, or removing an Ahentic-registered ability (`ahentic/…` or `ahentic-browser/…`), or when naming one in prompts, playbooks, or UI copy.

## Checklist

- [ ] One `catalog()` entry (or module equivalent): `write` / `hitl` / `progress` / `summary` (and `non_preallowable` when needed)
- [ ] Lists derived from that catalog — no hand-synced sibling arrays
- [ ] `register()` name, schemas, and annotations match the catalog entry
- [ ] Prompt / playbook / error copy names the ability only if it ships and is executable on that path
- [ ] Module `*AbilityCatalogTest` (or equivalent) stays green; extend if you add policy fields
- [ ] Phantom-name PHPUnit stays green when prompts or playbooks changed
- [ ] Playwright module spec under `tests/e2e/specs/` updated (see abilities CONTRACT Testing)
- [ ] Free tree stays free of premium-only logic (`pro__premium_only/` boundary)

## Done

Every box that applies is checked; named PHPUnit filters for the touched module and `PhantomAbilityNameTest` (if prompts changed) are green.
