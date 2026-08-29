<?php
/**
 * product-image-delete.php — delete ONE product photo file from the gallery.
 *
 * Auth + CSRF protected. Takes { slug, path } where `path` is a root-relative
 * file inside assets/img/products (e.g. "assets/img/products/spider-2.webp").
 * Only removes the file on disk; the caller is responsible for persisting the
 * updated `images[]` array via products-save. Best-effort — a missing file is
 * treated as success so the UI never gets stuck.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || empty($in['path'])) {
    json_out(array('success' => false, 'error' => 'Missing image path.'), 422);
}
$path = (string) $in['path'];

// Strictly a file inside assets/img/products — no traversal, no other dirs.
if (!preg_match('#^assets/img/products/[A-Za-z0-9._-]+\.(webp|jpe?g|png)$#', $path)) {
    json_out(array('success' => false, 'error' => 'Invalid image path.'), 422);
}

$full = KAP_WEB_ROOT . '/' . $path;
if (is_file($full)) { @unlink($full); }

json_out(array('success' => true, 'path' => $path), 200);
