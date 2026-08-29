# Shoe spec codes — how to finish and publish

Two parts: **A)** add the missing codes in the Admin, **B)** re-run the merge and upload.

Nothing is live yet. The prepared file is `deploy/live-snapshot/products.specs-merged.json`.

---

## Part A — Add the missing codes (Admin → Codes)

Go to **Admin → Codes**. Add each row below, then press **Save**.

### The one rule that matters

The matching is done on the **Title**, so **type the Title exactly as written below**.
The Code can be whatever you like (it's what shows on the product page) — but if the Title
doesn't match, that code won't get picked up and those shoes stay empty.

### Technologies

| Code (suggested) | Title — type exactly | Shoes |
|---|---|---|
| `STEEL` | `Hard safety toe cap` | 25 |
| `INOX` | `Inox perforation proof midsole` | 11 |
| `PU` | `PU sole` | 5 |
| `PVC` | `PVC` | 3 |
| `PU RUBBER` | `PU-Rubber sole` | 1 |
| `ALU` | `ALU` | 1 |

These mirror codes you already have: you have `CF` / `COMP` toe caps but not the **hard (steel)**
one, and `HIGH FLEX` midsole but not the **Inox (steel)** one. That's why they're the two biggest gaps.

### Performance

| Code (suggested) | Title — type exactly | Shoes |
|---|---|---|
| `WRU` | `WRU - Water-resistant upper` | 21 |
| `PL` | `PL - Perforation-resistant shoe (4.5 mm nail)` | 4 |
| `NON SLIP` | `Non-slip sole` | 4 |
| `LIGHT` | `Light` | 2 |
| `P` | `P - Perforation-resistant shoe` | 1 |
| `METAL FREE` | `Metal Free` | 1 |
| `FLEX` | `Flex` | 1 |

`WRU` is the big one (21 shoes). You already have `WR` (waterproof shoe) — `WRU` is a *different,
weaker* rating (water-resistant upper), so it was deliberately not substituted.

### Optional — skip these unless you want them

These appear only on insoles, laces and the spray, not on actual shoes:
`Comfort` (4 insoles), `Breathable` (2 insoles), `Water repellent` (duo-spray),
`Wear Resistance` (shoelaces).

### Also worth fixing while you're in there

The **Safety class** list currently holds codes that aren't safety classes — `CI, ESD, FO, HI,
HRO, LG, SC, SR, SRC, WR`. They leaked in from an old import. Safety classes are only the
`S…`/`O…` ones (`O1, O2, O6, OB, S1, S1P, S1PL, S3, S3L, S3S, S5, S7S`). Deleting the rest keeps
the product page and the catalog filters clean.

---

## Part B — Merge and upload

### B1. Download the two live files
In cPanel **File Manager** → `assets/data/`, download:
- `attributes.json`  ← needed because you just changed it in Part A
- `products.json`

Drop both in `Share Folder/` (or tell Claude where they are).

### B2. Re-run the merge
Ask Claude to re-run the spec-code merge. It will:
- read your updated code list,
- re-read all 46 shoe data sheets,
- write `products.specs-merged.json`,
- report anything still unmatched.

### B3. Upload
In cPanel **File Manager** → `assets/data/`:
1. Rename the live `products.json` → `products-backup.json` (your undo).
2. Upload `products.specs-merged.json`.
3. Rename it to `products.json`.

### B4. Check
Open a shoe, e.g. `https://kapriol.ergotrade.bg/product?p=makalu` — the Performance and
Technologies pictograms should appear. Hard-refresh (Ctrl+F5) if not.

---

## Important

- **Don't edit products in the Admin between B2 and B3.** The upload replaces the whole
  products file, so any admin edit made in that window would be overwritten. Do the upload
  right after the merge.
- **Safety class is never touched** by this merge — only `performance` and `technologies`.
- `dune-jaq-pink` exists locally but **not** in your live data, so it can't be updated.
- `rigger`'s own data sheet contradicts itself (title says `S5 CI FO SR` but there's no CI
  pictogram). CI was left off rather than guessed — add it by hand if you want it.
