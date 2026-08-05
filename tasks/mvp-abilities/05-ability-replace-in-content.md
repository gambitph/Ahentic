# Task 05 — `ahentic/replace-in-content`

**Track:** B (independent, no infra dependency)
**Source:** [abilities-catalog.md](../../pro__premium_only/docs/abilities-catalog.md) — `v1-must`; backs wow prompt #4 (HTTP→HTTPS site-wide find/replace) and the "Dry-run + undo" discipline the catalog already documents

## Current state

`ahentic/search-content` exists and does the hard part — multi-post-type, multi-status search across title/content/meta with snippets:

```624:649:/Users/benjaminintal/Workspace/Repos/Ahentic/src/abilities/class-abilities-content.php
		public static function execute_search_content( $input = array() ) {
			// ...
			$wpq = new WP_Query(
				array(
					's'                      => $query,
					'post_type'              => $post_types,
					'post_status'            => $statuses,
					'posts_per_page'         => $limit,
					'orderby'                => 'relevance',
```

There is no write counterpart. The catalog's own dry-run discipline ("Preview (counts, samples, post links) → HITL confirm → Execute; report skips/failures → Offer undo") has nothing to execute.

## What's missing

A HITL-gated, dry-run-first, site-wide (or scoped) find-and-replace across post content/title, with per-post revision safety (posts already have WordPress revisions — this doesn't need the Track A snapshot store).

## Scope

- Input: `find`, `replace`, optional `post_type`/`status` scope (reuse `normalize_post_types()` / `normalize_statuses()` from the content module), `dry_run` (default `true` — the model must call it once as preview before a real run), `limit`.
- `dry_run: true` returns: match count, list of `{ id, title, edit_url, occurrences, before_snippet, after_snippet }` — same snippet-building helpers `search-content` already has — **without writing anything**.
- `dry_run: false` (only reachable after HITL approval, per the standard write path) performs the replacement via `wp_update_post()` per matched post, same guardrails as `execute_update_post()` (respects `blocked_post_types()`, checks `edit_post` capability per post), and reports `{ updated: [...], skipped: [...], failed: [...] }`.
- Case-sensitivity and whole-word options are reasonable inputs but keep the default simple (case-sensitive literal substring) — don't build a regex engine for v1.
- Relies on WordPress's own post revisions for undo (`ahentic/restore-revision`, Task 06) — do **not** wire this into the Track A settings-snapshot store; that store is for surfaces with no native revisions.

## Out of scope

- Options-table replace (`ahentic/replace-in-options` is a separate, `v1-should` catalog entry, not this task).
- Regex / whole-word / case-insensitive modes beyond a simple flag, if any.

## Acceptance criteria

- [ ] `dry_run: true` never mutates a post
- [ ] Real run only proceeds through the standard HITL path (this ability is in `hitl_names()`)
- [ ] Respects `blocked_post_types()` and per-post `edit_post` capability
- [ ] Reports per-post updated/skipped/failed, not just a total count
- [ ] Reuses `search-content`'s query/snippet helpers rather than re-implementing them
