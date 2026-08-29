<?php
/**
 * attributes-get.php — the master spec-code list (Performance / Safety class /
 * Technologies), each an array of { code, img?, title?, description? }.
 *
 * Always returns all three groups, even when empty, because admin.js indexes
 * them by name.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$pdo = kap_db();
$attrs = kap_attributes($pdo);
foreach (array('performance', 'safetyClass', 'technologies') as $g) {
    if (!isset($attrs[$g]) || !is_array($attrs[$g])) { $attrs[$g] = array(); }
}
json_out(array('success' => true, 'attributes' => $attrs));
