<?php
/**
 * content-save.php — write the editable site content (copy + best sellers).
 * Auth + CSRF protected. Only known keys are stored; values are sanitised.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_out(array('success' => false, 'error' => 'Invalid payload.'), 422);
}

function clean_str($v, $max = 2000) {
    $v = is_scalar($v) ? (string) $v : '';
    $v = str_replace(array("\r\n", "\r"), "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    $v = trim($v);
    if (mb_strlen($v) > $max) { $v = mb_substr($v, 0, $max); }
    return $v;
}

$COPY_KEYS = array(
    'heroLabel', 'heroH1', 'heroSub',
    'aboutHeading', 'aboutQuote', 'aboutP1', 'aboutP2', 'aboutP3',
    'contactPhone', 'contactEmail', 'contactAddr',
    'bestEyebrow',
    'footerCopy', 'footerPowered',
);

// Contact rows the admin can hide on the public page. Stored as booleans;
// a missing key means "shown" on the public side.
$VISIBLE_KEYS = array('contactPhone', 'contactEmail', 'contactAddr');

$copyIn = isset($in['copy']) && is_array($in['copy']) ? $in['copy'] : array();
$copy = array();
foreach ($COPY_KEYS as $k) {
    $copy[$k] = clean_str(isset($copyIn[$k]) ? $copyIn[$k] : '');
}

$best = array();
$bestIn = isset($in['best']) && is_array($in['best']) ? $in['best'] : array();
for ($i = 0; $i < 3; $i++) {
    $row = isset($bestIn[$i]) && is_array($bestIn[$i]) ? $bestIn[$i] : array();
    // A slot bound to a catalog product stores its slug + a snapshot of the
    // product's name/img/imgV (so the landing page needs no products.json fetch).
    $best[] = array(
        'slug' => clean_str(isset($row['slug']) ? $row['slug'] : '', 200),
        'img'  => clean_str(isset($row['img']) ? $row['img'] : '', 500),
        'name' => clean_str(isset($row['name']) ? $row['name'] : '', 300),
        'imgV' => clean_str(isset($row['imgV']) ? $row['imgV'] : '', 64),
    );
}

// Per-category landing image (id → { img, imgV }). Only the six known category
// ids are accepted; a category with no image is simply omitted.
$CAT_IDS = array(
    'footwear', 'footwear-accessories', 'clothing',
    'clothing-accessories', 'ppe', 'ppe-accessories',
);
$catsIn = isset($in['categories']) && is_array($in['categories']) ? $in['categories'] : array();
$cats = array();
foreach ($CAT_IDS as $cid) {
    $row = isset($catsIn[$cid]) && is_array($catsIn[$cid]) ? $catsIn[$cid] : array();
    $img = clean_str(isset($row['img']) ? $row['img'] : '', 500);
    if ($img === '') { continue; }
    $cats[$cid] = array(
        'img'  => $img,
        'imgV' => clean_str(isset($row['imgV']) ? $row['imgV'] : '', 64),
    );
}

$visIn = isset($in['visible']) && is_array($in['visible']) ? $in['visible'] : array();
$visible = array();
foreach ($VISIBLE_KEYS as $k) {
    // default true so an older client that posts no `visible` block shows all rows
    $visible[$k] = array_key_exists($k, $visIn) ? (bool) $visIn[$k] : true;
}

$ok = atomic_write_json(KAP_CONTENT, array(
    'copy' => $copy, 'best' => $best, 'categories' => $cats, 'visible' => $visible,
));
json_out(
    $ok ? array('success' => true)
        : array('success' => false, 'error' => 'Content file is not writable.'),
    $ok ? 200 : 500
);
