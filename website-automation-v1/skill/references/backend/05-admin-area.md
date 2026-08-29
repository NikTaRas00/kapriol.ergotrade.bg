# 05 — Admin area

A PHP session-login dashboard under `admin/` that edits the JSON data layer
through small JSON endpoints in `admin/api/`. Every write is authenticated,
CSRF-checked, allowlisted, and atomic. Templates:
`templates/_guard.php`, `templates/admin-login.php`,
`templates/endpoint-save.php`, `templates/endpoint-get.php`,
`templates/config.sample.php`.

## Folder shape
```
admin/
  login.php     logout.php     index.php          (HTML pages)
  assets/       admin.css  admin.js               (admin-only front end)
  api/
    _guard.php                                    shared bootstrap (below)
    <thing>-get.php                               read endpoints  (auth)
    <thing>-save.php  <thing>-create.php  <thing>-delete.php   (auth + CSRF)
    <thing>-image.php                             uploads (auth + CSRF, multipart)
private/                                           OUTSIDE the web root
  config.php    enquiries.json    analytics.json    login-throttle.json
```

## `_guard.php` — shared bootstrap (include in every admin page/endpoint)
It does **not** auto-gate (so `login.php` can include it too); callers enforce
access explicitly. Responsibilities:

1. **Locate config**: prefer `private/config.php` that is a **sibling of the web
   root** (`dirname(__DIR__,3).'/private/config.php'`), fall back to an in-web-root
   `private/`. 500 if neither exists. Define data-path constants
   (`APP_ITEMS`, `APP_CONTENT`, `APP_ATTRIBUTES` under the web root;
   `APP_ENQUIRIES`, `APP_ANALYTICS`, `APP_THROTTLE` next to the config).
2. **Hardened session**:
   - `httponly`, `samesite=Strict`.
   - `secure` **only when the request is actually HTTPS** (so local http dev
     works) — detect via `HTTPS`, port 443, or `X-Forwarded-Proto`.
   - **Derive the cookie `path` from `SCRIPT_NAME`** (the `/…/admin` segment,
     fallback `/`). A hardcoded `/admin` breaks subfolder deploys: the cookie is
     set under `/admin` but never sent back to `/sub/admin`, so login silently
     loops. Never re-hardcode this.
   - Configurable `session_name` from config.
3. **Helpers**:
   - `json_out($payload, $status=200)` — JSON + `nosniff`, exit.
   - `read_json($path, $default)`.
   - `atomic_write_json($path, $data)` — write `<path>.tmp` under `LOCK_EX`, then
     `rename()` (atomic on POSIX; reuses one transient inode). Windows dev
     fallback: `copy()` overwrite (never atomic, but never loses the file).
   - `app_save_upload_image(...)` — see Uploads.
4. **Access enforcement** (callers pick):
   - `admin_require_auth()` — 401 if `$_SESSION['auth']` is unset.
   - `admin_require_csrf()` — compares `X-CSRF-Token` header to `$_SESSION['csrf']`
     with `hash_equals`; 403 on mismatch.
   - `admin_require_post()` — 405 on non-POST.
   - `admin_redirect_if_guest()` — 302 to `login.php` for HTML pages.
   - `admin_csrf_token()` — mint once per session (`bin2hex(random_bytes(32))`).
5. Guard against double-include with a `defined()` sentinel. Direct web access to
   `_guard.php` is denied by `.htaccess`.

## `config.php` (in `private/`, never in the web root)
```php
<?php return array(
  'pass_hash'    => '$2y$…',        // password_hash('yourpass', PASSWORD_DEFAULT)
  'session_name' => 'appadmin',
  'max_attempts' => 5,
  'lockout_secs' => 900,
);
```
Generate the hash offline; **never commit a real password**. Ship a
`config.sample.php` and have the user create the real one on the host. Default to
a placeholder and tell the user to change it.

## `login.php` — sign-in + per-IP throttle
- Includes `_guard.php`; if already authed → redirect to `index.php`.
- **Per-IP throttle** in `private/login-throttle.json`: after `max_attempts`
  failures within `lockout_secs`, return 429 and refuse. Clear on success.
- Honeypot `website` field → count as a failed attempt, reveal nothing.
- Verify with `password_verify($_POST['password'], pass_hash)`. On success:
  `session_regenerate_id(true)`, set `$_SESSION['auth']=true`, mint CSRF, redirect.
- `noindex,nofollow` meta; the page never leaks whether the password or the
  lockout was the reason beyond a generic message.

## Read endpoints (`*-get.php`)
`require _guard.php; admin_require_auth();` then `json_out(read_json(APP_…))`.
Read-only, so no CSRF needed.

## Write endpoints (`*-save.php` / `*-create.php` / `*-delete.php`)
Every one: `admin_require_post(); admin_require_auth(); admin_require_csrf();`
then:
- Parse the JSON body; 422 if not an array.
- **Sanitise**: `clean_str()` (normalise newlines, strip control chars, trim,
  length cap) and `clean_str_array()` (per-element sanitise + item/length caps).
- **Allowlist fields explicitly.** Copy only known keys onto the record. For
  per-item **merge-by-slug**: read the array, find by `slug`, overwrite only
  allowlisted keys, pass everything else through untouched. For whole-file config
  (`content-save`): rebuild the document from known keys only.
- **Validate identities**: `create` requires a unique kebab `slug`
  (`^[a-z0-9][a-z0-9-]*$`), a `category` from the fixed set, a non-empty name; 409
  on duplicate slug. `delete` removes by slug and unlinks its image file.
- **Atomic write**; return `{"success":true, …}` or a JSON error + status.
- Keep every allowlist in sync across the endpoint, the admin JS, and any mirrored
  list in the public JS (e.g. the category set). Note this in comments.

## Uploads (`*-image.php`) — `app_save_upload_image()`
Multipart, auth + CSRF. Validate a **real** image via `getimagesize` (not the
extension), cap size (~4 MB), then hand to the shared helper which:
- clears any prior file for that slug (one file per id — matters on inode-limited
  hosts),
- re-encodes to **WebP q82** (smaller than JPEG/PNG same quality), applying JPEG
  EXIF orientation and downscaling the longest side to ≤1600px, preserving
  PNG/WebP alpha,
- degrades gracefully if GD can't write WebP (re-encode in the original format,
  else store the raw upload),
- returns the **saved extension** so the caller builds the stored path.
The caller then persists the path + a fresh `imgV` cache-buster via the item's
save endpoint. Uploads go under `assets/img/<kind>/`; admin display paths need a
`../` prefix because the admin runs under `/admin/` but stored paths are
root-relative.

## `index.php` — the dashboard
`admin_redirect_if_guest()` at the top; then tabbed editors (items, content,
enquiries, analytics, taxonomies) that call the JSON endpoints with the CSRF
header. Scope the admin surface to what Phase 2 said the client will actually
edit — don't build editors for content nobody maintains.
