<?php
/**
 * product-create.php — add a new product.
 *
 * Auth + CSRF protected. Requires a unique kebab-case `slug`, a `category`
 * from the fixed six, and a non-empty `name`. Everything else is optional and
 * sanitised exactly as products-save does — literally the same code path, so
 * create and save can never disagree about how a field is stored.
 *
 * The slug is taken VERBATIM from the form and is never generated from the
 * name: slugs are opaque (dune-jaq vs "Dune Jaq", and worse elsewhere), they
 * are the primary key, and they are the stem of the product's image filenames.
 *
 * Returns { success, product }.
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_fields.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$CATEGORIES = array(
    'footwear', 'footwear-accessories', 'clothing',
    'clothing-accessories', 'ppe', 'ppe-accessories',
);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_out(array('success' => false, 'error' => 'Invalid payload.'), 422);
}

$slug = kap_clean_str(isset($in['slug']) ? $in['slug'] : '', 100);
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    json_out(array('success' => false, 'error' => 'Slug must be lowercase letters, numbers and hyphens (e.g. dune-rock-green).'), 422);
}
$category = kap_clean_str(isset($in['category']) ? $in['category'] : '', 60);
if (!in_array($category, $CATEGORIES, true)) {
    json_out(array('success' => false, 'error' => 'Unknown category.'), 422);
}
$fields = kap_product_fields($in);
if (!isset($fields['name']) || $fields['name'] === '') {
    json_out(array('success' => false, 'error' => 'Name is required.'), 422);
}

$pdo = kap_db();
try {
    $err = kap_product_create($pdo, $slug, $category, $fields);
    if ($err !== '') {
        // "already exists" is a conflict; anything else is bad input.
        json_out(array('success' => false, 'error' => $err),
                 stripos($err, 'exists') !== false ? 409 : 422);
    }
    $product = kap_product($pdo, $slug);
} catch (Exception $e) {
    error_log('kapriol product-create: ' . $e->getMessage());
    json_out(array('success' => false, 'error' => 'Create failed.'), 500);
}

json_out(array('success' => true, 'product' => $product), 200);
