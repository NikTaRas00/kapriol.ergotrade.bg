<?php
/**
 * sitemap.php — dynamic XML sitemap.
 *
 * Served at /sitemap.xml via an .htaccess rewrite (robots.txt points there).
 * Reads the LIVE collection on every request, so items added/removed through the
 * admin appear with no regeneration. Origin is taken from the request host, so it
 * survives a domain move.
 *
 * ADAPT: the static page list, the detail-page URL pattern, and the data file.
 */
header('Content-Type: application/xml; charset=utf-8');

$host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? $_SERVER['HTTP_HOST'] : 'REPLACE-WITH-DOMAIN';
$base  = 'https://' . $host;
$today = gmdate('Y-m-d');

$items = json_decode(@file_get_contents(__DIR__ . '/assets/data/items.json'), true);
if (!is_array($items)) { $items = array(); }

function app_sitemap_url($loc, $lastmod, $freq, $priority) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    echo '    <changefreq>' . $freq . "</changefreq>\n";
    echo '    <priority>' . $priority . "</priority>\n";
    echo "  </url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Static pages — adjust to the real site map.
app_sitemap_url($base . '/',        $today, 'weekly',  '1.0');
app_sitemap_url($base . '/products', $today, 'weekly',  '0.9');
app_sitemap_url($base . '/contact',  $today, 'monthly', '0.7');

// One entry per item. Skip hidden ones — never invite a crawler to a page the
// front end answers with "not found".
foreach ($items as $p) {
    if (empty($p['slug'])) { continue; }
    if (!empty($p['hidden'])) { continue; }
    app_sitemap_url($base . '/product?p=' . rawurlencode($p['slug']), $today, 'monthly', '0.6');
}

echo '</urlset>' . "\n";
