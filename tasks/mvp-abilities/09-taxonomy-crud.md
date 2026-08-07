# Task 09 — Taxonomy CRUD + post term assignment

**Track:** G — Taxonomy / content coupling. No dependency on Track C. **Do this before Task 10** — highest user-visible failure mode today (`update-term` when the term does not exist yet).
**Source:** Grilling 2026-08-07 (parity bar + create-vs-update debugger miss). `update-term` already ships in [`class-abilities-taxonomy.php`](../../src/abilities/class-abilities-taxonomy.php) but is absent from `abilities-catalog.md` — document when implementing.

## Current state

Taxonomy module is **update-only**:

| Ability | Status |
| --- | --- |
| `ahentic/update-term` | Exists — `wp_update_term` + safe term meta; requires an existing term |
| `list-terms` / `get-term` / `create-term` / `delete-term` | Missing |
| Assign terms on posts | Missing — `create-post` / `update-post` have no `categories` / `tags` / `tax_input` |
| Terms on read | Missing — `get-content` does not return assigned terms |

Prompt assembler steers only `update-term` for “change an existing category/tag,” so agents invent create or call update and get `ahentic_term_not_found`.

## What's missing

Full term CRUD, post write/read coupling for taxonomies, prompt + catalog updates, and an editor-open path so assignment works on the open document.

## Scope

### Term abilities (`class-abilities-taxonomy.php`)

Deepen the existing module catalog — do not add a parallel registry.

| Ability | Role | HITL |
| --- | --- | --- |
| `ahentic/list-terms` | Readonly. Filter by `taxonomy` (required), optional search / parent / hide_empty / number. Return id, name, slug, description, parent, count. | — |
| `ahentic/get-term` | Readonly. `taxonomy` + `term_id` or `term` (id/slug/name). Include safe term meta when requested (same caps as update). | — |
| `ahentic/create-term` | `wp_insert_term`. Input: `taxonomy`, `name`, optional slug / description / parent / meta. | HITL, **preallowable** (same tier as `update-term`) |
| `ahentic/update-term` | Already shipped — keep behavior; ensure catalog/prompt list create/list/get alongside it. | HITL, preallowable |
| `ahentic/delete-term` | `wp_delete_term`. **Refuse if `count > 0`** with a clear error naming the post count and steering to reassign/remove terms on posts first (no silent WP default unassign). | HITL, **non-preallowable** (`allow_session` / `always_allow` rejected) |

Capabilities: use taxonomy cap objects (`edit_terms`, `delete_terms`, etc.) — never hardcode `manage_categories` only.

### Post assignment — extend content writes (replace-per-taxonomy)

On `ahentic/create-post` and `ahentic/update-post`:

- Accept `categories`, `tags`, and/or `tax_input` (taxonomy slug → list of term ids and/or slugs/names resolved like `update-term`).
- **Semantics (Q12):** if a taxonomy key is **present**, it becomes the **full** set for that taxonomy (`wp_set_object_terms( …, $append = false )`). **Omit** the key → leave that taxonomy unchanged. Prompt must say this explicitly.
- Resolve names/slugs to ids; missing terms → error that steers to `create-term` (do not auto-create unless we later add an explicit flag — out of scope).
- Only taxonomies registered for the post type; respect assign caps.

### Read path

- `ahentic/get-content` returns assigned terms grouped by taxonomy (id, name, slug at minimum).
- Leave `list-content` lean — no full term lists on every row.

### Editor open (ADR-0004 special case)

- While the block editor is open for this post: allow **server** `update-post` when the only mutating fields are taxonomy assignment (and existing exceptions if any — do not reopen body/title/excerpt/slug). Refuse body edits as today.
- **Follow-up in this same task (same file, can land in a second PR):** `ahentic-browser/set-post-terms` so the document panel stays in sync without reload. Ship server special-case first if splitting PRs; do not close the task until the browser twin is done or explicitly waived in acceptance.

### Prompts / catalog / checklist

- Teach create vs update vs list; never tell the model to “create” via `update-term`.
- Add taxonomy abilities to `abilities-catalog.md` (they are shipped/partially shipped but undocumented there).
- Run [`docs/agents/ability-checklist.md`](../../docs/agents/ability-checklist.md); keep catalog + phantom tests green.

## Out of scope

- Auto-creating terms from unknown names on post write.
- Bulk term delete / merge.
- Changing taxonomy registration itself (register_taxonomy).
- Block navigation / pattern taxonomies as a special case (use generic term APIs).

## Acceptance criteria

- [ ] `list-terms`, `get-term`, `create-term`, `delete-term` registered; `update-term` unchanged in spirit
- [ ] `delete-term` refuses when term `count > 0`; non-preallowable HITL
- [ ] `create-term` / `update-term` HITL preallowable
- [ ] `create-post` / `update-post` accept categories/tags/tax_input with replace-per-taxonomy semantics; omit = unchanged
- [ ] Missing term on assign returns a steer to `create-term`
- [ ] `get-content` includes assigned terms; `list-content` stays without full term payloads
- [ ] Editor-open: taxonomy-only `update-post` allowed; body still refused
- [ ] Browser `set-post-terms` shipped or explicitly waived with a follow-up issue noted in the PR
- [ ] Prompts no longer imply update-only; catalog documents the taxonomy set
- [ ] Ability checklist + module catalog / phantom tests green
