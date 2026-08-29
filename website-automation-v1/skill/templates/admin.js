/* ============================================================================
   Admin dashboard front end (starter). Talks to the JSON endpoints in api/,
   sending the CSRF token on every write. ADAPT field lists to the project.
   ========================================================================== */
(function () {
  "use strict";

  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  var API = "api/";

  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

  /* fetch helper. GET for reads; POST JSON (with CSRF) for writes. A 401 means
     the session expired — send the user back to the login screen. */
  function api(path, body, isForm) {
    var opt = { credentials: "same-origin" };
    if (body !== undefined) {
      opt.method = "POST";
      opt.headers = { "X-CSRF-Token": CSRF };
      if (isForm) { opt.body = body; }               // FormData (uploads)
      else { opt.headers["Content-Type"] = "application/json"; opt.body = JSON.stringify(body); }
    }
    return fetch(API + path, opt).then(function (r) {
      if (r.status === 401) { location.href = "login.php"; throw new Error("auth"); }
      return r.json();
    });
  }

  /* ------------------------------------------------------------- tabs ----- */
  $$(".admin-tab").forEach(function (tab) {
    tab.addEventListener("click", function () {
      $$(".admin-tab").forEach(function (t) { t.classList.toggle("is-active", t === tab); });
      var id = tab.getAttribute("data-tab");
      $$(".admin-panel").forEach(function (p) { p.hidden = (p.id !== "tab-" + id); });
      if (id === "enquiries") loadEnquiries();
      if (id === "content") loadContent();
    });
  });

  /* ------------------------------------------------------------- items ---- */
  var ITEMS = [], editingSlug = null;
  var form = $("#itemForm"), list = $("#itemList"), status = $("#itemStatus");

  function loadItems() {
    api("items-get.php").then(function (data) {
      ITEMS = Array.isArray(data) ? data : [];
      renderList();
    });
  }
  function renderList() {
    var q = ($("#itemSearch").value || "").toLowerCase();
    list.innerHTML = "";
    ITEMS.filter(function (p) { return !q || (p.name || "").toLowerCase().indexOf(q) >= 0 || (p.slug || "").indexOf(q) >= 0; })
      .forEach(function (p) {
        var li = document.createElement("li");
        li.className = "admin-list__row" + (p.hidden ? " is-hidden" : "");
        li.textContent = p.name || p.slug;
        li.addEventListener("click", function () { openEditor(p); });
        list.appendChild(li);
      });
  }
  function openEditor(p) {
    editingSlug = p ? p.slug : null;
    form.hidden = false;
    form.slug.value = p ? (p.slug || "") : "";
    form.slug.readOnly = !!p;                          // slug is immutable once created
    form.category.value = p ? (p.category || "") : "";
    form.name.value = p ? (p.name || "") : "";
    form.description.value = p ? (p.description || "") : "";
    form.fullDescription.value = p ? (p.fullDescription || "") : "";
    form.hidden.checked = p ? !!p.hidden : false;
    $("#itemDelete").hidden = !p;
    var prev = $("#itemImagePreview");
    prev.src = (p && p.img) ? "../" + p.img + (p.imgV ? "?v=" + p.imgV : "") : "";
    status.textContent = "";
  }
  $("#itemNew").addEventListener("click", function () { openEditor(null); });
  $("#itemSearch").addEventListener("input", renderList);

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var rec = {
      slug: form.slug.value.trim(),
      category: form.category.value.trim(),
      name: form.name.value.trim(),
      description: form.description.value.trim(),
      fullDescription: form.fullDescription.value.trim(),
      hidden: form.hidden.checked
    };
    var endpoint = editingSlug ? "items-save.php" : "items-create.php";
    api(endpoint, rec).then(function (res) {
      if (!res.success) { status.textContent = res.error || "Save failed."; return; }
      status.textContent = "Saved.";
      editingSlug = rec.slug; form.slug.readOnly = true; $("#itemDelete").hidden = false;
      loadItems();
    });
  });

  $("#itemDelete").addEventListener("click", function () {
    if (!editingSlug || !confirm("Delete this item?")) return;
    api("items-delete.php", { slug: editingSlug }).then(function (res) {
      if (!res.success) { status.textContent = res.error || "Delete failed."; return; }
      form.hidden = true; loadItems();
    });
  });

  $("#itemImage").addEventListener("change", function () {
    var f = this.files && this.files[0];
    if (!f || !editingSlug) { if (!editingSlug) status.textContent = "Save the item first, then add a photo."; return; }
    var fd = new FormData(); fd.append("slug", editingSlug); fd.append("image", f);
    api("items-image.php", fd, true).then(function (res) {
      if (!res.success) { status.textContent = res.error || "Upload failed."; return; }
      var imgV = String(Date.now());
      return api("items-save.php", { slug: editingSlug, img: res.path, images: [res.path], imgV: imgV }).then(function () {
        $("#itemImagePreview").src = "../" + res.path + "?v=" + imgV;
        status.textContent = "Photo updated."; loadItems();
      });
    });
  });

  /* -------------------------------------------------------- enquiries ----- */
  function loadEnquiries() {
    api("enquiries-list.php").then(function (rows) {
      var ul = $("#enquiryList"); ul.innerHTML = "";
      (Array.isArray(rows) ? rows : []).forEach(function (e) {
        var li = document.createElement("li"); li.className = "admin-enquiries__row";
        li.textContent = (e.ts || "") + " — " + (e.name || "") + " <" + (e.email || "") + "> " + (e.phone || "") + " — " + (e.message || "");
        ul.appendChild(li);
      });
    });
  }

  /* ---------------------------------------------------------- content ----- */
  var cForm = $("#contentForm"), cStatus = $("#contentStatus");
  function loadContent() {
    api("content-get.php").then(function (c) {
      var copy = (c && c.copy) || {}, vis = (c && c.visible) || {};
      ["contactPhone", "contactEmail", "contactAddr", "footerCopy", "footerPowered"].forEach(function (k) {
        if (cForm[k]) cForm[k].value = copy[k] || "";
      });
      ["contactPhone", "contactEmail", "contactAddr"].forEach(function (k) {
        if (cForm["v_" + k]) cForm["v_" + k].checked = (k in vis) ? !!vis[k] : true;
      });
    });
  }
  cForm.addEventListener("submit", function (e) {
    e.preventDefault();
    var payload = {
      copy: {
        contactPhone: cForm.contactPhone.value, contactEmail: cForm.contactEmail.value,
        contactAddr: cForm.contactAddr.value, footerCopy: cForm.footerCopy.value,
        footerPowered: cForm.footerPowered.value
      },
      visible: {
        contactPhone: cForm.v_contactPhone.checked, contactEmail: cForm.v_contactEmail.checked,
        contactAddr: cForm.v_contactAddr.checked
      }
    };
    api("content-save.php", payload).then(function (res) {
      cStatus.textContent = res.success ? "Saved." : (res.error || "Save failed.");
    });
  });

  loadItems();
})();
