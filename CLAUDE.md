# CLAUDE.md — kapriol.ergotrade.bg

Persistent context for Claude Code. Auto-loaded every session. **Keep it tight** —
every byte here is spent on every request. Deep history lives in
`docs/legacy-notes.md` (the archived 86 KB predecessor of this file); the newest
state-of-play is `docs/handoff.md`.

Last full verification against the live server: **2026-08-29**.

---

## Always

- **Read `docs/handoff.md` first** at the start of a fresh session — it may be newer
  than this file.
- **Begin every response with `START` on its own line, end with `END` on its own line.**
- **`website-automation-v1/` is OFF LIMITS.** A self-contained side project (a Claude
  Code skill being authored). Unrelated to this site. Do not read, edit or deploy
  anything in it unless the user explicitly reopens it.
- **`Share Folder/` is a DISPOSABLE drop-zone.** The user drops files there and wipes
  it periodically. Never store anything the project depends on there, and never point
  a script at a path inside it. Already lost this way: `product_mapping.xlsx`,
  `picto1.png`, `Links_1`/`Links_2`.
- **At the end of any task that changes the project, update this file** in ONE
  consolidated pass (not live, line by line). Replace stale wording in place rather
  than appending — a contradictory note is worse than no note. Keep it dense; push
  long rationale into `docs/`.

---

## Project Overview

Credibility / marketing site for **Kapriol** (Italian workwear, footwear, PPE),
distributed exclusively in Bulgaria by **Ergotrade**. **Not e-commerce** — no cart,
no prices. The job is to convince a sceptical tradesperson or shop owner that brand
and distributor are serious, then route them to the catalogue, a product page, or the
contact form.

Live at **`https://kapriol.ergotrade.bg`** (subdomain root; cPanel / SuperHosting.bg,
account `ergotrade`, PHP 8, **inode-limited** — keep the file count down, never add
`node_modules` to the host).

---

## Architecture

**Plain static HTML + CSS + vanilla JS. No framework, no build, no npm.** PHP only for
the contact form, the analytics beacon, the dynamic sitemap and the admin panel.

| Piece | Where |
| --- | --- |
| Public pages | `index.html`, `products.html`, `product.html`, `contact.html` |
| Styles | `assets/css/style.css` (~1560 lines — tokens + every component) |
| Behaviour | `assets/js/main.js` (~1930 lines, ONE guarded IIFE for all 4 pages) |
| Data (host-owned) | `assets/data/products.json`, `content.json`, `attributes.json` |
| Fonts (self-hosted, GDPR) | `assets/fonts/*.woff2` |
| Admin | `admin/login.php`, `admin/index.php`, `admin/api/*.php` |
| Server config | `.htaccess`, `sitemap.php`, `robots.txt`, `llms.txt`, `error/*.html` |
| Secrets + runtime data | `private/` — **must sit OUTSIDE the web root** |

`main.js` is one IIFE whose every `init()` step no-ops when its root element is
absent, so a single file serves all pages with no per-page branching. `init()` runs:
`setupNav` → `setupReveals` → `guardHeroEntrance` → `applyHome` (which owns
`setupRotator`, because the slide count comes from `content.json`) → `initHome` →
`initCatalog` → `initProduct` → `applyContact` → `setupForm` → `applyStaticCascades`
→ `beacon`.

**Data is fetched at runtime**, never inlined: `loadProducts()` / `loadContent()` /
`loadAttributes()` pull the three JSONs through `cachedJSON()`, which memoises them in
`sessionStorage` (`t1:<url>`) for the visit. **A fresh window = a fresh fetch**; an
admin save therefore shows on a new window, not a same-session reload.

**Six categories**, fixed: `footwear`, `footwear-accessories`, `clothing`,
`clothing-accessories`, `ppe`, `ppe-accessories`.

### Admin (PHP session login, 5 tabs)

`Products / Enquiries / Analytics / Codes / Site content`. Auth, CSRF, hardened
session, `atomic_write_json` (temp + rename) and the `KAP_PRODUCTS` / `KAP_CONTENT` /
`KAP_ATTRIBUTES` path constants all live in `admin/api/_guard.php`. 17 endpoints;
every write is auth + CSRF checked. Products save is **merge-by-slug with an
allowlist** — unknown keys on a record pass through untouched.

All image uploaders (`product-image.php`, `attr-image.php`, `category-image.php`,
`site-image.php` for the home-page slots) hand off to one shared
**`kap_save_upload_image()`** in `_guard.php`: validates a real JPG/PNG/WebP via
`getimagesize`, caps 4 MB, applies EXIF orientation, downscales the longest side to
≤1600 px, and **re-encodes to WebP q82 whatever went in**, deleting the prior file for
that basename. Product photos are cache-busted with a per-upload `imgV` stamp that
`main.js` appends as `?v=`.

**Editable-copy keys must stay in sync in three places** or a field silently edits
nothing: `admin/index.php` (the form), `admin/assets/admin.js` (`COPY_KEYS`,
`VISIBLE_KEYS`), and `admin/api/content-save.php` (`$COPY_KEYS`, `$VISIBLE_KEYS`).

### The `old/` archive

`old/` holds the previous (pre-2026-07-28) design, frozen. It has no `assets/data` and
no `assets/img`, so its catalogue renders empty — that is expected. `old/.htaccess`
denies all PHP there (a half-working admin would write a second, diverging copy of the
product data). It is `noindex`ed, `robots.txt` disallows it, and it is **deliberately
NOT in the deploy allowlist** — it was uploaded once and never changes. To restore that
design, move the files back to the root; do not try to make it run in place.

---

## Deploying

**Double-click `Deploy.cmd`** at the project root (`Deploy-Preview.cmd` = dry run,
uploads nothing). `.ps1` files can't be double-clicked — Windows opens them in
Notepad; the `.cmd` wrappers are the fix.

The deploy is **code only**. Allowlist in `deploy/ftp-deploy.ps1`:

- `$IncludeDirs` = `assets/css`, `assets/js`, `assets/fonts`, `admin`, `error`
- `$IncludeFiles` = the 4 HTML pages, `send-mail.php`, `track.php`, `sitemap.php`,
  `.htaccess`, `robots.txt`, `llms.txt`, plus the individually-listed logo and favicon
  files.

It **never** uploads `assets/data/`, `assets/img/` or `private/`, so host JSON and
admin-uploaded photos can never be clobbered. The FTP account is chrooted to the
subdomain docroot, hence `RemoteBase=/` in `deploy/ftp.config`. Because of the chroot,
`private/` cannot be pushed by the deploy account — upload it via cPanel File Manager.

---

## Decisions Made

- **Design language (2026 redesign, promoted to root 2026-07-28):** cream `#F7F4EF`,
  ink `#14110f`, safety orange `#E35D15`, hazard yellow `#F4B400`. Flat, 1 px borders,
  no shadows, a single 2 px radius, hazard-stripe motif.
- **Accessible colour variants are mandatory.** Brand orange is only 3.3:1 on cream, so
  small orange TEXT uses `--orange-ink #B4460A` (5.0:1) and button fills use
  `--orange-cta #C9500F` with white labels (4.5:1). Yellow is used on dark only.
- **Type:** `--font-display` **Clash Display** (500/600/700), `--font-stencil`
  **Saira Stencil One** on the wordmark, hero title, why title, section titles, CTA and
  page-hero `h1`; `--font-sans` **Inter**. Barlow Condensed remains as the display
  fallback. Stencil headlines carry a per-letter **cascade hover** (`splitCascade` /
  `cascadeText` in `main.js`, `.csc*` in CSS): each letter rolls up 1 em via a
  `clip-path`-clipped `text-shadow` copy, staggered, turning orange. Reduced motion
  keeps the colour and drops the roll. Latin-only fonts are safe — the site is `lang=en`.
- **⚠️ Animation safety rule — the single most important rule in this codebase.**
  **Never animate from `opacity: 0`.** A renderer that *starts but never advances* an
  animation (throttled background tab, headless renderer, crawler) parks it at the
  from-state permanently — with or without `animation-fill-mode`. This has shipped as a
  bug more than once. The safe shape is *animate TO a hidden state and back FROM an
  already-visible element*. Two failsafes exist because of it and **must not be
  removed**: `.reveal` is scoped to `.js` with a re-armable observer + `revealAll()`
  force-show, and `guardHeroEntrance()` strips the hero animation if it is still
  transparent after 1400 ms. Three entrance animations were built and then deleted for
  this reason (rotator slide copy, product-photo blur-in, `.empty` panel arrival) —
  **do not re-add them.**
- **Motion is budgeted, not sprayed.** One signature entrance (the hero), sibling
  stagger on real lists only (capped at 8 steps), press feedback on everything
  pressable. Standing sections deliberately do NOT fade in on scroll — fade-and-rise on
  every section is the saturated AI tell.
- **The home page has NO hardcoded photography.** Every image is uploaded through the
  admin into `content.json`'s `home` block; the HTML copy is the fallback when a field
  is blank, and a missing photo renders a flat dark panel (a deliberate state, not a
  gap). Slides the admin unticks are dropped along with their dot; when none remain the
  whole banner hides.
- **Home "Our ranges" is a text-only hover-expanding LIST** (`.rangelist` /
  `renderRangeList()`), not photo tiles — the products landing page keeps the `.cat`
  tiles. The footer must not move on hover: `.rangelist` reserves
  `padding-bottom: var(--range-grow)` which collapses via `:has(.range:hover)` in
  lockstep with a **fixed-`height`** row grow (measured delta 0).
- **Module-level flags that must stay module-level:** `filtersOpen` and
  `groupCollapsed`. `renderFilters()` rebuilds the whole `#filters` subtree on every
  chip click, so deriving open-state locally slams the drawer shut / springs a collapsed
  group open the moment you pick a filter.
- **Catalogue filters are responsive in three modes**, one `#filters` element:
  ≤760 px a right-hand drawer (closed = `visibility: hidden`, not merely translated, or
  it stays tabbable); 761–1024 px the inline accordion; ≥1025 px an always-open left
  sidebar with collapsible groups. AND-matching across every selected code.
- **`safetyClass` is a compound EN ISO marking string** (`"S1 PL"`, `"S1PL ESD"`).
  `toCodes()` splits it on whitespace for filtering; `rawSpec()` keeps the intact
  marking for the product badge.
- **Spec codes render for every category** (Performance / Safety class / Technologies)
  and are **clickable** on the product page when the admin authored a `title` or
  `description` — an inline disclosure panel per group, never a modal. A code with no
  authored text stays an inert `<span>`, so an affordance never promises text that
  isn't there.
- **Products are HIDDEN, never deleted, when they should come off the site** — a
  boolean `hidden` on the record. The public side filters in exactly **one** place,
  `loadProducts()` in `main.js`, because `PRODUCTS` is what the whole build reads from;
  `sitemap.php` applies the same rule server-side.
- **The live `safetyClass` code list intentionally keeps 10 non-safety-class codes**
  (`CI, ESD, FO, HI, HRO, LG, SC, SR, SRC, WR`, leaked from the old PDF import). **The
  user has decided to leave them — do NOT "clean" this or re-raise it.**
- **Absolute URLs are domain-coupled.** `rel=canonical`, `og:url`, `og:image`, the
  `Sitemap:` line in `robots.txt`, the 10 `ErrorDocument` paths and the `<base href>`
  in all 10 `error/*.html` hardcode the origin. `product.html` has no static canonical
  (one template, hundreds of slugs) — `setSeo()` writes a per-product canonical, og
  tags and meta description at runtime from `location`, so that part is move-proof.

---

## Current State (verified against the live server 2026-08-29)

**Local and live are IN SYNC and fully deployed.** `style.css`, `main.js`, all four
HTML pages, `llms.txt` and `robots.txt` are byte-identical to what the server serves.
Cache strings **CSS `?v=72`, JS `?v=72`**; admin `base.css?v=2`, `admin.css?v=3`,
`theme.css?v=2`, `admin.js?v=6`. `node --check` clean on both JS files; `php -l` clean
on all 23 PHP files. The Clash Display + Saira Stencil + cascade font work that
`docs/handoff.md` still calls "pending sign-off / not deployed" **is live**.

**Live catalogue: 333 products** — footwear 46, footwear-accessories 6, clothing 105,
clothing-accessories 23, ppe 139, ppe-accessories 14.

| | Live |
| --- | --- |
| With photos | **180** (footwear + accessories + the clothing drop) |
| Rich `fullDescription` | **317** |
| `performance` + `technologies` codes | **46** (all footwear) |
| `safetyClass` | 44 |
| `hidden` | 0 |

**Live `attributes.json`:** performance 18 codes (all with title + description, **only
1 with a pictogram**), safetyClass 22 (code-only by design), technologies 14 (all with
title + description, none with a pictogram).

**Live `content.json`:** contact email `info@kapriol.ergotrade.bg` shown; phone and
address are **empty and toggled off** (`visible.contactPhone/contactAddr = false`) so
those rows don't render. Footer reads "© 2026 Ergotrade BG" / "Powered by NikTaRas Web
Studio". Home: hero has **no** photo (flat dark panel), `why` has
`assets/img/site/why.webp`, **all three rotator slides are `show: false`** so the
banner is hidden entirely, all four featured slots are auto-filled, stats read
1927 / 70 / 6.

**The LOCAL `assets/data/*.json` are STALE and must never be pushed.** Local
`products.json` has 334 records (one extra, `footwear/dune-jaq-pink`), zero
`performance`/`technologies`, and the local `content.json` is full of test junk
(`contactPhone: "phoneTest"`, `ADDRESS_PLACEHOLDER`). Harmless, because the deploy
excludes `assets/data` — but do not "fix" the host from local copies.

---

## Known Issues (found 2026-08-29, none fixed yet)

1. **`/<page>/<anything>` returns a raw Apache 500, not the styled 404.** Reproducible:
   `/products/foo`, `/contact/x`, `/index/x`, `/error/404/x` all 500; `/foo/bar` and
   `/assets/xx.css` correctly 404. The response leaks `webmaster@kapriol.ergotrade.bg`,
   and the ErrorDocument fallback fails too. Cause is the clean-URL block in `.htaccess`
   interacting with a path whose first segment resolves to an existing `.html` file.
2. **`deploy/ftp.config` stores the FTP password in plaintext**, despite the file's own
   comment and the script's help text both claiming it is prompted each run —
   `ftp-deploy.ps1:152` reads `$cfg.Pass` and skips the prompt when it is present.
3. **A stale `admin/index.html` stub still exists on the host** (92 bytes, dated
   2026-07-28, a meta-refresh to the absolute login URL). It is not in the local repo
   and it shadows `admin/index.php` via `DirectoryIndex index.html index.php`. It
   currently points at the right URL so nothing is broken, but it hardcodes the domain
   and will silently break on the next move.
4. **Synthetic bold on the display face.** Clash Display ships 500/600/700 only, but
   `.hero h1`, `.stat__num`, `.cat__img--empty span` and `.card__ph` ask for
   `font-weight: 800/900` — the browser fakes it.
5. **Founding-year contradiction.** The static `<meta name="description">`,
   `og:description` and hero fallback in `index.html` say "Built in Italy since 1980";
   the live admin stats say Kapriol was founded **1927**. The meta description is not
   admin-editable, so the wrong year is what shows in search results.
6. **The project is NOT under version control.** No `.git`, no `.gitignore` — despite
   `README.md` documenting both. Every change is one bad overwrite away from gone.
7. **`README.md` is badly stale** — it still describes the pre-redesign site (video
   hero, About + Best Sellers, Archivo/Spline fonts, `assets/video/`, `Info hosting/`,
   `.git/`).
8. Analytics page keys still carry the `t1-` staging prefix (`beacon("t1-" + page)`),
   a leftover from when the redesign lived in `test1/`.

## What's Left To Do

- **PPE photos.** 139 `ppe` + 14 `ppe-accessories` + `thermic-kit` still have no
  imagery — there is no WebP source drop for them yet. Footwear and clothing are done.
- **Contact details.** Phone and address are empty and hidden on the live site; supply
  them (admin → Site content) or leave the rows off deliberately.
- **Home page content.** The rotator is entirely switched off and the hero has no
  photo; both are admin-authored decisions to make, not code work.
- **Pictograms.** Only 1 of 18 performance codes and 0 of 14 technologies codes carry an
  image — the rest render as text pills. Upload via admin → Codes.
- **Footwear-accessory spec codes** are still empty: their data sheets use `Comfort`,
  `Breathable`, `Water repellent`, `Wear Resistance`, none of which exist in
  `attributes.json`. Add those 4 codes and re-run `deploy/extract-sheet-specs.py`
  (it matches on title). Non-footwear categories use a different spec vocabulary and
  are out of scope for that script.
- Confirm the enquiry mailbox exists in cPanel, and confirm the host admin password was
  changed from the default `ChangeMe!2026` (`private/config.php` `pass_hash`; the local
  copy is still the default and the host copy is not visible from here).

---

## Gotchas (each of these has actually bitten)

- **Bump `?v=N` on the CHANGED asset across all four pages on every `style.css` /
  `main.js` edit — including a second edit in the same session if the first was already
  deployed.** Assets are cached 1 year immutable in `.htaccess`. A version string is
  **spent the moment it is deployed**. Symptom to recognise: `curl` shows the new rules
  on the server but the browser doesn't apply them.
- **Never bulk-edit HTML with PowerShell `Get-Content -Raw` + `WriteAllText`.** In
  Windows PowerShell 5.1, `-Raw` without `-Encoding` reads UTF-8 as Windows-1252, so
  every `—` / `©` / `…` is mangled on write-back, compounding on each pass and **not**
  reversible. Use the Edit tool, or read *and* write through an explicit
  `UTF8Encoding($false)`.
- **NEVER overwrite host JSON or images.** `products.json`, `content.json`,
  `attributes.json` and everything under `assets/img/` are admin-edited live and diverge
  from the local copies. To publish bulk data changes, download the host file, merge into
  it (`deploy/merge-host-images.js`, `deploy/merge-host-descriptions.js` — both write a
  `*.merged.json` and leave every other host field intact), then upload the merged file.
- **The user does NOT use the preview.** Verify offline: `node --check` on JS, `php -l`
  on PHP, and headless-browser **measurement** (`getBoundingClientRect`, computed
  styles) — never screenshots or hover. The headless preview reports the tab as hidden,
  which pauses `requestAnimationFrame`, so scroll reveals and entrance animation never
  run there, and a mid-transition computed value is meaningless (read the settled state
  by setting `transition: none` first).
- **Same-specificity mobile overrides depend on source order.** The
  `@media (max-width: 560px)` block and the mobile category-tile block must stay AFTER
  the base rules they override. Both have been in the wrong place and shipped bugs.
- **`display` defeats the `hidden` attribute.** `.inforow` / `.form-success` are flex,
  so each needs an explicit `[hidden] { display: none }`. This has broken the contact
  page twice.
- **Do not revert the mobile `.nav` to `position: fixed`.** A percentage `top` on a
  fixed element resolves against the viewport, so the burger menu opened one full screen
  below the fold. It must stay `position: absolute; top: calc(100% + 3px)`.
- **`.pgroup__items` must stay `align-items: flex-start`** — `stretch` pulls a 36 px chip
  up to the 130 px pictogram height in groups that mix both.
- **Product-photo preloading is driven by scroll position, not IntersectionObserver.**
  IO silently never fires in throttled/headless renderers (measured: 0 callbacks over 47
  cards). `PRELOAD_EAGER` (8) load immediately, then a rolling `PRELOAD_AHEAD` (15)
  window warms the HTTP cache, throttled at 150 ms.
- **`_guard.php` derives the session-cookie `path` from `SCRIPT_NAME`**, so the admin
  works at the web root and in a subfolder. Don't re-hardcode it — the old hardcoded
  `/admin` broke subfolder deploys with a silent login loop (wrong password → error,
  correct password → blank reload). Note the guard prefers `<docroot>/private/config.php`
  over `<deploydir>/private/config.php`.
- **`.htaccess` `DirectoryIndex` must keep `index.php`.** Narrowing it to `index.html`
  makes any PHP-only directory unable to resolve its index — that, plus a stale
  `admin/index.html`, once made the dashboard unreachable at its own address.
- **Error pages carry no entrance animation, by design** (see the animation safety rule)
  and no external requests at all — inline CSS, no `@font-face`, no JS, so they render
  during the outage they report. All ten share one stylesheet; edit them as a set.
- **Tools:** ffmpeg at `C:\ffmpeg\bin` (not on PATH). ImageMagick is NOT installed. PHP
  8.3 is on PATH; local preview is `.claude/launch.json` (`php -S` on :8001 via
  `.claude/router.php`, which mirrors the `.htaccess` clean-URL rewrites).

---

## Key Files

`assets/js/main.js` · `assets/css/style.css` · `admin/assets/admin.js` ·
`admin/index.php` · `admin/api/_guard.php` · the four HTML pages ·
`deploy/ftp-deploy.ps1` · `.htaccess`

## Docs Map

Root holds only `README.md`, `CLAUDE.md` and the two deploy launchers. Everything else
is in `docs/`, except `deploy/README.md` and `deploy/_review.md` (which
`resolve-descriptions.js` writes there via `__dirname` — it must stay in `deploy/`).

| File | What it is |
| --- | --- |
| `docs/handoff.md` | Newest state-of-play snapshot. Read first. |
| `docs/legacy-notes.md` | The archived 86 KB predecessor of this file. History only — **much of it describes the pre-redesign site and is not current.** |
| `docs/product-brief.md` | Audience and positioning. |
| `docs/brand-kit.md` | Colours, logo usage. |
| `docs/PRODUCT.md` | Product-data field reference. |
| `docs/descriptions-pipeline.md` | How `fullDescription` copy was matched and merged. |
| `docs/spec-codes-howto.md` | How to add spec codes and re-run the extractor. |
| `docs/missing-spec-codes.md` | Gap report from the data-sheet ingest. |
| `docs/product-codes.md`, `docs/product-slugs.md` | Generated reference tables. |
| `deploy/README.md` | Deploy engine + content tooling. |

**Local-only, never uploaded:** `README.md`, `CLAUDE.md`, `docs/`, `deploy/`,
`products/`, `brand/`, `Share Folder/`, `.claude/`, `.impeccable/`,
`kapriol-hero-SOURCE.mp4`, `website-automation-v1/`, and `old/` (deployed once,
now excluded).
