# Future — Composer voice input

**When:** **Premium v3** (after Premium v2 Agents)
**Status:** Deferred product note — chrome placeholder only in free UI
**Canonical spec:** [`pro__premium_only/docs/future-prompt-voice-input.md`](../../pro__premium_only/docs/future-prompt-voice-input.md)

> **Before implementing:** run a [grill](../../.cursor/skills/grilling/SKILL.md). Do not wire STT/REST until that session finishes.

## Free-tree chrome

`composer.js` keeps the mic button in the DOM; it is `disabled`, `hidden`, and styled with `.ahentic-composer__affordance--deferred`. Do not delete it when touching the composer — un-hide only when Premium implements gated STT.

Sibling (Free v3): [`prompt-file-attachments.md`](./prompt-file-attachments.md)
