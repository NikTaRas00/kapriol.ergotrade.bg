# Phase 2 — Site Map

**Goal:** converge on a site map the user is happy with — the list of pages,
their purpose, their relationships, and any special sections (admin, blog,
catalogue, search) — through an interview-and-iterate loop. Phase 2 ends only
when the user tells you to start Phase 3.

## The loop

1. **Interview with `AskUserQuestion`.** Ask the questions you need to shape the
   map. Batch related decisions into one call (up to 4 questions). Cover at least:
   - **Site type / primary goal** (marketing/credibility, catalogue, lead-gen,
     portfolio, e-commerce-lite, docs…). This drives everything.
   - **Core pages** the user knows they want.
   - **Catalogue vs. flat content:** is there a repeating data-driven entity
     (products, services, listings, articles)? If yes, capture: the entity name,
     roughly how many, whether they group into categories, and what a single
     detail page shows. (This maps directly onto the backend data layer.)
   - **Special/back-office pages:** an **admin area** to edit content? A contact
     form? Search? Analytics? Multi-language? Explicitly ask "what special pages
     do you want (admin section, etc.)?" — the brief rarely states these.
   - **Data ownership:** who edits the content after launch, and how often? (Decides
     how much of the admin surface to build.)

2. **Propose a first site-map draft.** Present it clearly — a tree or table:
   - Page name → route (clean URL) → purpose → data-driven? (Y/N) → in main nav?
   - Mark which nodes are **their own page** vs. a section within a page.
   - Note the data entities and their fields alongside.

3. **Iterate.** The user reacts and corrects. Keep brainstorming, ask follow-up
   `AskUserQuestion`s where a decision has discrete options, and revise the draft.
   Repeat until they're satisfied.

4. **Do not rush to Phase 3.** Continue the loop until the user explicitly says
   to start Phase 3. When they do, first make sure the map is complete enough to
   hand to a designer (every page has a purpose and a rough content inventory).

## Output of Phase 2
A settled site map held in `.website-automation/state.md`, containing:
- The ordered page list with routes, purpose, nav membership, and page-vs-section.
- The data entities: name, category grouping, per-item fields, detail-page contents.
- The special pages decided (admin scope, contact form, search, languages).
- Any global elements (header/nav, footer, breadcrumbs) and the brand's tone notes
  carried from Phase 1.

This is the raw material Phase 3 turns into `SITEMAP.md` + `BRANDING.md` +
`INSTRUCTIONS.md`. Record `phase: 2 complete` when the user calls Phase 3.

## Notes
- Keep tying choices back to the backend: a "products" entity with categories,
  detail pages and an editor becomes the JSON data layer + admin of
  `references/backend/`. Naming the entity and its fields now saves rework later.
- If the user wants something the documented backend does not cover (a database,
  payments, user accounts, real e-commerce), flag it now as an extension beyond
  the standard stack, and note it in state — don't silently promise it.
