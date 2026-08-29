/* ============================================================================
   Kapriol Bulgaria - test1 redesign
   One shared IIFE for all four pages. Every init step is guarded: it no-ops
   when its root element is absent, so a single file serves every page.

   test1 is SELF-CONTAINED. It carries its own assets/data/*.json and
   assets/img/, so the folder can be dropped on the host, the data and image
   folders pasted in beside it, and it runs on its own. BASE is therefore
   empty: nothing is read from the parent site, and its admin writes only to
   test1's own files.
   ========================================================================== */
(function () {
  "use strict";

  var BASE = "";

  /* ---------------------------------------------------------- Static copy -- */
  /* Category ids are the REAL ids used by products.json and by the existing
     ?cat= deep links. Only the labels follow the new design. */
  var CATEGORIES = [
    { id: "footwear", label: "Footwear" },
    { id: "footwear-accessories", label: "Footwear Accessories" },
    { id: "clothing", label: "Work Wear" },
    { id: "clothing-accessories", label: "Work Wear Accessories" },
    { id: "ppe", label: "PPE" },
    { id: "ppe-accessories", label: "PPE Accessories" }
  ];

  var SPEC_GROUPS = [
    { key: "performance", label: "Performance" },
    { key: "safetyClass", label: "Safety class" },
    { key: "technologies", label: "Technologies" }
  ];

  /* --------------------------------------------------------------- State -- */
  var PRODUCTS = [];
  var productsLoadFailed = false;
  var ATTRS = null;
  var CONTENT = null;

  /* --------------------------------------------------------------- Utils -- */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  /* --- Cascade hover for the stencil headlines ----------------------------
     Each character is wrapped in a clip box with an identical copy painted 1em
     below it (text-shadow, currentColor); on hover the character rolls up by 1em
     with a per-character stagger, so the copy takes its place. Adapted from a
     supplied React component into vanilla: made WRAP-SAFE (the original clipped
     the whole string on one line) by clipping per character, and driven off the
     live text so it also covers admin-set titles. Real text stays in aria-label;
     the visual spans are aria-hidden. */
  function cscGraphemes(text) {
    if (typeof Intl !== "undefined" && Intl.Segmenter) {
      var seg = new Intl.Segmenter("en", { granularity: "grapheme" });
      return Array.from(seg.segment(text), function (s) { return s.segment; });
    }
    return Array.prototype.slice.call(text);
  }

  function splitCascade(node) {
    if (!node) { return; }
    var text = node.textContent;
    node.setAttribute("data-csc-src", text);
    node.setAttribute("aria-label", text.trim());
    node.classList.add("csc-host");

    var wrap = el("span", "csc");
    wrap.setAttribute("aria-hidden", "true");
    var chars = cscGraphemes(text), idx = 0;
    for (var i = 0; i < chars.length; i++) {
      var ch = chars[i];
      if (ch === " ") { wrap.appendChild(document.createTextNode(" ")); continue; }
      var clip = el("span", "csc__c");
      // The wordmark's period is the one orange glyph; keep that.
      var g = el("span", "csc__g" + (ch === "." ? " csc__g--dot" : ""), ch);
      g.style.setProperty("--i", idx);
      clip.appendChild(g);
      wrap.appendChild(clip);
      idx++;
    }
    node.textContent = "";
    node.appendChild(wrap);
  }

  /* Idempotent: a node already split to the same text is left alone, so a
     re-rendered dynamic title (the catalogue heading changes on every keystroke)
     is not rebuilt needlessly. */
  function cascadeText(node, text) {
    if (!node) { return; }
    if (text == null) { text = node.textContent; }
    if (node.classList.contains("csc-host") && node.getAttribute("data-csc-src") === text) { return; }
    node.classList.remove("csc-host");
    node.textContent = text;
    splitCascade(node);
  }

  /* The stencil headlines whose text is fixed in the HTML (the admin-set hero,
     why and catalogue titles are split where they are rendered instead). */
  var CSC_STATIC = ".sec__title:not(.sec__title--sub), .ctabar h2, .brand__mark, .foot__mark, .pagehero h1";
  function applyStaticCascades() {
    $$(CSC_STATIC).forEach(function (n) {
      if (!n.classList.contains("csc-host")) { splitCascade(n); }
    });
  }

  /* Inline stroke icon matching the ones already in the markup (24-box,
     round caps, currentColor), so JS-built controls look like hand-written
     ones rather than a second icon family. */
  function svgIcon(cls, path) {
    var NS = "http://www.w3.org/2000/svg";
    var svg = document.createElementNS(NS, "svg");
    svg.setAttribute("class", cls);
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("fill", "none");
    svg.setAttribute("stroke", "currentColor");
    svg.setAttribute("stroke-width", "2.6");
    svg.setAttribute("stroke-linecap", "round");
    svg.setAttribute("stroke-linejoin", "round");
    svg.setAttribute("aria-hidden", "true");
    var p = document.createElementNS(NS, "path");
    p.setAttribute("d", path);
    svg.appendChild(p);
    return svg;
  }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }

  /* One source of truth for the motion preference. Anything that MOVES on its
     own (the rotator advancing itself) has to obey it, not just the CSS
     durations: silencing a transition still leaves the content changing under
     someone who asked it not to. */
  function reducedMotion() {
    try { return window.matchMedia("(prefers-reduced-motion: reduce)").matches; }
    catch (e) { return false; }
  }

  /* Per-visit cache. The JSON is served no-cache so the admin never sees a
     stale edit; this stops every navigation refetching a ~1 MB catalogue.
     A fresh window means a fresh fetch. */
  /* In-flight requests, so two callers asking for the same file on the same
     page (applyHome and applyContact both want content.json) share one fetch
     instead of racing two. */
  var inFlight = {};

  function cachedJSON(url, cb) {
    var key = "t1:" + url;
    try {
      var hit = sessionStorage.getItem(key);
      if (hit) { cb(JSON.parse(hit)); return; }
    } catch (e) { /* private mode: just fetch */ }

    if (inFlight[url]) { inFlight[url].push(cb); return; }
    inFlight[url] = [cb];

    function done(data) {
      var waiting = inFlight[url] || [];
      delete inFlight[url];
      waiting.forEach(function (fn) { fn(data); });
    }

    fetch(url, { credentials: "same-origin" })
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (data) {
        try { sessionStorage.setItem(key, JSON.stringify(data)); } catch (e) { /* quota */ }
        done(data);
      })
      .catch(function () { done(null); });
  }

  /* Hidden products are dropped HERE, once, rather than at each call site.
     PRODUCTS is what the whole public build reads from — catalogue, search,
     range counts, featured, related, and the ?p= lookup — so filtering at the
     source is the only way a new consumer cannot forget to check the flag.
     A hidden slug therefore also stops resolving on product.html, which
     correctly falls through to the "product not found" panel. sitemap.php
     applies the same rule server-side. */
  function loadProducts(cb) {
    if (PRODUCTS.length) { cb(PRODUCTS); return; }
    cachedJSON(BASE + "assets/data/products.json", function (data) {
      // "The file would not load" and "everything in it is hidden" are two
      // different situations and must not share an error message.
      productsLoadFailed = !Array.isArray(data);
      PRODUCTS = (Array.isArray(data) ? data : []).filter(function (p) {
        return p && !p.hidden;
      });
      cb(PRODUCTS);
    });
  }

  function loadAttributes(cb) {
    if (ATTRS) { cb(ATTRS); return; }
    cachedJSON(BASE + "assets/data/attributes.json", function (data) {
      ATTRS = data && typeof data === "object" ? data : {};
      cb(ATTRS);
    });
  }

  function loadContent(cb) {
    if (CONTENT) { cb(CONTENT); return; }
    cachedJSON(BASE + "assets/data/content.json", function (data) {
      CONTENT = data && typeof data === "object" ? data : {};
      cb(CONTENT);
    });
  }

  /* Product photo. imgV is the admin's per-upload cache-buster: images are
     served 1-year-immutable, so a replaced photo needs a changed URL. */
  function productImg(p) {
    if (!p || !p.img) { return ""; }
    return BASE + p.img + (p.imgV ? "?v=" + encodeURIComponent(p.imgV) : "");
  }

  function galleryOf(p) {
    var list = (p && Array.isArray(p.images) && p.images.length) ? p.images.slice()
             : (p && p.img ? [p.img] : []);
    var v = p && p.imgV ? "?v=" + encodeURIComponent(p.imgV) : "";
    return list.map(function (src) { return BASE + src + v; });
  }

  function catLabel(id) {
    for (var i = 0; i < CATEGORIES.length; i++) {
      if (CATEGORIES[i].id === id) { return CATEGORIES[i].label; }
    }
    return id || "";
  }

  function param(name) {
    return new URLSearchParams(location.search).get(name) || "";
  }

  /* Whitelist scrubber for the admin-authored fullDescription HTML. Parsed in
     an inert document, then rebuilt from an allowlist, so nothing executable
     or navigable survives. */
  function sanitizeHtml(html) {
    var OK_TAGS = { P: 1, BR: 1, STRONG: 1, B: 1, EM: 1, I: 1, UL: 1, OL: 1, LI: 1, H3: 1, H4: 1, SPAN: 1 };
    var out = document.createDocumentFragment();
    var doc;
    try {
      doc = new DOMParser().parseFromString("<body>" + String(html) + "</body>", "text/html");
    } catch (e) { return document.createTextNode(String(html)); }

    (function walk(src, dest) {
      for (var i = 0; i < src.childNodes.length; i++) {
        var n = src.childNodes[i];
        if (n.nodeType === 3) {
          dest.appendChild(document.createTextNode(n.nodeValue));
        } else if (n.nodeType === 1) {
          var tag = n.tagName;
          if (OK_TAGS[tag]) {
            var copy = document.createElement(tag === "B" ? "STRONG" : (tag === "I" ? "EM" : tag));
            walk(n, copy);          // attributes are dropped wholesale
            dest.appendChild(copy);
          } else {
            walk(n, dest);          // unwrap unknown tags, keep their text
          }
        }
      }
    })(doc.body, out);

    return out;
  }

  /* A spec field is either an array of codes (what the admin writes) or a
     single EN ISO marking string carried over from the catalogue import
     ("S3L", "S1 PL", "S1PL ESD"). For FILTERING we want the individual
     markings, so a string is split on whitespace; "S1 PL" then offers an S1
     chip and a PL chip rather than one unusable compound option. */
  function toCodes(v) {
    if (!v) { return []; }
    if (Array.isArray(v)) {
      return v.map(function (x) { return String(x && x.code != null ? x.code : x).trim(); })
              .filter(Boolean);
    }
    return String(v).trim().split(/\s+/).filter(Boolean);
  }

  /* The marking as printed on the product. Kept intact for display, because
     "S1 PL" is one certification marking, not two separate claims. */
  function rawSpec(v) {
    if (!v) { return ""; }
    if (Array.isArray(v)) { return v.map(function (x) { return String(x && x.code != null ? x.code : x); }).join(" "); }
    return String(v).trim();
  }

  function byCode(a, b) {
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: "base" });
  }

  /* ---------------------------------------------------------- Breadcrumbs -- */
  /* One renderer for both pages, so the trail can never disagree with itself:
       catalogue          Home / Catalogue
       one range          Home / Catalogue / Footwear
       one product        Home / Catalogue / Footwear / Dune Rock
     EVERY crumb is a real link, including the last one. A self-link is the
     W3C breadcrumb pattern (an <a> carrying aria-current="page"), and it means
     the range you are looking at is still a place you can click back to after
     wandering into filters or a search. */
  function renderCrumbs(trail) {
    var nav = $("#crumbs");
    if (!nav) { return; }
    nav.innerHTML = "";
    trail.forEach(function (item, i) {
      if (i) {
        var sep = el("span", "crumbs__sep", "/");
        sep.setAttribute("aria-hidden", "true");
        nav.appendChild(sep);
      }
      var a = el("a", null, item.label);
      a.href = item.href;
      if (i === trail.length - 1) { a.setAttribute("aria-current", "page"); }
      nav.appendChild(a);
    });
  }

  function crumbHome() { return { label: "Home", href: "index.html" }; }
  function crumbCatalogue() { return { label: "Catalogue", href: "products.html" }; }
  function crumbCategory(id) {
    return { label: catLabel(id), href: "products.html?cat=" + encodeURIComponent(id) };
  }

  /* -------------------------------------------------------------- Reveals -- */
  var revealObserver = null;
  var revealFired = false;
  var revealFailsafe = null;

  /* Force-show, bypassing the transition. Adding .is-in alone is not enough:
     a transition that never advances (background tab, headless renderer) leaves
     the element parked on its from-value, so the content stays invisible. The
     inline styles cannot get stuck. */
  function revealAll() {
    $$(".reveal").forEach(function (n) {
      n.classList.add("is-in");
      n.style.transition = "none";
      n.style.opacity = "1";
      n.style.transform = "none";
    });
  }

  function setupReveals() {
    if (!("IntersectionObserver" in window)) { revealAll(); return; }

    revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          revealFired = true;
          e.target.classList.add("is-in");
          revealObserver.unobserve(e.target);
        }
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.06 });
    observeReveals();
  }

  /* Failsafe: if the observer has never reported after a couple of seconds it
     is not doing its job (background tab, headless renderer, odd embedding),
     so show everything. Once it HAS reported we know it works and leave the
     scroll reveal alone. Re-armed on every observeReveals() call, because the
     catalogue and related-product cards are created after the first pass and
     would otherwise miss the original window. */
  function armRevealFailsafe() {
    clearTimeout(revealFailsafe);
    revealFailsafe = setTimeout(function () {
      if (!revealFired) { revealAll(); }
    }, 2000);
  }

  function observeReveals() {
    if (!revealObserver) { revealAll(); return; }
    $$(".reveal:not(.is-in)").forEach(function (n) { revealObserver.observe(n); });
    armRevealFailsafe();
  }

  /* ------------------------------------------------------------ Header nav -- */
  function setupNav() {
    var burger = $("#burger");
    var nav = $("#nav");
    if (!burger || !nav) { return; }

    $$("a", nav).forEach(function (a, i) { a.style.setProperty("--i", i); });

    // The icon morphs to an X, so the label has to say the same thing.
    function shut() {
      nav.classList.remove("is-open");
      burger.setAttribute("aria-expanded", "false");
      burger.setAttribute("aria-label", "Open menu");
    }
    function open() {
      nav.classList.add("is-open");
      burger.setAttribute("aria-expanded", "true");
      burger.setAttribute("aria-label", "Close menu");
    }

    burger.addEventListener("click", function () {
      if (nav.classList.contains("is-open")) { shut(); } else { open(); }
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") { shut(); }
    });
    nav.addEventListener("click", function (e) {
      if (e.target.tagName === "A") { shut(); }
    });

    // Mark the current category in the nav.
    var cat = param("cat");
    if (cat) {
      $$("a[data-cat]", nav).forEach(function (a) {
        if (a.getAttribute("data-cat") === cat) { a.setAttribute("aria-current", "true"); }
      });
    }
  }

  /* --------------------------------------------------------------- Rotator -- */
  /* Purpose: three standing marketing messages share one slot instead of
     stacking three more sections onto the page. Auto-advance is slow (20s),
     pauses on hover/focus and whenever the tab is hidden, and every slide is
     reachable by hand from the dots. */
  function setupRotator() {
    var root = $("#rotator");
    if (!root) { return; }
    var track = $(".rotator__track", root);
    // Only the slides that survived applyHome(); a slide the admin left empty
    // is removed, and its dot goes with it.
    var slides = $$(".rotator__slide", root).filter(function (s) { return !s.hidden; });
    var dots = $$(".rotator__dot", root).filter(function (d) { return !d.hidden; });
    if (slides.length < 2) {
      var dotBox = $("#rotatorDots");
      if (dotBox) { dotBox.hidden = true; }
      return;
    }

    var idx = 0;
    var timer = null;
    var DELAY = 20000;
    /* Content that changes on its own is exactly what a reduced-motion request
       is about, so the carousel becomes a manual one: the dots still work, it
       just never moves by itself. */
    var AUTO = !reducedMotion();

    root.style.setProperty("--rotator-delay", DELAY + "ms");
    if (AUTO) { root.classList.add("rotator--timed"); }

    /* The dots are a tablist, so each one has to own a panel. Without
       aria-controls the relationship is asserted and never stated, and a
       screen-reader user gets six controls with nothing attached. */
    slides.forEach(function (s, n) {
      if (!s.id) { s.id = "rotatorSlide" + n; }
      s.setAttribute("role", "tabpanel");   // keeps aria-roledescription="slide"
    });
    dots.forEach(function (d, n) {
      if (!d.id) { d.id = "rotatorTab" + n; }
      d.setAttribute("aria-controls", slides[n] ? slides[n].id : "");
      if (slides[n]) { slides[n].setAttribute("aria-labelledby", d.id); }
    });

    function show(i) {
      idx = (i + slides.length) % slides.length;
      track.style.transform = "translateX(-" + (idx * 100) + "%)";
      slides.forEach(function (s, n) {
        var on = n === idx;
        s.setAttribute("aria-hidden", on ? "false" : "true");
        // A tabpanel holding no focusable content has to be focusable itself,
        // or the copy inside it is unreachable from the keyboard.
        s.tabIndex = on ? 0 : -1;
      });
      dots.forEach(function (d, n) {
        var on = n === idx;
        d.setAttribute("aria-selected", on ? "true" : "false");
        // Roving tabindex: the tablist is one tab stop, arrows move inside it.
        d.tabIndex = on ? 0 : -1;
      });
    }

    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function start() {
      stop();
      if (!AUTO || document.hidden) { return; }
      timer = setInterval(function () { show(idx + 1); }, DELAY);
    }
    /* The dot fill is a readout of that timer, so it has to freeze with it. */
    function pause() { stop(); root.classList.add("is-paused"); }
    function resume() { root.classList.remove("is-paused"); start(); }

    dots.forEach(function (d, n) {
      d.addEventListener("click", function () { show(n); resume(); });
    });

    root.addEventListener("keydown", function (e) {
      var next = e.key === "ArrowRight" ? 1 : (e.key === "ArrowLeft" ? -1 : 0);
      if (!next || dots.indexOf(document.activeElement) === -1) { return; }
      e.preventDefault();
      show(idx + next);
      resume();
      dots[idx].focus();
    });

    root.addEventListener("mouseenter", pause);
    root.addEventListener("mouseleave", resume);
    root.addEventListener("focusin", pause);
    root.addEventListener("focusout", resume);
    document.addEventListener("visibilitychange", function () {
      if (document.hidden) { pause(); } else { resume(); }
    });

    show(0);
    start();
  }

  /* ------------------------------------------------------------ Card build -- */
  /* `tag` is the heading level for the product name. The catalogue grid sits
     directly under the page h1 so its cards are h2; the featured and related
     rows sit under their own section h2, so they are h3. Getting this wrong
     skips a level and breaks screen-reader navigation. */
  /* ------------------------------------------------------ Image preloading --
     Product photos were trickling in one card at a time on a scroll: every
     <img> was loading="lazy", so the browser only STARTED the request once the
     card was nearly on screen, and on a phone connection that is a visible
     wait per row.
     Two changes fix it. The first screenful loads eagerly (nothing above the
     fold should ever wait for a lazy trigger), and everything after that is
     warmed in a rolling window: as each card comes within 800px of the
     viewport we fetch the NEXT 15 photos into the HTTP cache, so by the time
     the browser gets round to the real <img> the bytes are already there.
     PRELOAD_EAGER is capped low on purpose: making all 139 eager would fight
     the visible cards for bandwidth and make things worse, not better. */
  var PRELOAD_EAGER = 8;      // loaded immediately, no lazy trigger
  var PRELOAD_AHEAD = 15;     // kept warm ahead of wherever you are
  var preloadQueue = [];      // photo URLs, in grid order
  var preloadedTo = 0;
  var preloadTick = 0;

  function warmTo(n) {
    if (n > preloadQueue.length) { n = preloadQueue.length; }
    while (preloadedTo < n) {
      var url = preloadQueue[preloadedTo++];
      if (url) {
        var warm = new Image();
        warm.decoding = "async";
        warm.src = url;       // response lands in the HTTP cache; nothing is rendered
      }
    }
  }

  /* How far through the list the viewport currently is, mapped onto the photo
     queue. Derived from scroll position rather than from observing all 139
     cards: one cheap sum instead of 139 observations, no getBoundingClientRect
     per card (so no layout thrash), and — the reason it is done this way —
     it does not depend on IntersectionObserver, which is exactly the API that
     silently does nothing in a throttled or headless renderer. The 15-image
     lookahead makes the approximation harmless. */
  function preloadFromScroll() {
    if (preloadedTo >= preloadQueue.length) { return; }
    var doc = document.documentElement;
    var scrollable = doc.scrollHeight - window.innerHeight;
    var progress = scrollable > 0 ? (window.pageYOffset || doc.scrollTop) / scrollable : 0;
    progress = clamp(progress, 0, 1);
    warmTo(Math.ceil(progress * preloadQueue.length) + PRELOAD_EAGER + PRELOAD_AHEAD);
  }

  function onPreloadScroll() {
    var now = Date.now();
    if (now - preloadTick < 150) { return; }   // plain throttle: no rAF dependency
    preloadTick = now;
    preloadFromScroll();
  }

  /* Reset the window for a freshly rendered grid. */
  function resetPreload(urls) {
    preloadQueue = urls || [];
    preloadedTo = 0;
    preloadTick = 0;
    warmTo(PRELOAD_EAGER + PRELOAD_AHEAD);
    if (preloadQueue.length > PRELOAD_EAGER + PRELOAD_AHEAD) {
      window.removeEventListener("scroll", onPreloadScroll);
      window.addEventListener("scroll", onPreloadScroll, { passive: true });
    }
  }

  function buildCard(p, i, instant, tag) {
    var card = el("article", "card" + (instant ? "" : " reveal"));
    if (!instant) { card.style.setProperty("--rd", Math.min(i, 7)); }
    card.setAttribute("data-i", i);

    var media = el("div", "card__media");
    var src = productImg(p);
    if (src) {
      var img = new Image();
      img.alt = p.name || "";
      // The first screenful must not wait for a lazy trigger.
      if (i < PRELOAD_EAGER) {
        img.loading = "eager";
        img.fetchPriority = i < 4 ? "high" : "auto";
      } else {
        img.loading = "lazy";
      }
      img.decoding = "async";
      img.src = src;
      media.appendChild(img);
    } else {
      var ph = el("span", "card__ph", "KAPRIOL");
      ph.setAttribute("aria-hidden", "true");
      media.appendChild(ph);
    }
    if (p.isNew) { media.appendChild(el("span", "card__new", "New")); }
    card.appendChild(media);

    var body = el("div", "card__body");
    var h = el(tag || "h3", "card__name");
    var a = el("a", "card__link", p.name || "");
    a.href = "product.html?p=" + encodeURIComponent(p.slug || "");
    h.appendChild(a);
    body.appendChild(h);

    var spec = p.description || (Array.isArray(p.features) ? p.features.slice(0, 2).join(". ") : "");
    if (spec) { body.appendChild(el("p", "card__spec", spec)); }
    card.appendChild(body);
    return card;
  }

  function skeletonGrid(root, n) {
    var grid = el("div", "grid");
    for (var i = 0; i < n; i++) {
      var c = el("article", "card card--skeleton");
      c.appendChild(el("div", "card__media"));
      var b = el("div", "card__body");
      b.appendChild(el("div", "sk"));
      b.appendChild(el("div", "sk"));
      c.appendChild(b);
      grid.appendChild(c);
    }
    root.innerHTML = "";
    root.appendChild(grid);
  }

  /* ------------------------------------------------------- Home page media -- */
  /* Nothing on the home page is hardcoded photography. The hero, the three
     rotator slides and the "why" block are authored in the admin and stored in
     content.json under `home`. Every field is optional:
       - missing text  -> the wording already in the HTML stays
       - missing image -> the slot renders as a flat brand panel, never a
         broken image and never an empty grey frame
     Because the rotator's slide count depends on this, setupRotator() is
     called from here rather than from init(). */
  function applyHome() {
    if (!$(".hero")) { return; }        // home page only
    loadContent(function (c) {
      var h = (c && c.home) || {};

      fillMedia("#heroMedia", h.heroImg, h.heroImgV, "", true);
      setIfPresent("#heroTitle", h.heroTitle);
      setMultiline("#heroSub", h.heroSub);

      // The whole banner can be switched off in the admin. Absent means shown.
      var rotator = $("#rotator");
      if (h.showRotator === false) {
        if (rotator) { rotator.hidden = true; }
        setIfPresent("#whyTitle", h.whyTitle);
        setMultiline("#whyBody", h.whyBody);
        applyStats(h);
        applyWhyMedia(h);
        cascadeText($("#heroTitle"));
        cascadeText($("#whyTitle"));
        observeReveals();
        return;
      }

      var slides = Array.isArray(h.slides) ? h.slides : [];
      var kept = 0;
      for (var i = 0; i < 3; i++) {
        var s = slides[i] || {};
        var slide = $('.rotator__slide[data-slide="' + i + '"]');
        var dot = $$("#rotatorDots .rotator__dot")[i];
        if (!slide) { continue; }

        // A slide is dropped when the admin unticked "Show this slide", or when
        // both its text and photo are empty (which reads as "I do not want it").
        var authored = (s.eyebrow || s.title || s.body || s.img);
        var hasDefault = $("#slideTitle" + i) && $("#slideTitle" + i).textContent.trim();
        if (s.show === false || (!authored && !hasDefault)) {
          slide.hidden = true;
          if (dot) { dot.hidden = true; }
          continue;
        }
        kept++;
        fillMedia("#slideMedia" + i, s.img, s.imgV, "", false);
        setIfPresent("#slideEyebrow" + i, s.eyebrow);
        setIfPresent("#slideTitle" + i, s.title);
        setMultiline("#slideBody" + i, s.body);
        if (dot) { dot.setAttribute("aria-label", "Slide " + kept); }
      }

      // Every slide hidden (or emptied) means the whole banner has nothing to
      // show, so drop the section rather than leave an empty band.
      if (kept === 0 && rotator) { rotator.hidden = true; }

      setIfPresent("#whyTitle", h.whyTitle);
      setMultiline("#whyBody", h.whyBody);
      applyStats(h);
      applyWhyMedia(h);
      cascadeText($("#heroTitle"));
      cascadeText($("#whyTitle"));

      setupRotator();
      observeReveals();
    });
  }

  /* The three key figures in the "why" block are fully editable in the admin
     (number + label each). A field left blank keeps the built-in wording, the
     same fallback convention as every other home field. */
  function applyStats(h) {
    var stats = Array.isArray(h.stats) ? h.stats : [];
    for (var i = 0; i < 3; i++) {
      var s = stats[i] || {};
      setIfPresent("#statNum" + i, s.num);
      setIfPresent("#statLabel" + i, s.label);
    }
  }

  function applyWhyMedia(h) {
    var whyBox = $("#whyMedia");
    if (!whyBox) { return; }
    if (h.whyImg) {
      whyBox.hidden = false;
      fillMedia("#whyMedia", h.whyImg, h.whyImgV, "", false);
    } else {
      // No photo: drop the frame and let the text use the full width.
      whyBox.hidden = true;
      var why = $("#whySection");
      if (why) { why.classList.add("why--solo"); }
    }
  }

  /* The hero entrance uses animation-fill-mode: backwards, which parks the
     element on its from-state (opacity 0) until the animation runs. If it
     never runs - a throttled background tab, a headless renderer, an animation
     the browser declined to start - the hero would stay invisible. The
     entrance is over by ~860ms, so anything still transparent well after that
     has failed: strip the animation and let the natural styles show. */
  function guardHeroEntrance() {
    var h1 = $(".hero h1");
    if (!h1) { return; }
    setTimeout(function () {
      if (parseFloat(getComputedStyle(h1).opacity || "1") > 0.99) { return; }
      $$(".hero .hazard, .hero h1, .hero__sub").forEach(function (n) {
        n.style.animation = "none";
      });
    }, 1400);
  }

  function setIfPresent(sel, value) {
    var n = $(sel);
    if (n && value != null && String(value).trim() !== "") { n.textContent = value; }
  }

  /* Like setIfPresent, but honours the line breaks the admin typed: every
     newline in the stored value becomes a <br>, so body copy breaks publicly
     exactly where it was broken in the admin form and nowhere else. Text is
     inserted as text nodes (never innerHTML), so it cannot inject markup. */
  function setMultiline(sel, value) {
    var n = $(sel);
    if (!(n && value != null && String(value).trim() !== "")) { return; }
    var lines = String(value).split("\n");
    n.textContent = "";
    lines.forEach(function (line, i) {
      if (i) { n.appendChild(document.createElement("br")); }
      n.appendChild(document.createTextNode(line));
    });
  }

  /* Insert an <img> into a media slot. eager=true for the hero (it is the LCP
     element); everything below the fold stays lazy. */
  function fillMedia(sel, path, ver, alt, eager) {
    var box = $(sel);
    if (!box) { return; }
    box.innerHTML = "";
    if (!path) { return; }
    var img = new Image();
    img.src = BASE + path + (ver ? "?v=" + encodeURIComponent(ver) : "");
    img.alt = alt || "";
    img.decoding = "async";
    if (eager) { img.fetchPriority = "high"; } else { img.loading = "lazy"; }
    // A path that no longer resolves must not leave a broken-image icon.
    img.addEventListener("error", function () { box.innerHTML = ""; });
    box.appendChild(img);
  }

  /* ------------------------------------------------------------------ Home -- */
  function initHome() {
    var catRoot = $("#catGrid");
    var featRoot = $("#featured");
    if (!catRoot && !featRoot) { return; }

    // Content first: the range tiles can carry an admin-uploaded photo and the
    // featured row can be hand-picked, so both need content.json. Both loaders
    // are cached and de-duplicated, so this costs one request.
    loadContent(function () {
      loadProducts(function (items) {
        if (catRoot) { renderRangeList(catRoot, items); }
        if (featRoot) { renderFeatured(featRoot, items); }
        observeReveals();
      });
    });
  }

  /* An admin-uploaded photo for a range tile, if there is one. */
  function categoryImg(id) {
    var cats = (CONTENT && CONTENT.categories) || {};
    var row = cats[id];
    if (!row || !row.img) { return ""; }
    return BASE + row.img + (row.imgV ? "?v=" + encodeURIComponent(row.imgV) : "");
  }

  /* Home page: the six ranges as a vertical list that expands on hover
     (corner brackets, accent border, an arrow leaning in). Text-only and
     data-driven — the products landing page keeps the photo tiles below. */
  function renderRangeList(root, items) {
    root.innerHTML = "";
    CATEGORIES.forEach(function (cat, i) {
      var count = items.filter(function (p) { return p.category === cat.id; }).length;

      var a = el("a", "range reveal");
      a.href = "products.html?cat=" + encodeURIComponent(cat.id);
      a.style.setProperty("--rd", i);

      var body = el("span", "range__body");
      body.appendChild(el("span", "range__title", cat.label));
      body.appendChild(el("span", "range__sub", count + " products"));
      a.appendChild(body);

      var go = el("span", "range__go", "→");   // → leans in on hover
      go.setAttribute("aria-hidden", "true");
      a.appendChild(go);

      root.appendChild(a);
    });
  }

  function renderCategoryTiles(root, items) {
    root.innerHTML = "";
    CATEGORIES.forEach(function (cat, i) {
      var list = items.filter(function (p) { return p.category === cat.id; });
      // Prefer the photo the admin uploaded for this range; fall back to the
      // first product in it that has one.
      var custom = categoryImg(cat.id);
      var lead = custom ? null : list.find(function (p) { return p.img; });

      var a = el("a", "cat reveal");
      a.href = "products.html?cat=" + encodeURIComponent(cat.id);
      a.style.setProperty("--rd", i);

      var media = el("div", "cat__img");
      if (custom || lead) {
        var img = new Image();
        img.src = custom || productImg(lead);
        img.alt = "";
        img.loading = "lazy";
        img.decoding = "async";
        media.appendChild(img);
      } else {
        // No photography for this range yet: show the brand device rather
        // than an empty grey box.
        media.className = "cat__img cat__img--empty";
        var mark = el("span", null, "KAPRIOL");
        mark.setAttribute("aria-hidden", "true");
        media.appendChild(mark);
      }
      a.appendChild(media);
      a.appendChild(el("div", "cat__scrim"));

      var body = el("div", "cat__body");
      body.appendChild(el("span", "cat__name", cat.label));
      var meta = el("span", "cat__meta");
      meta.appendChild(el("span", "cat__count", list.length + " products"));
      var go = el("span", "cat__go", "›");   // leans on hover
      go.setAttribute("aria-hidden", "true");
      meta.appendChild(go);
      body.appendChild(meta);
      a.appendChild(body);
      a.appendChild(el("div", "cat__rule"));
      root.appendChild(a);
    });
  }

  /* The four teaser cards. If the admin has hand-picked any slot, that
     selection wins; otherwise the row fills itself with one product per range,
     preferring new arrivals that actually have a photograph. Mixing the two is
     allowed: pick two, leave two, and the gaps fill automatically. */
  function renderFeatured(root, items) {
    var chosen = ((CONTENT && CONTENT.home && CONTENT.home.featured) || [])
      .map(function (slug) {
        return slug ? items.find(function (p) { return p.slug === slug; }) : null;
      });

    var picks = [];
    var order = ["footwear", "clothing", "clothing-accessories", "footwear-accessories", "ppe", "ppe-accessories"];
    var auto = [];
    order.forEach(function (cid) {
      var pool = items.filter(function (p) { return p.category === cid && p.img; });
      if (!pool.length) { return; }
      auto.push(pool.find(function (p) { return p.isNew; }) || pool[0]);
    });

    for (var i = 0; i < 4; i++) {
      if (chosen[i]) { picks.push(chosen[i]); continue; }
      // Fill the gap with the next automatic pick that isn't already shown.
      var fill = auto.find(function (p) {
        return picks.indexOf(p) === -1 && chosen.indexOf(p) === -1;
      });
      if (fill) { picks.push(fill); }
    }

    if (!picks.length) { root.closest("section").hidden = true; return; }

    root.innerHTML = "";
    picks.forEach(function (p, i) {
      var card = buildCard(p, i);
      var tag = el("span", "card__tag", catLabel(p.category));
      $(".card__media", card).appendChild(tag);
      root.appendChild(card);
    });
  }

  /* --------------------------------------------------------------- Catalog -- */
  var filterState = {};

  function initCatalog() {
    var root = $("#catalog");
    if (!root) { return; }

    skeletonGrid(root, 8);

    loadProducts(function (items) {
      if (!items.length) {
        root.innerHTML = "";
        root.appendChild(productsLoadFailed
          ? buildEmpty(
              "Catalogue unavailable",
              "The product list could not be loaded. Please refresh the page.",
              null)
          // Every product is hidden in the admin. Say something true and give
          // the visitor the one route that still works.
          : buildEmpty(
              "Catalogue temporarily unavailable",
              "Our product listings are being updated. Please get in touch and we will help you directly.",
              { label: "Contact us", href: "contact.html" }));
        return;
      }
      loadAttributes(function () {
        setupSearch();
        renderCatalog();
      });
    });
  }

  function renderCatalog() {
    var root = $("#catalog");
    if (!root) { return; }

    var q = ($("#q") ? $("#q").value : "").trim();
    var cat = param("cat");
    var titleEl = $("#catalogTitle");
    var metaEl = $("#catalogMeta");
    var filtersRoot = $("#filters");

    root.innerHTML = "";
    if (metaEl) { metaEl.textContent = ""; }

    /* --- search across everything ------------------------------------ */
    if (q) {
      if (titleEl) { cascadeText(titleEl, "Search"); }
      // A search spans every range, so there is no range to name. You are
      // still inside the catalogue, and the trail says exactly that.
      renderCrumbs([crumbHome(), crumbCatalogue()]);
      if (filtersRoot) { filtersRoot.hidden = true; }

      var hits = searchProducts(q);
      if (metaEl) {
        metaEl.innerHTML = hits.length
          ? hits.length + " " + (hits.length === 1 ? "product" : "products") + " matching <strong>" + esc(q) + "</strong>"
          : "";
      }
      if (!hits.length) {
        root.appendChild(buildEmpty(
          "No products match that",
          "Try a shorter or different term, or browse a range from the menu.",
          { label: "Browse the full catalogue", href: "products.html" }
        ));
        return;
      }
      root.appendChild(makeGrid(hits, true));
      return;
    }

    /* --- one category ------------------------------------------------- */
    if (cat && CATEGORIES.some(function (c) { return c.id === cat; })) {
      var list = PRODUCTS.filter(function (p) { return p.category === cat; });
      if (titleEl) { cascadeText(titleEl, catLabel(cat)); }
      renderCrumbs([crumbHome(), crumbCatalogue(), crumbCategory(cat)]);
      document.title = catLabel(cat) + " | Kapriol Bulgaria";

      var filtered = applyFilters(list);
      if (filtersRoot) { renderFilters(filtersRoot, list); }

      if (metaEl) {
        metaEl.textContent = filtered.length === list.length
          ? list.length + " products"
          : filtered.length + " of " + list.length + " products";
      }
      if (!filtered.length) {
        root.appendChild(buildEmpty(
          "Nothing matches those filters",
          "Clear a filter to widen the results.",
          { label: "Clear filters", action: clearFilters }
        ));
        return;
      }
      root.appendChild(makeGrid(filtered, false));
      observeReveals();
      return;
    }

    /* --- landing: the six ranges -------------------------------------- */
    if (titleEl) { cascadeText(titleEl, "All ranges"); }
    renderCrumbs([crumbHome(), crumbCatalogue()]);
    if (filtersRoot) { filtersRoot.hidden = true; }
    if (metaEl) { metaEl.textContent = PRODUCTS.length + " products across six ranges"; }

    var tiles = el("div", "cats");
    root.appendChild(tiles);
    renderCategoryTiles(tiles, PRODUCTS);
    observeReveals();
  }

  function makeGrid(items, instant) {
    var grid = el("div", "grid");
    // Arm the rolling preload window for THIS result set before building the
    // cards, so the observer exists by the time they are handed to it.
    resetPreload(items.map(productImg));
    // Catalogue results are the page's own content, one level under the h1.
    items.forEach(function (p, i) { grid.appendChild(buildCard(p, i, instant, "h2")); });
    return grid;
  }

  /* `tag` defaults to h2 because the empty panel normally sits under the page
     h1. On the not-found product page it REPLACES the h1, so that call passes
     "h1": otherwise the document is left with no top-level heading at all. */
  function buildEmpty(title, body, cta, tag) {
    var box = el("div", "empty");
    box.appendChild(el(tag || "h2", null, title));
    box.appendChild(el("p", null, body));
    if (cta) {
      if (cta.href) {
        var a = el("a", "btn btn--dark", cta.label);
        a.href = cta.href;
        box.appendChild(a);
      } else {
        var b = el("button", "btn btn--dark", cta.label);
        b.type = "button";
        b.addEventListener("click", cta.action);
        box.appendChild(b);
      }
    }
    return box;
  }

  /* ---------------------------------------------------------------- Search -- */
  function searchProducts(q) {
    var terms = q.toLowerCase().split(/\s+/).filter(Boolean);
    if (!terms.length) { return []; }
    return PRODUCTS.filter(function (p) {
      var hay = [
        p.name, p.description, p.productLine, catLabel(p.category),
        Array.isArray(p.features) ? p.features.join(" ") : ""
      ].join(" ").toLowerCase();
      return terms.every(function (t) { return hay.indexOf(t) !== -1; });
    });
  }

  function setupSearch() {
    var input = $("#q");
    if (!input) { return; }
    var clear = $("#qClear");
    var t = null;

    input.value = param("q");
    syncClear();

    function syncClear() {
      if (clear) { clear.hidden = !input.value; }
    }

    function apply() {
      syncClear();
      var url = new URL(location.href);
      if (input.value.trim()) {
        url.searchParams.set("q", input.value.trim());
        url.searchParams.delete("cat");
      } else {
        url.searchParams.delete("q");
      }
      history.replaceState(null, "", url);
      renderCatalog();
    }

    input.addEventListener("input", function () {
      clearTimeout(t);
      t = setTimeout(apply, 120);
    });
    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter") { e.preventDefault(); clearTimeout(t); apply(); }
      if (e.key === "Escape") { input.value = ""; clearTimeout(t); apply(); }
    });
    if (clear) {
      clear.addEventListener("click", function () {
        input.value = ""; apply(); input.focus();
      });
    }
  }

  /* --------------------------------------------------------------- Filters -- */
  function specDefs(groupKey) {
    var list = (ATTRS && Array.isArray(ATTRS[groupKey])) ? ATTRS[groupKey] : [];
    var map = {};
    list.forEach(function (d) { if (d && d.code) { map[String(d.code)] = d; } });
    return map;
  }

  /* Only offer codes that products in this view actually carry. */
  function specOptions(groupKey, items) {
    var counts = {};
    items.forEach(function (p) {
      toCodes(p[groupKey]).forEach(function (c) { counts[c] = (counts[c] || 0) + 1; });
    });
    return Object.keys(counts).sort(byCode).map(function (c) {
      return { code: c, n: counts[c] };
    });
  }

  function applyFilters(items) {
    var active = SPEC_GROUPS.filter(function (g) {
      return filterState[g.key] && filterState[g.key].length;
    });
    if (!active.length) { return items; }
    // AND across every selected code, within and across groups.
    return items.filter(function (p) {
      return active.every(function (g) {
        var have = toCodes(p[g.key]);
        return filterState[g.key].every(function (c) { return have.indexOf(c) !== -1; });
      });
    });
  }

  function clearFilters() {
    filterState = {};
    renderCatalog();
  }

  /* ------------------------------------------------------- Filters drawer --
     On a phone the filter panel is a right-hand drawer over the page, not an
     accordion that pushes the whole grid down. `filtersOpen` lives out here
     because renderFilters() rebuilds the panel from scratch on every chip
     click: without it, choosing a filter would slam the drawer shut on you. */
  var filtersOpen = false;

  // Per-group collapse state for the desktop sidebar. Lives out here for the
  // same reason as filtersOpen: renderFilters() rebuilds the panel on every
  // chip click, so without a persisted flag a section would spring back open
  // the moment you picked a filter inside it. Default (absent) = expanded.
  var groupCollapsed = {};

  function drawerMode() {
    try { return window.matchMedia("(max-width: 760px)").matches; }
    catch (e) { return false; }
  }

  // The always-open left sidebar is a desktop-only treatment; below it the
  // filters stay the toggle+drawer, where per-section collapse is not offered.
  function sidebarMode() {
    try { return window.matchMedia("(min-width: 1025px)").matches; }
    catch (e) { return false; }
  }

  function setFiltersOpen(open) {
    filtersOpen = !!open;
    var panel = $(".filters__panel");
    var toggle = $(".filters__toggle");
    var back = $(".filters__backdrop");
    if (panel) { panel.setAttribute("data-open", filtersOpen ? "true" : "false"); }
    if (toggle) { toggle.setAttribute("aria-expanded", filtersOpen ? "true" : "false"); }
    if (back) { back.hidden = !filtersOpen; }
    // Only lock the page behind a real drawer; the desktop accordion is inline.
    document.body.classList.toggle("is-drawer-open", filtersOpen && drawerMode());
    if (filtersOpen && panel) {
      var close = $(".filters__close", panel);
      if (close && drawerMode()) { close.focus(); }
    } else if (toggle && document.activeElement && !document.body.contains(document.activeElement)) {
      toggle.focus();
    }
  }

  // One document-level listener for the whole session, not one per re-render.
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && filtersOpen) { setFiltersOpen(false); var t = $(".filters__toggle"); if (t) { t.focus(); } }
  });

  function renderFilters(root, items) {
    var groups = SPEC_GROUPS.map(function (g) {
      return { g: g, opts: specOptions(g.key, items) };
    }).filter(function (x) { return x.opts.length > 1; });

    if (!groups.length) { root.hidden = true; return; }
    root.hidden = false;

    var activeCount = SPEC_GROUPS.reduce(function (n, g) {
      return n + ((filterState[g.key] || []).length);
    }, 0);

    root.innerHTML = "";

    var bar = el("div", "filters__bar");
    var toggle = el("button", "filters__toggle");
    toggle.type = "button";
    toggle.setAttribute("aria-expanded", filtersOpen ? "true" : "false");
    toggle.setAttribute("aria-controls", "filtersPanel");
    toggle.appendChild(document.createTextNode("Filter"));
    if (activeCount) { toggle.appendChild(el("span", "filters__badge", String(activeCount))); }
    toggle.appendChild(svgIcon("filters__chev", "M6 9l6 6 6-6"));
    bar.appendChild(toggle);

    if (activeCount) {
      var clearBtn = el("button", "filters__clear", "Clear all");
      clearBtn.type = "button";
      clearBtn.addEventListener("click", clearFilters);
      bar.appendChild(clearBtn);
    }
    root.appendChild(bar);

    // Backdrop for the mobile drawer. Kept inside #filters so the whole
    // control is torn down and rebuilt as one unit on every re-render.
    var back = el("div", "filters__backdrop");
    back.hidden = !filtersOpen;
    back.addEventListener("click", function () { setFiltersOpen(false); });
    root.appendChild(back);

    var panel = el("div", "filters__panel");
    panel.id = "filtersPanel";
    panel.setAttribute("data-open", filtersOpen ? "true" : "false");
    panel.setAttribute("role", "group");
    panel.setAttribute("aria-label", "Filter products");
    var inner = el("div");

    // Drawer chrome: only rendered as a drawer below 760px (CSS hides it
    // above that), where a panel covering the page needs a way out.
    var head = el("div", "filters__drawerhead");
    head.appendChild(el("h2", "filters__drawertitle", "Filters"));
    // Desktop sidebar Clear-all. The .filters__bar (which carries the drawer/
    // tablet Clear-all) is hidden in sidebar mode, so the reset lives here too;
    // CSS shows exactly one of the two per breakpoint.
    if (activeCount) {
      var headClear = el("button", "filters__clear filters__clear--head", "Clear all");
      headClear.type = "button";
      headClear.addEventListener("click", clearFilters);
      head.appendChild(headClear);
    }
    var closeBtn = el("button", "filters__close");
    closeBtn.type = "button";
    closeBtn.setAttribute("aria-label", "Close filters");
    closeBtn.appendChild(svgIcon("filters__closeicon", "M6 6l12 12M18 6L6 18"));
    closeBtn.addEventListener("click", function () {
      setFiltersOpen(false);
      var t = $(".filters__toggle");
      if (t) { t.focus(); }
    });
    head.appendChild(closeBtn);
    inner.appendChild(head);

    var wrap = el("div", "filters__groups");

    groups.forEach(function (x) {
      var box = el("div", "fgroup");
      var collapsed = !!groupCollapsed[x.g.key];
      if (collapsed) { box.classList.add("is-collapsed"); }

      // Collapsible header. Below the sidebar breakpoint CSS renders it as a
      // plain heading (chevron hidden, body forced open) and the handler no-ops,
      // so the drawer/tablet behaviour is unchanged.
      var bodyId = "fg-" + x.g.key;
      var head = el("button", "fgroup__head");
      head.type = "button";
      head.setAttribute("aria-expanded", collapsed ? "false" : "true");
      head.setAttribute("aria-controls", bodyId);
      head.appendChild(el("span", "fgroup__label", x.g.label));
      head.appendChild(svgIcon("fgroup__chev", "M6 9l6 6 6-6"));
      head.addEventListener("click", function () {
        if (!sidebarMode()) { return; }
        var now = !groupCollapsed[x.g.key];
        groupCollapsed[x.g.key] = now;
        box.classList.toggle("is-collapsed", now);
        head.setAttribute("aria-expanded", now ? "false" : "true");
      });
      box.appendChild(head);

      var body = el("div", "fgroup__body");
      body.id = bodyId;
      var chips = el("div", "fchips");
      var defs = specDefs(x.g.key);
      x.opts.forEach(function (o) {
        var on = (filterState[x.g.key] || []).indexOf(o.code) !== -1;
        var b = el("button", "fchip");
        b.type = "button";
        b.setAttribute("aria-pressed", on ? "true" : "false");
        var def = defs[o.code];
        if (def && def.title) { b.title = def.title; }
        else if (def && def.name) { b.title = def.name; }
        b.appendChild(document.createTextNode(o.code));
        b.appendChild(el("span", "fchip__n", String(o.n)));
        b.addEventListener("click", function () {
          var cur = filterState[x.g.key] || [];
          filterState[x.g.key] = on
            ? cur.filter(function (c) { return c !== o.code; })
            : cur.concat([o.code]);
          renderCatalog();
        });
        chips.appendChild(b);
      });
      body.appendChild(chips);
      box.appendChild(body);
      wrap.appendChild(box);
    });

    inner.appendChild(wrap);

    // Filtering is live, so this only dismisses the drawer — but it tells you
    // what you are going back to, which "Close" does not.
    var shown = applyFilters(items).length;
    var foot = el("div", "filters__drawerfoot");
    var done = el("button", "btn btn--dark btn--block",
      shown === 1 ? "Show 1 product" : "Show " + shown + " products");
    done.type = "button";
    done.addEventListener("click", function () {
      setFiltersOpen(false);
      var t = $(".filters__toggle");
      if (t) { t.focus(); }
    });
    foot.appendChild(done);
    inner.appendChild(foot);

    panel.appendChild(inner);
    root.appendChild(panel);

    toggle.addEventListener("click", function () { setFiltersOpen(!filtersOpen); });

    // Re-assert the lock/backdrop against the freshly built nodes.
    setFiltersOpen(filtersOpen);
  }

  /* -------------------------------------------------------- Product detail -- */
  function initProduct() {
    var root = $("#product");
    if (!root) { return; }

    loadProducts(function (items) {
      var slug = param("p");
      var p = items.find(function (x) { return x.slug === slug; });
      if (!p) { renderNotFound(root); return; }
      loadAttributes(function () {
        renderProduct(root, p, items);
        observeReveals();
        beacon("t1-product", p.slug);
      });
    });
  }

  function renderNotFound(root) {
    // The trail lives inside #product, so clearing the page takes it with it.
    // Put it back: "Home / Catalogue" is still true, and a dead-end page is
    // exactly where a visitor most needs a way out.
    root.innerHTML = "";
    var nav = el("nav", "crumbs");
    nav.id = "crumbs";
    nav.setAttribute("aria-label", "Breadcrumb");
    root.appendChild(nav);
    renderCrumbs([crumbHome(), crumbCatalogue()]);

    root.appendChild(buildEmpty(
      "Product not found",
      "That product is no longer listed, or the link is incomplete.",
      { label: "Browse the catalogue", href: "products.html" },
      "h1"
    ));
    document.title = "Product not found | Kapriol Bulgaria";
  }

  function setSeo(p) {
    var origin = location.origin + location.pathname;
    var url = origin + "?p=" + encodeURIComponent(p.slug);
    function upsert(sel, make, set) {
      var tag = document.head.querySelector(sel);
      if (!tag) { tag = make(); document.head.appendChild(tag); }
      set(tag);
    }
    upsert('link[rel="canonical"]',
      function () { var l = document.createElement("link"); l.rel = "canonical"; return l; },
      function (l) { l.href = url; });
    upsert('meta[name="description"]',
      function () { var m = document.createElement("meta"); m.name = "description"; return m; },
      function (m) { m.content = (p.description || "").slice(0, 300); });
    [["og:title", p.name], ["og:description", (p.description || "").slice(0, 300)], ["og:url", url]]
      .forEach(function (pair) {
        upsert('meta[property="' + pair[0] + '"]',
          function () { var m = document.createElement("meta"); m.setAttribute("property", pair[0]); return m; },
          function (m) { m.content = pair[1] || ""; });
      });
    var photo = galleryOf(p)[0];
    if (photo) {
      upsert('meta[property="og:image"]',
        function () { var m = document.createElement("meta"); m.setAttribute("property", "og:image"); return m; },
        function (m) { m.content = new URL(photo, location.href).href; });
    }
  }

  function renderProduct(root, p, items) {
    document.title = p.name + " | Kapriol Bulgaria";
    setSeo(p);

    /* breadcrumb: Home / Catalogue / Range / Product */
    renderCrumbs([
      crumbHome(),
      crumbCatalogue(),
      crumbCategory(p.category),
      { label: p.name, href: "product.html?p=" + encodeURIComponent(p.slug) }
    ]);

    /* gallery */
    var shots = galleryOf(p);
    var media = $("#pdMedia");
    var thumbs = $("#pdThumbs");
    if (media) {
      media.innerHTML = "";
      if (shots.length) {
        var main = new Image();
        main.alt = p.name;
        main.decoding = "async";
        main.id = "pdMainImg";
        main.fetchPriority = "high";     // it is the LCP element on this page
        main.src = shots[0];
        media.appendChild(main);
      } else {
        var ph = el("span", "card__ph", "KAPRIOL");
        ph.setAttribute("aria-hidden", "true");
        media.appendChild(ph);
      }
    }
    if (thumbs) {
      thumbs.innerHTML = "";
      if (shots.length > 1) {
        shots.forEach(function (src, i) {
          var b = el("button", "pd__thumb");
          b.type = "button";
          b.setAttribute("aria-label", "View photo " + (i + 1) + " of " + shots.length);
          b.setAttribute("aria-current", i === 0 ? "true" : "false");
          var im = new Image();
          im.src = src; im.alt = ""; im.loading = "lazy"; im.decoding = "async";
          b.appendChild(im);
          b.addEventListener("click", function () {
            var mainImg = $("#pdMainImg");
            if (mainImg && mainImg.getAttribute("src") !== src) {
              // Dip to a soft blur, swap on the decoded image, then come back.
              // Without the bridge the eye sees two photos trade places.
              media.classList.add("is-swapping");
              var next = new Image();
              next.onload = next.onerror = function () {
                mainImg.src = src;
                media.classList.remove("is-swapping");
              };
              next.src = src;
            }
            $$(".pd__thumb", thumbs).forEach(function (o) { o.setAttribute("aria-current", "false"); });
            b.setAttribute("aria-current", "true");
          });
          thumbs.appendChild(b);
        });
      } else {
        thumbs.hidden = true;
      }
    }

    /* headline block */
    var tag = $("#pdTag"); if (tag) { tag.textContent = catLabel(p.category); }
    var h1 = $("#pdName"); if (h1) { h1.textContent = p.name; }
    var desc = $("#pdDesc");
    if (desc) {
      if (p.description) { setMultiline("#pdDesc", p.description); }
      else { desc.hidden = true; }
    }

    /* badges: real certification data, not a fixed list */
    var badges = $("#pdBadges");
    if (badges) {
      badges.innerHTML = "";
      var marking = rawSpec(p.safetyClass);
      if (marking) { badges.appendChild(el("span", "badge badge--fill", marking)); }
      (Array.isArray(p.standards) ? p.standards : []).forEach(function (s) {
        badges.appendChild(el("span", "badge", s));
      });
      if (!badges.children.length) { badges.hidden = true; }
    }

    /* spec rows: only fields the product actually carries */
    var specs = $("#pdSpecs");
    if (specs) {
      specs.innerHTML = "";
      function row(label, value) {
        if (!value) { return; }
        var r = el("div", "specs__row");
        r.appendChild(el("span", "specs__label", label));
        r.appendChild(el("span", "specs__value", value));
        specs.appendChild(r);
      }
      function list(v) { return Array.isArray(v) && v.length ? v.join(", ") : ""; }
      row("Range", p.productLine);
      row("Material", p.material || p.materialComposition);
      row("Sole", p.sole);
      row("Toe cap", p.toeCap);
      row("Sizes", list(p.sizes));
      row("Colours", list(p.colors));
      row("Fit", p.fit);
      row("Weight", p.weightGrams ? p.weightGrams + " g" : "");
      row("Standards", list(p.standards));
      if (!specs.children.length) { specs.hidden = true; }
    }

    /* contact CTA carries the product so the enquiry arrives with context */
    var cta = $("#pdCta");
    if (cta) { cta.href = "contact.html?p=" + encodeURIComponent(p.slug); }

    /* long-form copy */
    var bodyBlock = $("#pdBody");
    var prose = $("#pdProse");
    if (prose) {
      prose.innerHTML = "";
      if (p.fullDescription) {
        prose.appendChild(sanitizeHtml(p.fullDescription));
      } else if (Array.isArray(p.overview) && p.overview.length) {
        p.overview.forEach(function (t) { prose.appendChild(el("p", null, t)); });
      } else if (Array.isArray(p.features) && p.features.length) {
        var ul = el("ul");
        p.features.forEach(function (f) { ul.appendChild(el("li", null, f)); });
        prose.appendChild(ul);
      } else if (bodyBlock) {
        bodyBlock.hidden = true;
      }
    }

    renderSpecGroups(p);
    renderCodes(p);
    renderRelated(p, items);
  }

  /* What a code has to SAY, beyond the code itself. The admin's Codes tab
     authors `title` + `description`; older entries carry only `name`. A code
     with nothing but its own letters has nothing to disclose, so it stays a
     plain label rather than pretending to be a control. */
  function specHeading(d, code) {
    var t = (d && (d.title || d.name) ? String(d.title || d.name) : "").trim();
    return t && t.toUpperCase() !== String(code).toUpperCase() ? t : "";
  }
  function specBody(d) {
    return (d && d.description ? String(d.description) : "").trim();
  }
  function hasDetail(d, code) {
    return !!(specHeading(d, code) || specBody(d));
  }

  var specPanelSeq = 0;

  function renderSpecGroups(p) {
    var block = $("#pdPicto");
    if (!block) { return; }
    block.innerHTML = "";
    var any = false;

    SPEC_GROUPS.forEach(function (g) {
      var codes = toCodes(p[g.key]).sort(byCode);
      if (!codes.length) { return; }
      any = true;
      var defs = specDefs(g.key);
      var box = el("div", "pgroup");
      box.appendChild(el("div", "pgroup__label", g.label));

      /* One disclosure panel per group, fed by whichever code is open. A
         panel per code would push the pictograms apart every time one opened;
         this keeps the row of badges intact and puts the text under it. */
      var panelId = "specDetail" + (++specPanelSeq);
      var panel = el("div", "pgroup__detail");
      panel.id = panelId;
      panel.setAttribute("data-open", "false");
      var panelInner = el("div");
      var card = el("div", "spec-detail");
      panelInner.appendChild(card);
      panel.appendChild(panelInner);

      var items = el("div", "pgroup__items");
      var buttons = [];

      function close() {
        panel.setAttribute("data-open", "false");
        buttons.forEach(function (b) { b.setAttribute("aria-expanded", "false"); });
      }

      codes.forEach(function (c) {
        var d = defs[c];
        var heading = specHeading(d, c);
        var body = specBody(d);
        var img = d && d.img ? BASE + d.img : "";
        var can = hasDetail(d, c);

        // A pictogram with no caption and no affordance was the whole problem:
        // it looked like decoration, so nobody knew the text was there.
        var node = el(can ? "button" : "span", (img ? "picto" : "chip") + (can ? " spec-open" : ""));
        if (can) {
          node.type = "button";
          node.setAttribute("aria-expanded", "false");
          node.setAttribute("aria-controls", panelId);
          buttons.push(node);
        }

        if (img) {
          var im = new Image();
          im.src = img;
          im.alt = heading || c;
          im.loading = "lazy";
          im.decoding = "async";
          node.appendChild(im);
          // The code is the caption a pictogram never had. Without it the
          // badge is unreadable to anyone who does not already know the icon.
          node.appendChild(el("span", "picto__code", c));
        } else {
          node.appendChild(el("span", "chip__code", c));
        }
        if (can) {
          node.appendChild(svgIcon("spec-open__i", "M12 16v-5M12 8h.01"));
          node.title = heading || c;
          node.addEventListener("click", function () {
            var open = node.getAttribute("aria-expanded") === "true";
            if (open) { close(); return; }
            close();
            card.innerHTML = "";
            card.appendChild(el("span", "spec-detail__code", c));
            if (heading) { card.appendChild(el("p", "spec-detail__title", heading)); }
            if (body) { card.appendChild(el("p", "spec-detail__text", body)); }
            panel.setAttribute("data-open", "true");
            node.setAttribute("aria-expanded", "true");
          });
        } else if (heading) {
          node.title = heading;
        }
        items.appendChild(node);
      });

      box.appendChild(items);
      box.appendChild(panel);
      block.appendChild(box);
    });

    var section = block.closest("section");
    if (section) { section.hidden = !any; }
  }

  function renderCodes(p) {
    var wrap = $("#pdCodes");
    if (!wrap) { return; }
    var rows = Array.isArray(p.articleCodes) ? p.articleCodes.filter(function (r) { return r && r.code; }) : [];
    if (!rows.length) { wrap.hidden = true; return; }
    wrap.hidden = false;

    var hasSize = rows.some(function (r) { return r.size; });
    var html = '<table class="codes"><thead><tr><th scope="col">Article code</th>' +
      (hasSize ? '<th scope="col">Size</th>' : "") + "</tr></thead><tbody>";
    rows.forEach(function (r) {
      html += "<tr><td>" + esc(r.code) + "</td>" + (hasSize ? "<td>" + esc(r.size || "") + "</td>" : "") + "</tr>";
    });
    html += "</tbody></table>";
    $(".codes__wrap", wrap).innerHTML = html;
  }

  function renderRelated(p, items) {
    var root = $("#pdRelated");
    if (!root) { return; }
    var rel = items.filter(function (x) {
      return x.category === p.category && x.slug !== p.slug;
    }).slice(0, 4);
    if (!rel.length) {
      var sec = root.closest("section");
      if (sec) { sec.hidden = true; }
      return;
    }
    root.innerHTML = "";
    rel.forEach(function (x, i) { root.appendChild(buildCard(x, i)); });
  }

  /* --------------------------------------------------------------- Contact -- */
  function applyContact() {
    loadContent(function (c) {
      var copy = (c && c.copy) || {};
      var vis = (c && c.visible) || {};

      function setRow(id, value, visible, hrefPrefix) {
        var row = document.getElementById(id);
        if (!row) { return; }
        var clean = String(value || "").trim();
        var isPlaceholder = /PLACEHOLDER/i.test(clean);
        if (!clean || isPlaceholder || visible === false) { row.hidden = true; return; }
        row.hidden = false;
        var val = $(".inforow__value", row);
        if (!val) { return; }
        if (hrefPrefix) {
          var a = el("a", null, clean);
          a.href = hrefPrefix + clean.replace(/\s+/g, "");
          val.innerHTML = "";
          val.appendChild(a);
        } else {
          val.textContent = clean;
        }
      }

      setRow("info-phone", copy.contactPhone, vis.contactPhone, "tel:");
      setRow("info-email", copy.contactEmail || "info@kapriol.ergotrade.bg", vis.contactEmail, "mailto:");
      setRow("info-addr", copy.contactAddr, vis.contactAddr, null);

      // Both footer lines are admin-editable.
      var fc = $("#footCopy");
      if (fc && copy.footerCopy) { fc.textContent = copy.footerCopy; }
      var fp = $("#footPowered");
      if (fp && copy.footerPowered) { fp.textContent = copy.footerPowered; }
    });
  }

  function setupForm() {
    var form = $("#contactForm");
    if (!form) { return; }
    var status = $("#formStatus");
    var success = $("#formSuccess");

    // A product enquiry arrives pre-filled with the product it came from.
    var slug = param("p");
    if (slug) {
      loadProducts(function (items) {
        var p = items.find(function (x) { return x.slug === slug; });
        var msg = $("#cf-message");
        if (p && msg && !msg.value) {
          msg.value = "I would like more information about: " + p.name + " (" + p.slug + ").\n\n";
        }
      });
    }

    function fieldError(input, on, text) {
      input.setAttribute("aria-invalid", on ? "true" : "false");
      var err = document.getElementById(input.id + "-err");
      if (err) {
        err.hidden = !on;
        if (on && text) { err.textContent = text; }
      }
    }

    $$("input, textarea", form).forEach(function (input) {
      input.addEventListener("input", function () {
        if (input.getAttribute("aria-invalid") === "true") { fieldError(input, false); }
      });
    });

    function shake(node) {
      node.classList.remove("is-invalid");
      void node.offsetWidth;
      node.classList.add("is-invalid");
      setTimeout(function () { node.classList.remove("is-invalid"); }, 400);
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (status) { status.textContent = ""; status.classList.remove("is-error"); }

      var required = [
        { id: "cf-name", msg: "Please enter your name." },
        { id: "cf-email", msg: "Please enter a valid email address." },
        { id: "cf-phone", msg: "Please enter a phone number." },
        { id: "cf-message", msg: "Please tell us what you need." }
      ];
      var firstBad = null;
      required.forEach(function (r) {
        var input = document.getElementById(r.id);
        if (!input) { return; }
        var v = input.value.trim();
        var bad = !v || (input.type === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v));
        fieldError(input, bad, r.msg);
        if (bad && !firstBad) { firstBad = input; }
      });
      if (firstBad) {
        shake(form);
        firstBad.focus();
        return;
      }

      // send-mail.php has no "company" parameter, so the company line is
      // folded into the message body rather than silently dropped.
      var company = ($("#cf-company") && $("#cf-company").value.trim()) || "";
      var message = $("#cf-message").value.trim();
      if (company) { message = "Company: " + company + "\n\n" + message; }

      var payload = {
        name: $("#cf-name").value.trim(),
        email: $("#cf-email").value.trim(),
        phone: $("#cf-phone").value.trim(),
        message: message,
        website: ($("[name=website]", form) || {}).value || ""
      };

      form.classList.add("is-sending");
      var btn = $("button[type=submit]", form);
      var spin = null;
      if (btn) {
        btn.disabled = true;
        spin = el("span", "btn__spin");
        spin.setAttribute("aria-hidden", "true");
        btn.appendChild(spin);
      }
      if (status) { status.textContent = "Sending..."; }

      function settle() {
        form.classList.remove("is-sending");
        if (spin && spin.parentNode) { spin.parentNode.removeChild(spin); }
        if (btn) { btn.disabled = false; }
      }

      fetch(BASE + "send-mail.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().catch(function () { return { success: r.ok }; }); })
        .then(function (res) {
          settle();
          if (res && res.success) {
            if (success) {
              // Let the form leave before the confirmation arrives. The exit is
              // deliberately faster than the entrance.
              form.classList.add("is-leaving");
              setTimeout(function () {
                form.hidden = true;
                form.classList.remove("is-leaving");
                success.hidden = false;
                success.focus();
              }, 180);
            } else if (status) {
              status.textContent = "Thank you. We will reply within one business day.";
            }
          } else {
            if (status) {
              status.textContent = (res && res.error) || "Something went wrong. Please try again.";
              status.classList.add("is-error");
            }
            shake(form);
          }
        })
        .catch(function () {
          settle();
          if (status) {
            status.textContent = "Network error. Please email info@kapriol.ergotrade.bg directly.";
            status.classList.add("is-error");
          }
        });
    });
  }

  /* -------------------------------------------------------------- Analytics -- */
  /* Cookieless aggregate beacon, once per browser session. test1 pages use a
     "t1-" prefix so this build's traffic stays separate from the live site's. */
  function beacon(page, slug) {
    try {
      var key = "t1:seen";
      var fresh = !sessionStorage.getItem(key);
      if (fresh) { sessionStorage.setItem(key, "1"); }
      var body = JSON.stringify({ page: page, s: fresh ? 1 : 0, slug: slug || "" });
      if (navigator.sendBeacon) {
        navigator.sendBeacon(BASE + "track.php", new Blob([body], { type: "application/json" }));
      }
    } catch (e) { /* analytics must never break a page */ }
  }

  /* -------------------------------------------------------------------- Go -- */
  function init() {
    setupNav();
    setupReveals();
    guardHeroEntrance();
    applyHome();          // owns setupRotator(): the slide count comes from content.json

    initHome();
    initCatalog();
    initProduct();

    applyContact();
    setupForm();

    applyStaticCascades();   // hero/why/catalogue titles are split where rendered

    var page = document.body.getAttribute("data-page") || "other";
    if (page !== "product") { beacon("t1-" + page); }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
