# Task 13 — Classic menus: list / get / update

**Track:** H — Menus. No hard dependency on Track C; can follow Task 09 or run after Users. Prefer after taxonomy so nav label workflows that create pages+terms aren’t blocked first.
**Source:** [abilities-catalog.md](../../pro__premium_only/docs/abilities-catalog.md) (`list-menu-items` **v1-should**; `get-menu` / `update-menu` were **v2-free** — this task **promotes writes into the free MVP wave** per parity grilling). Block `wp_navigation` remains deferred — see [15-parity-backlog.md](./15-parity-backlog.md).

## Current state

No menu abilities. Content module **blocks** `nav_menu_item` as a post type. Snapshot/admin links may point at Appearance → Menus only.

## What's missing

Agent-shaped classic menu read + one structure write (not a swarm of item micro-ops).

## Scope

### `ahentic/list-menus` (readonly)

- List classic menus: term id, name, slug, count, assigned locations (if any).

### `ahentic/list-menu-items` (readonly)

- Input: `menu` (id/slug/name). Return ordered items: id, title, type (`post_type` / `taxonomy` / `custom`), object id, url, parent, classes — enough to rebuild a tree. Paginate or cap if huge; refuse unbounded dumps with a clear error.

### `ahentic/get-menu` (readonly)

- Full menu: metadata + nested item tree + `locations` currently assigned to this menu.

### `ahentic/update-menu` (write, HITL)

- Input: `menu` (id/slug/name, or create-by-name if missing — document the create-on-write rule in the ability description), `items` (ordered tree payload), optional `locations` (theme location slugs this menu should occupy).
- **Replace semantics for `items` when present:** the submitted tree becomes the full menu; omit `items` → leave items unchanged. Same idea for `locations` when that key is present (assign this menu to those locations; clear from locations not listed — spell out in schema description).
- Implementation may use `wp_get_nav_menu_items` / `wp_update_nav_menu_item` / `wp_delete_post` on `nav_menu_item` internally — still **not** exposed as content CPT CRUD.
- Capability: `edit_theme_options` (or equivalent menu caps).
- HITL summary must name the menu and summarize change size (e.g. item count before → after, locations).

## Out of scope

- Separate `create-menu` / `delete-menu` abilities (empty/create via `update-menu`; deleting a menu term deferred).
- Per-item micro abilities (`add-menu-item`, `move-menu-item`).
- Block theme `wp_navigation` CPT editing (parity backlog).
- Mega-menu / walker plugin specifics.

## Acceptance criteria

- [ ] `list-menus`, `list-menu-items`, `get-menu`, `update-menu` registered and catalogued
- [ ] `update-menu` can replace the item tree and assigned locations in one HITL-approved call
- [ ] Agents never need `create-post` on `nav_menu_item`
- [ ] Prompts mention menus only after these abilities ship
- [ ] Ability checklist + tests green
