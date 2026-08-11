# Run feedback intake

**Canonical product should:** [`pro__premium_only/docs/prd/run-feedback-intake.md`](../../pro__premium_only/docs/prd/run-feedback-intake.md) (Premium checkout).

**Glossary:** [`CONTEXT.md`](../../CONTEXT.md) · **ADR:** [`docs/adr/0008-run-feedback-intake.md`](../adr/0008-run-feedback-intake.md)

**Host:** Cloudflare Worker at `https://feedback.wpahentic.com` (`wpahentic.com` on Cloudflare Registrar).

**Auth:** Fresh mint uses a shared **mint proof** (HMAC formula both plugin and Worker verify).
Site tokens remain the enrolled-site credential; refresh is silent; reports use a valid site token.
No Cloudflare Turnstile on customer wp-admin hostnames (free Turnstile cannot authorize arbitrary sites).

**API (summary):** `GET /healthz` · `POST /v1/site-tokens` (mint proof) · `POST /v1/site-tokens/refresh` (silent) · `POST /v1/reports` (create or comment+`+1` with debug pack).

**Rate limits (client-visible):** mint/refresh/report may return `429` / `rate_limited`.
**New issues** are additionally limited to **1 create per client IP per minute** (default); commenting on a valid duplicate is not gated by that limit.
Prefer proposing `duplicate_of` when public search finds an open `run-feedback` issue.

This free-repo stub exists so links resolve when Premium is not checked out; do not duplicate full service law here.
Align with intake `docs/api.md` in `gambitph/ahentic-feedback-intake`.
