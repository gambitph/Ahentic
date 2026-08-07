# Future — Classic WP admin parity backlog (v2)

**When:** **v2** (not MVP)
**Status:** Deferred product backlog — surfaces in scope for parity tracking but **not** a current build wave
**Source:** Grilling 2026-08-07 (Q4 deferrals); MVP ship wave **09–14** already landed

Delete or rewrite rows when a surface is promoted to its own build task (or explicitly marked never). Sibling future note: [`multi-window-take-over.md`](./multi-window-take-over.md).

## Bar

Every usual wp-admin area should eventually have list + create + update + delete (or an explicit “refuse / soft-only” policy). MVP covered taxonomy, classic menus, users, media list/get, settings writes, and earlier content/media/plugin tracks — not the rows below.

## Deferred surfaces (v2+)

| Surface | Implied abilities (sketch) | Why deferred from MVP |
| --- | --- | --- |
| **Block navigation** (`wp_navigation`) | list/get/update navigation blocks or Site Editor browser path | Classic menus shipped in MVP; block nav needs Site Editor identity work |
| **Widgets / block widget areas** | list sidebars, get/update widget instances or widget-area blocks | Theme-dependent; block themes push layout into template parts |
| **Comments** | `list-comments`, approve/spam/trash, reply | Catalog already **v2-free** / premium moderate — keep that split unless product revisits |
| **Themes install / activate / delete** | `install-theme`, `activate-theme`, optional delete | `list-themes` exists; install/activate is high-blast-radius and was not in site-settings v1-must |
| **Posts hard-delete / bulk trash** | `delete-posts` / bulk trash beyond `set-post-status` → trash | Soft trash is enough for MVP; `delete-posts` remains a prompt phantom for `missing_ability` pedagogy |
| **Patterns** (`wp_block`) | list/create/update/delete synced patterns | Lower frequency than posts/terms/menus; no current debugger miss |
| **Full page templates** (`wp_template`) | list/update beyond template **parts** | Template parts shipped in MVP; promote only after parts + Site Editor browser path prove out |

## Intentionally not “full CRUD”

These are policy choices, not forgotten rows:

- **Options:** denylist remains (`siteurl`, `home`, …).
- **Users:** no self-edit, no role escalation, delete requires `reassign_to`.
- **Media delete:** quarantine/trash, not permanent purge.
- **Terms delete:** refuse while term is in use.
- **Code-bearing** Customizer / raw theme.json CSS: refuse → premium snippets path.

## When promoting a row

1. New build task (issue or `tasks/` note) with scope / acceptance — grill first if blast radius is high.
2. Update `abilities-catalog.md` / PRD tier labels so agents don’t treat the sketch names above as shipped.
3. Remove the row from this table (or strike through with task/issue link).
