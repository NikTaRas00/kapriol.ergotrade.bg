<?php
/**
 * index.php — test1 admin dashboard shell.
 *
 * Same five working panels as the live admin (Products / Enquiries / Analytics
 * / Codes / Site content) and the same admin.js driving them; only the shell is
 * restyled to the dark-sidebar design. The nav buttons keep their .admin-tab
 * class and data-tab attribute and the panels keep their #tab-* ids, so the
 * existing JS needs no changes.
 *
 * test1 is a self-contained copy of the site: its guard resolves KAP_WEB_ROOT
 * to the test1 folder, so every read and write targets test1/assets/. Only
 * private/ (config, password, enquiries) is shared with the live install.
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
  <meta name="theme-color" content="#14110f" />
  <meta name="csrf" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
  <title>Admin | Kapriol Bulgaria</title>
  <link rel="icon" href="../assets/img/favicon.ico" sizes="any" />
  <link rel="stylesheet" href="assets/base.css?v=2" />
  <link rel="stylesheet" href="assets/admin.css?v=3" />
  <link rel="stylesheet" href="assets/theme.css?v=2" />
</head>
<body class="admin">

<div class="admin-shell">

  <!-- ============================ SIDEBAR ============================ -->
  <aside class="admin-side">
    <a class="admin-side__brand" href="../index.html">
      <span class="admin-side__mark">KAPRIOL<i>.</i></span>
      <span class="admin-side__sub">Admin</span>
    </a>
    <div class="admin-side__rule" aria-hidden="true"></div>

    <nav class="admin-tabs" aria-label="Admin sections">
      <button class="admin-tab is-active" type="button" data-tab="products" aria-selected="true">Products</button>
      <button class="admin-tab" type="button" data-tab="enquiries" aria-selected="false">Enquiries</button>
      <button class="admin-tab" type="button" data-tab="analytics" aria-selected="false">Analytics</button>
      <button class="admin-tab" type="button" data-tab="codes" aria-selected="false">Codes</button>
      <button class="admin-tab" type="button" data-tab="content" aria-selected="false">Site content</button>
    </nav>

    <div class="admin-side__foot">
      <a class="btn btn--outline btn--sm" href="../index.html" target="_blank" rel="noopener">View site</a>
      <a class="btn btn--ghost btn--sm" href="logout.php">Sign out</a>
    </div>
  </aside>

  <main class="admin-main">

    <!-- ============================ PRODUCTS ============================ -->
    <section class="admin-panel is-active" id="tab-products" role="tabpanel">
      <div class="admin-grid">
        <aside class="admin-list">
          <div class="admin-list__head">
            <button class="btn btn--ghost btn--sm admin-list__bulk" type="button" id="prodHideAll">Hide all products</button>
            <p class="form-status admin-list__msg" id="prodBulkStatus" role="status" aria-live="polite"></p>
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

    <!-- ============================ ANALYTICS ============================ -->
    <section class="admin-panel" id="tab-analytics" role="tabpanel" hidden>
      <div class="admin-section__head">
        <h2 class="admin-h2">Visitor analytics</h2>
        <button class="btn btn--outline btn--sm" type="button" id="anRefresh">Refresh</button>
      </div>
      <p class="admin-meta">Anonymous, cookieless counts collected on the public site. A &ldquo;visit&rdquo; is one browser session; a &ldquo;view&rdquo; is one page load. Pages from this staging build are prefixed <strong>t1-</strong>.</p>
      <div id="anBody"><p class="admin-empty">Loading…</p></div>
    </section>

    <!-- ============================ CODES ============================ -->
    <section class="admin-panel" id="tab-codes" role="tabpanel" hidden>
      <div class="admin-section__head">
        <h2 class="admin-h2">Codes &amp; pictograms</h2>
        <button class="btn btn--red btn--sm" type="button" id="codesSave">Save codes</button>
      </div>
      <p class="admin-meta">Define the codes a product can be tagged with. Performance and Technologies codes can have a pictogram; Safety class codes are text-only. <span class="admin-hint">Assign codes to a product on the Products tab.</span></p>
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
          <legend>Home: hero</legend>
          <p class="admin-img__hint">The dark band at the top of the home page. Leave a text field empty to keep the built-in wording. With no photo the hero shows a plain dark panel, never a broken image. A wide, landscape photo works best.</p>
          <div class="field"><label for="h-heroTitle">Headline</label><input id="h-heroTitle" type="text" placeholder="Gear that doesn't quit when the shift does" /></div>
          <div class="field"><label for="h-heroSub">Subtitle</label><textarea id="h-heroSub" rows="2" placeholder="Built in Italy since 1980…"></textarea></div>
          <div id="homeHeroImg"><!-- uploader injected by admin.js --></div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>Home: rotating banner</legend>
          <p class="admin-img__hint">Three slides directly under the hero. Untick &ldquo;Show this slide&rdquo; on any of them to hide it; a slide with no text and no photo is skipped too. When none are shown the whole banner disappears.</p>
          <div id="homeSlides"><!-- three slide editors injected by admin.js --></div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>Home: why Kapriol</legend>
          <p class="admin-img__hint">The photo-and-text block further down the home page. A portrait or square photo suits this slot.</p>
          <div class="field"><label for="h-whyTitle">Heading</label><input id="h-whyTitle" type="text" placeholder="Italian engineering, built for the field" /></div>
          <div class="field"><label for="h-whyBody">Text</label><textarea id="h-whyBody" rows="4"></textarea></div>
          <div id="homeWhyImg"><!-- uploader injected by admin.js --></div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>Home: key figures</legend>
          <p class="admin-img__hint">The three figures shown under the &ldquo;Why Kapriol&rdquo; text (big number + caption). Leave a field empty to keep the built-in wording.</p>
          <div class="admin-statrow">
            <div class="field"><label for="h-stat0-num">Figure 1 &mdash; number</label><input id="h-stat0-num" type="text" placeholder="2014" /></div>
            <div class="field"><label for="h-stat0-label">Figure 1 &mdash; caption</label><input id="h-stat0-label" type="text" placeholder="Distributing since" /></div>
          </div>
          <div class="admin-statrow">
            <div class="field"><label for="h-stat1-num">Figure 2 &mdash; number</label><input id="h-stat1-num" type="text" placeholder="1980" /></div>
            <div class="field"><label for="h-stat1-label">Figure 2 &mdash; caption</label><input id="h-stat1-label" type="text" placeholder="Kapriol founded" /></div>
          </div>
          <div class="admin-statrow">
            <div class="field"><label for="h-stat2-num">Figure 3 &mdash; number</label><input id="h-stat2-num" type="text" placeholder="6" /></div>
            <div class="field"><label for="h-stat2-label">Figure 3 &mdash; caption</label><input id="h-stat2-label" type="text" placeholder="Product ranges" /></div>
          </div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>Home: featured products</legend>
          <p class="admin-img__hint">The four product cards near the bottom of the home page. Leave every slot on &ldquo;None&rdquo; and the site picks one product per range automatically.</p>
          <div id="featuredRows"><!-- four product pickers injected by admin.js --></div>
        </fieldset>

        <fieldset class="admin-fieldset">
          <legend>Contact details</legend>
          <p class="admin-img__hint">Shown on the Contact page. Untick &ldquo;Show on site&rdquo; to hide a row completely: the whole line disappears, it is not left blank.</p>

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
            <input id="c-contactEmail" name="contactEmail" type="text" placeholder="info@kapriol.ergotrade.bg" />
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
          <legend>Range tile photos</legend>
          <p class="admin-img__hint">The photo behind each of the six range tiles on the home and catalogue pages. Without one, a tile falls back to the first product photo in that range.</p>
          <div id="catImageRows"><!-- injected by admin.js --></div>
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
</div>

<script src="assets/admin.js?v=6" defer></script>
</body>
</html>
