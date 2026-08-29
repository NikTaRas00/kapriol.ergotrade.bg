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

/* Only the keys the test1 design actually renders. The live site's hero/about
   copy and the best-seller eyebrow are not in this list: that design has no
   About section and its hero text now lives in the `home` block, so keeping
   the fields would have meant an admin form that edits nothing. */
$COPY_KEYS = array(
    'contactPhone', 'contactEmail', 'contactAddr',
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

/* ---- Home page (test1) ----------------------------------------------------
   The redesigned home page has no hardcoded imagery: the hero, the three
   rotator slides and the "why" section are all authored here. Each image slot
   stores the path returned by site-image.php plus an `imgV` cache-buster,
   because images are served one-year-immutable. Every field is optional; the
   public page falls back to its built-in copy and hides an empty image slot
   rather than rendering a broken one. */
$homeIn = isset($in['home']) && is_array($in['home']) ? $in['home'] : array();
function home_str($src, $key, $max = 2000) {
    return clean_str(isset($src[$key]) ? $src[$key] : '', $max);
}
$home = array(
    'heroTitle' => home_str($homeIn, 'heroTitle', 300),
    'heroSub'   => home_str($homeIn, 'heroSub', 600),
    'heroImg'   => home_str($homeIn, 'heroImg', 500),
    'heroImgV'  => home_str($homeIn, 'heroImgV', 64),
    'whyTitle'  => home_str($homeIn, 'whyTitle', 300),
    'whyBody'   => home_str($homeIn, 'whyBody', 2000),
    'whyImg'    => home_str($homeIn, 'whyImg', 500),
    'whyImgV'   => home_str($homeIn, 'whyImgV', 64),
    // The banner is one block the admin can switch off entirely. Absent means
    // shown, so an older content.json keeps its current behaviour.
    'showRotator' => array_key_exists('showRotator', $homeIn) ? (bool) $homeIn['showRotator'] : true,
    // Three editable "why" key figures (number + caption).
    'stats'     => array(),
    'slides'    => array(),
    // Four hand-picked product slugs for the Featured row. Left empty, the
    // public page falls back to picking one product per range automatically.
    'featured'  => array(),
);
$featIn = isset($homeIn['featured']) && is_array($homeIn['featured']) ? $homeIn['featured'] : array();
for ($i = 0; $i < 4; $i++) {
    $home['featured'][] = clean_str(isset($featIn[$i]) ? $featIn[$i] : '', 200);
}
$statsIn = isset($homeIn['stats']) && is_array($homeIn['stats']) ? $homeIn['stats'] : array();
for ($i = 0; $i < 3; $i++) {
    $row = isset($statsIn[$i]) && is_array($statsIn[$i]) ? $statsIn[$i] : array();
    $home['stats'][] = array(
        'num'   => home_str($row, 'num', 40),
        'label' => home_str($row, 'label', 120),
    );
}
$slidesIn = isset($homeIn['slides']) && is_array($homeIn['slides']) ? $homeIn['slides'] : array();
for ($i = 0; $i < 3; $i++) {
    $row = isset($slidesIn[$i]) && is_array($slidesIn[$i]) ? $slidesIn[$i] : array();
    $home['slides'][] = array(
        'eyebrow' => home_str($row, 'eyebrow', 120),
        'title'   => home_str($row, 'title', 300),
        'body'    => home_str($row, 'body', 800),
        'img'     => home_str($row, 'img', 500),
        'imgV'    => home_str($row, 'imgV', 64),
        // Per-slide show/hide. Absent means shown, so an older content.json keeps
        // its current behaviour.
        'show'    => array_key_exists('show', $row) ? (bool) $row['show'] : true,
    );
}

/* Category tile photos live in the catalogue database now, not in this file.
   They are written first: if the DB write fails we stop before touching
   content.json, so the two never disagree about what was saved. A category
   the editor sent no image for is cleared, which is what makes "remove photo"
   work — the old code achieved the same by omitting the key. */
$pdo = kap_db();
try {
    foreach ($CAT_IDS as $cid) {
        $row = isset($cats[$cid]) ? $cats[$cid] : array('img' => '', 'imgV' => '');
        kap_category_image_save($pdo, $cid, $row['img'], $row['imgV']);
    }
} catch (Exception $e) {
    error_log('kapriol content-save (categories): ' . $e->getMessage());
    json_out(array('success' => false, 'error' => 'Could not save the range images.'), 500);
}

/* Note the absence of 'categories' here — dropping it is the point. A stale
   copy left in the file would quietly diverge from the database and there
   would be no way to tell which one the site was really using. */
$ok = atomic_write_json(KAP_CONTENT, array(
    'copy' => $copy, 'visible' => $visible, 'home' => $home,
));
json_out(
    $ok ? array('success' => true)
        : array('success' => false, 'error' => 'Content file is not writable.'),
    $ok ? 200 : 500
);
