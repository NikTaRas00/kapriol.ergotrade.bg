<?php
/**
 * config.php — admin secrets & tunables for kapriol.bg.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  SECURITY: this file (and its sibling enquiries.json / login-throttle.json)
 *  must NOT be web-accessible.
 *
 *  BEST:  move this whole `private/` folder OUT of the web root, so it sits
 *         next to public_html, e.g.  /home/<account>/private/  .
 *         _guard.php looks there first and will use it automatically.
 *  OK:    if you cannot move it, leaving it at  public_html/private/  is still
 *         protected — the root .htaccess denies direct access to config.php,
 *         enquiries.json and login-throttle.json. (Belt-and-braces.)
 * ──────────────────────────────────────────────────────────────────────────
 *
 *  CHANGE THE PASSWORD before going live. The hash below is for the default
 *  password  "ChangeMe!2026"  — do not ship that. To generate a new hash,
 *  run this once on the host (cPanel “Terminal” or a throwaway PHP file),
 *  paste the output into 'pass_hash', then delete the throwaway file:
 *
 *      php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT), PHP_EOL;"
 */

return array(
    // bcrypt hash of the admin password. Default password: "ChangeMe!2026".
    'pass_hash'    => '$2y$10$dp.U49hfQc0TyKG3poY7eu2Qvzkqs4my4kX4byOvqD2QdtEKl1QTq',

    // Session cookie name (kept distinct from anything else on the domain).
    'session_name' => 'kapadmin',

    // Login throttle: after this many failures within the window, lock out.
    'max_attempts' => 5,
    'lockout_secs' => 900,   // 15 minutes
);
