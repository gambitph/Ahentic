# Server-side abilities

Abilities that run entirely in PHP when the Tool runner reaches server execute (`Ahentic_Abilities::execute`). No browser pause unless the ability opts into browser runtime for a specific input (today: `ahentic/http-fetch` with `as_user`).

**Code:** `class-abilities-*.php` (content, plugins, site, settings, media, taxonomy), snapshot in `class-abilities-snapshot.php`, artifacts + settings snapshots / undo in `src/session/`

**Related:** [Abilities overview](./abilities.md) · [Client abilities](./client-abilities.md) · [Orchestrator](../orchestrator/orchestrator.md) · [Orchestrator CONTRACT](../orchestrator/CONTRACT.md)

---

## Execution path

```text
tools_planned includes ahentic/…
  → Orchestrator: availability / Ask filter
  → Ahentic_Tool_Runner::run( name, input )
       → optional auto-stage / from_memory
       → optional HITL (awaiting_human → approvals → run again with skip_hitl)
       → optional browser pause (if runtime requires it)
       → Ahentic_Abilities::execute( name, input )   ← ability dispatch only
       → module execute_* → array | WP_Error
       → assess + JSON tool entry on the session
  → next think
```

Do **not** invent a parallel HITL/browser/execute path in the Orchestrator, REST, or ability modules. Register the ability and let the Tool runner own the pipeline ([orchestrator CONTRACT § Tool branches](../orchestrator/CONTRACT.md)).

The Tool runner wraps execute with `Ahentic_Orchestrator::with_current_session()` so abilities can read page context / artifacts for the in-flight session when needed.

---

## Module pattern (copy this)

```php
class Ahentic_Abilities_Example {
	const LIST = 'ahentic/list-example';
	const WRITE = 'ahentic/update-example';

	public static function names() {
		return array( self::LIST, self::WRITE );
	}

	public static function write_names() {
		return array( self::WRITE );
	}

	public static function is_readonly( $name ) {
		return ! in_array( (string) $name, self::write_names(), true );
	}

	public static function hitl_names() {
		return array( self::WRITE );
	}

	public static function requires_hitl( $name ) {
		return in_array( (string) $name, self::hitl_names(), true );
	}

	public static function hitl_summary( $name, $input = array() ) {
		// Short Allow-card copy — no huge bodies.
		return __( 'Update example setting', 'ahentic' );
	}

	public static function progress_label( $name ) {
		if ( self::LIST === (string) $name ) {
			return __( 'Listing examples…', 'ahentic' );
		}
		if ( self::WRITE === (string) $name ) {
			return __( 'Updating example…', 'ahentic' );
		}
		return '';
	}

	public static function register_category() { /* wp_register_ability_category */ }

	public static function register() { /* wp_register_ability for each */ }

	public static function execute( $name, $input = array() ) {
		switch ( $name ) {
			case self::LIST:
				return self::execute_list( $input );
			case self::WRITE:
				return self::execute_write( $input );
			default:
				return new WP_Error( 'ahentic_ability_unknown', /* … */ );
		}
	}
}

// Outside the class_exists guard — self-hook WP + join the catalog:
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Example', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Example', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Example' );
}
```

Then `require_once` the file from `ahentic.php` **after** `class-abilities.php`. Do **not** add another `class_exists` fan-out in the facade — the catalog picks the module up via `register_module`.

---

## HITL (human in the loop)

Mutating site changes should pause for Allow / Deny:

1. Add the name to `hitl_names()`.
2. Implement `hitl_summary( $name, $input )` for the sidebar card.
3. The Tool runner sets `pending_tool` + `awaiting_human` (do not reimplement this in a new Orchestrator branch).
4. On allow: Tool runner continues (`skip_hitl`) — PHP execute or browser pause if `requires_browser_runtime`.
5. Session / always-allow lists can skip repeat prompts (`hitl_is_preallowed`).
6. **Non-preallowable** abilities (e.g. future user writes): implement `non_preallowable_names()` / `is_non_preallowable()` so session/always allowlists are ignored and `allow_session` / `always_allow` decisions are rejected (`Ahentic_Abilities::hitl_choice_allowed`).

Do **not** put full post bodies or block trees in the HITL summary. Prefer title, id, artifact key (`from_memory`).

### Settings snapshot + undo (ADR-0007)

Before writing theme settings / options / global styles / template parts / media (no WP revisions), call `Ahentic_Settings_Snapshots::record( $session_id, … )` with `prior_existed` distinct from empty values. Register a restore callback via `Ahentic_Settings_Snapshots::register_restore( $ability, $callback )`. Agents undo via `ahentic/undo-last-actions`.

---

## Input / output practices

- Validate and sanitize; return `WP_Error` with a stable `error` code and a model-facing `hint` when useful.
- Cap large reads/writes (see content module max chars).
- Reject placeholder stubs (`[full article]`, etc.) so the model cannot claim success on empty work.
- Prefer returning structured fields the next think can reuse (ids, slugs, urls) rather than prose-only.

### Editor vs server content

If the block editor is open for the same post, server body writes should fail with `ahentic_use_browser_editor` and steer the model to `ahentic-browser/*`. Use session `page_context` (`Ahentic_Orchestrator::current_session_id()` + `get_page_context`).

### Large drafts

Stage with `ahentic/stage-artifact`, then `create-post` / `update-post` with `from_memory`. The Tool runner expands before execute. See [artifacts.md](../session/artifacts.md).

---

## Special case: `http-fetch`

- Public URLs → server HTTP API.
- `as_user: true` (same-site / wp-admin) → `requires_browser_runtime` → sidebar credentialed `fetch`.
- Do not pretend a headless logged-in fetch works without the sidebar.

---

## Categories in use

| Category | Examples |
| --- | --- |
| `ahentic-site` | Snapshot, health, options, http-fetch, admin context, debug log |
| `ahentic-settings` | get-settings-context, list-settings, get-setting, update-theme-setting, update-global-styles |
| `ahentic-content` | list/get/search/create/update/set-status |
| `ahentic-plugins` | list/search/install/activate/deactivate/uninstall |
| `ahentic-media` | unused media scan, … |
| `ahentic-taxonomy` | update term, … |
| `ahentic-session` | stage/list/delete artifacts |

---

## Testing a new server ability

Full policy: [`docs/agents/testing.md`](../../docs/agents/testing.md).

1. **Split decision logic from WP I/O where practical** (heuristic flags, a
   diff/dry-run preview, a snapshot-entry shape) and cover the pure function
   in PHPUnit (`tests/unit/`). Never grow `tests/bootstrap.php`'s stubs to
   make WP-dependent code fit in PHPUnit instead — that behaviour belongs in
   the Playwright suite.
2. **Add or extend a Playwright module spec** (`tests/e2e/specs/`, grouped by
   `tasks/mvp-abilities` track, not one file per ability) calling the ability
   through `runAbility()` (`tests/e2e/utils/ability-client.js`) against a real
   (if WASM, via `@wp-playground/cli`) WordPress — no LLM turn needed. That hits
   `Ahentic_Abilities::execute` (dispatch), not the Tool runner. Confirm:
   - It appears in `Ahentic_Abilities::available_for_agent()` and, if
     readonly, in `available_for_mode( 'ask' )`; a write is blocked/absent in
     Ask mode.
   - HITL abilities report `requires_hitl()` true; failure paths return tool
     JSON the model can adapt to, not a silent fatal. (Full Allow/Deny +
     pause/resume is characterized in `orchestrator-pipeline.spec.js`.)
   - If it touches content while the editor is open, assert the
     browser-routing error.
3. Allow/Deny UX itself (the sidebar card) is out of scope for a new
   ability's own spec — that's covered once by the small browser-driven HITL
   tier, not per-ability. Do **not** reimplement HITL/browser around `execute`
   in production code; register flags and let the Tool runner own the pipeline.
