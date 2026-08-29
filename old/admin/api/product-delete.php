<?php
/**
 * product-delete.php — remove a product from products.json.
 *
 * Auth + CSRF protected. Takes { slug }, removes the matching record, and also
 * deletes that slug's product image file(s) so we don't orphan them on the
 * inode-limited host. Writes are lock-protected and atomic.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || empty($in['slug'])) {
    json_out(array('success' => false, 'error' => 'Missing product slug.'), 422);
}
$slug = (string) $in['slug'];

$all = read_json(KAP_PRODUCTS, null);
if (!is_array($all)) {
    json_out(array('success' => false, 'error' => 'Catalog file is missing or corrupt.'), 500);
}

$idx = -1;
foreach ($all as $i => $p) {
    if (isset($p['slug']) && $p['slug'] === $slug) { $idx = $i; break; }
}
if ($idx < 0) {
    json_out(array('success' => false, 'error' => 'Product not found.'), 404);
}

array_splice($all, $idx, 1);

if (!atomic_write_json(KAP_PRODUCTS, $all)) {
    json_out(array('success' => false, 'error' => 'Save failed (catalog not writable).'), 500);
}

// Best-effort cleanup of the product's image (only if the slug is a safe name).
if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    $dir = KAP_WEB_ROOT . '/assets/img/products';
    foreach (array('jpg', 'jpeg', 'png', 'webp') as $ext) {
        $f = $dir . '/' . $slug . '.' . $ext;
        if (is_file($f)) { @unlink($f); }
    }
}

json_out(array('success' => true, 'slug' => $slug), 200);
