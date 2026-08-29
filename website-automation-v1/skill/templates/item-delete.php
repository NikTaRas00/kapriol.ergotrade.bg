<?php
/**
 * items-delete.php — remove an item from items.json + delete its image file(s).
 * Auth + CSRF. Takes { slug }. Atomic write. Best-effort image cleanup.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || empty($in['slug'])) { json_out(array('success' => false, 'error' => 'Missing slug.'), 422); }
$slug = (string) $in['slug'];

$all = read_json(APP_ITEMS, null);
if (!is_array($all)) { json_out(array('success' => false, 'error' => 'Data file missing or corrupt.'), 500); }

$idx = -1;
foreach ($all as $i => $p) { if (isset($p['slug']) && $p['slug'] === $slug) { $idx = $i; break; } }
if ($idx < 0) { json_out(array('success' => false, 'error' => 'Item not found.'), 404); }

array_splice($all, $idx, 1);
if (!atomic_write_json(APP_ITEMS, $all)) { json_out(array('success' => false, 'error' => 'Save failed (not writable).'), 500); }

// Best-effort cleanup of the item's image (only if the slug is a safe name).
if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    $dir = APP_WEB_ROOT . '/assets/img/items';
    foreach (array('jpg', 'jpeg', 'png', 'webp') as $ext) {
        $f = $dir . '/' . $slug . '.' . $ext;
        if (is_file($f)) { @unlink($f); }
    }
}

json_out(array('success' => true, 'slug' => $slug), 200);
