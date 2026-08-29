# Product

## Register

brand

## Users

Bulgarian trade and end buyers of workwear, safety footwear, and PPE — site managers, procurement leads at construction/industrial firms, and individual tradespeople. They arrive wanting to gauge whether Kapriol is a serious, durable brand and to find the local (Bulgarian) distributor, ergotrade.bg. Context is often mobile, on-site, low patience: they judge credibility in seconds and want a fast path to the catalog and to contact.

## Product Purpose

A premium landing site for **kapriol.bg** — Kapriol workwear/footwear/PPE, distributed exclusively in Bulgaria by ergotrade.bg. It exists to establish brand credibility (not e-commerce: there is no cart) and route visitors to the catalog, product detail, and the contact form. Success = a visitor trusts the brand, browses best sellers/products, and reaches out via the contact form.

## Brand Personality

Rugged, precise, confident. The voice is industrial and unfussy — tools and gear that survive real job sites. Emotionally it should evoke **trust through toughness**: dark, engineered, premium-industrial, with brand red used as a deliberate signal, never decoration. Think product-engineering confidence, not lifestyle softness.

## Anti-references

- Generic dropship/marketplace catalog pages (cluttered grids, badges, star ratings, urgency banners).
- Soft "lifestyle" workwear branding (pastel, airy, fashion-editorial) — wrong register for PPE.
- SaaS landing clichés: gradient text, glassmorphism, hero-metric template, tiny tracked eyebrows above every section, identical card grids.
- Anything that reads "AI-templated." The dark premium-industrial look must feel engineered, not defaulted.

## Design Principles

- **Engineered, not decorated.** Every accent (red, hairline, motion) earns its place; restraint signals quality in this category.
- **Credibility in the first fold.** The hero must convince a skeptical tradesperson the brand is serious before they scroll.
- **Show the product as object.** Best sellers and detail pages treat gear as engineered objects (drop shadow, contain-fit, deliberate lighting), not lifestyle scenes.
- **Fast and resilient.** Plain static HTML/CSS/JS, no build; content stays visible without JS; motion degrades to crossfade/instant under reduced-motion.
- **One path to contact.** Don't transact — communicate, then route to the distributor.

## Accessibility & Inclusion

WCAG 2.1 AA. Brand red (`#e8000d`) fails AA as text on dark, so red text uses `--red-ink #ff2b30`. Honor `prefers-reduced-motion` (hero scrub, reveals, best-sellers scrub all degrade). Visible `:focus-visible` indicators, skip link, semantic HTML, 44px touch targets on mobile nav/toggle. Often used outdoors on mobile in bright light — contrast must hold up.
