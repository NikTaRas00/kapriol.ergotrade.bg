# Product descriptions — match & deploy pipeline

New rich product descriptions (from `deploy/product-descriptions.md`) were matched
to the 334 catalog products and stored as a per-product **`fullDescription`** HTML field.
The product page renders it (sanitised) in place of the old plain-text `overview`; products
without a match fall back to `overview`.

**Coverage:** 316 / 334 matched. The 18 with no catalog description are listed at the
bottom of `_review.md` (left blank — author later if copy becomes available).

## Files — all live in `deploy/` (local-only; none are uploaded by the FTP deploy)
- `product-descriptions.md` — **the source catalog copy** (1,132 described entries).
  Kept here on purpose: it must NOT live in `Share Folder/`, which is a disposable
  drop-zone that gets wiped periodically.
- `desc-lib.js` — parser + HTML cleaner + fuzzy name scorer.
- `_overrides.json` — hand-curated `slug → md-name` (or `null` = leave blank) for every
  case the fuzzy matcher got wrong or tied. **Edit this** to correct a match.
- `resolve-descriptions.js` — applies overrides + fuzzy top-1, writes:
  - `descriptions.json` — the merge payload (`slug → { name, html }`).
  - `_review.md` — human review table grouped by category.
- `merge-host-descriptions.js` — merges the payload into a copy of the LIVE host file.

To regenerate after editing `_overrides.json`:
```
node deploy/resolve-descriptions.js
node -e "const fs=require('fs');const f='assets/data/products.json';const p=JSON.parse(fs.readFileSync(f));const d=require('./deploy/descriptions.json');for(const x of p){if(d[x.slug])x.fullDescription=d[x.slug].html;else delete x.fullDescription;}fs.writeFileSync(f,JSON.stringify(p,null,2));"
```
(then bump the JS/CSS `?v=` if you also changed render code, and re-test the preview).

## Getting the descriptions onto the LIVE site

The host `assets/data/products.json` is admin-edited and diverges from local, and the FTP
deploy never uploads `assets/data` — so we **must not** push the local file. Instead, merge
by slug into a copy of the live file (adds/updates only `fullDescription`, every other host
field is left exactly as-is):

1. **Download** the live file via cPanel File Manager (or FTP). The site sits at the
   `kapriol.ergotrade.bg` subdomain ROOT, so the file is at
   `<subdomain docroot>/assets/data/products.json` → save locally, e.g. `host-products.json`.
2. **Merge:**
   ```
   node deploy/merge-host-descriptions.js host-products.json
   ```
   This writes `host-products.merged.json` and prints how many were added/updated and any
   payload slugs missing on the host (review those — means a slug differs).
3. **Upload** `host-products.merged.json` back over the host `products.json`
   (rename to `products.json` on upload). The `.json` is served `no-cache`, so it goes live
   immediately. **Deploy the code changes** (`main.js`/`style.css`/HTML — bump their `?v=`
   cache strings first) by double-clicking **`Deploy.cmd`** in the project root, so the page
   can render the HTML.

Tip: back up the host file before overwriting (keep the original `products.json` download).
