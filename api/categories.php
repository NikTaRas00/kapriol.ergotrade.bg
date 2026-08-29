<?php
/**
 * categories.php — category tile images.
 *
 * Replaces the `categories` block that used to live in content.json. Returns
 * an OBJECT keyed by category id: { "<catId>": { img, imgV } }. Categories
 * with no uploaded photo are omitted entirely, which is what makes
 * categoryImg() in main.js fall through to its flat-panel empty state.
 *
 * Site copy (hero, why, rotator, contact, footer) deliberately stays in
 * content.json — only the catalogue moved to the database.
 */
require __DIR__ . '/_bootstrap.php';
kap_api_require_get();

$pdo = kap_api_db();
try {
    // Force an object in JSON: an empty PHP array would encode as [] and
    // main.js indexes this by category id.
    $rows = kap_category_images($pdo);
    kap_api_out($rows ? $rows : new stdClass());
} catch (Exception $e) {
    error_log('kapriol api/categories: ' . $e->getMessage());
    kap_api_fail('Categories unavailable.', 503);
}
