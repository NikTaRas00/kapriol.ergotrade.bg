# Instructions for Claude Design — <PROJECT / BRAND NAME>

You are designing the **front end only** for the website described in
`SITEMAP.md` and `BRANDING.md` (in this folder). A separate build step will wire
in the backend afterwards, so your output must follow the **Output Contract**
below exactly — it is the same for every project and the build depends on it.

## What to design
- One finished, polished, responsive front end for the pages marked **"own page"**
  in `SITEMAP.md`.
- Use the identity in `BRANDING.md`. Meet **WCAG AA** contrast everywhere.
- Make data-driven pages (the list/catalogue and the detail template) render from
  **placeholder JSON**, not hardcoded content, so real data can be swapped in.
- Design the real states, not just the happy path: empty results, "not found",
  loading, form success/error, long titles, missing image.

## Do NOT
- No admin/back-office design (the build handles it).
- No backend code.
- No framework, no build tooling, no npm.

---

## OUTPUT CONTRACT (return exactly this shape)

Deliver everything in a folder named **`design-handoff-end/`** (a `.zip` is
fine), with this structure:

```
design-handoff-end/
  index.html                 one HTML file per "own-page" sitemap node
  <page>.html                (e.g. products.html, product.html, contact.html)
  assets/
    css/style.css            ONE shared stylesheet for the whole site
    js/main.js               ONE shared script for the whole site
    fonts/*.woff2            self-hosted fonts (no CDN)
    img/…                    exported images used by the design
    data/*.json              placeholder JSON the data-driven pages read
  README.md                  (optional) notes on structure / where data plugs in
```

Rules the build relies on:

1. **One HTML file per own-page node.** Clean names: `index.html`,
   `products.html`, `product.html`, `contact.html`. Section-only nodes are
   anchors within their parent page, not separate files.
2. **One shared `assets/css/style.css` and one shared `assets/js/main.js`.** Not
   per-page CSS/JS. If a page needs behaviour, put it in the shared script and
   have it **no-op when its root element is absent** (so one file serves every
   page). Reference them with a version query: `style.css?v=1`, `main.js?v=1`.
3. **No runtime third-party requests.** No CDN scripts, no Google Fonts link,
   no remote images. **Self-host fonts** under `assets/fonts/` with `@font-face`.
4. **Data-driven pages read placeholder JSON**, not hardcoded markup. Put sample
   data in `assets/data/` (e.g. `items.json`, `content.json`) and render cards /
   detail from it in `main.js`. Each item has at least `slug`, `name`, a
   `category`, and an image path. The detail page reads `?p=<slug>` (or
   `?slug=`). Leave the page's built-in copy as a sensible fallback.
5. **Static HTML/CSS/vanilla JS only.** No framework, no build step.
6. **Accessibility & motion:** WCAG AA contrast; visible focus states; respect
   `prefers-reduced-motion`. Never animate an element **from** `opacity:0` with
   fill-mode/delay on content that must be visible — a renderer that starts but
   never advances the animation parks it invisible. Animate *to* a hidden state
   and back *from* a visible element instead.
7. **Responsive:** works cleanly at 360 / 768 / 1280px, no horizontal overflow.

Return the folder following this contract. The build step will consume it as-is
and only reconcile anything that drifted from these rules.
