<?php
/**
 * products.php — the public catalogue.
 *
 * Replaces assets/data/products.json. Returns a BARE JSON ARRAY of product
 * records in the original file order (category-grouped), with hidden products
 * removed in SQL.
 *
 * The array shape is deliberately unchanged from the old file: main.js filters,
 * searches, counts and builds its filter-chip vocabulary from the full set in
 * the browser, so this endpoint hands over the full set exactly as before.
 * Query parameters (?cat=, ?codes=, ?page=) are intentionally NOT implemented —
 * the schema and indexes support adding them later without another migration.
 *
 * Hidden filtering moved from the browser (old main.js loadProducts) into SQL,
 * which also means hidden records are no longer shipped to the client at all.
 */
require __DIR__ . '/_bootstrap.php';
kap_api_require_get();

$pdo = kap_api_db();
try {
    kap_api_out(kap_products($pdo, false));
} catch (Exception $e) {
    error_log('kapriol api/products: ' . $e->getMessage());
    kap_api_fail('Catalogue unavailable.', 503);
}
