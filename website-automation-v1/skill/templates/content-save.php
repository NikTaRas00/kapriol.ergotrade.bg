<?php
/**
 * content-save.php — write the editable site content (copy + home + categories).
 * Auth + CSRF. Only known keys are stored; values sanitised. Whole-file replace
 * (rebuilds the document from allowlisted keys). ADAPT the key lists per project.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { json_out(array('success' => false, 'error' => 'Invalid payload.'), 422); }

function clean_str($v, $max = 2000) {
    $v = is_scalar($v) ? (string) $v : '';
    $v = str_replace(array("\r\n", "\r"), "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    $v = trim($v);
    if (mb_strlen($v) > $max) { $v = mb_substr($v, 0, $max); }
    return $v;
}

// --- Simple copy fields (mirror in the admin form + the public JS) -----------
$COPY_KEYS    = array('contactPhone', 'contactEmail', 'contactAddr', 'footerCopy', 'footerPowered');
// Contact rows the admin can hide. Stored as booleans; missing key => shown.
$VISIBLE_KEYS = array('contactPhone', 'contactEmail', 'contactAddr');
// Category ids that may carry a landing image (must match the public category set).
$CAT_IDS      = array(/* 'cat-a', 'cat-b', ... */);

$copyIn = isset($in['copy']) && is_array($in['copy']) ? $in['copy'] : array();
$copy = array();
foreach ($COPY_KEYS as $k) { $copy[$k] = clean_str(isset($copyIn[$k]) ? $copyIn[$k] : ''); }

$visIn = isset($in['visible']) && is_array($in['visible']) ? $in['visible'] : array();
$visible = array();
foreach ($VISIBLE_KEYS as $k) { $visible[$k] = array_key_exists($k, $visIn) ? (bool) $visIn[$k] : true; }

$catsIn = isset($in['categories']) && is_array($in['categories']) ? $in['categories'] : array();
$cats = array();
foreach ($CAT_IDS as $cid) {
    $row = isset($catsIn[$cid]) && is_array($catsIn[$cid]) ? $catsIn[$cid] : array();
    $img = clean_str(isset($row['img']) ? $row['img'] : '', 500);
    if ($img === '') { continue; }
    $cats[$cid] = array('img' => $img, 'imgV' => clean_str(isset($row['imgV']) ? $row['imgV'] : '', 64));
}

// --- Home block (optional; every field falls back to HTML copy publicly) -----
$homeIn = isset($in['home']) && is_array($in['home']) ? $in['home'] : array();
function home_str($src, $key, $max = 2000) { return clean_str(isset($src[$key]) ? $src[$key] : '', $max); }
$home = array(
    'heroTitle' => home_str($homeIn, 'heroTitle', 300),
    'heroSub'   => home_str($homeIn, 'heroSub', 600),
    'heroImg'   => home_str($homeIn, 'heroImg', 500),
    'heroImgV'  => home_str($homeIn, 'heroImgV', 64),
    'stats'     => array(),
    'featured'  => array(),
);
$featIn = isset($homeIn['featured']) && is_array($homeIn['featured']) ? $homeIn['featured'] : array();
for ($i = 0; $i < 4; $i++) { $home['featured'][] = clean_str(isset($featIn[$i]) ? $featIn[$i] : '', 200); }
$statsIn = isset($homeIn['stats']) && is_array($homeIn['stats']) ? $homeIn['stats'] : array();
for ($i = 0; $i < 3; $i++) {
    $row = isset($statsIn[$i]) && is_array($statsIn[$i]) ? $statsIn[$i] : array();
    $home['stats'][] = array('num' => home_str($row, 'num', 40), 'label' => home_str($row, 'label', 120));
}

$ok = atomic_write_json(APP_CONTENT, array('copy' => $copy, 'visible' => $visible, 'categories' => $cats, 'home' => $home));
json_out($ok ? array('success' => true) : array('success' => false, 'error' => 'Content file is not writable.'), $ok ? 200 : 500);
