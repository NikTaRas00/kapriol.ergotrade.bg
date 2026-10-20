<?php
/**
 * _pricing.php — the "Upload all pricing" machinery, plus the category move the
 * product editor needs.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  Shared by pricing-upload.php, pricing-apply.php, pricing-backup.php and
 *  products-save.php. Never an entry point — denied by the root .htaccess
 *  (^_.*\.php). Requires _guard.php to have been included first (KAP_PRIVATE_DIR,
 *  atomic_write_json, kap_product_delete from private/catalog.php via kap_db()).
 *
 *  THE FLOW
 *
 *    1. pricing-upload.php reads the .xlsx/.csv, then CLASSIFIES every slug
 *       before changing anything:
 *         case 1  slug in file (valid price) and in the DB  -> price update
 *         case 2  slug in the DB but nowhere in the file     -> admin decides
 *         case 3  slug in file (valid price) but not in DB   -> admin decides
 *       A slug that appears in the file but cannot be applied (bad price,
 *       duplicated) is "protected": it is reported as skipped and is NEVER
 *       treated as missing, so a typo in the sheet can't get a product deleted.
 *    2. A backup snapshot is written, then case 1 is applied immediately.
 *    3. The plan (case 2 / case 3 lists, stats) is kept in private/price-plans/
 *       for an hour; pricing-apply.php carries out the admin's button choices
 *       against exactly that plan, one case at a time.
 *
 *  PRICES: DECIMAL(8,2) in products.price, euros, VAT included. `unavailable`
 *  products have price NULL and show a banner instead of a number.
 * ──────────────────────────────────────────────────────────────────────────
 */

if (defined('KAP_PRICING_LOADED')) { return; }
define('KAP_PRICING_LOADED', true);

define('KAP_PLACEHOLDER_CAT', 'unsorted');
define('KAP_PRICE_MAX', 99999.99);
define('KAP_PLAN_TTL', 3600);
define('KAP_PRICE_MAX_ROWS', 5000);
define('KAP_NS_MAIN', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
define('KAP_NS_REL',  'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

/** The six real ranges a product may be moved into (never the placeholder). */
function kap_editor_categories() {
    return array('footwear', 'footwear-accessories', 'clothing',
                 'clothing-accessories', 'ppe', 'ppe-accessories');
}

/* ========================================================================== *
 *  CATEGORY MOVE (product editor)
 * ========================================================================== */

/**
 * Move a product to another real category, at the end of that category so the
 * "first product with a photo" ordering of the others is undisturbed.
 *
 * @return string '' on success (including "already there"), else an error.
 */
function kap_product_set_category(PDO $pdo, $slug, $category) {
    if (!in_array($category, kap_editor_categories(), true)) { return 'Unknown category.'; }

    $st = $pdo->prepare('SELECT category_id FROM products WHERE slug = ?');
    $st->execute(array($slug));
    $cur = $st->fetchColumn();
    if ($cur === false) { return 'Product not found.'; }
    if ($cur === $category) { return ''; }

    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM products WHERE category_id = ?');
    $st->execute(array($category));
    $ord = (int) $st->fetchColumn();

    $pdo->prepare('UPDATE products SET category_id = ?, sort_order = ? WHERE slug = ?')
        ->execute(array($category, $ord, $slug));
    return '';
}

/* ========================================================================== *
 *  READING THE UPLOADED FILE
 * ========================================================================== */

/**
 * Parse one price cell.
 *
 * Accepts 20, "20", "12.50", "12,50", "1 234,50", "€12.5", "12 EUR". Numeric
 * spreadsheet cells are rounded to cents (Excel stores 12.5 as 12.499999…);
 * typed text with more than two decimals is rejected rather than guessed at.
 *
 * @return array(float|null $price, string $reason)
 */
function kap_price_parse($raw, $isNumericCell) {
    $s = trim((string) $raw);
    if ($s === '') { return array(null, 'empty price'); }

    if ($isNumericCell && is_numeric($s)) {
        $v = round((float) $s, 2);
    } else {
        $s = preg_replace('/\s|\x{00A0}|€|euro?|лв\.?/iu', '', $s);
        $hasComma = strpos($s, ',') !== false;
        $hasDot   = strpos($s, '.') !== false;
        if ($hasComma && $hasDot) {
            $dec  = (strrpos($s, ',') > strrpos($s, '.')) ? ',' : '.';
            $thou = ($dec === ',') ? '.' : ',';
            $s = str_replace($thou, '', $s);
            $s = str_replace($dec, '.', $s);
        } elseif ($hasComma) {
            $s = str_replace(',', '.', $s);
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $s)) { return array(null, 'not a valid number'); }
        if (preg_match('/\.\d{3,}$/', $s))      { return array(null, 'more than two decimals'); }
        $v = round((float) $s, 2);
    }

    if ($v <= 0)             { return array(null, 'price must be above zero'); }
    if ($v > KAP_PRICE_MAX)  { return array(null, 'price too large'); }
    return array($v, '');
}

/** Spreadsheet column letters -> 0-based index (A=0, AA=26). */
function kap_price_col_index($letters) {
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/** Load one XML part of an .xlsx safely (size cap, no DTD/entities, no network). */
function kap_price_xml_doc($zip, $name) {
    $st = $zip->statName($name);
    if (!$st || $st['size'] > 20 * 1024 * 1024) { return null; }
    $xml = $zip->getFromName($name);
    if ($xml === false || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        return null;
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $doc : null;
}

function kap_price_xpath($doc) {
    $x = new DOMXPath($doc);
    $x->registerNamespace('m', KAP_NS_MAIN);
    return $x;
}

/**
 * Read the first worksheet of an .xlsx into a grid:
 *   rowNumber => array(colIndex => array(text, isNumericCell))
 *
 * @return array(array|null $grid, string $error)
 */
function kap_price_read_xlsx($path) {
    if (!class_exists('ZipArchive')) {
        return array(null, 'This server cannot read .xlsx files (PHP zip extension missing). Save the sheet as CSV and upload that instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { return array(null, 'The file is not a valid .xlsx workbook.'); }

    // Which part is the first sheet? Normally xl/worksheets/sheet1.xml, but
    // follow workbook.xml -> its relationships so a reordered workbook works.
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = kap_price_xml_doc($zip, 'xl/workbook.xml');
    if ($wb) {
        $x = kap_price_xpath($wb);
        $sheets = $x->query('//m:sheets/m:sheet');
        if ($sheets->length) {
            $rid = $sheets->item(0)->getAttributeNS(KAP_NS_REL, 'id');
            $rels = kap_price_xml_doc($zip, 'xl/_rels/workbook.xml.rels');
            if ($rels && $rid !== '') {
                foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                    if ($rel->getAttribute('Id') === $rid) {
                        $t = $rel->getAttribute('Target');
                        $sheetPath = ltrim((substr($t, 0, 1) === '/') ? $t : 'xl/' . $t, '/');
                        break;
                    }
                }
            }
        }
    }

    $shared = array();
    $ss = kap_price_xml_doc($zip, 'xl/sharedStrings.xml');
    if ($ss) {
        $x = kap_price_xpath($ss);
        foreach ($x->query('//m:si') as $si) {
            $t = '';
            foreach ($x->query('.//m:t', $si) as $tn) { $t .= $tn->textContent; }
            $shared[] = $t;
        }
    }

    $sheet = kap_price_xml_doc($zip, $sheetPath);
    $zip->close();
    if (!$sheet) { return array(null, 'Could not read the first sheet of the workbook.'); }

    $x = kap_price_xpath($sheet);
    $grid = array();
    foreach ($x->query('//m:sheetData/m:row') as $rowEl) {
        $rowNo = (int) $rowEl->getAttribute('r');
        foreach ($x->query('m:c', $rowEl) as $c) {
            $ref = $c->getAttribute('r');
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) { continue; }
            $col = kap_price_col_index($m[1]);
            $rn  = (int) $m[2];
            $type = $c->getAttribute('t');

            $text = ''; $isNum = false;
            if ($type === 's') {
                $v = $x->query('m:v', $c);
                $idx = $v->length ? (int) $v->item(0)->textContent : -1;
                $text = isset($shared[$idx]) ? $shared[$idx] : '';
            } elseif ($type === 'inlineStr') {
                foreach ($x->query('.//m:t', $c) as $tn) { $text .= $tn->textContent; }
            } elseif ($type === 'str') {
                $v = $x->query('m:v', $c);
                $text = $v->length ? $v->item(0)->textContent : '';
            } elseif ($type === 'b' || $type === 'e') {
                continue;
            } else {
                $v = $x->query('m:v', $c);
                $text = $v->length ? $v->item(0)->textContent : '';
                $isNum = true;
            }
            if (trim($text) === '') { continue; }
            $grid[$rn][$col] = array($text, $isNum);
        }
    }
    ksort($grid);
    return array($grid, '');
}

/** Read a .csv (comma, semicolon or tab separated) into the same grid shape. */
function kap_price_read_csv($path) {
    $raw = @file_get_contents($path);
    if ($raw === false) { return array(null, 'Could not read the file.'); }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") { $raw = substr($raw, 3); }
    $nl = strpos($raw, "\n");
    $head = $nl === false ? $raw : substr($raw, 0, $nl);
    $delim = ',';
    if (substr_count($head, ';') > substr_count($head, $delim)) { $delim = ';'; }
    if (substr_count($head, "\t") > substr_count($head, $delim)) { $delim = "\t"; }

    $grid = array();
    $n = 0;
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $n++;
        if (trim($line) === '') { continue; }
        $cells = str_getcsv($line, $delim);
        foreach ($cells as $i => $val) {
            if (trim($val) === '') { continue; }
            $grid[$n][$i] = array($val, false);
        }
    }
    return array($grid, '');
}

/**
 * Turn a grid into flat rows: array(array(row, slug, price, isNumeric)).
 * A first row whose cells say "slug(s)" / "price(s)" is a header (and tells us
 * which columns to use); otherwise column A is the slug and B the price.
 */
function kap_price_grid_rows($grid) {
    $slugCol = 0; $priceCol = 1; $skipFirst = false;
    if ($grid) {
        $firstKey = key($grid);
        foreach ($grid[$firstKey] as $col => $cell) {
            $t = strtolower(trim($cell[0]));
            if ($t === 'slug' || $t === 'slugs')   { $slugCol = $col;  $skipFirst = true; }
            if ($t === 'price' || $t === 'prices') { $priceCol = $col; $skipFirst = true; }
        }
    }
    $rows = array();
    $first = true;
    foreach ($grid as $rowNo => $cells) {
        if ($first && $skipFirst) { $first = false; continue; }
        $first = false;
        $slug  = isset($cells[$slugCol])  ? $cells[$slugCol][0]  : '';
        $price = isset($cells[$priceCol]) ? $cells[$priceCol][0] : '';
        $isNum = isset($cells[$priceCol]) ? $cells[$priceCol][1] : false;
        $rows[] = array('row' => $rowNo, 'slug' => $slug, 'price' => $price, 'num' => $isNum);
        if (count($rows) > KAP_PRICE_MAX_ROWS) { break; }
    }
    return $rows;
}

/** @return array(array|null $rows, string $error) */
function kap_price_read_upload($tmpPath, $origName) {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx')      { list($grid, $err) = kap_price_read_xlsx($tmpPath); }
    elseif ($ext === 'csv')   { list($grid, $err) = kap_price_read_csv($tmpPath); }
    else { return array(null, 'Upload an .xlsx or .csv file.'); }
    if ($grid === null) { return array(null, $err); }

    $rows = kap_price_grid_rows($grid);
    if (count($rows) > KAP_PRICE_MAX_ROWS) {
        return array(null, 'The file has more than ' . KAP_PRICE_MAX_ROWS . ' rows.');
    }
    return array($rows, '');
}

/* ========================================================================== *
 *  CLASSIFY, BACK UP, APPLY CASE 1
 * ========================================================================== */

function kap_price_private_sub($sub) {
    $d = KAP_PRIVATE_DIR . '/' . $sub;
    if (!is_dir($d) && !@mkdir($d, 0755, true)) { return ''; }
    return $d;
}

/** True once the migration has added products.price / products.unavailable. */
function kap_price_schema_ready(PDO $pdo) {
    $n = 0;
    foreach ($pdo->query("SHOW COLUMNS FROM products WHERE Field IN ('price','unavailable')") as $r) { $n++; }
    return $n === 2;
}

/**
 * Work out which slug lands in which case. Pure: reads the DB, changes nothing.
 *
 * @param  array $rows from kap_price_read_upload
 * @return array {
 *   skipped: [{row, slug, reason}], rowsInFile, valid: slug=>price,
 *   case1: [realSlug => newPrice], case2: [slug], case3: [{slug,price}],
 *   old: lowerSlug => {slug, price, unavailable}
 * }
 */
function kap_price_classify(PDO $pdo, array $rows) {
    $skipped = array();
    $inFile  = array();        // every slug the sheet mentions (protected from case 2)
    $count   = array();        // occurrences per slug
    $parsed  = array();        // lowerSlug => array(price|null, reason, firstRow)
    $rowsInFile = 0;

    foreach ($rows as $r) {
        $slug = strtolower(trim($r['slug']));
        $priceTxt = trim($r['price']);
        if ($slug === '' && $priceTxt === '') { continue; }          // blank line
        $rowsInFile++;
        if ($slug === '') {
            $skipped[] = array('row' => $r['row'], 'slug' => '', 'reason' => 'missing slug');
            continue;
        }
        $inFile[$slug] = true;
        $count[$slug] = (isset($count[$slug]) ? $count[$slug] : 0) + 1;
        if (!isset($parsed[$slug])) {
            list($p, $why) = kap_price_parse($r['price'], $r['num']);
            $parsed[$slug] = array($p, $why, $r['row']);
        }
    }

    // Duplicates are ambiguous (which price is right?), so none of their rows apply.
    $valid = array();
    foreach ($parsed as $slug => $info) {
        if ($count[$slug] > 1) {
            $skipped[] = array('row' => $info[2], 'slug' => $slug,
                'reason' => 'listed ' . $count[$slug] . ' times in the file');
        } elseif ($info[0] === null) {
            $skipped[] = array('row' => $info[2], 'slug' => $slug, 'reason' => $info[1]);
        } else {
            $valid[$slug] = $info[0];
        }
    }

    $old = array();
    foreach ($pdo->query('SELECT slug, name, price, unavailable FROM products') as $p) {
        $old[strtolower($p['slug'])] = array(
            'slug' => $p['slug'],
            'name' => (string) $p['name'],
            'price' => $p['price'] === null ? null : (float) $p['price'],
            'unavailable' => (int) $p['unavailable'],
        );
    }

    $case1 = array(); $case2 = array(); $case3 = array();
    foreach ($valid as $slug => $price) {
        if (isset($old[$slug])) { $case1[$old[$slug]['slug']] = $price; continue; }
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) && strlen($slug) <= 100) {
            $case3[] = array('slug' => $slug, 'price' => $price);
        } else {
            $skipped[] = array('row' => $parsed[$slug][2], 'slug' => $slug,
                'reason' => 'not in the database and not a valid slug, so it cannot be created');
        }
    }
    foreach ($old as $low => $o) {
        if (!isset($inFile[$low])) { $case2[] = $o['slug']; }
    }

    usort($skipped, function ($a, $b) { return $a['row'] - $b['row']; });
    return array(
        'skipped' => $skipped, 'rowsInFile' => $rowsInFile, 'valid' => $valid,
        'case1' => $case1, 'case2' => $case2, 'case3' => $case3, 'old' => $old,
    );
}

/** Raw rows of the given products and their child tables, for a true restore. */
function kap_price_raw_dump(PDO $pdo, array $slugs) {
    $out = array('products' => array(), 'product_list' => array(),
                 'product_article_code' => array(), 'product_spec_code' => array());
    foreach (array_chunk($slugs, 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        foreach (array_keys($out) as $table) {
            $st = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE slug IN (' . $in . ')');
            $st->execute($chunk);
            foreach ($st as $row) { $out[$table][] = $row; }
        }
    }
    return $out;
}

/**
 * Snapshot written BEFORE anything changes: every product's current price and
 * availability, plus the complete rows of every product that could be deleted
 * in case 2. Returns the file name (inside private/price-backups/) or ''.
 */
function kap_price_write_backup(PDO $pdo, array $class, $uploadName) {
    $dir = kap_price_private_sub('price-backups');
    if ($dir === '') { return ''; }

    $prices = array();
    foreach ($class['old'] as $o) {
        $prices[] = array('slug' => $o['slug'], 'price' => $o['price'], 'unavailable' => $o['unavailable']);
    }
    $name = 'pricing-backup-' . date('Ymd-His') . '.json';
    $data = array(
        'created'      => date('c'),
        'uploadedFile' => $uploadName,
        'note'         => 'prices = state of every product before this upload. '
                        . 'case2Products = full rows of products that the admin could delete in step 2.',
        'prices'       => $prices,
        'case2Products' => kap_price_raw_dump($pdo, $class['case2']),
    );
    return atomic_write_json($dir . '/' . $name, $data) ? $name : '';
}

/**
 * Case 1: set the new price on every product present in both places.
 * Also clears `unavailable`, so a product that was withdrawn and reappears in a
 * later sheet comes back with its price.
 *
 * @return array(updated, unchanged, restored, details) where details lists every
 *         product that was touched: {slug, name, from (float|null), to, restored}.
 */
function kap_price_apply_case1(PDO $pdo, array $class) {
    $updated = 0; $unchanged = 0; $restored = 0;
    $details = array();
    $upd = $pdo->prepare('UPDATE products SET price = ?, unavailable = 0 WHERE slug = ?');
    $pdo->beginTransaction();
    try {
        foreach ($class['case1'] as $slug => $price) {
            $o = $class['old'][strtolower($slug)];
            $priceSame = ($o['price'] !== null && abs($o['price'] - $price) < 0.004);
            if ($priceSame && !$o['unavailable']) { $unchanged++; continue; }
            $upd->execute(array(number_format($price, 2, '.', ''), $slug));
            if (!$priceSame)         { $updated++; }
            if ($o['unavailable'])   { $restored++; }
            $details[] = array('slug' => $slug, 'name' => $o['name'], 'from' => $o['price'],
                               'to' => $price, 'restored' => (bool) $o['unavailable']);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return array($updated, $unchanged, $restored, $details);
}

/**
 * What each case 2 product looked like before the admin's choice, for the
 * report ("deleted: X, was 20.00 €"). Keyed lookups into the classify() result.
 */
function kap_price_case2_info(array $class) {
    $out = array();
    foreach ($class['case2'] as $slug) {
        $o = $class['old'][strtolower($slug)];
        $out[] = array('slug' => $slug, 'name' => $o['name'], 'price' => $o['price']);
    }
    return $out;
}

/* ========================================================================== *
 *  THE PENDING PLAN (survives between the admin's button clicks)
 * ========================================================================== */

function kap_price_plan_path($token) {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) { return ''; }
    $dir = kap_price_private_sub('price-plans');
    return $dir === '' ? '' : $dir . '/plan-' . $token . '.json';
}

function kap_price_plan_save(array $plan) {
    $path = kap_price_plan_path($plan['token']);
    return $path !== '' && atomic_write_json($path, $plan);
}

/** The plan for a token, or null if unknown, expired or not this session's. */
function kap_price_plan_load($token) {
    if (empty($_SESSION['price_plan']) || !is_string($token)
        || !hash_equals($_SESSION['price_plan'], $token)) { return null; }
    $path = kap_price_plan_path($token);
    if ($path === '') { return null; }
    $plan = read_json($path, null);
    if (!is_array($plan) || empty($plan['created'])) { return null; }
    if (time() - (int) $plan['created'] > KAP_PLAN_TTL) { @unlink($path); return null; }
    return $plan;
}

/** Drop plan files older than a day (they would otherwise pile up). */
function kap_price_plan_sweep() {
    $dir = kap_price_private_sub('price-plans');
    if ($dir === '') { return; }
    foreach ((array) glob($dir . '/plan-*.json') as $f) {
        if (@filemtime($f) < time() - 86400) { @unlink($f); }
    }
}

/** The first step that still has something for the admin to decide. */
function kap_price_next_step(array $plan, $after) {
    if ($after === 'start' && count($plan['case2'])) { return 'case2'; }
    if (($after === 'start' || $after === 'case2') && count($plan['case3'])) { return 'case3'; }
    return 'done';
}

/** The slice of the plan the browser needs (no file paths, no old-price map). */
function kap_price_plan_view(array $plan) {
    return array(
        'token'   => $plan['token'],
        'step'    => $plan['step'],
        'file'    => $plan['file'],
        'backup'  => $plan['backup'],
        'case2'   => $plan['case2'],
        'case3'   => $plan['case3'],
        'skipped' => $plan['skipped'],
        'stats'   => $plan['stats'],
        'changes' => isset($plan['changes']) ? $plan['changes'] : array(),
        'expires' => (int) $plan['created'] + KAP_PLAN_TTL,
    );
}

/* ========================================================================== *
 *  CASE 2 AND CASE 3 ACTIONS
 * ========================================================================== */

/**
 * Case 2, "delete": remove the product rows (child rows go with them through
 * ON DELETE CASCADE). Image FILES are deliberately left on the server so a
 * deleted product can be rebuilt from the backup.
 */
function kap_price_case2_delete(PDO $pdo, array $slugs) {
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($slugs as $slug) { if (kap_product_delete($pdo, $slug)) { $n++; } }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $n;
}

/** Case 2, "keep, unavailable": clear the price and raise the flag. */
function kap_price_case2_unavailable(PDO $pdo, array $slugs) {
    $n = 0;
    $st = $pdo->prepare('UPDATE products SET price = NULL, unavailable = 1 WHERE slug = ?');
    $pdo->beginTransaction();
    try {
        foreach ($slugs as $slug) { $st->execute(array($slug)); if ($st->rowCount() > 0) { $n++; } }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $n;
}

/**
 * Case 3, "add": create bare products — slug as the title, no photos or text,
 * HIDDEN, in the placeholder category — carrying the price from the file. The
 * admin finishes each one in the Products tab (category is editable there) and
 * unticks "Hide from the public site" when it is ready.
 *
 * @return array(added, alreadyExisted, addedItems) or string error message
 */
function kap_price_case3_add(PDO $pdo, array $items) {
    $st = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
    $st->execute(array(KAP_PLACEHOLDER_CAT));
    if (!$st->fetch()) {
        return 'The placeholder category "' . KAP_PLACEHOLDER_CAT . '" is missing. Run the pricing migration SQL first.';
    }

    $added = 0; $existed = 0; $addedItems = array();
    $chk = $pdo->prepare('SELECT 1 FROM products WHERE slug = ?');
    $ord = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM products WHERE category_id = ?');
    $ins = $pdo->prepare('INSERT INTO products (slug, category_id, sort_order, name, hidden, is_new, price, unavailable)
                          VALUES (?,?,?,?,1,0,?,0)');
    $pdo->beginTransaction();
    try {
        foreach ($items as $it) {
            $chk->execute(array($it['slug']));
            if ($chk->fetch()) { $existed++; continue; }
            $ord->execute(array(KAP_PLACEHOLDER_CAT));
            $ins->execute(array($it['slug'], KAP_PLACEHOLDER_CAT, (int) $ord->fetchColumn(),
                                $it['slug'], number_format($it['price'], 2, '.', '')));
            $added++;
            $addedItems[] = array('slug' => $it['slug'], 'price' => $it['price']);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return array($added, $existed, $addedItems);
}
