# FTP code deploy — kapriol.bg

Quick **code-only** FTP push over plain FTP (port 21). Uploads HTML/CSS/JS/PHP +
the `admin/` folder and **never** touches the host's live data, photos, video,
or secrets.

## What gets uploaded

Allowlist (see top of `ftp-deploy.ps1`):

- Root: `index.html`, `products.html`, `product.html`, `contact.html`,
  `send-mail.php`, `track.php`, `sitemap.php`, `.htaccess`, `robots.txt`,
  `llms.txt`
- Code-managed images, listed individually so the rest of `assets/img/` is never
  touched: `ergotrade-logo.png`, `logo-kapriol.svg`, and the favicon set
  (`favicon.ico`, `favicon-16.png`, `favicon-32.png`, `apple-touch-icon.png`).
  `favicon-512.png` is deliberately **not** in the allowlist — nothing
  references it.
- `assets/css/`, `assets/js/`, `assets/fonts/` (recursive)
- `admin/` (recursive)
- `error/` (recursive — the 10 styled ErrorDocument pages)

**Always excluded** (host-owned — overwriting them would wipe secretary edits):
`assets/data/`, `assets/img/`, `assets/video/`, `private/`.

## Run it

**Double-click `Deploy.cmd`** in the project root (or `Deploy-Preview.cmd` for a
dry run that uploads nothing). Those are the intended entry points.

To run the script directly instead, from the project root:

```powershell
# preview the file list, upload nothing
powershell -ExecutionPolicy Bypass -File deploy/ftp-deploy.ps1 -DryRun

# real deploy
powershell -ExecutionPolicy Bypass -File deploy/ftp-deploy.ps1
```

## Credentials

First run with no config: the script prompts for **host**, **username**, and
**remote target dir**, and offers to save them to `deploy/ftp.config`
(gitignored). The **password is prompted each run** and never written to disk.

Current live values (the site sits at the `kapriol.ergotrade.bg` subdomain ROOT):

```
Host=ftp.ergotrade.bg
User=ftp1@kapriol.ergotrade.bg
RemoteBase=/                  # this FTP account is chrooted to the subdomain docroot
```

> `RemoteBase=/` is correct **because** the account is chrooted — it already lands
> inside the subdomain's docroot. If you ever deploy with a different account, run
> `-DryRun`, check the printed `ftp://host/<path>/`, and adjust.
>
> Because that account is chrooted, `private/` cannot be pushed with it — upload
> `private/` via cPanel File Manager or the main account, as a **sibling** of the
> docroot (outside `public_html`).

To skip the password prompt too, add a `Pass=...` line to `ftp.config`
(convenient but less safe — the file is gitignored, so it stays out of git).

## After deploying

If you changed `style.css` or `main.js`, remember to **bump the `?v=N`** cache
strings on the `<link>`/`<script>` tags across all four HTML pages, or the
1-year immutable cache serves the old asset.

---

# Data tooling (separate from the deploy)

The deploy **never** uploads `assets/data/`, so catalog data reaches the host by a
different route: download the live `products.json`, merge one field into it
locally, upload it back. Each script below only touches its own fields and passes
every other host key through untouched.

`live-snapshot/` holds the most recent download of the live `products.json` +
`attributes.json`. It is both the input for these scripts and the pre-change
backup. Re-download before every merge — the host copy is admin-edited and drifts.

## Spec codes — `extract-sheet-specs.py`  (Python, needs `pip install pymupdf`)

Reads `datasheets-by-slug/<slug>.pdf`, pulls each shoe's **Technology** and
**Performance** pictogram captions, matches them to codes in the live
`attributes.json` (by code or by title), and writes
`live-snapshot/products.specs-merged.json`.

```bash
python deploy/extract-sheet-specs.py
```

Footwear only. Writes `performance` + `technologies`; **never** `safetyClass`.
Any caption with no matching code is reported instead of guessed — add the code
in Admin → Codes (matching on the **title**) and re-run. See
`docs/spec-codes-howto.md`.

**Status: applied.** All 46 shoes carry codes on the live site.

## Product photos — `merge-host-images.js`

```bash
node deploy/merge-host-images.js <downloaded-host-products.json>
```

Sets `img` (main shot), `images[]` (gallery) and `imgV` (cache-buster) by slug
from a prepared image map, then you upload the `*.merged.json` plus the image
files themselves into `assets/img/products/`.

`--payload=<_img-map.json>` picks which image set to merge; the default is the
footwear map (`_RENAMED_FOR_UPLOAD/_img-map.json`).

**Status: footwear done** (46/46 live). **Clothing is matched and waiting to be
uploaded** — see below. PPE + ppe-accessories have no photo drop at all.

### Preparing a drop — `match-workwear-images.js`

```bash
node deploy/match-workwear-images.js            # dry run, prints the report
node deploy/match-workwear-images.js --write    # rename + downscale + write the map
```

Matches `WORKWEAR/WEBP ALL/*.webp` to slugs by the 5-digit article code in the
filename, orders each product's shots, downscales to ≤1600px WebP q82 (ffmpeg),
and writes `full-range-25-26-v1/_RENAMED_WORKWEAR/` (renamed files +
`_img-map.json`, `_mapping.csv`, `_review.html` contact sheet, `_unmatched.md`).
Bad source filenames are corrected in the `OVERRIDES` map at the top of the script.

**Upload steps for this drop:**

1. Download the live `assets/data/products.json` over the older
   `deploy/live-snapshot/products.json`.
2. `node deploy/merge-host-images.js deploy/live-snapshot/products.json --payload=full-range-25-26-v1/_RENAMED_WORKWEAR/_img-map.json`
3. Upload `products.merged.json` over the host `assets/data/products.json`.
4. Upload `full-range-25-26-v1/workwear-photos-for-upload.zip` (30.6 MB) into the
   host `assets/img/products/` and **Extract** it there — cPanel File Manager
   handles a zip far faster than 264 separate FTP puts.

## Rich descriptions — `resolve-descriptions.js` + `merge-host-descriptions.js`

`product-descriptions.md` is the official Kapriol catalog copy (1,132 entries) and
is the **only** copy — it was rescued from the disposable `Share Folder/`, so do
not delete it. `resolve-descriptions.js` matches it to slugs (fuzzy, with
hand-curated fixes in `_overrides.json`, helper logic in `desc-lib.js`) and emits
`descriptions.json` + `_review.md`; `merge-host-descriptions.js` writes
`fullDescription` into a downloaded host file.

```bash
node deploy/resolve-descriptions.js                       # regenerate the payload
node deploy/merge-host-descriptions.js <host-products.json>
```

**Status: applied.** 316 of 333 products have `fullDescription` live; the
remaining 17 have no catalog copy. Only re-run if products are added.
