# Backend Overview — the architecture you implement in Phase 4

This is the single, proven architecture this skill builds. It is deliberately
small, dependency-free, and cheap to host. Do not substitute a framework unless
the user explicitly asks.

## Stack in one line
Plain **static HTML + CSS + vanilla JS** front end, a **runtime JSON data
layer**, and **PHP only where dynamic behaviour is unavoidable** (contact mail,
analytics beacon, dynamic sitemap, and a session-based admin). Target host is
**shared Apache + PHP (cPanel-class)**. No build step, no npm, no framework, no
runtime CDN.

## Why this shape
- **Fast, portable, cheap.** Static pages + a few PHP scripts run on the
  cheapest shared hosting and load instantly.
- **Editable without a database.** Content lives in JSON files the admin edits;
  a database is unnecessary for a brochure/catalogue site and adds cost + ops.
- **One JS file, one CSS file.** The whole front end is one guarded IIFE and one
  stylesheet — trivial to reason about, cache, and ship.
- **Safe by construction.** Admin writes are authenticated, CSRF-checked,
  allowlisted field-by-field, and written atomically. Secrets and logs live
  outside the web root.

## Canonical folder layout
```
<web-root>/                     (public_html on the host)
  index.html                    one HTML file per "own-page" sitemap node
  <page>.html                   (products.html, product.html, contact.html, …)
  assets/
    css/style.css               ONE shared stylesheet
    js/main.js                  ONE shared guarded IIFE
    fonts/*.woff2               self-hosted fonts
    data/                       the runtime JSON data layer (public-readable)
      content.json              editable site copy + home-page content
      items.json                the data-driven entity collection (rename per project)
      attributes.json           optional taxonomies/spec vocabularies
    img/…                       images (product/site/etc.)
  send-mail.php                 contact form handler        (see 04)
  track.php                     cookieless analytics beacon (see 04)
  sitemap.php                   dynamic XML sitemap         (see 04)
  robots.txt
  .htaccess                     clean URLs, caching, security, error pages (see 01)
  error/<code>.html             styled, self-contained error pages
  admin/                        session-login dashboard + editors (see 05)
    login.php  logout.php  index.php
    assets/…                    admin-only css/js
    api/                        JSON endpoints (auth + CSRF on writes)
      _guard.php                shared bootstrap: config, session, helpers
      *-get.php  *-save.php     read/write endpoints, one concern each
private/                        OUTSIDE the web root (sibling of public_html)
  config.php                    admin password hash + session settings
  enquiries.json                contact submissions log
  analytics.json                aggregate counters
  login-throttle.json           per-IP login attempts
```

`private/` **must sit outside the web root** on the host (a sibling of
`public_html`). The code discovers it as a sibling first, then falls back to an
in-web-root `private/` that `.htaccess` denies — but the sibling location is the
correct one.

## The data-driven entity (generic model)
Most sites have one repeating thing: products, services, listings, projects,
articles. Model it once, generically:

- A **collection** stored as a JSON array in `assets/data/items.json`. Rename the
  file and the noun per project (`products.json`, `services.json`, …).
- Each item has at minimum a **`slug`** (unique, kebab-case, the URL key), a
  **`name`/`title`**, and a **`category`** if the collection groups. Add whatever
  fields the entity needs (description, images, price-less spec fields, etc.).
- **Two page types** consume it: a **list/catalogue page** (all items, optionally
  filtered by `?cat=<id>` or searched via `?q=`) and a **detail page**
  (`item.html?slug=<slug>` or `?p=<slug>`), one template rendering any item.
- **Categories** (if used) are a small fixed list defined once in the JS and
  mirrored in the admin save-endpoint allowlist.
- **Hidden flag:** an item may carry `hidden: true`; it is filtered out in exactly
  one place on load, so catalogue, search, detail lookup and sitemap all respect
  it without each remembering to check.

Configure the entity's name, fields, and category set from the Phase 2 site map.
Everything else in this spec is entity-agnostic.

## Non-negotiables (carry these into every build)
1. **One shared JS file, guarded per page.** Each init step no-ops when its root
   element is absent (`03-frontend-runtime.md`).
2. **JSON served `no-cache`; assets served 1-year immutable.** Version asset URLs
   with `?v=N` and bump on every edit (`01`, `06`).
3. **Admin writes = auth + CSRF + allowlist + atomic write.** No exceptions
   (`05-admin-area.md`).
4. **Secrets and logs never in the web root**, never served (`01`, `06`).
5. **Deploy ships code only** and never overwrites host-side JSON/images, which
   are edited live through the admin (`07-deploy.md`).
6. **No runtime third-party requests.** Self-host fonts and scripts; the front end
   works offline of any CDN.
