<?php
/**
 * content-get.php — return the editable site content. Auth required; read-only.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

json_out(read_json(APP_CONTENT, array('copy' => array(), 'visible' => array(), 'categories' => array(), 'home' => array())));
