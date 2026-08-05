# Task 08 — `ahentic/update-theme-setting`

**Track:** C — needs Task 01 (snapshot store) and Task 07 (registry index to validate against)
**Source:** [site-settings.md § `ahentic/update-theme-setting`](../../pro__premium_only/docs/prd/site-settings.md#ahentic-update-theme-setting-customizer-theme_mod-and-option-backed-settings)

## Current state

No write path exists. For reference, this is what the write has to go through correctly — Blocksy (representative classic theme) registers real `WP_Customize_Setting` objects with a theme-supplied sanitizer:

```759:767:blocksy/inc/customizer/init.php (theme file, not this repo)
if ($is_allowed) {
    $wp_customize->add_setting($opt['id'], array_merge([
        'sanitize_callback' => function ($input, $setting) {
            return $input;
        }
    ], $args_setting));
}
```

and reads via a plain `get_theme_mods()` cache with a `theme_mod_{name}` filter:

```8:29:blocksy/inc/classes/database.php (theme file, not this repo)
public function get_theme_mod($name, $default_value = false) {
    if ( is_admin() || is_customize_preview() || wp_doing_ajax() || $this->mods === '__EMPTY__' ) {
        $this->mods = get_theme_mods();
    }
    // ...
    return apply_filters("theme_mod_{$name}", $value);
}
```

## What's missing

The write ability itself, plus the merge-by-path logic nested Customizer values (e.g. Blocksy's `header_placements`) need to avoid clobbering.

## Scope

- Input: `changes: [{ id, path?, value, replace? }]`, `dry_run?`.
- **Bootstrap for real** — unlike Task 07's cached read, a write must instantiate the live `WP_Customize_Manager` and call the setting's actual `sanitize()` (and, where present, `validate()`) so the theme's own validation runs — do not just call `set_theme_mod()` directly. This is acceptable cost because writes are already HITL-gated.
- **Reject unknown ids** — cross-check against Task 07's cached index; a request to write an id not in the registry fails with a clear error (the model doesn't get to invent theme mod names).
- **Nested merge, not replace.** When `path` is given (e.g. `header_placements.sections[0].items`), merge into the existing structure at that path. A bare `value` for the whole setting id is refused unless `replace: true` is explicitly passed — this stops a partial intent ("add a search icon to the header") from silently deleting the rest of the builder structure.
- **Code-bearing exclusion enforced here too**, not just in discovery — even if a caller somehow has a code-bearing id, the write path independently refuses `custom_css[...]`, code-editor-control ids, and anything gated on `edit_css`/`unfiltered_html`, returning the same upsell-shaped refusal Task 07 uses for discovery.
- **Snapshot before write** (Task 01's store): record the prior value for each changed id/path before applying.
- Capability: `edit_theme_options`.
- Standard HITL (not non-preallowable) — this is the "Standard HITL" tier per site-settings.md's table.
- Register the restore path with Task 01's `undo-last-actions` dispatcher (write the prior value back through the same sanitize path).

## Out of scope

- Global styles (Task 09) and template parts (Task 10) — different storage, different modules.
- Page-scoped Custom HTML block fallback when no control exists — that's existing Free content-editing capability, not a new ability; this task's ability simply refuses and returns the structured upsell when there's no matching setting, and the orchestrator/model decides whether to offer the fallback.

## Acceptance criteria

- [ ] Write goes through the setting's real `sanitize_callback`, not a raw `set_theme_mod()`
- [ ] Rejects ids absent from the Task 07 registry index
- [ ] Rejects whole-object replacement of a nested value unless `replace: true`
- [ ] Independently refuses code-bearing ids at write time (not just relying on discovery filtering)
- [ ] Snapshots prior value before writing; restorable via `undo-last-actions`
- [ ] `dry_run: true` reports the diff without writing
