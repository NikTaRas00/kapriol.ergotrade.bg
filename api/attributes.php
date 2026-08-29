<?php
/**
 * attributes.php — the spec-code dictionary.
 *
 * Replaces assets/data/attributes.json. Returns an OBJECT keyed by the three
 * spec groups (performance / safetyClass / technologies), each an array of
 * { code, img?, title?, description? }.
 *
 * main.js keys off these group names directly (SPEC_GROUPS) and tolerates a
 * missing entry, so a code with no dictionary row simply renders as a bare
 * inert chip rather than an error.
 */
require __DIR__ . '/_bootstrap.php';
kap_api_require_get();

$pdo = kap_api_db();
try {
    kap_api_out(kap_attributes($pdo));
} catch (Exception $e) {
    error_log('kapriol api/attributes: ' . $e->getMessage());
    kap_api_fail('Attributes unavailable.', 503);
}
