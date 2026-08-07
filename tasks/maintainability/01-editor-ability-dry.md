# Task M1 — Editor ability DRY (prelude helper)

**Track:** Maintainability 2  
**Status:** done  
**Can run:** ∥ M2  
**Source:** AI-slop detector duplicate blocks · anti-slop rule

## Current state

`editor-abilities.js` repeats the same prelude across mutate exports (`replaceBlocks`, `setBlocks`, `insertBlocks`, `deleteBlocks`, …): `requireEditor` → resolve refs → missing/notFound → `rejectPlaceholderBlocks` → `normalizeBlocksInput`.

## Scope

Extract a **shared prelude helper** that returns either an error result or `{ ctx, clientIds, blocks }` (shape as needed per call). Each export still owns its Gutenberg `dispatch` and success payload.

## Out of scope

- Full mutate runner that also dispatches and shapes success (too much abstraction).
- Changing REST / ability wire contracts.
- PHP ability modules.

## Acceptance criteria

- [x] Detector-flagged duplicate blocks for the prelude path are gone (one helper, thin exporters).
- [x] Behavior unchanged for replace / set / insert / delete (and any other export that shared the prelude).
- [x] Unit or characterization tests at the helper seam where pure (e.g. error shaping); existing editor/browser e2e still green or manual smoke.

## Done notes

- Exported `resolveTargetClientIds` + `prepareBlocksPayload` in `editor-abilities.js`.
- Wired: `replaceBlocks`, `setBlocks`, `insertBlocks`, `duplicateBlocks`, `deleteBlocks`, `moveBlocks` (refs-only via `allowSelection: false`).
- Tests: `editor-abilities.test.js` (6 cases).

## Files likely touched

- `src/admin/js/sidebar/editor-abilities.js`
- Optional: `src/admin/js/sidebar/editor-abilities.test.js` (or focused helper test)
