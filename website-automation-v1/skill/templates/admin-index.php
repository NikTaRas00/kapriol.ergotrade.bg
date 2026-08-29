<?php
/**
 * index.php — admin dashboard (starter). Guests bounce to login.php.
 * Emits the CSRF token for admin.js and hosts the tabbed editors. ADAPT the
 * fields to the entity + content keys for this project.
 */
require __DIR__ . '/api/_guard.php';
admin_redirect_if_guest();
$csrf = admin_csrf_token();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
  <title>Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=1" />
</head>
<body class="admin">
  <header class="admin-head">
    <strong class="admin-head__brand">Admin</strong>
    <nav class="admin-tabs" role="tablist">
      <button class="admin-tab is-active" data-tab="items" role="tab">Items</button>
      <button class="admin-tab" data-tab="enquiries" role="tab">Enquiries</button>
      <button class="admin-tab" data-tab="content" role="tab">Site content</button>
    </nav>
    <a class="admin-logout" href="logout.php">Sign out</a>
  </header>

  <main class="admin-main">
    <!-- ITEMS ------------------------------------------------------------ -->
    <section id="tab-items" class="admin-panel is-active" role="tabpanel">
      <div class="admin-toolbar">
        <input id="itemSearch" type="search" placeholder="Search items…" />
        <button id="itemNew" class="btn">+ New item</button>
      </div>
      <div class="admin-split">
        <ul id="itemList" class="admin-list" aria-label="Items"></ul>
        <form id="itemForm" class="admin-form" hidden>
          <div class="field"><label>Slug<input name="slug" required /></label></div>
          <div class="field"><label>Category<input name="category" /></label></div>
          <div class="field"><label>Name<input name="name" required /></label></div>
          <div class="field"><label>Short description<input name="description" /></label></div>
          <div class="field"><label>Full description<textarea name="fullDescription" rows="6"></textarea></label></div>
          <div class="field"><label><input type="checkbox" name="hidden" /> Hide from the public site</label></div>
          <div class="field">
            <label>Photo</label>
            <input id="itemImage" type="file" accept="image/*" />
            <img id="itemImagePreview" alt="" />
          </div>
          <div class="admin-form__actions">
            <button type="submit" class="btn">Save</button>
            <button type="button" id="itemDelete" class="btn btn--ghost" hidden>Delete item</button>
          </div>
          <p id="itemStatus" class="form-status" role="status"></p>
        </form>
      </div>
    </section>

    <!-- ENQUIRIES -------------------------------------------------------- -->
    <section id="tab-enquiries" class="admin-panel" role="tabpanel" hidden>
      <ul id="enquiryList" class="admin-enquiries" aria-label="Enquiries"></ul>
    </section>

    <!-- CONTENT ---------------------------------------------------------- -->
    <section id="tab-content" class="admin-panel" role="tabpanel" hidden>
      <form id="contentForm" class="admin-form">
        <fieldset>
          <legend>Contact details</legend>
          <div class="field"><label>Phone<input name="contactPhone" /></label>
            <label class="chk"><input type="checkbox" name="v_contactPhone" /> Show on site</label></div>
          <div class="field"><label>Email<input name="contactEmail" /></label>
            <label class="chk"><input type="checkbox" name="v_contactEmail" /> Show on site</label></div>
          <div class="field"><label>Address<input name="contactAddr" /></label>
            <label class="chk"><input type="checkbox" name="v_contactAddr" /> Show on site</label></div>
        </fieldset>
        <fieldset>
          <legend>Footer</legend>
          <div class="field"><label>Footer copy<input name="footerCopy" /></label></div>
          <div class="field"><label>Powered-by line<input name="footerPowered" /></label></div>
        </fieldset>
        <div class="admin-form__actions"><button type="submit" class="btn">Save content</button></div>
        <p id="contentStatus" class="form-status" role="status"></p>
      </form>
    </section>
  </main>

  <script src="assets/admin.js?v=1"></script>
</body>
</html>
