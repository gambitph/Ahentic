# Ahentic end-user docs site

Public help for the Ahentic WordPress plugin.

**Live URL:** https://docs.wpahentic.com  
**Pages project:** `ahentic-docs` (`https://ahentic-docs.pages.dev`)  
**Source of truth for copy:** [`getting-started.md`](./getting-started.md)

This folder is **not** agent/ADR product docs (`docs/` at repo root).
It is the beginner Help page linked from the plugin sidebar.

---

## Hosting (locked)

| Item | Value |
| --- | --- |
| Product | Cloudflare **Pages** only (static HTML/CSS/SVG) |
| Cloudflare account | Same account as `wpahentic.com` / `feedback.wpahentic.com` |
| Account ID | `291664ba98b37f010f5ac1bef462d9d6` |
| Project name | `ahentic-docs` |
| Git | Connected to **`gambitph/Ahentic`** (GitHub) |
| Production branch | `main` |
| Root directory | `docs-site` |
| Build command | `npm run build` |
| Build output directory | `dist` |
| Custom domain | `docs.wpahentic.com` (CNAME `docs` → `ahentic-docs.pages.dev`, proxied) |

**Do not** add Pages Functions, `wrangler.toml` bindings, D1, KV, R2, Durable Objects, Queues, Workers AI, or other Cloudflare services for this site.

Dashboard: [Workers & Pages → ahentic-docs](https://dash.cloudflare.com/291664ba98b37f010f5ac1bef462d9d6/pages/view/ahentic-docs)

Every push to `main` that touches this tree triggers a Pages build.
PR branches get preview deployments when Git is connected.

Plugin Help / Guides use `https://docs.wpahentic.com` (`docsUrl` in `src/admin/class-script-loader.php` and sidebar fallbacks).

---

## Local build

```bash
cd docs-site
npm install
npm run build    # markdown + Spruce SCSS → dist/
npm test         # build + smoke checks
```

From the free repo root:

```bash
npm run docs:build
npm run docs:test
```

Open `dist/index.html` in a browser, or:

```bash
npx --yes serve dist
```

Edit **`getting-started.md`** for copy.
Edit **`scss/main.scss`** for chrome/theme.
Do not hand-edit `dist/`. It is generated (and gitignored).

Stack: [Spruce CSS](https://sprucecss.com/) (Sass) + `marked` via [`build.mjs`](./build.mjs).

---

## Manual deploy (optional)

Prefer Git auto-deploy.
For a one-off upload of a local `dist/` (same project, still static-only):

```bash
cd docs-site
npm run build
npx wrangler pages deploy dist --project-name=ahentic-docs --branch=main
```

---

## Related

- Feedback intake Worker (separate repo/service): `feedback.wpahentic.com`
- Marketing / Plugin URI: `https://wpahentic.com`
