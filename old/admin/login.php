<?php
/**
 * login.php — branded admin sign-in.
 *
 *  GET  : render the login form.
 *  POST : check the per-IP throttle, verify the password, start the session.
 *
 * Reuses the public site's design tokens + .field/.btn/.form-status classes
 * (style.css) plus a little admin.css for the centred card.
 */
require __DIR__ . '/api/_guard.php';

// Already signed in? Skip straight to the dashboard.
if (admin_is_authed()) {
    header('Location: index.php');
    exit;
}

$error = '';

/* ------------------------------------------------- per-IP login throttle -- */
function throttle_key() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    return preg_replace('/[^A-Za-z0-9_.:]/', '_', $ip);
}
function throttle_state() {
    $all = read_json(KAP_THROTTLE, array());
    return is_array($all) ? $all : array();
}
/** Returns seconds remaining if locked out, else 0. */
function throttle_locked_for() {
    $all   = throttle_state();
    $key   = throttle_key();
    $max   = (int) kap_cfg('max_attempts', 5);
    $win   = (int) kap_cfg('lockout_secs', 900);
    if (empty($all[$key])) { return 0; }
    $e = $all[$key];
    if (($e['count'] ?? 0) >= $max && (time() - ($e['first'] ?? 0)) < $win) {
        return $win - (time() - $e['first']);
    }
    return 0;
}
function throttle_fail() {
    $all = throttle_state();
    $key = throttle_key();
    $win = (int) kap_cfg('lockout_secs', 900);
    $now = time();
    if (empty($all[$key]) || ($now - ($all[$key]['first'] ?? 0)) >= $win) {
        $all[$key] = array('count' => 1, 'first' => $now);
    } else {
        $all[$key]['count'] = ($all[$key]['count'] ?? 0) + 1;
    }
    atomic_write_json(KAP_THROTTLE, $all);
}
function throttle_clear() {
    $all = throttle_state();
    $key = throttle_key();
    if (isset($all[$key])) { unset($all[$key]); atomic_write_json(KAP_THROTTLE, $all); }
}

/* --------------------------------------------------------------- handle POST -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locked = throttle_locked_for();
    if ($locked > 0) {
        http_response_code(429);
        $error = 'Too many attempts. Try again in about ' . ceil($locked / 60) . ' min.';
    } elseif (!empty($_POST['website'])) {
        // Honeypot tripped — treat as a failed attempt, reveal nothing.
        throttle_fail();
        $error = 'Sign-in failed.';
    } else {
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if ($password !== '' && password_verify($password, kap_cfg('pass_hash', ''))) {
            throttle_clear();
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            admin_csrf_token();         // mint CSRF token for the session
            header('Location: index.php');
            exit;
        }
        throttle_fail();
        $error = 'Incorrect password.';
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="theme-color" content="#f4f5f6" />
  <title>Admin sign-in — kapriol.bg</title>
  <link rel="stylesheet" href="../assets/css/style.css?v=22" />
  <link rel="stylesheet" href="assets/admin.css?v=17" />
</head>
<body class="admin admin--auth">
  <main class="auth-card">
    <a class="auth-card__brand" href="../">KAPRIOL</a>
    <h1 class="auth-card__title">Admin sign-in</h1>
    <p class="auth-card__sub">Manage products, enquiries and site content.</p>

    <form class="auth-form" method="post" action="login.php" novalidate>
      <div class="hp" aria-hidden="true">
        <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off" /></label>
      </div>

      <div class="field">
        <label for="ad-pass">Password</label>
        <input id="ad-pass" name="password" type="password" autocomplete="current-password"
               autofocus required />
      </div>

      <button class="btn btn--red btn--block" type="submit">Sign in</button>

      <?php if ($error !== ''): ?>
        <p class="form-status is-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php endif; ?>
    </form>
  </main>
</body>
</html>
