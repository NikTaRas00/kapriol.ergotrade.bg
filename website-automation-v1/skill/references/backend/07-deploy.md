# 07 — Deploy

Target: shared Apache + PHP (cPanel-class), typically over FTP/SFTP or the cPanel
file manager. The guiding rule: **ship code, never overwrite live content.**

## What ships
- The public pages (`*.html`), `send-mail.php`, `track.php`, `sitemap.php`,
  `.htaccess`, `robots.txt`, the `error/` folder.
- `assets/css`, `assets/js`, `assets/fonts` (self-hosted `*.woff2`).
- The `admin/` folder (pages + `assets/` + `api/`).

## What NEVER ships on a normal deploy
- **`assets/data/*.json`** — the live content is edited through the admin and
  diverges from your local copy. Overwriting it destroys the client's edits.
- **`assets/img/…`** — images are uploaded live through the admin. Same reason.
- **`private/`** — via the deploy account it usually can't be pushed anyway (see
  below); upload it once by hand.

Seed `assets/data` and `assets/img` on the host **once** at first launch (initial
JSON + any starter images), then exclude them from every subsequent deploy.

## `private/` placement
- Must end up **outside the web root** (sibling of `public_html`). A chrooted
  deploy account often can't write there, so upload `config.php` (with the real
  password hash) and create the empty JSON logs via the cPanel file manager / main
  account once.
- Confirm the app finds it: the guard prefers the sibling location.

## First-launch checklist
1. Upload all shipping code to the web root.
2. Create `private/` outside the web root; add `config.php` with a real
   `pass_hash` (change the default), and empty/started
   `enquiries.json` / `analytics.json` / `login-throttle.json` as needed.
3. Seed `assets/data/*.json` and any starter images **once**.
4. Ensure `assets/img/<upload-dirs>/` are writable by PHP.
5. Set the contact recipient in `send-mail.php`; confirm the domain mailbox
   exists for SPF/DKIM.
6. Update the location-coupled URLs (canonical/og/robots/ErrorDocument/base href)
   to the real domain (`01-hosting-htaccess.md`).
7. Verify `/admin/` resolves (DirectoryIndex includes `index.php`), login works,
   and a test save writes the JSON.

## Recommended deploy script
Provide a small upload script (PowerShell/bash) with explicit **include** dirs
(html, css, js, fonts, php, admin, error) and hard **exclude** of `assets/data`,
`assets/img`, `private/`. Prompt for the password interactively; offer a
`-DryRun`/preview mode that uploads nothing. For a non-technical operator, wrap it
in a double-clickable launcher.

## Cache discipline on every deploy
Bump `?v=N` on any changed `style.css`/`main.js` (and other replaced immutable
assets) across all pages — a version string is spent once it ships. HTML is
`no-cache`, so the new reference is read immediately; the stale-asset risk is only
behind an unchanged URL.
