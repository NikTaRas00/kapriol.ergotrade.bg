<?php
/**
 * _bootstrap.php — shared setup for the PUBLIC read endpoints.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  These endpoints replace the static assets/data/*.json files the front end
 *  used to fetch. They are READ-ONLY and unauthenticated, by design: the data
 *  they return was world-readable as flat JSON before this migration, so
 *  nothing is newly exposed. They accept GET only and take no parameters —
 *  there is no user input to inject with.
 *
 *  Denied to the web by the root .htaccess (FilesMatch on _*.php), same as
 *  admin/api/_guard.php.
 * ──────────────────────────────────────────────────────────────────────────
 *
 *  CONTRACT: each endpoint must emit EXACTLY the JSON shape the corresponding
 *  file used to serve, because main.js parses it unchanged. A bare array for
 *  products; an object for attributes and categories.
 *
 *  FAILURE BEHAVIOUR matters as much as success. cachedJSON() in main.js turns
 *  any non-2xx or unparseable response into cb(null) (main.js:175-181), and
 *  the callers already handle that: the catalogue shows "Catalogue
 *  unavailable", the home and contact pages silently keep their HTML
 *  fallbacks. So on a database failure we return a non-200 and let that
 *  existing path run — never a 200 with an empty array, which would render as
 *  "no products found" and look like the catalogue had been emptied.
 */

if (defined('KAP_API_BOOTED')) { return; }
define('KAP_API_BOOTED', true);

header('X-Content-Type-Options: nosniff');

/** Emit JSON and stop. */
function kap_api_out($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // Same caching posture the .htaccess gave the .json files: always
    // revalidate, so an admin edit shows up immediately.
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Fail closed: a status main.js already knows how to handle. */
function kap_api_fail($msg, $status = 503) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // Deliberately terse — no DSN, credentials or SQL in a public response.
    echo json_encode(array('error' => $msg));
    exit;
}

/**
 * Locate private/, connect, and load the catalogue mapping.
 *
 * The two candidate paths mirror admin/api/_guard.php:28-42 — private/ is
 * ideally a sibling of public_html, but works inside it too. Keep the two in
 * step if either ever moves.
 *
 * @return PDO
 */
function kap_api_db() {
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }

    $candidates = array(
        dirname(__DIR__, 2) . '/private',   // sibling of public_html (preferred)
        dirname(__DIR__) . '/private',      // inside the web root (fallback)
    );
    $dir = null;
    foreach ($candidates as $c) {
        if (is_file($c . '/db.php') && is_file($c . '/catalog.php')) { $dir = $c; break; }
    }
    if ($dir === null) { kap_api_fail('Catalogue backend unavailable.', 503); }

    try {
        require_once $dir . '/db.php';
        require_once $dir . '/catalog.php';
        $pdo = kap_pdo();
    } catch (Exception $e) {
        error_log('kapriol api: ' . $e->getMessage());
        kap_api_fail('Catalogue backend unavailable.', 503);
    }
    return $pdo;
}

/** GET (and HEAD) only. */
function kap_api_require_get() {
    $m = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if ($m !== 'GET' && $m !== 'HEAD') {
        header('Allow: GET');
        kap_api_fail('Method not allowed.', 405);
    }
}
