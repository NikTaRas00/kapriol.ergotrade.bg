<?php
/**
 * products-visibility.php — set the `hidden` flag on EVERY product at once.
 *
 * Backs the "Hide all products" / "Show all products" button on the Products
 * tab. Auth + CSRF protected, same as every other write.
 *
 * Payload: { "hidden": true|false }
 * Returns: { success, hidden, changed, total }
 *
 * Now a single UPDATE. The old version decoded 977 KB of JSON, looped 333
 * records and re-encoded the lot; nothing but the flag was ever meant to
 * change, and now nothing else can. Hiding stays reversible — no row is
 * deleted, so posting the opposite value restores the previous state.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || !array_key_exists('hidden', $in)) {
    json_out(array('success' => false, 'error' => 'Missing hidden flag.'), 422);
}
$hidden = (bool) $in['hidden'];

$pdo = kap_db();
try {
    $r = kap_products_visibility($pdo, $hidden);
} catch (Exception $e) {
    error_log('kapriol products-visibility: ' . $e->getMessage());
    json_out(array('success' => false, 'error' => 'Bulk update failed.'), 500);
}

json_out(array(
    'success' => true,
    'hidden'  => $hidden,
    'changed' => $r['changed'],
    'total'   => $r['total'],
), 200);
