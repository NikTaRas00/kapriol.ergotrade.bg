<?php
/**
 * db-selftest.php — exercise private/catalog.php offline, against SQLite.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  WHY THIS EXISTS
 *
 *  The live database is MySQL on the cPanel host, and port 3306 is firewalled
 *  from this workstation (measured: 443 opens in 0.1s, 3306 times out). So the
 *  API and admin SQL cannot be run against the real database from here.
 *
 *  Instead this builds an equivalent SQLite database from the same JSON
 *  snapshot — using deploy/db-import.php's own decomposition, not a copy — and
 *  then drives the REAL functions in private/catalog.php against it. That
 *  proves the query and assembly logic; it cannot prove MySQL column
 *  constraints or the connection itself, which is what the post-deploy check
 *  on the host is for.
 *
 *  Run:
 *      php -c .claude/php.ini deploy/db-selftest.php
 *
 *  Exit 0 = all assertions passed.
 * ──────────────────────────────────────────────────────────────────────────
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$ROOT   = dirname(__DIR__);
$SNAP   = $ROOT . '/deploy/live-snapshot';
$DBFILE = sys_get_temp_dir() . '/kap-selftest.sqlite';

$php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
$ini = $ROOT . '/.claude/php.ini';

// ---- build the test database through the real importer ---------------------
$cmd = escapeshellarg($php) . ' -c ' . escapeshellarg($ini) . ' '
     . escapeshellarg($ROOT . '/deploy/db-import.php')
     . ' --dir=' . escapeshellarg($SNAP)
     . ' --sqlite=' . escapeshellarg($DBFILE) . ' --quiet 2>&1';
exec($cmd, $out, $rc);
if ($rc !== 0) {
    echo "Importer failed:\n" . implode("\n", $out) . "\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $DBFILE, null, null, array(
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
));
$pdo->exec('PRAGMA foreign_keys = ON');

require $ROOT . '/private/catalog.php';

$src   = json_decode(file_get_contents($SNAP . '/products.json'), true);
$srcAt = json_decode(file_get_contents($SNAP . '/attributes.json'), true);

$pass = 0; $fail = 0;
function ok($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  ok    %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s%s\n", $label, $detail !== '' ? "  -- $detail" : ''); }
}

/** null == "" == absent for scalars; null == [] for lists. */
function norm($v, $isList) {
    if ($isList) {
        if ($v === null || $v === '' || $v === false) { return array(); }
        if (!is_array($v)) { return array((string) $v); }
        $o = array();
        foreach ($v as $x) { if ($x !== null) { $o[] = is_array($x) ? $x : (string) $x; } }
        return $o;
    }
    if ($v === null)  { return ''; }
    if (is_bool($v))  { return $v ? '1' : '0'; }
    if (is_array($v)) { return $v; }
    return trim((string) $v);
}
$LISTS = array('features','overview','sizes','colors','standards','images',
               'sourceNames','features_pictograms','performance','technologies','articleCodes');
function codes($v) {
    if (!$v) { return array(); }
    if (is_array($v)) {
        $o = array();
        foreach ($v as $x) { $s = trim((string) (is_array($x) && isset($x['code']) ? $x['code'] : $x)); if ($s !== '') { $o[] = $s; } }
        return $o;
    }
    $p = preg_split('/\s+/', trim((string) $v), -1, PREG_SPLIT_NO_EMPTY);
    return $p ? $p : array();
}

echo "\n== READ: kap_products() reproduces products.json ==\n";
$got = kap_products($pdo, true);
ok('record count', count($got) === count($src), count($got) . ' vs ' . count($src));

// Order matters: renderCategoryTiles / renderFeatured / renderRelated all take
// "the first N in file order".
$orderOk = true;
for ($i = 0; $i < min(count($got), count($src)); $i++) {
    if ($got[$i]['slug'] !== $src[$i]['slug']) { $orderOk = false; break; }
}
ok('order identical to file', $orderOk, $orderOk ? '' : "first divergence at index $i");

$diffs = array();
$n = min(count($got), count($src));
for ($i = 0; $i < $n; $i++) {
    $a = $src[$i]; $b = $got[$i];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
        if ($k === 'safetyClass') {
            $x = json_encode(codes(isset($a[$k]) ? $a[$k] : null));
            $y = json_encode(codes(isset($b[$k]) ? $b[$k] : null));
            $rawA = (isset($a[$k]) && is_string($a[$k])) ? trim($a[$k]) : '';
            $rawB = (isset($b[$k]) && is_string($b[$k])) ? trim($b[$k]) : '';
            if ($x !== $y)       { $diffs[] = "{$a['slug']}.safetyClass codes $x -> $y"; }
            if ($rawA !== $rawB) { $diffs[] = "{$a['slug']}.safetyClass raw '$rawA' -> '$rawB'"; }
            continue;
        }
        $isList = in_array($k, $LISTS, true);
        $x = norm(array_key_exists($k, $a) ? $a[$k] : null, $isList);
        $y = norm(array_key_exists($k, $b) ? $b[$k] : null, $isList);
        if ($x != $y) {
            $sx = is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : (string) $x;
            $sy = is_array($y) ? json_encode($y, JSON_UNESCAPED_UNICODE) : (string) $y;
            $diffs[] = "{$a['slug']}.$k [" . mb_substr($sx, 0, 70) . '] -> [' . mb_substr($sy, 0, 70) . ']';
        }
    }
}
ok('every field of every product matches', !$diffs, count($diffs) . ' difference(s)');
foreach (array_slice($diffs, 0, 10) as $d) { echo "          $d\n"; }

echo "\n== READ: the rest ==\n";
$at = kap_attributes($pdo);
ok('attributes group counts', count($at['performance']) === count($srcAt['performance'])
    && count($at['safetyClass']) === count($srcAt['safetyClass'])
    && count($at['technologies']) === count($srcAt['technologies']));
$titled = 0;
foreach ($at['performance'] as $e) { if (!empty($e['title'])) { $titled++; } }
$srcTitled = 0;
foreach ($srcAt['performance'] as $e) { if (!empty($e['title']) || !empty($e['name'])) { $srcTitled++; } }
ok('performance titles preserved', $titled === $srcTitled, "$titled vs $srcTitled");
ok('safetyClass entries are code-only', !isset($at['safetyClass'][0]['img']));
ok('kap_product_slugs count', count(kap_product_slugs($pdo)) === count($src));
ok('kap_product() single fetch', kap_product($pdo, $src[0]['slug'])['slug'] === $src[0]['slug']);
ok('kap_category_images empty (none uploaded)', kap_category_images($pdo) === array());

echo "\n== WRITE: kap_product_save() merge semantics ==\n";
$slug   = 'dune-rock-green';
$before = kap_product($pdo, $slug);

kap_product_save($pdo, $slug, array('name' => 'RENAMED'));
$after = kap_product($pdo, $slug);
ok('name updated', $after['name'] === 'RENAMED');
ok('untouched list survives (features)', $after['features'] === $before['features']);
ok('untouched spec_json survives (sole)',
    (isset($after['sole']) ? $after['sole'] : null) === (isset($before['sole']) ? $before['sole'] : null));
ok('untouched articleCodes survive', $after['articleCodes'] === $before['articleCodes']);
ok('category never touched', $after['category'] === $before['category']);
ok('slug never touched', $after['slug'] === $slug);

kap_product_save($pdo, $slug, array('features' => array('One', 'Two')));
$after = kap_product($pdo, $slug);
ok('list replaced wholesale', $after['features'] === array('One', 'Two'));
ok('name from previous save persists', $after['name'] === 'RENAMED');

kap_product_save($pdo, $slug, array('articleCodes' => array(
    array('code' => 'X1', 'size' => '42'), array('code' => 'X2', 'size' => ''))));
$after = kap_product($pdo, $slug);
ok('articleCodes replaced', count($after['articleCodes']) === 2
    && $after['articleCodes'][0]['code'] === 'X1' && $after['articleCodes'][1]['size'] === '');

// A legacy marking string must be superseded when the editor sends codes.
$raw = $pdo->query("SELECT slug FROM products WHERE safety_class_raw IS NOT NULL")->fetchColumn();
ok('a legacy safetyClass marking exists to test', $raw !== false, (string) $raw);
if ($raw) {
    ok('raw marking read back as a string', is_string(kap_product($pdo, $raw)['safetyClass']));
    kap_product_save($pdo, $raw, array('safetyClass' => array('S3', 'SR')));
    $r = kap_product($pdo, $raw);
    ok('editor save clears the raw marking', $r['safetyClass'] === array('S3', 'SR'));
}

kap_product_save($pdo, $slug, array('hidden' => true));
ok('hidden hides from public read', !in_array($slug, array_column(kap_products($pdo, false), 'slug'), true));
ok('hidden still visible to admin read', in_array($slug, array_column(kap_products($pdo, true), 'slug'), true));
ok('hidden excluded from sitemap', !in_array($slug, kap_product_slugs($pdo), true));
kap_product_save($pdo, $slug, array('hidden' => false));

ok('save on unknown slug returns false', kap_product_save($pdo, 'no-such-slug', array('name' => 'x')) === false);

echo "\n== WRITE: create / delete / bulk ==\n";
$err = kap_product_create($pdo, 'test-widget', 'ppe',
    array('name' => 'Test Widget', 'features' => array('A'), 'safetyClass' => array('S1')));
ok('create succeeds', $err === '', $err);
$new = kap_product($pdo, 'test-widget');
ok('created slug verbatim', $new['slug'] === 'test-widget');
ok('created record has its list', $new['features'] === array('A'));
ok('created record has its spec codes', $new['safetyClass'] === array('S1'));
$ppe = array_values(array_filter(kap_products($pdo, true),
    function ($p) { return $p['category'] === 'ppe'; }));
$last = end($ppe);
ok('created lands last in its category', $last['slug'] === 'test-widget');
ok('duplicate slug rejected',
    kap_product_create($pdo, 'test-widget', 'ppe', array('name' => 'x')) !== '');
ok('unknown category rejected',
    kap_product_create($pdo, 'test-widget-2', 'nope', array('name' => 'x')) !== '');

ok('delete removes the product', kap_product_delete($pdo, 'test-widget') === true);
ok('child rows cascaded',
    (int) $pdo->query("SELECT COUNT(*) FROM product_list WHERE slug='test-widget'")->fetchColumn() === 0);
ok('delete of missing slug returns false', kap_product_delete($pdo, 'test-widget') === false);

$v = kap_products_visibility($pdo, true);
ok('bulk hide reports totals', $v['total'] === count($src) && $v['changed'] === count($src),
    json_encode($v));
ok('bulk hide empties the public read', count(kap_products($pdo, false)) === 0);
$v = kap_products_visibility($pdo, false);
ok('bulk show restores', count(kap_products($pdo, false)) === count($src));
ok('bulk show is idempotent', kap_products_visibility($pdo, false)['changed'] === 0);

echo "\n== WRITE: attributes ==\n";
kap_attributes_save($pdo, array(
    'performance'  => array(array('code' => 'FO', 'title' => 'Fuel Oil', 'description' => 'x', 'img' => '')),
    'safetyClass'  => array(array('code' => 'S3')),
    'technologies' => array(),
));
$at = kap_attributes($pdo);
ok('attributes replaced', count($at['performance']) === 1 && count($at['safetyClass']) === 1
    && count($at['technologies']) === 0);
ok('title/description round-trip', $at['performance'][0]['title'] === 'Fuel Oil');
ok('empty img emitted as ""', $at['performance'][0]['img'] === '');
ok('safetyClass stays code-only', $at['safetyClass'][0] === array('code' => 'S3'));

kap_category_image_save($pdo, 'footwear', 'assets/img/categories/footwear.webp', '20260830');
$ci = kap_category_images($pdo);
ok('category image saved', isset($ci['footwear']) && $ci['footwear']['imgV'] === '20260830');
kap_category_image_save($pdo, 'footwear', '', '');
ok('category image cleared', kap_category_images($pdo) === array());

echo "\n== admin/api/_fields.php: the allowlist ==\n";
require $ROOT . '/admin/api/_fields.php';

$f = kap_product_fields(array('slug' => 'x', 'category' => 'ppe', 'name' => ' Trimmed  ',
                              'nonsense' => 'nope', 'sole' => 'HACK', 'weightGrams' => 999));
ok('slug is not writable',     !array_key_exists('slug', $f));
ok('category is not writable', !array_key_exists('category', $f));
ok('unknown keys dropped',     !array_key_exists('nonsense', $f));
ok('frozen import fields not writable',
    !array_key_exists('sole', $f) && !array_key_exists('weightGrams', $f));
ok('name trimmed', $f['name'] === 'Trimmed');
ok('absent keys stay absent (merge semantics)', !array_key_exists('description', $f));

$f = kap_product_fields(array('img' => 'https://evil.example/x.png', 'images' => array(
    'assets/img/products/ok.webp', '../../etc/passwd', 'https://evil.example/a.jpg')));
ok('lead img path-validated',  $f['img'] === '');
ok('gallery paths filtered',   $f['images'] === array('assets/img/products/ok.webp'));

$f = kap_product_fields(array('img' => 'assets/img/products/dune.webp', 'imgV' => '20260830'));
ok('valid lead img accepted',  $f['img'] === 'assets/img/products/dune.webp');
ok('imgV kept as a string',    $f['imgV'] === '20260830' && is_string($f['imgV']));

$f = kap_product_fields(array('safetyClass' => array(' S3 ', '', 'SR')));
ok('spec codes trimmed, blanks dropped', $f['safetyClass'] === array('S3', 'SR'));

$f = kap_product_fields(array('features' => array("a\x00b")));
ok('control characters stripped', $f['features'] === array('ab'));

echo "\n== rescued articleCodes colours survive an edit ==\n";
$colored = $pdo->query("SELECT slug FROM product_article_code WHERE color IS NOT NULL LIMIT 1")->fetchColumn();
ok('a coloured article row exists', $colored !== false, (string) $colored);
if ($colored) {
    $before = kap_product($pdo, $colored);
    $withColor = 0;
    foreach ($before['articleCodes'] as $a) { if (isset($a['color'])) { $withColor++; } }
    ok('colours present before edit', $withColor > 0, "$withColor row(s)");

    // Exactly what admin.js posts: {code,size} pairs, no colour field at all.
    $payload = array();
    foreach ($before['articleCodes'] as $a) {
        $payload[] = array('code' => $a['code'], 'size' => $a['size']);
    }
    kap_product_save($pdo, $colored, kap_product_fields(array('articleCodes' => $payload)));

    $after = kap_product($pdo, $colored);
    $stillColored = 0;
    foreach ($after['articleCodes'] as $a) { if (isset($a['color'])) { $stillColored++; } }
    ok('colours survive an editor save that omits them', $stillColored === $withColor,
        "$stillColored vs $withColor");
    ok('article rows otherwise unchanged', count($after['articleCodes']) === count($before['articleCodes']));

    // A code removed from the list loses its colour with the row — correct.
    kap_product_save($pdo, $colored, kap_product_fields(array(
        'articleCodes' => array(array('code' => 'ZZZ', 'size' => '1')))));
    $after = kap_product($pdo, $colored);
    ok('dropped codes take their colour with them',
        count($after['articleCodes']) === 1 && !isset($after['articleCodes'][0]['color']));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
@unlink($DBFILE);
exit($fail === 0 ? 0 : 1);
