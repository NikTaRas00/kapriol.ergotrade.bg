# Phase 4 — Build

**Goal:** turn the returned front end in `/design-handoff-end/` into a working
website by wiring in the documented backend. This is where the precise backend
spec pays off. Phase 4 continues as a normal collaborative build until the user
says the skill is finished.

## Before you build — read the backend spec
Read `references/backend/` in full before writing backend code:
`00-overview.md` first, then `01`–`07`. Implement **that** architecture. The
`templates/` folder holds drop-in starting code — **see `templates/README.md` for
the manifest** (which file copies where + the per-project adaptation checklist).
Copy and adapt those files rather than writing endpoints from memory.

## Steps

1. **Read `/design-handoff-end/` completely.** Inventory the pages, the shared
   `assets/css/style.css` and `assets/js/main.js`, any `assets/data/*.json`
   sample data, fonts, and images. Note where the design diverged from the output
   contract (e.g. per-page scripts, hardcoded content, CDN links) — you'll
   reconcile those.

2. **Interview with `AskUserQuestion`** for anything the build needs and the
   handoff didn't settle. Typical decisions:
   - Which pages/entities need an **admin editor**, and which fields are editable.
   - Contact form recipient + whether to keep a private enquiries log.
   - Whether to include the analytics beacon and the dynamic sitemap.
   - Hosting specifics (PHP shared/cPanel is the assumed target; confirm).
   - The admin password (never hardcode a real one — see `05-admin-area.md`).

3. **Stand up the backend from the spec:**
   - Move the design's pages to the site root; keep the shared CSS/JS/asset layout.
   - Create the **data layer** (`assets/data/*.json`) from the entities defined in
     Phase 2 / the sample data — see `02-data-layer.md`.
   - Refactor `main.js` into the **single guarded IIFE** with the per-visit cache
     and runtime JSON loading — see `03-frontend-runtime.md`.
   - Add the **PHP endpoints** you agreed on: `send-mail.php`, `track.php`,
     `sitemap.php` — see `04-php-endpoints.md`.
   - Add the **admin area** (`admin/` + `admin/api/` + `private/`) with the guard,
     login/throttle, CSRF, allowlisted save endpoints and image uploads — see
     `05-admin-area.md`.
   - Add **`.htaccess`**, error pages, and caching/security headers — see
     `01-hosting-htaccess.md` and `06-security.md`.

4. **Verify offline** (the user typically does not use a live preview):
   `php -l` every PHP file; `node --check` the JS; JSON-parse every data file;
   confirm the guard/CSRF/atomic-write behaviour by reading, not guessing. Only
   run a live preview if the user asks.

5. **Iterate.** The user corrects, brings in other skills, adds pages. Keep the
   backend faithful to the spec unless they override it.

6. **Ending the skill.** When the user says the skill is finished, stop following
   this workflow, record `phase: 4 — skill closed by user` in state, and continue
   as a normal Claude Code build session.

## Reconciling a non-conforming design
If `/design-handoff-end/` broke the output contract:
- **Per-page scripts / inline JS** → consolidate into the shared guarded IIFE.
- **Hardcoded content on a data-driven page** → extract into `assets/data/*.json`
  and render at runtime; keep the hardcoded copy as the fallback.
- **CDN/external runtime deps** → self-host (fonts under `assets/fonts/`, etc.).
- **Wrong file granularity** → split/merge to one HTML file per own-page node.
Flag each reconciliation to the user; don't silently rewrite their designer's work.

## Deploy
Follow `references/backend/07-deploy.md`: ship code only, never overwrite
host-side JSON/images (they are admin-edited live), and keep `private/` outside
the web root.
