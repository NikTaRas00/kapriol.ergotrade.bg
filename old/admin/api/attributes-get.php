<?php
/**
 * attributes-get.php — return the master spec-code list (Performance / Safety
 * class / Technologies), each an array of { code, name, img? }.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$default = array('performance' => array(), 'safetyClass' => array(), 'technologies' => array());
$attrs = read_json(KAP_ATTRIBUTES, $default);
if (!is_array($attrs)) { $attrs = $default; }
foreach (array('performance', 'safetyClass', 'technologies') as $g) {
    if (!isset($attrs[$g]) || !is_array($attrs[$g])) { $attrs[$g] = array(); }
}

json_out(array('success' => true, 'attributes' => $attrs));
