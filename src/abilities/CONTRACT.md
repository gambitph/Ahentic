# Contract: Abilities registration

**Kind:** Subsystem must-guarantee (free tree)  
**Product should:** [Abilities PRD](../../pro__premium_only/docs/prd/abilities.md) · [Content & editor PRD](../../pro__premium_only/docs/prd/content-and-editor.md) · [Site settings PRD](../../pro__premium_only/docs/prd/site-settings.md)  
**How-it-works:** [abilities.md](./abilities.md) · [server-abilities.md](./server-abilities.md) · [client-abilities.md](./client-abilities.md)  
**ADR:** [0004](../../docs/adr/0004-editor-first-content-writes.md) · [0007](../../docs/adr/0007-settings-writes-require-snapshot-undo.md)

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
| Non-preallowable | Some abilities (e.g. user writes) must reject `allow_session` / `always_allow` and always pause for a fresh Allow/Deny — see [site-settings PRD](../../pro__premium_only/docs/prd/site-settings.md) |

## Content routing

- While page context indicates block editor open for post P, server abilities that rewrite **P**’s body must refuse or instruct browser path (editor-first).
- Outside editor, server content abilities are the path for create/update.

## Return shape

- Success: JSON-serializable array (include `ok` when useful).
- Failure: `WP_Error` or structured `{ ok: false, error, message, hint? }` suitable for the next think.

## Artifacts

- Abilities that accept large content should support `from_memory` where documented; orchestrator expands before execute.

## Settings snapshot + undo

- Any ability writing a surface with no WordPress-native revision system (theme settings, options, global styles, template parts, media) must snapshot the prior value — or its absence — keyed to the session before executing, so `ahentic/undo-last-actions` can restore it. See ADR-0007.
- An absent prior value (e.g. a template part with no database row yet) must be recorded distinctly from an empty one, so undo can delete an override instead of writing back blank.

## Premium headless

- Abilities used by Premium Agents must be server-executable; browser-only tools are unavailable headless.

## Testing

- Every new or changed ability lands with coverage in its `tasks/mvp-abilities`-track Playwright module spec (`tests/e2e/specs/`) — see [`docs/agents/testing.md`](../../docs/agents/testing.md) and [server-abilities.md § Testing](./server-abilities.md#testing-a-new-server-ability).
- Pure decision logic inside an ability (heuristics, diff previews, snapshot shaping) should be split out and covered in PHPUnit; PHPUnit never gets WordPress integration tests.
