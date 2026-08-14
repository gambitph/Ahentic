# Run feedback files via Ahentic intake, not the site’s GitHub identity

Typical admins will not connect GitHub (OAuth/PAT) or finish a prefilled `issues/new` flow.
We draft an anonymized **Run feedback report** on-site from the sidebar (one LLM pass for title + summary + hypothesis, plus a decluttered **Run feedback debug pack**), search public `run-feedback` issues on the client, and POST to **Run feedback intake** at **`feedback.wpahentic.com`**.
The file route (`POST /ahentic/v1/feedback/reports`) does not call a model; `POST /ahentic/v1/feedback/draft` is the think step.

**Hosting (locked):** domain **`wpahentic.com`** via Cloudflare Registrar; intake is a **Cloudflare Worker**; code in a **separate private repo** (not the Premium plugin build).

**Auth:** Fresh mint requires a **mint proof** — a shared HMAC formula (`MINT_KEY`) both the Ahentic plugin and the Worker can compute and verify.
That replaces Cloudflare Turnstile: free Turnstile widgets cannot authorize arbitrary customer WordPress hostnames (`110200`).
After mint, the site stores a stateless **site token** (`SERVER_SECRET` HMAC on the Worker only); silent refresh when a token remains; reports use a valid site token.
No identity DB.
Debug packs are scrubbed on-site, capped (2 MiB), and filed by intake as a **GitHub issue file attachment** (not inlined into the 65,536-character issue body).

**Abuse (intake):** new issue creates are rate-limited per client IP (**1/minute** default, best-effort in-isolate).
Duplicate comments are not gated by that bucket.
GitHub bodies may include a keyed `Intake ip_hash` (not a site identity; raw IP never written).
Clients must surface `429` / `rate_limited` clearly and prefer a valid `duplicate_of` when searching finds a match.

Canonical: [`pro__premium_only/docs/prd/run-feedback-intake.md`](../../pro__premium_only/docs/prd/run-feedback-intake.md); stub: [`docs/prd/run-feedback-intake.md`](../prd/run-feedback-intake.md).
