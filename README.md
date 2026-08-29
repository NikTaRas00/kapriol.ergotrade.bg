# kapriol.bg

Credibility landing site for **Kapriol** workwear / footwear / PPE, distributed
exclusively in Bulgaria by **ergotrade.bg**. Not e-commerce — there is no cart. The
job is to convince a skeptical tradesperson the brand is serious, then route them to
the catalog, a product page, and the contact form.

**Live at:** `kapriol.ergotrade.bg` (subdomain root, cPanel / SuperHosting.bg, PHP 8).

Plain static **HTML + CSS + vanilla JS**. No framework, no npm, no build step.
PHP is used only for the contact form, the analytics beacon, and the admin panel.

> Local notes only — **never upload** `README.md`, `CLAUDE.md`, `docs/`,
> `Share Folder/`, `deploy/`, `brand/`, `.claude/`, or `Info hosting/`.

---

## Repo map

Everything in the project root, at a glance. **↑** = uploaded to the live server,
**·** = local only (never uploaded).

```
kapriol-website/
├── ↑ index.html               Home: video hero + About + Best Sellers
├── ↑ products.html            Catalog: category tiles, search, filters
├── ↑ product.html             Single product detail (driven by ?p=<slug>)
├── ↑ contact.html             Contact form page
├── ↑ send-mail.php            Contact-form handler (emails + logs enquiry)
├── ↑ track.php                Cookieless page-view beacon
├── ↑ .htaccess                Apache rules: clean URLs, caching, error pages
├── ↑ robots.txt               Search-crawler rules
│
├── · README.md                You are here — entry point + this map
├── · CLAUDE.md                Full technical snapshot (MUST stay at root)
├── · Deploy.cmd               ▶ DOUBLE-CLICK THIS TO DEPLOY
├── · Deploy-Preview.cmd       ▶ Dry run — shows the file list, uploads nothing
├── · kapriol-hero-SOURCE.mp4  Raw 11 MB hero master (backup, never uploaded)
├── · .gitignore               What git ignores
├── · .gitattributes           Git line-ending / diff settings
│
├── ↑ assets/                  Everything the live site serves (see below)
├── ↑ admin/                   PHP admin panel — login, dashboard, api/
├── ↑ error/                   10 styled error pages (404, 500, …)
├── ⚠ private/                 Secrets + runtime data — goes OUTSIDE public_html
│
├── · docs/                    All project documentation
├── · deploy/                  Deploy engine + content tooling
├── · products/                Source per-category product JSONs
├── · brand/                   Brand source art (icon masters)
├── · Share Folder/            ⚠ DISPOSABLE hand-off drop-zone — gets wiped
├── · .claude/                 Claude Code local config (dev server, router)
├── · .impeccable/             Design-critique tool output
└── · .git/                    Git repository data
```

### Root files, one by one

| File | Purpose |
| --- | --- |
| `index.html` | Home page — scroll-scrubbed video hero, About section, Best Sellers showcase. |
| `products.html` | Catalog. Renders three ways: 6 category tiles, a single category (`?cat=`), or search results (`?q=`). |
| `product.html` | One template for **all 334 products** — filled at runtime from `?p=<slug>`. |
| `contact.html` | Contact form. |
| `send-mail.php` | Receives the contact form, emails the enquiry, and appends it to `private/enquiries.json` so it shows in the admin Enquiries tab. |
| `track.php` | Cookieless analytics beacon. Writes page views to `private/analytics.json`. No cookies, no third party. |
| `.htaccess` | Apache config: clean URLs (`/products` not `/products.html`), 1-year asset caching, `no-cache` on JSON, `ErrorDocument` wiring, security headers. |
| `robots.txt` | Crawler rules. |
| `README.md` | This file. |
| `CLAUDE.md` | The detailed technical snapshot — architecture, decisions, gotchas. **Must stay at root**; Claude Code loads it automatically each session. |
| `Deploy.cmd` | **The deploy button.** Double-click to upload the site. |
| `Deploy-Preview.cmd` | Dry run — lists what would upload, sends nothing. |
| `kapriol-hero-SOURCE.mp4` | The original high-quality hero video (11 MB), kept as a backup. The optimised clip that ships is `assets/video/kapriol-hero.mp4`. Never uploaded. |
| `.gitignore` / `.gitattributes` | Git housekeeping — ignored paths and line-ending rules. |

### Root folders, one by one

| Folder | Purpose |
| --- | --- |
| **`assets/`** ↑ | Everything the browser loads. See the breakdown below. |
| **`admin/`** ↑ | The PHP admin panel: `login.php`, `index.php` (5-tab dashboard), `api/` endpoints, and `assets/` for its own CSS/JS. This is where you edit products and site copy. |
| **`error/`** ↑ | Ten self-contained error pages (400, 401, 403, 404, 405, 429, 500, 502, 503, 504) wired up in `.htaccess`. Inline CSS so they render even if the site is down. |
| **`private/`** ⚠ | `config.php` (admin password hash), `enquiries.json`, `analytics.json`, `login-throttle.json`. **Must sit OUTSIDE `public_html`** on the server, as a sibling folder — never web-reachable. Not pushed by `Deploy.cmd`; upload via cPanel. |
| **`docs/`** · | Project documentation — `handoff.md` (current state), `product-brief.md` (audience & brand), `brand-kit.md` (colours, logo usage), `descriptions-pipeline.md`. |
| **`deploy/`** · | The deploy engine (`ftp-deploy.ps1`, `ftp.config`) plus the product-description matching tooling and its source catalog. See `deploy/README.md`. |
| **`products/`** · | Working copies of the catalog split per category (6 JSONs + `all-products.json`). The live catalog is `assets/data/products.json`; these are the source/backup. |
| **`brand/`** · | Brand source artwork (icon masters used to generate the favicon set). Usage rules are documented in `docs/brand-kit.md`. |
| **`Share Folder/`** · | ⚠️ **Disposable drop-zone** for handing files over. Contents get wiped periodically — **never store anything the project depends on here.** |
| **`.claude/`** · | Local dev config: `launch.json` (PHP preview server), `router.php` (resolves clean URLs locally), `php.ini`. |
| **`.impeccable/`** · | Saved output from the design-critique tooling. |
| **`.git/`** · | Git's internal repository data. Don't touch. |

### Inside `assets/`

| Path | Contents |
| --- | --- |
| `assets/css/style.css` ↑ | Every style in the site, plus the design tokens (colours, spacing, easing). |
| `assets/js/main.js` ↑ | One guarded IIFE serving all four pages — catalog rendering, search, filters, hero scrub, Best Sellers, form handling. |
| `assets/fonts/` ↑ | Self-hosted Archivo + Spline Sans Mono (4 `.woff2`). Self-hosted for GDPR — no Google CDN call. |
| `assets/data/` ✋ | `products.json` (334 products), `content.json` (editable site copy), `attributes.json` (spec codes). **Host-owned** — edited live through the admin panel, so the deploy never overwrites it. |
| `assets/img/` ✋ | Product photos, spec pictograms (`certs/`), logos, favicons. **Mostly host-owned** — only the logos and favicon set are pushed by the deploy; uploaded product photos are never touched. |
| `assets/video/` ✋ | `kapriol-hero.mp4` — the optimised all-intra hero clip. Excluded from the deploy because of its size; upload manually if it changes. |

> ✋ = the deploy deliberately **skips** these so live admin edits and uploaded
> photos are never clobbered.

---

## Deploying

**Double-click `Deploy.cmd`** in the project root. It lists the files, asks you to
confirm, prompts for the FTP password (never stored), and uploads.

Use **`Deploy-Preview.cmd`** first if you want to see the file list without sending
anything.

The deploy is **code-only**. It never uploads `assets/data/`, `assets/img/`, or
`private/` — so products, photos and secrets edited live on the host are always safe.

> **Before deploying:** if you changed `style.css` or `main.js`, bump the `?v=N`
> cache string in **all four** HTML pages. Assets are cached 1 year immutable, so a
> stale version number means nobody sees your change.

---

## Editing content (no code required)

Most day-to-day content is edited through the **admin panel** at `/admin/`, not in
code. Sign in and use the five tabs:

| Tab | What it edits |
| --- | --- |
| **Products** | Every product: name, short + full description, features, material, sizes, colours, standards, article codes, photo, spec codes. Add and delete products. |
| **Enquiries** | Contact-form submissions received. |
| **Analytics** | Cookieless visitor stats (views, visits, top products). |
| **Codes** | The master list of Performance / Safety-class / Technologies codes, their pictograms, and the info text shown when a customer clicks one. |
| **Site content** | Hero, About, contact details, footer, category images, and the three home-page best sellers. |

Admin edits write to `assets/data/*.json` on the host and go live immediately.

---

## Local preview

The admin panel and contact form need PHP:

```bash
php -S localhost:8001 -t . .claude/router.php
```

Then open `http://localhost:8001`. The router resolves the clean URLs that
`.htaccess` handles in production. Opening the HTML as `file://` won't work — the
catalog is fetched from `assets/data/products.json`.

---

## Still to do before launch

- Replace `PHONE_PLACEHOLDER` and `ADDRESS_PLACEHOLDER` (admin → Site content).
- Add product photos — most products still show the grey KAPRIOL placeholder.
- Change the admin password (`private/config.php`; default is `ChangeMe!2026`).
- Confirm the enquiry mailbox exists in cPanel → Email Accounts.

---

## Hosting constraint

The account is near its **inode (file-count) ceiling**, so keep the file count low.
Prefer a modest number of optimised images. Never add `node_modules` or build
tooling to this account.

---

**More detail:** `CLAUDE.md` is the full technical snapshot (architecture, decisions,
gotchas). `docs/handoff.md` is the newest state-of-play summary.
