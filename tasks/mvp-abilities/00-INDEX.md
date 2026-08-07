# MVP ability gaps — task index

**Temporary working folder.** Not a PRD, not a contract, not canonical. Delete this whole `tasks/` folder once every task below is implemented and the real docs (PRDs, CONTRACT.md, catalog) are already updated to match — they already describe the target state, these files just track the build.

Each task file is self-contained: current state, what's missing, scope, out of scope, acceptance criteria, files likely touched. Work them in order — later tracks depend on earlier ones.

## Done (removed from this folder)

- Track B: `list-post-types`, `analyze-plugins`, `list-themes`, `replace-in-content`, `list-revisions` / `restore-revision`, `describe-image`, `generate-image` + `image` artifacts, and `upload-media` (incl. `from_memory`).
- Task 16: `ahentic-browser/set-featured-image` (editor-open featured media).
- **Track A / Task 01:** non-preallowable HITL + settings snapshot store + `ahentic/undo-last-actions` (see `src/session/class-settings-snapshots.php`, `Ahentic_Abilities::is_non_preallowable`, session meta `_ahentic_settings_snapshots`).

Sidebar multi-window viewer overlay (v1) also shipped — see [`src/admin/js/sidebar/sidebar.md`](../../src/admin/js/sidebar/sidebar.md). Take-over remains deferred: [`../future/multi-window-take-over.md`](../future/multi-window-take-over.md).

## Sources

- [`pro__premium_only/docs/abilities-catalog.md`](../../pro__premium_only/docs/abilities-catalog.md)
- [`pro__premium_only/docs/prd/site-settings.md`](../../pro__premium_only/docs/prd/site-settings.md)
- [`docs/adr/0007-settings-writes-require-snapshot-undo.md`](../../docs/adr/0007-settings-writes-require-snapshot-undo.md)

## Track C — Theme / settings surface — needs Track A (done)

| # | Task |
| --- | --- |
| [07](./07-settings-discovery.md) | `get-settings-context`, `list-settings`, `get-setting` + Customizer registry bootstrap/cache |
| [08](./08-ability-update-theme-setting.md) | `update-theme-setting` (classic Customizer) |
| [09](./09-ability-update-global-styles.md) | `update-global-styles` (block theme) |
| [10](./10-ability-update-template-part.md) | `update-template-part` (block theme, client/server routing) |
| [11](./11-ability-update-option.md) | `update-option` (interactive, registered + vetted keys, denylist) |

## Track D — Users — needs Track A (done)

| # | Task |
| --- | --- |
| [12](./12-users-abilities.md) | `list-users` (upgrade), `create-user`, `update-user`, `delete-user` |

## Track E — Media (remaining) — needs Track A for snapshotted writes

| # | Task |
| --- | --- |
| [13](./13-media-abilities.md) | Remaining: `update-media`, `set-featured-image` (server), `delete-media`, `replace-media-file` (`upload-media` + browser `set-featured-image` already shipped) |

## Track F — Block editor (open canvas) — no Track A dependency

| # | Task |
| --- | --- |
| [14](./14-block-editor-surgery-gaps.md) | Surgery gaps + editor doc fields (two workstreams): `delete-blocks`, relative `move-blocks`, leave-canvas/wrap playbooks, `update-post-document` (title/excerpt/slug) |

## Suggested order

1. Track C (07 → 08 → 09 → 10 → 11) — **next**
2. **Track F (14)** — can run in parallel with C–E (browser-only; unblocks remove / move / leave-canvas product fails)
3. Track D (12)
4. Track E remainder (13)
