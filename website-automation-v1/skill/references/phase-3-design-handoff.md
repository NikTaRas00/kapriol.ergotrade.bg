# Phase 3 — Design Handoff

**Goal:** produce the `/design-handoff-start/` folder the user uploads to Claude
Design, then step back. Claude Code does almost nothing during the actual
design work — this phase is about writing a clean, complete brief.

## What you create

Create `/design-handoff-start/` in the project root with:

```
design-handoff-start/
  SITEMAP.md          The finalised site map (from Phase 2).
  BRANDING.md         Visual + verbal identity for the designer.
  INSTRUCTIONS.md     What Claude Design must do + the required OUTPUT CONTRACT.
  assets/             Reference images/videos/logos from /intro/ + anything gathered.
```

Use the templates in `handoff/` as the starting structure:
- `handoff/SITEMAP.template.md`
- `handoff/BRANDING.template.md`
- `handoff/INSTRUCTIONS.template.md`

Fill them from `.website-automation/state.md`. **Format is Markdown + an assets
folder** (chosen so Claude Design reads prose and images natively and the user
can edit any section by hand). Do not invent a proprietary format.

### SITEMAP.md
The page list with routes, purpose, nav membership, page-vs-section, and — for
any data-driven entity — the category grouping, per-item fields, and what the
detail page shows. Be explicit about **which sitemap nodes become their own
page** while designing vs. which are sections within a page.

### BRANDING.md
Everything the designer needs for a coherent look and voice: brand story in one
paragraph, tone words, colour direction, typography direction, imagery style,
logo usage, and any hard visual constraints from the brief. Reference the files
in `assets/` by name.

### INSTRUCTIONS.md — the important one
Tells Claude Design what to build and, **most importantly, the exact output
style/contract** for the finished front end, so Phase 4 can consume it
deterministically. The output contract is the SAME for every project (it matches
the backend this skill implements). It must state that the finished design is
returned in `/design-handoff-end/` as:

- **Static HTML/CSS/JS, one HTML file per "own-page" sitemap node**, using clean
  extensionless-friendly file names (`index.html`, `products.html`,
  `product.html`, `contact.html`, …).
- **One shared stylesheet** (`assets/css/style.css`) and **one shared script**
  (`assets/js/main.js`) — not per-page CSS/JS. The script must be structured so
  each page's behaviour no-ops when its root element is absent (a single file
  serves every page).
- **No build step, no framework, no npm, no external CDN dependencies at
  runtime** — plain HTML/CSS/vanilla JS only. Self-host fonts under
  `assets/fonts/`. Inline nothing that belongs in the shared files.
- **Data-driven pages must render from placeholder JSON, not hardcoded content**,
  so Phase 4 can swap the real data layer in. Detail/catalogue/list pages should
  read from `assets/data/*.json` (the designer may ship sample JSON).
- **Deliverable packaging:** the whole `/design-handoff-end/` folder (a `.zip` is
  fine), preserving the `assets/` structure.

The full, copy-paste output contract lives in
`handoff/INSTRUCTIONS.template.md` — keep it identical across projects so Phase 4
always receives the same shape.

## Steps
1. Confirm the site map is final (last chance to adjust).
2. Generate the three documents from the templates + state, and copy reference
   assets into `design-handoff-start/assets/`.
3. Tell the user the folder is ready, and give them the handoff instructions:
   upload the whole `/design-handoff-start/` folder to Claude Design; when done,
   have Claude Design emit `/design-handoff-end/` following the output contract;
   copy that folder into the project root; then tell Claude Code to start Phase 4.
4. Record `phase: 3 complete — awaiting design-handoff-end` in state, and **stop**.
   Do not attempt to design the site yourself.

## Notes
- If the user's brand has real logos/photography in `/intro/`, pass them through;
  do not generate placeholder brand art unless asked.
- The output contract is a promise to Phase 4. If you change it here, change
  `phase-4-build.md`'s expectations to match.
