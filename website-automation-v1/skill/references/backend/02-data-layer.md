# 02 — The data layer (runtime JSON)

Content lives in JSON files under `assets/data/`, fetched by the front end at
runtime and written only through the authed admin API. No database.

## The files
- **`content.json`** — editable site copy and home-page content. Shape is
  project-specific; a typical structure:
  ```json
  {
    "copy":       { "contactPhone": "", "contactEmail": "", "contactAddr": "",
                    "footerCopy": "", "footerPowered": "" },
    "visible":    { "contactPhone": true, "contactEmail": true, "contactAddr": true },
    "home":       { "heroTitle": "", "heroSub": "", "heroImg": "", "heroImgV": "",
                    "stats": [], "slides": [], "featured": [] },
    "categories": { "<catId>": { "img": "", "imgV": "" } }
  }
  ```
  Every field is **optional**; the public page falls back to the copy baked into
  the HTML when a field is blank (see `03`). Image slots carry an `imgV`
  cache-buster string alongside the path.

- **`items.json`** — the entity collection (rename per project). A flat JSON
  array; each element is one item:
  ```json
  {
    "slug": "unique-kebab-key",
    "category": "<catId>",
    "name": "Display Name",
    "description": "short one-liner",
    "fullDescription": "<p>rich sanitised HTML…</p>",
    "img": "assets/img/items/unique-kebab-key.webp",
    "images": ["assets/img/items/…-1.webp", "…-2.webp"],
    "imgV": "1699999999",
    "hidden": false
  }
  ```
  - `slug` is the URL key and the identity for merge-by-slug on save.
  - `img` mirrors `images[0]` (the card/`og:image` shot); `images[]` is the
    gallery; `imgV` cache-busts all of them.
  - Add whatever fields the entity needs; keep them flat and JSON-serialisable.
  - `hidden` — see below.

- **`attributes.json`** *(optional)* — shared vocabularies/taxonomies the items
  reference by code (spec codes, tags, filters). Master list edited in admin,
  referenced from items by short code.

## Read rules (front end)
- Fetch each file **once per visit** and cache it in `sessionStorage` (see the
  per-visit cache in `03`). The files are served `no-cache`, so a fresh window is
  a fresh fetch, but repeated navigations within a visit don't refetch.
- **Filter `hidden` items in exactly one place** — the loader — so every consumer
  (catalogue, search, category counts, featured, related, and the detail-page
  `?slug=` lookup) inherits the filter and a hidden slug simply stops resolving.
- Distinguish **"file failed to load"** from **"everything is hidden / empty"** —
  they need different empty-state messages. Track a `loadFailed` flag.

## Write rules (admin API — see 05)
- **Atomic writes only** via `atomic_write_json()` (write `<file>.tmp`, then
  `rename()`); never `file_put_contents` the live file directly.
- **Allowlist every field.** A save endpoint copies only known keys from the
  request into the record and passes unknown keys through untouched (for
  merge-by-slug) or drops them (for whole-file replacement) — per endpoint. Never
  trust the client to send a well-formed record.
- **Merge-by-slug** for per-item edits: read the array, find the item by `slug`,
  overwrite only allowlisted fields, write the array back. Creating appends after
  a uniqueness check; deleting removes by slug (and its image file).
- **Sanitise strings**: normalise `\r\n`→`\n`, strip control chars, `trim`, cap
  length. Sanitise arrays element-by-element with item and length caps.
- Bump the item's `imgV` when its image changes, so the immutable image cache
  doesn't serve the old photo.

## Why JSON, not a DB
The content is small, read-mostly, and edited by one or few people. Flat JSON is
diffable, backup-trivial, needs no schema migration, and costs nothing to host.
If a project genuinely needs relational queries, many concurrent writers, or user
accounts, that is an explicit step beyond this stack — raise it, don't assume it.
