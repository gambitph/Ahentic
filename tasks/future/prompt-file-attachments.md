# Future — Composer file attachments

**When:** **Free v3** (after free v2; same band as edge/corner snap)
**Status:** Deferred product note — chrome placeholder only in UI today
**Canonical spec:** [`pro__premium_only/docs/future-prompt-file-attachments.md`](../../pro__premium_only/docs/future-prompt-file-attachments.md)

> **Before implementing:** run a [grill](../../.cursor/skills/grilling/SKILL.md). Do not wire upload/REST until that session finishes.

## Free-tree chrome

`composer.js` keeps the paperclip button in the DOM; it is `disabled`, `hidden`, and styled with `.ahentic-composer__affordance--deferred`. Do not delete it when touching the composer — un-hide when free v3 implements attach.

Sibling (Premium): [`prompt-voice-input.md`](./prompt-voice-input.md)
