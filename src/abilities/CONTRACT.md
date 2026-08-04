# Contract: Abilities registration

**Kind:** Subsystem must-guarantee (free tree)  
**Product should:** [Abilities PRD](../../pro__premium_only/docs/prd/abilities.md) · [Content & editor PRD](../../pro__premium_only/docs/prd/content-and-editor.md)  
**How-it-works:** [abilities.md](./abilities.md) · [server-abilities.md](./server-abilities.md) · [client-abilities.md](./client-abilities.md)  
**ADR:** [0004](../../docs/adr/0004-editor-first-content-writes.md)

---

## Registration

1. Agent-facing tools are WordPress Abilities (`wp_register_ability`), namespaced `ahentic/…` or `ahentic-browser/…`.
2. Every agent-facing ability is wired through `Ahentic_Abilities` (or module hooked from it): `names`, mode/readonly, HITL, browser flag, `execute` dispatch.
3. `permission_callback` enforces WP capabilities; never rely on HITL alone for security.

## Runtime split

| Flag / mechanism | Meaning |
| --- | --- |
| Server execute | Runs in PHP during orchestrator step |
| `requires_browser_runtime()` | Orchestrator must pause; sidebar JS executes |

Browser abilities must not perform real work in PHP stubs beyond registration/metadata.

## Mode & safety lists

| Concern | Contract |
| --- | --- |
| Ask mode | Non-readonly abilities are not executable |
| HITL | `requires_hitl()` + human summary for mutating/dangerous tools |
| Destructive / site-wide | Always HITL per Agent runtime PRD tiers |

## Content routing

- While page context indicates block editor open for post P, server abilities that rewrite **P**’s body must refuse or instruct browser path (editor-first).
- Outside editor, server content abilities are the path for create/update.

## Return shape

- Success: JSON-serializable array (include `ok` when useful).
- Failure: `WP_Error` or structured `{ ok: false, error, message, hint? }` suitable for the next think.

## Artifacts

- Abilities that accept large content should support `from_memory` where documented; orchestrator expands before execute.

## Premium headless

- Abilities used by Premium Agents must be server-executable; browser-only tools are unavailable headless.
