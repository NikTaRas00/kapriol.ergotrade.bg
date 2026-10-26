<?php
/**
 * pricing-upload.php — "Upload all pricing".
 *
 * Auth + CSRF protected. Takes a multipart upload (field `file`, .xlsx or .csv)
 * keyed by ARTICLE CODE: an Item/Code column and a price column. In this order:
 *
 *   1. read and validate the file,
 *   2. classify everything BEFORE touching anything (see _pricing.php),
 *   3. write a backup snapshot to private/price-backups/,
 *   4. apply: code prices, products' first-code price, and visibility (products
 *      with no code in the file are hidden; ones this flow hid and that are
 *      listed again are shown),
 *   5. return the report in the same response.
 *
 * Returns { success, report }.
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_pricing.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])
    || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_out(array('success' => false, 'error' => 'No file received. Choose an .xlsx or .csv file and try again.'), 422);
}
if ($_FILES['file']['size'] > 8 * 1024 * 1024) {
    json_out(array('success' => false, 'error' => 'The file is larger than 8 MB.'), 422);
}

$origName = basename((string) $_FILES['file']['name']);
list($rows, $err) = kap_price_read_upload($_FILES['file']['tmp_name'], $origName);
if ($rows === null) {
    json_out(array('success' => false, 'error' => $err), 422);
}

$pdo = kap_db();
try {
    if (!kap_price_schema_ready($pdo)) {
        json_out(array('success' => false,
            'error' => 'The database is not ready for code prices yet. Run the codes migration SQL first.'), 500);
    }

    $class = kap_price_classify($pdo, $rows);

    // Nothing in the file matches a code on the site: almost certainly the wrong
    // file or the wrong column, and applying it would hide the whole catalogue.
    $matched = $class['unchanged'] + count($class['updates']);
    if (!$class['valid'] || $matched === 0) {
        json_out(array('success' => false,
            'error' => 'None of the codes in the file match a code on the site, so nothing was changed. '
                     . 'Expected a column of article codes (e.g. 144330) and a column of prices.',
            'skipped' => $class['skipped']), 422);
    }

    $backup = kap_price_write_backup($pdo, $origName);
    if ($backup === '') {
        json_out(array('success' => false,
            'error' => 'Could not write the backup file, so nothing was changed.'), 500);
    }

    $done = kap_price_apply($pdo, $class);
} catch (Exception $e) {
    error_log('kapriol pricing-upload: ' . $e->getMessage());
    json_out(array('success' => false, 'error' => 'Upload failed and was rolled back. Nothing was changed.'), 500);
}

$report = array(
    'file'    => $origName,
    'backup'  => $backup,
    'skipped' => $class['skipped'],
    'missing' => array_slice($class['missing'], 0, 300),
    'changes' => $done,
    'stats'   => array(
        'rows'          => $class['rowsInFile'],
        'valid'         => count($class['valid']),
        'codesChanged'  => count($class['updates']),
        'codesSame'     => $class['unchanged'],
        'productsPriced' => count($done['updated']),
        'hidden'        => count($done['hidden']),
        'shown'         => count($done['shown']),
        'notOnSite'     => $class['notOnSite'],
        'missing'       => count($class['missing']),
    ),
);

json_out(array('success' => true, 'report' => $report), 200);
