# Control block & prompt contract

Protocol between the **LLM** and the **orchestrator**. Ahentic does **not** use native provider tool-calling. The model must emit a structured `AHENTIC_DEBUG` block; the orchestrator parses it and drives tools / reply / HITL.

> **Canonical should:** [Agent runtime PRD](../../pro__premium_only/docs/prd/agent-runtime.md) · **Contract:** [CONTRACT.md](./CONTRACT.md)  
> **Plan rule (product law):** require a plan in Agent mode when ≥2 tools are planned **or** any write runs. Older “≥3 coarse steps” guidance below describes **current prompt heuristics** and must be aligned to the contract.

**Verification:** Agent writes verify themselves from their own return payload — never a readonly follow-up. Long-form runs must not idle with a body under the floor — see Agent runtime PRD.

**Code:** `Ahentic_Prompt_Assembler::for_llm()` / `system_prompt()`, Orchestrator debug parse/retry helpers  
**Related:** [orchestrator.md](./orchestrator.md) · [abilities.md](../abilities/abilities.md) · [artifacts.md](../session/artifacts.md)

---

## Wire format

Every model response for a think step should start with **exactly one** control block, then a short user-facing reply:

```text
<<<AHENTIC_DEBUG
{"intention":"Checking installed plugins","thinking":"1-3 sentences of thought process.","plan":null,"tools_planned":[{"name":"ahentic/list-plugins","input":{}}],"next":"use_tools"}
AHENTIC_DEBUG>>>

Optional short message the user can read.
```

Closing marker: `AHENTIC_DEBUG` followed by exactly `>>>`.

If the block is missing or `next` is invalid, the orchestrator **retries internally** (up to `MAX_DEBUG_ATTEMPTS`) without asking the user to continue.

---

## Fields

| Field | Required | Purpose |
| --- | --- | --- |
| `intention` | yes | Short present-tense live status (“Reading editor blocks”) — UI progress |
| `thinking` | yes | 1–3 sentences shown in the sidebar chat as thought process |
| `tools_planned` | when using tools | Strings (`"ahentic/…"`) or objects `{ "name", "input" }` |
| `next` | yes | `reply` \| `ask_user` \| `use_tools` \| `missing_ability` |
| `plan` | optional | Multi-step checklist for the plan card / system prompt |
| `ability_needed` | when missing | Ability slug(s) the product does not have yet |

### `next` semantics

| Value | Orchestrator behavior |
| --- | --- |
| `use_tools` | Run `tools_planned` (HITL / browser / PHP as needed), then another think |
| `reply` | Finish run; show assistant text; status → idle |
| `ask_user` | Finish run waiting on the user (clarifying question) |
| `missing_ability` | Finish + queue capability request; do not pretend the tool ran |

**HITL note:** For mutating tools that already pause for Allow/Deny, prefer `next=use_tools` with that ability in `tools_planned` — do not use `ask_user` only to “confirm” the same action.

---

## `tools_planned`

Prefer objects with input:

```json
{ "name": "ahentic/get-content", "input": { "id": 123 } }
```

Bare name strings are normalized to `{ "name", "input": {} }`.

Caps: at most `MAX_TOOL_PROGRESS` tools per think; unknown names become tool-error entries (or Ask-mode write blocks).

### `from_memory`

For large drafts, stage first (`ahentic/stage-artifact`), then:

```json
{ "name": "ahentic-browser/set-blocks", "input": { "from_memory": "article_draft" } }
```

Supported apply targets today: `set-blocks`, `create-post`, `update-post`. Do not re-paste the full artifact body into `tools_planned`.

---

## `plan`

**Required in Agent mode** when the model intends **≥2 tools** or **any write** (non-readonly). A single readonly tool may omit the plan. Omit for simple Ask answers.

Rules enforced in prompt + merge logic:

- Re-send the **full** plan each think (including completed steps)
- Exactly one `in_progress` at a time
- Cap length (`Ahentic_Plan::MAX_PLAN_STEPS`)
- Plan is UI/orchestrator state — **not** a substitute for `thinking` / chat narration
- If the model skips a required plan, the orchestrator retries once then synthesizes a minimal plan

---

## What the model sees each think

Assembled approximately as:

1. **System prompt** — role, available abilities for mode, routing rules, admin link map, optional current plan
2. **History** — prior user/assistant turns (capped)
3. **Latest user message** plus:
   - Active **page context** (URL / editor / post id)
   - **Artifact pointers** (keys/status only; drafting vs ready)
   - **Ability results from this run** (tool JSON, truncated ~8k each)

Tool results are facts, including for writes: a write result reports what it persisted, so no readonly follow-up is called to confirm it. A long-form write that lands under the floor comes back marked `thin` with a `thin_reason` telling the model to keep writing — see the Agent runtime PRD.

---

## Ask vs Agent (prompt contract)

| Mode | Tools in system prompt | Expected behavior |
| --- | --- | --- |
| **Agent** | Full list | Use tools; HITL for listed mutators |
| **Ask** | Readonly only | Never claim site changes; tell user to switch to Agent for writes |

If a write is planned in Ask, the orchestrator injects `ability_ask_readonly` tool error — the model should explain, not invent success.

---

## Editing guidance (for developers)

| Change | Where |
| --- | --- |
| Ability availability / descriptions | Ability registration modules (preferred) |
| Global routing (“editor vs server”, artifacts, refs) | `Ahentic_Prompt_Assembler::system_prompt()` in `class-prompt-assembler.php` |
| Caps / retries | Class constants (`MAX_STEPS_PER_RUN`, `MAX_DEBUG_ATTEMPTS`, …) |
| Debug parse / normalization | Helpers near `run_llm_with_debug` / `normalize_tool_calls` |

Keep `system_prompt()` concise. Prefer rich ability `description` text for tool-specific rules so the catalog stays the source of truth.

---

## Anti-patterns

- Emitting tools via prose only (“I will call list-plugins”) without `next=use_tools` + `tools_planned`
- Pasting huge article bodies into every think instead of artifacts + `from_memory`
- Inventing `ahentic-browser` refs (`b1`) or Gutenberg `clientId` hashes
- Using `ask_user` to confirm an ability that already has HITL
- Claiming a write succeeded without a successful tool result in the transcript
