# Session-scoped artifacts (payload namespace)

Working files for the **current Ahentic session** (the open agent tab / `ahentic-session` CPT). Artifacts let the orchestrator and abilities stage large payloads once (drafts, block trees, HTML) and apply them later **by key**, without re-reading or re-emitting huge content from the chat transcript.

> **Canonical should:** [Working memory PRD](../../pro__premium_only/docs/prd/working-memory.md) — “artifacts” are the **payload** namespace; `editor.refs` is a sibling.  
> **Session contract:** [CONTRACT.md](./CONTRACT.md). This file is **how-it-works** for payload staging/apply; if it disagrees with the PRD, the PRD wins.

**Implementation:** `src/session/class-artifacts.php` (storage + stage/list/delete abilities). Orchestrator expands `from_memory` before execute / browser pause.

**Related:** [Session](./session.md) · [Orchestrator](../orchestrator/orchestrator.md) · [Abilities](../abilities/abilities.md) · [Server abilities](../abilities/server-abilities.md) · [Client abilities](../abilities/client-abilities.md)

---

## Problem

Today, long drafts only survive as assistant / tool text in `_ahentic_entries`. When the model later calls `ahentic-browser/set-blocks` or `ahentic/create-post`, it must rediscover that text in history and paste it again into `tools_planned`. That is expensive, lossy (truncation), and unreliable.

Artifacts fix the **draft → create** path: stage once, apply by reference.

---

## Non-goals

- **Not** site knowledge (`ahentic_site_knowledge`) — artifacts die with the session unless we deliberately promote something later.
- **Not** a full “working memory” dump in every LLM think — prompts only get **pointers** (key, title, size, status), never the full body every turn.
- **Not** a replacement for tool execution — staging does not mean the write already happened. Mutating abilities still run; they may load input from an artifact.
- **Not** permanent storage across sessions — new session ⇒ empty artifacts.

---

## Ownership & storage

| Concern | Location |
| --- | --- |
| Persistence | Session post meta, e.g. `_ahentic_artifacts` (JSON) |
| API | Session helpers (e.g. `Ahentic_Session_Artifacts` or methods on `Ahentic_Session_Repository`) |
| Who writes | Orchestrator + abilities (PHP and browser result merge) |
| Who reads | Orchestrator (prompt summary + `from_memory` expansion), abilities |

Keep artifacts **session-scoped**. Clear or leave them when the session is deleted with the CPT; do not write them to `localStorage`.

Suggested companion namespaces (same meta blob or adjacent keys) can hold small `vars` / `editor.ref_map` / `facts` later. This doc specifies **artifacts** only; other namespaces may share the store but must not bloat the every-think prompt with artifact bodies.

---

## Data shape

```json
{
  "version": 1,
  "updated_at": "2026-08-03T04:00:00Z",
  "items": {
    "article_draft": {
      "kind": "blocks",
      "title": "10 Ways to Brew Better Coffee",
      "status": "ready",
      "payload": {
        "blocks": [
          {
            "name": "core/heading",
            "attributes": { "level": 1, "content": "…" },
            "innerBlocks": []
          }
        ]
      },
      "meta": {
        "source": "model_stage",
        "step": 4,
        "bytes": 12400,
        "block_count": 12,
        "created_at": "…",
        "updated_at": "…"
      }
    }
  }
}
```

### Fields

| Field | Meaning |
| --- | --- |
| `items.{key}` | Stable id the model / tools use (`article_draft`, `section_2`, …). Prefer `snake_case`, max ~64 chars. |
| `kind` | `blocks` \| `html` \| `markdown` \| `post_content` \| `json` — how `payload` is interpreted at apply time. |
| `title` | Short label for UI / prompt pointer. |
| `status` | `ready` \| `applied` \| `stale` \| `empty`. |
| `payload` | The body. For `blocks`, prefer Gutenberg-shaped `{ name, attributes, innerBlocks }[]` (same shape `set-blocks` expects). |
| `meta.source` | Who wrote it (`model_stage`, ability name, `orchestrator`). |
| `meta.step` | Session step count when written (optional). |
| `meta.bytes` / `block_count` | For prompt pointers and size caps — **do not** require the model to count. |

### Status lifecycle

```text
(missing) → drafting → ready → applied
                 ↓         ↓
               stale     stale   (document/context changed, or superseded)
```

- **drafting** — chunks still landing (`mode=append`, `complete=false`); `from_memory` apply is **rejected**.
- **ready** — safe to apply via `from_memory` (`complete=true`, default).
- **applied** — successfully used by a mutate; keep briefly for audit or delete.
- **stale** — must not apply until restaged (e.g. user switched posts, or a newer draft replaced it).

---

## Core flow: draft then create

```text
1. Model produces structured content (blocks/HTML) intended for later write
2. Stage → artifacts.items.article_draft = { kind, payload, status: ready }
3. Later thinks see only a pointer in the prompt
4. Model plans set-blocks / create-post with input.from_memory = "article_draft"
5. Orchestrator expands from_memory → full tool input, then runs the ability
6. On success → status applied (or delete key)
```

### Staging (write)

Ways to stage (implement at least one; prefer ability-authored):

1. **Explicit stage ability** (e.g. `ahentic/stage-artifact`) — model passes `key`, `kind`, `payload`. Clearest contract.
2. **Side effect** — a “draft complete” control-block field or memory op merges into artifacts.
3. **Avoid** scraping free-form chat prose as the primary path — brittle.

On stage:

- Upsert `items[key]` (`mode: replace|append`, `complete` → drafting vs ready).
- **`append` only merges while status is `drafting`.** If the key is already `ready` / `applied` / `stale`, a new `append` is treated as **`replace`** so revisions do not concatenate duplicate sections.
- Empty `complete=true` finalize (no new payload) is allowed only while **drafting**.
- Refresh `meta` (bytes, block_count, timestamps).
- Append a short tool/event note to the session (“Staged artifact `article_draft` (12 blocks)”) — not the full payload in chat.
- Enforce size caps (reject or truncate with error; do not silently corrupt).
- Orchestrator may **auto-stage** oversized inline tool bodies and rewrite to `from_memory`.

### Apply (read + bind)

Supported consumers (minimum):

- `ahentic-browser/set-blocks` — `input.from_memory` → load `payload.blocks` (or convert `html`/`markdown` once if we support that).
- `ahentic/create-post` / `ahentic/update-post` — `from_memory` → `content` / block serialization as appropriate.

Pending HITL / browser tools keep **key only** on `_ahentic_pending_tool`; REST expands for the browser runner. HITL shows key / title / size / short excerpt.

Expansion happens **in the orchestrator (or ability wrapper) before execute**, so:

- Browser pause receives the **expanded** input (sidebar does not need to load artifacts for apply).
- PHP abilities receive expanded input the same way.
- The model is **not** required to re-emit the article in `tools_planned`.

If `from_memory` is set:

- Missing key → tool error `artifact_missing`.
- `status !== ready` → `artifact_not_ready` (include status).
- `kind` incompatible with the ability → `artifact_kind_mismatch`.
- On success → mark `applied` (or delete).

Explicit inline `blocks` / `content` in input still works; `from_memory` wins when both are present (document that rule).

---

## Prompt injection (pointers only)

Every LLM think may include a compact section, for example:

```text
Session artifacts (bodies omitted — use from_memory to apply):
- article_draft: ready · blocks · "10 Ways…" · 12 blocks · staged step 4
```

Rules for the system prompt:

- Prefer `from_memory` when applying a staged draft; do not paste the full artifact into `tools_planned`.
- Do not invent artifact keys; only use keys listed (or stage a new one first).
- Staging is not publishing — still call the mutate ability.

Never inject full `payload` into the system/user prompt by default. If a rare “revise draft” path needs the body, load it via a dedicated read (ability or orchestrator one-shot), not every turn.

---

## API sketch (PHP)

```php
// Illustrative — exact class name TBD in src/session/

Ahentic_Session_Artifacts::get( $session_id ): array;
Ahentic_Session_Artifacts::list_pointers( $session_id ): array; // for prompts
Ahentic_Session_Artifacts::get_item( $session_id, $key ): ?array;
Ahentic_Session_Artifacts::stage( $session_id, $key, array $item ): true|\WP_Error;
Ahentic_Session_Artifacts::set_status( $session_id, $key, $status ): true|\WP_Error;
Ahentic_Session_Artifacts::delete( $session_id, $key ): void;
Ahentic_Session_Artifacts::clear( $session_id ): void;

// Expand tool input before execute / browser pause:
Ahentic_Session_Artifacts::apply_from_memory( $session_id, $ability_name, array $input ): array|\WP_Error;
```

REST: expose pointers on the session payload for the sidebar/debugger (`artifacts` summary). Full payloads are not required in every poll — optional `?include=artifacts` for debugging.

---

## Orchestrator hooks

1. **Before tool run / browser pause** — if `input.from_memory` is present, call `apply_from_memory`; replace input; continue HITL/browser/PHP paths as today.
2. **After successful mutate that used an artifact** — mark `applied` or delete.
3. **On `run_start` (new user message)** — product choice:
   - **Keep** artifacts (default recommended: user may say “now put that draft in the editor”), or
   - **Clear** on each new message if we want a hard reset — document the chosen policy in code.
4. **Prompt build** — append `list_pointers()` next to page context / plan (not inside the transcript dump).

Browser abilities do not need a separate artifacts store. If a browser tool *stages* something, return a `memory` / `artifacts` patch in the browser-result POST; `handle_browser_result` merges via `stage()`.

---

## Size & safety

- Cap payload size per artifact (e.g. 100–200KB JSON) and max items per session (e.g. 10).
- Sanitize on write (same expectations as ability input schemas).
- Do not put secrets in artifacts.
- HITL summaries should mention the artifact key + title/size, not dump the body into the Allow card.

---

## Relationship to other session state

| State | Role vs artifacts |
| --- | --- |
| `_ahentic_entries` | Audit / chat; may note “staged” / “applied”; not the body store. |
| `_ahentic_page_context` | Open tab identity / editor routing — not draft bodies. |
| `_ahentic_plan` | Checklist for the run — not content payloads. |
| `_ahentic_pending_tool` | One in-flight tool handoff — may carry *expanded* input after `from_memory`. |
| `editor.refs` (working memory) | Editor addressing (`b1` ↔ clientId); session-backed + validated per Working memory PRD — sibling namespace to payload artifacts. |

---

## Acceptance scenarios

1. **Draft then set-blocks** — Model stages `article_draft`, later calls `set-blocks` with `{ "from_memory": "article_draft" }`. Editor receives full blocks; model output does not contain the full article again.
2. **Draft then create-post** — Same with server `create-post` when the block editor is not open.
3. **Revise** — Restage same key with new payload; status `ready`; previous body replaced.
4. **Stale** — Applying `applied` / missing / wrong kind returns a clear tool error; run continues.
5. **Prompt size** — Think prompts list pointers only; full payload is not re-injected each step.

---

## Implementation order

1. Meta storage + PHP get/stage/delete/list_pointers.
2. `apply_from_memory` wired in the orchestrator for `set-blocks` and `create-post` (and `update-post` if straightforward).
3. Stage path (ability and/or control-block merge).
4. Prompt pointers + system-prompt guidance.
5. Mark applied / size caps / debugger summary on session REST.
6. (Later) broader working memory: `vars`, `facts`, `editor.ref_map` in the same session store.

---

## Summary

Artifacts are **session-scoped staged payloads**. The model and tools share them by **key**. The orchestrator **expands** `from_memory` at execute time so large drafts are written once and applied reliably, without using the chat transcript as a bulk content store.
