# 03 — Front-end runtime (`assets/js/main.js`)

The whole front end is **one shared IIFE** in `assets/js/main.js`, loaded by
every page. Each page's behaviour is a guarded init step that **no-ops when its
root element is absent**, so one file serves every page with no per-page
branching. A starting `main.js` is in `templates/main.js`.

## Skeleton
```js
(function () {
  "use strict";
  var BASE = "";                         // "" = read data/img at the site root

  // ---- tiny DOM/util helpers ($, $$, el, esc, clamp, reducedMotion) ----

  // ---- per-visit JSON cache (below) ----

  // ---- loaders: loadItems(cb), loadContent(cb), loadAttributes(cb) ----

  // ---- renderers, each guarded on its root element ----
  function init() {
    applyContent();       // no-ops if the elements it fills are absent
    renderCatalog();      // no-ops if #catalog is absent
    renderItem();         // no-ops if #item is absent
    setupForm();          // no-ops if the contact form is absent
    setupSearch();        // …
    // …one guarded call per page feature…
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else { init(); }
})();
```

Guarding pattern inside each renderer:
```js
function renderCatalog() {
  var root = document.getElementById("catalog");
  if (!root) { return; }                 // this page has no catalogue → skip
  loadItems(function (items) { /* build cards */ });
}
```

## Per-visit cache (paste this pattern)
The data JSON is served `no-cache`, so the admin never sees a stale edit; this
cache stops every navigation refetching the collection. A fresh window = fresh
fetch. It also de-dupes in-flight requests so two callers wanting the same file
share one fetch.
```js
var inFlight = {};
function cachedJSON(url, cb) {
  var key = "app:" + url;
  try { var hit = sessionStorage.getItem(key); if (hit) { cb(JSON.parse(hit)); return; } }
  catch (e) {}
  if (inFlight[url]) { inFlight[url].push(cb); return; }
  inFlight[url] = [cb];
  function done(d) { var w = inFlight[url] || []; delete inFlight[url]; w.forEach(function (fn) { fn(d); }); }
  fetch(url, { credentials: "same-origin" })
    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then(function (d) { try { sessionStorage.setItem(key, JSON.stringify(d)); } catch (e) {} done(d); })
    .catch(function () { done(null); });
}
```

## Loading + the single hidden-filter
```js
var ITEMS = [], itemsLoadFailed = false;
function loadItems(cb) {
  if (ITEMS.length) { cb(ITEMS); return; }
  cachedJSON(BASE + "assets/data/items.json", function (data) {
    itemsLoadFailed = !Array.isArray(data);           // "failed" ≠ "empty"
    ITEMS = (Array.isArray(data) ? data : []).filter(function (p) { return p && !p.hidden; });
    cb(ITEMS);
  });
}
```
Filtering `hidden` here — once — is the whole reason every downstream consumer is
correct without repeating the check.

## Content application with HTML fallback
`applyContent()` merges `content.json` over the copy already in the HTML. **Blank
admin fields fall back to the HTML default** (skip empty strings), so an empty
field never wipes visible text. Multi-line admin fields render `\n`→`<br>` via a
`setMultiline()` helper that inserts **text nodes** (no markup injection). Re-sync
`tel:`/`mailto:` hrefs from the admin values.

## Rendering data-driven pages
- **List/catalogue page**: read the query string. `?cat=<id>` → that category;
  `?q=<term>` → client-side AND-search across the item's text fields (debounced);
  neither → the default view. Mirror the active query to the URL with
  `history.replaceState` so results are shareable, and seed from the URL on load.
- **Detail page**: read `?slug=`/`?p=`, look the item up in the (hidden-filtered)
  collection, render the template. **Unknown slug → an honest "not found" state
  with a real `<h1>`**, not a silent fallback to the first item. Set per-item
  `<title>`, canonical, `og:*` and meta description at runtime.
- **Images** are versioned: append `?v=<imgV>` so a replaced photo shows despite
  the immutable cache.

## The animation-safety rule (critical — applies to CSS too)
Never animate **from** `opacity:0` using `animation-fill-mode`/`delay` on content
that must be visible. A renderer that *starts but never advances* an animation
(throttled background tab, headless/crawler render) parks it invisible —
permanently, with or without fill-mode. This has shipped as a real bug more than
once.

Safe shapes:
- Animate **to** a hidden state and back **from** an already-visible element
  (e.g. a photo-swap crossfade).
- Scope scroll-reveal classes to a `.js` root set by an inline `<head>` script,
  with a **re-armable failsafe** that force-shows the element if the observer
  never fires. Intersection observers silently never fire in throttled/headless
  renderers — assume it and provide the failsafe.
- For any signature entrance, add a timeout guard that strips the animation and
  reveals the element if it's still transparent after ~1.4s.

## Motion budget
One signature entrance at most; sibling stagger on **real lists only** (capped ~8
steps); press feedback on pressable things; honour `prefers-reduced-motion` for
anything that moves on its own (kill the behaviour, not just the CSS duration).
Do not fade-and-rise every section on scroll — that is the saturated tell.
