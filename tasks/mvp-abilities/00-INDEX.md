# MVP ability gaps + chrome

**Temporary working folder.** Not a PRD, not a contract, not canonical.

Ability ship wave (**09–14** and earlier tracks) is done; task files removed. Deferred wp-admin parity lives under **v2 future**:

→ [`../future/admin-parity-backlog.md`](../future/admin-parity-backlog.md)

## Open

| Task | Status |
| --- | --- |
| [`15-floating-viewport-recovery.md`](./15-floating-viewport-recovery.md) — re-home lost floating sidebar on open | Implemented |

## Done (task files removed)

- Track B (inventory / replace / revisions / images / upload-media)
- Task 16 (`ahentic-browser/set-featured-image`)
- Track A / 01 — non-preallowable HITL + settings snapshots + `undo-last-actions`
- Track F — `delete-blocks`, relative `move-blocks`, `update-post-document`, leave-canvas/wrap playbooks
- Track C / 07–11 — settings context, Customizer write, global styles, template part, `update-option`
- Track E — media mutate + **14** `list-media` / `get-media`
- Track G / 09 — taxonomy CRUD + post term assignment
- Track D / 12 — users (role ceiling, `reassign_to`)
- Track H / 13 — classic menus (`list-menus` / `list-menu-items` / `get-menu` / `update-menu`)

Sidebar multi-window viewer overlay (v1): [`src/admin/js/sidebar/sidebar.md`](../../src/admin/js/sidebar/sidebar.md). Take-over: [`../future/multi-window-take-over.md`](../future/multi-window-take-over.md) (v3).

## Sources

- [`pro__premium_only/docs/abilities-catalog.md`](../../pro__premium_only/docs/abilities-catalog.md)
- [`pro__premium_only/docs/prd/site-settings.md`](../../pro__premium_only/docs/prd/site-settings.md)
- [`docs/adr/0007-settings-writes-require-snapshot-undo.md`](../../docs/adr/0007-settings-writes-require-snapshot-undo.md)
