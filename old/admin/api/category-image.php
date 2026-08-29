<?php
/**
 * category-image.php — upload a landing image for one of the six categories.
 *
 * Auth + CSRF protected. Accepts a multipart POST with:
 *   - slug   : the category id (must be one of the six fixed ids)
 *   - image  : the uploaded file (JPG/PNG/WebP)
 *
 * Validates it's a real image, caps the size, saves to
 * assets/img/categories/<id>.<ext> (replacing any prior file for that id), and
 * returns { success, path }. The caller stores `path` in content.json's
 * `categories` map via content-save. Mirrors attr-image.php / product-image.php.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$MAX_BYTES = 4 * 1024 * 1024;   // 4 MB

$CAT_IDS = array(
    'footwear', 'footwear-accessories', 'clothing',
    'clothing-accessories', 'ppe', 'ppe-accessories',
);

$slug = isset($_POST['slug']) ? (string) $_POST['slug'] : '';
if (!in_array($slug, $CAT_IDS, true)) {
    json_out(array('success' => false, 'error' => 'Unknown category.'), 422);
}

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
// Re-encode to WebP (downscaled) + replace any prior image for this category.
$savedExt = kap_save_upload_image($f['tmp_name'], KAP_WEB_ROOT . '/assets/img/categories', $slug, $info, 1600);
if ($savedExt === false) {
    json_out(array('success' => false, 'error' => 'Could not save the image.'), 500);
}

json_out(array(
    'success' => true,
    'path'    => 'assets/img/categories/' . $slug . '.' . $savedExt,
));
