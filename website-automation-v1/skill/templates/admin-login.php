<?php
/**
 * login.php — admin sign-in with a per-IP throttle. Include _guard.php; enforce
 * auth explicitly. On success: regenerate the session id, set auth, mint CSRF.
 */
require __DIR__ . '/api/_guard.php';

if (admin_is_authed()) { header('Location: index.php'); exit; }

$error = '';

/* ---------- per-IP login throttle (private/login-throttle.json) ---------- */
function throttle_key() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    return preg_replace('/[^A-Za-z0-9_.:]/', '_', $ip);
}
function throttle_state() { $all = read_json(APP_THROTTLE, array()); return is_array($all) ? $all : array(); }
function throttle_locked_for() {
    $all = throttle_state(); $key = throttle_key();
    $max = (int) app_cfg('max_attempts', 5); $win = (int) app_cfg('lockout_secs', 900);
    if (empty($all[$key])) { return 0; }
    $e = $all[$key];
    if (($e['count'] ?? 0) >= $max && (time() - ($e['first'] ?? 0)) < $win) { return $win - (time() - $e['first']); }
    return 0;
}
function throttle_fail() {
    $all = throttle_state(); $key = throttle_key(); $win = (int) app_cfg('lockout_secs', 900); $now = time();
    if (empty($all[$key]) || ($now - ($all[$key]['first'] ?? 0)) >= $win) { $all[$key] = array('count' => 1, 'first' => $now); }
    else { $all[$key]['count'] = ($all[$key]['count'] ?? 0) + 1; }
    atomic_write_json(APP_THROTTLE, $all);
}
function throttle_clear() {
    $all = throttle_state(); $key = throttle_key();
    if (isset($all[$key])) { unset($all[$key]); atomic_write_json(APP_THROTTLE, $all); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locked = throttle_locked_for();
    if ($locked > 0) {
        http_response_code(429);
        $error = 'Too many attempts. Try again in about ' . ceil($locked / 60) . ' min.';
    } elseif (!empty($_POST['website'])) {         // honeypot
        throttle_fail();
        $error = 'Sign-in failed.';
    } else {
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if ($password !== '' && password_verify($password, app_cfg('pass_hash', ''))) {
            throttle_clear();
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            admin_csrf_token();
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
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Admin sign-in</title>
  <link rel="stylesheet" href="assets/admin.css?v=1" />
</head>
<body class="admin admin--auth">
  <main class="auth-card">
    <h1 class="auth-card__title">Admin sign-in</h1>
    <form class="auth-form" method="post" action="login.php" novalidate>
      <div class="hp" aria-hidden="true" style="position:absolute;left:-9999px">
        <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off" /></label>
      </div>
      <div class="field">
        <label for="ad-pass">Password</label>
        <input id="ad-pass" name="password" type="password" autocomplete="current-password" autofocus required />
      </div>
      <button class="btn" type="submit">Sign in</button>
      <?php if ($error !== ''): ?>
        <p class="form-status is-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php endif; ?>
    </form>
  </main>
</body>
</html>
