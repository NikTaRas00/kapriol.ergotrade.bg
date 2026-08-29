<?php
/**
 * items-image.php — upload an item photo. Auth + CSRF. Multipart POST:
 *   - slug     : the item slug (kebab-case; identifies the item)
 *   - image    : the uploaded file
 *   - basename : (optional) saved filename stem. For gallery photos pass a unique
 *                stem (e.g. "widget-lq3k9") so several photos coexist. Omitted →
 *                the slug is used (single-image behaviour; replaces the photo).
 * Validates a real image, caps size, then app_save_upload_image() re-encodes to
 * WebP + downscales + replaces any prior file. Returns { success, path }.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$MAX_BYTES = 4 * 1024 * 1024;

$slug = isset($_POST['slug']) ? (string) $_POST['slug'] : '';
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) { json_out(array('success' => false, 'error' => 'Invalid slug.'), 422); }

$basename = isset($_POST['basename']) ? (string) $_POST['basename'] : '';
if ($basename === '') { $basename = $slug; }
elseif (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $basename)) { json_out(array('success' => false, 'error' => 'Invalid image name.'), 422); }

if (empty($_FILES['image']) || !isset($_FILES['image']['error'])) { json_out(array('success' => false, 'error' => 'No file received.'), 422); }
$f = $_FILES['image'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    $msg = ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) ? 'File is too large for the server.' : 'Upload failed (code ' . (int) $f['error'] . ').';
    json_out(array('success' => false, 'error' => $msg), 422);
}
if ($f['size'] <= 0 || $f['size'] > $MAX_BYTES) { json_out(array('success' => false, 'error' => 'Image must be between 1 byte and 4 MB.'), 422); }
if (!is_uploaded_file($f['tmp_name'])) { json_out(array('success' => false, 'error' => 'Invalid upload.'), 400); }

$info = @getimagesize($f['tmp_name']);
if ($info === false) { json_out(array('success' => false, 'error' => 'That file is not a valid image.'), 422); }
$extByType = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp');
if (!isset($extByType[$info[2]])) { json_out(array('success' => false, 'error' => 'Use a JPG, PNG or WebP image.'), 422); }

$savedExt = app_save_upload_image($f['tmp_name'], APP_WEB_ROOT . '/assets/img/items', $basename, $info, 1600);
if ($savedExt === false) { json_out(array('success' => false, 'error' => 'Could not save the image.'), 500); }

json_out(array('success' => true, 'path' => 'assets/img/items/' . $basename . '.' . $savedExt));
