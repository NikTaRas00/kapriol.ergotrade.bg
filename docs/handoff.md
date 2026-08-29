# Kapriol Bulgaria — Session Handoff

## Project Overview
Credibility/marketing site for **Kapriol** workwear, footwear and PPE, distributed exclusively in Bulgaria by **Ergotrade**. Not e-commerce — no cart, no prices. Goal: convince a skeptical tradesperson or shop owner that the brand and distributor are serious, then route them to the catalogue, a product page, or the contact form. Live at the `kapriol.ergotrade.bg` subdomain root. **Read this file, then CLAUDE.md** (the authoritative, detailed snapshot) at session start. Every response begins with `START` and ends with `END`.

## ⚠️ Off-limits side project
`website-automation-v1/` is a self-contained side project (authoring a Claude Code skill). **Do not touch it unless the user explicitly reopens it.**

## Architecture
Plain static **HTML + CSS + vanilla JS, no framework/build/npm**. PHP only for the contact form (`send-mail.php`), analytics beacon (`track.php`), dynamic `sitemap.php`, and the `admin/` area. Four public pages (`index/products/product/contact.html`) share `assets/css/style.css` + `assets/js/main.js` (one guarded IIFE; each `init` step no-ops if its root element is absent). Data is runtime JSON in `assets/data/` (`products.json` 334 items, `content.json`, `attributes.json`), fetched client-side, cached per-visit in sessionStorage. Fonts self-hosted in `assets/fonts/` (GDPR). 2026 redesign at root; old design frozen/noindexed in `old/`. Admin (PHP session login) manages products, photos, spec codes, enquiries, site copy/home content. `private/` (secrets, enquiries, analytics) lives OUTSIDE the web root.

## Decisions Made
- **Palette:** cream `#F7F4EF`, ink `#14110f`, safety orange `#E35D15` (+ `--orange-cta/--orange-ink/--orange-tint`), hazard yellow. Flat, 1px borders, no shadows, 2px radius.
- **Animation safety rule (critical):** never animate from `opacity:0` with fill-mode/delay — a renderer that starts-but-never-advances parks it invisible. Safe shape: animate TO a hidden state and back FROM an already-visible element. `.reveal` is `.js`-scoped with a 2s failsafe (`revealAll`).
- **Home "Our ranges"** is a text-only hover-expanding LIST (`.rangelist`/`renderRangeList()`), NOT photo tiles (products landing keeps the `.cat` tiles). Footer must not move on hover — `.rangelist` reserves `padding-bottom:--range-grow` collapsed via `:has(.range:hover)` in lockstep with a fixed-`height` grow (measured delta 0).
- **Fonts (SHIPPED — live since the `?v=72` deploy):** display swapped Barlow Condensed → **Clash Display** (Fontshare, `--font-display`); a **Saira Stencil One** tier (`--font-stencil`) on the wordmark + hero title + why title + `.sec__title:not(--sub)` + CTA + `.catalog__title` + `.pagehero h1`. Stencil headlines carry a **per-letter "cascade" hover** (`splitCascade`/`cascadeText` in main.js, `.csc*` CSS): each letter rolls up 1em via a `clip-path`-clipped `text-shadow` copy, staggered, and turns `--orange`; trigger scoped to the inline `.csc` (over the text only), reduced-motion keeps colour, drops the roll. Latin-only fonts are safe because the site is `lang=en`.

## Current State (re-verified against the live server 2026-08-29)
**Everything is DEPLOYED and local matches live byte-for-byte** — `style.css`, `main.js`, all four HTML pages, `llms.txt` and `robots.txt` fetched from `kapriol.ergotrade.bg` are identical to the working copy. **Cache CSS/JS `?v=72`** (admin `admin.css?v=3 / admin.js?v=6`). `node --check` clean on both JS files, `php -l` clean on all 23 PHP files. The Clash + Stencil + cascade fonts, the rebuilt ranges section, the contact-page "Hours" removal and the `/impeccable` ranges pass are all live.

Live data (host-owned, admin-edited): **333 products**, 180 with photos, 317 with `fullDescription`, 46 footwear carrying `performance` + `technologies`, 0 hidden. `attributes.json` has 18 performance / 22 safetyClass / 14 technologies codes — titles and descriptions authored throughout, but only **1 pictogram image** in total. `content.json`: contact phone + address are empty AND toggled off; the rotator's three slides are all `show: false` (banner hidden); the hero has no photo; featured slots all auto. **The local `assets/data/*.json` are stale — never push them.**

## What's Left To Do
- Product photos: footwear + clothing live (180 products); **PPE (139) + ppe-accessories (14) still without photos** (no WebP source drop yet).
- Supply contact phone + address (admin → Site content), or leave those rows deliberately off; confirm the enquiry mailbox in cPanel.
- Home content decisions: the rotator is switched off entirely and the hero has no photo.
- Upload pictograms for the performance / technologies codes (admin → Codes).
- **8 known issues are logged in `CLAUDE.md` → Known Issues** — including a raw Apache **500** on any `/<page>/<anything>` URL, a plaintext FTP password in `deploy/ftp.config`, and the fact that the project has **no git repository**.

## How To Continue
- **Begin every response with `START`, end with `END`.** Read handoff.md then CLAUDE.md at session start.
- **Bump `?v=N` on the CHANGED asset across all 4 pages on every style.css/main.js edit** (assets are 1yr-immutable). A version is spent once deployed; `?v=72` is still local so it's reused across this session's edits.
- **User does NOT use the preview.** Verify offline: `node --check` on JS, `php -l` on PHP, and headless-browser *measurement* (getBoundingClientRect / computed styles) — never trust headless screenshots or hover (the reveal failsafe sets inline `transition:none`).
- **Never overwrite host JSON/images** — admin-edited live, diverge from local copies.
- Never version-bump HTML via PowerShell `Get-Content -Raw` (mangles UTF-8) — use the Edit tool.
- Key files: `assets/js/main.js`, `assets/css/style.css`, `admin/assets/admin.js`, `admin/index.php`, the 4 HTML pages, `deploy/ftp-deploy.ps1`, `Deploy.cmd`.
