/* ============================================================================
   Shared front-end runtime — ONE IIFE for every page.
   Each init step is GUARDED: it no-ops when its root element is absent, so this
   single file serves every page with no per-page branching.
   BASE = "" reads data/img at the site root.
   ========================================================================== */
(function () {
  "use strict";

  var BASE = "";

  /* -------------------------------------------------- config (edit these) -- */
  var CATEGORIES = [
    // { id: "cat-a", label: "Category A" }, ...
  ];

  /* --------------------------------------------------------------- state -- */
  var ITEMS = [], itemsLoadFailed = false, CONTENT = null, ATTRS = null;

  /* --------------------------------------------------------------- utils -- */
  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function esc(s) { return String(s == null ? "" : s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;"); }
  function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
  function reducedMotion() { try { return window.matchMedia("(prefers-reduced-motion: reduce)").matches; } catch (e) { return false; } }

  /* Insert admin-authored multi-line text as TEXT NODES (\n -> <br>), so a line
     break survives without opening a markup-injection hole. */
  function setMultiline(node, str) {
    if (!node) return;
    node.textContent = "";
    String(str == null ? "" : str).split("\n").forEach(function (line, i) {
      if (i) node.appendChild(document.createElement("br"));
      node.appendChild(document.createTextNode(line));
    });
  }

  /* ------------------------------------------------- per-visit JSON cache -- */
  var inFlight = {};
  function cachedJSON(url, cb) {
    var key = "app:" + url;
    try { var hit = sessionStorage.getItem(key); if (hit) { cb(JSON.parse(hit)); return; } } catch (e) {}
    if (inFlight[url]) { inFlight[url].push(cb); return; }
    inFlight[url] = [cb];
    function d(data) { var w = inFlight[url] || []; delete inFlight[url]; w.forEach(function (fn) { fn(data); }); }
    fetch(url, { credentials: "same-origin" })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (data) { try { sessionStorage.setItem(key, JSON.stringify(data)); } catch (e) {} d(data); })
      .catch(function () { d(null); });
  }

  /* ------------------------------------------------------------- loaders -- */
  function loadItems(cb) {
    if (ITEMS.length) { cb(ITEMS); return; }
    cachedJSON(BASE + "assets/data/items.json", function (data) {
      itemsLoadFailed = !Array.isArray(data);
      ITEMS = (Array.isArray(data) ? data : []).filter(function (p) { return p && !p.hidden; });
      cb(ITEMS);
    });
  }
  function loadContent(cb) {
    if (CONTENT) { cb(CONTENT); return; }
    cachedJSON(BASE + "assets/data/content.json", function (data) { CONTENT = (data && typeof data === "object") ? data : {}; cb(CONTENT); });
  }
  function loadAttributes(cb) {
    if (ATTRS) { cb(ATTRS); return; }
    cachedJSON(BASE + "assets/data/attributes.json", function (data) { ATTRS = (data && typeof data === "object") ? data : {}; cb(ATTRS); });
  }

  /* --------------------------------------------------- guarded renderers -- */
  /* Each returns early when its root element is absent. Fill these in per site. */

  function applyContent() {
    // Merge content.json over the copy already in the HTML; blank fields fall
    // back to the HTML default (skip empty strings). Re-sync tel:/mailto: hrefs.
  }

  function renderCatalog() {
    var root = document.getElementById("catalog");
    if (!root) return;
    loadItems(function (items) { /* build cards; honour ?cat= / ?q= */ });
  }

  function renderItem() {
    var root = document.getElementById("item");
    if (!root) return;
    var slug = new URLSearchParams(location.search).get("p") || new URLSearchParams(location.search).get("slug");
    loadItems(function (items) {
      var it = items.filter(function (p) { return p.slug === slug; })[0];
      if (!it) { /* honest not-found state with a real <h1> */ return; }
      /* render detail; set per-item <title>/canonical/og/meta at runtime */
    });
  }

  function setupForm() {
    var form = document.getElementById("contactForm");
    if (!form) return;
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var payload = { name: form.name.value, email: form.email.value, phone: form.phone.value, message: form.message.value, website: form.website ? form.website.value : "" };
      fetch(BASE + "send-mail.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) })
        .then(function (r) { return r.json(); })
        .then(function (res) { /* success/error UI */ })
        .catch(function () { /* network error UI */ });
    });
  }

  /* Cookieless analytics beacon: once-per-session "visit" flag via sessionStorage. */
  function track(page, slug) {
    try {
      var fresh = !sessionStorage.getItem("app:visited");
      if (fresh) sessionStorage.setItem("app:visited", "1");
      var body = JSON.stringify({ page: page, s: fresh, slug: slug || "" });
      if (navigator.sendBeacon) navigator.sendBeacon(BASE + "track.php", body);
      else fetch(BASE + "track.php", { method: "POST", body: body, keepalive: true });
    } catch (e) {}
  }

  /* ---------------------------------------------------------------- init -- */
  function init() {
    applyContent();
    renderCatalog();
    renderItem();
    setupForm();
    // track(document.body.getAttribute("data-page") || "other");
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
