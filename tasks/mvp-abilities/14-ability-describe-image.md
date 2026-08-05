# Task 14 — `ahentic/describe-image`

**Track:** B (independent, no infra dependency — readonly, nothing to snapshot/undo)
**Source:** Not yet in `abilities-catalog.md` — new gap identified while scoping Task 13. Closes the *input* side of the accessibility loop: `ahentic-browser/audit-accessibility` finds missing alt text, `ahentic/update-media` (Task 13) writes it, but nothing today can look at an image and generate the alt text/description in between. Add this ability to the catalog (PHP — free table) once implemented.

## Current state

`class-abilities-media.php` has exactly one ability, a readonly report (`ahentic/find-unused-media`) with nothing that inspects image *content*. `Ahentic_AI` (`src/orchestrator/class-ai.php`) only ever calls `generate_text_result()` for plain chat-style prompts — no ability anywhere calls `with_file()` on the AI Client prompt builder. This would be the first vision-capable call in the codebase.

Confirmed via the vendored `File` DTO (`vendor/wordpress/php-ai-client/src/Files/DTO/File.php`): `with_file( $file, $mimeType )` accepts a local file path, a remote URL, base64, or a data URI.

- **Local path** → the DTO reads the file itself and inlines it as base64 in the request.
- **URL** → the DTO does **not** fetch it; it forwards `{ fileType: remote, url }` straight to the provider, who fetches it server-side. Our PHP process never makes that request — there is nothing for `host_is_publicly_fetchable()` to usefully guard here, unlike `upload-media`/`http-fetch`.

## What's missing

A single readonly ability that takes either an existing attachment or an arbitrary image URL, sends it to the AI Client for vision understanding, and returns a structured description + an accessibility-ready alt-text suggestion — without ever making it into `_ahentic_entries`/the model's own context as anything larger than that structured result (a description + a short suggestion, not raw pixels).

## Scope

### `ahentic/describe-image`

- **Category:** `ahentic-media` (alongside `find-unused-media`, and eventually `update-media` / `upload-media` / `set-featured-image`).
- **Input:** `attachment_id` (int) **or** `url` (string) — mutually exclusive; reject if both or neither are supplied.
- **Resolving `attachment_id` → file:**
  - Validate `post_mime_type` starts with `image/`; reject otherwise with a clear error (no video/PDF/audio description in v1).
  - Read `wp_get_attachment_metadata( $id )['sizes']`. Pick the **smallest registered size whose long edge (`max(width, height)`) is ≥ 1024px**. If none qualify (all registered sizes are smaller than that), fall back to the full/original file. This is a deliberate cost optimization — most vision providers stop gaining accuracy past roughly this resolution, so sending a smaller registered size instead of the full original cuts request size for no perceptible quality loss.
  - Resolve the chosen size to a local path: original via `get_attached_file( $id )`; a named size via `dirname( get_attached_file( $id ) ) . '/' . $meta['sizes'][$size]['file']`.
  - Reject if the resolved local file exceeds **10MB** (`filesize()` check) before calling `with_file()` — clear ability error, not a provider-side failure.
- **Resolving `url`:**
  - Validate it is a well-formed `http://` or `https://` URL. That is the *only* check — no `host_is_publicly_fetchable()` SSRF guard, since we never fetch it ourselves (see Current state above). No size cap is enforceable on this path either, for the same reason.
- **AI call:** one `wp_ai_client_prompt( ... )->with_file( $file, $mime_type )->as_json_response()->generate_text_result()` (or SDK equivalent via `Ahentic_AI`), with a system instruction asking for exactly:
  ```json
  { "description": "1-3 sentence general description", "alt_text_suggestion": "objective, <125 chars, no 'Image of'/'Photo of'" }
  ```
  Follow the WP reference pattern for the system instruction wording (make.wordpress.org's "Introducing the AI Client" post has a worked alt-text example — reuse its accessibility-expert framing).
- **No `using_model_preference()`** — consistent with every other `Ahentic_AI` call today (none set one). If the configured provider/model rejects the file part or otherwise fails, catch the exception/`WP_Error` and return a clear ability error ("Your configured AI provider doesn't support image understanding — check Settings → Connectors"), not a raw SDK message.
- **Rate limit:** bespoke per-session counter (new code — `http-fetch`'s "rate-limited" catalog claim has no actual implementation to copy from), max **10 calls per session**. Reject the 11th+ call with a clear, distinguishable error code (e.g. `ahentic_describe_image_rate_limited`) rather than silently failing.
- **Permission:** `current_user_can( 'upload_files' ) || current_user_can( 'manage_options' )` (matches `find-unused-media`).
- **HITL:** none — `meta.annotations: { readonly: true, idempotent: true }`, same shape as `find-unused-media`. It doesn't mutate the site; writing the result (via `update-media`) is a separate, already-HITL'd step.
- **Output:** `{ ok, source: 'attachment'|'url', attachment_id?, url?, mime_type, resolved_size (name or 'full'), description, alt_text_suggestion }`.

## Out of scope

- Non-image MIME types (video/audio/PDF description).
- Auto-writing the result anywhere — this ability only returns data; the caller chains a separate `ahentic/update-media` call.
- A shared/reusable rate-limit primitive (flagged in Task 15 too — both this and `generate-image` need bespoke per-session counters for now; generalizing is future work, not blocking either task).
- `using_model_preference()` — no hardcoded vendor model slugs in v1.

## Acceptance criteria

- [ ] `attachment_id` resolves to the smallest registered size ≥ 1024px long edge, falling back to `full` when no size qualifies
- [ ] Non-image `attachment_id` and oversized (>10MB) local files are rejected with a clear ability error before any AI call is made
- [ ] `url` input never triggers a local HTTP fetch (verify no `wp_remote_get`/`curl` call happens for the `url` path — it only builds a remote `File` reference)
- [ ] Output is valid JSON matching `{ description, alt_text_suggestion }` (via `as_json_response()`), not free text requiring further parsing
- [ ] 11th call in the same session within the rate-limit window is rejected with a distinct, catchable error
- [ ] A provider that errors on the file part surfaces a clear "provider doesn't support image understanding" message, not a raw exception string
- [ ] Readonly: visible/callable in Ask mode, no HITL card ever shown
- [ ] `alt_text_suggestion` from a real test image is verified to actually work as `update-media`'s `alt_text` input end-to-end (manual check, matches Task 13's own acceptance bar for the write side)
