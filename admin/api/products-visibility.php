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
 * This is a bulk edit of the whole catalog, so it deliberately touches ONLY
 * the `hidden` key on each record and leaves every other field alone — the
 * same merge discipline products-save.php uses for a single product. Nothing
 * is deleted: hiding is reversible by posting the opposite value.
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

$all = read_json(KAP_PRODUCTS, null);
if (!is_array($all)) {
    json_out(array('success' => false, 'error' => 'Catalog file is missing or corrupt.'), 500);
}

$changed = 0;
foreach ($all as $i => $p) {
    if (!is_array($p)) { continue; }
    $was = !empty($p['hidden']);
    if ($was !== $hidden) { $changed++; }
    $all[$i]['hidden'] = $hidden;
}

if (atomic_write_json(KAP_PRODUCTS, $all)) {
    json_out(array(
        'success' => true,
        'hidden'  => $hidden,
        'changed' => $changed,
        'total'   => count($all),
    ), 200);
}
json_out(array('success' => false, 'error' => 'Save failed (catalog not writable).'), 500);
