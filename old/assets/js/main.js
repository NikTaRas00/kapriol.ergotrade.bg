/* =============================================================================
   KAPRIOL.BG — main.js  (plain ES, no dependencies)

   Edit content here, not in index.html:
     • COPY     — every visible UI string (ready for a BG/EN switcher later).
     • PRODUCTS — the catalog. Add/edit/remove items; cards re-render from this.
   ========================================================================== */
(function () {
  "use strict";

  /* ----------------------------------------------------------- 1. COPY ---- */
  /* All chrome strings live here. To add Bulgarian later: make COPY_BG with the
     same keys and swap which object you pass to hydrate(). No HTML edits needed. */
  var COPY = {
    // Nav
    navBrand: "KAPRIOL",
    navProducts: "Discover our products",
    navContact: "Contact us",
    navAbout: "Who are we?",

    // Hero  ( \n in heroH1 becomes a <br> )
    heroLabel: "OFFICIAL BULGARIA DISTRIBUTOR",
    heroH1: "Built for work.\nMade by Kapriol.",
    heroSub: "Premium workwear, footwear and PPE. Distributed exclusively in Bulgaria by ergotrade.bg.",
    heroCta: "Discover the catalog",
    heroGhost: "Who are we?",

    // About
    aboutHeading: "Who we are",
    aboutQuote: "Kapriol has equipped professionals across Europe since 1974.",
    aboutP1: "Founded in Italy in 1974, Kapriol builds workwear, footwear and protective equipment for people who work with their hands. Five decades of field-tested gear, engineered to outlast the job.",
    aboutP2: "In Bulgaria, Kapriol is distributed exclusively by ergotrade.bg. Every item in this catalog is sourced direct, held in local stock and backed by a team that knows the trades it supplies.",
    aboutP3: "From the first layer to the last line of defence, the standard does not move.",

    // Products
    productsHeading: "Our Catalog",
    productsLead: "The full Kapriol range of professional workwear, footwear and PPE — sourced direct and held in local stock by ergotrade.bg. Found something you need? Get in touch for stock and pricing.",
    // Category labels (also used as headings on a single-category page)
    catFootwear:            "Footwear & Safety Shoes",
    catFootwearAccessories: "Footwear Accessories & Care",
    catClothing:            "Workwear & Professional Clothing",
    catClothingAccessories: "Clothing Accessories",
    catPPE:                 "PPE & Protection",
    catPPEAccessories:      "PPE Accessories",
    // Catalog UI
    catShowAll: "View all",          // "View all (47)" — appended with count
    catBackAll: "All categories",    // back link on a single-category page
    catLeadOne: "Every Kapriol {cat} item we stock, sourced direct by ergotrade.bg. Get in touch for stock and pricing.",
    cardLink: "View details",
    // Search
    searchPlaceholder: "Search products…",
    searchResults: "{n} results for {q}",        // {n}=count, {q}=query (bolded)
    searchResult: "1 result for {q}",
    searchEmptyTitle: "No matches for “{q}”",
    searchEmptyBody: "Try a different term, a product line, or a category name like “footwear” or “PPE”.",
    searchEmptyAction: "Browse the full catalog",

    // Product detail page
    detailCrumbHome: "Products",
    detailFeatures: "Key features",
    detailOverview: "Overview",
    specPerformance: "Performance",
    specSafetyClass: "Safety class",
    specTechnologies: "Technologies",
    detailBack: "Back to catalog",
    detailNote: "Distributed exclusively in Bulgaria by ergotrade.bg — held in local stock and backed by a team that knows the trades it supplies.",
    notFoundTitle: "We couldn't find that product",
    notFoundBody: "The link may be out of date or the item is no longer listed. Browse the full catalog to find what you need.",

    // Best sellers showcase (landing page, under About)
    bestEyebrow: "BEST SELLERS",
    bestHeading: "Title",
    bestSub: "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
    bestCta: "Buy now",
    bestStat1Num: "4.5k+",
    bestStat1Label: "Satisfied customers",
    bestStat2Num: "5 Year",
    bestStat2Label: "Guarantee",
    bestC1Title: "Characteristic 1",
    bestC1Body: "Lorem ipsum dolor sit amet, consectetur adipiscing elit.",
    bestC2Title: "Characteristic 2",
    bestC2Body: "Lorem ipsum dolor sit amet, consectetur adipiscing elit.",

    // Contact
    contactHeading: "Get in touch",
    contactLead: "Questions on stock, pricing or becoming a reseller? Send a message and the ergotrade.bg team will get back to you.",
    contactPhoneLabel: "Phone",
    contactPhone: "PHONE_PLACEHOLDER",
    contactEmailLabel: "Email",
    contactEmail: "info@kapriol.bg",
    contactAddrLabel: "Address",
    contactAddr: "ADDRESS_PLACEHOLDER",
    formName: "Name",
    formEmail: "Email",
    formPhone: "Phone",
    formMessage: "Message",
    formSubmit: "Send message",
    formSending: "Sending…",
    formSuccess: "Thank you. We'll be in touch.",
    formError: "Something went wrong. Please try again, or email info@kapriol.bg.",
    formInvalid: "Please complete every field with a valid email address.",


    // Footer
    footerWordmark: "KAPRIOL.BG",
    footerTag: "Exclusive distributor for Bulgaria",
    footerAbout: "About",
    footerProducts: "Products",
    footerContact: "Contact",
    footerCopy: "© 2026 ergotrade.bg",
    footerPowered: "Powered by ergotrade.bg"
  };

  /* --------------------------------------------------------- 2. PRODUCTS -- */
  /* The catalog (334 items) is loaded at runtime from assets/data/products.json
     so the data lives in one editable file, not inline here. Each item:
       • category  — one of CATEGORIES[].id below
       • slug      — stable id used in product.html?p=<slug> (never reuse one)
       • name / description / img
       • features  — bullet list on the detail page
       • overview  — array of paragraphs for the longer description block
     (plus richer fields: articleCodes, sizes, colors, material, standards…)
     PRODUCTS starts empty and is filled by loadProducts() before rendering. */
  var PRODUCTS = [];
  var PRODUCTS_URL = "assets/data/products.json";
  var CONTENT_URL  = "assets/data/content.json";   // admin-editable site copy

  /* ---- Per-visit data cache -------------------------------------------------
     The data files (esp. products.json ≈ 0.5 MB) are served `no-cache` so admin
     edits never go stale, which means every page navigation re-downloads + re-
     parses them — the source of the lag when moving between pages. We cache the
     raw JSON text in sessionStorage: it persists across navigations WITHIN this
     tab (one visit, so the heavy file is fetched once), but a brand-new window
     or tab starts with an empty store and re-fetches fresh. Bump CACHE_NS to
     invalidate after a data-shape change. Degrades to a plain fetch if storage
     is unavailable or full. */
  var CACHE_NS = "kap_cache_v1:";
  function cachedJSON(url, cb) {
    var key = CACHE_NS + url;
    try {
      var hit = sessionStorage.getItem(key);
      if (hit) { cb(JSON.parse(hit)); return; }
    } catch (e) { /* parse error / storage blocked → fall through to fetch */ }
    if (!("fetch" in window)) { cb(null); return; }
    fetch(url)
      .then(function (r) { if (!r.ok) throw new Error("HTTP " + r.status); return r.text(); })
      .then(function (txt) {
        var data = JSON.parse(txt);
        try { sessionStorage.setItem(key, txt); } catch (e) { /* quota/full: skip caching */ }
        cb(data);
      })
      .catch(function () { cb(null); });
  }

  /* Editable site copy (hero text, phone/address, best-seller copy) lives in
     content.json so the admin can change it without touching this file. We merge
     it over COPY/BEST as overrides; if the fetch fails we keep the hardcoded
     values above, so the site always renders. */
  function applyContent(done) {
    cachedJSON(CONTENT_URL, function (j) {
        if (j && j.copy) {
          for (var k in j.copy) {
            if (j.copy.hasOwnProperty(k) && j.copy[k] != null && j.copy[k] !== "") COPY[k] = j.copy[k];
          }
        }
        if (j && Array.isArray(j.best)) {
          for (var i = 0; i < BEST.length && i < j.best.length; i++) {
            var src = j.best[i] || {};
            // A slot bound to a catalog product carries a slug — take its
            // snapshot wholesale (incl. an empty img, so it falls back to the
            // placeholder card instead of the seed best-N.png image).
            if (src.slug) {
              BEST[i].slug = src.slug;
              BEST[i].name = src.name || "";
              BEST[i].img  = src.img || "";
              BEST[i].imgV = src.imgV || "";
            } else {
              if (src.name) BEST[i].name = src.name;
              if (src.img)  BEST[i].img  = src.img;
            }
          }
        }
        // Per-row show/hide flags for the contact details (admin-managed).
        if (j && j.visible && typeof j.visible === "object") {
          for (var vk in j.visible) {
            if (j.visible.hasOwnProperty(vk)) CONTACT_VISIBLE[vk] = j.visible[vk] !== false;
          }
        }
        if (j && j.categories && typeof j.categories === "object") {
          for (var ci = 0; ci < CATEGORIES.length; ci++) {
            var cid = CATEGORIES[ci].id, cv = j.categories[cid];
            if (cv && cv.img) CATEGORY_IMG[cid] = { img: cv.img, imgV: cv.imgV || "" };
          }
        }
        done();
    });   // cachedJSON never throws → if it fails, j is null and we keep COPY/BEST
  }

  /* The tel:/mailto: hrefs are literal placeholders in the HTML; sync them to the
     (possibly admin-edited) values after hydrate, so the link dials/mails what
     the page actually shows. */
  function patchContactLinks() {
    var tel = document.querySelector('a[href^="tel:"][data-copy="contactPhone"]');
    if (tel && COPY.contactPhone) {
      tel.setAttribute("href", "tel:" + String(COPY.contactPhone).replace(/\s+/g, ""));
    }
    var mail = document.querySelector('a[href^="mailto:"][data-copy="contactEmail"]');
    if (mail && COPY.contactEmail) {
      mail.setAttribute("href", "mailto:" + String(COPY.contactEmail).trim());
    }
  }

  /* Contact rows the admin can switch off (Site content → Contact details).
     Missing/!== false means shown, so an older content.json with no `visible`
     block keeps all three visible. A row is also hidden when its value is still
     an unedited PLACEHOLDER, so the raw token never faces a visitor. */
  var CONTACT_ROWS = [
    { id: "info-phone", key: "contactPhone" },
    { id: "info-email", key: "contactEmail" },
    { id: "info-addr",  key: "contactAddr"  }
  ];
  function applyContactVisibility() {
    for (var i = 0; i < CONTACT_ROWS.length; i++) {
      var row = document.getElementById(CONTACT_ROWS[i].id);
      if (!row) continue;
      var val = COPY[CONTACT_ROWS[i].key];
      var on = CONTACT_VISIBLE[CONTACT_ROWS[i].key] !== false;
      if (!val || /PLACEHOLDER/.test(String(val))) on = false;
      row.hidden = !on;
    }
  }

  function loadProducts(cb) {
    if (PRODUCTS.length) { cb(true); return; }
    cachedJSON(PRODUCTS_URL, function (data) {
      if (!Array.isArray(data)) { cb(false); return; }   // fetch failed → error state
      PRODUCTS = data;
      cb(true);
    });
  }

  /* Category order + heading keys (into COPY). Order = display order on the
     all-categories products page. id must match the JSON `category` field. */
  var CATEGORIES = [
    { id: "footwear",             titleKey: "catFootwear" },
    { id: "footwear-accessories", titleKey: "catFootwearAccessories" },
    { id: "clothing",             titleKey: "catClothing" },
    { id: "clothing-accessories", titleKey: "catClothingAccessories" },
    { id: "ppe",                  titleKey: "catPPE" },
    { id: "ppe-accessories",      titleKey: "catPPEAccessories" }
  ];
  // Per-category landing image (admin-editable, stored in content.json as
  // `categories: { <id>: { img, imgV } }`). Filled by applyContent(); a category
  // with no image falls back to a neutral "KAPRIOL" placeholder tile.
  var CATEGORY_IMG = {};

  // Which contact rows are shown, from content.json `visible`. Defaults to all
  // on, so a content.json written before this feature behaves as before.
  var CONTACT_VISIBLE = { contactPhone: true, contactEmail: true, contactAddr: true };

  /* ------------------------------------------- 2a. PRODUCT SPEC GROUPS ----
     Footwear detail pages end with three grouped attribute strips:
     Performance, Safety class and Technologies. Each product stores arrays of
     codes (`performance` / `safetyClass` / `technologies`); the master list of
     codes — their human-readable names and (for performance/technologies) an
     optional pictogram — is admin-editable and loaded at runtime from
     attributes.json. A code whose definition has an `img` renders as a
     pictogram (with the code as caption); otherwise it's a text bullet.
     Safety class is text-only by design. ATTRIBUTES starts empty and is filled
     by loadAttributes(); a missing file just yields empty groups. */
  var SPEC_GROUPS = [
    { key: "performance",  labelKey: "specPerformance"  },
    { key: "safetyClass",  labelKey: "specSafetyClass"  },
    { key: "technologies", labelKey: "specTechnologies" }
  ];
  var ATTRIBUTES = { performance: [], safetyClass: [], technologies: [] };
  var ATTRIBUTES_URL = "assets/data/attributes.json";

  /* ----------------------------------------------------- 2b. BEST SELLERS -- */
  /* The rotating showcase under "Who we are" on the landing page. To swap a
     product, drop a transparent PNG into assets/img/products/ and point `img`
     at it (square-ish, subject centred — it's shown with object-fit: contain). */
  var BEST = [
    { img: "assets/img/products/best-1.png", name: "Product 1" },
    { img: "assets/img/products/best-2.png", name: "Product 2" },
    { img: "assets/img/products/best-3.png", name: "Product 3" }
  ];

  /* ------------------------------------------------------------ helpers -- */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
  // Product photos are cached hard (1yr) for speed, but reuse the same filename
  // per slug. The admin stamps `imgV` on every upload; appending it as ?v= busts
  // the cache only when the photo actually changes, so replacements show at once.
  function productImg(p) {
    if (!p || !p.img) return "";
    return p.imgV ? (p.img + "?v=" + p.imgV) : p.img;
  }
  // Category landing image (same cache-busting contract as productImg).
  function categoryImg(id) {
    var c = CATEGORY_IMG[id];
    if (!c || !c.img) return "";
    return c.imgV ? (c.img + "?v=" + c.imgV) : c.img;
  }
  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
    });
  }
  /* Whitelist-sanitise a stored HTML string before injecting it (product
     `fullDescription` comes from the catalog import / admin JSON). Keeps only
     semantic text tags, strips every attribute, drops anything else. */
  var SAFE_TAGS = { P:1, UL:1, OL:1, LI:1, STRONG:1, B:1, EM:1, I:1, BR:1, H3:1, H4:1, H5:1, SPAN:1 };
  function sanitizeHtml(html) {
    var tpl = document.createElement("template");
    tpl.innerHTML = String(html || "");
    (function walk(node) {
      var child = node.firstChild;
      while (child) {
        if (child.nodeType === 1) {                       // element
          if (SAFE_TAGS[child.tagName]) {
            while (child.attributes.length) child.removeAttribute(child.attributes[0].name);
            walk(child);
            child = child.nextSibling;
          } else {                                        // unwrap: keep children, drop tag
            var nxt = child.nextSibling;
            var first = child.firstChild;
            while (child.firstChild) node.insertBefore(child.firstChild, child);
            node.removeChild(child);
            child = first || nxt;                         // re-process unwrapped children
          }
        } else if (child.nodeType === 3) {                // text
          child = child.nextSibling;
        } else {                                          // comment / other
          var rm = child; child = child.nextSibling; node.removeChild(rm);
        }
      }
    })(tpl.content);
    return tpl.innerHTML;
  }

  var reduceMotion = window.matchMedia &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* --------------------------------------------------- 3. hydrate COPY ---- */
  function hydrate(dict) {
    var nodes = document.querySelectorAll("[data-copy]");
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var key = el.getAttribute("data-copy");
      if (!dict.hasOwnProperty(key)) continue;
      var val = dict[key];
      if (val.indexOf("\n") >= 0) el.innerHTML = esc(val).replace(/\n/g, "<br>");
      else el.textContent = val;
    }
    // Placeholder strings (e.g. the search input).
    var ph = document.querySelectorAll("[data-copy-ph]");
    for (var k = 0; k < ph.length; k++) {
      var pk = ph[k].getAttribute("data-copy-ph");
      if (dict.hasOwnProperty(pk)) ph[k].setAttribute("placeholder", dict[pk]);
    }
  }

  /* --------------------------------------------------- 4. render catalog -- */
  var ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
  var ARROW_L = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>';

  function catLabel(id) {
    for (var i = 0; i < CATEGORIES.length; i++) {
      if (CATEGORIES[i].id === id) return COPY[CATEGORIES[i].titleKey] || id;
    }
    return id;
  }
  function findCategory(id) {
    for (var i = 0; i < CATEGORIES.length; i++) if (CATEGORIES[i].id === id) return CATEGORIES[i];
    return null;
  }

  // One product card. `i` drives the staggered reveal delay; `instant` skips the
  // entrance (used for search results, which re-render on every keystroke).
  function buildCard(p, i, instant) {
    var card = document.createElement("article");
    card.className = instant ? "card reveal is-visible" : "card reveal";
    card.style.setProperty("--rd", ((i % 9) * 0.05) + "s");   // staggered reveal

    var media = document.createElement("div");
    media.className = "card__media";
    if (p.img) {
      var img = document.createElement("img");
      img.src = productImg(p);
      img.alt = p.name;
      img.loading = "lazy";
      img.decoding = "async";
      img.addEventListener("error", function () {
        media.innerHTML = '';
        media.classList.add("card__media--empty");
        media.innerHTML = '<span class="card__ph" aria-hidden="true">KAPRIOL</span>';
      });
      media.appendChild(img);
    } else {
      media.classList.add("card__media--empty");
      media.innerHTML = '<span class="card__ph" aria-hidden="true">KAPRIOL</span>';
    }

    var body = document.createElement("div");
    body.className = "card__body";
    var name = document.createElement("h4");
    name.className = "card__name";
    name.textContent = p.name;
    var desc = document.createElement("p");
    desc.className = "card__desc";
    desc.textContent = p.description || "";
    var link = document.createElement("a");
    link.className = "card__link";
    link.href = "product?p=" + encodeURIComponent(p.slug);   // open detail page
    link.innerHTML = esc(COPY.cardLink) + ARROW;

    body.appendChild(name);
    body.appendChild(desc);
    body.appendChild(link);
    card.appendChild(media);
    card.appendChild(body);
    return card;
  }

  function makeGrid(items, startIdx, instant) {
    var grid = document.createElement("div");
    grid.className = "cards";
    items.forEach(function (p, i) { grid.appendChild(buildCard(p, (startIdx || 0) + i, instant)); });
    return grid;
  }

  // products.html renders three ways:
  //   • search active → flat grid of every product matching the query
  //   • no ?cat       → the six categories as image tiles (2 per row)
  //   • ?cat=<id>     → a single category, all its products, with a back link
  // searchQuery is the live query (set by setupSearch, mirrored to ?q= in the URL).
  var searchQuery = "";

  function renderCatalog() {
    var root = document.getElementById("catalog");
    if (!root) return;
    root.innerHTML = "";
    root.className = "catalog";   // reset any single-category modifier

    if (searchQuery.trim()) { renderSearch(root, searchQuery.trim()); return; }

    var catId = "";
    try { catId = new URLSearchParams(location.search).get("cat") || ""; } catch (e) {}
    var single = catId ? findCategory(catId) : null;

    if (single) renderSingleCategory(root, single);
    else renderAllCategories(root);
  }

  // Case-insensitive AND-match across name, description, features, product line
  // and the category label, so "ppe gloves" or "dune black" both work.
  function searchProducts(q) {
    var terms = q.toLowerCase().split(/\s+/).filter(Boolean);
    if (!terms.length) return [];
    return PRODUCTS.filter(function (p) {
      var hay = (
        (p.name || "") + " " +
        (p.description || "") + " " +
        ((p.features || []).join(" ")) + " " +
        (p.productLine || "") + " " +
        catLabel(p.category)
      ).toLowerCase();
      for (var i = 0; i < terms.length; i++) if (hay.indexOf(terms[i]) < 0) return false;
      return true;
    });
  }

  function renderSearch(root, q) {
    var results = searchProducts(q);

    // Keep the page header/intro stable; result feedback lives below the search
    // box (next to the results), not in place of the page lead above it.
    var heading = document.querySelector('[data-copy="productsHeading"]');
    if (heading) heading.textContent = COPY.productsHeading;
    var lead = document.querySelector('[data-copy="productsLead"]');
    if (lead) lead.textContent = COPY.productsLead;
    document.title = "“" + q + "” — Kapriol Bulgaria";

    if (!results.length) {
      root.appendChild(buildSearchEmpty(q));
      return;
    }

    var meta = document.createElement("p");
    meta.className = "catalog__meta";
    var tmpl = results.length === 1 ? COPY.searchResult : COPY.searchResults;
    meta.innerHTML = esc(tmpl.replace("{n}", results.length))
      .replace("{q}", '<strong>“' + esc(q) + '”</strong>');
    root.appendChild(meta);
    root.appendChild(makeGrid(results, 0, true));   // instant: no per-keystroke fade
  }

  // Friendly no-match state with a one-tap way back to the full catalog.
  function buildSearchEmpty(q) {
    var box = document.createElement("div");
    box.className = "catalog__empty";
    box.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>' +
      '<h3>' + esc(COPY.searchEmptyTitle.replace("{q}", q)) + '</h3>' +
      '<p>' + esc(COPY.searchEmptyBody) + '</p>';
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "btn btn--outline";
    btn.textContent = COPY.searchEmptyAction;
    btn.addEventListener("click", function () {
      var input = document.getElementById("productSearch");
      if (input) { input.value = ""; input.dispatchEvent(new Event("input", { bubbles: true })); input.focus(); }
    });
    box.appendChild(btn);
    return box;
  }

  // Landing view: the six categories as image tiles (2 per row on desktop,
  // 1 on phones), each linking to its single-category page. Replaces the older
  // "3 products + View all" strips so the page stays compact and visual.
  function renderAllCategories(root) {
    // Reset the header in case we're coming back from search / a category page.
    var heading = document.querySelector('[data-copy="productsHeading"]');
    if (heading) heading.textContent = COPY.productsHeading;
    var lead = document.querySelector('[data-copy="productsLead"]');
    if (lead) lead.textContent = COPY.productsLead;
    document.title = "Products — Kapriol Bulgaria | Workwear, Footwear & PPE";

    var grid = document.createElement("div");
    grid.className = "cat-tiles";

    CATEGORIES.forEach(function (cat, i) {
      var count = PRODUCTS.filter(function (p) { return p.category === cat.id; }).length;
      if (!count) return;
      grid.appendChild(buildCategoryTile(cat, count, i));
    });

    root.appendChild(grid);
  }

  // One category tile: a full-tile link to ?cat=<id> with the category image
  // (or a neutral KAPRIOL placeholder), the category name and a product count.
  function buildCategoryTile(cat, count, i) {
    var label = COPY[cat.titleKey] || cat.id;
    var src = categoryImg(cat.id);

    var a = document.createElement("a");
    a.className = "cat-tile reveal";
    a.href = "products?cat=" + encodeURIComponent(cat.id);
    a.style.setProperty("--rd", i);   // staggered reveal

    var media = document.createElement("span");
    media.className = "cat-tile__media";

    function placeholder() {
      a.classList.add("cat-tile--empty");
      media.innerHTML = '<span class="cat-tile__ph" aria-hidden="true">KAPRIOL</span>';
    }
    if (src) {
      var img = document.createElement("img");
      img.className = "cat-tile__img";
      img.src = src; img.alt = ""; img.loading = "lazy"; img.decoding = "async";
      img.addEventListener("error", placeholder);   // missing file → placeholder
      media.appendChild(img);
    } else {
      placeholder();
    }

    var body = document.createElement("span");
    body.className = "cat-tile__body";
    body.innerHTML =
      '<span class="cat-tile__name">' + esc(label) + '</span>' +
      '<span class="cat-tile__count">' + count + ' ' + (count === 1 ? "product" : "products") + ARROW + '</span>';

    a.appendChild(media);
    a.appendChild(body);
    return a;
  }

  function renderSingleCategory(root, cat) {
    root.className = "catalog catalog--single";   // tighter gap: back link hugs the grid
    var items = PRODUCTS.filter(function (p) { return p.category === cat.id; });
    var label = COPY[cat.titleKey] || cat.id;

    // Retitle the page header for this category.
    document.title = label + " — Kapriol Bulgaria";
    var heading = document.querySelector('[data-copy="productsHeading"]');
    if (heading) heading.textContent = label;
    var lead = document.querySelector('[data-copy="productsLead"]');
    if (lead) lead.textContent = (COPY.catLeadOne || "").replace("{cat}", label.toLowerCase());

    // Back to the full catalog.
    var back = document.createElement("a");
    back.className = "catalog__back reveal";
    back.href = "products";
    back.innerHTML = ARROW_L + "<span>" + esc(COPY.catBackAll) + "</span>";
    root.appendChild(back);

    if (!items.length) return;

    // Footwear gets live spec filters (Performance / Safety class / Technologies);
    // every other category renders a plain grid.
    if (cat.id === "footwear") {
      renderFootwearFilters(root, items);
    } else {
      root.appendChild(makeGrid(items, 0));
    }
  }

  /* Footwear category filters ------------------------------------------------
     Three facets — Performance, Safety class, Technologies — whose options are
     derived live from the products in the category and labelled/pictogrammed
     from the master attributes.json list. Assign a code to a product in admin
     and it shows up here automatically; a facet with no codes yet is omitted.
     Within a facet selections are OR'd; across facets they're AND'd. */
  var FILTER_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>';
  var catFilters = null;

  // Filter options for one facet. The vocabulary is the ADMIN MASTER LIST only
  // (attributes.json), in its order — a code stored on a product but not defined
  // in admin is ignored, so no stray chips ever appear. We keep only master
  // codes that at least one product in the category actually carries, so there
  // are no dead (zero-result) filters.
  function specOptions(groupKey, items) {
    // Tally products per code (counted once per product, case-insensitive).
    var counts = {};
    items.forEach(function (p) {
      var raw = p[groupKey];
      var arr = Array.isArray(raw) ? raw : (raw == null || raw === "" ? [] : [raw]);
      var seen = {};
      arr.forEach(function (v) {
        var key = String(v).trim().toUpperCase();
        if (!key || seen[key]) return;                   // count each product once
        seen[key] = 1;
        counts[key] = (counts[key] || 0) + 1;
      });
    });
    var defs = Array.isArray(ATTRIBUTES[groupKey]) ? ATTRIBUTES[groupKey] : [];
    var out = [];
    defs.forEach(function (d) {
      if (!d || !d.code) return;
      var key = String(d.code).toUpperCase();
      if (counts[key]) out.push({ key: key, code: d.code, name: d.name || "", img: d.img || "", count: counts[key] });
    });
    out.sort(byCode);   // alphabetical (also drives the applied-filter pills / optIndex)
    return out;
  }

  function buildChip(groupKey, o) {
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "filter-chip" + (o.img ? " filter-chip--picto" : "");
    btn.setAttribute("data-group", groupKey);
    btn.setAttribute("data-code", o.key);
    btn.setAttribute("aria-pressed", "false");
    btn.title = o.name ? (o.code + " — " + o.name) : o.code;
    var html = "";
    if (o.img) html += '<img class="filter-chip__icon" src="' + esc(o.img) + '" alt="" width="22" height="22" loading="lazy" decoding="async" />';
    html += '<span class="filter-chip__label">' + esc(o.code) + '</span>';
    html += '<span class="filter-chip__count">' + o.count + '</span>';
    btn.innerHTML = html;
    return btn;
  }
  function buildFilterGroup(g, opts) {
    var wrap = document.createElement("div");
    wrap.className = "filter-group";
    wrap.setAttribute("data-group", g.key);
    var label = document.createElement("h3");
    label.className = "filter-group__label";
    label.textContent = COPY[g.labelKey] || g.key;
    wrap.appendChild(label);
    var chips = document.createElement("div");
    chips.className = "filter-chips";
    opts.forEach(function (o) { chips.appendChild(buildChip(g.key, o)); });
    wrap.appendChild(chips);
    return wrap;
  }
  // AND across every selected code: a product must carry ALL selected filters
  // (both within a facet and across facets) to match.
  function filterFootwear(items) {
    if (!catFilters) return items;
    return items.filter(function (p) {
      return SPEC_GROUPS.every(function (g) {
        var keys = Object.keys(catFilters[g.key]);
        if (!keys.length) return true;                  // facet inactive
        var raw = p[g.key];
        var arr = Array.isArray(raw) ? raw : (raw == null || raw === "" ? [] : [raw]);
        var have = {};
        arr.forEach(function (v) { have[String(v).trim().toUpperCase()] = 1; });
        for (var i = 0; i < keys.length; i++) if (!have[keys[i]]) return false;
        return true;
      });
    });
  }
  function buildFilterEmpty(onClear) {
    var box = document.createElement("div");
    box.className = "catalog__empty";
    box.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>' +
      '<h3>No footwear matches those filters</h3>' +
      '<p>Try removing a filter to widen the results.</p>';
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "btn btn--outline";
    btn.textContent = "Clear filters";
    btn.addEventListener("click", onClear);
    box.appendChild(btn);
    return box;
  }

  function renderFootwearFilters(root, items) {
    catFilters = { performance: {}, safetyClass: {}, technologies: {} };

    var groups = [];
    SPEC_GROUPS.forEach(function (g) {
      var opts = specOptions(g.key, items);
      if (opts.length) groups.push({ g: g, opts: opts });
    });
    // No footwear tagged with any code yet → just the grid, no empty filter bar.
    if (!groups.length) { root.appendChild(makeGrid(items, 0)); return; }

    // Index options by key per group → used to render the collapsed summary pills.
    var optIndex = {};
    groups.forEach(function (grp) {
      optIndex[grp.g.key] = {};
      grp.opts.forEach(function (o) { optIndex[grp.g.key][o.key] = o; });
    });

    var panel = document.createElement("section");
    panel.className = "filters reveal";   // collapsed by default (no .is-open)
    panel.setAttribute("aria-label", "Filter products");

    // ---- Always-visible bar: toggle button + applied-filter summary + clear ----
    var bar = document.createElement("div");
    bar.className = "filters__bar";

    var toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "filters__toggle";
    toggle.setAttribute("aria-expanded", "false");
    toggle.innerHTML =
      FILTER_ICON +
      '<span class="filters__toggle-label">Filter</span>' +
      '<span class="filters__badge" hidden></span>' +
      '<svg class="filters__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
    toggle.addEventListener("click", function () {
      var open = panel.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
    bar.appendChild(toggle);

    var applied = document.createElement("div");   // active filters, shown when collapsed
    applied.className = "filters__applied";
    bar.appendChild(applied);

    var clearBtn = document.createElement("button");
    clearBtn.type = "button";
    clearBtn.className = "filters__clear";
    clearBtn.textContent = "Clear all";
    clearBtn.hidden = true;
    clearBtn.addEventListener("click", clearAll);
    bar.appendChild(clearBtn);

    panel.appendChild(bar);

    // ---- Collapsible body: the facet groups (animated open via grid-rows) ----
    var body = document.createElement("div");
    body.className = "filters__body";
    var bodyInner = document.createElement("div");
    bodyInner.className = "filters__body-inner";
    groups.forEach(function (grp) { bodyInner.appendChild(buildFilterGroup(grp.g, grp.opts)); });
    body.appendChild(bodyInner);
    panel.appendChild(body);

    root.appendChild(panel);

    // ---- Results (meta + grid) ----
    var results = document.createElement("div");
    results.className = "filters__results";
    var meta = document.createElement("p");
    meta.className = "catalog__meta filters__meta";
    var gridWrap = document.createElement("div");
    results.appendChild(meta);
    results.appendChild(gridWrap);
    root.appendChild(results);

    var firstRender = true;

    function activeCount() {
      var n = 0;
      SPEC_GROUPS.forEach(function (g) { n += Object.keys(catFilters[g.key]).length; });
      return n;
    }
    // Collapsed summary: one removable pill per active selection.
    function renderApplied() {
      applied.innerHTML = "";
      SPEC_GROUPS.forEach(function (g) {
        Object.keys(catFilters[g.key]).forEach(function (key) {
          applied.appendChild(buildAppliedPill(g.key, key));
        });
      });
    }
    function groupLabel(groupKey) {
      for (var i = 0; i < SPEC_GROUPS.length; i++) {
        if (SPEC_GROUPS[i].key === groupKey) return COPY[SPEC_GROUPS[i].labelKey] || groupKey;
      }
      return groupKey;
    }
    function buildAppliedPill(groupKey, key) {
      var o = (optIndex[groupKey] && optIndex[groupKey][key]) || { code: key, name: "", img: "" };
      var gLabel = groupLabel(groupKey);
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "applied-chip";
      btn.setAttribute("data-group", groupKey);
      btn.setAttribute("aria-label", "Remove " + gLabel + " filter " + o.code);
      btn.title = "Remove " + gLabel + " — " + (o.name ? (o.code + " — " + o.name) : o.code);
      var html = "";
      html += '<span class="applied-chip__dot" aria-hidden="true"></span>';
      if (o.img) html += '<img class="applied-chip__icon" src="' + esc(o.img) + '" alt="" width="18" height="18" />';
      html += '<span class="applied-chip__code">' + esc(o.code) + '</span>';
      html += '<svg class="applied-chip__x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
      btn.innerHTML = html;
      btn.addEventListener("click", function () {
        delete catFilters[groupKey][key];
        var chip = panel.querySelector('.filter-chip[data-group="' + groupKey + '"][data-code="' + key.replace(/"/g, '\\"') + '"]');
        if (chip) { chip.classList.remove("is-active"); chip.setAttribute("aria-pressed", "false"); }
        update();
      });
      return btn;
    }
    function clearAll() {
      SPEC_GROUPS.forEach(function (g) { catFilters[g.key] = {}; });
      Array.prototype.forEach.call(panel.querySelectorAll(".filter-chip.is-active"), function (c) {
        c.classList.remove("is-active"); c.setAttribute("aria-pressed", "false");
      });
      update();
    }
    function update() {
      var filtered = filterFootwear(items);
      var active = activeCount();

      clearBtn.hidden = active === 0;
      var badge = toggle.querySelector(".filters__badge");
      badge.textContent = active;
      badge.hidden = active === 0;
      renderApplied();

      var noun = items.length === 1 ? "product" : "products";
      meta.innerHTML = active
        ? '<strong>' + filtered.length + '</strong> of ' + items.length + ' ' + noun
        : items.length + ' ' + noun;

      gridWrap.innerHTML = "";
      if (!filtered.length) {
        gridWrap.appendChild(buildFilterEmpty(clearAll));
      } else {
        // First paint animates the cards in; re-filters render instantly
        // (frequent action — shouldn't re-animate every toggle).
        gridWrap.appendChild(makeGrid(filtered, 0, !firstRender));
        if (firstRender) revealNew();
      }
      firstRender = false;
    }

    // One delegated handler for all facet chips (inside the collapsible body).
    panel.addEventListener("click", function (e) {
      var chip = e.target && e.target.closest ? e.target.closest(".filter-chip") : null;
      if (!chip || !panel.contains(chip)) return;
      var group = chip.getAttribute("data-group");
      var key = chip.getAttribute("data-code");
      var map = catFilters[group];
      if (map[key]) { delete map[key]; chip.classList.remove("is-active"); chip.setAttribute("aria-pressed", "false"); }
      else { map[key] = true; chip.classList.add("is-active"); chip.setAttribute("aria-pressed", "true"); }
      update();
    });

    update();
  }

  /* ----------------------------------------------- 4b. product detail page */
  var CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';

  function fillEmptyMedia(el) {
    el.classList.add("card__media--empty");
    el.innerHTML = '<span class="card__ph" aria-hidden="true">KAPRIOL</span>';
  }
  function setText(id, txt) {
    var el = document.getElementById(id);
    if (el) el.textContent = txt;
  }

  // Renders product.html from ?p=<slug>. Unknown/missing slug falls back to the
  // first catalog item so the page is never blank.
  function renderProductNotFound(root) {
    document.title = "Product not found — Kapriol Bulgaria";
    var c = root.querySelector(".container") || root;
    c.innerHTML =
      '<div class="product-notfound">' +
      '<h1>' + esc(COPY.notFoundTitle) + '</h1>' +
      '<p>' + esc(COPY.notFoundBody) + '</p>' +
      '<a class="btn btn--red" href="products">' + esc(COPY.detailBack) + '</a>' +
      '</div>';
  }

  // Create-or-update a <head> tag (canonical link / meta by name or property).
  function headTag(sel, make) {
    var el = document.head.querySelector(sel);
    if (!el) { el = make(); document.head.appendChild(el); }
    return el;
  }
  // Per-product SEO: product.html is one template for 334 ?p=<slug> pages, so a
  // static canonical/og would be wrong for every product. Set them at runtime
  // from the resolved product. Origin comes from location, so it's move-proof.
  function setProductSeo(product) {
    var origin = location.origin;
    var url = origin + "/product?p=" + encodeURIComponent(product.slug);
    var desc = (product.description || "").trim() ||
      "Kapriol professional workwear, footwear and PPE, distributed in Bulgaria by ergotrade.bg.";
    var img = product.img ? (origin + "/" + product.img) : (origin + "/assets/img/poster.jpg");

    headTag('link[rel="canonical"]', function () {
      var l = document.createElement("link"); l.setAttribute("rel", "canonical"); return l;
    }).setAttribute("href", url);

    var metas = {
      "og:url": url,
      "og:title": product.name + " — Kapriol Bulgaria",
      "og:description": desc,
      "og:image": img
    };
    Object.keys(metas).forEach(function (prop) {
      headTag('meta[property="' + prop + '"]', function () {
        var m = document.createElement("meta"); m.setAttribute("property", prop); return m;
      }).setAttribute("content", metas[prop]);
    });
    headTag('meta[name="description"]', function () {
      var m = document.createElement("meta"); m.setAttribute("name", "description"); return m;
    }).setAttribute("content", desc);
  }

  function renderProduct() {
    var root = document.getElementById("product-detail");
    if (!root) return;

    var slug = "";
    try { slug = new URLSearchParams(location.search).get("p") || ""; } catch (e) {}
    var product = null;
    for (var i = 0; i < PRODUCTS.length; i++) {
      if (PRODUCTS[i].slug === slug) { product = PRODUCTS[i]; break; }
    }
    // A slug that matches nothing gets an honest not-found, not a random product.
    // (No slug at all still falls back to the first item so a bare visit isn't blank.)
    if (!product) {
      if (slug) { renderProductNotFound(root); return; }
      product = PRODUCTS[0];
    }

    var cat = null;
    for (var j = 0; j < CATEGORIES.length; j++) {
      if (CATEGORIES[j].id === product.category) { cat = CATEGORIES[j]; break; }
    }
    var catTitle = cat ? (COPY[cat.titleKey] || cat.id) : "";

    document.title = product.name + " — Kapriol Bulgaria";
    setProductSeo(product);
    setText("pd-eyebrow", catTitle);
    // Breadcrumb: Products / <category, links to that category page> / <product>.
    var crumbCat = document.getElementById("pd-crumb-cat");
    if (crumbCat) {
      crumbCat.textContent = catTitle;
      crumbCat.href = cat ? ("products?cat=" + encodeURIComponent(cat.id)) : "products";
    }
    setText("pd-crumb-name", product.name);
    setText("pd-name", product.name);
    setText("pd-desc", product.description);

    var media = document.getElementById("pd-media");
    if (media) {
      // Gallery: a product can carry `images[]` (multiple photos). Fall back to the
      // single `img`. The first entry is the primary/hero shot; extras render as a
      // clickable thumbnail strip that swaps the main image.
      var gallery = (product.images && product.images.length)
        ? product.images.slice()
        : (product.img ? [product.img] : []);
      var ver = product.imgV ? ("?v=" + product.imgV) : "";
      // strip any stale thumbnails from a prior render (renderProduct is idempotent)
      var oldThumbs = media.parentNode && media.parentNode.querySelector(".product-thumbs");
      if (oldThumbs) oldThumbs.parentNode.removeChild(oldThumbs);

      if (gallery.length) {
        var mainImg = document.createElement("img");
        mainImg.src = gallery[0] + ver;
        mainImg.alt = product.name;
        mainImg.decoding = "async";
        mainImg.addEventListener("error", function () { media.innerHTML = ""; fillEmptyMedia(media); });
        media.appendChild(mainImg);

        if (gallery.length > 1) {
          var thumbs = document.createElement("div");
          thumbs.className = "product-thumbs";
          gallery.forEach(function (src, idx) {
            var b = document.createElement("button");
            b.type = "button";
            b.className = "product-thumb" + (idx === 0 ? " is-active" : "");
            b.setAttribute("aria-label", product.name + " — photo " + (idx + 1));
            var t = document.createElement("img");
            t.src = src + ver; t.alt = ""; t.loading = "lazy"; t.decoding = "async";
            b.appendChild(t);
            b.addEventListener("click", function () {
              mainImg.src = src + ver;
              var all = thumbs.querySelectorAll(".product-thumb");
              for (var k = 0; k < all.length; k++) all[k].classList.remove("is-active");
              b.classList.add("is-active");
            });
            thumbs.appendChild(b);
          });
          media.parentNode.appendChild(thumbs);
        }
      } else {
        fillEmptyMedia(media);
      }
    }

    var ul = document.getElementById("pd-features");
    var block = document.getElementById("pd-features-block");
    if (ul && product.features && product.features.length) {
      product.features.forEach(function (f) {
        var li = document.createElement("li");
        li.className = "feature";
        li.innerHTML = CHECK;
        var span = document.createElement("span");
        span.textContent = f;
        li.appendChild(span);
        ul.appendChild(li);
      });
    } else if (block) {
      block.style.display = "none";
    }

    // Longer description block lower down the page. Prefer the rich catalog
    // `fullDescription` (HTML, sanitised); fall back to the plain-text
    // `overview` paragraphs for products without one; hide if neither exists.
    var ov = document.getElementById("pd-overview");
    var ovBlock = document.getElementById("pd-overview-block");
    if (ov && product.fullDescription) {
      ov.classList.add("product-overview__body--rich");
      ov.innerHTML = sanitizeHtml(product.fullDescription);
    } else if (ov && product.overview && product.overview.length) {
      product.overview.forEach(function (para) {
        var p = document.createElement("p");
        p.textContent = para;
        ov.appendChild(p);
      });
    } else if (ovBlock) {
      ovBlock.style.display = "none";
    }

    renderFacts(product);
    renderSpecs(product);
  }

  /* Specifications datasheet: material / sizes / colours / standards as labelled
     rows, plus the article-code table. Renders only the fields the product has;
     the whole #pd-facts-block hides when none are set. */
  function renderFacts(product) {
    var block = document.getElementById("pd-facts-block");
    if (!block) return;
    var dl = document.getElementById("pd-facts");
    var codesWrap = document.getElementById("pd-codes-wrap");
    var codesBox = document.getElementById("pd-codes");
    if (dl) dl.innerHTML = "";
    if (codesBox) codesBox.innerHTML = "";

    function chips(arr) {
      var span = document.createElement("span");
      span.className = "chips";
      arr.forEach(function (v) {
        var c = document.createElement("span");
        c.className = "chip";
        c.textContent = v;
        span.appendChild(c);
      });
      return span;
    }
    function addRow(label, node) {
      if (!dl) return;
      var wrap = document.createElement("div");
      wrap.className = "fact";
      var dt = document.createElement("dt");
      dt.className = "fact__label";
      dt.textContent = label;
      var dd = document.createElement("dd");
      dd.className = "fact__value";
      dd.appendChild(node);
      wrap.appendChild(dt);
      wrap.appendChild(dd);
      dl.appendChild(wrap);
    }

    var rows = 0;
    if (product.material) {
      var m = document.createElement("span");
      m.textContent = product.material;
      addRow("Material", m); rows++;
    }
    if (Array.isArray(product.sizes) && product.sizes.length) { addRow("Sizes", chips(product.sizes)); rows++; }
    if (Array.isArray(product.colors) && product.colors.length) { addRow("Colours", chips(product.colors)); rows++; }
    if (Array.isArray(product.standards) && product.standards.length) { addRow("Standards", chips(product.standards)); rows++; }

    // Article codes: a small Code/Size table when any size is present, else chips.
    var codes = Array.isArray(product.articleCodes)
      ? product.articleCodes.filter(function (c) { return c && (c.code || c.size); })
      : [];
    if (codes.length && codesBox && codesWrap) {
      if (codes.some(function (c) { return c.size; })) {
        var tbl = document.createElement("table");
        tbl.className = "codes-table";
        tbl.innerHTML = "<thead><tr><th>Article code</th><th>Size</th></tr></thead>";
        var tb = document.createElement("tbody");
        codes.forEach(function (c) {
          var tr = document.createElement("tr");
          var td1 = document.createElement("td"); td1.textContent = c.code || "—";
          var td2 = document.createElement("td"); td2.textContent = c.size || "—";
          tr.appendChild(td1); tr.appendChild(td2); tb.appendChild(tr);
        });
        tbl.appendChild(tb);
        codesBox.appendChild(tbl);
      } else {
        codesBox.appendChild(chips(codes.map(function (c) { return c.code; })));
      }
      codesWrap.hidden = false;
    } else if (codesWrap) {
      codesWrap.hidden = true;
    }

    block.style.display = (rows || codes.length) ? "" : "none";
  }

  /* Sort spec codes alphabetically (case-insensitive, numeric-aware so S2 < S10).
     Accepts either a {code} object or a raw code string. */
  function codeOf(x) { return String(x && x.code != null ? x.code : x); }
  function byCode(a, b) { return codeOf(a).localeCompare(codeOf(b), undefined, { numeric: true, sensitivity: "base" }); }

  /* Index a master code list (attributes.json group) by upper-cased code so a
     product's stored code can be matched case-insensitively to its name/img. */
  function specDefMap(groupKey) {
    var map = {};
    var defs = ATTRIBUTES[groupKey];
    if (Array.isArray(defs)) {
      defs.forEach(function (d) {
        if (d && d.code) map[String(d.code).toUpperCase()] = d;
      });
    }
    return map;
  }

  /* Small "ⓘ" affordance appended to a clickable spec so customers can tell it
     reveals a details panel underneath. */
  function specInfoBadge() {
    var s = document.createElement("span");
    s.className = "spec__info";
    s.setAttribute("aria-hidden", "true");
    s.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11.5v4.5"/><circle cx="12" cy="7.8" r="0.7" fill="currentColor" stroke="none"/></svg>';
    return s;
  }

  // Fill an inline detail panel with a code's authored title + description.
  function fillSpecDetail(card, def) {
    card.innerHTML = "";
    var t = (def.title || "").trim();
    if (t) {
      var h = document.createElement("h4");
      h.className = "spec-detail__title";
      h.textContent = t;
      card.appendChild(h);
    }
    (def.description || "").trim().split(/\n{2,}/).forEach(function (para) {
      para = para.trim();
      if (!para) return;
      var p = document.createElement("p");
      p.textContent = para.replace(/\n/g, " ");
      card.appendChild(p);
    });
  }

  /* Spec strips (Performance / Safety class / Technologies) at the foot of the
     detail page, for ANY product category. Each group renders pictograms for
     codes whose definition has an image and text bullets for the rest. Codes
     that carry an authored title/description become clickable → they reveal the
     text inline in a disclosure panel under the group (no blocking modal). The
     whole block (and each group within it) stays hidden when the product has no
     codes assigned. */
  function renderSpecs(product) {
    var block = document.getElementById("pd-specs-block");
    if (!block) return;
    block.innerHTML = "";

    var any = false;
    SPEC_GROUPS.forEach(function (g) {
      var codes = (Array.isArray(product[g.key]) ? product[g.key] : []).slice().sort(byCode);
      if (!codes.length) return;
      var defs = specDefMap(g.key);

      var pictoFrag = document.createDocumentFragment();
      var textCodes = [];
      var pictoCount = 0;

      // One inline disclosure panel per group; clicking a code reveals its
      // authored title + description underneath (no blocking modal).
      var detail = document.createElement("div");
      detail.className = "spec-detail";
      var detailInner = document.createElement("div");
      detailInner.className = "spec-detail__inner";
      var detailCard = document.createElement("div");
      detailCard.className = "spec-detail__card";
      detailInner.appendChild(detailCard);
      detail.appendChild(detailInner);
      var activeBtn = null;

      function toggleDetail(btn, def) {
        if (activeBtn) {
          activeBtn.classList.remove("is-open");
          activeBtn.setAttribute("aria-expanded", "false");
        }
        if (activeBtn === btn) {        // clicking the open one closes it
          activeBtn = null;
          detail.classList.remove("is-open");
          return;
        }
        activeBtn = btn;
        btn.classList.add("is-open");
        btn.setAttribute("aria-expanded", "true");
        fillSpecDetail(detailCard, def);
        detail.classList.add("is-open");
      }
      function bindToggle(btn, def) {
        btn.addEventListener("click", function () { toggleDetail(btn, def); });
      }

      codes.forEach(function (raw) {
        var code = String(raw).trim();
        if (!code) return;
        var def = defs[code.toUpperCase()] || {};
        var info = !!(def.title || def.description);   // has details to reveal
        if (def.img) {
          pictoCount++;
          // Photo badge: the image alone (no code caption, no ⓘ badge). The code
          // stays in alt/title for accessibility + hover.
          var img = document.createElement("img");
          img.className = "spec__icon";
          img.src = def.img;
          img.width = 120; img.height = 120;
          img.loading = "lazy";
          img.decoding = "async";
          img.alt = code;
          var node;
          if (info) {
            node = document.createElement("button");
            node.type = "button";
            node.className = "spec spec--photo spec--clickable";
            node.setAttribute("data-group", g.key);
            node.setAttribute("aria-expanded", "false");
            node.title = code;
            node.setAttribute("aria-label", code + " — view details");
            bindToggle(node, def);
          } else {
            node = document.createElement("figure");
            node.className = "spec spec--photo";
            node.title = code;
          }
          node.appendChild(img);
          pictoFrag.appendChild(node);
        } else {
          textCodes.push({ code: code, def: def, info: info });
        }
      });

      var groupEl = document.createElement("div");
      groupEl.className = "spec-group";
      groupEl.setAttribute("data-group", g.key);
      var head = document.createElement("h3");
      head.className = "spec-group__head";
      head.textContent = COPY[g.labelKey] || g.key;
      groupEl.appendChild(head);

      if (pictoCount) {
        var grid = document.createElement("div");
        grid.className = "spec-group__grid";
        grid.appendChild(pictoFrag);
        groupEl.appendChild(grid);
      }
      if (textCodes.length) {
        var ul = document.createElement("ul");
        ul.className = "spec-group__list";
        textCodes.forEach(function (t) {
          var li = document.createElement("li");
          if (t.info) {
            var btn = document.createElement("button");
            btn.type = "button";
            btn.className = "spec-text spec-text--btn";
            btn.setAttribute("data-group", g.key);
            btn.setAttribute("aria-expanded", "false");
            btn.title = "View details";
            var sb = document.createElement("strong");
            sb.textContent = t.code;
            btn.appendChild(sb);
            btn.appendChild(specInfoBadge());
            bindToggle(btn, t.def);
            li.appendChild(btn);
          } else {
            li.className = "spec-text";
            var b = document.createElement("strong");
            b.textContent = t.code;
            li.appendChild(b);
          }
          ul.appendChild(li);
        });
        groupEl.appendChild(ul);
      }

      groupEl.appendChild(detail);   // inline disclosure under this group's codes

      block.appendChild(groupEl);
      any = true;
    });

    block.style.display = any ? "" : "none";
  }

  /* Load the admin-editable master code list (names + pictograms). Optional:
     a missing/broken file just leaves ATTRIBUTES empty so the spec strips
     simply don't render. Always calls done(). */
  function loadAttributes(done) {
    cachedJSON(ATTRIBUTES_URL, function (j) {
      if (j && typeof j === "object") {
        SPEC_GROUPS.forEach(function (g) {
          if (Array.isArray(j[g.key])) ATTRIBUTES[g.key] = j[g.key];
        });
      }
      done();   // cachedJSON failure → j is null, keep empty groups
    });
  }

  /* ---------------------------------------------------- 5. scroll reveal -- */
  var io = null;
  function setupReveals() {
    if (!("IntersectionObserver" in window)) {
      // No IO: just show everything.
      var all = document.querySelectorAll(".reveal");
      for (var i = 0; i < all.length; i++) all[i].classList.add("is-visible");
      return;
    }
    io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add("is-visible");
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.15 });
    observeReveals();
  }
  function observeReveals() {
    if (!io) return;
    var els = document.querySelectorAll(".reveal:not(.is-visible)");
    for (var i = 0; i < els.length; i++) io.observe(els[i]);
  }
  // Reveal freshly-injected .reveal nodes: observe them, or show them outright
  // when IntersectionObserver isn't available.
  function revealNew() {
    if (io) { observeReveals(); return; }
    var els = document.querySelectorAll(".reveal:not(.is-visible)");
    for (var i = 0; i < els.length; i++) els[i].classList.add("is-visible");
  }

  /* ---------------------------------------------------------- 6. mobile nav */
  function setupNav() {
    var toggle = document.getElementById("navToggle");
    var menu = document.getElementById("mobileNav");
    var close = document.getElementById("navClose");
    if (!toggle || !menu || !close) return;

    function open() {
      menu.classList.add("is-open");
      toggle.setAttribute("aria-expanded", "true");
      document.body.style.overflow = "hidden";
      close.focus();
    }
    function shut() {
      menu.classList.remove("is-open");
      toggle.setAttribute("aria-expanded", "false");
      document.body.style.overflow = "";
      toggle.focus();
    }
    toggle.addEventListener("click", open);
    close.addEventListener("click", shut);
    var links = menu.querySelectorAll("a");
    for (var i = 0; i < links.length; i++) links[i].addEventListener("click", shut);
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && menu.classList.contains("is-open")) shut();
    });
  }

  /* ----------------------------------------- 7. hero scrub + parallax + nav */
  function setupHero() {
    var hero = document.getElementById("hero");
    var content = $(".hero__content");
    var video = document.getElementById("heroVideo");
    var nav = document.getElementById("nav");
    if (!hero) return;

    /* Reduced motion: ship a clean static poster hero (no pin, no scrub, no
       video download). EVERY other device — phones included — gets the full
       scroll-scrub below, so the clip "unfolds" on scroll exactly like desktop.
       The clip is all-intra and loaded as an in-memory blob, so seeks are fast
       on mobile too; iOS is primed on first touch (see the touchstart handler). */
    if (reduceMotion) {
      hero.classList.add("hero--static");
      if (nav) nav.classList.add("is-bordered");
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { hero.classList.add("is-ready"); });
      });
      return;
    }

    var duration = 0;
    var canScrub = false;     // true once we have a valid, fully-seekable clip
    var curT = 0, tgtT = 0;   // lerped vs target currentTime
    var progress = 0;
    var ticking = false;
    var primed = false;       // iOS: whether the clip has been "played" once

    // Exit-gate state: you can't leave the hero until the man is fully
    // transformed (clip at its last frame), so a fast flick can't outrun it.
    var GATE_EPS = 0.08;      // seconds from the end that count as "done"
    var STALL_MS = 2000;      // never hold longer than this (safety net)
    var heldSince = 0;        // timestamp the current hold began (0 = not held)
    var gaveUp = false;       // released because the decode stalled
    var bypassUntil = 0;      // suspend the gate during anchor navigation
    var html = document.documentElement;

    /* End-zoom -----------------------------------------------------------
       The clip ends on a three-man line-up; we don't want that reveal. After
       the single-man frame (≈ t6.0s of the 8.04s clip → ZOOM_START progress),
       push the video in toward the centre man so the two flanking men crop
       out of frame, leaving only him at the end of the scrub. Tunable:
         ZOOM_START  — progress where the push-in begins (the img_2_1 frame)
         ZOOM_MAX    — final scale (bigger = tighter on the centre man)
         ZOOM_ORIGIN — the point kept fixed while scaling (centre man, head-biased)
       Overflow is clipped by .hero__pin (overflow:hidden); the hero copy sits
       above the video, so the leftward growth never covers the text. */
    var ZOOM_START  = 0.75;
    var ZOOM_MAX    = 1.7;
    var ZOOM_ORIGIN = "50% 34%";
    function ease(t) { t = clamp(t, 0, 1); return t * t * (3 - 2 * t); }

    function nowMs() {
      return (window.performance && performance.now) ? performance.now() : Date.now();
    }
    // Force an instant scroll even though <html> keeps scroll-behavior: smooth
    // for the nav anchors.
    function jumpTo(y) {
      var prev = html.style.scrollBehavior;
      html.style.scrollBehavior = "auto";
      window.scrollTo(0, y);
      html.style.scrollBehavior = prev;
    }

    /* --- scroll -> progress (drives parallax + nav + scrub) -------------- */
    function readProgress() {
      var vh = window.innerHeight;
      var denom = hero.offsetHeight - vh;          // scrollable range of the pin
      progress = denom > 0 ? clamp((window.pageYOffset - hero.offsetTop) / denom, 0, 1) : 0;
    }
    function schedule() {
      if (!ticking) { ticking = true; requestAnimationFrame(frame); }
    }
    function frame() {
      ticking = false;

      // Re-arm the gate once the user has scrolled back up into the hero.
      if (progress < 0.9) { gaveUp = false; heldSince = 0; }

      // EXIT GATE: at the very end of the scrub, hold the scroll here until the
      // clip has actually reached its last frame. This is what stops a fast
      // flick from skipping past a half-transformed man. Safety: never hold
      // more than STALL_MS, and stand down during anchor navigation.
      if (canScrub && video && progress >= 0.999 && !gaveUp && nowMs() > bypassUntil) {
        if (video.currentTime < duration - GATE_EPS) {
          var exitY = hero.offsetTop + (hero.offsetHeight - window.innerHeight);
          if (window.pageYOffset > exitY) jumpTo(exitY);          // hold them here
          if (!heldSince) heldSince = nowMs();
          else if (nowMs() - heldSince > STALL_MS) gaveUp = true; // decode stuck: release
          schedule();                                             // pump until it lands
        } else {
          heldSince = 0;
        }
      }

      // Parallax: drift the hero copy up to -80px across the scroll range.
      if (content && !reduceMotion) {
        content.style.transform = "translateY(" + (-80 * progress) + "px)";
      }

      // End-zoom: push the video in toward the centre man past ZOOM_START so
      // the closing three-man line-up crops down to just him. (No-op under
      // reduced motion — the clip never scrubs there, it holds on the poster.)
      if (video && !reduceMotion) {
        var z = ease((progress - ZOOM_START) / (1 - ZOOM_START));
        var s = 1 + (ZOOM_MAX - 1) * z;
        video.style.transformOrigin = ZOOM_ORIGIN;
        video.style.transform = s > 1.0005 ? "scale(" + s + ")" : "none";
      }

      // Nav hairline fades in once the scrub is complete (past the hero).
      if (nav) nav.classList.toggle("is-bordered", progress >= 0.999);

      // Video scrub: lerp currentTime toward target in BOTH directions.
      if (canScrub && video) {
        tgtT = progress * duration;
        curT += (tgtT - curT) * 0.12;             // smoothing factor
        if (Math.abs(tgtT - curT) < 0.008) curT = tgtT;

        // Commit a seek ONLY when the decoder is idle. Firing a fresh seek on
        // every frame makes browsers drop them, which stalls the scrub after
        // ~1 second. Gating on !seeking lets each seek finish first.
        var pending = Math.abs(video.currentTime - curT) > 0.02;
        if (pending && video.readyState >= 2 && !video.seeking) {
          try { video.currentTime = curT; } catch (e) {/* not seekable yet */}
        }
        if (Math.abs(tgtT - curT) > 0.008 || pending) schedule();
      }
    }
    function onScroll() { readProgress(); schedule(); }
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll, { passive: true });

    // Clicking any in-page anchor (nav, hero CTAs, footer, card links) means
    // "take me there" -- suspend the exit gate so the smooth scroll isn't held.
    document.addEventListener("click", function (e) {
      var t = e.target;
      if (t && t.closest && t.closest('a[href^="#"]')) bypassUntil = nowMs() + 1800;
    }, true);

    /* --- video: scrub-only, never autoplays ------------------------------ */
    if (video) {
      video.pause();

      // Defensive: this clip must never play on its own (no autoplay anywhere).
      video.addEventListener("play", function () { if (!primed) video.pause(); });
      video.addEventListener("loadedmetadata", onMeta);
      video.addEventListener("error", function () { canScrub = false; });
      // Each finished seek re-pumps the loop so we keep stepping toward target.
      video.addEventListener("seeked", function () { if (canScrub) schedule(); });

      // iOS won't seek a muted clip until it has "played" once. Prime it
      // invisibly on the FIRST touch only. Desktop has no touchstart, so it
      // never plays there -- that was the "it just autoplays" bug.
      window.addEventListener("touchstart", function primeOnce() {
        window.removeEventListener("touchstart", primeOnce);
        primed = true;
        var pr = video.play();
        if (pr && pr.then) pr.then(function () { video.pause(); }).catch(function () {});
      }, { passive: true });

      preloadVideo();
    }

    function onMeta() {
      duration = video.duration;
      // Fallback per spec: no usable duration => keep poster, skip scrubbing.
      canScrub = !!duration && isFinite(duration) && duration > 0 && !reduceMotion;
      readProgress();
      schedule();
    }

    // The clip is NOT "faststart" (its moov / seek index sits at the END of the
    // file), so progressive seeking only reaches the first ~second until the
    // whole file downloads. It's tiny (~1.5MB): fetch it whole and seek the
    // blob, which makes the ENTIRE timeline instantly seekable on any host.
    function preloadVideo() {
      var srcEl = video.querySelector("source");
      var url = (srcEl && srcEl.getAttribute("src")) || video.currentSrc || video.src;
      if (!url || !("fetch" in window) || reduceMotion) { video.load(); return; }
      fetch(url).then(function (r) {
        if (!r.ok) throw new Error("video fetch failed");
        return r.blob();
      }).then(function (blob) {
        video.src = URL.createObjectURL(blob);
        // The markup ships preload="none" so phones don't pull the ~9MB clip; the
        // blob is already in memory, so re-enable buffering for the desktop scrub.
        video.preload = "auto";
        video.load();
      }).catch(function () {
        // file:// or a blocked fetch: fall back to the <source> element.
        video.preload = "auto";
        video.load();
      });
    }

    readProgress();
    schedule();

    // Trigger the staggered hero entrance after the first paint.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { hero.classList.add("is-ready"); });
    });
  }

  /* ------------------------------------------------------- 8. contact form */
  function setupForm() {
    var form = document.getElementById("contactForm");
    if (!form) return;
    var statusEl = document.getElementById("formStatus");
    var successEl = document.getElementById("formSuccess");
    var btn = form.querySelector('button[type="submit"]');
    var emailRe = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      statusEl.textContent = "";
      statusEl.className = "form-status";

      var data = {
        name: form.name.value.trim(),
        email: form.email.value.trim(),
        phone: form.phone.value.trim(),
        message: form.message.value.trim(),
        website: form.website.value.trim()    // honeypot
      };

      // Mark each offending field so the user sees WHERE, not just a generic line.
      var bad = [];
      if (!data.name) bad.push(form.name);
      if (!emailRe.test(data.email)) bad.push(form.email);
      if (!data.phone) bad.push(form.phone);
      if (!data.message) bad.push(form.message);
      if (bad.length) {
        bad.forEach(function (f) { f.setAttribute("aria-invalid", "true"); });
        bad[0].focus();
        statusEl.textContent = COPY.formInvalid;
        statusEl.classList.add("is-error");
        shake(form);
        return;
      }

      var prev = btn.textContent;
      btn.disabled = true;
      btn.textContent = COPY.formSending;
      btn.classList.add("is-sending");

      fetch("send-mail.php", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify(data)
      })
        .then(function (res) {
          return res.json().catch(function () { return {}; })
            .then(function (json) { return { ok: res.ok, json: json }; });
        })
        .then(function (r) {
          btn.classList.remove("is-sending");
          if (r.ok && r.json && r.json.success) {
            revealSuccess();
          } else {
            statusEl.textContent = (r.json && r.json.error) ? r.json.error : COPY.formError;
            statusEl.classList.add("is-error");
            btn.disabled = false;
            btn.textContent = prev;
          }
        })
        .catch(function () {
          btn.classList.remove("is-sending");
          statusEl.textContent = COPY.formError;
          statusEl.classList.add("is-error");
          btn.disabled = false;
          btn.textContent = prev;
        });
    });

    // Clear a field's error marker as soon as the user starts correcting it.
    form.addEventListener("input", function (e) {
      if (e.target && e.target.hasAttribute("aria-invalid")) e.target.removeAttribute("aria-invalid");
    });

    // Replay-able shake: drop the class, force reflow, re-add so it retriggers
    // on every invalid submit. No-op under reduced motion (CSS animation: none).
    function shake(el) {
      el.classList.remove("is-invalid");
      void el.offsetWidth;
      el.classList.add("is-invalid");
    }

    // Ease the form out, then swap in the confirmation (CSS handles the fade/pop).
    // Reduced motion: skip the out-transition and swap immediately.
    function revealSuccess() {
      function swap() {
        form.hidden = true;
        if (successEl) successEl.hidden = false;
      }
      if (reduceMotion) { swap(); return; }
      form.classList.add("is-sent");
      setTimeout(swap, 300);
    }
  }

  /* --------------------------------------- 8b. best-sellers scroll showcase */
  /* Scroll-scrubbed alternating showcase. Each product is a text block + an
     image block placed in opposite lanes. As you scroll the (pinned) section,
     the outgoing product's image slides off toward its own edge while the next
     product's image slides in from the opposite edge — and its text does the
     mirror — so each product settles into an inverted version of the previous
     layout (text-left/image-right -> image-left/text-right -> text-left/...).
     Falls back to a simple stacked layout when JS is off, reduced motion is
     requested, or the viewport is too narrow for the side-by-side effect. */
  function setupBestSellers() {
    var section = document.getElementById("bestsellers");
    var scene = document.getElementById("bsScene");
    if (!section || !scene) return;
    var dotsWrap = document.getElementById("bsDots");

    function txt(tag, cls, str) { var e = document.createElement(tag); if (cls) e.className = cls; e.textContent = str; return e; }
    function bestPh() { var s = txt("span", "card__ph", "KAPRIOL"); s.setAttribute("aria-hidden", "true"); return s; }
    // Resolve a best-seller slot to its live catalog product (so name /
    // description / pictograms always reflect the latest admin edits).
    function bestProduct(slug) {
      if (!slug) return null;
      for (var i = 0; i < PRODUCTS.length; i++) if (PRODUCTS[i].slug === slug) return PRODUCTS[i];
      return null;
    }
    // Pictogram strip: the product's Performance + Technologies codes that carry
    // an image (resolved via the master ATTRIBUTES list). Returns null if none.
    function bestPictos(p) {
      if (!p) return null;
      var wrap = document.createElement("div");
      wrap.className = "bs-pictos";
      var count = 0;
      ["performance", "technologies"].forEach(function (key) {
        var codes = Array.isArray(p[key]) ? p[key] : [];
        var defs = specDefMap(key);
        codes.forEach(function (raw) {
          var code = String(raw).trim();
          if (!code) return;
          var def = defs[code.toUpperCase()] || {};
          if (!def.img) return;   // only pictogram-bearing codes show here
          count++;
          var fig = document.createElement("figure");
          fig.className = "spec bs-picto";
          if (def.name) fig.title = code + " — " + def.name;
          var im = document.createElement("img");
          im.className = "spec__icon";
          im.src = def.img;
          im.width = 56; im.height = 56;
          im.loading = "lazy"; im.decoding = "async";
          im.alt = def.name ? (code + " — " + def.name) : code;
          var cap = txt("figcaption", "spec__code", code);
          fig.appendChild(im); fig.appendChild(cap);
          wrap.appendChild(fig);
        });
      });
      return count ? wrap : null;
    }

    var items = [];
    BEST.forEach(function (b, i) {
      var even = (i % 2 === 0);   // even: text-left / image-right; odd: mirrored

      // Live product (preferred) backed by the saved snapshot as a fallback.
      var p = bestProduct(b.slug);
      var name = (p && p.name) || b.name || COPY.bestHeading;
      var desc = (p && p.description) || COPY.bestSub;

      var text = document.createElement("div");
      text.className = "bs-text";
      text.appendChild(txt("p", "bs-eyebrow", COPY.bestEyebrow));
      text.appendChild(txt("h2", "bs-title", name));
      text.appendChild(txt("p", "bs-sub", desc));
      var pictos = bestPictos(p);
      if (pictos) text.appendChild(pictos);
      // Visible route to the product (the image alone is an invisible target).
      if (b.slug) {
        var go = document.createElement("a");
        go.className = "bs-link";
        go.href = "product?p=" + encodeURIComponent(b.slug);
        go.innerHTML = "View product" + ARROW;
        text.appendChild(go);
      }

      var media = document.createElement("div");
      media.className = "bs-media";
      var src = productImg(p || b);   // live photo first (with imgV cache-bust)
      var photo;
      if (src) {
        var img = document.createElement("img");
        img.src = src; img.alt = name; img.decoding = "async";
        img.loading = "lazy";   // best sellers sit below the hero fold
        img.addEventListener("error", function () {
          media.classList.add("bs-media--empty");
          if (this.parentNode) this.parentNode.replaceChild(bestPh(), this);
        });
        photo = img;
      } else {
        media.classList.add("bs-media--empty");
        photo = bestPh();
      }
      // A picked product links through to its detail page; legacy/seed items don't.
      if (b.slug) {
        var a = document.createElement("a");
        a.className = "bs-media__link";
        a.href = "product?p=" + encodeURIComponent(b.slug);
        a.setAttribute("aria-label", name);
        a.appendChild(photo);
        media.appendChild(a);
      } else {
        media.appendChild(photo);
      }

      // Each product is wrapped in a group so the stacked layout can treat it as
      // one unit (image-first, card-like). In scrub mode the wrapper is
      // display:contents, so text/media still position against the scene.
      var group = document.createElement("div");
      group.className = "bs-group";
      group.appendChild(text);
      group.appendChild(media);
      scene.appendChild(group);
      // sText/sMedia: which edge an element belongs to (+1 right, -1 left).
      items.push({ text: text, media: media, sText: even ? -1 : 1, sMedia: even ? 1 : -1, i: i });

      if (dotsWrap) {
        var d = document.createElement("button");
        d.type = "button"; d.className = "bs-dot-btn";
        d.setAttribute("aria-label", name || ("Product " + (i + 1)));
        dotsWrap.appendChild(d);
      }
    });
    var n = items.length;
    if (!n) return;
    var dots = dotsWrap ? dotsWrap.querySelectorAll(".bs-dot-btn") : [];

    // Fallback: keep the stacked layout (no pin / no scrub).
    var wide = !window.matchMedia || window.matchMedia("(min-width: 900px)").matches;
    if (reduceMotion || !wide) return;

    section.classList.add("is-scrub");
    scene.classList.add("bs-scene--scrub");

    // Lane anchors — scrub mode ONLY. In the stacked fallback these would shove
    // the relatively-positioned image off-screen (the even items broke on mobile).
    items.forEach(function (it) {
      var ev = (it.i % 2 === 0);
      it.text.style.left  = ev ? "0%"  : "54%";
      it.media.style.left = ev ? "54%" : "0%";
    });

    // Timeline: each product gets a "hold" (H) of the scroll; transitions (T)
    // share the rest. pos in [0..n-1] — integer = a product is centred/home.
    var H = 0.12, T = (n > 1) ? (1 - n * H) / (n - 1) : 0, EXIT = 1.35;
    function smooth(t) { t = clamp(t, 0, 1); return t * t * (3 - 2 * t); }
    function computePos(p) {
      var x = 0;
      for (var i = 0; i < n; i++) {
        if (p <= x + H) return i;
        x += H;
        if (i < n - 1) {
          if (p <= x + T) return i + smooth((p - x) / T);
          x += T;
        }
      }
      return n - 1;
    }
    function holdCenter(i) { return i * (H + T) + H / 2; }

    // An element lives off-screen on its own edge, slides to centre as its
    // product becomes active, then slides back out the same edge as it passes.
    function place(el, delta, s) {
      var a = Math.abs(delta), x, op;
      if (a >= 1) { x = s * EXIT; op = 0; }
      else { var e = smooth(a); x = s * EXIT * e; op = 1 - e; }
      el.style.transform = "translate(" + (x * 100) + "%, -50%)";
      el.style.opacity = op;
      el.style.zIndex = Math.round((1 - Math.min(a, 1)) * 100);
      el.style.pointerEvents = op > 0.6 ? "auto" : "none";
    }
    var ticking = false;
    function frame() {
      ticking = false;
      var range = section.offsetHeight - window.innerHeight;
      var p = range > 0 ? clamp((window.pageYOffset - section.offsetTop) / range, 0, 1) : 0;
      var pos = computePos(p);
      for (var i = 0; i < n; i++) {
        place(items[i].text, pos - items[i].i, items[i].sText);
        place(items[i].media, pos - items[i].i, items[i].sMedia);
      }
      var act = Math.round(pos);
      for (var k = 0; k < dots.length; k++) dots[k].classList.toggle("is-on", k === act);
    }
    function onScroll() { if (!ticking) { ticking = true; requestAnimationFrame(frame); } }
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll, { passive: true });
    frame();

    // Dots jump to a product's resting point.
    for (var di = 0; di < dots.length; di++) {
      (function (idx) {
        dots[idx].addEventListener("click", function () {
          var range = section.offsetHeight - window.innerHeight;
          window.scrollTo({ top: section.offsetTop + holdCenter(idx) * range, behavior: "smooth" });
        });
      })(di);
    }
  }

  /* ------------------------------------------------- 8c. catalog search --- */
  /* Live, client-side product search on products.html. Reads ?q= on load,
     filters across all products as you type (debounced), re-renders the
     catalog, and mirrors the query back into the URL so a search is
     shareable/bookmarkable and survives a refresh or the Back button. */
  function setupSearch() {
    var input = document.getElementById("productSearch");
    if (!input) return;
    var clear = document.getElementById("searchClear");

    // Seed from ?q= so a shared/bookmarked search restores on load.
    var initial = "";
    try { initial = new URLSearchParams(location.search).get("q") || ""; } catch (e) {}
    if (initial) { input.value = initial; searchQuery = initial; }
    if (clear) clear.hidden = !input.value;

    // Keep ?q= in sync without piling up history entries.
    function syncUrl(q) {
      if (!window.history || !history.replaceState) return;
      var url = location.pathname;
      var params = new URLSearchParams(location.search);
      if (q) params.set("q", q); else params.delete("q");
      params.delete("cat");                 // search spans all categories
      var qs = params.toString();
      history.replaceState(null, "", url + (qs ? "?" + qs : ""));
    }

    function apply() {
      searchQuery = input.value;
      if (clear) clear.hidden = !input.value;
      syncUrl(input.value.trim());
      renderCatalog();
      revealNew();
    }

    var t = 0;
    input.addEventListener("input", function () {
      clearTimeout(t);
      t = setTimeout(apply, 120);
    });
    // Enter shouldn't submit/reload — just apply immediately.
    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter") { e.preventDefault(); clearTimeout(t); apply(); }
      if (e.key === "Escape" && input.value) { input.value = ""; clearTimeout(t); apply(); }
    });
    if (clear) clear.addEventListener("click", function () {
      input.value = ""; clearTimeout(t); apply(); input.focus();
    });
  }

  /* ------------------------------------------------------------- 9. init -- */
  function init() {
    // Pull admin-editable copy first (with a hardcoded fallback), then render.
    applyContent(function () { boot(); });
  }

  /* Cookieless analytics beacon — records ONE anonymous aggregate hit per
     page, per browser session: reloading or returning to the same page in the
     same visit is NOT counted again (the dedup flag lives in sessionStorage,
     which is not a cookie and never leaves the device). Sends a page label, a
     fresh-session flag (→ one "visit"), and the product slug on detail pages.
     No cookie, no personal data. Wrapped so it can never break the page. */
  function trackView() {
    try {
      if (location.protocol === "file:") return;             // local file open
      if (!("sendBeacon" in navigator) && !("fetch" in window)) return;

      // Identify the page by a DOM marker (robust at the web root AND in a
      // subfolder like /1/, where the URL alone is ambiguous). Fall back to the
      // path's last segment only if no marker matches.
      var page;
      if (document.getElementById("product-detail")) page = "product";
      else if (document.getElementById("catalog")) page = "products";
      else if (document.getElementById("contactForm")) page = "contact";
      else if (document.getElementById("bestsellers")) page = "home";
      else {
        var seg = location.pathname.replace(/\/+$/, "").split("/").pop() || "";
        seg = seg.replace(/\.html$/i, "").toLowerCase();
        page = (seg === "" || seg === "index") ? "home" : (seg || "other");
      }

      var slug = "";
      try { slug = new URLSearchParams(location.search).get("p") || ""; } catch (e) {}

      // Count each page at most once per visit. "kap_v" marks the whole session
      // (→ a "visit" on the very first hit); a per-page key marks pages already
      // counted, so a reload or a return to the same page in this visit is
      // ignored. A distinct product slug counts as a distinct page.
      var fresh = true, seen = false;
      var pageKey = "kap_seen_" + page + (slug ? "_" + slug : "");
      try {
        if (sessionStorage.getItem("kap_v")) fresh = false;
        else sessionStorage.setItem("kap_v", "1");

        if (sessionStorage.getItem(pageKey)) seen = true;
        else sessionStorage.setItem(pageKey, "1");
      } catch (e) { /* private mode — no dedup possible; count this load */ }

      if (seen) return;   // already counted this page this visit — nothing to send

      var body = JSON.stringify({ page: page, s: fresh ? 1 : 0, slug: slug });
      // "track.php" is relative to the current path, so it resolves correctly
      // at the web root AND inside a subfolder (e.g. /1/).
      if ("sendBeacon" in navigator) {
        navigator.sendBeacon("track.php", new Blob([body], { type: "application/json" }));
      } else {
        fetch("track.php", { method: "POST", body: body, keepalive: true, headers: { "Content-Type": "application/json" } });
      }
    } catch (e) { /* analytics must never affect the visitor */ }
  }

  function boot() {
    hydrate(COPY);
    patchContactLinks();
    applyContactVisibility();
    trackView();
    setupReveals();
    setupNav();
    setupHero();
    setupForm();

    var needsData = document.getElementById("catalog") || document.getElementById("product-detail");

    // Home best sellers now show each product's live name / description /
    // pictograms, so they need the catalog + master code list. Only fetch when
    // a slot is actually bound to a product; otherwise render the legacy
    // snapshot straight away (no reason to pull the 334-product catalog).
    var bestEl = document.getElementById("bestsellers");
    if (bestEl && !needsData && BEST.some(function (b) { return b.slug; })) {
      loadProducts(function () { loadAttributes(function () { setupBestSellers(); }); });
    } else {
      setupBestSellers();
    }

    // Catalog + detail page need the product data; load it then render.
    if (needsData) {
      var catRoot = document.getElementById("catalog");
      if (catRoot && !searchHasQuery() && !catParam()) renderSkeleton(catRoot);  // loading state
      var isDetail = !!document.getElementById("product-detail");
      loadProducts(function (ok) {
        if (!ok) { showCatalogError(); return; }
        setupSearch();        // seeds searchQuery from ?q= before the first render
        // Master spec codes (names + pictograms) are needed by BOTH the detail
        // page's spec strips AND the footwear category's filters, so load them
        // before rendering either. The file is tiny and session-cached.
        loadAttributes(function () {
          renderCatalog();
          renderProduct();
          revealNew();        // reveal the freshly-injected cards/tiles
        });
      });
    }
  }

  // Only skeleton the default catalog view (a search/category deep-link renders
  // its own shape once data lands; a generic skeleton there would mislead).
  function searchHasQuery() { try { return !!new URLSearchParams(location.search).get("q"); } catch (e) { return false; } }
  function catParam() { try { return !!new URLSearchParams(location.search).get("cat"); } catch (e) { return false; } }

  function renderSkeleton(root) {
    root.innerHTML = "";
    var grid = document.createElement("div");
    grid.className = "cat-tiles";
    grid.setAttribute("aria-hidden", "true");
    for (var i = 0; i < 6; i++) {
      var t = document.createElement("div");
      t.className = "cat-tile cat-tile--skeleton";
      t.innerHTML = '<span class="cat-tile__media"></span>';
      grid.appendChild(t);
    }
    root.appendChild(grid);
  }

  // Data fetch failed (e.g. opened via file://, or the JSON is missing).
  function showCatalogError() {
    var root = document.getElementById("catalog") || document.getElementById("product-detail");
    if (!root) return;
    var p = document.createElement("p");
    p.className = "catalog__error";
    p.textContent = "Catalog is temporarily unavailable. Please refresh, or contact info@kapriol.bg.";
    root.appendChild(p);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
