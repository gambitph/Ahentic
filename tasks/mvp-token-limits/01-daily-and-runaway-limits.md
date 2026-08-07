# MVP — Daily token limit + runaway lock

**When:** MVP (free plugin)
**Status:** Ready to implement (grill settled)
**Depends on:** Existing `Ahentic_Usage` daily rollup + session `add_tokens()`; Settings → Ahentic stub

> Grilled decisions are locked below. Re-grill only if product intent changes.

## Goal

Protect site owners from runaway agent token spend (loops, bugs, accidents) with:

1. A **site-wide daily token limit** (reasonable default; user-editable)
2. A **runaway lock** after the daily limit is enforced on **3 consecutive site-timezone days**

Lightweight Settings UI so users can raise the daily cap and unlock after a runaway lock. Not a freemium gate — safety backstop only.

## Settled decisions

| # | Decision |
| --- | --- |
| Scope | **Site-wide** pool (all admins share budget) |
| Metric | Session/provider **`total`** tokens |
| Day clock | **Site timezone** (`wp_timezone()`) for limit/streak enforcement |
| Daily default | **1,000,000** total tokens / site / day |
| Overall tokens field | **None** — runaway = consecutive daily-limit days only |
| Streak length | **3** consecutive hit-days (hard-coded, not a setting) |
| Hit-day | Day counts only if **enforcement fired** that day (stop/refuse because at/over limit) |
| Mid-day raise | Raising daily limit above today’s usage **clears the daily block immediately**; if enforcement already fired today, today **still counts** toward the streak |
| Runaway unlock | Explicit **“Acknowledge & unlock”** (or equivalent) in Settings; **resets streak to 0**. Raising daily alone does **not** clear runaway |
| Unlimited kill-switch | **No** — set a very high daily limit after unlock if needed |
| Overshoot | **Post-call account** (in-flight LLM may finish and count); **pre-flight refuse** next orchestrator step / new prompt |
| Daily blast radius | Cancel site-wide **running / queued** sessions (same path spirit as user Stop); **refuse new prompts**; do **not** auto-cancel `awaiting_human` waits |
| Runaway blast radius | **Same cancel/refuse as daily**; stickier only until unlock |
| Blocked UX | Distinct copy for **daily** vs **runaway**; **thread system/assistant message** + composer/toast; point to Settings (`ahentic.settingsUrl` / options page) |
| Settings MVP | Daily limit field + **today’s usage / limit** progress; unlock CTA when locked. **No** multi-day graph in this task |
| UTC rollup | Keep existing `ahentic_token_stats_daily` (UTC) for graphs later; **enforcement uses site-tz accounting** (new keys/option as needed — no big migration science project) |
| Free / Directory | Safety copy only; **no Premium upsell** on this UI |
| Proof | **PHP unit/integration** for limit check, streak, unlock, resume-after-raise. E2E optional follow-up |

## Behavior

### Daily limit

- Before starting / continuing work that would call the model: if runaway locked → refuse; else if today’s site-tz `total >= daily_limit` → refuse / stop runs.
- After each token bump: if newly at/over limit → mark **hit-day**, cancel running/queued site-wide, append limit message to affected viewing sessions (at least the session that crossed), refuse further prompts for the rest of the day (until limit raised above today’s usage).
- Stopped sessions stay stopped; user must send a new prompt after raising the limit.

### Runaway lock

- Persist streak of consecutive **site-tz calendar days** that were hit-days.
- A calendar day with **no** enforcement does not extend the streak (breaks it).
- On the 3rd consecutive hit-day: enter runaway lock → same cancel/refuse as daily; prompts stay blocked until Settings unlock.
- Unlock: clear lock + reset streak to 0; does not rewrite historical usage.

### Settings → Ahentic

Replace stub with at least:

- Daily token limit (number; default `1000000` on first install / empty option)
- Today’s usage vs limit (site-tz)
- When runaway locked: status explanation + **Acknowledge & unlock** button
- Capability: `manage_options`
- Framing: protect against unexpected spend; user may increase freely

## Implementation notes (non-normative)

- Deepen `Ahentic_Usage` (or a thin sibling) rather than a parallel quota system.
- Single gate used by orchestrator step loop + session prompt REST so nothing bypasses the check.
- Reuse `Ahentic_Orchestrator::cancel()` (or shared helper) for site-wide running/queued cancel.
- Error / event codes should distinguish `daily_limit` vs `runaway_lock` for sidebar copy.
- Options: store limit + streak/lock state in plugin options; don’t put budgets in `localStorage`.

## Out of scope

- Per-user budgets
- Overall / lifetime token field
- USD / provider cost limits
- Editable streak length or “disable limits” toggle
- Multi-day usage graph (reuse `GET /stats/tokens` later)
- Composer session usage ring ([`future-sidebar-usage.md`](../../pro__premium_only/docs/future-sidebar-usage.md))
- Soft warnings before the cap
- Premium upsell on the limit screen

## Acceptance criteria

- [ ] Fresh install: daily limit defaults to **1,000,000**; no runaway lock
- [ ] Settings page: edit/save daily limit; shows today usage/limit; unlock CTA only when locked
- [ ] When today’s total reaches the limit: running/queued sessions cancel; new prompts refused; session UI shows daily-limit copy + path to Settings
- [ ] Raising limit above today’s usage allows new prompts the same day; prior stopped sessions do not auto-resume
- [ ] A day where enforcement fired counts as a hit-day even if the user later raises the limit and continues
- [ ] Three consecutive hit-days → runaway lock; prompts refused until Acknowledge & unlock; unlock resets streak
- [ ] A day with no enforcement breaks the consecutive streak
- [ ] `awaiting_human` is not auto-cancelled solely by the limit trip
- [ ] No Premium / upsell copy on this settings surface
- [ ] PHP tests cover: under-limit allow, daily trip, raise-and-resume, streak increment/break, runaway lock, unlock reset
