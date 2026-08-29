# PRODUCT.md

register: brand

## What this is
A credibility / marketing site for **Kapriol** workwear, footwear and PPE, distributed
exclusively in Bulgaria by **Ergotrade** (ergotrade.bg). Not e-commerce: no cart, no
prices. The job is to convince a skeptical tradesperson (or a shop owner deciding what
to stock) that the brand and the distributor are serious, then route them to the
catalogue, a product page, or the contact form.

Live at the `kapriol.ergotrade.bg` subdomain root. The site at the root is the **2026
redesign** (cream / near-black / safety-orange, Barlow Condensed + Inter). The previous
design is kept as a frozen, noindexed archive at `old/` and is not a running site.

## Who it is for
- **Tradespeople and site foremen.** Read on a phone, outdoors, in a hurry. They care
  about certification markings, sizes and whether the thing is actually in stock.
- **Retailers / purchasing managers.** Evaluating whether to stock the range. They care
  about supply reliability and support.
- **Ergotrade staff** use the authenticated admin to manage products, photos, spec
  codes, enquiries and site copy.

## Stack
Plain static HTML + CSS + vanilla JS. **No framework, no build step, no npm.** PHP only
for the contact form (`send-mail.php`), the analytics beacon (`track.php`), the dynamic
sitemap and the admin under `admin/`. Runtime data is JSON in `assets/data/`
(`products.json` with 334 products, `content.json`, `attributes.json`), fetched by the
client and cached per visit in sessionStorage.

## Constraints that shape design decisions
- **Mobile-first and legible in daylight.** The audience reads a bright surface as more
  credible than a dark one.
- **Self-hosted fonts only.** No Google Fonts CDN request at runtime (GDPR).
- **Inode-limited cPanel host.** Don't add files casually; one image file per slug.
- **Assets are cached one year immutable.** Every `style.css` / `main.js` edit needs a
  new `?v=N`, including a second edit in the same session.
- **Never overwrite host JSON or images.** They are admin-edited live and diverge from
  the local copies.
- Content reality: **152 of 334 products still have no photograph**, so empty states are
  a first-class design problem, not an edge case.

## Current visual direction

Warm industrial / utilitarian, from a high-fidelity client design handoff in
`docs/new_design_handoff/`.
- Cream `#F7F4EF` surface, near-black `#14110f`, safety orange `#E35D15`,
  hazard yellow `#F4B400`.
- Barlow Condensed (700/800/900, uppercase display) + Inter (body).
- Flat and utilitarian: 1px borders, **no shadows**, a single 2px radius everywhere.
- One recurring brand device: a diagonal **hazard stripe**
  `repeating-linear-gradient(135deg, #14110f 0 8px, #F4B400 8px 16px)`.
- Accessible variants exist because the brand orange is only 3.3:1 on cream:
  `--orange-ink #B4460A` for small orange text, `--orange-cta #C9500F` for solid
  buttons carrying a white label.

The retired design (light "concrete", off-white `#f4f5f6`, brand red `#e8000d`,
Archivo + Spline Sans Mono) is archived at `old/` for reference.
