<?php
/**
 * site-image.php — upload one of the home page's marketing images.
 *
 * Auth + CSRF protected. Accepts a multipart POST with:
 *   - slot   : which image (hero | slide1 | slide2 | slide3 | why)
 *   - image  : the uploaded file (JPG/PNG/WebP)
 *
 * Validates it is a real image, caps the size, hands off to the shared
 * kap_save_upload_image() (WebP re-encode, EXIF orientation, downscale to
 * 1600px, replaces any prior file for that slot) and returns { success, path }.
 * The caller stores `path` in content.json's `home` block via content-save.
 * Mirrors category-image.php / attr-image.php / product-image.php.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$MAX_BYTES = 4 * 1024 * 1024;   // 4 MB

$SLOTS = array('hero', 'slide1', 'slide2', 'slide3', 'why');

$slot = isset($_POST['slot']) ? (string) $_POST['slot'] : '';
if (!in_array($slot, $SLOTS, true)) {
    json_out(array('success' => false, 'error' => 'Unknown image slot.'), 422);
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

// These are wide banner images, so allow a larger long edge than a product cut-out.
$savedExt = kap_save_upload_image($f['tmp_name'], KAP_WEB_ROOT . '/assets/img/site', $slot, $info, 1920);
if ($savedExt === false) {
    json_out(array('success' => false, 'error' => 'Could not save the image.'), 500);
}

json_out(array(
    'success' => true,
    'path'    => 'assets/img/site/' . $slot . '.' . $savedExt,
));
