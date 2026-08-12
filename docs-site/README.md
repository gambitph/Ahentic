# Ahentic end-user docs site

Source for **https://docs.wpahentic.com** (Cloudflare Pages).

Built with [Spruce CSS](https://sprucecss.com/) (Sass) plus a small docs layout.
Content lives in **`getting-started.md`**.

## Local build

```bash
cd docs-site
npm install
npm run build    # markdown + Spruce SCSS → dist/
npm test         # build + smoke checks
```

Open `dist/index.html` in a browser, or serve the folder:

```bash
npx --yes serve dist
```

Edit **`getting-started.md`** for copy.
Edit **`scss/main.scss`** for chrome/theme.
Do not hand-edit `dist/`. It is generated.

## Cloudflare Pages

1. Workers & Pages → Create → Pages → Connect to Git → `gambitph/Ahentic`
2. Project settings:
   - **Production branch:** `main`
   - **Root directory:** `docs-site`
   - **Build command:** `npm run build`
   - **Build output directory:** `dist`
3. Custom domains → add `docs.wpahentic.com` (DNS on the same Cloudflare account as `wpahentic.com`)

Plugin Help / Guides links use `https://docs.wpahentic.com`.
