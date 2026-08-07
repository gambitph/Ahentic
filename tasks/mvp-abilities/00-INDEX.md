# MVP ability gaps — task index

**Temporary working folder.** Not a PRD, not a contract, not canonical. Delete this whole `tasks/mvp-abilities/` folder when the deferred backlog is empty (or promoted into real docs) and nothing here remains useful.

## Done (task files removed)

Ship wave complete for classic admin parity **09–14** plus earlier tracks:

- Track B (inventory / replace / revisions / images / upload-media)
- Task 16 (`ahentic-browser/set-featured-image`)
- Track A / 01 — non-preallowable HITL + settings snapshots + `undo-last-actions`
- Track F — `delete-blocks`, relative `move-blocks`, `update-post-document`, leave-canvas/wrap playbooks
- Track C / 07–11 — settings context, Customizer write, global styles, template part, `update-option`
- Track E — media mutate + **14** `list-media` / `get-media`
- Track G / 09 — taxonomy CRUD + post term assignment
- Track D / 12 — users (role ceiling, `reassign_to`)
- Track H / 13 — classic menus (`list-menus` / `list-menu-items` / `get-menu` / `update-menu`)

Sidebar multi-window viewer overlay (v1): [`src/admin/js/sidebar/sidebar.md`](../../src/admin/js/sidebar/sidebar.md). Take-over deferred: [`../future/multi-window-take-over.md`](../future/multi-window-take-over.md).

## Open (deferred only)

| # | Task |
| --- | --- |
| [15](./15-parity-backlog.md) | Block nav, widgets, comments, theme install/activate, hard-delete posts, patterns, full `wp_template` |

Promote a row from 15 into a new numbered task only when product pulls it forward.

## Sources

- [`pro__premium_only/docs/abilities-catalog.md`](../../pro__premium_only/docs/abilities-catalog.md)
- [`pro__premium_only/docs/prd/site-settings.md`](../../pro__premium_only/docs/prd/site-settings.md)
- [`docs/adr/0007-settings-writes-require-snapshot-undo.md`](../../docs/adr/0007-settings-writes-require-snapshot-undo.md)
- Grilling 2026-08-07: classic admin **parity bar**
