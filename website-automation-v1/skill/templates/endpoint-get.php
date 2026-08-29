<?php
/**
 * <thing>-get.php — read endpoint. Auth required; no CSRF (read-only).
 * Copy per collection; point it at the right APP_ constant.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

json_out(read_json(APP_ITEMS, array()));
