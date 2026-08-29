<?php
/**
 * product-create.php — append a new product to products.json.
 *
 * Auth + CSRF protected. Requires a unique kebab-case `slug`, a `category`
 * from the fixed list of six, and a non-empty `name`. All other allowlisted
 * fields are optional and sanitised the same way as products-save. Writes are
 * lock-protected and atomic. Returns { success, product }.
 */
require __DIR__ . '/_guard.php';
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

/* ---- Sanitisers (same rules as products-save) ---------------------------- */
function clean_str($v, $max = 5000) {
    $v = is_scalar($v) ? (string) $v : '';
    $v = str_replace(array("\r\n", "\r"), "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    $v = trim($v);
    if (mb_strlen($v) > $max) { $v = mb_substr($v, 0, $max); }
    return $v;
}
function clean_str_array($v, $maxItems = 100, $maxLen = 2000) {
    if (!is_array($v)) { return array(); }
    $out = array();
    foreach ($v as $item) {
        $s = clean_str($item, $maxLen);
        if ($s !== '') { $out[] = $s; }
        if (count($out) >= $maxItems) { break; }
    }
    return $out;
}

/* ---- Required: slug, category, name -------------------------------------- */
$slug = clean_str(isset($in['slug']) ? $in['slug'] : '', 100);
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    json_out(array('success' => false, 'error' => 'Slug must be lowercase letters, numbers and hyphens (e.g. dune-rock-green).'), 422);
}
$category = clean_str(isset($in['category']) ? $in['category'] : '', 60);
if (!in_array($category, $CATEGORIES, true)) {
    json_out(array('success' => false, 'error' => 'Unknown category.'), 422);
}
$name = clean_str(isset($in['name']) ? $in['name'] : '', 300);
if ($name === '') {
    json_out(array('success' => false, 'error' => 'Name is required.'), 422);
}

/* ---- Build the record from allowlisted fields ---------------------------- */
$record = array('category' => $category, 'slug' => $slug, 'name' => $name);
if (array_key_exists('description', $in)) { $record['description'] = clean_str($in['description'], 4000); }
if (array_key_exists('fullDescription', $in)) { $record['fullDescription'] = clean_str($in['fullDescription'], 20000); }
if (array_key_exists('productLine', $in)) { $record['productLine'] = clean_str($in['productLine'], 300); }
if (array_key_exists('material', $in))    { $record['material']    = clean_str($in['material'], 500); }
if (array_key_exists('img', $in))         { $record['img']         = clean_str($in['img'], 500); }
if (array_key_exists('images', $in) && is_array($in['images'])) {
    $imgs = array();
    foreach ($in['images'] as $s) {
        $s = clean_str($s, 500);
        if (preg_match('#^assets/img/products/[A-Za-z0-9._-]+\.(webp|jpe?g|png)$#', $s)) { $imgs[] = $s; }
        if (count($imgs) >= 30) break;
    }
    $record['images'] = $imgs;
}
if (array_key_exists('isNew', $in))       { $record['isNew']       = (bool) $in['isNew']; }

foreach (array('features', 'overview', 'sizes', 'colors', 'standards') as $k) {
    if (array_key_exists($k, $in)) { $record[$k] = clean_str_array($in[$k]); }
}
foreach (array('performance', 'safetyClass', 'technologies') as $k) {
    if (array_key_exists($k, $in)) { $record[$k] = clean_str_array($in[$k], 100, 100); }
}
if (array_key_exists('articleCodes', $in) && is_array($in['articleCodes'])) {
    $codes = array();
    foreach ($in['articleCodes'] as $row) {
        if (!is_array($row)) { continue; }
        $code = clean_str(isset($row['code']) ? $row['code'] : '', 100);
        $size = clean_str(isset($row['size']) ? $row['size'] : '', 100);
        if ($code !== '' || $size !== '') { $codes[] = array('code' => $code, 'size' => $size); }
        if (count($codes) >= 200) { break; }
    }
    $record['articleCodes'] = $codes;
}

/* ---- Read, ensure slug is unique, append, atomic write ------------------- */
$all = read_json(KAP_PRODUCTS, null);
if (!is_array($all)) {
    json_out(array('success' => false, 'error' => 'Catalog file is missing or corrupt.'), 500);
}
foreach ($all as $p) {
    if (isset($p['slug']) && $p['slug'] === $slug) {
        json_out(array('success' => false, 'error' => 'A product with that slug already exists.'), 409);
    }
}

$all[] = $record;

if (atomic_write_json(KAP_PRODUCTS, $all)) {
    json_out(array('success' => true, 'product' => $record), 200);
}
json_out(array('success' => false, 'error' => 'Save failed (catalog not writable).'), 500);
