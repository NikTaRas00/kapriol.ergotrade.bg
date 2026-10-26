<?php
/**
 * _pricing.php — the "Upload all pricing" machinery, plus the category move the
 * product editor needs.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  Shared by pricing-upload.php, pricing-backup.php and products-save.php.
 *  Never an entry point — denied by the root .htaccess (^_.*\.php). Requires
 *  _guard.php to have been included first (KAP_PRIVATE_DIR, atomic_write_json,
 *  kap_db()).
 *
 *  THE FLOW (one step, no questions)
 *
 *    The sheet is keyed by ARTICLE CODE (the supplier's "Item" column, which is
 *    our code with a leading "1"), not by slug. Each code has its own price in
 *    product_article_code.price. A product shows the price of its first priced
 *    code.
 *
 *    1. pricing-upload.php reads the .xlsx/.csv and classifies everything before
 *       changing anything.
 *    2. A backup snapshot is written.
 *    3. In one transaction: codes found in the sheet get the sheet's price;
 *       products with NO code anywhere in the sheet are hidden (hidden_by_upload
 *       = 1); products the upload hid earlier that are listed again are shown
 *       again. A product hidden by hand in the admin is never touched.
 *    4. The report is returned in the same response.
 *
 *    A code that is in the sheet but cannot be applied (bad price, conflicting
 *    duplicate rows) is reported as skipped and still counts as "in the sheet",
 *    so a typo can never cause a product to be hidden. Codes in the sheet that
 *    are not on the site are ignored (the supplier list is far bigger than our
 *    catalogue) and only counted. Codes of a listed product that are missing
 *    from the sheet are left as they are and listed in the report.
 *
 *  PRICES: DECIMAL(8,2), euros, VAT included.
 * ──────────────────────────────────────────────────────────────────────────
 */

if (defined('KAP_PRICING_LOADED')) { return; }
define('KAP_PRICING_LOADED', true);

define('KAP_PLACEHOLDER_CAT', 'unsorted');
define('KAP_PRICE_MAX', 99999.99);
define('KAP_PRICE_MAX_ROWS', 20000);
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
 * Turn a grid into flat rows: array(array(row, code, price, num, color)).
 *
 * Finds the header row (a cell reading Item / Code / Article within the first 20
 * rows) and takes the price from the column headed Price, else from the
 * rightmost header cell that is not the code, description or colour column
 * (the supplier's sheet heads it with a price-list name such as "1028 NEW").
 * Rows above the header (a date line, say) are ignored. With no header at all,
 * column A is the code and B the price.
 */
function kap_price_grid_rows($grid) {
    $codeCol = 0; $priceCol = 1; $colorCol = null; $headerRow = null;

    $seen = 0;
    foreach ($grid as $rowNo => $cells) {
        if (++$seen > 20) { break; }
        $cc = null;
        foreach ($cells as $col => $cell) {
            $t = strtolower(trim($cell[0]));
            if (in_array($t, array('item', 'code', 'codes', 'article', 'article code', 'item code', 'артикул', 'код'), true)) {
                $cc = $col; break;
            }
        }
        if ($cc === null) { continue; }

        $codeCol = $cc; $headerRow = $rowNo; $colorCol = null;
        $named = null; $rightmost = null;
        foreach ($cells as $col => $cell) {
            if ($col === $cc) { continue; }
            $t = strtolower(trim($cell[0]));
            if (preg_match('/desc|описание/u', $t)) { continue; }
            if (preg_match('/colou?r|цвят/u', $t))  { $colorCol = $col; continue; }
            if (preg_match('/price|цена/u', $t))    { $named = $col; }
            if ($rightmost === null || $col > $rightmost) { $rightmost = $col; }
        }
        $priceCol = $named !== null ? $named : ($rightmost !== null ? $rightmost : 1);
        break;
    }

    $rows = array();
    foreach ($grid as $rowNo => $cells) {
        if ($headerRow !== null && $rowNo <= $headerRow) { continue; }
        $code  = isset($cells[$codeCol])  ? trim($cells[$codeCol][0])  : '';
        $price = isset($cells[$priceCol]) ? $cells[$priceCol][0] : '';
        $isNum = isset($cells[$priceCol]) ? $cells[$priceCol][1] : false;
        // A numeric code cell can come back as "144330.0".
        if (isset($cells[$codeCol]) && $cells[$codeCol][1]) { $code = preg_replace('/\.0+$/', '', $code); }
        $rows[] = array(
            'row'   => $rowNo, 'code' => $code, 'price' => $price, 'num' => $isNum,
            'color' => ($colorCol !== null && isset($cells[$colorCol])) ? trim($cells[$colorCol][0]) : '',
        );
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
 *  CLASSIFY, BACK UP, APPLY
 * ========================================================================== */

function kap_price_private_sub($sub) {
    $d = KAP_PRIVATE_DIR . '/' . $sub;
    if (!is_dir($d) && !@mkdir($d, 0755, true)) { return ''; }
    return $d;
}

/** True once the codes migration has added the two columns this flow needs. */
function kap_price_schema_ready(PDO $pdo) {
    $n = 0;
    foreach ($pdo->query("SHOW COLUMNS FROM product_article_code WHERE Field = 'price'") as $r) { $n++; }
    foreach ($pdo->query("SHOW COLUMNS FROM products WHERE Field = 'hidden_by_upload'") as $r) { $n++; }
    return $n === 2;
}

/** The price a product shows: that of its first code that has one. */
function kap_price_first(array $codes) {
    foreach ($codes as $c) { if ($c['price'] !== null) { return $c['price']; } }
    return null;
}

/**
 * Work out what an upload would change. Pure: reads the DB, changes nothing.
 *
 * @param  array $rows from kap_price_read_upload
 * @return array {
 *   skipped, rowsInFile, valid: code=>price, notOnSite (int),
 *   updates: [{slug, code, from, to}]   article rows whose price changes,
 *   unchanged (int),
 *   products: slug => {name, hidden, byUpload, inFile, codes: [{code,size,price,inFile}]},
 *   hide: [slug], show: [slug], missing: [{slug, code, size}]
 * }
 */
function kap_price_classify(PDO $pdo, array $rows) {
    $skipped = array(); $inFile = array(); $parsed = array(); $rowsInFile = 0;

    foreach ($rows as $r) {
        $code = $r['code'];
        $priceTxt = trim($r['price']);
        if ($code === '' && $priceTxt === '') { continue; }          // blank line
        $rowsInFile++;
        if ($code === '') {
            $skipped[] = array('row' => $r['row'], 'code' => '', 'reason' => 'missing code');
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $code)) {
            $skipped[] = array('row' => $r['row'], 'code' => $code, 'reason' => 'not a valid code');
            continue;
        }
        $inFile[$code] = true;          // protects the code's product from being hidden
        list($p, $why) = kap_price_parse($r['price'], $r['num']);
        $parsed[$code][] = array($p, $why, $r['row']);
    }

    // A code listed twice is fine when every row agrees; conflicting rows are
    // ambiguous, so none of them applies.
    $valid = array();
    foreach ($parsed as $code => $list) {
        $bad = null; $prices = array();
        foreach ($list as $e) {
            if ($e[0] === null) { $bad = $e; break; }
            $prices[number_format($e[0], 2, '.', '')] = true;
        }
        if ($bad !== null) {
            $skipped[] = array('row' => $bad[2], 'code' => $code, 'reason' => $bad[1]);
        } elseif (count($prices) > 1) {
            $skipped[] = array('row' => $list[0][2], 'code' => $code,
                'reason' => 'listed ' . count($list) . ' times with different prices');
        } else {
            $valid[$code] = $list[0][0];
        }
    }

    $products = array(); $onSite = array();
    $sql = 'SELECT a.slug, a.code, a.size, a.price, p.name, p.hidden, p.hidden_by_upload
            FROM product_article_code a JOIN products p ON p.slug = a.slug
            ORDER BY a.slug, a.ord';
    foreach ($pdo->query($sql) as $r) {
        $s = $r['slug'];
        if (!isset($products[$s])) {
            $products[$s] = array('name' => (string) $r['name'], 'hidden' => (int) $r['hidden'],
                                  'byUpload' => (int) $r['hidden_by_upload'], 'codes' => array(), 'inFile' => false);
        }
        $listed = isset($inFile[$r['code']]);
        if ($listed) { $products[$s]['inFile'] = true; }
        $onSite[$r['code']] = true;
        $products[$s]['codes'][] = array('code' => $r['code'], 'size' => (string) $r['size'],
            'price' => $r['price'] === null ? null : (float) $r['price'], 'inFile' => $listed);
    }

    // Products with no article code at all have nothing to be listed by; they
    // are left alone rather than hidden.
    $updates = array(); $unchanged = 0; $hide = array(); $show = array(); $missing = array();
    foreach ($products as $slug => $p) {
        foreach ($p['codes'] as $c) {
            if (isset($valid[$c['code']])) {
                $to = $valid[$c['code']];
                if ($c['price'] !== null && abs($c['price'] - $to) < 0.004) { $unchanged++; }
                else { $updates[] = array('slug' => $slug, 'code' => $c['code'], 'from' => $c['price'], 'to' => $to); }
            } elseif ($p['inFile'] && !$c['inFile']) {
                $missing[] = array('slug' => $slug, 'code' => $c['code'], 'size' => $c['size']);
            }
        }
        if (!$p['inFile'] && !$p['hidden'])                  { $hide[] = $slug; }
        if ($p['inFile'] && $p['hidden'] && $p['byUpload'])  { $show[] = $slug; }
    }

    $notOnSite = 0;
    foreach ($valid as $code => $_) { if (!isset($onSite[$code])) { $notOnSite++; } }

    return array(
        'skipped' => $skipped, 'rowsInFile' => $rowsInFile, 'valid' => $valid, 'notOnSite' => $notOnSite,
        'updates' => $updates, 'unchanged' => $unchanged, 'products' => $products,
        'hide' => $hide, 'show' => $show, 'missing' => $missing,
    );
}

/**
 * Snapshot written BEFORE anything changes: every code's price and every
 * product's visibility flags. Returns the file name (in private/price-backups/)
 * or ''.
 */
function kap_price_write_backup(PDO $pdo, $uploadName) {
    $dir = kap_price_private_sub('price-backups');
    if ($dir === '') { return ''; }

    $codes = array();
    foreach ($pdo->query('SELECT slug, code, price FROM product_article_code ORDER BY slug, ord') as $r) {
        $codes[] = array('slug' => $r['slug'], 'code' => $r['code'],
                         'price' => $r['price'] === null ? null : (float) $r['price']);
    }
    $prods = array();
    foreach ($pdo->query('SELECT slug, hidden, hidden_by_upload, price FROM products') as $r) {
        $prods[] = array('slug' => $r['slug'], 'hidden' => (int) $r['hidden'],
                         'hidden_by_upload' => (int) $r['hidden_by_upload'],
                         'price' => $r['price'] === null ? null : (float) $r['price']);
    }
    $name = 'pricing-backup-' . date('Ymd-His') . '.json';
    $data = array(
        'created' => date('c'), 'uploadedFile' => $uploadName,
        'note' => 'codes = price of every article code before this upload; '
                . 'products = visibility flags (and stored price) of every product before it.',
        'codes' => $codes, 'products' => $prods,
    );
    return atomic_write_json($dir . '/' . $name, $data) ? $name : '';
}

/**
 * Apply an upload in one transaction: code prices, then products.price (first
 * priced code), then visibility (hide products with no code in the file; show
 * again the ones the upload itself had hidden).
 *
 * @return array {updated:[{slug,name,codes,from,to}], hidden:[{slug,name}], shown:[{slug,name}]}
 */
function kap_price_apply(PDO $pdo, array $class) {
    $upd  = $pdo->prepare('UPDATE product_article_code SET price = ? WHERE slug = ? AND code = ?');
    $hide = $pdo->prepare('UPDATE products SET hidden = 1, hidden_by_upload = 1 WHERE slug = ?');
    $show = $pdo->prepare('UPDATE products SET hidden = 0, hidden_by_upload = 0 WHERE slug = ?');

    $touched = array();
    foreach ($class['updates'] as $u) { $touched[$u['slug']] = true; }
    $before = array();
    foreach (array_keys($touched) as $slug) { $before[$slug] = kap_price_first($class['products'][$slug]['codes']); }

    $pdo->beginTransaction();
    try {
        foreach ($class['updates'] as $u) {
            $upd->execute(array(number_format($u['to'], 2, '.', ''), $u['slug'], $u['code']));
        }
        foreach (array_keys($touched) as $slug) { kap_sync_product_price($pdo, $slug); }
        foreach ($class['hide'] as $slug) { $hide->execute(array($slug)); }
        foreach ($class['show'] as $slug) { $show->execute(array($slug)); }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    // Per-product report: how many codes changed and what the product now shows.
    $perProduct = array();
    foreach ($class['updates'] as $u) {
        $s = $u['slug'];
        if (!isset($perProduct[$s])) { $perProduct[$s] = array('codes' => 0, 'new' => array()); }
        $perProduct[$s]['codes']++;
        $perProduct[$s]['new'][$u['code']] = $u['to'];
    }
    $updated = array();
    foreach ($perProduct as $slug => $info) {
        $codes = $class['products'][$slug]['codes'];
        foreach ($codes as $i => $c) {
            if (isset($info['new'][$c['code']])) { $codes[$i]['price'] = $info['new'][$c['code']]; }
        }
        $updated[] = array('slug' => $slug, 'name' => $class['products'][$slug]['name'],
                           'codes' => $info['codes'], 'from' => $before[$slug], 'to' => kap_price_first($codes));
    }
    $label = function ($slug) use ($class) {
        return array('slug' => $slug, 'name' => $class['products'][$slug]['name']);
    };
    return array('updated' => $updated,
                 'hidden'  => array_map($label, $class['hide']),
                 'shown'   => array_map($label, $class['show']));
}
