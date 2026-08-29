<?php
/**
 * enquiries-list.php — return the stored contact submissions (newest first).
 * Auth required; read-only (no CSRF).
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$list = read_json(APP_ENQUIRIES, array());
if (!is_array($list)) { $list = array(); }
json_out(array_reverse($list));
