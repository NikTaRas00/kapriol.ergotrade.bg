<?php
/**
 * sitemap.php — dynamic XML sitemap.
 *
 * Served at /sitemap.xml via an .htaccess rewrite (robots.txt points there).
 * It reads the LIVE catalog (assets/data/products.json) on every request, so
 * products added or removed through the admin appear with no regeneration step.
 * The origin is taken from the request host, so it survives a domain move.
 */
header('Content-Type: application/xml; charset=utf-8');

$host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? $_SERVER['HTTP_HOST'] : 'kapriol.ergotrade.bg';
$base  = 'https://' . $host;
$today = gmdate('Y-m-d');

$products = json_decode(@file_get_contents(__DIR__ . '/assets/data/products.json'), true);
if (!is_array($products)) { $products = array(); }

function kap_sitemap_url($loc, $lastmod, $freq, $priority) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    echo '    <changefreq>' . $freq . "</changefreq>\n";
    echo '    <priority>' . $priority . "</priority>\n";
    echo "  </url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Static pages.
kap_sitemap_url($base . '/',         $today, 'weekly',  '1.0');
kap_sitemap_url($base . '/products', $today, 'weekly',  '0.9');
kap_sitemap_url($base . '/contact',  $today, 'monthly', '0.7');

// One entry per catalog product (product.html?p=<slug>).
foreach ($products as $p) {
    if (empty($p['slug'])) { continue; }
    // Hidden in the admin means hidden everywhere: never invite a crawler to a
    // page the public renderer will answer with "product not found".
    if (!empty($p['hidden'])) { continue; }
    kap_sitemap_url($base . '/product?p=' . rawurlencode($p['slug']), $today, 'monthly', '0.6');
}

echo '</urlset>' . "\n";
