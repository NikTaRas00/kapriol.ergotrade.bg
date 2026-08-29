<?php
/**
 * items-image-delete.php — delete ONE item photo file from the gallery.
 * Auth + CSRF. Takes { path } (root-relative, inside assets/img/items). Only
 * removes the file; the caller persists the updated images[] via items-save.
 * Best-effort — a missing file is treated as success so the UI never sticks.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || empty($in['path'])) { json_out(array('success' => false, 'error' => 'Missing image path.'), 422); }
$path = (string) $in['path'];

// Strictly a file inside assets/img/items — no traversal, no other dirs.
if (!preg_match('#^assets/img/items/[A-Za-z0-9._-]+\.(webp|jpe?g|png)$#', $path)) {
    json_out(array('success' => false, 'error' => 'Invalid image path.'), 422);
}

$full = APP_WEB_ROOT . '/' . $path;
if (is_file($full)) { @unlink($full); }

json_out(array('success' => true, 'path' => $path), 200);
