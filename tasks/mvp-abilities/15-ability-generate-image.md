# Task 15 — `ahentic/generate-image` + `image`-kind session artifact

**Track:** B for parts 1–2 (artifact kind + the ability itself — independent, no Track A dependency, nothing site-visible happens until a follow-up `upload-media` call). Part 3 (`upload-media` `from_memory` wiring) is **cross-track**: it edits Track E's Task 13, so it cannot land before Task 13's `upload-media` exists, even though it needs no Track A infra itself. Sequence parts 1–2 whenever convenient; hold part 3 until Task 13 is merged.
**Source:** Not yet in `abilities-catalog.md` — new gap identified alongside Task 14. Add to the catalog (PHP — free table) once implemented, and cross-reference from Task 13's `upload-media` entry once that ability gains the `from_memory` support this task requires.
**Depends on:** `src/session/class-artifacts.php` (`Ahentic_Session_Artifacts`) gaining a new `image` kind — build that first as part of this task, it is not separable from the ability. Part 3 additionally depends on Task 13.

## Current state

`Ahentic_Session_Artifacts` (`src/session/class-artifacts.php`, `src/session/artifacts.md`) already solves "stage a large payload once, apply it later by key" for text — kinds are `blocks | html | markdown | post_content | json`, capped at `MAX_PAYLOAD_BYTES = 200000` (200KB), stored as JSON in session post meta (`_ahentic_artifacts`). No image/binary kind exists. Nothing in the codebase calls `generate_image()` / `generate_images()` today.

`ahentic/upload-media` (Task 13) is currently scoped as URL-sideload-only for v1, with base64/staged-file support explicitly punted ("revisit only if a real product need shows up" — this task is that need, resolved via `from_memory` rather than a new base64 input shape, see Scope below).

The AI Client's image-generation config (confirmed via `vendor/wordpress/php-ai-client/src/Providers/Models/DTO/ModelConfig.php`) exposes `outputMediaOrientation` (`square|landscape|portrait`) and `outputMediaAspectRatio` (a `"width:height"` string, e.g. `16:9`) — there is no arbitrary pixel width/height knob; the provider maps aspect ratio to whatever pixel sizes it actually supports.

## What's missing

1. An `image` kind on `Ahentic_Session_Artifacts` whose payload is a **pointer to a temp file on disk**, not raw bytes.
2. `ahentic/generate-image`: calls `generate_image()`, writes the result to that temp location, and stages the pointer artifact.
3. `ahentic/upload-media` (Task 13) gaining `from_memory` support so it can resolve an `image`-kind artifact key straight to its temp path and sideload from there.

## Scope

### 1. `image` kind on `Ahentic_Session_Artifacts`

- Add `const KIND_IMAGE = 'image';` to the kind enum (`allowed_kinds()`).
- Payload shape: `{ path: string, mime_type: string, width: int, height: int }` — a pointer only. This trivially stays under the existing 200KB cap since it's metadata, not pixels.
- Physical bytes live at `wp_get_temp_dir() . 'ahentic-images/' . <generated filename>` — deliberately **not** under `wp-content/uploads/`: never web-accessible (the browser never fetches from this path — it already receives the image inline as a data URI in the ability's own REST response, see below), and doesn't pollute the real Media Library directory tree with files that were never attachments.
- **Cleanup:** no timer/cron — dies with the session, same as every other artifact kind (per `artifacts.md`'s existing "artifacts die with the session" rule). `Ahentic_Session_Artifacts::clear()` / `delete()` must be extended so that clearing/deleting an `image`-kind artifact also `unlink()`s the temp file, not just the JSON pointer — this is the one kind so far backed by a real filesystem side effect.
- `kind_mismatch()` / `allowed_kinds()` and any `from_memory` consumer allowlist (`ability_supports_from_memory()`) need `upload-media` added once Task 13 wires it up.

### 2. `ahentic/generate-image`

- **Category:** `ahentic-media`.
- **Input:** `prompt` (required, string — style/mood/medium described in the prompt text itself, no separate `style` field) + optional `aspect_ratio` (enum: `1:1` default, `16:9`, `9:16`, `4:3`, `3:4`) → `usingOutputMediaAspectRatio()`. No separate orientation field (redundant with aspect ratio). Single image per call only — no `candidate_count` / `generate_images()` plural path; that would need a multi-item artifact shape this task doesn't build.
- **Execution:** `wp_ai_client_prompt( $prompt )->using_output_media_aspect_ratio( $aspect_ratio )->generate_image()` (or SDK equivalent), write the returned `File`'s bytes to the temp path (`getDataUri()` → decode, or whatever raw-bytes accessor the result exposes), then `Ahentic_Session_Artifacts::stage( $session_id, $key, [ 'kind' => KIND_IMAGE, 'payload' => [ 'path' => ..., 'mime_type' => ..., 'width' => ..., 'height' => ... ], 'status' => 'ready' ] )`.
- **What the model/transcript sees:** the pointer only — key, dimensions, prompt, status — via the normal artifact-staging tool-result shape. Never the base64/data URI. This matches artifacts.md's existing "prompts only get pointers, never full payload" rule; putting a multi-MB data URI into `_ahentic_entries` would bloat every subsequent think.
- **What the browser sees:** the *same* ability's REST response (the one-time result payload delivered to the sidebar, not what gets persisted into the session transcript store) additionally carries the data URI inline, so the sidebar can render a preview and construct a `File`/`Blob` client-side without a second fetch round-trip. This is a REST-shaping concern, not a new endpoint — the persisted/model-facing copy and the browser-facing copy of the same response differ only in whether the data URI is included.
- **No `using_model_preference()`** — same reasoning as Task 14; catch provider failures (e.g. "this model doesn't support image generation") and surface a clear ability error.
- **Rate limit:** bespoke per-session counter (separate from Task 14's — do not share state), max **5 calls per session** — lower than `describe-image`'s 10, since generation is the priciest AI call in this feature.
- **Permission:** `current_user_can( 'upload_files' ) || current_user_can( 'manage_options' )`.
- **HITL:** none. Nothing site-visible or permanent happens yet — the image only exists as a session-scoped temp file + artifact pointer. The real mutation (and its own HITL gate) is the later `upload-media` call.
- **Output:** `{ ok, artifact_key, mime_type, width, height, aspect_ratio, prompt }` to the model; the browser-facing REST variant additionally includes `data_uri`.

### 3. `ahentic/upload-media` gains `from_memory` (Task 13 amendment)

- Add `from_memory` support to `upload-media`'s input, following the exact existing pattern `create-post`/`set-blocks` already use (`Ahentic_Session_Artifacts::apply_from_memory()` expands it before `execute_callback` runs).
- For an `image`-kind artifact, expansion means: read the pointer's `path`, run it through the **same** `wp_handle_sideload()` call `upload-media`'s URL-sideload path already uses (just skip the download step — the bytes are already local) — so WordPress's own MIME allowlist still governs what lands in the real Media Library, identical safety property to the URL-sideload path.
- **No new base64/data-uri input shape on `upload-media` itself** — `from_memory` is the only new surface, deliberately smaller than what Task 13's "revisit only if a real product need shows up" note originally anticipated.
- On successful upload, mark the artifact `applied` (or delete it) per the existing artifact lifecycle — including unlinking the now-redundant temp file.

## Out of scope

- Multiple image variants per call (`generate_images()` / `candidate_count`).
- A generic/shared rate-limit primitive (same note as Task 14 — bespoke counters for now).
- `using_model_preference()` / hardcoded vendor model slugs.
- Any change to `upload-media`'s existing URL-sideload path — this task only adds a second, `from_memory`-driven entry point alongside it.

## Acceptance criteria

- [ ] `image`-kind artifact payload is always a pointer (`path`, `mime_type`, `width`, `height`) — never raw bytes or base64 in the JSON stored in `_ahentic_artifacts`
- [ ] Generated bytes are written under `wp_get_temp_dir() . 'ahentic-images/'`, never under `wp-content/uploads/`
- [ ] The tool-result persisted for the model/transcript never contains a data URI; the one-time browser-facing REST response does
- [ ] Deleting/clearing an `image`-kind artifact also deletes its temp file (no orphaned files after a session ends)
- [ ] No cron/timer-based cleanup exists for this kind — verify it only ever gets removed via session deletion or explicit artifact delete/apply
- [ ] `upload-media` with `from_memory` pointing at a valid `image` artifact produces a real Media Library attachment via `wp_handle_sideload()`, with no separate `url`/base64 input required
- [ ] 6th `generate-image` call in the same session is rejected with a distinct, catchable error
- [ ] `generate-image` has no HITL card; `upload-media` (regardless of `from_memory` vs URL input) keeps whatever HITL tier Task 13 assigns it
