<?php
/**
 * config.sample.php — copy to private/config.php on the host and edit.
 *
 * private/ MUST live OUTSIDE the web root (sibling of public_html).
 * NEVER commit the real config.php with a real password.
 *
 * Generate the hash offline, e.g.:
 *   php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
 */
return array(
    // bcrypt hash from password_hash(). The default below is the literal string
    // "ChangeMe!2026" — CHANGE IT before or immediately after first launch.
    'pass_hash'    => '$2y$10$e0NRE0Q0m0m0m0m0m0m0mO0m0m0m0m0m0m0m0m0m0m0m0m0m0m0m', // placeholder — regenerate
    'session_name' => 'appadmin',
    'max_attempts' => 5,     // failed logins before lockout
    'lockout_secs' => 900,   // lockout window (15 min)
);
