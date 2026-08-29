<?php
/**
 * product-image.php — upload a product photo.
 *
 * Auth + CSRF protected. Accepts a multipart POST with:
 *   - slug     : the product slug (kebab-case only; identifies the product)
 *   - image    : the uploaded file
 *   - basename : (optional) the saved filename stem. For gallery photos the
 *                caller passes a unique stem (e.g. "spider-lq3k9") so multiple
 *                photos coexist for one product. When omitted, the slug is used
 *                (legacy single-image behaviour — replaces the product's photo).
 *
 * Validates that the file is a real image (jpeg/png/webp) by inspection, caps
 * the size, then hands off to kap_save_upload_image() (in _guard.php) which
 * re-encodes it to WebP, downscales it, and replaces any prior image for that
 * slug. Returns { success, path }; the caller stores `path` in the product's
 * `img` field via products-save.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$MAX_BYTES = 4 * 1024 * 1024;   // 4 MB (also bounded by PHP upload_max_filesize)

// ---- slug (also the filename) — strict kebab-case, no path traversal --------
$slug = isset($_POST['slug']) ? (string) $_POST['slug'] : '';
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    json_out(array('success' => false, 'error' => 'Invalid product slug.'), 422);
}

// Optional saved filename stem — lets one product hold several photos. Must be
// kebab-case (no path traversal); defaults to the slug for the legacy path.
$basename = isset($_POST['basename']) ? (string) $_POST['basename'] : '';
if ($basename === '') {
    $basename = $slug;
} elseif (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $basename)) {
    json_out(array('success' => false, 'error' => 'Invalid image name.'), 422);
}

// ---- file present & uploaded ok --------------------------------------------
if (empty($_FILES['image']) || !isset($_FILES['image']['error'])) {
    json_out(array('success' => false, 'error' => 'No file received.'), 422);
}
$f = $_FILES['image'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    $msg = ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
        ? 'File is too large for the server.'
        : 'Upload failed (code ' . (int) $f['error'] . ').';
    json_out(array('success' => false, 'error' => $msg), 422);
}
if ($f['size'] <= 0 || $f['size'] > $MAX_BYTES) {
    json_out(array('success' => false, 'error' => 'Image must be between 1 byte and 4 MB.'), 422);
}
if (!is_uploaded_file($f['tmp_name'])) {
    json_out(array('success' => false, 'error' => 'Invalid upload.'), 400);
}

// ---- verify it's actually an image, pick a safe extension -------------------
$info = @getimagesize($f['tmp_name']);
if ($info === false) {
    json_out(array('success' => false, 'error' => 'That file is not a valid image.'), 422);
}
$extByType = array(
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
);
if (!isset($extByType[$info[2]])) {
    json_out(array('success' => false, 'error' => 'Use a JPG, PNG or WebP image.'), 422);
}
// ---- re-encode to WebP (downscaled) + replace any prior image for this slug --
$savedExt = kap_save_upload_image($f['tmp_name'], KAP_WEB_ROOT . '/assets/img/products', $basename, $info, 1600);
if ($savedExt === false) {
    json_out(array('success' => false, 'error' => 'Could not save the image.'), 500);
}

json_out(array(
    'success' => true,
    'path'    => 'assets/img/products/' . $basename . '.' . $savedExt,
));
