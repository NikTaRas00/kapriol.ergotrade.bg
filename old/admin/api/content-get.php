<?php
/**
 * content-get.php — return the editable site content (copy + best sellers).
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$content = read_json(KAP_CONTENT, array('copy' => new stdClass(), 'best' => array()));
json_out(array('success' => true, 'content' => $content));
