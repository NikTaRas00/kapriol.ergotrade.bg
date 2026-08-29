---
name: website-automation-v1
description: >-
  Orchestrated four-phase workflow for building a complete brand website from
  scratch: read the client brief, brainstorm a site map, produce a design
  handoff for Claude Design, then build the finished, functioning site on a
  precise, proven backend architecture (static HTML/CSS/JS front end + PHP data
  layer, JSON content store, session-based admin, contact/analytics/sitemap
  endpoints, hardened .htaccess). Invoke when the user starts a new website
  project and runs /website-automation-v1, or asks to "start the website
  automation", "run the website skill", or continue an in-progress phase.
---

# Website Automation v1

You are running a **guided, multi-phase website build**. The user drives the
pace; you orchestrate. Your job is to move the project through four phases,
and — most importantly — to build the final site on the exact backend
architecture documented in `references/backend/`, so that once a front end
exists the working site comes together fast and correctly.

## The prime directive

The value of this skill is a **precise, reusable backend**. When you reach
Phase 4 you do not improvise the architecture — you implement the one specified
in `references/backend/`. Read those files before writing backend code. Do not
invent a different stack, framework, or data model unless the user explicitly
overrides it.

## The four phases

| Phase | Name | What you do |
|------|------|-------------|
| 1 | **Intro** | Read everything in `/intro/`, confirm understanding, ask to begin Phase 2. |
| 2 | **Site Map** | Interview the user (`AskUserQuestion`), propose and iterate a site map until they say start Phase 3. |
| 3 | **Design Handoff** | Write `/design-handoff-start/` (SITEMAP.md, BRANDING.md, INSTRUCTIONS.md + assets) for the user to upload to Claude Design. Then wait. |
| 4 | **Build** | Read the returned `/design-handoff-end/`, interview as needed, then build the working site on the specified backend. |

Full instructions for each phase are in `references/`. **Read the matching phase
file at the start of each phase** — do not work from this table alone.

- Phase 1 → `references/phase-1-intro.md`
- Phase 2 → `references/phase-2-sitemap.md`
- Phase 3 → `references/phase-3-design-handoff.md`
- Phase 4 → `references/phase-4-build.md`

## Detecting which phase you are in

Never assume. On every invocation, determine the current phase from the project
state, in this order:

1. Read `.website-automation/state.md` if it exists — it records the phase last
   reached and any decisions made. This is the source of truth.
2. If there is no state file, infer from folders on disk and confirm with the user:
   - `/design-handoff-end/` present → **Phase 4** (build).
   - `/design-handoff-start/` present, no `/design-handoff-end/` → between Phase 3 and 4 (waiting on Claude Design).
   - `/intro/` present only → **Phase 1** (just starting).
   - none of these → ask the user to set up `/intro/` first (Phase 1 step 1).
3. Announce the detected phase and confirm before doing work in it.

**Maintain `.website-automation/state.md`** as you go: record the current phase,
key answers from the Phase 2 interview, the agreed site map, and any backend
choices. This lets a fresh session resume cleanly. Create the
`.website-automation/` folder if absent.

## Global rules while the skill is running

- **Use `AskUserQuestion` liberally** — it is the interview instrument for
  Phases 2 and 4. Prefer it over free-text questions whenever the decision has a
  small set of concrete options.
- **One phase transition at a time.** Never skip ahead. Always ask before moving
  from one phase to the next (e.g. "Ready to start Phase 2?").
- **The user ends the skill, not you.** When the user says the skill is finished
  (typically during Phase 4), stop following this workflow and continue as a
  normal build session.
- **Do not name or reference the source project** the backend spec was derived
  from. The spec in `references/backend/` is written to stand alone.

## Reference map

```
references/
  phase-1-intro.md            How to run the intro/confirm/handshake.
  phase-2-sitemap.md          The interview + site-map iteration loop.
  phase-3-design-handoff.md   Writing /design-handoff-start/ + the Claude Design contract.
  phase-4-build.md            Consuming /design-handoff-end/ and building.
  backend/                    THE precise backend architecture (read in Phase 4).
    00-overview.md            Stack, folder layout, principles, non-negotiables.
    01-hosting-htaccess.md    Apache/cPanel, .htaccess, clean URLs, caching, errors.
    02-data-layer.md          JSON content store, shapes, read/write rules.
    03-frontend-runtime.md    The single guarded-IIFE JS pattern + per-visit cache.
    04-php-endpoints.md       Contact mail, analytics beacon, dynamic sitemap.
    05-admin-area.md          Session login, guard, CSRF, allowlisted save endpoints, uploads.
    06-security.md            The security model, gathered in one place.
    07-deploy.md              What ships, what never ships, the deploy method.
templates/                    Drop-in starting code for the backend (copy + adapt).
handoff/                      Templates for the three Phase-3 documents.
```
