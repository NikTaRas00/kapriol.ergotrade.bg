# 01 — Hosting, .htaccess, clean URLs, caching, error pages

Target host: **shared Apache + PHP (cPanel-class)**. Everything here is Apache
config plus static error pages. A ready `.htaccess` is in
`templates/htaccess.txt` — copy it to `.htaccess` and adjust the noted lines.

## Responsibilities of `.htaccess`
1. **Directory hardening** — `Options -Indexes -MultiViews`, and
   `DirectoryIndex index.html index.php` (list `index.php` too, so a PHP-only
   directory like `/admin/` still resolves its index).
2. **Deny private files** even if `private/` ends up inside the web root:
   block `config|enquiries|login-throttle|analytics\.(php|json)`, block
   `_guard.php` and `*.tmp`, and `RewriteRule ^private/ - [F,L]`.
   **Do not block the public data files** (`items.json`, `content.json`,
   `attributes.json`) — the front end fetches them; they are only *writable*
   through the authed admin API.
3. **Canonical host + HTTPS** — `www` → non-www and http → https in one hop each,
   via `R=301`. (Adjust/remove if the project wants www as canonical.)
4. **Dynamic sitemap** — rewrite `^sitemap\.xml$` to `sitemap.php`.
5. **Clean, extensionless URLs** — three rules, in order:
   - `.../index.html` → `.../` (301, canonical home).
   - `.../page.html` → `.../page` (301, strip `.html`, keep query string).
   - internally serve `page` from `page.html` when the file exists
     (`RewriteCond %{REQUEST_FILENAME}\.html -f`).
   These use `%{THE_REQUEST}` so they work at the web root **and** in a subfolder.
6. **Error pages** — `ErrorDocument <code> /error/<code>.html` for
   400/401/403/404/405/429/500/502/503/504.
7. **Compression** — `mod_deflate` for html/css/js/json/svg.
8. **Caching** (see also `06-security.md`):
   - css/js/img/video/woff2 → `access plus 1 year` + `Cache-Control: public,
     max-age=31536000, immutable`.
   - `.html` → `access plus 0 seconds` + `Cache-Control: no-cache`.
   - `.json` → `Cache-Control: no-cache, must-revalidate` (admin edits must show
     immediately).
9. **Security headers** — `X-Content-Type-Options: nosniff` globally.
10. **MIME fallbacks** — `AddType` for `.mp4`, `.svg`, `.woff2`.

## The cache-busting rule (critical, recurring bug)
Because assets are **1-year immutable**, a changed `style.css`/`main.js` will
NOT be picked up by returning browsers unless its URL changes. Therefore:

- Reference shared assets as `assets/css/style.css?v=N` and
  `assets/js/main.js?v=N` in every HTML page.
- **Bump `N` on every edit to that asset, across all pages** — including a second
  edit in the same session if the first was already deployed. A version string is
  spent the moment it ships.
- Version other immutable assets that get replaced too (hero video, etc.).
- Symptom of getting this wrong: the server has the new file (`curl | grep`
  confirms) but the browser renders the old one.

HTML itself is `no-cache`, so the *new* `?v=N` reference is always read
immediately; only the asset bytes behind a stale URL are the risk.

## Clean-URL implications for links
Write internal links **without** the `.html` extension (`/products`, `/contact`),
letting the rewrite serve the file. The home link is `/`. Query strings survive
the rewrite, so `product?p=<slug>` works.

## Error pages (`error/<code>.html`)
- **Self-contained**: inline CSS, **zero external requests**, no `@font-face`, no
  JS — they must render during the very outage they report.
- Include `<base href="/">` so nav links resolve regardless of the failing URL's
  depth.
- **No entrance animation.** An animation that starts from `opacity:0` and never
  advances (throttled tab, headless renderer) parks the page invisible, and these
  files ship no JS failsafe. Only button hover/press (transitioning from a visible
  resting state) are safe.
- The URL prefix in the `ErrorDocument` lines and in each page's `<base href>` is
  location-coupled. If the site moves into a subfolder, update all of them.

## When the site moves domains or into a subfolder
These places hardcode the location — change them together:
- `.htaccess` `ErrorDocument` paths.
- `<base href>` in every `error/*.html`.
- Any `<link rel="canonical">` / `og:url` / `og:image` absolute URLs in the HTML.
- The `Sitemap:` line in `robots.txt`.
`sitemap.php` derives its origin from the request host, so it is move-proof by
itself.
