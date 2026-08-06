# MVP ability gaps — task index

**Temporary working folder.** Not a PRD, not a contract, not canonical. Delete this whole `tasks/` folder once every task below is implemented and the real docs (PRDs, CONTRACT.md, catalog) are already updated to match — they already describe the target state, these files just track the build.

Each task file is self-contained: current state, what's missing, scope, out of scope, acceptance criteria, files likely touched. Work them in order — later tracks depend on earlier ones.

## Sources

- [`pro__premium_only/docs/abilities-catalog.md`](../../pro__premium_only/docs/abilities-catalog.md) — v1-must ability list, largely unimplemented beyond content/plugins/browser
- [`pro__premium_only/docs/prd/site-settings.md`](../../pro__premium_only/docs/prd/site-settings.md) — new theme/options/users/media surface
- [`docs/adr/0007-settings-writes-require-snapshot-undo.md`](../../docs/adr/0007-settings-writes-require-snapshot-undo.md) — why Track A exists

## Track A — Prerequisite infra (build first, blocks Tracks C–E)

| # | Task | Blocks |
| --- | --- | --- |
| [01](./01-hitl-non-preallowable-and-undo.md) | Non-preallowable HITL + settings snapshot store + `undo-last-actions` | C, D, E |

## Track B — Existing v1-must ability gaps (abilities-catalog.md, independent of site-settings)

None of these exist in code today — only referenced in docs. No dependency on Track A.

| # | Task |
| --- | --- |
| [02](./02-ability-list-post-types.md) | `ahentic/list-post-types` |
| [03](./03-ability-analyze-plugins.md) | `ahentic/analyze-plugins` |
| [04](./04-ability-list-themes.md) | `ahentic/list-themes` |
| [05](./05-ability-replace-in-content.md) | `ahentic/replace-in-content` |
| [06](./06-ability-revisions.md) | `ahentic/list-revisions` + `ahentic/restore-revision` |
| [14](./14-ability-describe-image.md) | `ahentic/describe-image` (vision; readonly) |
| [15](./15-ability-generate-image.md) | `ahentic/generate-image` + `image`-kind session artifact (part 3, `upload-media` `from_memory` wiring, must wait on Task 13) |

## Track C — Theme / settings surface (site-settings.md) — needs Track A

| # | Task |
| --- | --- |
| [07](./07-settings-discovery.md) | `get-settings-context`, `list-settings`, `get-setting` + Customizer registry bootstrap/cache |
| [08](./08-ability-update-theme-setting.md) | `update-theme-setting` (classic Customizer) |
| [09](./09-ability-update-global-styles.md) | `update-global-styles` (block theme) |
| [10](./10-ability-update-template-part.md) | `update-template-part` (block theme, client/server routing) |
| [11](./11-ability-update-option.md) | `update-option` (interactive, registered + vetted keys, denylist) |

## Track D — Users — needs Track A

| # | Task |
| --- | --- |
| [12](./12-users-abilities.md) | `list-users` (upgrade), `create-user`, `update-user`, `delete-user` |

## Track E — Media — needs Track A

| # | Task |
| --- | --- |
| [13](./13-media-abilities.md) | `update-media`, `set-featured-image` (server), `upload-media`, `delete-media` (quarantine), `replace-media-file` |
| [16](./16-ability-browser-set-featured-image.md) | `ahentic-browser/set-featured-image` (editor-open live panel; pairs with 13) |

## Suggested order

1. Track A (01) — proves the mechanism on paper before any real surface uses it
2. Track B (02–06, 14, 15 parts 1–2) — independent, can interleave with A/C at will, no new infra needed
3. Track C (07 → 08 → 09 → 10 → 11) — discovery before writes; template-part (10) last, it's the biggest build
4. Track D (12)
5. Track E (13), then 16 (browser featured-image; can land with or right after 13’s server twin), then 15 part 3 (`upload-media` `from_memory` wiring — needs 13's `upload-media` to exist first)
