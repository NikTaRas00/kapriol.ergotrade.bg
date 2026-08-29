<?php
/**
 * logout.php — destroy the admin session and return to the login screen.
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
