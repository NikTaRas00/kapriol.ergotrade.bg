<?php
/**
 * db-import.php — seed the catalogue database from the JSON snapshot.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  CLI ONLY. Run from the project root:
 *
 *      php deploy/db-import.php --dir=deploy/live-snapshot
 *      php deploy/db-import.php --dir=deploy/live-snapshot --dry-run
 *
 *  Options:
 *      --dir=PATH        directory holding the source JSON  (required)
 *      --products=FILE   override the products file inside --dir
 *                        (default: products.merged.json, else products.json)
 *      --attributes=FILE default: attributes.json   (optional; skipped if absent)
 *      --content=FILE    default: content.json      (optional; skipped if absent)
 *      --config=FILE     default: private/config.php
 *      --dry-run         parse, validate and report — touch nothing
 *      --quiet           only print the summary
 * ──────────────────────────────────────────────────────────────────────────
 *
 * WHICH FILES TO POINT IT AT
 *
 * The HOST files are the source of truth, never the working copies in
 * assets/data/ — those are known-stale (334 records including one that is not
 * live, no performance/technologies codes, and content.json full of test junk
 * like "phoneTest" and ADDRESS_PLACEHOLDER). Download the three files from the
 * server into --dir first.
 *
 * deploy/live-snapshot/products.merged.json is already a faithful copy of the
 * live catalogue (333 records, 180 with photos, 46 performance + 46
 * technologies, 44 safetyClass) and is a good starting point, but it dates
 * from 2026-07-26 — re-download if the admin has been used since.
 *
 * IDEMPOTENT: every run wipes the six tables and rebuilds them inside one
 * transaction. Nothing is merged, so a failed run leaves the previous contents
 * intact. This is safe for exactly as long as the JSON files remain the source
 * of truth; once the admin writes to MySQL directly, this script stops being
 * re-runnable and becomes a one-shot historical tool.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("db-import.php is a command-line tool.\n");
}

// ---------------------------------------------------------------- arguments --
$opts = array(
    'dir' => '', 'products' => '', 'attributes' => 'attributes.json',
    'content' => 'content.json', 'config' => '', 'emit-sql' => '', 'sqlite' => '',
    'dry-run' => false, 'quiet' => false,
);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $opts['dry-run'] = true; continue; }
    if ($arg === '--quiet')   { $opts['quiet']   = true; continue; }
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opts)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "Unknown argument: $arg\n");
    exit(2);
}

$ROOT = dirname(__DIR__);

function say($msg) {
    global $opts;
    if (!$opts['quiet']) { echo $msg, PHP_EOL; }
}
function fail($msg) {
    fwrite(STDERR, 'ERROR: ' . $msg . PHP_EOL);
    exit(1);
}

if ($opts['dir'] === '') {
    fail("--dir=PATH is required (the directory holding the source JSON).\n"
       . "       e.g. php deploy/db-import.php --dir=deploy/live-snapshot");
}

$dir = $opts['dir'];
if (!preg_match('#^(/|[A-Za-z]:)#', $dir)) { $dir = $ROOT . '/' . $dir; }
$dir = rtrim(str_replace('\\', '/', $dir), '/');
if (!is_dir($dir)) { fail("--dir is not a directory: $dir"); }

/** Read + decode a JSON file, or return null when optional and absent. */
function load_json($path, $required) {
    if (!is_file($path)) {
        if ($required) { fail("File not found: $path"); }
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') { fail("File is empty or unreadable: $path"); }
    // Strip a UTF-8 BOM — cPanel File Manager's editor likes to add one, and
    // json_decode rejects it outright.
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") { $raw = substr($raw, 3); }
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        fail('Invalid JSON in ' . $path . ': ' . json_last_error_msg());
    }
    return $data;
}

// Products: prefer the merged snapshot (it carries the gallery images), fall
// back to the plain export.
$productsFile = $opts['products'] !== ''
    ? $dir . '/' . $opts['products']
    : (is_file($dir . '/products.merged.json') ? $dir . '/products.merged.json'
                                               : $dir . '/products.json');

$products   = load_json($productsFile, true);
$attributes = load_json($dir . '/' . $opts['attributes'], false);
$content    = load_json($dir . '/' . $opts['content'], false);

if (!is_array($products) || !isset($products[0])) {
    fail('products file must be a bare JSON array of records: ' . $productsFile);
}

say('Source:     ' . $productsFile . '  (' . count($products) . ' records)');
say('Attributes: ' . ($attributes === null ? '(absent — spec_code left empty)' : $dir . '/' . $opts['attributes']));
say('Content:    ' . ($content    === null ? '(absent — category images left null)' : $dir . '/' . $opts['content']));
say('');

// --------------------------------------------------------------- constants --
// Mirrors CATEGORIES in assets/js/main.js:19-26. The ids are the real values
// stored on products and used by the ?cat= deep links; only the labels are
// redesign copy (note clothing -> "Work Wear").
$CATEGORIES = array(
    array('footwear',             'Footwear'),
    array('footwear-accessories', 'Footwear Accessories'),
    array('clothing',             'Work Wear'),
    array('clothing-accessories', 'Work Wear Accessories'),
    array('ppe',                  'PPE'),
    array('ppe-accessories',      'PPE Accessories'),
);
$CAT_IDS = array();
foreach ($CATEGORIES as $c) { $CAT_IDS[$c[0]] = true; }

// Mirrors SPEC_GROUPS in main.js:28-32.
$SPEC_GROUPS = array('performance', 'safetyClass', 'technologies');

// The ordered string arrays that become product_list rows.
$LIST_KEYS = array(
    'features', 'overview', 'sizes', 'colors', 'standards',
    'images', 'sourceNames', 'features_pictograms',
);

// Scalar record keys that map to real columns on `products`. Everything NOT in
// this set, and not a list key / spec group / articleCodes, falls through to
// spec_json — deliberately, so an unrecognised import field survives.
$COLUMN_KEYS = array(
    'slug', 'category', 'name', 'description', 'fullDescription',
    'productLine', 'material', 'img', 'imgV', 'isNew', 'hidden',
);

// ---------------------------------------------------------------- helpers --

/**
 * Split a spec value into filter codes, exactly as toCodes() does in
 * main.js:277-289 — array of strings, array of {code}, or a whitespace-joined
 * marking string ("S1 PL" -> ["S1","PL"]).
 */
function to_codes($v) {
    if ($v === null || $v === '' || $v === false) { return array(); }
    if (is_array($v)) {
        $out = array();
        foreach ($v as $x) {
            $s = is_array($x) && isset($x['code']) ? $x['code'] : $x;
            $s = trim((string) $s);
            if ($s !== '') { $out[] = $s; }
        }
        return $out;
    }
    $parts = preg_split('/\s+/', trim((string) $v), -1, PREG_SPLIT_NO_EMPTY);
    return $parts === false ? array() : $parts;
}

/** Normalise "" to null for nullable text columns; keep real values verbatim. */
function nn($v) {
    if ($v === null) { return null; }
    if (is_string($v)) { $v = trim($v); return $v === '' ? null : $v; }
    return $v;
}

// ----------------------------------------------------------- validate pass --
// Everything that could abort the import is checked BEFORE a single row is
// written, so a bad snapshot never leaves a half-populated database.

$seen   = array();
$errors = array();
foreach ($products as $i => $p) {
    if (!is_array($p)) { $errors[] = "record #$i is not an object"; continue; }

    $slug = isset($p['slug']) ? (string) $p['slug'] : '';
    if ($slug === '')                    { $errors[] = "record #$i has no slug"; continue; }
    if (isset($seen[$slug]))             { $errors[] = "duplicate slug: $slug"; continue; }
    $seen[$slug] = true;

    $cat = isset($p['category']) ? (string) $p['category'] : '';
    if (!isset($CAT_IDS[$cat]))          { $errors[] = "$slug: unknown category '$cat'"; }
    if (!isset($p['name']) || trim((string) $p['name']) === '') {
        $errors[] = "$slug: missing name";
    }
}
if ($errors) {
    fwrite(STDERR, "Refusing to import — " . count($errors) . " problem(s):\n");
    foreach (array_slice($errors, 0, 25) as $e) { fwrite(STDERR, "  - $e\n"); }
    if (count($errors) > 25) { fwrite(STDERR, '  ... and ' . (count($errors) - 25) . " more\n"); }
    exit(1);
}

// ------------------------------------------------------------- build rows --
// Shaped in memory first so --dry-run reports exactly what a real run writes.

$catImg   = array();
if (is_array($content) && isset($content['categories']) && is_array($content['categories'])) {
    // The live shape is an object map keyed by category id. The stale local
    // file has an empty array here instead; both are tolerated.
    foreach ($content['categories'] as $cid => $row) {
        if (is_array($row) && isset($CAT_IDS[$cid])) {
            $catImg[$cid] = array(nn(isset($row['img']) ? $row['img'] : null),
                                  nn(isset($row['imgV']) ? $row['imgV'] : null));
        }
    }
}

$rowsProduct = array();
$rowsList    = array();
$rowsSpec    = array();
$rowsArt     = array();
$catCounts   = array();
$stats       = array('specJsonKeys' => array(), 'safetyRaw' => 0, 'artColor' => 0);

foreach ($products as $p) {
    $slug = (string) $p['slug'];
    $cat  = (string) $p['category'];
    $catCounts[$cat] = (isset($catCounts[$cat]) ? $catCounts[$cat] : 0) + 1;

    // sort_order: the record's position WITHIN its category, preserving the
    // array order the front end currently depends on for "first product with a
    // photo" (main.js:855-859, :912) and for related products (:1704).
    $ord = $catCounts[$cat] - 1;

    // safetyClass: keep the intact marking when the legacy value is a string.
    // An array value is already admin-authored, so there is no raw marking to
    // preserve and the column stays null — which is exactly the state a future
    // admin save produces.
    $sc     = isset($p['safetyClass']) ? $p['safetyClass'] : null;
    $scRaw  = (is_string($sc) && trim($sc) !== '') ? trim($sc) : null;
    if ($scRaw !== null) { $stats['safetyRaw']++; }

    // spec_json: whatever is left after the known keys are consumed.
    $leftover = $p;
    foreach ($COLUMN_KEYS as $k) { unset($leftover[$k]); }
    foreach ($LIST_KEYS   as $k) { unset($leftover[$k]); }
    foreach ($SPEC_GROUPS as $k) { unset($leftover[$k]); }
    unset($leftover['articleCodes']);
    foreach (array_keys($leftover) as $k) {
        $stats['specJsonKeys'][$k] = (isset($stats['specJsonKeys'][$k]) ? $stats['specJsonKeys'][$k] : 0) + 1;
    }

    $rowsProduct[] = array(
        'slug'             => $slug,
        'category_id'      => $cat,
        'sort_order'       => $ord,
        'name'             => (string) $p['name'],
        'description'      => nn(isset($p['description']) ? $p['description'] : null),
        'full_description' => nn(isset($p['fullDescription']) ? $p['fullDescription'] : null),
        'product_line'     => nn(isset($p['productLine']) ? $p['productLine'] : null),
        'material'         => nn(isset($p['material']) ? $p['material'] : null),
        'img'              => nn(isset($p['img']) ? $p['img'] : null),
        // imgV is a STRING in the data ("20260723") even though the admin
        // casts it to int on save. Store the string; do not coerce.
        'img_v'            => nn(isset($p['imgV']) ? (string) $p['imgV'] : null),
        'safety_class_raw' => $scRaw,
        'is_new'           => !empty($p['isNew'])  ? 1 : 0,
        'hidden'           => !empty($p['hidden']) ? 1 : 0,
        'spec_json'        => $leftover
            ? json_encode($leftover, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
    );

    foreach ($LIST_KEYS as $key) {
        if (!isset($p[$key]) || !is_array($p[$key])) { continue; }
        $n = 0;
        foreach ($p[$key] as $v) {
            if (is_array($v) || $v === null) { continue; }
            $v = (string) $v;
            if (trim($v) === '') { continue; }
            $rowsList[] = array($slug, $key, $n++, $v);
        }
    }

    foreach ($SPEC_GROUPS as $g) {
        $codes = to_codes(isset($p[$g]) ? $p[$g] : null);
        $n = 0;
        $dedupe = array();
        foreach ($codes as $code) {
            // uq_spec is (slug, spec_group, code) — a value like "S1 S1" would
            // otherwise blow up the insert.
            $k = mb_strtolower($code, 'UTF-8');
            if (isset($dedupe[$k])) { continue; }
            $dedupe[$k] = true;
            $rowsSpec[] = array($slug, $g, $code, $n++);
        }
    }

    if (isset($p['articleCodes']) && is_array($p['articleCodes'])) {
        $n = 0;
        foreach ($p['articleCodes'] as $ac) {
            if (!is_array($ac) || !isset($ac['code'])) { continue; }
            $code = trim((string) $ac['code']);
            if ($code === '') { continue; }
            $color = nn(isset($ac['color']) ? $ac['color'] : null);
            if ($color !== null) { $stats['artColor']++; }
            $rowsArt[] = array($slug, $n++, $code,
                               nn(isset($ac['size']) ? $ac['size'] : null), $color);
        }
    }
}

// spec_code rows from attributes.json, folding the legacy `name` into `title`.
$rowsCode = array();
if (is_array($attributes)) {
    foreach ($SPEC_GROUPS as $g) {
        if (!isset($attributes[$g]) || !is_array($attributes[$g])) { continue; }
        $n = 0;
        $dedupe = array();
        foreach ($attributes[$g] as $d) {
            if (!is_array($d) || !isset($d['code'])) { continue; }
            $code = trim((string) $d['code']);
            if ($code === '') { continue; }
            $k = mb_strtolower($code, 'UTF-8');
            if (isset($dedupe[$k])) { continue; }   // matches the writer's
            $dedupe[$k] = true;                     // case-insensitive de-dupe
            $title = nn(isset($d['title']) ? $d['title'] : (isset($d['name']) ? $d['name'] : null));
            $rowsCode[] = array(
                $g, $code, $title,
                nn(isset($d['description']) ? $d['description'] : null),
                nn(isset($d['img']) ? $d['img'] : null),
                $n++,
            );
        }
    }
}

// ------------------------------------------------------------------ report --
say('Parsed OK:');
say(sprintf('  products              %6d', count($rowsProduct)));
foreach ($CATEGORIES as $c) {
    say(sprintf('    %-22s %4d', $c[0], isset($catCounts[$c[0]]) ? $catCounts[$c[0]] : 0));
}
say(sprintf('  product_list          %6d rows', count($rowsList)));
say(sprintf('  product_spec_code     %6d rows', count($rowsSpec)));
say(sprintf('  product_article_code  %6d rows  (%d with a rescued color)',
            count($rowsArt), $stats['artColor']));
say(sprintf('  spec_code             %6d rows', count($rowsCode)));
say(sprintf('  categories            %6d rows  (%d with a tile image)',
            count($CATEGORIES), count($catImg)));
say(sprintf('  safety_class_raw preserved on %d products', $stats['safetyRaw']));
ksort($stats['specJsonKeys']);
say('  spec_json keys carried through: ' . (count($stats['specJsonKeys'])
    ? implode(', ', array_keys($stats['specJsonKeys'])) : '(none)'));
say('');

// ---------------------------------------------------------------- emit SQL --
// Produces a plain .sql file you can feed to phpMyAdmin -> Import. Needed
// because SuperHosting filters port 3306 at the network edge, so this machine
// cannot reach MySQL directly even with the Remote Database Access whitelist
// in place (measured: port 443 opens in 0.1s, 3306 times out after 6s).
//
// No database connection is required to produce it.
if ($opts['emit-sql'] !== '') {
    $out = $opts['emit-sql'];
    if (!preg_match('#^(/|[A-Za-z]:)#', $out)) { $out = $ROOT . '/' . $out; }

    /*
     * String literal escaping.
     *
     * The source data was scanned and contains ZERO backslashes, control
     * characters, NUL bytes and 0x1A bytes — only apostrophes (94) and double
     * quotes (5). So doubling the apostrophe is sufficient AND is valid
     * regardless of whether the server runs with NO_BACKSLASH_ESCAPES, which a
     * backslash-based escape would not be.
     *
     * Backslashes are still escaped defensively in case the data changes, and
     * control characters abort the run rather than being silently mangled —
     * a corrupt seed file is far worse than a failed one.
     */
    $badCtrl = 0;
    $sqlStr = function ($v) use (&$badCtrl) {
        $v = (string) $v;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x1A]/', $v)) { $badCtrl++; }
        return "'" . str_replace(array('\\', "'"), array('\\\\', "''"), $v) . "'";
    };
    $sqlVal = function ($v) use ($sqlStr) {
        if ($v === null)  { return 'NULL'; }
        if (is_bool($v))  { return $v ? '1' : '0'; }
        if (is_int($v))   { return (string) $v; }
        return $sqlStr($v);
    };

    $fh = @fopen($out, 'wb');
    if (!$fh) { fail("Cannot write $out"); }

    fwrite($fh, "-- ===========================================================================\n");
    fwrite($fh, "-- db-seed.sql — generated by deploy/db-import.php --emit-sql\n");
    fwrite($fh, '-- Source: ' . basename($productsFile) . ' (' . count($rowsProduct) . " products)\n");
    fwrite($fh, '-- Generated: ' . gmdate('Y-m-d H:i:s') . " UTC\n");
    fwrite($fh, "--\n");
    fwrite($fh, "-- Import via phpMyAdmin -> select the database -> Import tab -> choose this\n");
    fwrite($fh, "-- file -> Go. Run deploy/db-schema.sql FIRST; this file only fills the tables.\n");
    fwrite($fh, "-- Re-runnable: it clears every table before inserting.\n");
    fwrite($fh, "-- ===========================================================================\n\n");
    fwrite($fh, "SET NAMES utf8mb4;\n");
    // DELETE, not TRUNCATE. MySQL refuses to TRUNCATE a table that a foreign
    // key references (error 1701: "Cannot truncate a table referenced in a
    // foreign key constraint") and SET FOREIGN_KEY_CHECKS = 0 does NOT lift
    // that — it relaxes row-level checks, not the DDL guard. This shipped as a
    // bug once and failed on the first real import; do not "optimise" it back
    // to TRUNCATE. Child-first order keeps the sweep valid on its own merits,
    // and at <5k rows the performance difference is irrelevant.
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n");
    foreach (array('product_article_code', 'product_spec_code', 'product_list',
                   'spec_code', 'products', 'categories') as $t) {
        fwrite($fh, "DELETE FROM `$t`;\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n\n");

    /**
     * Write rows as multi-row INSERTs, flushing whenever the statement grows
     * past ~256 KB. One giant INSERT would risk max_allowed_packet; one
     * statement per row would make the file huge and slow to import.
     */
    $writeRows = function ($table, $cols, $rows) use ($fh, $sqlVal) {
        if (!$rows) { return; }
        $head = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . "`) VALUES\n";
        $buf = '';
        $n   = 0;
        foreach ($rows as $r) {
            $vals = array();
            foreach ($r as $v) { $vals[] = $sqlVal($v); }
            $tuple = '(' . implode(',', $vals) . ')';
            if ($buf === '') { $buf = $head . $tuple; }
            else             { $buf .= ",\n" . $tuple; }
            $n++;
            if (strlen($buf) > 262144) { fwrite($fh, $buf . ";\n"); $buf = ''; }
        }
        if ($buf !== '') { fwrite($fh, $buf . ";\n"); }
        fwrite($fh, "\n");
    };

    $catRows = array();
    foreach ($CATEGORIES as $i => $c) {
        $img = isset($catImg[$c[0]]) ? $catImg[$c[0]] : array(null, null);
        $catRows[] = array($c[0], $c[1], $i, $img[0], $img[1]);
    }
    $writeRows('categories', array('id', 'label', 'sort_order', 'img', 'img_v'), $catRows);

    $prodRows = array();
    foreach ($rowsProduct as $r) { $prodRows[] = array_values($r); }
    $writeRows('products', array_keys($rowsProduct[0]), $prodRows);

    $writeRows('product_list',         array('slug', 'list_key', 'ord', 'value'),        $rowsList);
    $writeRows('product_spec_code',    array('slug', 'spec_group', 'code', 'ord'),       $rowsSpec);
    $writeRows('product_article_code', array('slug', 'ord', 'code', 'size', 'color'),    $rowsArt);
    $writeRows('spec_code', array('spec_group', 'code', 'title', 'description', 'img', 'sort_order'), $rowsCode);

    fclose($fh);

    if ($badCtrl) {
        @unlink($out);
        fail("$badCtrl string(s) contained control characters — refusing to emit a seed "
           . 'file that MySQL would silently mangle. Investigate the source data.');
    }

    say('Wrote ' . $out . '  (' . number_format(filesize($out) / 1048576, 2) . " MB)");
    say('');
    say('Next: phpMyAdmin -> select the database -> Import -> choose this file -> Go.');
    say('Run deploy/db-schema.sql first if you have not already.');
    exit(0);
}

if ($opts['dry-run']) {
    say('--dry-run: nothing was written.');
    exit(0);
}

// ------------------------------------------------------------------- write --
$isSqlite = ($opts['sqlite'] !== '');

if ($isSqlite) {
    /*
     * LOCAL TEST TARGET. The host database is MySQL, but port 3306 is
     * firewalled from this workstation, so the only way to exercise the real
     * import + read code offline is against an equivalent SQLite file. The
     * decomposition below is shared verbatim with the MySQL path — that is the
     * point: the test drives the shipping code, not a copy of it.
     *
     * Types are looser than the MySQL schema (SQLite has no ENUM/JSON and
     * ignores VARCHAR lengths), so this proves LOGIC, not column constraints.
     */
    $sqlitePath = $opts['sqlite'];
    if (!preg_match('#^(/|[A-Za-z]:)#', $sqlitePath)) { $sqlitePath = $ROOT . '/' . $sqlitePath; }
    @unlink($sqlitePath);
    try {
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    } catch (Exception $e) {
        fail('SQLite: ' . $e->getMessage());
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE categories (id TEXT PRIMARY KEY, label TEXT NOT NULL,
                  sort_order INTEGER NOT NULL DEFAULT 0, img TEXT, img_v TEXT)');
    $pdo->exec('CREATE TABLE products (slug TEXT PRIMARY KEY, category_id TEXT NOT NULL,
                  sort_order INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL, description TEXT,
                  full_description TEXT, product_line TEXT, material TEXT, img TEXT, img_v TEXT,
                  safety_class_raw TEXT, is_new INTEGER NOT NULL DEFAULT 0,
                  hidden INTEGER NOT NULL DEFAULT 0, spec_json TEXT,
                  FOREIGN KEY (category_id) REFERENCES categories(id))');
    $pdo->exec('CREATE TABLE product_list (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL,
                  list_key TEXT NOT NULL, ord INTEGER NOT NULL, value TEXT NOT NULL,
                  UNIQUE (slug, list_key, ord),
                  FOREIGN KEY (slug) REFERENCES products(slug) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE product_spec_code (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL,
                  spec_group TEXT NOT NULL, code TEXT NOT NULL, ord INTEGER NOT NULL DEFAULT 0,
                  UNIQUE (slug, spec_group, code),
                  FOREIGN KEY (slug) REFERENCES products(slug) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE product_article_code (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL,
                  ord INTEGER NOT NULL DEFAULT 0, code TEXT NOT NULL, size TEXT, color TEXT,
                  FOREIGN KEY (slug) REFERENCES products(slug) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE spec_code (spec_group TEXT NOT NULL, code TEXT NOT NULL, title TEXT,
                  description TEXT, img TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
                  PRIMARY KEY (spec_group, code))');
    say('SQLite test database: ' . $sqlitePath);
} else {
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
}

say($isSqlite ? 'Importing into SQLite...' : 'Connected. Importing...');

try {
    // DELETE, not TRUNCATE: MySQL refuses to TRUNCATE a table referenced by a
    // foreign key (error 1701) and SET FOREIGN_KEY_CHECKS = 0 does not lift
    // that — it relaxes row-level checks, not the DDL guard. Child-first order
    // keeps the sweep valid regardless.
    // FOREIGN_KEY_CHECKS is MySQL-only; SQLite uses PRAGMA and the child-first
    // order below is valid without either.
    if (!$isSqlite) { $pdo->exec('SET FOREIGN_KEY_CHECKS = 0'); }
    foreach (array('product_article_code', 'product_spec_code', 'product_list',
                   'spec_code', 'products', 'categories') as $t) {
        $pdo->exec('DELETE FROM `' . $t . '`');
    }
    if (!$isSqlite) { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); }

    // Unlike TRUNCATE, DELETE is not DDL and does not implicitly commit — but
    // the SET statements around it are outside the transaction below, so the
    // wipe is only rolled back if it happens inside one. Kept in this order
    // deliberately: a failed reload leaves empty tables, which the next run
    // repairs, rather than a half-merged catalogue.
    $pdo->beginTransaction();

    $stCat = $pdo->prepare(
        'INSERT INTO categories (id, label, sort_order, img, img_v) VALUES (?,?,?,?,?)');
    foreach ($CATEGORIES as $i => $c) {
        $img = isset($catImg[$c[0]]) ? $catImg[$c[0]] : array(null, null);
        $stCat->execute(array($c[0], $c[1], $i, $img[0], $img[1]));
    }

    $stProd = $pdo->prepare(
        'INSERT INTO products
           (slug, category_id, sort_order, name, description, full_description,
            product_line, material, img, img_v, safety_class_raw, is_new, hidden, spec_json)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rowsProduct as $r) {
        $stProd->execute(array(
            $r['slug'], $r['category_id'], $r['sort_order'], $r['name'],
            $r['description'], $r['full_description'], $r['product_line'],
            $r['material'], $r['img'], $r['img_v'], $r['safety_class_raw'],
            $r['is_new'], $r['hidden'], $r['spec_json'],
        ));
    }

    $stList = $pdo->prepare(
        'INSERT INTO product_list (slug, list_key, ord, value) VALUES (?,?,?,?)');
    foreach ($rowsList as $r) { $stList->execute($r); }

    $stSpec = $pdo->prepare(
        'INSERT INTO product_spec_code (slug, spec_group, code, ord) VALUES (?,?,?,?)');
    foreach ($rowsSpec as $r) { $stSpec->execute($r); }

    $stArt = $pdo->prepare(
        'INSERT INTO product_article_code (slug, ord, code, size, color) VALUES (?,?,?,?,?)');
    foreach ($rowsArt as $r) { $stArt->execute($r); }

    $stCode = $pdo->prepare(
        'INSERT INTO spec_code (spec_group, code, title, description, img, sort_order)
         VALUES (?,?,?,?,?,?)');
    foreach ($rowsCode as $r) { $stCode->execute($r); }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fail('Import failed and was rolled back: ' . $e->getMessage());
}

// ------------------------------------------------------------- read it back --
$check = function ($sql) use ($pdo) {
    $s = $pdo->query($sql);
    return (int) $s->fetchColumn();
};

say('');
say('Committed. Row counts as stored:');
say(sprintf('  categories            %6d', $check('SELECT COUNT(*) FROM categories')));
say(sprintf('  products              %6d', $check('SELECT COUNT(*) FROM products')));
say(sprintf('  product_list          %6d', $check('SELECT COUNT(*) FROM product_list')));
say(sprintf('  product_spec_code     %6d', $check('SELECT COUNT(*) FROM product_spec_code')));
say(sprintf('  product_article_code  %6d', $check('SELECT COUNT(*) FROM product_article_code')));
say(sprintf('  spec_code             %6d', $check('SELECT COUNT(*) FROM spec_code')));
say('');
say('Next: node deploy/db-verify.js --dir=' . $opts['dir']);
say('The site is still reading assets/data/*.json — nothing has changed for visitors.');
