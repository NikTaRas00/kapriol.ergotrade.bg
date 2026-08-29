<?php
/**
 * logout.php — destroy the test1 admin session and return to the sign-in page.
 * Clears the cookie using the session's own params, so it removes the cookie
 * scoped to /test1/admin and leaves a /admin session untouched.
 */
require __DIR__ . '/api/_guard.php';

$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}
session_destroy();

header('Location: login.php');
exit;
