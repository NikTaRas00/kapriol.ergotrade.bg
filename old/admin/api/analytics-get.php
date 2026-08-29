<?php
/**
 * analytics-get.php — return the aggregated visitor stats for the admin.
 * Auth required (read-only). Data is written by the public track.php beacon.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$data = read_json(KAP_ANALYTICS, array());
if (!is_array($data)) { $data = array(); }

// Normalise so the front-end can rely on the shape even before any hit lands.
$out = array(
    'totals'   => isset($data['totals']) && is_array($data['totals']) ? $data['totals'] : array('views' => 0, 'visits' => 0),
    'daily'    => isset($data['daily']) && is_array($data['daily']) ? $data['daily'] : array(),
    'pages'    => isset($data['pages']) && is_array($data['pages']) ? $data['pages'] : array(),
    'products' => isset($data['products']) && is_array($data['products']) ? $data['products'] : array(),
);

json_out(array('success' => true, 'analytics' => $out));
