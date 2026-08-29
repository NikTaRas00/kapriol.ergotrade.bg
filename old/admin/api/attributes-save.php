<?php
/**
 * attributes-save.php — write the master spec-code list (attributes.json).
 *
 * Auth + CSRF protected. Accepts { performance:[], safetyClass:[], technologies:[] }
 * where each item is { code, img, title, description }. Only the code is required;
 * everything else optional. Pictograms (img) plus the title/description popup
 * fields are kept for performance/technologies only — safety class is text-only
 * by design, so img/title/description there are dropped. title/description are
 * only written when non-empty (keeps the JSON tidy). Codes are de-duplicated
 * (case-insensitive) within each group. Write is lock-protected and atomic.
 */
require __DIR__ . '/_guard.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_out(array('success' => false, 'error' => 'Invalid payload.'), 422);
}

function clean_str($v, $max = 500) {
    $v = is_scalar($v) ? (string) $v : '';
    $v = str_replace(array("\r\n", "\r", "\n"), ' ', $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    $v = trim($v);
    if (mb_strlen($v) > $max) { $v = mb_substr($v, 0, $max); }
    return $v;
}

// Like clean_str but preserves newlines (description paragraphs).
function clean_multiline($v, $max = 2000) {
    $v = is_scalar($v) ? (string) $v : '';
    $v = str_replace(array("\r\n", "\r"), "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);   // keeps \n (0x0A) and \t (0x09)
    $v = trim($v);
    if (mb_strlen($v) > $max) { $v = mb_substr($v, 0, $max); }
    return $v;
}

// Only keep image paths that point into our own assets tree (no arbitrary URLs).
function clean_img($v) {
    $v = clean_str($v, 500);
    if ($v === '') { return ''; }
    if (!preg_match('#^assets/img/[A-Za-z0-9/_.-]+\.(png|jpg|jpeg|webp|svg)$#', $v)) { return ''; }
    return $v;
}

$GROUPS = array('performance', 'safetyClass', 'technologies');
$out = array();
foreach ($GROUPS as $g) {
    $items = isset($in[$g]) && is_array($in[$g]) ? $in[$g] : array();
    $allowImg = ($g !== 'safetyClass');
    $seen = array();
    $clean = array();
    foreach ($items as $row) {
        if (!is_array($row)) { continue; }
        $code = clean_str(isset($row['code']) ? $row['code'] : '', 60);
        if ($code === '') { continue; }
        $key = mb_strtolower($code);
        if (isset($seen[$key])) { continue; }   // de-dupe within group
        $seen[$key] = true;
        $entry = array('code' => $code);
        if ($allowImg) {
            $entry['img'] = clean_img(isset($row['img']) ? $row['img'] : '');
            $title = clean_str(isset($row['title']) ? $row['title'] : '', 160);
            $desc  = clean_multiline(isset($row['description']) ? $row['description'] : '', 2000);
            if ($title !== '') { $entry['title'] = $title; }
            if ($desc !== '')  { $entry['description'] = $desc; }
        }
        $clean[] = $entry;
        if (count($clean) >= 200) { break; }
    }
    $out[$g] = $clean;
}

$ok = atomic_write_json(KAP_ATTRIBUTES, $out);
json_out(
    $ok ? array('success' => true, 'attributes' => $out)
        : array('success' => false, 'error' => 'Attributes file is not writable.'),
    $ok ? 200 : 500
);
