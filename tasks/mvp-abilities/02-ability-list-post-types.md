# Task 02 — `ahentic/list-post-types`

**Track:** B (independent, no infra dependency)
**Source:** [abilities-catalog.md](../../pro__premium_only/docs/abilities-catalog.md) — `v1-must`, used by site-tour and pre-launch-gap jobs ("Site structure / CPT inventory")

## Current state

Not implemented. No `list-post-types` or `list_post_types` reference anywhere in `src/`. Wow-prompt flows that depend on it (site tour, pre-launch gaps) currently have no CPT inventory ability to call.

## What's missing

A readonly ability that returns the registered post types relevant to an agent doing a site tour or content audit — name, label, public/show_in_rest, and a cheap count.

## Scope

- New constant + registration, most naturally in `class-abilities-content.php` (content-adjacent) or a new small module — follow the existing pattern in [`server-abilities.md`](../../src/abilities/server-abilities.md).
- Use `get_post_types( array( 'show_in_rest' => true ) )` merged with a labeled pass over `get_post_type_object()`, excluding obviously internal types already excluded elsewhere in the content module's `blocked_post_types()` (`class-abilities-content.php:991-1006`) — reuse that list rather than re-deriving it, so the two stay in sync.
- Per type, return: `name`, `label`, `public`, `hierarchical`, `show_in_rest`, `count` (published + draft, via `wp_count_posts( $name )`).
- Cap output — most sites have under 20 post types, but guard anyway (e.g. skip internal types with zero non-trivial statuses).

## Out of scope

- Taxonomy inventory (separate ability already exists: `ahentic/update-term` is a write; a `list-taxonomies` readonly companion is not in this task).
- Per-post listing — that's `list-content`.

## Acceptance criteria

- [ ] Returns public, agent-relevant post types with counts; excludes the same internal types `class-abilities-content.php::blocked_post_types()` excludes
- [ ] Readonly (visible in Ask mode)
- [ ] Registered in `Ahentic_Abilities::available_for_agent()` and `execute()` dispatch
