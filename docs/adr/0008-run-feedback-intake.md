# Run feedback files via Ahentic intake, not the site’s GitHub identity

Typical admins will not connect GitHub (OAuth/PAT) or finish a prefilled `issues/new` flow. We draft an anonymized **Run feedback report** on-site (AI summary + decluttered **Run feedback debug pack**), search public `run-feedback` issues on the client, and POST to **Run feedback intake** at **`feedback.wpahentic.com`**.

**Hosting (locked):** domain **`wpahentic.com`** via Cloudflare Registrar; intake is a **Cloudflare Worker** + **Turnstile**; code in a **separate private repo** (not the Premium plugin build). Auth: Turnstile on fresh mint only; silent site-token refresh when a token remains. Stateless HMAC (`SERVER_SECRET` on the Worker); no identity DB. Debug packs target remote debugging under GitHub’s 65,536-character body limit.

**Abuse (intake):** new issue creates are rate-limited per client IP (**1/minute** default, best-effort in-isolate). Duplicate comments are not gated by that bucket. GitHub bodies may include a keyed `Intake ip_hash` (not a site identity; raw IP never written). Clients must surface `429` / `rate_limited` clearly and prefer a valid `duplicate_of` when searching finds a match.

Canonical: [`pro__premium_only/docs/prd/run-feedback-intake.md`](../../pro__premium_only/docs/prd/run-feedback-intake.md); stub: [`docs/prd/run-feedback-intake.md`](../prd/run-feedback-intake.md).
