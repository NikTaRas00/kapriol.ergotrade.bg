<?php
/**
 * track.php — public, cookieless visitor-analytics beacon.
 *
 * Pages POST a tiny JSON body here on load (navigator.sendBeacon). We record
 * AGGREGATE COUNTS ONLY — no IP, no user-agent stored, no cookie — so it needs no
 * consent banner. Deliberately does NOT include _guard.php and never starts a
 * session (that would set a cookie on every visitor).
 */

header('X-Content-Type-Options: nosniff');
function done($code = 204) { http_response_code($code); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { done(405); }

// Locate private/ (mirror _guard.php, WITHOUT a session).
$candidates = array(dirname(__DIR__) . '/private', __DIR__ . '/private');
$privateDir = '';
foreach ($candidates as $c) { if (is_dir($c)) { $privateDir = $c; break; } }
if ($privateDir === '') { done(204); }
$file = $privateDir . '/analytics.json';

// Drop obvious bots (UA read to filter, never stored).
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|bing|baidu|yandex|duckduck|facebookexternalhit|preview|monitor|curl|wget|python|headless|lighthouse|pingdom|uptime/i', $ua)) {
    done(204);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { done(204); }

$page = isset($in['page']) ? (string) $in['page'] : '';
$page = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $page));
if ($page === '') { $page = 'other'; }
if (strlen($page) > 40) { $page = substr($page, 0, 40); }

$newSession = !empty($in['s']);

$slug = isset($in['slug']) ? (string) $in['slug'] : '';
$slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', $slug));
if (strlen($slug) > 80) { $slug = substr($slug, 0, 80); }

$today = gmdate('Y-m-d');

// Increment counters under an exclusive lock. fopen('c+') creates if missing and
// never truncates on open, so we can read-modify-write while holding LOCK_EX.
$fp = @fopen($file, 'c+');
if (!$fp) { done(204); }

if (flock($fp, LOCK_EX)) {
    $data = json_decode(stream_get_contents($fp), true);
    if (!is_array($data)) {
        $data = array('totals' => array('views' => 0, 'visits' => 0),
                      'daily' => array(), 'pages' => array(), 'products' => array());
    }
    if (!isset($data['totals']) || !is_array($data['totals'])) $data['totals'] = array('views' => 0, 'visits' => 0);
    foreach (array('daily', 'pages', 'products') as $k) {
        if (!isset($data[$k]) || !is_array($data[$k])) $data[$k] = array();
    }

    $data['totals']['views'] = (int) $data['totals']['views'] + 1;
    if ($newSession) $data['totals']['visits'] = (int) $data['totals']['visits'] + 1;

    if (!isset($data['daily'][$today]) || !is_array($data['daily'][$today])) {
        $data['daily'][$today] = array('views' => 0, 'visits' => 0);
    }
    $data['daily'][$today]['views'] += 1;
    if ($newSession) $data['daily'][$today]['visits'] += 1;

    $data['pages'][$page] = (isset($data['pages'][$page]) ? (int) $data['pages'][$page] : 0) + 1;

    if ($slug !== '') {
        $data['products'][$slug] = (isset($data['products'][$slug]) ? (int) $data['products'][$slug] : 0) + 1;
        if (count($data['products']) > 500) { arsort($data['products']); $data['products'] = array_slice($data['products'], 0, 400, true); }
    }
    if (count($data['daily']) > 180) { ksort($data['daily']); $data['daily'] = array_slice($data['daily'], -180, null, true); }

    $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($out !== false) { rewind($fp); ftruncate($fp, 0); fwrite($fp, $out); fflush($fp); }
    flock($fp, LOCK_UN);
}
fclose($fp);
done(204);
