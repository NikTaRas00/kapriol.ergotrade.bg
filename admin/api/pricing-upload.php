<?php
/**
 * pricing-upload.php — "Upload all pricing", step one.
 *
 * Auth + CSRF protected. Takes a multipart upload (field `file`, .xlsx or .csv:
 * one column of slugs, one of prices). It does, in this order:
 *
 *   1. read and validate the file,
 *   2. classify every slug into case 1 / 2 / 3 BEFORE touching anything
 *      (see _pricing.php for the rules),
 *   3. write a backup snapshot to private/price-backups/,
 *   4. apply case 1 (price updates) straight away,
 *   5. save the plan so pricing-apply.php can run cases 2 and 3 on the admin's
 *      say-so, against exactly these numbers.
 *
 * Returns { success, plan } — see kap_price_plan_view().
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
if ($_FILES['file']['size'] > 2 * 1024 * 1024) {
    json_out(array('success' => false, 'error' => 'The file is larger than 2 MB.'), 422);
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
            'error' => 'The database has no price columns yet. Run the pricing migration SQL first.'), 500);
    }

    $class = kap_price_classify($pdo, $rows);
    if (!$class['valid']) {
        json_out(array('success' => false,
            'error' => 'No usable price rows were found in the file, so nothing was changed. '
                     . 'Expected one column of slugs and one of prices.',
            'skipped' => $class['skipped']), 422);
    }

    $backup = kap_price_write_backup($pdo, $class, $origName);
    if ($backup === '') {
        json_out(array('success' => false,
            'error' => 'Could not write the backup file, so nothing was changed.'), 500);
    }

    list($updated, $unchanged, $restored, $details) = kap_price_apply_case1($pdo, $class);
} catch (Exception $e) {
    error_log('kapriol pricing-upload: ' . $e->getMessage());
    json_out(array('success' => false, 'error' => 'Upload failed: nothing further was changed.'), 500);
}

kap_price_plan_sweep();

$plan = array(
    'token'   => bin2hex(random_bytes(16)),
    'created' => time(),
    'file'    => $origName,
    'backup'  => $backup,
    'case2'   => $class['case2'],
    'case3'   => $class['case3'],
    'case2Info' => kap_price_case2_info($class),
    'skipped' => $class['skipped'],
    // Per-product detail for the report: who changed and by how much. The
    // deleted / unavailable / added lists fill in as the admin picks.
    'changes' => array(
        'updated'     => $details,
        'deleted'     => array(),
        'unavailable' => array(),
        'added'       => array(),
    ),
    'stats'   => array(
        'rows'        => $class['rowsInFile'],
        'valid'       => count($class['valid']),
        'updated'     => $updated,
        'unchanged'   => $unchanged,
        'restored'    => $restored,
        'case2Count'  => count($class['case2']),
        'case3Count'  => count($class['case3']),
        'case2Choice' => '',
        'case3Choice' => '',
        'deleted'     => 0,
        'unavailable' => 0,
        'added'       => 0,
        'addedExisted' => 0,
    ),
);
$plan['step'] = kap_price_next_step($plan, 'start');

if (!kap_price_plan_save($plan)) {
    json_out(array('success' => false,
        'error' => 'Prices were updated (case 1), but the follow-up plan could not be saved. '
                 . 'Upload the same file again to continue with the remaining products.'), 500);
}
$_SESSION['price_plan'] = $plan['token'];

json_out(array('success' => true, 'plan' => kap_price_plan_view($plan)), 200);
