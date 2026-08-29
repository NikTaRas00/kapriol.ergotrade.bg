<?php
/**
 * items-save.php — merge-by-slug write endpoint (per-item edit).
 * Auth + CSRF + allowlist + atomic write. Copy + adapt the allowlist per entity.
 *
 * Body: a single item object carrying at least { slug }. Only allowlisted fields
 * are overwritten on the matching record; every other stored key is preserved.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { json_out(array('success' => false, 'error' => 'Invalid payload.'), 422); }

/* ---- Sanitisers ---------------------------------------------------------- */
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
function clean_image_paths($v, $max = 30) {
    if (!is_array($v)) { return array(); }
    $out = array();
    foreach ($v as $s) {
        $s = clean_str($s, 500);
        if (preg_match('#^assets/img/items/[A-Za-z0-9._-]+\.(webp|jpe?g|png)$#', $s)) { $out[] = $s; }
        if (count($out) >= $max) { break; }
    }
    return $out;
}

/* ---- Identify the record ------------------------------------------------- */
$slug = clean_str(isset($in['slug']) ? $in['slug'] : '', 100);
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    json_out(array('success' => false, 'error' => 'Invalid slug.'), 422);
}

$all = read_json(APP_ITEMS, null);
if (!is_array($all)) { json_out(array('success' => false, 'error' => 'Data file missing or corrupt.'), 500); }

$idx = -1;
foreach ($all as $i => $p) { if (isset($p['slug']) && $p['slug'] === $slug) { $idx = $i; break; } }
if ($idx < 0) { json_out(array('success' => false, 'error' => 'No item with that slug.'), 404); }

/* ---- Overwrite ONLY allowlisted fields ----------------------------------- */
$rec = $all[$idx];

// Simple strings.
foreach (array(
    'name'            => 300,
    'description'     => 4000,
    'fullDescription' => 20000,
    'productLine'     => 300,
    'material'        => 500,
    'img'             => 500,
    'imgV'            => 64,
) as $k => $max) {
    if (array_key_exists($k, $in)) { $rec[$k] = clean_str($in[$k], $max); }
}
// Booleans.
foreach (array('isNew', 'hidden') as $k) {
    if (array_key_exists($k, $in)) { $rec[$k] = (bool) $in[$k]; }
}
// String arrays.
foreach (array('features', 'overview', 'sizes', 'colors', 'standards') as $k) {
    if (array_key_exists($k, $in)) { $rec[$k] = clean_str_array($in[$k]); }
}
// Image gallery (validated paths).
if (array_key_exists('images', $in)) { $rec['images'] = clean_image_paths($in['images']); }

// category is NOT edited here (identity-ish); use a dedicated move endpoint if needed.

$all[$idx] = $rec;

if (atomic_write_json(APP_ITEMS, $all)) {
    json_out(array('success' => true, 'item' => $rec), 200);
}
json_out(array('success' => false, 'error' => 'Save failed (not writable).'), 500);
