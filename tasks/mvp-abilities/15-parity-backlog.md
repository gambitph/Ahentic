# Task 15 — Classic WP admin parity backlog (deferred)

**Track:** Meta — not a build ticket. Records surfaces we agreed are **in scope for parity tracking** but **not** in the current build wave (grilling Q1 = B, Q4 deferrals).
**Source:** Grilling 2026-08-07.

Delete or rewrite this file when a surface is promoted to its own numbered task (or explicitly marked never).

## Bar

Every usual wp-admin area should eventually have list + create + update + delete (or an explicit “refuse / soft-only” policy). Shipping now is Tasks **09–14** plus existing done tracks — not the rows below.

## Deferred surfaces

| Surface | Implied abilities (sketch) | Why deferred now |
| --- | --- | --- |
| **Block navigation** (`wp_navigation`) | list/get/update navigation blocks or Site Editor browser path | Classic menus covered by Task 13; block nav needs Site Editor identity work overlapping Task 10 |
| **Widgets / block widget areas** | list sidebars, get/update widget instances or widget-area blocks | Theme-dependent; block themes push layout into template parts (Task 10) |
| **Comments** | `list-comments`, approve/spam/trash, reply | Catalog already **v2-free** / premium moderate — keep that split unless product revisits |
| **Themes install / activate / delete** | `install-theme`, `activate-theme`, optional delete | `list-themes` exists; install/activate is high-blast-radius and was not in site-settings v1-must |
| **Posts hard-delete / bulk trash** | `delete-posts` / bulk trash beyond `set-post-status` → trash | Soft trash is enough for MVP; `delete-posts` remains a prompt phantom for `missing_ability` pedagogy |
| **Patterns** (`wp_block`) | list/create/update/delete synced patterns | Lower frequency than posts/terms/menus; no current debugger miss |
| **Full page templates** (`wp_template`) | list/update beyond template **parts** | Explicitly out of scope for Task 10; promote only after parts + Site Editor browser path prove out |

## Intentionally not “full CRUD”

These are policy choices, not forgotten rows:

- **Options:** denylist remains (`siteurl`, `home`, …) even after Task 11.
- **Users:** no self-edit, no role escalation, delete requires `reassign_to` (Task 12).
- **Media delete:** quarantine/trash, not permanent purge.
- **Terms delete:** refuse while `count > 0` (Task 09).
- **Code-bearing** Customizer / raw theme.json CSS: refuse → premium snippets path.

## When promoting a row

1. New task file next to this index (copy Task 09/13 shape).
2. Update [`00-INDEX.md`](./00-INDEX.md) suggested order.
3. Update `abilities-catalog.md` / PRD tier labels so agents don’t treat the sketch names above as shipped.
4. Remove the row from this table (or strike through with task link).
