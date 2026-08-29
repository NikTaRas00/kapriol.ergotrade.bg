<?php
/**
 * db-verify.php — prove the database round-trips the JSON snapshot.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  CLI ONLY. Run after deploy/db-import.php:
 *
 *      php deploy/db-verify.php --dir=deploy/live-snapshot
 *      php deploy/db-verify.php --dir=deploy/live-snapshot --write=out.json
 *
 *  Options:
 *      --dir=PATH       directory holding the source JSON (required)
 *      --products=FILE  override the products file inside --dir
 *      --config=FILE    default: private/config.php
 *      --write=FILE     also dump the reconstructed array to FILE, for eyeballing
 *      --max=N          show at most N differing products (default 15)
 *
 *  Exit code 0 = clean, 1 = differences found. THIS IS THE GATE: do not switch
 *  any code over to the database until this exits 0.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * WHAT IT COMPARES
 *
 * It rebuilds each product record out of the six tables — real columns, plus
 * spec_json spread back in, plus the list tables, spec codes and article codes
 * — and diffs that against the source record. The rebuild here is deliberately
 * the same shape the future api/products.php will emit, so a clean run is
 * evidence that endpoint can be a drop-in.
 *
 * This is a SEMANTIC diff, not a byte diff. Three encodings of "no value"
 * exist in the source data — key absent, explicit null, and ""/[] — and
 * main.js treats all three identically at every read site. So before
 * comparing, both sides are normalised:
 *
 *   - absent  ==  null  ==  ""      for scalar fields
 *   - absent  ==  null  ==  []      for list fields
 *   - numbers and numeric strings compare by string value (imgV is stored as
 *     a string but products-save.php casts it to int)
 *   - booleans compare loosely against 0/1
 *
 * Anything else — a changed character, a dropped array element, a reordered
 * list, a lost article code — is reported as a difference.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("db-verify.php is a command-line tool.\n");
}

$opts = array('dir' => '', 'products' => '', 'config' => '', 'write' => '', 'max' => '15');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opts)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "Unknown argument: $arg\n");
    exit(2);
}

$ROOT = dirname(__DIR__);
function fail($msg) { fwrite(STDERR, 'ERROR: ' . $msg . PHP_EOL); exit(1); }

if ($opts['dir'] === '') { fail('--dir=PATH is required.'); }
$dir = $opts['dir'];
if (!preg_match('#^(/|[A-Za-z]:)#', $dir)) { $dir = $ROOT . '/' . $dir; }
$dir = rtrim(str_replace('\\', '/', $dir), '/');

$productsFile = $opts['products'] !== ''
    ? $dir . '/' . $opts['products']
    : (is_file($dir . '/products.merged.json') ? $dir . '/products.merged.json'
                                               : $dir . '/products.json');
if (!is_file($productsFile)) { fail("File not found: $productsFile"); }

$raw = file_get_contents($productsFile);
if (substr($raw, 0, 3) === "\xEF\xBB\xBF") { $raw = substr($raw, 3); }
$source = json_decode($raw, true);
if (!is_array($source)) { fail('Could not decode ' . $productsFile); }

// ------------------------------------------------------------------ connect --
$configFile = $opts['config'] !== '' ? $opts['config'] : $ROOT . '/private/config.php';
if (!preg_match('#^(/|[A-Za-z]:)#', $configFile)) { $configFile = $ROOT . '/' . $configFile; }
if (!is_file($configFile)) { fail("config not found: $configFile"); }
$dbFile = dirname($configFile) . '/db.php';
if (!is_file($dbFile)) { fail("db.php not found beside config: $dbFile"); }

try {
    $cfg = require $configFile;
    require_once $dbFile;
    $pdo = kap_pdo($cfg);
} catch (Exception $e) {
    fail($e->getMessage());
}

// ---------------------------------------------------------------- rebuild --
// Four bulk queries, then assemble in PHP. This is exactly the shape
// api/products.php will use: no N+1, no per-product round trip.

$lists = array();
foreach ($pdo->query('SELECT slug, list_key, value FROM product_list ORDER BY slug, list_key, ord') as $r) {
    $lists[$r['slug']][$r['list_key']][] = $r['value'];
}

$specs = array();
foreach ($pdo->query('SELECT slug, spec_group, code FROM product_spec_code ORDER BY slug, spec_group, ord') as $r) {
    $specs[$r['slug']][$r['spec_group']][] = $r['code'];
}

$arts = array();
foreach ($pdo->query('SELECT slug, code, size, color FROM product_article_code ORDER BY slug, ord') as $r) {
    $row = array('code' => $r['code'], 'size' => $r['size'] === null ? '' : $r['size']);
    if ($r['color'] !== null && $r['color'] !== '') { $row['color'] = $r['color']; }
    $arts[$r['slug']][] = $row;
}

$rebuilt = array();
$sql = 'SELECT p.* FROM products p
        JOIN categories c ON c.id = p.category_id
        ORDER BY c.sort_order, p.sort_order';
foreach ($pdo->query($sql) as $p) {
    $slug = $p['slug'];
    $rec  = array(
        'slug'            => $slug,
        'category'        => $p['category_id'],
        'name'            => $p['name'],
        'description'     => $p['description'],
        'fullDescription' => $p['full_description'],
        'productLine'     => $p['product_line'],
        'material'        => $p['material'],
        'img'             => $p['img'],
        'imgV'            => $p['img_v'],
        'isNew'           => (bool) $p['is_new'],
        'hidden'          => (bool) $p['hidden'],
    );

    // The frozen import payload goes back in as top-level keys — several of
    // these ARE rendered (sole, toeCap, weightGrams, fit, materialComposition).
    if ($p['spec_json'] !== null && $p['spec_json'] !== '') {
        $extra = json_decode($p['spec_json'], true);
        if (is_array($extra)) {
            foreach ($extra as $k => $v) { $rec[$k] = $v; }
        }
    }

    foreach (array('features', 'overview', 'sizes', 'colors', 'standards',
                   'images', 'sourceNames', 'features_pictograms') as $k) {
        $rec[$k] = isset($lists[$slug][$k]) ? $lists[$slug][$k] : array();
    }

    $rec['performance']  = isset($specs[$slug]['performance'])  ? $specs[$slug]['performance']  : array();
    $rec['technologies'] = isset($specs[$slug]['technologies']) ? $specs[$slug]['technologies'] : array();

    // safetyClass: the intact marking wins when one was preserved, exactly as
    // the file serves it today; otherwise the normalised code array.
    $rec['safetyClass'] = $p['safety_class_raw'] !== null
        ? $p['safety_class_raw']
        : (isset($specs[$slug]['safetyClass']) ? $specs[$slug]['safetyClass'] : array());

    $rec['articleCodes'] = isset($arts[$slug]) ? $arts[$slug] : array();

    $rebuilt[$slug] = $rec;
}

if ($opts['write'] !== '') {
    $out = $opts['write'];
    if (!preg_match('#^(/|[A-Za-z]:)#', $out)) { $out = $ROOT . '/' . $out; }
    file_put_contents($out, json_encode(array_values($rebuilt),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    echo "Wrote reconstruction to $out\n";
}

// ------------------------------------------------------------- normalise --
$LIST_FIELDS = array('features', 'overview', 'sizes', 'colors', 'standards', 'images',
                     'sourceNames', 'features_pictograms', 'performance', 'technologies');

/** Collapse the three "no value" encodings and unify scalar types. */
function norm($v, $isList) {
    if ($isList) {
        if ($v === null || $v === '' || $v === false) { return array(); }
        if (!is_array($v)) { return array((string) $v); }
        $out = array();
        foreach ($v as $x) {
            if ($x === null) { continue; }
            $out[] = is_array($x) ? $x : (string) $x;
        }
        return $out;
    }
    if ($v === null) { return ''; }
    if (is_bool($v)) { return $v ? '1' : '0'; }
    if (is_array($v)) { return $v; }
    if (is_float($v) || is_int($v)) { return (string) $v; }
    return trim((string) $v);
}

/** Every key either side mentions, so a dropped field cannot hide. */
function all_keys($a, $b) {
    return array_unique(array_merge(array_keys($a), array_keys($b)));
}

// ------------------------------------------------------------------ diff --
$problems  = array();
$missing   = array();
$extra     = array();
$srcBySlug = array();

foreach ($source as $p) {
    if (!is_array($p) || !isset($p['slug'])) { continue; }
    $srcBySlug[(string) $p['slug']] = $p;
}

foreach ($srcBySlug as $slug => $src) {
    if (!isset($rebuilt[$slug])) { $missing[] = $slug; continue; }
    $got  = $rebuilt[$slug];
    $diff = array();

    foreach (all_keys($src, $got) as $k) {
        $isList = in_array($k, $LIST_FIELDS, true);

        // safetyClass is legitimately polymorphic (string OR array). Compare it
        // through toCodes()-style splitting so "S1 PL" and ["S1","PL"] match,
        // and separately confirm the raw string survived.
        if ($k === 'safetyClass') {
            $a = isset($src[$k]) ? $src[$k] : null;
            $b = isset($got[$k]) ? $got[$k] : null;
            $sa = is_string($a) ? trim($a) : null;
            $sb = is_string($b) ? trim($b) : null;
            if ($sa !== $sb) {
                $diff[] = "safetyClass raw marking: " . var_export($sa, true)
                        . " -> " . var_export($sb, true);
                continue;
            }
            $ca = is_array($a) ? $a : preg_split('/\s+/', (string) $sa, -1, PREG_SPLIT_NO_EMPTY);
            $cb = is_array($b) ? $b : preg_split('/\s+/', (string) $sb, -1, PREG_SPLIT_NO_EMPTY);
            if (norm($ca, true) != norm($cb, true)) {
                $diff[] = 'safetyClass codes: ' . json_encode($ca) . ' -> ' . json_encode($cb);
            }
            continue;
        }

        $a = norm(array_key_exists($k, $src) ? $src[$k] : null, $isList);
        $b = norm(array_key_exists($k, $got) ? $got[$k] : null, $isList);

        if ($a != $b) {
            $sa = is_array($a) ? json_encode($a, JSON_UNESCAPED_UNICODE) : (string) $a;
            $sb = is_array($b) ? json_encode($b, JSON_UNESCAPED_UNICODE) : (string) $b;
            if (mb_strlen($sa) > 120) { $sa = mb_substr($sa, 0, 120) . '...'; }
            if (mb_strlen($sb) > 120) { $sb = mb_substr($sb, 0, 120) . '...'; }
            $diff[] = "$k: [$sa] -> [$sb]";
        }
    }

    if ($diff) { $problems[$slug] = $diff; }
}

foreach ($rebuilt as $slug => $_) {
    if (!isset($srcBySlug[$slug])) { $extra[] = $slug; }
}

// --------------------------------------------------- headline assertions --
$srcCount = count($srcBySlug);
$catCount = array();
foreach ($srcBySlug as $p) {
    $c = isset($p['category']) ? $p['category'] : '?';
    $catCount[$c] = (isset($catCount[$c]) ? $catCount[$c] : 0) + 1;
}
$dbCat = array();
foreach ($pdo->query('SELECT category_id, COUNT(*) n FROM products GROUP BY category_id') as $r) {
    $dbCat[$r['category_id']] = (int) $r['n'];
}

echo "\n";
echo "Source:  $productsFile\n";
echo str_repeat('-', 62), "\n";
printf("  %-24s %6d source   %6d db\n", 'products', $srcCount, count($rebuilt));
ksort($catCount);
foreach ($catCount as $c => $n) {
    printf("    %-22s %6d         %6d\n", $c, $n, isset($dbCat[$c]) ? $dbCat[$c] : 0);
}

$q = function ($sql) use ($pdo) { $s = $pdo->query($sql); return (int) $s->fetchColumn(); };
$srcWith = function ($fn) use ($srcBySlug) {
    $n = 0; foreach ($srcBySlug as $p) { if ($fn($p)) { $n++; } } return $n;
};

$rows = array(
    array('with photo (img)',
        $srcWith(function ($p) { return !empty($p['img']); }),
        $q("SELECT COUNT(*) FROM products WHERE img IS NOT NULL AND img <> ''")),
    array('with fullDescription',
        $srcWith(function ($p) { return !empty($p['fullDescription']); }),
        $q("SELECT COUNT(*) FROM products WHERE full_description IS NOT NULL AND full_description <> ''")),
    array('with performance codes',
        $srcWith(function ($p) { return !empty($p['performance']); }),
        $q("SELECT COUNT(DISTINCT slug) FROM product_spec_code WHERE spec_group='performance'")),
    array('with technologies codes',
        $srcWith(function ($p) { return !empty($p['technologies']); }),
        $q("SELECT COUNT(DISTINCT slug) FROM product_spec_code WHERE spec_group='technologies'")),
    array('with safetyClass',
        $srcWith(function ($p) { return !empty($p['safetyClass']); }),
        $q("SELECT COUNT(DISTINCT slug) FROM product_spec_code WHERE spec_group='safetyClass'")),
    array('hidden',
        $srcWith(function ($p) { return !empty($p['hidden']); }),
        $q('SELECT COUNT(*) FROM products WHERE hidden = 1')),
);
$srcArts  = 0;
$srcColor = 0;
foreach ($srcBySlug as $p) {
    if (empty($p['articleCodes']) || !is_array($p['articleCodes'])) { continue; }
    foreach ($p['articleCodes'] as $ac) {
        if (!is_array($ac) || !isset($ac['code']) || trim((string) $ac['code']) === '') { continue; }
        $srcArts++;
        if (isset($ac['color']) && trim((string) $ac['color']) !== '') { $srcColor++; }
    }
}
$rows[] = array('article-code rows', $srcArts,
    $q('SELECT COUNT(*) FROM product_article_code'));
$rows[] = array('article rows w/ color', $srcColor,
    $q("SELECT COUNT(*) FROM product_article_code WHERE color IS NOT NULL AND color <> ''"));

// safetyClass is polymorphic in the source: as of the live snapshot it is 43
// arrays, 1 legacy marking string ("S1PL ESD"), 1 empty array and 288 nulls —
// the admin has already migrated most of it. Only the string form has a raw
// marking to preserve, so this count is expected to be small, not zero.
$srcRaw = 0;
foreach ($srcBySlug as $p) {
    if (isset($p['safetyClass']) && is_string($p['safetyClass']) && trim($p['safetyClass']) !== '') { $srcRaw++; }
}
$rows[] = array('safetyClass raw markings', $srcRaw,
    $q("SELECT COUNT(*) FROM products WHERE safety_class_raw IS NOT NULL AND safety_class_raw <> ''"));

echo str_repeat('-', 62), "\n";
$countMismatch = 0;
foreach ($rows as $r) {
    $flag = $r[1] === $r[2] ? '  ' : '!!';
    if ($r[1] !== $r[2]) { $countMismatch++; }
    printf("%s%-24s %6d source   %6d db\n", $flag, ' ' . $r[0], $r[1], $r[2]);
}

// Slug integrity — the constraint that prompted the whole design. Slugs must
// be byte-identical, and must NOT have been reconstructed from names.
$slugBad = 0;
foreach ($srcBySlug as $slug => $src) {
    if (isset($rebuilt[$slug]) && $rebuilt[$slug]['slug'] !== $slug) { $slugBad++; }
}
$slugNeName = 0;
foreach ($srcBySlug as $slug => $src) {
    $derived = strtolower(preg_replace('/[^a-z0-9]+/i', '-', isset($src['name']) ? $src['name'] : ''));
    $derived = trim($derived, '-');
    if ($derived !== $slug) { $slugNeName++; }
}
echo str_repeat('-', 62), "\n";
printf("  %-24s %6d\n", 'slugs altered in transit', $slugBad);
printf("  %-24s %6d of %d  (proof slugs are opaque,\n", 'slug != slugify(name)', $slugNeName, $srcCount);
echo "                                   not derivable from the product name)\n";

// ------------------------------------------------------------------ verdict --
echo "\n";
$max = max(1, (int) $opts['max']);
$ok  = true;

if ($missing) {
    $ok = false;
    echo 'MISSING from db (' . count($missing) . "): " . implode(', ', array_slice($missing, 0, $max)) . "\n";
}
if ($extra) {
    $ok = false;
    echo 'EXTRA in db (' . count($extra) . "): " . implode(', ', array_slice($extra, 0, $max)) . "\n";
}
if ($problems) {
    $ok = false;
    echo 'FIELD DIFFERENCES on ' . count($problems) . " product(s):\n";
    $shown = 0;
    foreach ($problems as $slug => $diff) {
        if ($shown++ >= $max) {
            echo '  ... and ' . (count($problems) - $max) . " more\n";
            break;
        }
        echo "  $slug\n";
        foreach ($diff as $d) { echo "      $d\n"; }
    }
}
if ($countMismatch) { $ok = false; }
if ($slugBad)       { $ok = false; }

if ($ok) {
    echo "PASS — the database round-trips the snapshot with no semantic differences.\n";
    echo "The site is still reading assets/data/*.json; nothing has changed for visitors.\n";
    exit(0);
}

echo "\nFAIL — resolve the above before switching any code to the database.\n";
exit(1);
