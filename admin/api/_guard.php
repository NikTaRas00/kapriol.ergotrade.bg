<?php
/**
 * _guard.php — shared bootstrap for every admin page & endpoint.
 *
 * Including this file:
 *   • locates and loads ../private/config.php (sibling-of-web-root first),
 *   • defines the data-file paths,
 *   • starts a hardened session.
 *
 * It does NOT auto-gate, so login.php can include it too. Callers enforce
 * access explicitly:
 *   • admin_require_auth()        — JSON endpoints (401 if not logged in)
 *   • admin_require_csrf()        — state-changing endpoints (403 on bad token)
 *   • admin_redirect_if_guest()   — HTML pages (302 to login.php)
 *
 * Helpers: read_json(), atomic_write_json(), json_out().
 *
 * Direct web access to this file is denied by .htaccess (it only defines things).
 */

if (defined('KAP_ADMIN_BOOTED')) { return; }
define('KAP_ADMIN_BOOTED', true);

/* ---- Locate config -------------------------------------------------------
   __DIR__ is  <web-root>/admin/api . Prefer a private/ folder that sits
   OUTSIDE the web root (one level above public_html); fall back to an
   in-web-root private/ (which the root .htaccess protects). */
$KAP_CONFIG_CANDIDATES = array(
    dirname(__DIR__, 3) . '/private/config.php',  // sibling of public_html  (best)
    dirname(__DIR__, 2) . '/private/config.php',  // public_html/private/    (fallback)
);
$KAP_CONFIG_FILE = '';
foreach ($KAP_CONFIG_CANDIDATES as $cand) {
    if (is_file($cand)) { $KAP_CONFIG_FILE = $cand; break; }
}
if ($KAP_CONFIG_FILE === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'error' => 'Admin config not found.'));
    exit;
}
$KAP_CFG = require $KAP_CONFIG_FILE;

/* ---- Paths ---------------------------------------------------------------
   Private data lives next to config.php (wherever that was found). Public,
   site-readable data (products/content) lives under the web root. */
define('KAP_PRIVATE_DIR', dirname($KAP_CONFIG_FILE));
define('KAP_WEB_ROOT',    dirname(__DIR__, 2));            // public_html
define('KAP_ENQUIRIES',   KAP_PRIVATE_DIR . '/enquiries.json');
define('KAP_THROTTLE',    KAP_PRIVATE_DIR . '/login-throttle.json');
define('KAP_ANALYTICS',   KAP_PRIVATE_DIR . '/analytics.json');
define('KAP_CONTENT',     KAP_WEB_ROOT . '/assets/data/content.json');
/* KAP_PRODUCTS / KAP_ATTRIBUTES are GONE: the catalogue and the spec-code
   dictionary moved to MySQL on 2026-08-29 and are reached through kap_db()
   plus the mapping in private/catalog.php. content.json stays a flat file —
   site copy was deliberately left out of the migration. Any leftover
   assets/data/products.json on the host is inert; nothing reads it. */

$GLOBALS['KAP_CFG'] = $KAP_CFG;

/* ---- Session -------------------------------------------------------------
   Scoped to the admin folder, httponly, SameSite=Strict. Secure flag only when
   the request is actually over HTTPS (so local http dev still works). */
function kap_cfg($key, $default = null) {
    return isset($GLOBALS['KAP_CFG'][$key]) ? $GLOBALS['KAP_CFG'][$key] : $default;
}

/**
 * The catalogue database, plus the shared JSON<->SQL mapping.
 *
 * Resolved from KAP_PRIVATE_DIR (i.e. wherever config.php was actually found),
 * so it follows private/ whether that sits beside public_html or inside it —
 * do NOT re-derive the path here, that is the bug the config lookup above
 * already solves.
 *
 * Every product read and write in this admin goes through the functions in
 * private/catalog.php, which is the same code api/products.php serves the
 * public site from. One mapping, one behaviour.
 *
 * On failure this emits a JSON error and exits, so callers can use the return
 * value without checking it.
 *
 * @return PDO
 */
function kap_db() {
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }

    $dbFile  = KAP_PRIVATE_DIR . '/db.php';
    $mapFile = KAP_PRIVATE_DIR . '/catalog.php';
    if (!is_file($dbFile) || !is_file($mapFile)) {
        json_out(array('success' => false,
            'error' => 'Database layer missing (private/db.php + private/catalog.php).'), 500);
    }
    try {
        require_once $dbFile;
        require_once $mapFile;
        $pdo = kap_pdo($GLOBALS['KAP_CFG']);
    } catch (Exception $e) {
        error_log('kapriol admin db: ' . $e->getMessage());
        // The admin is authenticated, so a real reason is useful here — but
        // kap_pdo() already strips credentials out of what it throws.
        json_out(array('success' => false,
            'error' => 'Database connection failed: ' . $e->getMessage()), 500);
    }
    return $pdo;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    // Derive the cookie path from the current URL so it works whether the site
    // is at the web root (/admin) or in a subfolder (e.g. /test1/admin). A
    // hardcoded "/admin" breaks subfolder deploys: the cookie is set under
    // /admin but never sent back to /test1/admin, so login silently loops.
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
    $adminPos = strpos($script, '/admin/');
    $cookiePath = ($adminPos !== false) ? substr($script, 0, $adminPos + 6) : '/';

    session_name(kap_cfg('session_name', 'kapadmin'));
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => $cookiePath,
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $https,
    ));
    session_start();
}

/* ---- JSON helpers --------------------------------------------------------- */
function json_out($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json($path, $default = null) {
    if (!is_file($path)) { return $default; }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') { return $default; }
    $data = json_decode($raw, true);
    return is_null($data) ? $default : $data;
}

/**
 * Atomic, lock-protected JSON write: write to <path>.tmp then rename().
 * rename() is atomic on the same filesystem and reuses one transient inode,
 * which matters on the inode-limited host. Returns true on success.
 */
function atomic_write_json($path, $data) {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if ($json === false) { return false; }

    $dir = dirname($path);
    if (!is_dir($dir)) { return false; }

    $tmp = $path . '.tmp';
    $fp = @fopen($tmp, 'wb');
    if (!$fp) { return false; }

    $ok = false;
    if (flock($fp, LOCK_EX)) {
        $ok = (fwrite($fp, $json) !== false);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    if (!$ok) { @unlink($tmp); return false; }

    // POSIX (the cPanel host): rename() atomically replaces the destination,
    // so a concurrent reader sees either the old or new file, never partial.
    if (@rename($tmp, $path)) { return true; }

    // Windows fallback (local dev only): rename() can't overwrite an existing
    // file. copy() overwrites in place — not atomic, but it never removes the
    // destination without writing, so the file can't be lost. Production is
    // Linux and uses the atomic rename above.
    $copied = @copy($tmp, $path);
    @unlink($tmp);
    return $copied;
}

/* ---- Access enforcement --------------------------------------------------- */
function admin_is_authed() {
    return !empty($_SESSION['auth']);
}

function admin_require_auth() {
    if (!admin_is_authed()) {
        json_out(array('success' => false, 'error' => 'Not authenticated.'), 401);
    }
}

function admin_require_csrf() {
    $sent = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    $have = isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
    if ($have === '' || !hash_equals($have, $sent)) {
        json_out(array('success' => false, 'error' => 'Invalid request token.'), 403);
    }
}

function admin_require_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(array('success' => false, 'error' => 'Method not allowed.'), 405);
    }
}

/** For HTML pages: bounce guests to the login screen. */
function admin_redirect_if_guest() {
    if (!admin_is_authed()) {
        header('Location: login.php');
        exit;
    }
}

/** Mint (once per session) and return the CSRF token. */
function admin_csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/* ---- Shared image ingestion (product / pictogram / category uploads) --------
   Every admin image upload runs through kap_save_upload_image(): it clears any
   prior file for that id (one file per slug on the inode-limited host), then
   re-encodes the upload to WebP — smaller than JPEG/PNG at the same quality —
   applying EXIF orientation and downscaling the longest side to $maxDim. If the
   host's GD build can't write WebP it degrades gracefully: GD re-encode in the
   original format, or, failing that, the raw upload stored untouched. Returns
   the SAVED extension ('webp' normally) so the caller can build the stored path,
   or false on hard failure. */
function kap_save_upload_image($srcTmp, $destDir, $slug, $info, $maxDim = 1600) {
    $extMap = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp');
    $srcType = isset($info[2]) ? $info[2] : 0;
    if (!isset($extMap[$srcType])) { return false; }
    $origExt = $extMap[$srcType];

    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) { return false; }

    // One file per id: drop any earlier upload in any format before writing.
    foreach (array('jpg', 'jpeg', 'png', 'webp') as $old) {
        $p = $destDir . '/' . $slug . '.' . $old;
        if (is_file($p)) { @unlink($p); }
    }

    // Preferred: convert to WebP.
    if (function_exists('imagewebp')) {
        $dest = $destDir . '/' . $slug . '.webp';
        if (kap_reencode_image($srcTmp, $dest, $srcType, $maxDim, true)) {
            @chmod($dest, 0644);
            return 'webp';
        }
        @unlink($dest);   // clear any partial write before falling back
    }

    // Fallback 1: GD re-encode in the original format (still downscaled/oriented).
    $dest = $destDir . '/' . $slug . '.' . $origExt;
    if (kap_reencode_image($srcTmp, $dest, $srcType, $maxDim, false)) {
        @chmod($dest, 0644);
        return $origExt;
    }

    // Fallback 2: GD unavailable — store the original upload untouched.
    if (@move_uploaded_file($srcTmp, $dest) || @copy($srcTmp, $dest)) {
        @chmod($dest, 0644);
        return $origExt;
    }
    return false;
}

/* Re-encode one image with GD: apply JPEG EXIF orientation, downscale so the
   longest side is <= $maxDim (never upscales), preserve PNG/WebP transparency,
   and write to $dest either as WebP ($outWebp = true) or in its source format.
   Returns true on success; false if GD is missing or any step fails. */
function kap_reencode_image($srcTmp, $dest, $srcType, $maxDim, $outWebp) {
    if (!function_exists('imagecreatetruecolor')) { return false; }
    switch ($srcType) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($srcTmp); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($srcTmp);  break;
        case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcTmp) : false; break;
        default: return false;
    }
    if (!$img) { return false; }

    // Honour JPEG EXIF orientation so portrait phone photos aren't sideways.
    if ($srcType === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($srcTmp);
        $o = isset($exif['Orientation']) ? (int) $exif['Orientation'] : 0;
        if ($o === 3)      { $img = imagerotate($img, 180, 0); }
        elseif ($o === 6)  { $img = imagerotate($img, -90, 0); }
        elseif ($o === 8)  { $img = imagerotate($img,  90, 0); }
    }

    $alpha = ($srcType === IMAGETYPE_PNG || $srcType === IMAGETYPE_WEBP);
    $w = imagesx($img); $h = imagesy($img);
    $scale = (max($w, $h) > $maxDim) ? ($maxDim / max($w, $h)) : 1.0;

    if ($scale < 1.0) {
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    } elseif ($alpha) {
        imagesavealpha($img, true);
    }

    if ($outWebp) {
        $ok = @imagewebp($img, $dest, 82);
    } else {
        switch ($srcType) {
            case IMAGETYPE_JPEG: $ok = @imagejpeg($img, $dest, 82); break;
            case IMAGETYPE_PNG:  $ok = @imagepng($img, $dest, 6);   break;
            case IMAGETYPE_WEBP: $ok = function_exists('imagewebp') ? @imagewebp($img, $dest, 82) : false; break;
            default: $ok = false;
        }
    }
    imagedestroy($img);
    return $ok;
}
