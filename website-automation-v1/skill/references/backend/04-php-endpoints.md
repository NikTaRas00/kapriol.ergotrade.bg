# 04 — Public PHP endpoints

Three small, self-contained PHP scripts at the web root. None require a login;
none start a session (starting one would set a cookie on every visitor). Ready
templates: `templates/send-mail.php`, `templates/track.php`,
`templates/sitemap.php`.

## `send-mail.php` — contact form handler
Accepts a JSON (or form-encoded) POST and emails the enquiry, logging it too.

- **Always responds JSON**: `{"success":true}` or `{"success":false,"error":"…"}`.
- **Method guard**: non-POST → 405.
- **Honeypot**: a hidden `website` field. If it is non-empty, return
  `{"success":true}` and send nothing (silently drop the bot).
- **Validate server-side**: required fields present; `email` passes
  `FILTER_VALIDATE_EMAIL`; length caps (e.g. message ≤5000, name ≤200) → 422.
- **Log first, mail second**: append the enquiry to `private/enquiries.json`
  under an exclusive lock (bounded to the last N records), wrapped so a storage
  hiccup never breaks the visitor's response. This means the admin can read
  submissions even if `mail()` fails.
- **Header-injection safe**: strip CR/LF from any value that lands in a mail
  header (name/email in `Reply-To`). RFC-2047-encode the subject for UTF-8.
- **From address** must be a domain mailbox (`no-reply@<domain>`) for SPF/DKIM
  deliverability on shared hosting; **To** is the recipient inbox. Set both at the
  top; ask the user for the recipient in Phase 4.
- Locates `private/` the same way the guard does: sibling of the web root first,
  in-web-root fallback.

## `track.php` — cookieless analytics beacon
The static pages `navigator.sendBeacon()` a tiny JSON body on load; this records
**aggregate counts only** — no IP, no user-agent stored, no cookie — so it needs
no consent banner.

- **Never includes the guard; never starts a session.**
- Non-POST → 204. Responds fast and empty (`204`); sendBeacon ignores the body.
- **Drops obvious bots** by user-agent regex (bot/crawl/spider/headless/curl/…)
  so the numbers are about real people. The UA is used to filter, not stored.
- Payload: `{ page: "<slug>", s: <bool newSession>, slug: "<optional item slug>" }`.
  Sanitise `page`/`slug` to `[a-z0-9_-]`, cap length.
- Under an exclusive lock (`fopen('c+')`, `flock`), read-modify-write a single
  `private/analytics.json`:
  - `totals.views` (+1 always), `totals.visits` (+1 when `s`),
  - `daily[YYYY-MM-DD]` views/visits, capped to the last ~180 days,
  - `pages[<page>]` counter,
  - `products[<slug>]` counter, capped (keep top N) so a full crawl can't bloat it.
- The client sends `s:true` only once per browser session (guard with
  `sessionStorage`), so "visits" approximate unique sessions.

## `sitemap.php` — dynamic XML sitemap
Served at `/sitemap.xml` via the `.htaccess` rewrite; `robots.txt` points here.

- Reads the **live** `assets/data/items.json` on every request, so items added or
  removed through the admin appear with no regeneration step.
- **Origin from the request host** (`$_SERVER['HTTP_HOST']`), so it survives a
  domain move with no edit.
- Emits the static pages (home/list/contact/…) plus **one `<url>` per item**
  (`/item?slug=<slug>`), `rawurlencode`-ing the slug and `htmlspecialchars` the
  loc.
- **Skips `hidden` items** — never invite a crawler to a page the front end
  answers with "not found." This mirrors the front-end hidden filter server-side.

## Shared conventions
- Send `X-Content-Type-Options: nosniff` on every response.
- Fail safe: an endpoint should degrade to a harmless empty/204 response rather
  than 500 on a missing optional resource (e.g. no `private/` yet).
- Keep each script single-concern and dependency-free.
