<?php
/**
 * router.php — LOCAL DEV ONLY (used by `php -S` via .claude/launch.json).
 *
 * The live host is Apache + .htaccess, which rewrites clean/extensionless URLs
 * (e.g. /products, /products?cat=footwear) to the matching .html file. The PHP
 * built-in server has no .htaccess support, so without this router every
 * extensionless link 404s during local testing. This mirrors that one rule:
 * an extensionless request whose <path>.html exists is served as that file.
 * Everything else (real assets, .php endpoints, directories, the root) falls
 * through to the server's default handling. Not deployed.
 */
$docroot = dirname(__DIR__);                                   // project root
$path    = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$full    = $docroot . $path;

// Dynamic sitemap: mirror the .htaccess rewrite (/sitemap.xml → sitemap.php).
if ($path === '/sitemap.xml' && is_file($docroot . '/sitemap.php')) {
    require $docroot . '/sitemap.php';
    return true;
}
// Real FILE (asset, .php, .json, image) → serve normally.
if ($path !== '/' && is_file($full)) {
    return false;
}
// Extensionless clean URL → its .html twin (mirrors the .htaccess rewrite).
// Checked before directories so the local-only products/ source folder doesn't
// shadow the /products clean URL (that folder isn't deployed to the host).
if (pathinfo($path, PATHINFO_EXTENSION) === '' && is_file($full . '.html')) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($full . '.html');
    return true;
}
// Directory without a trailing slash (e.g. /admin) → redirect to add it, so
// the built-in server resolves its index.php / index.html (Apache does this).
if (is_dir($full) && substr($path, -1) !== '/') {
    $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: ' . $path . '/' . $qs, true, 301);
    return true;
}
// Root and directory-with-slash → default handling (resolves index.php/html).
return false;
