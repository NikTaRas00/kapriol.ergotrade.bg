<?php
/**
 * index.php — admin dashboard shell (Products / Enquiries / Site content).
 * Guarded: guests are bounced to login.php. The CSRF token is embedded in a
 * <meta> for admin.js to send on every write.
 */
require __DIR__ . '/api/_guard.php';
admin_redirect_if_guest();
$csrf = admin_csrf_token();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="theme-color" content="#f4f5f6" />
  <meta name="csrf" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
  <title>Admin — kapriol.bg</title>
  <link rel="stylesheet" href="../assets/css/style.css?v=24" />
  <link rel="stylesheet" href="assets/admin.css?v=17" />
</head>
<body class="admin">

  <!-- ============================ TOP BAR ============================ -->
  <header class="admin-bar">
    <div class="admin-bar__inner">
      <span class="admin-bar__brand">KAPRIOL <span>Admin</span></span>
      <nav class="admin-tabs" aria-label="Admin sections">
        <button class="admin-tab is-active" type="button" data-tab="products" aria-selected="true">Products</button>
        <button class="admin-tab" type="button" data-tab="enquiries" aria-selected="false">Enquiries</button>
        <button class="admin-tab" type="button" data-tab="analytics" aria-selected="false">Analytics</button>
        <button class="admin-tab" type="button" data-tab="codes" aria-selected="false">Codes</button>
        <button class="admin-tab" type="button" data-tab="content" aria-selected="false">Site content</button>
      </nav>
      <div class="admin-bar__right">
        <a class="btn btn--outline btn--sm" href="../" target="_blank" rel="noopener">View site</a>
        <a class="btn btn--ghost btn--sm" href="logout.php">Sign out</a>
      </div>
    </div>
  </header>

  <main class="admin-main">

    <!-- ============================ PRODUCTS ============================ -->
    <section class="admin-panel is-active" id="tab-products" role="tabpanel">
      <div class="admin-grid">
        <!-- Left: search + list -->
        <aside class="admin-list">
          <div class="admin-list__head">
            <button class="btn btn--red btn--sm admin-list__new" type="button" id="prodNew">+ New product</button>
            <input type="search" id="prodSearch" class="admin-search" placeholder="Search products…"
                   autocomplete="off" aria-label="Search products" />
            <select class="admin-select" id="prodCatFilter" aria-label="Filter by category">
              <option value="">All categories</option>
            </select>
            <p class="admin-list__count" id="prodCount" aria-live="polite"></p>
          </div>
          <div class="admin-list__scroll" id="prodResults"></div>
        </aside>

        <!-- Right: editor (populated by admin.js when a product is picked) -->
        <div class="admin-editor" id="prodEditor">
          <div class="admin-empty" id="prodEditorEmpty">
            <p>Select a product on the left to edit it.</p>
          </div>
          <form class="admin-form" id="prodForm" hidden novalidate>
            <div class="admin-form__head">
              <h2 class="admin-form__title" id="prodFormTitle">Edit product</h2>
              <span class="admin-meta" id="prodFormMeta"></span>
            </div>
            <div id="prodFields"><!-- fields injected by admin.js --></div>
            <div class="admin-form__foot">
              <button class="btn btn--red" type="submit" id="prodSave">Save changes</button>
              <p class="form-status" id="prodStatus" role="status" aria-live="polite"></p>
              <button class="btn btn--ghost btn--sm admin-del" type="button" id="prodDelete" hidden>Delete product</button>
            </div>
          </form>
        </div>
      </div>
    </section>

    <!-- ============================ ENQUIRIES ============================ -->
    <section class="admin-panel" id="tab-enquiries" role="tabpanel" hidden>
      <div class="admin-section__head">
        <h2 class="admin-h2">Enquiries</h2>
        <button class="btn btn--outline btn--sm" type="button" id="enqRefresh">Refresh</button>
      </div>
      <p class="admin-meta" id="enqCount" aria-live="polite"></p>
      <div class="admin-table-wrap" id="enqTableWrap">
        <p class="admin-empty">Loading…</p>
      </div>
    </section>

    <!-- ============================ ANALYTICS ============================
         Visitor stats from the cookieless track.php beacon. Read-only. -->
    <section class="admin-panel" id="tab-analytics" role="tabpanel" hidden>
      <div class="admin-section__head">
        <h2 class="admin-h2">Visitor analytics</h2>
        <button class="btn btn--outline btn--sm" type="button" id="anRefresh">Refresh</button>
      </div>
      <p class="admin-meta">Anonymous, cookieless counts collected on the public site. A &ldquo;visit&rdquo; is one browser session; a &ldquo;view&rdquo; is one page load.</p>
      <div id="anBody"><p class="admin-empty">Loading…</p></div>
    </section>

    <!-- ============================ CODES ============================
         Master list of spec codes (Performance / Safety class / Technologies)
         used by footwear products. Performance & Technologies codes can carry a
         pictogram; Safety class is text-only. Built by admin.js. -->
    <section class="admin-panel" id="tab-codes" role="tabpanel" hidden>
      <div class="admin-section__head">
        <h2 class="admin-h2">Codes &amp; pictograms</h2>
        <button class="btn btn--red btn--sm" type="button" id="codesSave">Save codes</button>
      </div>
      <p class="admin-meta">Define the codes a footwear product can be tagged with. Performance and Technologies codes can have a pictogram; Safety class codes are text-only. <span class="admin-hint">Assign codes to a product on the Products tab.</span></p>
      <div id="codesGroups"><!-- three groups injected by admin.js --></div>
      <div class="admin-form__foot">
        <button class="btn btn--red" type="button" id="codesSave2">Save codes</button>
        <p class="form-status" id="codesStatus" role="status" aria-live="polite"></p>
      </div>
    </section>


    <!-- ============================ SITE CONTENT ============================ -->
    <section class="admin-panel" id="tab-content" role="tabpanel" hidden>
      <form class="admin-form admin-form--content" id="contentForm" novalidate>
        <div class="admin-section__head">
          <h2 class="admin-h2">Site content</h2>
          <button class="btn btn--red btn--sm" type="submit">Save content</button>
        </div>
        <p class="admin-meta">These texts appear on the public site. Changes go live on save.</p>

        <fieldset class="admin-fieldset">
          <legend>Hero (home top)</legend>
          <div class="field"><label for="c-heroLabel">Label</label><input id="c-heroLabel" name="heroLabel" type="text" /></div>
          <div class="field"><label for="c-heroH1">Headline <span class="admin-hint">(use a new line for a line break)</span></label><textarea id="c-heroH1" name="heroH1" rows="2"></textarea></div>
          <div class="field"><label for="c-heroSub">Subtitle</label><textarea id="c-heroSub" name="heroSub" rows="2"></textarea></div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>About (&ldquo;Who we are&rdquo;)</legend>
          <div class="field"><label for="c-aboutHeading">Heading</label><input id="c-aboutHeading" name="aboutHeading" type="text" /></div>
          <div class="field"><label for="c-aboutQuote">Lead statement <span class="admin-hint">(the large quote)</span></label><textarea id="c-aboutQuote" name="aboutQuote" rows="2"></textarea></div>
          <div class="field"><label for="c-aboutP1">Paragraph 1</label><textarea id="c-aboutP1" name="aboutP1" rows="3"></textarea></div>
          <div class="field"><label for="c-aboutP2">Paragraph 2</label><textarea id="c-aboutP2" name="aboutP2" rows="3"></textarea></div>
          <div class="field"><label for="c-aboutP3">Paragraph 3</label><textarea id="c-aboutP3" name="aboutP3" rows="2"></textarea></div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>Contact details</legend>
          <p class="admin-img__hint">Shown on the Contact page. Untick “Show on site” to hide a row completely — the whole line disappears, it isn't left blank.</p>

          <div class="field admin-contactrow">
            <label for="c-contactPhone">Phone</label>
            <input id="c-contactPhone" name="contactPhone" type="text" placeholder="+359 …" />
            <div class="admin-check admin-check--inline">
              <input type="checkbox" id="v-contactPhone" name="showContactPhone" />
              <label for="v-contactPhone">Show on site</label>
            </div>
          </div>

          <div class="field admin-contactrow">
            <label for="c-contactEmail">Email</label>
            <input id="c-contactEmail" name="contactEmail" type="text" placeholder="info@kapriol.bg" />
            <div class="admin-check admin-check--inline">
              <input type="checkbox" id="v-contactEmail" name="showContactEmail" />
              <label for="v-contactEmail">Show on site</label>
            </div>
          </div>

          <div class="field admin-contactrow">
            <label for="c-contactAddr">Address</label>
            <input id="c-contactAddr" name="contactAddr" type="text" placeholder="Street, city" />
            <div class="admin-check admin-check--inline">
              <input type="checkbox" id="v-contactAddr" name="showContactAddr" />
              <label for="v-contactAddr">Show on site</label>
            </div>
          </div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>Category images</legend>
          <p class="admin-meta">A photo for each category tile on the Products page. A wide/landscape image works best; a category with no image shows a grey placeholder. Uploads save immediately.</p>
          <div id="catImageRows"><!-- 6 category image uploaders injected by admin.js --></div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>Best sellers</legend>
          <p class="admin-meta">Pick three products to feature on the home page. Each card automatically shows that product's name, description and Performance/Technologies pictograms — assign those on the Products tab. A product with no photo shows a grey placeholder.</p>
          <div class="field"><label for="c-bestEyebrow">Section label <span class="admin-hint">(small text above each product)</span></label><input id="c-bestEyebrow" name="bestEyebrow" type="text" /></div>
          <div id="bestRows"><!-- 3 product pickers injected by admin.js --></div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>Footer</legend>
          <div class="field"><label for="c-footerCopy">Copyright line</label><input id="c-footerCopy" name="footerCopy" type="text" placeholder="&copy; 2026 ergotrade.bg" /></div>
          <div class="field"><label for="c-footerPowered">Powered-by line</label><input id="c-footerPowered" name="footerPowered" type="text" placeholder="Powered by ergotrade.bg" /></div>
        </fieldset>

        <div class="admin-form__foot">
          <button class="btn btn--red" type="submit">Save content</button>
          <p class="form-status" id="contentStatus" role="status" aria-live="polite"></p>
        </div>
      </form>
    </section>

  </main>

  <script src="assets/admin.js?v=23" defer></script>
</body>
</html>
