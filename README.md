# kapriol.ergotrade.bg

Credibility site for **Kapriol** workwear / footwear / PPE, distributed exclusively in
Bulgaria by **Ergotrade**. Not e-commerce — there is no cart and no prices. The job is
to convince a sceptical tradesperson the brand is serious, then route them to the
catalogue, a product page, and the contact form.

**Live at:** `https://kapriol.ergotrade.bg` (subdomain root, cPanel / SuperHosting.bg,
PHP 8).

Plain static **HTML + CSS + vanilla JS**. No framework, no npm, no build step. PHP is
used only for the contact form, the analytics beacon, the dynamic sitemap, and the
admin panel.

> **`CLAUDE.md` is the authoritative technical snapshot** — architecture, decisions,
> current live state, and the gotchas that have actually bitten. Read it before
> changing anything. This README is the map; that file is the manual.

---

## Repo map

**↑** = uploaded to the live server, **·** = local only, **✋** = host-owned (the
deploy deliberately never touches it).

```
kapriol.ergotrade.bg/
├── ↑ index.html               Home: hero, rotating banner, why, ranges, featured, CTA
├── ↑ products.html            Catalogue: range tiles, search, spec-code filters
├── ↑ product.html             Single product detail (driven by ?p=<slug>)
├── ↑ contact.html             Contact form
├── ↑ send-mail.php            Contact-form handler (emails + logs the enquiry)
├── ↑ track.php                Cookieless page-view beacon
├── ↑ sitemap.php              Dynamic XML sitemap (/sitemap.xml rewrites to it)
├── ↑ .htaccess                Clean URLs, caching, error pages, security headers
├── ↑ robots.txt  ↑ llms.txt   Crawler rules / LLM-readable site summary
│
├── · README.md                You are here
├── · CLAUDE.md                The technical snapshot — MUST stay at root
├── · Deploy.cmd               ▶ DOUBLE-CLICK THIS TO DEPLOY
├── · Deploy-Preview.cmd       ▶ Dry run — shows the file list, uploads nothing
├── · .gitignore               Keeps secrets out of git
├── · kapriol-hero-SOURCE.mp4  Hero master from the retired video design (backup)
│
├── ↑ assets/                  Everything the live site serves (see below)
├── ↑ admin/                   PHP admin panel — login, dashboard, api/
├── ↑ error/                   10 styled error pages (404, 500, …)
├── ⚠ private/                 Secrets + runtime data — goes OUTSIDE public_html
│
├── · old/                     FROZEN archive of the pre-2026 design (not deployed)
├── · docs/                    Project documentation
├── · deploy/                  Deploy engine + content tooling
├── · products/                Source per-category product JSONs
├── · brand/                   Brand source art (icon masters)
├── · Share Folder/            ⚠ DISPOSABLE hand-off drop-zone — gets wiped
├── · .claude/                 Claude Code local config (dev server, router)
├── · .impeccable/             Design-critique tool output
└── · website-automation-v1/   ⛔ Unrelated side project — do not touch
```

### Inside `assets/`

| Path | Contents |
| --- | --- |
| `assets/css/style.css` ↑ | Every style in the site, plus the design tokens. |
| `assets/js/main.js` ↑ | One guarded IIFE serving all four pages — catalogue rendering, search, filters, home content, product detail, form handling. |
| `assets/fonts/` ↑ | Self-hosted Clash Display + Saira Stencil One + Inter (Barlow Condensed kept as the display fallback). Self-hosted for GDPR — no Google CDN call. |
| `assets/data/` ✋ | `products.json` (333 products live), `content.json` (editable copy + home page), `attributes.json` (spec codes). **Host-owned** — edited live through the admin, so the deploy never overwrites it. |
| `assets/img/` ✋ | Product photos, spec pictograms (`certs/`), home-page uploads (`site/`), logos, favicons. **Mostly host-owned** — only the logos and favicon set are pushed. |

---

## Deploying

**Double-click `Deploy.cmd`.** It lists the files, asks you to confirm, and uploads.
Use **`Deploy-Preview.cmd`** first to see the file list without sending anything.

The deploy is **code only**. It never uploads `assets/data/`, `assets/img/`, or
`private/`, so products, photos and secrets edited live on the host are always safe.
The **local `assets/data/*.json` are stale on purpose** — never try to publish them by
hand; merge into a downloaded copy of the host file instead (see `deploy/README.md`).

> **Before deploying:** if you changed `style.css` or `main.js`, bump the `?v=N` cache
> string in **all four** HTML pages. Assets are cached one year immutable, and a
> version string is spent the moment it is deployed — a second edit needs a new number.

`private/` cannot be pushed by the deploy account (it is chrooted to the subdomain
docroot). Upload it via cPanel File Manager, as a sibling of `public_html`.

---

## Editing content (no code required)

Day-to-day content is edited in the **admin panel** at `/admin/`, not in code:

| Tab | What it edits |
| --- | --- |
| **Products** | Every product: name, short + full description, features, material, sizes, colours, standards, article codes, photo gallery, spec codes, visibility. Add and delete products. |
| **Enquiries** | Contact-form submissions received. |
| **Analytics** | Cookieless visitor stats (views, visits, top products). |
| **Codes** | The master list of Performance / Safety-class / Technologies codes, their pictograms, and the text shown when a customer clicks one. |
| **Site content** | Home page (hero, rotating slides, why, key figures, featured picks, range photos), contact details and their show/hide switches, footer lines. |

Admin edits write to `assets/data/*.json` on the host and go live immediately — on a
**fresh** browser window, since the data is cached per visit in `sessionStorage`.

---

## Local preview

The admin panel and contact form need PHP:

```bash
php -S localhost:8001 -t . .claude/router.php
```

Then open `http://localhost:8001`. The router resolves the clean URLs that `.htaccess`
handles in production. Opening the HTML as `file://` will not work — the catalogue is
fetched from `assets/data/products.json`.

---

## Still to do

- **PPE photos** — 139 `ppe` and 14 `ppe-accessories` products still have no imagery.
- Contact phone and address are empty and switched off on the live site.
- The home rotating banner is switched off entirely; the hero has no photo.
- Confirm the enquiry mailbox exists in cPanel → Email Accounts.
- Confirm the admin password was changed from the default `ChangeMe!2026`.

---

## Hosting constraint

The account is near its **inode (file-count) ceiling**, so keep the file count low.
Prefer a modest number of optimised images. Never add `node_modules` or build tooling
to this account.
