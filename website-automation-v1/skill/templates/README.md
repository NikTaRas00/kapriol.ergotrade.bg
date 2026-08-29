# Templates manifest

Drop-in starting code for the backend. Copy each file to its destination in the
project and adapt the marked spots (entity name, category set, field allowlists,
domain, recipient). All PHP lints clean; both JS files pass `node --check`.

Rename convention: the generic entity is **`items`** (file `items.json`,
constant `APP_ITEMS`, image dir `assets/img/items/`, endpoints `items-*.php`).
Rename to the real entity from Phase 2 (e.g. `products`, `services`).

| Template file | Copy to | Purpose |
|---|---|---|
| `_guard.php` | `admin/api/_guard.php` | Shared bootstrap: config discovery, hardened session, CSRF, `read_json`/`atomic_write_json`/`json_out`, WebP image ingestion. |
| `config.sample.php` | `private/config.php` (on host) | Password hash + session settings. Never commit the real one. |
| `htaccess.txt` | `.htaccess` | Clean URLs, caching, security headers, private-file denial, error pages. |
| `robots.txt` | `robots.txt` | Crawl rules + sitemap pointer. Set the domain. |
| `sitemap.php` | `sitemap.php` | Dynamic XML sitemap from live data; skips hidden items. |
| `send-mail.php` | `send-mail.php` | Contact handler: honeypot, validate, log, mail. Set `$TO`/`$FROM`. |
| `track.php` | `track.php` | Cookieless analytics beacon. |
| `main.js` | `assets/js/main.js` | The single guarded-IIFE front-end runtime skeleton. |
| `admin-login.php` | `admin/login.php` | Sign-in + per-IP throttle + honeypot. |
| `admin-logout.php` | `admin/logout.php` | Clear session, return to login. |
| `admin-index.php` | `admin/index.php` | Tabbed dashboard (Items / Enquiries / Content). |
| `admin.css` | `admin/assets/admin.css` | Minimal admin styles. |
| `admin.js` | `admin/assets/admin.js` | Dashboard logic; sends CSRF on writes. |
| `endpoint-get.php` | `admin/api/items-get.php` | Read the collection (auth). |
| `endpoint-save.php` | `admin/api/items-save.php` | Merge-by-slug edit (auth+CSRF+allowlist). |
| `item-create.php` | `admin/api/items-create.php` | Append a new item. |
| `item-delete.php` | `admin/api/items-delete.php` | Remove item + its image. |
| `item-image.php` | `admin/api/items-image.php` | Upload → WebP re-encode. |
| `item-image-delete.php` | `admin/api/items-image-delete.php` | Delete one gallery photo file. |
| `content-get.php` | `admin/api/content-get.php` | Read editable site content. |
| `content-save.php` | `admin/api/content-save.php` | Write site copy / contact / home. |
| `enquiries-list.php` | `admin/api/enquiries-list.php` | List contact submissions (auth). |
| `error-page.html` | `error/<code>.html` (×10) | Self-contained error page (edit code/title/message). |

## Per-project adaptation checklist
1. Rename `items` → the real entity everywhere (files, `APP_ITEMS`, image dir, endpoint names, admin.js paths).
2. Fill `$CATEGORIES` (item-create) and `$CAT_IDS` (content-save) with the fixed set from Phase 2.
3. Extend the field allowlists in `endpoint-save.php` / `item-create.php` to the entity's real fields.
4. Set the domain in `robots.txt`, `sitemap.php`, `send-mail.php` (and the canonical/og URLs in the HTML).
5. Set the contact recipient + a real domain `From` mailbox in `send-mail.php`.
6. Generate a real `pass_hash` and place `config.php` in `private/` outside the web root.
7. Wire `main.js` renderers to the real markup; add `track()` calls per page.
8. Bump `?v=N` on css/js references whenever you edit them.
