# MVP — Token usage limits

**Temporary working folder.** Not a PRD, not a contract, not canonical.

Site-wide daily token backstop + consecutive-hit runaway lock. Grilled decisions live in the task file.

## Open

_(none)_

## Done

- [`01-daily-and-runaway-limits.md`](./01-daily-and-runaway-limits.md) — settings + enforcement

## Sources

- Grill session (settled tree in task file)
- [`src/orchestrator/class-usage.php`](../../src/orchestrator/class-usage.php) — existing daily rollup (`ahentic_token_stats_daily`, UTC)
- [`src/admin/class-admin.php`](../../src/admin/class-admin.php) — Settings → Ahentic stub
- [`pro__premium_only/docs/prd/agent-runtime.md`](../../pro__premium_only/docs/prd/agent-runtime.md) — “Daily site tokens / settings UI later”
