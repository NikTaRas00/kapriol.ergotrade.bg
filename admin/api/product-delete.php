<?php
/**
 * product-delete.php — remove a product.
 *
 * Auth + CSRF protected. Takes { slug }. The product row is deleted and its
 * list, spec-code and article-code rows go with it via ON DELETE CASCADE —
 * no orphan sweep needed.
 *
 * The product's image FILES are then unlinked as well, because they are not in
 * the database and would otherwise sit on an inode-limited host forever. That
 * is best-effort and deliberately last: losing a photo is recoverable, and a
 * failed unlink must not roll back a completed delete.
 *
 * NB deletion is not the normal way to take a product off the site — `hidden`
 * is, and it is reversible. This exists for genuine mistakes.
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

$pdo = kap_db();
try {
    if (!kap_product_delete($pdo, $slug)) {
        json_out(array('success' => false, 'error' => 'Product not found.'), 404);
    }
} catch (Exception $e) {
    error_log('kapriol product-delete: ' . $e->getMessage());
    json_out(array('success' => false, 'error' => 'Delete failed.'), 500);
}

// Best-effort image cleanup (only for a slug that is a safe filename stem).
if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    $dir = KAP_WEB_ROOT . '/assets/img/products';
    foreach (array('jpg', 'jpeg', 'png', 'webp') as $ext) {
        $f = $dir . '/' . $slug . '.' . $ext;
        if (is_file($f)) { @unlink($f); }
    }
}

json_out(array('success' => true, 'slug' => $slug), 200);
