# 06 — Security model (gathered in one place)

Nothing here is new — it collects the security-relevant rules so they're auditable
together. Implement all of them.

## Secrets & private data
- `private/` holds the password hash (`config.php`), enquiries, analytics, and the
  login throttle. It **lives outside the web root** (sibling of `public_html`).
- Belt-and-braces `.htaccess` denies `config|enquiries|login-throttle|analytics`
  files and `_guard.php` and `*.tmp`, and forbids `^private/`, in case `private/`
  ever sits inside the web root.
- **Never commit a real password.** Ship `config.sample.php`; the real
  `config.php` is created on the host. `pass_hash` is a `password_hash()` bcrypt
  string; verify with `password_verify()`.

## Authentication & sessions
- Session cookie: `httponly`, `samesite=Strict`, `secure` only over real HTTPS,
  `path` derived from `SCRIPT_NAME`.
- `session_regenerate_id(true)` on successful login (prevents fixation).
- **Per-IP login throttle**: lock after N attempts within a window; return 429.
- Honeypot field on the login form counts as a failed attempt.

## CSRF
- Token minted once per session (`random_bytes(32)` hex), stored in the session.
- **Every state-changing endpoint** requires the token in an `X-CSRF-Token`
  header, compared with `hash_equals`. Read endpoints don't need it; they still
  require auth.
- SameSite=Strict is a second layer, not a substitute for the token.

## Input handling
- **Allowlist, never blocklist.** Save endpoints copy only known keys; unknown
  keys are dropped or passed through untouched, never interpreted.
- Sanitise every string (normalise newlines, strip control chars, trim, cap
  length); sanitise arrays element-by-element with caps.
- Validate identities with strict regex (kebab slug, fixed category set).
- **Rich HTML** (an item's `fullDescription`) is scrubbed through a **whitelist**
  sanitiser before render — allow a small tag set (`p, ul, li, h3, strong, em, a`),
  strip everything else, neutralise `javascript:` hrefs. Do this on the way in
  (admin) and defensively on the way out (front end).

## Output & headers
- `X-Content-Type-Options: nosniff` on every response (PHP + `.htaccess`).
- JSON responses via `json_out()` with the correct content type.
- HTML escape all interpolated values in PHP pages (`htmlspecialchars(...,
  ENT_QUOTES, 'UTF-8')`); in JS build DOM with `textContent`/text nodes, never
  `innerHTML` of untrusted strings. `setMultiline()` inserts text nodes for the
  same reason.

## Mail
- Honeypot drop for bots.
- Strip CR/LF from any value used in a mail header (anti header-injection).
- Domain `From` mailbox for SPF/DKIM; RFC-2047-encode the subject.

## Writes & integrity
- **Atomic writes** (`.tmp` + `rename()` under `LOCK_EX`) so a concurrent reader
  never sees a half-written file and a crash can't truncate the live file.
- Analytics/enquiries use `fopen('c+')` + `flock(LOCK_EX)` read-modify-write with
  size caps so concurrent hits can't lose each other or bloat the file unbounded.

## Privacy
- Analytics stores **aggregate counts only** — no IP, no UA, no cookie — so no
  consent banner is required. The UA is read to filter bots, never stored.
- Don't put personal data in URLs/query strings.

## Caching correctness (a security-adjacent footgun)
- Assets are 1-year immutable → **bump `?v=N` on every asset edit** or users run
  stale code. JSON is `no-cache` so admin edits show at once. Getting this wrong
  ships old CSS/JS to returning visitors while the server has the new file.
