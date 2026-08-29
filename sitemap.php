<?php
/**
 * sitemap.php — dynamic XML sitemap.
 *
 * Served at /sitemap.xml via an .htaccess rewrite (robots.txt points there).
 * It queries the LIVE catalogue database on every request, so products added,
 * hidden or removed through the admin appear with no regeneration step. The
 * origin is taken from the request host, so it survives a domain move.
 *
 * Hidden products are excluded in SQL (kap_product_slugs), matching what
 * api/products.php serves to the public renderer: never invite a crawler to a
 * page that would answer "product not found".
 *
 * On a database failure this emits the three static URLs and nothing else,
 * rather than a 500 — a briefly short sitemap is recoverable, a broken one
 * gets cached by crawlers.
 */
header('Content-Type: application/xml; charset=utf-8');

$host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? $_SERVER['HTTP_HOST'] : 'kapriol.ergotrade.bg';
$base  = 'https://' . $host;
$today = gmdate('Y-m-d');

/* private/ sits outside the web root where possible, beside public_html; the
   in-root copy is the fallback. Mirrors admin/api/_guard.php:28-42 and
   api/_bootstrap.php — keep the three in step if the folder ever moves. */
$slugs = array();
foreach (array(dirname(__DIR__) . '/private', __DIR__ . '/private') as $dir) {
    if (!is_file($dir . '/db.php') || !is_file($dir . '/catalog.php')) { continue; }
    try {
        require_once $dir . '/db.php';
        require_once $dir . '/catalog.php';
        $slugs = kap_product_slugs(kap_pdo());
    } catch (Exception $e) {
        error_log('kapriol sitemap: ' . $e->getMessage());
    }
    break;
}

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

// One entry per visible catalogue product (product.html?p=<slug>).
foreach ($slugs as $slug) {
    if ($slug === '') { continue; }
    kap_sitemap_url($base . '/product?p=' . rawurlencode($slug), $today, 'monthly', '0.6');
}

echo '</urlset>' . "\n";
