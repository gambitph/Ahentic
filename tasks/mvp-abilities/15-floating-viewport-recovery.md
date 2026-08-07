# 15 — Floating sidebar viewport recovery

**When:** MVP chrome fix  
**Status:** Implemented (unit-tested; manual acceptance)  
**Area:** Sidebar floating placement (`src/admin/js/sidebar/`)  
**Source:** Grill 2026-08-08 (toggle-then-rehome; nudge; min-size → defaults; admin bar + Cmd/Ctrl+I)

## Problem

Floating / floating-small can leave the viewport (browser resize, user shrinks the panel, or a persisted `floatRect` from a larger window). The admin-bar Ahentic control and Cmd/Ctrl+I only toggle open — they do not bring a lost panel back.

## Behavior

1. **Always toggle** open ↔ closed (admin bar and Cmd/Ctrl+I unchanged as toggles).
2. **On the open transition only**, if placement is floating and geometry is invalid, **re-home** before paint / as part of opening:
   - **Out of viewport:** nudge `left` / `top` so the rect fits inside the viewport using the same floating bounds / margins as today (`FLOATING_GAP`, `clampFloatingRect` / `getDefaultFloatingRect` family — do not invent a second margin system).
   - Prefer **minimal nudge** (keep the user’s preferred corner/size) over snapping to the default corner.
   - **Width too small** (`< MIN_WIDTH` or otherwise unusable): set width to floating default (`DEFAULT_WIDTH`).
   - **Height too small** (`< MIN_FLOAT_HEIGHT`): restore **placement default height** (`getDefaultFloatingRect` for `floating` / `floating-small`). Do not shrink a tall panel the user sized on purpose.
3. Persist the corrected `floatRect` (and width if reset) via existing `localStorage` chrome state.
4. Docked left/right: no change (not in scope).

## Implementation notes

- Reuse `clampFloatingRect`, `getDefaultFloatingRect`, `FLOATING_GAP`, `MIN_WIDTH`, `DEFAULT_WIDTH`, `MIN_FLOAT_HEIGHT` from `constants.js` / `storage.js` — deepen, don’t duplicate.
- Shared open path for admin-bar click and Cmd/Ctrl+I so both get the same recover-on-open.
- Closing never repositions.

## Acceptance

- [x] Unit: off-screen rect nudged inside with `FLOATING_GAP` (`constants.test.js`).
- [x] Unit: tiny width → `DEFAULT_WIDTH`; tiny height → placement default via `getDefaultFloatingRect`.
- [x] Unit: already-valid rect unchanged; close path does not call recover.
- [x] Wiring: admin bar + Cmd/Ctrl+I share `toggleSidebar` (recover only when opening); `openSidebar` recovers only on closed→open.
- [ ] Manual: drag floating panel mostly off-screen, close, reopen via admin bar / shortcut → fully inside viewport.
- [x] Resize-while-open unchanged (no `resize` listener for recovery).
