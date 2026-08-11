# Explain a codebase concept

Use the **`explain-codebase-concept`** skill (`.cursor/skills/explain-codebase-concept/`) when you want a beginner-friendly, cross-stack walkthrough of **one** Ahentic concept, feature, or subsystem.

## What you get

- A short chat summary (where to look, how you’d usually change it)
- A visual HTML report (Tailwind + Mermaid): story → wiring → cast → step-through → change map
- Optionally, a durable copy under [`docs/walkthroughs/`](../walkthroughs/) for humans catching up on how Ahentic works

## When to use it

- “How does HITL work?”
- “Onboard me on the orchestrator”
- “Walk me through sending a chat message”
- “Explain session sync in the sidebar”

## When not to

| Need | Instead |
| --- | --- |
| Whole-product map | [`docs/architecture.md`](../architecture.md) |
| Deepening / refactor opportunities | `improve-codebase-architecture` |
| Something broken | `diagnosing-bugs` |
| Product “should” | PRDs under `pro__premium_only/docs/prd/` |
| Must-guarantee interfaces | `src/**/CONTRACT.md` |

## Authority

Walkthroughs **compose** the doc ladder (CONTEXT → architecture → CONTRACT → how-it-works → PRD/ADR) and live code. They do not invent product law. Committed HTML under `docs/walkthroughs/` is pedagogical; regenerate when the slice drifts rather than maintaining a parallel truth.
