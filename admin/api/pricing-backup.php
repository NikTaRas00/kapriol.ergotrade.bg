<?php
/**
 * pricing-backup.php — download one of the snapshots written by pricing-upload.
 *
 * GET ?f=pricing-backup-YYYYMMDD-HHMMSS.json. Auth required. The name is
 * matched against a strict pattern and resolved inside private/price-backups/
 * only, so it cannot be used to read any other file.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$f = isset($_GET['f']) ? (string) $_GET['f'] : '';
if (!preg_match('/^pricing-backup-\d{8}-\d{6}\.json$/', $f)) {
    json_out(array('success' => false, 'error' => 'Bad file name.'), 400);
}
$path = KAP_PRIVATE_DIR . '/price-backups/' . $f;
if (!is_file($path)) {
    json_out(array('success' => false, 'error' => 'Backup not found.'), 404);
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $f . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($path);
exit;
