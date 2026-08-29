---
target: the whole website
total_score: 30
p0_count: 0
p1_count: 2
timestamp: 2026-06-18T17-38-18Z
slug: kapriol-website
---
# Kapriol website — design critique

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | No loading state for the 334-product catalog fetch (~500KB) — #catalog is blank until JSON resolves |
| 2 | Match System / Real World | 3 | Real category/product copy; article codes & specs not surfaced on detail |
| 3 | User Control and Freedom | 3 | Search clear, back links, Esc-to-close/clear all present |
| 4 | Consistency and Standards | 4 | Strong token system; cards/buttons/links/red accent fully consistent |
| 5 | Error Prevention | 3 | Form validates required + email regex + honeypot; on-submit only |
| 6 | Recognition Rather Than Recall | 3 | Visible nav, breadcrumb on detail, search, aria-labelled icons |
| 7 | Flexibility and Efficiency | 3 | Deep-linkable ?q=/?cat=, whole-card click; no power shortcuts (fine for register) |
| 8 | Aesthetic and Minimalist Design | 3 | Clean & on-brand, but catalog is a wall of identical grey "KAPRIOL" placeholders |
| 9 | Error Recovery | 3 | Clear form error + shake + preserved input; fetch-failure message; generic (no per-field) |
| 10 | Help and Documentation | 2 | Minimal; contact form is the only help path (acceptable for a marketing site) |
| **Total** | | **30/40** | **Good** |

## Anti-Patterns Verdict
**Deterministic scan:** clean — 0 findings across index/products/product/contact markup.
**LLM assessment:** Avoids the major tells — no gradient text, no glassmorphism, no hero-metric template, no tracked eyebrow on every section (uses a red rule instead), and the light theme is a deliberate non-default for the PPE category. The scrub-video full-bleed hero is genuinely distinctive. The one thing that reads "unfinished/templated": the catalog is a wall of identical grey placeholder cards, and Best Sellers on the home page still shows "Title / Lorem ipsum / Characteristic 1". Both are known pending-content items, not design failures, but they undercut first-impression credibility.

## Overall Impression
A confident, well-engineered light premium-industrial site with a strong token system and a distinctive hero. The craft is high; the gap is content (product photos, best-seller copy, real phone/address), plus a few interaction-polish items (catalog loading state, per-field form errors, unknown-product handling).

## What's Working
- **Token system & consistency** — one coherent visual language across four pages; nothing feels stitched together.
- **The hero** — full-bleed scroll-scrubbed video with end-zoom and a light multiply-blend is a real signature, not a template.
- **Search + catalog IA** — 6 categories × 3 with "View all", deep-linkable, whole-card tap targets, instant results, real empty state.

## Priority Issues
- **[P1] Best Sellers shows lorem on the home page.** "Title / Lorem ipsum / Characteristic 1/2" on the primary landing surface undercuts trust. Fix: real brand-level best-seller copy (or hide callouts until ready). Command: $impeccable clarify.
- **[P1] Catalog has zero product imagery.** 18+ identical grey "KAPRIOL" placeholders read as unfinished. Fix: real product photos (needs assets). Command: n/a (content).
- **[P2] No loading state for the catalog fetch.** ~500KB JSON; on a slow link #catalog is blank with no feedback. Fix: skeleton cards or a spinner during loadProducts(). Command: $impeccable polish.
- **[P2] Form validation is on-submit + generic.** "Please complete every field…" doesn't say which field. Fix: per-field inline validation/markers. Command: $impeccable harden.
- **[P2] Unknown ?p= slug silently shows the first product.** A stress-tester hitting a bad link sees a random product, not a "not found". Fix: a not-found message + link back to catalog. Command: $impeccable harden.
- **[P3] PHONE_PLACEHOLDER / ADDRESS_PLACEHOLDER on contact.** Needs real values. Command: n/a (content).

## Persona Red Flags
- **Jordan (first-timer):** lands on home → "Title / Lorem ipsum" in Best Sellers and PHONE_PLACEHOLDER on contact read as a half-built site; trust drops.
- **Casey (mobile):** hero full-bleed, 16px search input (no zoom), whole-card taps, thumb-reachable form — all good. Risk: 500KB catalog JSON on 3G with no skeleton.
- **Riley (stress-tester):** search empty state, long-title wrap, fetch-failure all handled. Gap: bad ?p= slug silently falls back to the first product.

## Minor Observations
- Catalog placeholder watermark is monotonous (mitigated once photos land).
- Detail page underuses the rich JSON (sizes, article codes, standards available but not shown).

## Questions to Consider
- What would the home page feel like if Best Sellers carried one real, confident product story instead of lorem?
- Should the detail page surface article codes / standards to signal technical credibility to procurement buyers?
