# Future — Edit previous prompt

**When:** **Free** Cursor-parity UX (band TBD — likely free v2/v3)
**Status:** Deferred product note — grill before implement
**Canonical spec:** [`pro__premium_only/docs/future-edit-previous-prompt.md`](../../pro__premium_only/docs/future-edit-previous-prompt.md)

> **Before implementing:** run a [grill](../../.cursor/skills/grilling/SKILL.md). Do not wire transcript edit / truncate / site-revert until that session finishes — especially the Cursor “revert vs don’t revert” meaning on a mutating WordPress site.

## Intent (one line)

Edit an earlier user prompt, confirm revert of what came after (chat and/or site — grill decides), then re-run from the edited text.
