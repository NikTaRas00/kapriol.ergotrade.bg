<?php
/**
 * products-get.php — the full catalogue for the editor.
 *
 * Includes HIDDEN products: the admin has to be able to see and un-hide them.
 * The public endpoint (api/products.php) excludes them in SQL instead. Both
 * assemble records through the same kap_products() mapping, so the editor can
 * never show a shape the site does not render.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$pdo = kap_db();
json_out(array('success' => true, 'products' => kap_products($pdo, true)));
