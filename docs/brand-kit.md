# Kapriol Bulgaria — Brand Kit

A single reference for the brand's identity, colours and voice. Use the
**"Icon generation prompt"** at the bottom to generate a website icon (favicon)
with an AI image tool (Gemini, Midjourney, DALL·E, etc.).

---

## 1. Who / what

- **Brand:** Kapriol — professional **workwear, safety footwear and PPE** (personal
  protective equipment) for the trades.
- **This site:** the official Bulgarian presence, distributed exclusively in
  Bulgaria by **ergotrade.bg**. It's a credibility landing site + catalog, not a
  shop.
- **Audience:** skeptical, practical tradespeople — builders, electricians,
  installers. They read a bright, legible, no-nonsense surface as *credible*.
- **One-line positioning:** "Built for work. Made by Kapriol."

## 2. Personality / aesthetic

Keywords to feed a design tool:

> **premium industrial · concrete-light · engineered · precise · utilitarian ·
> confident · high-contrast · minimal · trade-grade · datasheet**

The look is a **light "concrete / premium industrial"** aesthetic — a cool
neutral off-white surface, near-black ink, and a single decisive **brand-red**
accent. Not playful, not luxury-soft — think spec sheets, tools, and safety
markings, cleaned up and made premium.

## 3. Colour palette

The red is the signature. Everything else is a cool grey neutral scale.

| Role | Name | HEX | Notes |
|------|------|-----|-------|
| **Brand red (primary accent)** | Red | `#E8000D` | Fills, CTAs, the icon's hero colour |
| Red (pressed / darker) | Red-press | `#B3000B` | Hover/active darken |
| Red (as text on light) | Red-ink | `#C10010` | Darkened so red text passes contrast |
| Page background | BG | `#F4F5F6` | Cool concrete off-white |
| Section background | BG-alt | `#EBEDEF` | One step deeper |
| Surface (cards) | Surface | `#FFFFFF` | Clean white |
| Primary text | Ink | `#15171A` | Near-black |
| Secondary text | Ink-dim | `#54585E` | |
| Tertiary / labels | Ink-mute | `#6A6F76` | |

**Icon palette recommendation:** brand red `#E8000D` as the hero, on either pure
white `#FFFFFF` or near-black `#15171A`. Keep it to **two colours max** so it
reads at 16×16 px.

## 4. Typography (for reference / lockups)

- **Primary typeface:** **Archivo** — an engineered grotesque sans. Bold weights
  carry all headings and the `KAPRIOL` wordmark.
- **Accent typeface:** **Spline Sans Mono** — used only for small uppercase
  labels, spec codes and "datasheet" details.
- **Wordmark:** `KAPRIOL` set in Archivo, heavy weight (≈800), tight tracking,
  all-caps.

## 5. Existing visual cues on the site

- A recurring **48–64 px × 3 px solid red rule** above/below section titles.
- Rounded corners are restrained: 6 px on buttons, 12 px on cards.
- Everything is flat — no gradients, no drop shadows, no gloss.

---

## 6. Icon (favicon) brief

**Goal:** a distinctive site icon that shows in the browser tab, bookmarks and
phone home-screen — legible from **512 px down to 16 px**.

**Constraints (important):**
- Flat, geometric, **2 colours maximum** (brand red + white or near-black).
- Must stay recognisable and crisp at 16×16 px → **no fine detail, no text**
  (a single letterform is OK, a full word is not).
- Square canvas, centered, with a little breathing room (safe margin) so it also
  works as a rounded/maskable icon.
- Matches the "premium industrial" voice — confident and engineered, not cute.

**Concept directions (pick one — or ask the tool for options):**
1. **`K` monogram** — a bold, geometric capital K in brand red on white (or
   reversed: white K on a red rounded square). Simplest, most scalable.
2. **Red safety badge** — a solid red rounded square as the field, with a white
   `K` or an abstract mark cut out of it (echoes safety/PPE markings).
3. **Abstract industrial glyph** — a minimal mark suggesting protection /
   structure (e.g. a shield, a chevron, or interlocking angles) in red.

**Deliverables to ask for:**
- A master **SVG** (vector, ideal), plus PNGs at **512×512, 180×180
  (Apple touch), 32×32 and 16×16**.
- A **maskable** version with ~10% safe padding for Android/adaptive icons.
- Transparent background for the SVG/PNGs where possible.

---

## 7. ✂️ Paste-ready prompt for an AI image tool

> Design a **website favicon / app icon** for **"Kapriol"**, a premium
> professional **workwear, safety-footwear and PPE** brand (Bulgarian
> distributor: ergotrade.bg).
>
> **Style:** flat, geometric, minimal, "premium industrial" — think tools,
> spec sheets and safety markings, cleaned up and made premium. Confident and
> engineered, not cute or ornate. No gradients, no shadows, no 3D, no gloss.
>
> **Colours:** use the brand red **#E8000D** as the hero, paired with either
> pure white **#FFFFFF** or near-black **#15171A**. Two colours maximum.
>
> **Concept:** a bold, geometric **"K" monogram** — either a red K on white, or
> a white K knocked out of a solid red rounded square (like a safety badge).
>
> **Format:** perfectly square, centered, with a small safe margin around the
> mark. It must stay crisp and recognisable when scaled down to **16×16
> pixels**, so keep it extremely simple with no fine detail and no text beyond
> the single letter. Provide it as a clean vector-style icon on a transparent
> (or solid red / solid white) background.
>
> Give me a few distinct variations to choose from.

---

*Local file — not part of the deployed site. Once you have the icon, tell Claude
and it will wire the favicon `<link>` tags into the HTML pages.*
