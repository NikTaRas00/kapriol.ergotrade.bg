<?php
/**
 * products-get.php — return the full product catalog for the editor.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$products = read_json(KAP_PRODUCTS, array());
if (!is_array($products)) { $products = array(); }

json_out(array('success' => true, 'products' => $products));
