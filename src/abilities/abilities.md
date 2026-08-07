# Abilities

Ahentic exposes agent/tools as **WordPress Abilities** — named units with label, description, category, JSON Schema input/output, permission + execute callbacks. The orchestrator treats ability names in `tools_planned` as the tool surface.

> **Canonical should:** [Abilities PRD](../../pro__premium_only/docs/prd/abilities.md) · **Contract:** [CONTRACT.md](./CONTRACT.md)

**Code:** `src/abilities/` (+ session artifact abilities in `src/session/class-artifacts.php`)

**Related:** [Server abilities](./server-abilities.md) · [Client abilities](./client-abilities.md) · [Orchestrator](../orchestrator/orchestrator.md) · [Control block](../orchestrator/control-block.md) · [Artifacts](../session/artifacts.md)

---

## Why Abilities

Register once → discover via PHP / REST / (future) JS clients → reuse for the sidebar agent, HITL, and automation.

Prefer Abilities over buried AJAX, one-off REST routes, or a private tool registry when the action should be agent-facing.

Official refs: [Abilities API in WP 6.9](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/), [Client-Side Abilities in WP 7.0](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/).

---

## Module map

| Module | Namespace | Runtime |
| --- | --- | --- |
| `class-abilities.php` | Facade + module catalog | — |
| `class-abilities-snapshot.php` | `ahentic/get-site-snapshot` | Server |
| `class-abilities-content.php` | `ahentic/*` content | Server |
| `class-abilities-plugins.php` | `ahentic/*` plugins | Server |
| `class-abilities-site.php` | site health, options, http-fetch, … | Server (http-fetch may pause for browser when `as_user`) |
| `class-abilities-settings.php` | settings context, list-settings, get-setting, update-theme-setting, update-global-styles, update-template-part, update-option | Server |
| `class-abilities-media.php` | list-media, get-media, find-unused-media, describe/generate/upload, update/delete/replace, set-featured-image | Server |
| `class-abilities-taxonomy.php` | terms | Server |
| `class-abilities-users.php` | list/create/update/delete users | Server |
| `class-abilities-menus.php` | list-menus, list-menu-items, get-menu, update-menu (classic nav) | Server |
| `class-abilities-browser.php` | `ahentic-browser/*` | **Client** (PHP stubs) |
| `class-artifacts.php` (session) | `ahentic/stage-artifact`, list, delete | Server (session meta only) |
| `class-settings-snapshots.php` (session) | `ahentic/undo-last-actions` + snapshot store helpers | Server (session meta) |
| `class-playbooks.php` | `ahentic/get-wordpress-guidance` | Server |

Facade: `Ahentic_Abilities` keeps the public orchestrator seam (`available_for_agent()`, `available_for_mode()`, `execute()`, `requires_hitl()`, `is_non_preallowable()`, `hitl_choice_allowed()`, `requires_browser_runtime()`, `progress_label()`). Ability groups **self-register** via `Ahentic_Abilities::register_module( __CLASS__ )` and self-hook WP `wp_abilities_api_*` actions at file load. Agent runs go through `Ahentic_Tool_Runner`; `execute()` is dispatch only — see [orchestrator CONTRACT](../orchestrator/CONTRACT.md).

**v1 catalog:** only Ahentic-wired names. Foreign (other-plugin) WP Abilities are not in the agent catalog yet — planned for v2/v3; see [Abilities PRD](../../pro__premium_only/docs/prd/abilities.md) and [future-foreign-abilities.md](../../pro__premium_only/docs/future-foreign-abilities.md).

---

## How to write an ability (checklist)

### 1. Pick runtime

- Needs Gutenberg DOM / editor store / user’s browser cookies → **client** ([client-abilities.md](./client-abilities.md)).
- Pure PHP / WP APIs / public HTTP → **server** ([server-abilities.md](./server-abilities.md)).

### 2. Name and category

- Namespaced: `ahentic/…` or `ahentic-browser/…`
- Lowercase with dashes
- Register category on `wp_abilities_api_categories_init`, ability on `wp_abilities_api_init` (self-hooked at the bottom of your module file)
- Call `Ahentic_Abilities::register_module( __CLASS__ )` so the agent catalog picks you up
- Implement `progress_label( $name )` for Tool runner / sidebar progress copy

### 3. Schemas and meta

```php
wp_register_ability(
	'ahentic/example',
	array(
		'label'               => __( 'Example', 'ahentic' ),
		'description'         => __( 'Clear agent-facing description of when to use this.', 'ahentic' ),
		'category'            => 'ahentic-site',
		'input_schema'        => array( /* draft-04 */ ),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => array( __CLASS__, 'execute_example' ),
		'permission_callback' => static function () {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations'  => array(
				'readonly'    => true,  // Ask mode filter
				'idempotent'  => true,
				// 'destructive' => true,
			),
			'show_in_rest' => false, // Ahentic usually keeps agent tools off public REST run
			// Client-only:
			// 'ahentic' => array( 'runtime' => 'browser' ),
		),
	)
);
```

Write descriptions for the **model**: when to use, required args, what not to do (e.g. prefer browser tools while the editor is open).

### 4. Wire into the catalog

In your module:

- `names()` — all ability name constants
- `write_names()` / `is_readonly()` — Ask mode
- `hitl_names()` / `requires_hitl()` / `hitl_summary()` — mutating tools that need Allow/Deny
- `non_preallowable_names()` / `is_non_preallowable()` — optional; abilities that must never honor session/always allowlists
- `progress_label( $name )` — sidebar / heartbeat copy while the tool runs
- `execute( $name, $input )` — dispatch
- Self-hook WP + `Ahentic_Abilities::register_module( __CLASS__ )` at file bottom

For browser tools: implement `is_browser()` / `browser_summary()` on the browser module and a JS handler in `browser-abilities.js`.

### 5. Load the PHP file

`require_once` from `ahentic.php` **after** `class-abilities.php` (same pattern as existing modules).

### 6. Orchestrator prompt (optional)

Add a short preference line in `system_prompt()` only if routing is non-obvious. Prefer a strong ability `description` first.

### 7. Return shape

Return a JSON-serializable array on success, or `WP_Error` on failure. The orchestrator JSON-encodes the result into a `role: tool` session entry. Include `ok`, `error`, `message`, and a `hint` when the model should change approach.

---

## Annotations the orchestrator cares about

| Concern | Mechanism |
| --- | --- |
| Ask mode | `meta.annotations.readonly` (and module fallbacks) |
| HITL pause | Module `requires_hitl()` — not only Abilities annotations |
| Browser pause | `requires_browser_runtime()` — browser catalog or `http-fetch` + `as_user` |
| Artifacts | `input.from_memory` expanded by orchestrator for supported abilities |

---

## Modes

- **Agent** — all `available_for_agent()` names.
- **Ask** — filtered to readonly. Writes get a synthetic tool error (`ability_ask_readonly`) if planned anyway.

Session-only helpers (e.g. stage/list/delete artifact) are marked readonly so Ask can stage drafts without mutating the site.

---

## Do / don’t

- **Do** keep permission checks real (`current_user_can`).
- **Do** reject placeholders / unsafe input explicitly (`WP_Error` + hint).
- **Do** use [artifacts](../session/artifacts.md) for large drafts (`stage-artifact` → `from_memory`).
- **Don’t** call vendor AI SDKs from abilities — generation stays in the orchestrator / AI Client.
- **Don’t** invent a parallel public tool registry.
- **Don’t** execute browser work in PHP — stub and pause (see client doc).
