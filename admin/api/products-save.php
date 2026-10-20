<?php
/**
 * products-save.php — merge-save one product.
 *
 * Auth + CSRF protected. Only allowlisted fields are written (see _fields.php);
 * every other column, every untouched child collection and the whole frozen
 * spec_json blob are left exactly as they are. The record is located by its
 * read-only slug.
 *
 * This is the endpoint the migration was really for. It used to read all 333
 * products, mutate one array entry and re-encode 977 KB — on EVERY save,
 * including the per-photo autosave, with no cross-request locking, so two
 * overlapping saves were last-writer-wins across the entire catalogue. It is
 * now a handful of statements inside one transaction, touching one product.
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_fields.php';
require __DIR__ . '/_pricing.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || empty($in['slug'])) {
    json_out(array('success' => false, 'error' => 'Missing product slug.'), 422);
}
$slug = (string) $in['slug'];

$fields = kap_product_fields($in);

/* Category is the one editable field that is NOT in the allowlist in
   _fields.php: moving a product also re-sorts it to the end of the new range,
   so it goes through kap_product_set_category() instead of the merge-save.
   Only the six real ranges are accepted. */
$newCat = null;
if (array_key_exists('category', $in)) {
    $newCat = kap_clean_str($in['category'], 60);
    if (!in_array($newCat, kap_editor_categories(), true)) {
        json_out(array('success' => false, 'error' => 'Unknown category.'), 422);
    }
}
if (!$fields && $newCat === null) {
    json_out(array('success' => false, 'error' => 'Nothing to save.'), 422);
}

$pdo = kap_db();
try {
    if ($fields && !kap_product_save($pdo, $slug, $fields)) {
        json_out(array('success' => false, 'error' => 'Product not found.'), 404);
    }
    if ($newCat !== null) {
        $err = kap_product_set_category($pdo, $slug, $newCat);
        if ($err !== '') {
            json_out(array('success' => false, 'error' => $err), $err === 'Product not found.' ? 404 : 422);
        }
    }
    $product = kap_product($pdo, $slug);
} catch (Exception $e) {
    error_log('kapriol products-save: ' . $e->getMessage());
    json_out(array('success' => false, 'error' => 'Save failed.'), 500);
}

json_out(array('success' => true, 'product' => $product), 200);
