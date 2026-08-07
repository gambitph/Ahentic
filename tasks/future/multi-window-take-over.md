# Future — Multi-window take-over (“Become active”)

**When:** **v3** (not urgently needed for MVP)
**Depends on:** v1 viewer overlay + active-runner claim (shipped — see [`src/admin/js/sidebar/sidebar.md` § Multi-window runner lock](../../src/admin/js/sidebar/sidebar.md))
**Status:** Deferred product/tech note — not an MVP build task

> **Before implementing:** run a [grill](../../.cursor/skills/grilling/SKILL.md) on transfer races (both windows clicking, mid-`awaiting_browser` handoff, HITL mid-flight) and how the previous active window is forced into viewer mode without double-running a step.

## Goal

When session S is active in window A and viewer-only in window B, the user in B can **take over**: B becomes the active runner, A becomes viewer-only (with the same overlay). Sessions stay synced via the server as today.

## UX (target)

- Viewer overlay (shipped in v1) gains a primary button: **Take over** (or equivalent).
- Clicking it:
  1. Releases / steals the per-session active claim so A is no longer the runner
  2. Makes B the active runner (overlay clears on B; overlay appears on A)
  3. Does not fork the conversation — same `ahentic-session`, still server-synced

Reuse `session-runner-lock.js` / `ahentic.session-runner.v1` — do not invent a second claim model.

## Product rules to settle in grill

- What happens if A is mid browser-ability execute when B takes over?
- Can take-over happen during `awaiting_human` (HITL card open on A)?
- Should take-over require confirmation on A, or is silent demotion OK?
- Idle / closed A already reclaimed by v1 stale heartbeat — take-over is for the **live** two-window case.

## Out of scope here

- Cross-device / multi-user takeover
- Server-enforced single-writer locks (unless grill concludes client-only steal is unsafe)

## Acceptance criteria (when scheduled)

- [ ] Viewer overlay includes Take over; click transfers active runner from the other window to this one
- [ ] Previous active window enters viewer-only (overlay) without continuing to drive the same session
- [ ] No double-execution of the same pending browser step across the handoff
- [ ] Session transcript / plan / status remain a single synced timeline
