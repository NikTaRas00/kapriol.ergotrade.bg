/* =============================================================================
   KAPRIOL.BG — admin.js
   Drives the admin dashboard: tab switching, product editor, enquiries table,
   and the site-content form. Talks to the PHP endpoints under admin/api/.
   All writes send the CSRF token from <meta name="csrf">.
   ========================================================================== */
(function () {
  "use strict";

  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || "";

  /* ----------------------------------------------------------- helpers -- */
  function $(s, c) { return (c || document).querySelector(s); }
  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
    });
  }
  function status(node, msg, kind) {
    node.textContent = msg || "";
    node.classList.remove("is-error", "is-ok");
    if (kind) node.classList.add(kind === "error" ? "is-error" : "is-ok");
  }

  function getJSON(url) {
    return fetch(url, { credentials: "same-origin", headers: { "Accept": "application/json" } })
      .then(function (r) {
        if (r.status === 401) { location.href = "login.php"; throw new Error("auth"); }
        return r.json().catch(function () { throw new Error("Bad response (is PHP running?)"); });
      });
  }
  function postJSON(url, body) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF },
      body: JSON.stringify(body)
    }).then(function (r) {
      if (r.status === 401) { location.href = "login.php"; throw new Error("auth"); }
      return r.json().catch(function () { throw new Error("Bad response (is PHP running?)"); });
    });
  }

  function slugifyCode(s) {
    return String(s == null ? "" : s).toLowerCase()
      .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
  }

  /* Sort spec codes alphabetically (case-insensitive, numeric-aware so S2 < S10).
     Accepts either a {code} object or a raw code string. */
  function codeOf(x) { return String(x && x.code != null ? x.code : x); }
  function byCode(a, b) { return codeOf(a).localeCompare(codeOf(b), undefined, { numeric: true, sensitivity: "base" }); }

  // Stored image paths are root-relative ("assets/img/…") for the public site.
  // This admin lives at test1/admin/, two levels below the web root, so the
  // prefix is "../../" rather than the main admin's "../".
  function adminImgSrc(path) {
    var p = String(path == null ? "" : path);
    if (!p) return p;
    if (/^(https?:|data:|\/|\.\.\/)/.test(p)) return p;
    return "../../" + p;
  }

  /* ---------------------------------------- full-description conversion --
     The product page shows `fullDescription` (whitelisted HTML). The editor is
     a plain "simple rich text" box: blank line = new paragraph, a line starting
     "- " = a bullet, "## " = a small heading, **text** = bold. These two
     functions convert between that friendly text and the stored HTML, so the
     secretary never has to touch tags and existing rich descriptions round-trip. */
  function inlineFromNode(node) {          // element/text → editable text (with **bold**)
    var out = "";
    var kids = node.childNodes;
    for (var i = 0; i < kids.length; i++) {
      var c = kids[i];
      if (c.nodeType === 3) { out += c.nodeValue; }
      else if (c.nodeType === 1) {
        var tag = c.tagName;
        if (tag === "STRONG" || tag === "B") out += "**" + inlineFromNode(c) + "**";
        else if (tag === "BR") out += " ";
        else out += inlineFromNode(c);
      }
    }
    return out;
  }
  function norm(s) { return String(s == null ? "" : s).replace(/\s+/g, " ").trim(); }

  function htmlToSimpleText(html) {
    if (!html) return "";
    var tpl = document.createElement("template");
    tpl.innerHTML = String(html);
    var blocks = [];
    var kids = tpl.content.childNodes;
    for (var i = 0; i < kids.length; i++) {
      var n = kids[i];
      if (n.nodeType === 3) { var t = norm(n.nodeValue); if (t) blocks.push(t); continue; }
      if (n.nodeType !== 1) continue;
      var tag = n.tagName;
      if (tag === "UL" || tag === "OL") {
        var lines = [];
        for (var j = 0; j < n.children.length; j++) {
          if (n.children[j].tagName === "LI") {
            var s = norm(inlineFromNode(n.children[j]));
            if (s) lines.push("- " + s);
          }
        }
        if (lines.length) blocks.push(lines.join("\n"));
      } else if (tag === "H3" || tag === "H4" || tag === "H5") {
        var h = norm(inlineFromNode(n)); if (h) blocks.push("## " + h);
      } else {
        var p = norm(inlineFromNode(n)); if (p) blocks.push(p);
      }
    }
    return blocks.join("\n\n");
  }

  function inlineToHtml(s) {              // editable text → safe inline HTML
    return esc(s).replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
  }
  function simpleTextToHtml(text) {
    text = String(text == null ? "" : text).replace(/\r\n?/g, "\n").trim();
    if (!text) return "";
    return text.split(/\n{2,}/).map(function (block) {
      var lines = block.split("\n").map(function (l) { return l.trim(); })
                       .filter(function (l) { return l !== ""; });
      if (!lines.length) return "";
      if (lines.every(function (l) { return /^-\s+/.test(l); })) {
        return "<ul>" + lines.map(function (l) {
          return "<li>" + inlineToHtml(l.replace(/^-\s+/, "")) + "</li>";
        }).join("") + "</ul>";
      }
      if (lines.length === 1 && /^##\s+/.test(lines[0])) {
        return "<h3>" + inlineToHtml(lines[0].replace(/^##\s+/, "")) + "</h3>";
      }
      return "<p>" + inlineToHtml(lines.join(" ")) + "</p>";
    }).filter(Boolean).join("");
  }

  /* ----------------------------------------------- spec code definitions --
     The master list of Performance / Safety class / Technologies codes (each
     { code, name, img? }), shared by the Codes tab and the per-product pickers.
     Loaded once from attributes-get; ensureAttrs() guarantees it's ready. */
  var CODE_GROUPS = [
    { key: "performance",  label: "Performance",  pictos: true  },
    { key: "safetyClass",  label: "Safety class", pictos: false },
    { key: "technologies", label: "Technologies", pictos: true  }
  ];
  var attrDefs = { performance: [], safetyClass: [], technologies: [] };
  var attrLoaded = false;
  var attrLoading = null;

  function loadAttrDefs() {
    if (attrLoading) return attrLoading;
    attrLoading = getJSON("api/attributes-get.php").then(function (res) {
      var a = (res && res.attributes) || {};
      CODE_GROUPS.forEach(function (g) { attrDefs[g.key] = Array.isArray(a[g.key]) ? a[g.key] : []; });
      attrLoaded = true;
    }).catch(function () { attrLoaded = true; /* leave empty groups */ });
    return attrLoading;
  }
  function ensureAttrs(cb) {
    if (attrLoaded) { cb(); return; }
    loadAttrDefs().then(cb);
  }
  function findDef(groupKey, code) {
    var lc = String(code).toLowerCase();
    var defs = attrDefs[groupKey] || [];
    for (var i = 0; i < defs.length; i++) {
      if (defs[i] && String(defs[i].code).toLowerCase() === lc) return defs[i];
    }
    return null;
  }

  /* -------------------------------------------------------------- tabs -- */
  function setupTabs() {
    var tabs = document.querySelectorAll(".admin-tab");
    Array.prototype.forEach.call(tabs, function (tab) {
      tab.addEventListener("click", function () {
        var name = tab.getAttribute("data-tab");
        Array.prototype.forEach.call(tabs, function (t) {
          var on = t === tab;
          t.classList.toggle("is-active", on);
          t.setAttribute("aria-selected", on ? "true" : "false");
        });
        document.querySelectorAll(".admin-panel").forEach(function (p) {
          p.hidden = (p.id !== "tab-" + name);
        });
        if (name === "enquiries" && !enqLoaded) loadEnquiries();
        if (name === "analytics" && !anLoaded) loadAnalytics();
        if (name === "content" && !contentLoaded) loadContent();
        if (name === "codes" && !codesRendered) { codesRendered = true; ensureAttrs(renderCodes); }
        if (name === "pricing" && !priceLoaded) loadPricingState();
      });
    });
  }

  /* ===========================================================  PRODUCTS  */
  var products = [];
  var activeSlug = null;
  var activeCat = "";        // category filter ("" = all)
  var creatingNew = false;   // editor is in "add new product" mode

  // The six fixed categories (labels mirror the public site's COPY).
  var CATEGORIES = [
    { id: "footwear",             label: "Footwear & Safety Shoes" },
    { id: "footwear-accessories", label: "Footwear Accessories & Care" },
    { id: "clothing",             label: "Workwear & Professional Clothing" },
    { id: "clothing-accessories", label: "Clothing Accessories" },
    { id: "ppe",                  label: "PPE & Protection" },
    { id: "ppe-accessories",      label: "PPE Accessories" }
  ];
  function catLabel(id) {
    for (var i = 0; i < CATEGORIES.length; i++) if (CATEGORIES[i].id === id) return CATEGORIES[i].label;
    if (id === PLACEHOLDER_CAT) return "Unsorted (added from a price list)";
    return id;
  }

  // Hidden holding category for products the price-list upload creates (case 3).
  // Not one of the six public ranges: it only appears in this admin, as a
  // filter entry and as the "current" value of a product that still needs sorting.
  var PLACEHOLDER_CAT = "unsorted";

  // Field spec: which keys are editable and how. Everything else on a record
  // is passed through untouched by the server.
  var STR_ARRAYS = ["features", "sizes", "colors", "standards"];

  function setupProducts() {
    if (!$("#prodResults")) return;

    // Populate the category filter (one option per category).
    var filter = $("#prodCatFilter");
    if (filter) {
      CATEGORIES.forEach(function (c) {
        var o = document.createElement("option");
        o.value = c.id; o.textContent = c.label;
        filter.appendChild(o);
      });
      var uo = document.createElement("option");
      uo.value = PLACEHOLDER_CAT; uo.textContent = catLabel(PLACEHOLDER_CAT);
      filter.appendChild(uo);
      filter.addEventListener("change", function () { activeCat = filter.value; renderList(); });
    }

    getJSON("api/products-get.php").then(function (res) {
      products = (res && res.products) || [];
      renderList();
    }).catch(function (e) {
      $("#prodResults").innerHTML = '<p class="admin-empty">' + esc(e.message || "Could not load products.") + "</p>";
    });

    var search = $("#prodSearch");
    var t;
    search.addEventListener("input", function () {
      clearTimeout(t);
      t = setTimeout(function () { renderList(); }, 120);
    });
    var newBtn = $("#prodNew");
    if (newBtn) newBtn.addEventListener("click", openCreate);
    var hideAllBtn = $("#prodHideAll");
    if (hideAllBtn) {
      hideAllBtn.hidden = true;          // until the catalog has actually loaded
      hideAllBtn.addEventListener("click", hideAllProducts);
    }
    var delBtnEl = $("#prodDelete");
    if (delBtnEl) delBtnEl.addEventListener("click", deleteProduct);
    $("#prodForm").addEventListener("submit", saveProduct);
  }

  function matchProduct(p, q) {
    var hay = (p.name + " " + p.slug + " " + (p.productLine || "")).toLowerCase();
    if (Array.isArray(p.articleCodes)) {
      hay += " " + p.articleCodes.map(function (a) { return a && a.code; }).join(" ");
    }
    return q.split(/\s+/).every(function (term) { return !term || hay.indexOf(term) >= 0; });
  }

  // Filters by both the search box and the category dropdown (reads them live).
  function renderList() {
    var q = (($("#prodSearch") || {}).value || "").trim().toLowerCase();
    var matches = products.filter(function (p) {
      if (activeCat && p.category !== activeCat) return false;
      return q ? matchProduct(p, q) : true;
    });
    var root = $("#prodResults");
    root.innerHTML = "";
    $("#prodCount").textContent = matches.length + " of " + products.length;
    matches.slice(0, 300).forEach(function (p) {
      var b = el("button", "admin-item" + (p.slug === activeSlug ? " is-active" : "") + (p.hidden ? " is-hidden-prod" : ""));
      b.type = "button";
      // A hidden product has to be obvious in the list, or someone edits a
      // product for ten minutes wondering why the site never changes.
      b.innerHTML = '<span class="admin-item__name">' + esc(p.name) +
                    (p.hidden ? ' <span class="admin-flag">Hidden</span>' : "") + "</span>" +
                    '<span class="admin-item__sub">' + esc(p.category) + " · " + esc(p.slug) + "</span>";
      b.addEventListener("click", function () { selectProduct(p.slug); });
      root.appendChild(b);
    });
    if (!matches.length) root.innerHTML = '<p class="admin-empty">No products match.</p>';
    syncHideAll();
  }

  /* The bulk button reads the catalog's actual state rather than remembering
     what it last did: if every product is already hidden the only useful
     action is to put them all back. */
  function syncHideAll() {
    var btn = $("#prodHideAll");
    if (!btn) return;
    if (!products.length) { btn.hidden = true; return; }
    btn.hidden = false;
    var visible = products.filter(function (p) { return !p.hidden; }).length;
    btn.dataset.target = visible ? "hide" : "show";
    btn.textContent = visible ? "Hide all products" : "Show all products";
    btn.title = visible
      ? visible + " of " + products.length + " products are currently public"
      : "All " + products.length + " products are currently hidden";
  }

  function hideAllProducts() {
    var btn = $("#prodHideAll");
    var hide = btn.dataset.target !== "show";
    var n = products.filter(function (p) { return hide ? !p.hidden : p.hidden; }).length;
    if (!n) return;

    var msg = hide
      ? "Hide all " + products.length + " products from the public site?\n\n" +
        "The catalogue, search and the home page will show no products at all until you undo this. " +
        "Nothing is deleted — the same button puts them back."
      : "Make all " + products.length + " products public again?";
    if (!window.confirm(msg)) return;

    var st = $("#prodBulkStatus");
    btn.disabled = true;
    var label = btn.textContent;
    btn.textContent = hide ? "Hiding…" : "Showing…";
    postJSON("api/products-visibility.php", { hidden: hide }).then(function (res) {
      btn.disabled = false;
      if (res && res.success) {
        products.forEach(function (p) { p.hidden = hide; });
        // Keep an open editor honest about what just happened to it.
        var box = $("#f-hidden");
        if (box) box.checked = hide;
        renderList();
        if (st) status(st, res.changed + " product" + (res.changed === 1 ? "" : "s") +
          (hide ? " hidden from the public site." : " are public again."), "ok");
      } else {
        btn.textContent = label;
        if (st) status(st, (res && res.error) || "Bulk update failed.", "error");
      }
    }).catch(function (err) {
      btn.disabled = false;
      btn.textContent = label;
      if (st) status(st, err.message || "Bulk update failed.", "error");
    });
  }

  function findProduct(slug) {
    for (var i = 0; i < products.length; i++) if (products[i].slug === slug) return products[i];
    return null;
  }

  function selectProduct(slug) {
    activeSlug = slug;
    creatingNew = false;
    var p = findProduct(slug);
    if (!p) return;
    document.querySelectorAll(".admin-item").forEach(function (it) {
      it.classList.toggle("is-active", it.querySelector(".admin-item__sub").textContent.indexOf(slug) >= 0);
    });
    // Footwear forms need the master spec codes for the pickers; load once.
    ensureAttrs(function () { if (activeSlug === slug && !creatingNew) buildProductForm(p); });
    $("#prodEditorEmpty").hidden = true;
    $("#prodForm").hidden = false;
    var del = $("#prodDelete"); if (del) del.hidden = false;
    status($("#prodStatus"), "");
  }

  // Open the editor in "add new product" mode: a blank record with editable
  // slug + category. Spec pickers appear after the product exists (edit mode).
  function openCreate() {
    creatingNew = true;
    activeSlug = null;
    document.querySelectorAll(".admin-item.is-active").forEach(function (it) { it.classList.remove("is-active"); });
    buildProductForm({ slug: "", category: "footwear", name: "" });
    $("#prodEditorEmpty").hidden = true;
    $("#prodForm").hidden = false;
    var del = $("#prodDelete"); if (del) del.hidden = true;   // can't delete a product that doesn't exist yet
    status($("#prodStatus"), "");
    var f = $("#prodFields [name=slug]"); if (f) f.focus();
  }

  // ---- field builders ----
  function textField(key, label, value, multiline) {
    var wrap = el("div", "field");
    wrap.appendChild(el("label", null, label));
    var inp = multiline ? document.createElement("textarea") : document.createElement("input");
    if (multiline) inp.rows = 3; else inp.type = "text";
    inp.name = key;
    inp.value = value == null ? "" : value;
    wrap.appendChild(inp);
    return wrap;
  }

  // The main body text shown on the product page (stored as `fullDescription`
  // HTML). Editable as friendly "simple rich text". Falls back to a legacy
  // plain-text `overview` array so old products' copy shows up and migrates.
  function fullDescField(p) {
    var wrap = el("div", "field");
    wrap.appendChild(el("label", null, "Full description"));
    var ta = document.createElement("textarea");
    ta.name = "fullDescription";
    ta.rows = 10;
    ta.className = "admin-richtext";
    var val = htmlToSimpleText(p.fullDescription);
    if (!val && Array.isArray(p.overview) && p.overview.length) val = p.overview.join("\n\n");
    ta.value = val;
    wrap.appendChild(ta);
    wrap.appendChild(el("p", "admin-img__hint",
      "The main description shown on the product page. Leave a blank line between paragraphs. Start a line with “- ” for a bullet, “## ” for a small heading, and wrap **text** in double asterisks for bold."));
    return wrap;
  }

  // Repeatable rows of single strings (features, sizes…) or paragraphs.
  function rowsField(key, label, arr, multiline) {
    var wrap = el("div", "field");
    wrap.appendChild(el("label", null, label));
    var rows = el("div", "admin-rows");
    rows.setAttribute("data-rows", key);
    (arr && arr.length ? arr : []).forEach(function (v) { rows.appendChild(strRow(v, multiline)); });
    wrap.appendChild(rows);
    var add = el("button", "admin-rows__add", "+ Add");
    add.type = "button";
    add.addEventListener("click", function () { rows.appendChild(strRow("", multiline)); });
    wrap.appendChild(add);
    return wrap;
  }
  function strRow(v, multiline) {
    var row = el("div", "admin-rows__row");
    var inp = multiline ? document.createElement("textarea") : document.createElement("input");
    if (multiline) inp.rows = 2; else inp.type = "text";
    inp.value = v == null ? "" : v;
    inp.setAttribute("data-val", "1");
    row.appendChild(inp);
    row.appendChild(delBtn(row));
    return row;
  }
  // Repeatable {code, size} pairs (articleCodes).
  function pairField(key, label, arr) {
    var wrap = el("div", "field");
    wrap.appendChild(el("label", null, label));
    var rows = el("div", "admin-rows");
    rows.setAttribute("data-pairs", key);
    (arr && arr.length ? arr : []).forEach(function (o) { rows.appendChild(pairRow(o)); });
    wrap.appendChild(rows);
    var add = el("button", "admin-rows__add", "+ Add code");
    add.type = "button";
    add.addEventListener("click", function () { rows.appendChild(pairRow({})); });
    wrap.appendChild(add);
    return wrap;
  }
  function pairRow(o) {
    o = o || {};
    var row = el("div", "admin-rows__row admin-pair");
    var code = document.createElement("input"); code.type = "text"; code.placeholder = "Article code"; code.value = o.code == null ? "" : o.code; code.setAttribute("data-code", "1");
    var size = document.createElement("input"); size.type = "text"; size.placeholder = "Size"; size.value = o.size == null ? "" : o.size; size.setAttribute("data-size", "1");
    row.appendChild(code); row.appendChild(size); row.appendChild(delBtn(row));
    return row;
  }
  function delBtn(row) {
    var b = el("button", "admin-rows__del", "×");
    b.type = "button"; b.setAttribute("aria-label", "Remove");
    b.addEventListener("click", function () { row.parentNode.removeChild(row); });
    return b;
  }

  /* Product gallery: a product carries `images[]` (root-relative paths); index 0
     is the MAIN photo (mirrored to `img`, used by cards + social preview). The
     editor shows every photo in a row with per-tile controls — set as main,
     reorder (drag or arrows), remove — and an "Add photos" multi-picker. Every
     change persists immediately (merge-save img + images + imgV by slug). */
  var galleryState = [];      // array of paths; [0] = main
  var galleryRerender = null; // re-draw the current grid
  var galleryDragFrom = null; // drag source index
  var galleryVer = Date.now();// thumbnail cache-buster, bumped on every change

  function galleryField(p) {
    galleryState = (p.images && p.images.length) ? p.images.slice()
                 : (p.img ? [p.img] : []);
    galleryVer = Date.now();

    var wrap = el("div", "field");
    wrap.appendChild(el("label", null, "Product photos"));

    var box = el("div", "admin-gallery");
    var grid = el("div", "admin-gallery__grid");
    box.appendChild(grid);

    var st = el("p", "form-status admin-gallery__status");

    galleryRerender = function () { renderGalleryGrid(grid, st); };

    var controls = el("div", "admin-gallery__controls");
    var file = document.createElement("input");
    file.type = "file";
    file.accept = "image/jpeg,image/png,image/webp";
    file.multiple = true;
    file.className = "admin-img__file";
    controls.appendChild(file);
    controls.appendChild(el("p", "admin-img__hint",
      creatingNew
        ? "Save the product first, then add its photos here."
        : "JPG, PNG or WebP, up to 4 MB each. The first photo is the main one — drag to reorder, or use ★ to set the main."));
    controls.appendChild(st);
    box.appendChild(controls);

    file.addEventListener("change", function () {
      if (!file.files || !file.files.length) return;
      if (!activeSlug) { status(st, "Save the product first, then add photos.", "error"); file.value = ""; return; }
      var list = Array.prototype.slice.call(file.files);
      file.value = "";
      uploadGalleryImages(list, st);
    });

    wrap.appendChild(box);
    galleryRerender();
    return wrap;
  }

  function galleryMiniBtn(label, title, disabled) {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "admin-gallery__btn";
    b.textContent = label;
    b.title = title;
    b.setAttribute("aria-label", title);
    if (disabled) b.disabled = true;
    return b;
  }

  function renderGalleryGrid(grid, st) {
    grid.innerHTML = "";
    if (!galleryState.length) {
      grid.appendChild(el("p", "admin-gallery__empty", "No photos yet."));
      return;
    }
    galleryState.forEach(function (path, idx) {
      var tile = el("div", "admin-gallery__tile" + (idx === 0 ? " is-main" : ""));
      tile.setAttribute("draggable", "true");

      var thumb = el("div", "admin-gallery__thumb");
      var im = document.createElement("img");
      im.alt = "";
      im.src = adminImgSrc(path) + "?t=" + galleryVer;
      im.addEventListener("error", function () { thumb.classList.add("is-empty"); im.removeAttribute("src"); });
      thumb.appendChild(im);
      tile.appendChild(thumb);

      if (idx === 0) tile.appendChild(el("span", "admin-gallery__badge", "Main"));

      var actions = el("div", "admin-gallery__actions");
      var left = galleryMiniBtn("◀", "Move left", idx === 0);
      left.addEventListener("click", function () { moveGallery(idx, idx - 1, st); });
      var right = galleryMiniBtn("▶", "Move right", idx === galleryState.length - 1);
      right.addEventListener("click", function () { moveGallery(idx, idx + 1, st); });
      actions.appendChild(left);
      actions.appendChild(right);
      if (idx !== 0) {
        var star = galleryMiniBtn("★", "Set as main", false);
        star.addEventListener("click", function () { moveGallery(idx, 0, st); });
        actions.appendChild(star);
      }
      var del = galleryMiniBtn("×", "Remove photo", false);
      del.classList.add("admin-gallery__btn--del");
      del.addEventListener("click", function () { removeGallery(idx, st); });
      actions.appendChild(del);
      tile.appendChild(actions);

      tile.addEventListener("dragstart", function (e) {
        galleryDragFrom = idx; tile.classList.add("is-dragging");
        if (e.dataTransfer) e.dataTransfer.effectAllowed = "move";
      });
      tile.addEventListener("dragend", function () { tile.classList.remove("is-dragging"); });
      tile.addEventListener("dragover", function (e) { e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = "move"; tile.classList.add("is-over"); });
      tile.addEventListener("dragleave", function () { tile.classList.remove("is-over"); });
      tile.addEventListener("drop", function (e) {
        e.preventDefault(); tile.classList.remove("is-over");
        if (galleryDragFrom !== null && galleryDragFrom !== idx) moveGallery(galleryDragFrom, idx, st);
        galleryDragFrom = null;
      });

      grid.appendChild(tile);
    });
  }

  function moveGallery(from, to, st) {
    if (to < 0 || to >= galleryState.length || from === to) return;
    var item = galleryState.splice(from, 1)[0];
    galleryState.splice(to, 0, item);
    if (galleryRerender) galleryRerender();
    persistGallery(st);
  }

  function removeGallery(idx, st) {
    var path = galleryState[idx];
    if (!window.confirm("Remove this photo? This can’t be undone.")) return;
    galleryState.splice(idx, 1);
    if (galleryRerender) galleryRerender();
    persistGallery(st);
    // Best-effort file cleanup; the images[] array is the source of truth.
    postJSON("api/product-image-delete.php", { slug: activeSlug, path: path }).catch(function () {});
  }

  // Upload one or more files sequentially, appending each to the gallery.
  function uploadGalleryImages(list, st) {
    if (!activeSlug) return;
    status(st, "Uploading…", "ok");
    var i = 0;
    function next() {
      if (i >= list.length) { status(st, "Photos saved.", "ok"); return; }
      var f = list[i]; i++;
      var stem = activeSlug + "-" + Date.now().toString(36) + Math.floor(Math.random() * 1e6).toString(36);
      var fd = new FormData();
      fd.append("slug", activeSlug);
      fd.append("basename", stem);
      fd.append("image", f);
      fetch("api/product-image.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": CSRF },   // do NOT set Content-Type for FormData
        body: fd
      }).then(function (r) {
        if (r.status === 401) { location.href = "login.php"; throw new Error("auth"); }
        return r.json().catch(function () { throw new Error("Bad response (is PHP running?)"); });
      }).then(function (res) {
        if (!res || !res.success) { status(st, (res && res.error) || "Upload failed.", "error"); return; }
        galleryState.push(res.path);
        galleryVer = Date.now();
        if (galleryRerender) galleryRerender();
        persistGallery(null);   // persist as each lands; status shown at the end
        next();
      }).catch(function (e) { status(st, e.message || "Upload failed.", "error"); });
    }
    next();
  }

  // Merge-save the current gallery (img = main, images = full list) by slug.
  function persistGallery(st) {
    if (!activeSlug) return;
    galleryVer = Date.now();
    var payload = {
      slug: activeSlug,
      img: galleryState.length ? galleryState[0] : "",
      images: galleryState.slice(),
      imgV: galleryVer
    };
    postJSON("api/products-save.php", payload).then(function (sr) {
      if (sr && sr.success) {
        var local = findProduct(activeSlug);
        if (local) { local.img = payload.img; local.images = payload.images; local.imgV = payload.imgV; }
        renderList();
        if (st) status(st, "Photos saved.", "ok");
      } else if (st) {
        status(st, (sr && sr.error) || "Saving photos failed — click Save changes.", "error");
      }
    }).catch(function (e) { if (st) status(st, e.message || "Saving failed.", "error"); });
  }

  function buildProductForm(p) {
    var box = $("#prodFields");
    box.innerHTML = "";

    if (creatingNew) {
      $("#prodFormTitle").textContent = "New product";
      $("#prodFormMeta").innerHTML = "";
      // Editable slug (the permanent id) + category select — only at creation.
      var slugWrap = el("div", "field");
      slugWrap.appendChild(el("label", null, "Slug (permanent id)"));
      var slugInp = document.createElement("input");
      slugInp.type = "text"; slugInp.name = "slug"; slugInp.placeholder = "e.g. dune-rock-green";
      slugInp.autocomplete = "off";
      slugWrap.appendChild(slugInp);
      slugWrap.appendChild(el("p", "admin-img__hint", "Lowercase letters, numbers and hyphens. Used in the product URL — can't be changed later."));
      box.appendChild(slugWrap);

      var catWrap = el("div", "field");
      catWrap.appendChild(el("label", null, "Category"));
      var catSel = document.createElement("select");
      catSel.name = "category"; catSel.className = "admin-select admin-select--full";
      CATEGORIES.forEach(function (c) {
        var o = document.createElement("option");
        o.value = c.id; o.textContent = c.label;
        if (c.id === p.category) o.selected = true;
        catSel.appendChild(o);
      });
      catWrap.appendChild(catSel);
      box.appendChild(catWrap);

      // Auto-suggest a slug from the name until the user edits the slug field.
      slugInp.addEventListener("input", function () { slugInp.dataset.touched = "1"; });
    } else {
      $("#prodFormTitle").textContent = p.name;
      $("#prodFormMeta").innerHTML = 'Slug: <code>' + esc(p.slug) + "</code> <span class=\"admin-readonly\">(read-only)</span>";

      // The category can be changed after creation. A product still in the
      // placeholder range shows it as a disabled "current" choice, so saving
      // without picking a real range leaves it where it is.
      var ecWrap = el("div", "field");
      ecWrap.appendChild(el("label", null, "Category"));
      var ecSel = document.createElement("select");
      ecSel.name = "category"; ecSel.className = "admin-select admin-select--full";
      if (p.category === PLACEHOLDER_CAT) {
        var po = document.createElement("option");
        po.value = PLACEHOLDER_CAT; po.disabled = true; po.selected = true;
        po.textContent = catLabel(PLACEHOLDER_CAT) + " — choose a range";
        ecSel.appendChild(po);
      }
      CATEGORIES.forEach(function (c) {
        var o = document.createElement("option");
        o.value = c.id; o.textContent = c.label;
        if (c.id === p.category) o.selected = true;
        ecSel.appendChild(o);
      });
      ecWrap.appendChild(ecSel);
      if (p.category === PLACEHOLDER_CAT) {
        ecWrap.appendChild(el("p", "admin-img__hint", "This product was added from a price list and is hidden. Choose its range, fill in the details, then untick “Hide from the public site”."));
      }
      box.appendChild(ecWrap);
    }

    box.appendChild(textField("name", "Name", p.name));
    box.appendChild(textField("description", "Short description", p.description, true));
    box.appendChild(fullDescField(p));
    box.appendChild(textField("productLine", "Product line", p.productLine));
    box.appendChild(textField("material", "Material", p.material));
    box.appendChild(galleryField(p));

    var check = el("div", "admin-check");
    var cb = document.createElement("input"); cb.type = "checkbox"; cb.id = "f-isNew"; cb.name = "isNew"; cb.checked = !!p.isNew;
    var lb = el("label", null, "Mark as “New”"); lb.setAttribute("for", "f-isNew");
    check.appendChild(cb); check.appendChild(lb);
    box.appendChild(check);

    // Hide from the public site. The product keeps all of its data and stays
    // here in the admin; it just stops being served. Reversible at any time,
    // which is what makes it the safe alternative to deleting.
    var vis = el("div", "admin-check");
    var vcb = document.createElement("input");
    vcb.type = "checkbox"; vcb.id = "f-hidden"; vcb.name = "hidden"; vcb.checked = !!p.hidden;
    var vlb = el("label", null, "Hide from the public site");
    vlb.setAttribute("for", "f-hidden");
    vis.appendChild(vcb); vis.appendChild(vlb);
    vis.appendChild(el("p", "admin-hint", "Hidden products disappear from the catalogue, search, featured and related rows, and from the sitemap. Nothing is deleted."));
    box.appendChild(vis);

    box.appendChild(rowsField("features", "Key features", p.features, false));
    box.appendChild(rowsField("sizes", "Sizes", asArray(p.sizes), false));
    box.appendChild(rowsField("colors", "Colours", asArray(p.colors), false));
    box.appendChild(rowsField("standards", "Standards", asArray(p.standards), false));
    box.appendChild(pairField("articleCodes", "Article codes", p.articleCodes));

    // Spec codes (Performance / Safety class / Technologies): pick from the
    // master lists (managed on the Codes tab). Available for ANY category. Edit
    // mode only — in create mode the category can still change, so specs are
    // assigned after the product exists.
    if (!creatingNew) {
      var specs = el("div", "admin-specs");
      specs.appendChild(el("h3", "admin-specs__head", "Spec codes"));
      CODE_GROUPS.forEach(function (g) {
        specs.appendChild(specPickerField(g, p[g.key]));
      });
      box.appendChild(specs);
    }

    // In create mode, keep the slug in sync with the name until it's edited.
    if (creatingNew) {
      var nameInp = $("#prodFields [name=name]");
      var slugInp3 = $("#prodFields [name=slug]");
      if (nameInp && slugInp3) {
        nameInp.addEventListener("input", function () {
          if (slugInp3.dataset.touched) return;
          slugInp3.value = slugifyCode(nameInp.value);
        });
      }
    }
  }

  // A field that shows the codes assigned to the product (removable chips) plus
  // a "+" that opens a picker of the still-available codes for that group.
  function specPickerField(g, codes) {
    codes = Array.isArray(codes) ? codes.slice() : [];
    var wrap = el("div", "field admin-specpick");
    wrap.setAttribute("data-spec", g.key);
    wrap.appendChild(el("label", null, g.label));

    var chips = el("div", "admin-specpick__chips");
    var pop = el("div", "admin-specpick__pop"); pop.hidden = true;
    var add = el("button", "admin-specpick__add", "+");
    add.type = "button"; add.setAttribute("aria-label", "Add " + g.label + " code");

    function has(code) {
      return Array.prototype.some.call(chips.querySelectorAll("[data-chip]"), function (c) {
        return c.getAttribute("data-chip").toLowerCase() === String(code).toLowerCase();
      });
    }
    function addChip(code) {
      if (has(code)) return;
      var def = findDef(g.key, code);
      var chip = el("span", "admin-chip"); chip.setAttribute("data-chip", code);
      if (def && def.img) {
        var ic = document.createElement("img"); ic.className = "admin-chip__icon"; ic.src = adminImgSrc(def.img); ic.alt = "";
        chip.appendChild(ic);
      }
      chip.appendChild(el("span", "admin-chip__txt", code));
      var x = el("button", "admin-chip__x", "×"); x.type = "button"; x.setAttribute("aria-label", "Remove " + code);
      x.addEventListener("click", function () { chips.removeChild(chip); if (!pop.hidden) refreshPop(); });
      chip.appendChild(x);
      chips.appendChild(chip);
    }
    function refreshPop() {
      pop.innerHTML = "";
      var defs = (attrDefs[g.key] || []).slice().sort(byCode);
      var avail = defs.filter(function (d) { return d && d.code && !has(d.code); });
      if (!avail.length) {
        pop.appendChild(el("p", "admin-specpick__empty",
          defs.length ? "All codes added." : "No codes defined yet — add them on the Codes tab."));
        return;
      }
      avail.forEach(function (d) {
        var b = el("button", "admin-specpick__opt"); b.type = "button";
        if (d.img) { var ic = document.createElement("img"); ic.src = adminImgSrc(d.img); ic.alt = ""; b.appendChild(ic); }
        // `title` is the current field; `name` is the retired spelling kept as
        // a fallback for any record predating the rename. main.js:1327 does the
        // same. Without the `title` branch this picker showed bare codes.
        var label = d.title || d.name;
        b.appendChild(el("span", null, label ? (d.code + " — " + label) : d.code));
        b.addEventListener("click", function () { addChip(d.code); refreshPop(); });
        pop.appendChild(b);
      });
    }
    add.addEventListener("click", function () {
      if (pop.hidden) refreshPop();
      pop.hidden = !pop.hidden;
    });

    codes.slice().sort(byCode).forEach(addChip);
    var bar = el("div", "admin-specpick__bar");
    bar.appendChild(chips); bar.appendChild(add);
    wrap.appendChild(bar); wrap.appendChild(pop);
    return wrap;
  }

  function collectSpec(key) {
    var holder = document.querySelector('.admin-specpick[data-spec="' + key + '"]');
    if (!holder) return [];
    return Array.prototype.map.call(holder.querySelectorAll("[data-chip]"), function (c) {
      return c.getAttribute("data-chip");
    });
  }

  function asArray(v) {
    if (Array.isArray(v)) return v;
    if (v == null || v === "") return [];
    return [v];
  }

  function collectRows(key) {
    var box = document.querySelector('[data-rows="' + key + '"]');
    if (!box) return [];
    return Array.prototype.map.call(box.querySelectorAll("[data-val]"), function (i) { return i.value.trim(); })
      .filter(function (v) { return v !== ""; });
  }
  function collectPairs(key) {
    var box = document.querySelector('[data-pairs="' + key + '"]');
    if (!box) return [];
    return Array.prototype.map.call(box.querySelectorAll(".admin-rows__row"), function (row) {
      return { code: row.querySelector("[data-code]").value.trim(), size: row.querySelector("[data-size]").value.trim() };
    }).filter(function (o) { return o.code !== "" || o.size !== ""; });
  }

  // Common field collection from the editor form (shared by create + update).
  function collectProductFields() {
    var payload = {
      name: $("#prodFields [name=name]").value.trim(),
      description: $("#prodFields [name=description]").value.trim(),
      fullDescription: simpleTextToHtml($("#prodFields [name=fullDescription]").value),
      productLine: $("#prodFields [name=productLine]").value.trim(),
      material: $("#prodFields [name=material]").value.trim(),
      img: galleryState.length ? galleryState[0] : "",
      images: galleryState.slice(),
      isNew: $("#f-isNew").checked,
      hidden: $("#f-hidden").checked
    };
    STR_ARRAYS.forEach(function (k) { payload[k] = collectRows(k); });
    payload.articleCodes = collectPairs("articleCodes");
    return payload;
  }

  function saveProduct(e) {
    e.preventDefault();
    if (creatingNew) { createProduct(); return; }
    if (!activeSlug) return;
    var st = $("#prodStatus"), btn = $("#prodSave");
    var payload = collectProductFields();
    payload.slug = activeSlug;

    // Only send a category when the editor actually changed it.
    var catSel = $("#prodFields [name=category]");
    var cur = findProduct(activeSlug);
    if (catSel && cur && catSel.value && catSel.value !== cur.category) payload.category = catSel.value;

    // Spec codes (Performance / Safety class / Technologies) — sent for any
    // category in edit mode, where the pickers exist.
    payload.performance = collectSpec("performance");
    payload.safetyClass = collectSpec("safetyClass");
    payload.technologies = collectSpec("technologies");

    btn.disabled = true; btn.classList.add("is-sending"); status(st, "Saving…", "ok");
    postJSON("api/products-save.php", payload).then(function (res) {
      btn.disabled = false; btn.classList.remove("is-sending");
      if (res && res.success) {
        // reflect locally so the list/search stay in sync without a reload
        var local = findProduct(activeSlug);
        if (local) Object.keys(payload).forEach(function (k) { if (k !== "slug") local[k] = payload[k]; });
        renderList();
        status(st, "Saved.", "ok");
      } else {
        status(st, (res && res.error) || "Save failed.", "error");
      }
    }).catch(function (err) {
      btn.disabled = false; btn.classList.remove("is-sending");
      status(st, err.message || "Save failed.", "error");
    });
  }

  function createProduct() {
    var st = $("#prodStatus"), btn = $("#prodSave");
    var slug = ($("#prodFields [name=slug]").value || "").trim().toLowerCase();
    var category = $("#prodFields [name=category]").value;
    var payload = collectProductFields();
    payload.slug = slug;
    payload.category = category;

    if (!/^[a-z0-9][a-z0-9-]*$/.test(slug)) {
      status(st, "Slug must be lowercase letters, numbers and hyphens (e.g. dune-rock-green).", "error"); return;
    }
    if (findProduct(slug)) { status(st, "A product with that slug already exists.", "error"); return; }
    if (!payload.name) { status(st, "Name is required.", "error"); return; }

    btn.disabled = true; btn.classList.add("is-sending"); status(st, "Creating…", "ok");
    postJSON("api/product-create.php", payload).then(function (res) {
      btn.disabled = false; btn.classList.remove("is-sending");
      if (res && res.success) {
        products.push(res.product);
        creatingNew = false;
        renderList();
        selectProduct(slug);   // reopen in edit mode (spec pickers for footwear)
        status($("#prodStatus"), "Product created. Add a photo and specs below, then Save changes.", "ok");
      } else {
        status(st, (res && res.error) || "Create failed.", "error");
      }
    }).catch(function (err) {
      btn.disabled = false; btn.classList.remove("is-sending");
      status(st, err.message || "Create failed.", "error");
    });
  }

  function deleteProduct() {
    if (!activeSlug || creatingNew) return;
    var p = findProduct(activeSlug);
    var name = p ? p.name : activeSlug;
    if (!window.confirm('Delete "' + name + '" permanently? This removes it from the catalog and cannot be undone.')) return;
    var st = $("#prodStatus"), del = $("#prodDelete");
    var slug = activeSlug;
    del.disabled = true; status(st, "Deleting…", "ok");
    postJSON("api/product-delete.php", { slug: slug }).then(function (res) {
      del.disabled = false;
      if (res && res.success) {
        products = products.filter(function (x) { return x.slug !== slug; });
        activeSlug = null;
        $("#prodForm").hidden = true;
        $("#prodEditorEmpty").hidden = false;
        del.hidden = true;
        renderList();
      } else {
        status(st, (res && res.error) || "Delete failed.", "error");
      }
    }).catch(function (err) {
      del.disabled = false;
      status(st, err.message || "Delete failed.", "error");
    });
  }

  /* ===========================================================  PRICING  */
  /* "Upload all pricing": the upload applies case 1 (price updates) on the
     server and returns a plan. This tab then walks the admin through case 2
     (products missing from the file) and case 3 (products only in the file),
     one red box at a time, and ends with a written report. The server owns the
     plan; the browser only sends { token, action }. */
  var priceLoaded = false;
  var priceBusy = false;

  function setupPricing() {
    var btn = $("#priceUpload");
    if (btn) btn.addEventListener("click", uploadPricing);
  }

  // Reopening the tab (or reloading the page) mid-flow picks the plan back up.
  function loadPricingState() {
    priceLoaded = true;
    postJSON("api/pricing-apply.php", { action: "status" }).then(function (res) {
      if (res && res.success && res.plan) renderPricing(res.plan);
    }).catch(function () { /* nothing pending is not an error */ });
  }

  function fmtEur(n) { return Number(n).toFixed(2) + " €"; }

  function uploadPricing() {
    var inp = $("#priceFile"), st = $("#priceStatus"), btn = $("#priceUpload");
    if (priceBusy) return;
    if (!inp.files || !inp.files[0]) { status(st, "Choose a file first.", "error"); return; }

    var fd = new FormData();
    fd.append("file", inp.files[0]);
    priceBusy = true; btn.disabled = true;
    $("#priceResult").innerHTML = "";
    status(st, "Uploading and applying prices…", "ok");

    fetch("api/pricing-upload.php", {
      method: "POST", credentials: "same-origin",
      headers: { "X-CSRF-Token": CSRF }, body: fd
    }).then(function (r) {
      if (r.status === 401) { location.href = "login.php"; throw new Error("auth"); }
      return r.json().catch(function () { throw new Error("Bad response (is PHP running?)"); });
    }).then(function (res) {
      priceBusy = false; btn.disabled = false;
      if (res && res.success) {
        inp.value = "";
        status(st, "Prices from the file were applied. Read the notices below.", "ok");
        renderPricing(res.plan);
      } else {
        status(st, (res && res.error) || "Upload failed.", "error");
        if (res && res.skipped && res.skipped.length) {
          $("#priceResult").appendChild(skippedBox(res.skipped));
        }
      }
    }).catch(function (err) {
      priceBusy = false; btn.disabled = false;
      status(st, err.message || "Upload failed.", "error");
    });
  }

  function priceAction(plan, action) {
    if (priceBusy) return;
    var st = $("#priceStatus");
    priceBusy = true;
    Array.prototype.forEach.call(document.querySelectorAll("#priceResult button"), function (b) { b.disabled = true; });
    status(st, "Working…", "ok");
    postJSON("api/pricing-apply.php", { token: plan.token, action: action }).then(function (res) {
      priceBusy = false;
      if (res && res.plan) renderPricing(res.plan);   // also on a 409: show the real current step
      if (res && res.success) status(st, "Done.", "ok");
      else status(st, (res && res.error) || "That step failed.", "error");
    }).catch(function (err) {
      priceBusy = false;
      status(st, err.message || "That step failed.", "error");
      renderPricing(plan);   // buttons come back
    });
  }

  function priceNote(cls, html) {
    var d = el("div", "admin-alert " + cls);
    d.innerHTML = html;
    return d;
  }

  function slugList(items, withPrice) {
    var ul = el("ul", "admin-alert__list");
    items.forEach(function (it) {
      ul.appendChild(el("li", null, withPrice ? it.slug + " — " + fmtEur(it.price) : it));
    });
    return ul;
  }

  function skippedBox(skipped) {
    var box = el("div", "admin-alert");
    box.appendChild(el("p", null, skipped.length + " row" + (skipped.length === 1 ? " was" : "s were") +
      " skipped and changed nothing. Fix them in the file if they should be applied:"));
    var ul = el("ul", "admin-alert__list");
    skipped.forEach(function (s) {
      ul.appendChild(el("li", null, "row " + s.row + (s.slug ? " (" + s.slug + ")" : "") + " — " + s.reason));
    });
    box.appendChild(ul);
    return box;
  }

  function actionBtn(label, cls, onClick) {
    var b = el("button", "btn btn--sm " + cls, label);
    b.type = "button";
    b.addEventListener("click", onClick);
    return b;
  }

  function renderPricing(plan) {
    var root = $("#priceResult");
    root.innerHTML = "";
    var s = plan.stats;

    // Case 1 has already happened by the time any plan exists.
    var line = "<strong>" + esc(plan.file) + "</strong>: " + s.rows + " rows read, " + s.valid +
      " with a usable price. <strong>" + s.updated + "</strong> price" + (s.updated === 1 ? "" : "s") + " changed" +
      (s.unchanged ? ", " + s.unchanged + " already had that price" : "") +
      (s.restored ? ", " + s.restored + " unavailable product" + (s.restored === 1 ? "" : "s") + " came back with a price" : "") + ".";
    root.appendChild(priceNote("", line));

    if (plan.step === "case2") {
      var b2 = el("div", "admin-alert admin-alert--red");
      b2.appendChild(el("p", null, "the products with the following slugs were not found on the uploaded file")).style.fontWeight = "700";
      b2.appendChild(slugList(plan.case2, false));
      var a2 = el("div", "admin-alert__actions");
      a2.appendChild(actionBtn("delete these products", "btn--red", function () {
        if (!window.confirm("Delete " + plan.case2.length + " product" + (plan.case2.length === 1 ? "" : "s") +
          " permanently?\n\nTheir photos stay on the server and a backup of the products was saved, " +
          "but they disappear from the catalogue and the admin.")) return;
        priceAction(plan, "delete");
      }));
      a2.appendChild(actionBtn("keep these products on Data Base and Website, do not show a price number and show the banner ‘unavailable’ where their prices should sit", "btn--outline", function () {
        priceAction(plan, "unavailable");
      }));
      a2.appendChild(actionBtn("Nevermind, keep these products untouched", "btn--ghost", function () {
        priceAction(plan, "keep");
      }));
      b2.appendChild(a2);
      root.appendChild(b2);
    } else if (plan.step === "case3") {
      var b3 = el("div", "admin-alert admin-alert--red");
      b3.appendChild(el("p", null, "the products with the following slugs were not found on the Data Base")).style.fontWeight = "700";
      b3.appendChild(slugList(plan.case3, true));
      b3.appendChild(el("p", null, "the system can create these products and attribute them the given prices but they will have no pictures or text. " +
        "their title will be their slug. please go to the products' section of admin panel and add their informations if you choose to add these products to Data Base and Website"));
      b3.appendChild(el("p", "admin-hint", "They are created hidden, in the “" + catLabel(PLACEHOLDER_CAT) +
        "” category. In the Products tab, pick a range, fill in the details and untick “Hide from the public site” to publish each one."));
      var a3 = el("div", "admin-alert__actions");
      a3.appendChild(actionBtn("add these products to the Data Base and Website on given conditions", "btn--red", function () {
        priceAction(plan, "add");
      }));
      a3.appendChild(actionBtn("nevermind, do not add these products now", "btn--ghost", function () {
        priceAction(plan, "skip");
      }));
      b3.appendChild(a3);
      root.appendChild(b3);
    } else {
      root.appendChild(reportBox(plan));
    }

    // Skipped rows stay visible next to whichever step is showing.
    if (plan.skipped && plan.skipped.length && plan.step !== "done") root.appendChild(skippedBox(plan.skipped));
  }

  function reportBox(plan) {
    var s = plan.stats;
    var box = el("div", "admin-alert admin-alert--ok");
    box.appendChild(el("p", null, "Pricing report")).style.fontWeight = "700";
    var ul = el("ol", "admin-report");
    ul.appendChild(el("li", null, "Products whose price changed: " + s.updated));
    ul.appendChild(el("li", null, "Products deleted: " + s.deleted));
    ul.appendChild(el("li", null, "Products that became unavailable: " + s.unavailable));
    ul.appendChild(el("li", null, "Products added to the Data Base: " + s.added));
    box.appendChild(ul);

    var more = el("ul", "admin-report");
    more.appendChild(el("li", null, "Rows read from the file: " + s.rows + " (" + s.valid + " with a usable price, " +
      (plan.skipped ? plan.skipped.length : 0) + " skipped)."));
    if (s.unchanged) more.appendChild(el("li", null, s.unchanged + " product" + (s.unchanged === 1 ? "" : "s") + " already had the price in the file."));
    if (s.restored) more.appendChild(el("li", null, s.restored + " previously unavailable product" + (s.restored === 1 ? "" : "s") + " came back with a price."));
    if (s.case2Count) {
      more.appendChild(el("li", null, s.case2Count + " product" + (s.case2Count === 1 ? " was" : "s were") + " missing from the file: " +
        (s.case2Choice === "delete" ? "deleted." : s.case2Choice === "unavailable" ? "kept and marked unavailable." : "left untouched.")));
    }
    if (s.case3Count) {
      more.appendChild(el("li", null, s.case3Count + " product" + (s.case3Count === 1 ? " was" : "s were") + " only in the file: " +
        (s.case3Choice === "add"
          ? "added (hidden, in “" + catLabel(PLACEHOLDER_CAT) + "”)" + (s.addedExisted ? "; " + s.addedExisted + " already existed" : "") + "."
          : "not added.")));
    }
    box.appendChild(more);

    if (plan.skipped && plan.skipped.length) box.appendChild(skippedBox(plan.skipped));

    if (plan.backup) {
      var p = el("p", "admin-hint");
      p.appendChild(document.createTextNode("A backup of the previous prices (and of any products that could be deleted) was saved before the changes. "));
      var a = el("a", null, "Download backup");
      a.href = "api/pricing-backup.php?f=" + encodeURIComponent(plan.backup);
      p.appendChild(a);
      box.appendChild(p);
    }
    return box;
  }

  /* ==========================================================  ENQUIRIES  */
  var enqLoaded = false;
  function setupEnquiries() {
    var btn = $("#enqRefresh");
    if (btn) btn.addEventListener("click", loadEnquiries);
  }
  function loadEnquiries() {
    var wrap = $("#enqTableWrap");
    wrap.innerHTML = '<p class="admin-empty">Loading…</p>';
    getJSON("api/enquiries-list.php").then(function (res) {
      enqLoaded = true;
      var list = (res && res.enquiries) || [];
      $("#enqCount").textContent = list.length + (list.length === 1 ? " enquiry" : " enquiries");
      if (!list.length) { wrap.innerHTML = '<p class="admin-empty">No enquiries yet.</p>'; return; }
      var rows = list.map(function (e) {
        return "<tr>" +
          '<td class="admin-table__date">' + esc(fmtDate(e.ts)) + "</td>" +
          "<td>" + esc(e.name) + "</td>" +
          '<td><a href="mailto:' + esc(e.email) + '">' + esc(e.email) + "</a></td>" +
          '<td><a href="tel:' + esc(e.phone) + '">' + esc(e.phone) + "</a></td>" +
          '<td class="admin-msg">' + esc(e.message) + "</td>" +
        "</tr>";
      }).join("");
      wrap.innerHTML =
        '<table class="admin-table"><thead><tr>' +
        "<th>Date</th><th>Name</th><th>Email</th><th>Phone</th><th>Message</th>" +
        "</tr></thead><tbody>" + rows + "</tbody></table>";
    }).catch(function (e) {
      wrap.innerHTML = '<p class="admin-empty">' + esc(e.message || "Could not load enquiries.") + "</p>";
    });
  }
  function fmtDate(iso) {
    if (!iso) return "";
    var d = new Date(iso);
    if (isNaN(d)) return iso;
    return d.toLocaleString();
  }

  /* ==========================================================  ANALYTICS  */
  var anLoaded = false;
  var PAGE_LABELS = { home: "Home", products: "Products list", product: "Product detail", contact: "Contact", other: "Other" };
  function setupAnalytics() {
    var b = $("#anRefresh");
    if (b) b.addEventListener("click", loadAnalytics);
  }
  function loadAnalytics() {
    var box = $("#anBody");
    if (!box) return;
    box.innerHTML = '<p class="admin-empty">Loading…</p>';
    getJSON("api/analytics-get.php").then(function (res) {
      anLoaded = true;
      var a = (res && res.analytics) || {};
      // Resolve product slugs → names; ensureProducts is a no-op if loaded.
      ensureProducts(function () { renderAnalytics(a); });
    }).catch(function (e) {
      box.innerHTML = '<p class="admin-empty">' + esc(e.message || "Could not load analytics.") + "</p>";
    });
  }
  function anSorted(obj) {
    var arr = [];
    for (var k in obj) if (obj.hasOwnProperty(k)) arr.push([k, +obj[k] || 0]);
    arr.sort(function (a, b) { return b[1] - a[1]; });
    return arr;
  }
  function anLastDays(n) {
    var out = [], d = new Date(); d.setUTCHours(0, 0, 0, 0);
    for (var i = n - 1; i >= 0; i--) out.push(new Date(d.getTime() - i * 86400000).toISOString().slice(0, 10));
    return out;
  }
  function anKpi(label, n) {
    return '<div class="an-kpi"><span class="an-kpi__num">' + (Number(n || 0)).toLocaleString() +
           '</span><span class="an-kpi__label">' + esc(label) + "</span></div>";
  }
  function renderAnalytics(a) {
    var box = $("#anBody");
    var totals = a.totals || { views: 0, visits: 0 };
    var daily = a.daily || {};
    var todayKey = new Date().toISOString().slice(0, 10);   // UTC, matches track.php gmdate
    var tToday = daily[todayKey] || { views: 0, visits: 0 };

    if (!totals.views) {
      box.innerHTML = '<p class="admin-empty">No visits recorded yet. Stats will appear here once people browse the public site.</p>';
      return;
    }

    var kpis = '<div class="an-kpis">' +
      anKpi("Total views", totals.views) +
      anKpi("Total visits", totals.visits) +
      anKpi("Views today", tToday.views) +
      anKpi("Visits today", tToday.visits) + "</div>";

    var days = anLastDays(30), max = 1;
    days.forEach(function (k) { var v = (daily[k] && daily[k].views) || 0; if (v > max) max = v; });
    var bars = days.map(function (k) {
      var v = (daily[k] && daily[k].views) || 0;
      return '<div class="an-bar" title="' + esc(k + " — " + v + " views") + '">' +
             '<span class="an-bar__fill" style="height:' + Math.round(v / max * 100) + '%"></span></div>';
    }).join("");
    var chart = '<div class="an-block"><h3 class="an-h3">Page views — last 30 days</h3>' +
      '<div class="an-chart">' + bars + "</div>" +
      '<div class="an-axis"><span>' + esc(days[0]) + "</span><span>" + esc(days[days.length - 1]) + "</span></div></div>";

    var pageRows = anSorted(a.pages || {}).map(function (e) {
      return "<tr><td>" + esc(PAGE_LABELS[e[0]] || e[0]) + '</td><td class="an-num">' + e[1] + "</td></tr>";
    }).join("");
    var pagesTbl = pageRows ?
      '<div class="an-block"><h3 class="an-h3">Pages by views</h3><table class="admin-table"><thead><tr><th>Page</th><th class="an-num">Views</th></tr></thead><tbody>' +
      pageRows + "</tbody></table></div>" : "";

    var prodEntries = anSorted(a.products || {}).slice(0, 12);
    var prodRows = prodEntries.map(function (e) {
      var p = findProduct(e[0]);
      return "<tr><td>" + esc(p ? p.name : e[0]) + '</td><td class="an-num">' + e[1] + "</td></tr>";
    }).join("");
    var prodTbl = '<div class="an-block"><h3 class="an-h3">Most-viewed products</h3>' +
      (prodRows
        ? '<table class="admin-table"><thead><tr><th>Product</th><th class="an-num">Views</th></tr></thead><tbody>' + prodRows + "</tbody></table>"
        : '<p class="admin-empty">No product views yet.</p>') + "</div>";

    box.innerHTML = kpis + chart + pagesTbl + prodTbl;
  }

  /* ============================================================  CONTENT  */
  var contentLoaded = false;
  var catImages = {};   // id → { img, imgV } for the category landing tiles
  var COPY_KEYS = [
    "contactPhone", "contactEmail", "contactAddr",
    "footerCopy", "footerPowered"
  ];

  // Contact rows that can be shown/hidden on the public page. Stored in
  // content.json under `visible`; the checkbox ids are "v-<key>".
  var VISIBLE_KEYS = ["contactPhone", "contactEmail", "contactAddr"];

  function setupContent() {
    var form = $("#contentForm");
    if (form) form.addEventListener("submit", saveContent);
  }
  function loadContent() {
    getJSON("api/content-get.php").then(function (res) {
      contentLoaded = true;
      var c = (res && res.content) || {};
      var copy = c.copy || {};
      COPY_KEYS.forEach(function (k) {
        var f = document.querySelector('#contentForm [name="' + k + '"]');
        if (f) f.value = copy[k] == null ? "" : copy[k];
      });
      // Show/hide flags: absent means shown, matching the public default.
      var vis = (c.visible && typeof c.visible === "object") ? c.visible : {};
      VISIBLE_KEYS.forEach(function (k) {
        var cb = document.getElementById("v-" + k);
        if (cb) cb.checked = vis[k] !== false;
      });
      catImages = (c.categories && typeof c.categories === "object") ? c.categories : {};
      buildCatImageRows(catImages);
      homeData = (c.home && typeof c.home === "object") ? c.home : {};
      if (!Array.isArray(homeData.slides)) { homeData.slides = []; }
      if (!Array.isArray(homeData.featured)) { homeData.featured = []; }
      buildHomeRows(homeData);
      buildFeaturedRows(homeData.featured);
    }).catch(function (e) {
      status($("#contentStatus"), e.message || "Could not load content.", "error");
    });
  }

  /* ---- Home page media (test1) -------------------------------------------
     The redesigned home page ships with no hardcoded photography: the hero,
     the three rotator slides and the "why" block are authored here. Uploads go
     to site-image.php and then auto-persist into content.json, so an upload
     can't be lost by forgetting to press Save (same flow as the category
     images and the Codes-tab pictograms). ---- */
  var homeData = { slides: [] };

  function setVal(id, v) { var n = document.getElementById(id); if (n) { n.value = v == null ? "" : v; } }
  function getVal(id) { var n = document.getElementById(id); return n ? n.value : ""; }

  function buildHomeRows(home) {
    setVal("h-heroTitle", home.heroTitle);
    setVal("h-heroSub", home.heroSub);
    setVal("h-whyTitle", home.whyTitle);
    setVal("h-whyBody", home.whyBody);

    // (Per-slide show/hide replaced the single whole-banner toggle.)

    // The three "why" key figures.
    var stats = Array.isArray(home.stats) ? home.stats : [];
    for (var si = 0; si < 3; si++) {
      var st = stats[si] || {};
      setVal("h-stat" + si + "-num", st.num);
      setVal("h-stat" + si + "-label", st.label);
    }

    var heroBox = $("#homeHeroImg");
    if (heroBox) {
      heroBox.innerHTML = "";
      heroBox.appendChild(siteImageRow("hero", "Hero photo", home.heroImg, home.heroImgV));
    }
    var whyBox = $("#homeWhyImg");
    if (whyBox) {
      whyBox.innerHTML = "";
      whyBox.appendChild(siteImageRow("why", "Section photo", home.whyImg, home.whyImgV));
    }

    var slideBox = $("#homeSlides");
    if (!slideBox) { return; }
    slideBox.innerHTML = "";
    for (var i = 0; i < 3; i++) {
      var s = home.slides[i] || {};
      var wrap = el("div", "admin-pair");
      var h = el("div", "fgroup__label");
      h.textContent = "Slide " + (i + 1);
      h.style.cssText = "font-size:11.5px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--ink-mute);margin:6px 0 10px";
      wrap.appendChild(h);

      // Per-slide show/hide. Absent means shown, matching the public default.
      var showWrap = el("div", "admin-check");
      var cb = document.createElement("input");
      cb.type = "checkbox"; cb.id = "h-s" + i + "-show"; cb.checked = s.show !== false;
      var lb = el("label", null, "Show this slide"); lb.setAttribute("for", cb.id);
      showWrap.appendChild(cb); showWrap.appendChild(lb);
      wrap.appendChild(showWrap);

      wrap.appendChild(labelledInput("h-s" + i + "-eyebrow", "Small label", s.eyebrow, "New in"));
      wrap.appendChild(labelledInput("h-s" + i + "-title", "Title", s.title, "2026 range now in stock"));
      wrap.appendChild(labelledTextarea("h-s" + i + "-body", "Text", s.body));
      wrap.appendChild(siteImageRow("slide" + (i + 1), "Slide photo", s.img, s.imgV));
      slideBox.appendChild(wrap);
    }
  }

  function labelledInput(id, label, value, placeholder) {
    var f = el("div", "field");
    var l = el("label", null, label); l.setAttribute("for", id);
    var i = document.createElement("input");
    i.type = "text"; i.id = id; i.value = value == null ? "" : value;
    if (placeholder) { i.placeholder = placeholder; }
    f.appendChild(l); f.appendChild(i);
    return f;
  }
  function labelledTextarea(id, label, value) {
    var f = el("div", "field");
    var l = el("label", null, label); l.setAttribute("for", id);
    var t = document.createElement("textarea");
    t.id = id; t.rows = 2; t.value = value == null ? "" : value;
    f.appendChild(l); f.appendChild(t);
    return f;
  }

  /* One image slot: thumbnail + file picker. Mirrors catImageRow. */
  function siteImageRow(slot, label, path, ver) {
    var row = el("div", "field admin-catimg");
    row.appendChild(el("label", null, label));

    var thumb = el("span", "admin-catimg__thumb");
    var img = document.createElement("img");
    img.alt = "";
    if (path) { img.src = adminImgSrc(path) + (ver ? "?v=" + ver : ""); }
    else { thumb.classList.add("is-empty"); }
    img.addEventListener("error", function () { thumb.classList.add("is-empty"); img.removeAttribute("src"); });
    thumb.appendChild(img);

    var file = document.createElement("input");
    file.type = "file";
    file.accept = "image/jpeg,image/png,image/webp";
    file.className = "admin-file";
    file.addEventListener("change", function () {
      if (file.files && file.files[0]) { uploadSiteImage(slot, file.files[0], img, thumb); }
      file.value = "";
    });

    var controls = el("div", "admin-catimg__controls");
    controls.appendChild(el("span", "admin-catimg__hint", path ? "Choose a file to replace it." : "No image yet."));
    controls.appendChild(file);

    row.appendChild(thumb);
    row.appendChild(controls);
    return row;
  }

  function uploadSiteImage(slot, file, img, thumb) {
    var st = $("#contentStatus");
    var fd = new FormData();
    fd.append("slot", slot);
    fd.append("image", file);
    status(st, "Uploading image…", "ok");
    fetch("api/site-image.php", {
      method: "POST", credentials: "same-origin",
      headers: { "X-CSRF-Token": CSRF },   // no Content-Type for FormData
      body: fd
    }).then(function (r) {
      if (r.status === 401) { location.href = "login.php"; throw new Error("auth"); }
      return r.json().catch(function () { throw new Error("Bad response (is PHP running?)"); });
    }).then(function (res) {
      if (!res || !res.success) { status(st, (res && res.error) || "Upload failed.", "error"); return; }
      var ver = String(Date.now());
      if (slot === "hero") { homeData.heroImg = res.path; homeData.heroImgV = ver; }
      else if (slot === "why") { homeData.whyImg = res.path; homeData.whyImgV = ver; }
      else {
        var i = parseInt(slot.replace("slide", ""), 10) - 1;
        if (!homeData.slides[i]) { homeData.slides[i] = {}; }
        homeData.slides[i].img = res.path;
        homeData.slides[i].imgV = ver;
      }
      img.src = adminImgSrc(res.path) + "?v=" + ver;
      thumb.classList.remove("is-empty");
      persistContent(true).then(function (sr) {
        status(st, (sr && sr.success)
          ? "Image uploaded and saved."
          : "Uploaded, but saving failed — click Save content.", (sr && sr.success) ? "ok" : "error");
      });
    }).catch(function (e) { status(st, e.message || "Upload failed.", "error"); });
  }

  /* Text fields live in the DOM; image paths live in homeData (set on upload). */
  function collectHome() {
    var slides = [];
    for (var i = 0; i < 3; i++) {
      var stored = homeData.slides[i] || {};
      var showEl = document.getElementById("h-s" + i + "-show");
      slides.push({
        eyebrow: getVal("h-s" + i + "-eyebrow"),
        title: getVal("h-s" + i + "-title"),
        body: getVal("h-s" + i + "-body"),
        img: stored.img || "",
        imgV: stored.imgV || "",
        show: showEl ? !!showEl.checked : true
      });
    }
    var featured = [];
    for (var j = 0; j < 4; j++) {
      var sel = document.querySelector('.admin-best__sel[data-best="' + j + '"]');
      featured.push(sel ? sel.value : "");
    }
    var stats = [];
    for (var k = 0; k < 3; k++) {
      stats.push({
        num: getVal("h-stat" + k + "-num"),
        label: getVal("h-stat" + k + "-label")
      });
    }
    return {
      heroTitle: getVal("h-heroTitle"),
      heroSub: getVal("h-heroSub"),
      heroImg: homeData.heroImg || "",
      heroImgV: homeData.heroImgV || "",
      whyTitle: getVal("h-whyTitle"),
      whyBody: getVal("h-whyBody"),
      whyImg: homeData.whyImg || "",
      whyImgV: homeData.whyImgV || "",
      stats: stats,
      slides: slides,
      featured: featured
    };
  }

  /* ---- Category landing images: one uploader per category. Each upload goes
     to category-image.php, then auto-persists the path into content.json so it
     can't be lost (mirrors the Codes-tab pictogram flow). ---- */
  function buildCatImageRows(categories) {
    var box = $("#catImageRows");
    if (!box) return;
    box.innerHTML = "";
    CATEGORIES.forEach(function (c) {
      box.appendChild(catImageRow(c, (categories && categories[c.id]) || null));
    });
  }
  function catImageRow(cat, data) {
    var row = el("div", "field admin-catimg");
    row.appendChild(el("label", null, cat.label));

    var thumb = el("span", "admin-catimg__thumb");
    var img = document.createElement("img");
    img.alt = "";
    if (data && data.img) {
      img.src = adminImgSrc(data.img) + (data.imgV ? "?v=" + data.imgV : "");
    } else {
      thumb.classList.add("is-empty");
    }
    img.addEventListener("error", function () { thumb.classList.add("is-empty"); img.removeAttribute("src"); });
    thumb.appendChild(img);

    var file = document.createElement("input");
    file.type = "file";
    file.accept = "image/jpeg,image/png,image/webp";
    file.className = "admin-file";
    file.addEventListener("change", function () {
      if (file.files && file.files[0]) uploadCategoryImage(cat.id, file.files[0], img, thumb);
      file.value = "";
    });

    var controls = el("div", "admin-catimg__controls");
    controls.appendChild(el("span", "admin-catimg__hint", data && data.img ? "Choose a file to replace it." : "No image yet."));
    controls.appendChild(file);

    row.appendChild(thumb);
    row.appendChild(controls);
    return row;
  }
  function uploadCategoryImage(id, file, img, thumb) {
    var st = $("#contentStatus");
    var fd = new FormData();
    fd.append("slug", id);
    fd.append("image", file);
    status(st, "Uploading category image…", "ok");
    fetch("api/category-image.php", {
      method: "POST", credentials: "same-origin",
      headers: { "X-CSRF-Token": CSRF },   // no Content-Type for FormData
      body: fd
    }).then(function (r) {
      if (r.status === 401) { location.href = "login.php"; throw new Error("auth"); }
      return r.json().catch(function () { throw new Error("Bad response (is PHP running?)"); });
    }).then(function (res) {
      if (!res || !res.success) { status(st, (res && res.error) || "Upload failed.", "error"); return; }
      var ver = String(Date.now());
      catImages[id] = { img: res.path, imgV: ver };
      img.src = adminImgSrc(res.path) + "?v=" + ver;
      thumb.classList.remove("is-empty");
      persistContent(true).then(function (sr) {
        status(st, (sr && sr.success)
          ? "Category image uploaded and saved."
          : "Uploaded, but saving failed — click Save content.", (sr && sr.success) ? "ok" : "error");
      });
    }).catch(function (e) { status(st, e.message || "Upload failed.", "error"); });
  }
  // The three best-seller slots are now product PICKERS: each is a <select>
  // (grouped by category) drawing from the live catalog. The chosen slug + a
  // snapshot of the product's name/img/imgV are saved into content.json.
  function ensureProducts(cb) {
    if (products.length) { cb(); return; }
    getJSON("api/products-get.php").then(function (res) {
      products = (res && res.products) || [];
      cb();
    }).catch(function () { cb(); });
  }
  function slugByName(name) {
    if (!name) return "";
    var lc = String(name).trim().toLowerCase();
    for (var i = 0; i < products.length; i++) {
      if (String(products[i].name).trim().toLowerCase() === lc) return products[i].slug;
    }
    return "";
  }
  /* Four pickers for the home page's Featured row. Each stores just a slug;
     the public page renders the card from products.json at runtime, so a
     product renamed or rephotographed later stays correct with no re-save. */
  function buildFeaturedRows(featured) {
    var box = $("#featuredRows");
    if (!box) { return; }
    box.innerHTML = '<p class="admin-empty">Loading products…</p>';
    ensureProducts(function () {
      box.innerHTML = "";
      for (var i = 0; i < 4; i++) { box.appendChild(bestRow(i, { slug: featured[i] || "" })); }
    });
  }
  function bestRow(i, b) {
    var wrap = el("div", "field admin-best");
    wrap.appendChild(el("label", null, "Slot " + (i + 1)));

    var sel = document.createElement("select");
    sel.className = "admin-select admin-best__sel";
    sel.setAttribute("data-best", String(i));
    var none = document.createElement("option");
    none.value = ""; none.textContent = "— None —";
    sel.appendChild(none);
    CATEGORIES.forEach(function (c) {
      var inCat = products.filter(function (p) { return p.category === c.id; });
      if (!inCat.length) return;
      var og = document.createElement("optgroup");
      og.label = c.label;
      inCat.forEach(function (p) {
        var o = document.createElement("option");
        o.value = p.slug;
        o.textContent = p.name + (p.img ? "" : "  (no photo)");
        og.appendChild(o);
      });
      sel.appendChild(og);
    });
    sel.value = b.slug || slugByName(b.name) || "";

    var prev = el("div", "admin-best__prev");
    sel.addEventListener("change", function () { renderBestPrev(prev, sel.value); });
    wrap.appendChild(sel);
    wrap.appendChild(prev);
    renderBestPrev(prev, sel.value);
    return wrap;
  }
  function renderBestPrev(node, slug) {
    node.innerHTML = "";
    if (!slug) { node.appendChild(el("span", "admin-best__hint", "Nothing selected — this slot is filled automatically.")); return; }
    var p = findProduct(slug);
    if (!p) { node.appendChild(el("span", "admin-best__hint", "This product no longer exists — pick another.")); return; }
    if (p.img) {
      var img = document.createElement("img");
      img.className = "admin-best__thumb";
      img.src = adminImgSrc(p.img) + (p.imgV ? "?v=" + p.imgV : "");
      img.alt = "";
      img.addEventListener("error", function () { img.classList.add("is-empty"); img.removeAttribute("src"); });
      node.appendChild(img);
      node.appendChild(el("span", "admin-best__hint", p.name));
    } else {
      node.appendChild(el("span", "admin-best__thumb admin-best__thumb--empty", "—"));
      node.appendChild(el("span", "admin-best__hint admin-best__hint--warn", p.name + " has no photo — it shows a grey KAPRIOL placeholder on the site."));
    }
  }
  function saveContent(e) {
    if (e) e.preventDefault();
    persistContent(false);
  }
  // silent=true skips the status flashes (used by the category-image auto-save).
  function persistContent(silent) {
    var st = $("#contentStatus");
    var copy = {};
    COPY_KEYS.forEach(function (k) {
      var f = document.querySelector('#contentForm [name="' + k + '"]');
      copy[k] = f ? f.value : "";
    });
    var visible = {};
    VISIBLE_KEYS.forEach(function (k) {
      var cb = document.getElementById("v-" + k);
      visible[k] = cb ? !!cb.checked : true;
    });
    if (!silent) status(st, "Saving…", "ok");
    return postJSON("api/content-save.php", {
      copy: copy, categories: catImages, visible: visible, home: collectHome()
    }).then(function (res) {
      if (!silent) status(st, res && res.success ? "Saved. Changes are live." : ((res && res.error) || "Save failed."), res && res.success ? "ok" : "error");
      return res;
    }).catch(function (err) {
      if (!silent) status(st, err.message || "Save failed.", "error");
    });
  }

  /* ==============================================================  CODES  */
  var codesRendered = false;
  function setupCodes() {
    ["#codesSave", "#codesSave2"].forEach(function (sel) {
      var b = $(sel);
      if (b) b.addEventListener("click", function () { saveCodes(false); });
    });
  }

  function renderCodes() {
    var box = $("#codesGroups");
    if (!box) return;
    box.innerHTML = "";
    CODE_GROUPS.forEach(function (g) {
      var fs = el("div", "admin-fieldset");
      var lg = document.createElement("legend");
      lg.textContent = g.label + (g.pictos ? "" : " · text only");
      fs.appendChild(lg);

      var rows = el("div", "admin-coderows");
      rows.setAttribute("data-codes", g.key);
      (attrDefs[g.key] || []).slice().sort(byCode).forEach(function (it) { rows.appendChild(codeRow(g, it)); });
      fs.appendChild(rows);

      var add = el("button", "admin-rows__add", "+ Add code");
      add.type = "button";
      add.addEventListener("click", function () { rows.appendChild(codeRow(g, {})); });
      fs.appendChild(add);
      box.appendChild(fs);
    });
  }

  function codeRow(g, item) {
    item = item || {};
    var row = el("div", "admin-coderow" + (g.pictos ? " admin-coderow--picto" : ""));
    var main = el("div", "admin-coderow__main");
    var thumb, img, hidden;

    if (g.pictos) {
      thumb = el("div", "admin-coderow__thumb");
      img = document.createElement("img"); img.alt = "";
      if (item.img) { img.src = adminImgSrc(item.img); } else { thumb.classList.add("is-empty"); }
      img.addEventListener("error", function () { thumb.classList.add("is-empty"); img.removeAttribute("src"); });
      thumb.appendChild(img);
      main.appendChild(thumb);
    }

    var code = document.createElement("input");
    code.type = "text"; code.placeholder = "Code"; code.className = "admin-coderow__code";
    code.setAttribute("data-c-code", "1"); code.value = item.code == null ? "" : item.code;

    main.appendChild(code);

    if (g.pictos) {
      hidden = document.createElement("input");
      hidden.type = "hidden"; hidden.setAttribute("data-c-img", "1"); hidden.value = item.img == null ? "" : item.img;
      var file = document.createElement("input");
      file.type = "file"; file.accept = "image/jpeg,image/png,image/webp"; file.className = "admin-coderow__file";
      file.title = "Upload pictogram";
      file.addEventListener("change", function () {
        if (file.files && file.files[0]) uploadCodeImage(code, hidden, img, thumb, file.files[0]);
      });
      main.appendChild(hidden);
      main.appendChild(file);
    }

    main.appendChild(delBtn(row));
    row.appendChild(main);

    // Title + description → shown as a clickable info popup on the public product
    // page when a customer clicks this code. Pictogram groups (Performance /
    // Technologies) only; safety class stays code + name.
    if (g.pictos) {
      var detail = el("div", "admin-coderow__detail");
      var title = document.createElement("input");
      title.type = "text"; title.className = "admin-coderow__title";
      title.placeholder = "Popup title (optional, e.g. Fuel & oil resistance)";
      title.setAttribute("data-c-title", "1");
      title.value = item.title == null ? "" : item.title;

      var desc = document.createElement("textarea");
      desc.className = "admin-coderow__desc"; desc.rows = 2;
      desc.placeholder = "Popup description — shown when a customer clicks this code on the product page. Leave blank to keep the code non-clickable.";
      desc.setAttribute("data-c-desc", "1");
      desc.value = item.description == null ? "" : item.description;

      detail.appendChild(title);
      detail.appendChild(desc);
      row.appendChild(detail);
    }

    return row;
  }

  function uploadCodeImage(codeInp, hidden, img, thumb, file) {
    var st = $("#codesStatus");
    var slug = slugifyCode(codeInp.value);
    if (!slug) { status(st, "Type the code first, then choose a pictogram.", "error"); return; }
    var fd = new FormData();
    fd.append("slug", slug);
    fd.append("image", file);
    status(st, "Uploading pictogram…", "ok");

    fetch("api/attr-image.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-CSRF-Token": CSRF },   // no Content-Type for FormData
      body: fd
    }).then(function (r) {
      if (r.status === 401) { location.href = "login.php"; throw new Error("auth"); }
      return r.json().catch(function () { throw new Error("Bad response (is PHP running?)"); });
    }).then(function (res) {
      if (!res || !res.success) { status(st, (res && res.error) || "Upload failed.", "error"); return; }
      hidden.value = res.path;
      img.src = adminImgSrc(res.path) + "?t=" + Date.now();
      thumb.classList.remove("is-empty");
      saveCodes(true);   // persist so the new pictogram path isn't lost
      status(st, "Pictogram uploaded and saved.", "ok");
    }).catch(function (e) {
      status(st, e.message || "Upload failed.", "error");
    });
  }

  function collectCodes() {
    var out = {};
    CODE_GROUPS.forEach(function (g) {
      var rows = document.querySelectorAll('[data-codes="' + g.key + '"] .admin-coderow');
      out[g.key] = Array.prototype.map.call(rows, function (row) {
        var item = {
          code: row.querySelector("[data-c-code]").value.trim()
        };
        if (g.pictos) {
          var im = row.querySelector("[data-c-img]");
          item.img = im ? im.value.trim() : "";
          var ti = row.querySelector("[data-c-title]");
          var de = row.querySelector("[data-c-desc]");
          item.title = ti ? ti.value.trim() : "";
          item.description = de ? de.value.trim() : "";
        }
        return item;
      }).filter(function (it) { return it.code !== ""; });
    });
    return out;
  }

  // silent=true skips the "Saving…" flash (used by auto-save after an upload).
  // Never re-renders the list, so any in-progress empty rows stay put.
  function saveCodes(silent) {
    var st = $("#codesStatus");
    var payload = collectCodes();
    if (!silent) status(st, "Saving…", "ok");
    return postJSON("api/attributes-save.php", payload).then(function (res) {
      if (res && res.success) {
        CODE_GROUPS.forEach(function (g) {
          attrDefs[g.key] = (res.attributes && res.attributes[g.key]) || payload[g.key] || [];
        });
        if (!silent) status(st, "Saved. Changes are live.", "ok");
      } else {
        status(st, (res && res.error) || "Save failed.", "error");
      }
    }).catch(function (err) {
      status(st, err.message || "Save failed.", "error");
    });
  }

  /* --------------------------------------------------------------- init -- */
  setupTabs();
  setupProducts();
  setupEnquiries();
  setupPricing();
  setupAnalytics();
  setupContent();
  setupCodes();
  loadAttrDefs();   // warm the spec-code list for the product pickers

  // Close any open spec-picker popover when clicking outside it.
  document.addEventListener("click", function (e) {
    document.querySelectorAll(".admin-specpick__pop").forEach(function (pop) {
      if (pop.hidden) return;
      var holder = pop.closest(".admin-specpick");
      if (holder && !holder.contains(e.target)) pop.hidden = true;
    });
  });
})();
