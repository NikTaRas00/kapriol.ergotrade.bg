<?php
/**
 * track.php — public, cookieless visitor-analytics beacon.
 *
 * The static pages POST a tiny JSON body here on load (navigator.sendBeacon).
 * We record AGGREGATE COUNTS ONLY — no IP, no user-agent, no cookie, nothing
 * that identifies a person — so it needs no consent banner. "Visits" are an
 * approximation the client supplies once per browser session (sessionStorage).
 *
 * Storage: a single JSON file in the private/ dir (same place as enquiries),
 * updated under an exclusive lock so concurrent hits can't lose each other.
 * This file deliberately does NOT include _guard.php: it must stay public and
 * must never start a session (that would set a cookie on every visitor).
 */

header('X-Content-Type-Options: nosniff');

// Respond fast + empty. sendBeacon ignores the body anyway.
function done($code = 204) { http_response_code($code); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { done(405); }

// ---- Locate the private/ dir (mirror _guard.php, but WITHOUT a session) ----
// __DIR__ is the web root. Prefer a private/ folder OUTSIDE the web root.
$candidates = array(
    dirname(__DIR__) . '/private',   // sibling of public_html (best)
    __DIR__ . '/private',            // public_html/private (fallback)
);
$privateDir = '';
foreach ($candidates as $c) { if (is_dir($c)) { $privateDir = $c; break; } }
if ($privateDir === '') { done(204); }   // nothing to write to; never error a visitor
$file = $privateDir . '/analytics.json';

// ---- Drop obvious bots (keeps the numbers about real people) ---------------
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|bing|baidu|yandex|duckduck|facebookexternalhit|preview|monitor|curl|wget|python|headless|lighthouse|pingdom|uptime/i', $ua)) {
    done(204);
}

// ---- Parse the beacon payload ----------------------------------------------
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) { done(204); }

// Page label: a short, sanitised slug ("home" / "products" / "product" / ...).
$page = isset($in['page']) ? (string) $in['page'] : '';
$page = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $page));
if ($page === '') { $page = 'other'; }
if (strlen($page) > 40) { $page = substr($page, 0, 40); }

$newSession = !empty($in['s']);   // client says this is a fresh browser session

// Optional product slug (only sent from the product-detail page).
$slug = isset($in['slug']) ? (string) $in['slug'] : '';
$slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', $slug));
if (strlen($slug) > 80) { $slug = substr($slug, 0, 80); }

$today = gmdate('Y-m-d');

// ---- Increment counters under an exclusive lock ----------------------------
// fopen('c+') creates the file if missing and never truncates on open, so we
// can read-modify-write the whole document while holding LOCK_EX.
$fp = @fopen($file, 'c+');
if (!$fp) { done(204); }

if (flock($fp, LOCK_EX)) {
    $body = stream_get_contents($fp);
    $data = json_decode($body, true);
    if (!is_array($data)) {
        $data = array('totals' => array('views' => 0, 'visits' => 0),
                      'daily' => array(), 'pages' => array(), 'products' => array());
    }
    // Defensive defaults (an older/partial file).
    if (!isset($data['totals']) || !is_array($data['totals'])) $data['totals'] = array('views' => 0, 'visits' => 0);
    foreach (array('daily', 'pages', 'products') as $k) {
        if (!isset($data[$k]) || !is_array($data[$k])) $data[$k] = array();
    }

    // Totals
    $data['totals']['views']  = (int) $data['totals']['views'] + 1;
    if ($newSession) $data['totals']['visits'] = (int) $data['totals']['visits'] + 1;

    // Per-day
    if (!isset($data['daily'][$today]) || !is_array($data['daily'][$today])) {
        $data['daily'][$today] = array('views' => 0, 'visits' => 0);
    }
    $data['daily'][$today]['views'] += 1;
    if ($newSession) $data['daily'][$today]['visits'] += 1;

    // Per-page
    $data['pages'][$page] = (isset($data['pages'][$page]) ? (int) $data['pages'][$page] : 0) + 1;

    // Per-product (cap the map so a crawl of all 334 can't bloat the file)
    if ($slug !== '') {
        $data['products'][$slug] = (isset($data['products'][$slug]) ? (int) $data['products'][$slug] : 0) + 1;
        if (count($data['products']) > 500) {
            arsort($data['products']);
            $data['products'] = array_slice($data['products'], 0, 400, true);
        }
    }

    // Keep only the last 180 days of the daily series.
    if (count($data['daily']) > 180) {
        ksort($data['daily']);
        $data['daily'] = array_slice($data['daily'], -180, null, true);
    }

    $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($out !== false) {
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $out);
        fflush($fp);
    }
    flock($fp, LOCK_UN);
}
fclose($fp);

done(204);
