<?php
/**
 * items-create.php — append a new item to items.json.
 *
 * Auth + CSRF. Requires a unique kebab `slug`, a `category` from the fixed set,
 * and a non-empty `name`. All other allowlisted fields optional + sanitised.
 * Atomic write. Returns { success, item }.  ADAPT: $CATEGORIES + the allowlist.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$CATEGORIES = array(/* 'cat-a', 'cat-b', ... */);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { json_out(array('success' => false, 'error' => 'Invalid payload.'), 422); }

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
    foreach ($v as $item) { $s = clean_str($item, $maxLen); if ($s !== '') { $out[] = $s; } if (count($out) >= $maxItems) break; }
    return $out;
}

$slug = clean_str(isset($in['slug']) ? $in['slug'] : '', 100);
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
    json_out(array('success' => false, 'error' => 'Slug must be lowercase letters, numbers and hyphens.'), 422);
}
$category = clean_str(isset($in['category']) ? $in['category'] : '', 60);
if (!empty($CATEGORIES) && !in_array($category, $CATEGORIES, true)) {
    json_out(array('success' => false, 'error' => 'Unknown category.'), 422);
}
$name = clean_str(isset($in['name']) ? $in['name'] : '', 300);
if ($name === '') { json_out(array('success' => false, 'error' => 'Name is required.'), 422); }

$record = array('category' => $category, 'slug' => $slug, 'name' => $name);
foreach (array('description' => 4000, 'fullDescription' => 20000, 'material' => 500, 'img' => 500, 'imgV' => 64) as $k => $max) {
    if (array_key_exists($k, $in)) { $record[$k] = clean_str($in[$k], $max); }
}
foreach (array('isNew', 'hidden') as $k) { if (array_key_exists($k, $in)) { $record[$k] = (bool) $in[$k]; } }
foreach (array('features', 'overview', 'sizes', 'colors', 'standards') as $k) {
    if (array_key_exists($k, $in)) { $record[$k] = clean_str_array($in[$k]); }
}
if (array_key_exists('images', $in) && is_array($in['images'])) {
    $imgs = array();
    foreach ($in['images'] as $s) {
        $s = clean_str($s, 500);
        if (preg_match('#^assets/img/items/[A-Za-z0-9._-]+\.(webp|jpe?g|png)$#', $s)) { $imgs[] = $s; }
        if (count($imgs) >= 30) break;
    }
    $record['images'] = $imgs;
}

$all = read_json(APP_ITEMS, null);
if (!is_array($all)) { json_out(array('success' => false, 'error' => 'Data file missing or corrupt.'), 500); }
foreach ($all as $p) {
    if (isset($p['slug']) && $p['slug'] === $slug) { json_out(array('success' => false, 'error' => 'That slug already exists.'), 409); }
}

$all[] = $record;
if (atomic_write_json(APP_ITEMS, $all)) { json_out(array('success' => true, 'item' => $record), 200); }
json_out(array('success' => false, 'error' => 'Save failed (not writable).'), 500);
