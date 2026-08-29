<?php
/**
 * products-save.php — merge-save one product into products.json.
 *
 * Auth + CSRF protected. Only the allowlisted fields are written; every other
 * key on the existing record is passed through untouched. The record is located
 * by its (read-only) slug. Writes are lock-protected and atomic.
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

/* ---- Sanitise the incoming fields ---------------------------------------- */
function clean_str($v, $max = 5000) {
    $v = is_scalar($v) ? (string) $v : '';
    $v = str_replace(array("\r\n", "\r"), "\n", $v);
    // strip control chars except newline + tab
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
// Gallery image paths: only accept root-relative files inside assets/img/products.
function clean_image_paths($v, $maxItems = 30) {
    if (!is_array($v)) { return array(); }
    $out = array();
    foreach ($v as $item) {
        $s = clean_str($item, 500);
        if (preg_match('#^assets/img/products/[A-Za-z0-9._-]+\.(webp|jpe?g|png)$#', $s)) { $out[] = $s; }
        if (count($out) >= $maxItems) { break; }
    }
    return $out;
}

$fields = array();
if (array_key_exists('name', $in))        { $fields['name']        = clean_str($in['name'], 300); }
if (array_key_exists('description', $in)) { $fields['description'] = clean_str($in['description'], 4000); }
if (array_key_exists('fullDescription', $in)) { $fields['fullDescription'] = clean_str($in['fullDescription'], 20000); }
if (array_key_exists('productLine', $in)) { $fields['productLine'] = clean_str($in['productLine'], 300); }
if (array_key_exists('material', $in))    { $fields['material']    = clean_str($in['material'], 500); }
if (array_key_exists('img', $in))         { $fields['img']         = clean_str($in['img'], 500); }
if (array_key_exists('images', $in))      { $fields['images']      = clean_image_paths($in['images']); }
if (array_key_exists('imgV', $in))        { $fields['imgV']        = (int) $in['imgV']; }
if (array_key_exists('isNew', $in))       { $fields['isNew']       = (bool) $in['isNew']; }

foreach (array('features', 'overview', 'sizes', 'colors', 'standards') as $k) {
    if (array_key_exists($k, $in)) { $fields[$k] = clean_str_array($in[$k]); }
}

// Footwear spec codes (Performance / Safety class / Technologies). Stored as
// plain code arrays per product; their names/pictograms live in attributes.json.
foreach (array('performance', 'safetyClass', 'technologies') as $k) {
    if (array_key_exists($k, $in)) { $fields[$k] = clean_str_array($in[$k], 100, 100); }
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
    $fields['articleCodes'] = $codes;
}

if (!$fields) {
    json_out(array('success' => false, 'error' => 'Nothing to save.'), 422);
}

/* ---- Read, merge, atomic write ------------------------------------------
   We read then write via temp+rename (atomic_write_json) so a concurrent
   public read never sees partial JSON. We deliberately do NOT hold an open
   handle across the write: on Windows an open handle blocks the rename, and
   for a single shared-admin login there are no concurrent writers to serialise. */
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

// Merge: overwrite only allowlisted keys, keep everything else.
foreach ($fields as $k => $v) { $all[$idx][$k] = $v; }

if (atomic_write_json(KAP_PRODUCTS, $all)) {
    json_out(array('success' => true, 'product' => $all[$idx]), 200);
}
json_out(array('success' => false, 'error' => 'Save failed (catalog not writable).'), 500);
