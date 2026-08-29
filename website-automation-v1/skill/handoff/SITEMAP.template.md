# Site Map — <PROJECT / BRAND NAME>

> Fill from `.website-automation/state.md` (Phase 2 output). Delete the guidance
> lines before handing this to Claude Design.

## Pages
Each row is either its **own page** (gets its own HTML file) or a **section**
within another page.

| Page / Section | Route | Own page? | In main nav? | Purpose |
|----------------|-------|-----------|--------------|---------|
| Home | `/` | Yes | Yes | … |
| <List/Catalogue> | `/products` | Yes | Yes | Browse all <items>; filter by category / search |
| <Detail> | `/product?p=<slug>` | Yes (one template) | No | One <item>, data-driven |
| Contact | `/contact` | Yes | Yes | Enquiry form |
| <About> | `/#about` | No (section of Home) | Yes (anchor) | … |
| Admin | `/admin` | Yes (back office) | No | Content editor (not designed by Claude Design) |

## Global elements
- **Header / nav:** <logo position, links, mobile behaviour>
- **Footer:** <columns, legal, "powered by" line>
- **Breadcrumbs:** <where they appear, e.g. list + detail pages>

## Data-driven entity
- **Entity name:** <products / services / listings / …>
- **Roughly how many:** <count>
- **Grouped into categories?** <yes: list the fixed category ids + labels / no>
- **Fields per item:** <slug, name, description, images, price-less specs, …>
- **What a detail page shows:** <hero image + gallery, description, spec table,
  related items, CTA to contact, …>
- **List page behaviour:** <all items / per-category `?cat=` pages / search `?q=`>

## Special pages / features
- Admin area: <which collections are editable, by whom, how often>
- Contact form: <fields, recipient>
- Search: <yes/no, scope>
- Analytics: <yes/no>
- Languages: <single / multi — list them>

## Notes for the designer
- Which nodes must feel like distinct destinations vs. one scroll.
- Any page with unusual layout needs (hero video, full-bleed gallery, etc.).
