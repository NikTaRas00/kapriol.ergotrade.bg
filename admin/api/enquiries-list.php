<?php
/**
 * enquiries-list.php — return stored contact enquiries, newest first.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$list = read_json(KAP_ENQUIRIES, array());
if (!is_array($list)) { $list = array(); }

json_out(array(
    'success'    => true,
    'enquiries'  => array_reverse($list),
));
