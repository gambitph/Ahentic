# Contract: Abilities registration

**Kind:** Subsystem must-guarantee (free tree)  
**Product should:** [Abilities PRD](../../pro__premium_only/docs/prd/abilities.md) · [Content & editor PRD](../../pro__premium_only/docs/prd/content-and-editor.md) · [Site settings PRD](../../pro__premium_only/docs/prd/site-settings.md)  
**How-it-works:** [abilities.md](./abilities.md) · [server-abilities.md](./server-abilities.md) · [client-abilities.md](./client-abilities.md)  
**ADR:** [0004](../../docs/adr/0004-editor-first-content-writes.md) · [0007](../../docs/adr/0007-settings-writes-require-snapshot-undo.md)

---

## Registration

1. Agent-facing tools are WordPress Abilities (`wp_register_ability`), namespaced `ahentic/…` or `ahentic-browser/…`.
2. Every agent-facing ability group self-registers with `Ahentic_Abilities::register_module()` and self-hooks WP ability registration. The facade catalogs modules for mode/readonly, HITL, browser flag, progress labels, and `execute` dispatch — do not re-list modules in the facade.
3. `permission_callback` enforces WP capabilities; never rely on HITL alone for security.

## Runtime split

| Flag / mechanism | Meaning |
| --- | --- |
| Agent tool run | Goes through `Ahentic_Tool_Runner` (HITL / browser / `from_memory` / assess / persist) — see [orchestrator CONTRACT](../orchestrator/CONTRACT.md) |
| Server execute | Tool runner calls `Ahentic_Abilities::execute` (dispatch only) when PHP should run |
| `requires_browser_runtime()` | Tool runner pauses; sidebar JS executes; resume via `record_completed_result` |

`Ahentic_Abilities::execute` is ability dispatch (`execute_*`), not a second pipeline. Do not reimplement HITL/browser/assess around it in Orchestrator, REST, or modules.

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

- Abilities that accept large content should support `from_memory` where documented; the Tool runner expands before execute.

## Settings snapshot + undo

- Any ability writing a surface with no WordPress-native revision system (theme settings, options, global styles, template parts, media) must snapshot the prior value — or its absence — keyed to the session before executing, so `ahentic/undo-last-actions` can restore it. See ADR-0007.
- An absent prior value (e.g. a template part with no database row yet) must be recorded distinctly from an empty one, so undo can delete an override instead of writing back blank.

## Premium headless

- Abilities used by Premium Agents must be server-executable; browser-only tools are unavailable headless.

## Testing

- Before shipping a new or changed ability, complete [`docs/agents/ability-checklist.md`](../../docs/agents/ability-checklist.md) (catalog derivation, ship-or-silence prompts, catalog + phantom PHPUnit, Playwright).
- Every new or changed ability lands with coverage in its subsystem Playwright module spec (`tests/e2e/specs/`) — see [`docs/agents/testing.md`](../../docs/agents/testing.md) and [server-abilities.md § Testing](./server-abilities.md#testing-a-new-server-ability). Module specs may call `run-ability` → `Ahentic_Abilities::execute` (ability seam); full pipeline (HITL / browser / assess) is covered by `orchestrator-pipeline.spec.js`, not reimplemented per ability.
- Pure decision logic inside an ability (heuristics, diff previews, snapshot shaping) should be split out and covered in PHPUnit; PHPUnit never gets WordPress integration tests.
- Policy lists for a module must stay catalog-derived; lock with `*AbilityCatalogTest` (see browser/content/media). Prompt/playbook ability tokens must resolve to registered names (`PhantomAbilityNameTest`).
