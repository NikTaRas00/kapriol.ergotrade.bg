"""Extract Technology / Performance spec codes from the product data-sheet PDFs.

USAGE (run from the project root, needs `pip install pymupdf`):
    python deploy/extract-sheet-specs.py

INPUTS
    datasheets-by-slug/<slug>.pdf        one data sheet per product
    deploy/live-snapshot/attributes.json the live master code list
    deploy/live-snapshot/products.json   the live catalog (download it first)

OUTPUTS
    deploy/live-snapshot/products.specs-merged.json   <- upload over the host file
    deploy/_sheet-specs.json                          intermediate, per-slug codes
    plus a console report of any label with no matching code in attributes.json

SCOPE: footwear + footwear-accessories only. Only `performance` and
`technologies` are written; `safetyClass` and every other field are left alone.

WHY IT IS FIDDLY: the captions sit in two columns under each heading, wrap over
several lines, and can spill onto the next page (which carries no repeated
heading). Side-by-side captions share a baseline, so PyMuPDF merges them into one
block — hence the word-level extraction and the column/line clustering below.



Each sheet has a right-hand column with a "Technology" heading and a
"Performance" heading; under each sit icon captions laid out in 2 columns.
Captions wrap across several text blocks, so we group blocks spatially:
blocks whose x-ranges overlap and whose vertical gap is small belong to the
same caption.
"""
import fitz, json, os, re, sys, unicodedata

sys.stdout.reconfigure(encoding="utf-8", errors="replace")

SHEETS = "datasheets-by-slug"


def norm(s):
    """Aggressive normalisation for comparing labels: lowercase alphanumerics."""
    s = unicodedata.normalize("NFKD", str(s))
    s = s.replace("–", "-").replace("—", "-").replace("’", "'")
    return re.sub(r"[^a-z0-9]+", "", s.lower())


def blocks(page):
    out = []
    for x0, y0, x1, y1, txt, *_ in page.get_text("blocks"):
        t = " ".join(txt.split())
        if t:
            out.append({"x0": x0, "y0": y0, "x1": x1, "y1": y1, "t": t})
    return out


# Measured on the real sheets: words inside one caption sit 1.9pt apart, while
# the gap between two side-by-side icon captions is never below 9.7pt.
WORD_GAP = 6.0
# Wrapped lines of one caption are ~4pt apart; separate captions are ~45pt+.
LINE_GAP = 8.0


def line_segments(words):
    """Words -> caption fragments, splitting each baseline at column gaps."""
    lines, cur = [], []
    for w in sorted(words, key=lambda w: (round(w["y0"], 1), w["x0"])):
        if cur and abs(w["y0"] - cur[0]["y0"]) > 3:
            lines.append(cur)
            cur = []
        cur.append(w)
    if cur:
        lines.append(cur)

    segs = []
    for ln in lines:
        ln = sorted(ln, key=lambda w: w["x0"])
        run = [ln[0]]
        for w in ln[1:]:
            if w["x0"] - run[-1]["x1"] > WORD_GAP:      # new column on this line
                segs.append(run)
                run = []
            run.append(w)
        segs.append(run)

    return [{
        "x0": min(w["x0"] for w in s), "x1": max(w["x1"] for w in s),
        "y0": min(w["y0"] for w in s), "y1": max(w["y1"] for w in s),
        "t": " ".join(w["t"] for w in s),
    } for s in segs if s]


def group_items(words):
    """Cluster caption fragments into whole captions."""
    segs = sorted(line_segments(words), key=lambda s: (round(s["y0"], 1), s["x0"]))
    items, used = [], [False] * len(segs)
    for i, s in enumerate(segs):
        if used[i]:
            continue
        cur, used[i] = [s], True
        changed = True
        while changed:
            changed = False
            last = cur[-1]
            for j, o in enumerate(segs):
                if used[j]:
                    continue
                overlap = min(last["x1"], o["x1"]) - max(last["x0"], o["x0"])
                width = min(last["x1"] - last["x0"], o["x1"] - o["x0"])
                if overlap > 0.4 * max(width, 1) and -3 <= o["y0"] - last["y1"] <= LINE_GAP:
                    cur.append(o)
                    used[j] = True
                    changed = True
                    break
        items.append(" ".join(c["t"] for c in cur))
    return items


def footer_top(page, words):
    """Y of the running footer ('… Find out more: www.kapriol.com').

    Needed as the bottom bound of the last section on a page: a fixed margin
    cut off captions sitting just above the footer — that is what lost
    dune-stone's CI and makalu's SR.
    """
    H = page.rect.height
    cands = [w["y0"] for w in words
             if w["y0"] > H * 0.85
             and (norm(w["t"]) in ("find", "out", "more") or "kapriol" in norm(w["t"]))]
    return min(cands) if cands else H - 22


def sheet_specs(path):
    """Return {'technologies': [...labels...], 'performance': [...]}."""
    doc = fitz.open(path)
    res = {"technologies": [], "performance": []}
    # Captions can overflow onto the following page, which carries NO repeated
    # heading. Remember the last open section + its column so those
    # continuation captions are still collected (dune-jaq's ESD, makalu's LG).
    carry = None
    for page in doc:
        # Headings are bold 9pt; caption text is 8pt. Matching on text alone
        # misfires, because "NHP Ultra Rebound Technology" wraps so that its
        # last line is the bare word "Technology" — which was hijacking the
        # heading and collapsing the section to nothing. Take the topmost only.
        heads = {}
        for b in page.get_text("dict")["blocks"]:
            for l in b.get("lines", []):
                for sp in l["spans"]:
                    k = norm(sp["text"])
                    key = ("technologies" if k == "technology"
                           else "performance" if k == "performance" else None)
                    if not key or sp["size"] < 8.5:
                        continue
                    x0, y0, x1, y1 = sp["bbox"]
                    if key not in heads or y0 < heads[key]["y0"]:
                        heads[key] = {"x0": x0, "y0": y0, "x1": x1, "y1": y1}
        words = [
            {"x0": w[0], "y0": w[1], "x1": w[2], "y1": w[3], "t": w[4]}
            for w in page.get_text("words")
        ]
        fy = footer_top(page, words)
        # 'Table of article variants' marks the end of the icon area on a
        # continuation page.
        tbl = [w["y0"] for w in words if norm(w["t"]) == "table"]
        tbl = min(tbl) if tbl else None

        if not heads:
            # Continuation page: collect the leftover captions of the section
            # that was still open at the end of the previous page.
            if carry:
                top = page.rect.height * 0.06     # skip the running header
                bot = min([v for v in (tbl, fy) if v is not None] or [fy])
                sel = [w for w in words
                       if w["y0"] >= top and w["y1"] <= bot - 2
                       and w["x0"] >= carry["x0"] - 10]
                res[carry["key"]].extend(group_items(sel))
                carry = None
            continue

        # a section runs from its heading down to the next heading below it,
        # or to the page footer
        lowest = max(heads, key=lambda k: heads[k]["y0"])
        for key, hb in heads.items():
            lo = hb["y1"]
            his = [o["y0"] for o in heads.values() if o["y0"] > hb["y0"] + 5]
            hi = min(his) if his else fy
            sel = [
                w for w in words
                if w["y0"] >= lo + 2 and w["y1"] <= hi - 2
                # must sit inside the right-hand icon column: body paragraphs
                # begin far left and would otherwise be read as captions
                and w["x0"] >= hb["x0"] - 10
                # NB no text-based filtering here: real captions legitimately
                # contain "Technology" (NHP Ultra Rebound Technology) and
                # "Kapriol" (Kapriol Recycled Fabric). Geometry alone excludes
                # the headings and the page footer.
            ]
            res[key].extend(group_items(sel))
        # the bottom-most section on this page may spill onto the next one
        carry = {"key": lowest, "x0": heads[lowest]["x0"]}
    doc.close()
    return res


FOOTWEAR = ("footwear", "footwear-accessories")


def main():
    attrs = json.load(open("deploy/live-snapshot/attributes.json", encoding="utf-8"))

    # lookups per group: normalised title -> code, normalised code -> code
    look = {}
    for grp in ("performance", "technologies"):
        m = {}
        for e in attrs.get(grp, []):
            code = e.get("code", "")
            if not code:
                continue
            m[norm(code)] = code
            if e.get("title"):
                m[norm(e["title"])] = code
                # also index the part after a leading "CODE - " prefix
                mm = re.match(r"^\s*[A-Za-z0-9 ]{1,14}\s*[-–]\s*(.+)$", e["title"])
                if mm:
                    m[norm(mm.group(1))] = code
        look[grp] = m

    products = json.load(open("deploy/live-snapshot/products.json", encoding="utf-8"))
    by_slug = {p["slug"]: p for p in products}

    resolved, unknown = {}, {}
    no_section = []

    for fn in sorted(os.listdir(SHEETS)):
        if not fn.lower().endswith(".pdf"):
            continue
        slug = fn[:-4]
        if slug not in by_slug:
            continue
        # SHOES ONLY. Other categories use a completely different spec
        # vocabulary (chemical protection types, SNR dB, UPF ratings…) that
        # attributes.json does not carry, so they are out of scope.
        if by_slug[slug].get("category") not in FOOTWEAR:
            continue
        specs = sheet_specs(os.path.join(SHEETS, fn))
        if not specs["technologies"] and not specs["performance"]:
            no_section.append(slug)
            continue
        out = {}
        for grp in ("performance", "technologies"):
            codes, seen = [], set()
            for label in specs[grp]:
                key = norm(label)
                code = look[grp].get(key)
                if not code:
                    # try the "CODE - description" prefix from the sheet label
                    mm = re.match(r"^\s*([A-Za-z0-9 ]{1,14})\s*[-–]\s*(.+)$", label)
                    if mm:
                        code = look[grp].get(norm(mm.group(1))) or look[grp].get(norm(mm.group(2)))
                if code:
                    if code not in seen:
                        seen.add(code)
                        codes.append(code)
                elif re.fullmatch(r"[a-z]+", label.strip()):
                    # Tail of a caption that wrapped across a page break
                    # ("insulation", "resistance", "discharge"). Its parent
                    # caption on the previous page already resolved the code,
                    # so this is a fragment, not a missing definition.
                    pass
                else:
                    unknown.setdefault(grp, {}).setdefault(label, []).append(slug)
            out[grp] = codes
        resolved[slug] = out

    json.dump(resolved, open("deploy/_sheet-specs.json", "w", encoding="utf-8"),
              indent=2, ensure_ascii=False)

    total_shoes = sum(1 for p in products if p.get("category") in FOOTWEAR)
    wp = sum(1 for v in resolved.values() if v["performance"])
    wt = sum(1 for v in resolved.values() if v["technologies"])
    print(f"SHOES ONLY — {total_shoes} footwear + accessory products in the catalog")
    print(f"  sheets with a Technology/Performance section: {len(resolved)}")
    print(f"  sheets with no such section (left untouched): {len(no_section)}")
    print(f"  -> performance codes found on: {wp}")
    print(f"  -> technology codes found on:  {wt}")

    print("\n" + "=" * 72)
    print("MISSING FROM attributes.json — in the PDFs but not in your code lists")
    print("=" * 72)
    for grp in ("performance", "technologies"):
        u = unknown.get(grp, {})
        if not u:
            print(f"\n[{grp}] nothing missing — every label matched")
            continue
        print(f"\n[{grp}] {len(u)} distinct label(s):")
        for label, slugs in sorted(u.items(), key=lambda kv: -len(kv[1])):
            uniq = sorted(set(slugs))
            ex = ", ".join(uniq[:4])
            more = "" if len(uniq) <= 4 else f" +{len(uniq)-4} more"
            print(f"  on {len(uniq):3d} shoe(s)   {label}")
            print(f"                 e.g. {ex}{more}")

    # ---- write the merged products.json --------------------------------------
    changed, per_shoe = 0, []
    for p in products:
        r = resolved.get(p["slug"])
        if not r or p.get("category") not in FOOTWEAR:
            continue
        if r["performance"] or r["technologies"]:
            p["performance"] = r["performance"]
            p["technologies"] = r["technologies"]
            changed += 1
            per_shoe.append((p["slug"], r["performance"], r["technologies"]))
    out = "deploy/live-snapshot/products.specs-merged.json"
    json.dump(products, open(out, "w", encoding="utf-8"), indent=2, ensure_ascii=False)

    print("\n" + "=" * 72)
    print("WHAT WOULD BE WRITTEN (per shoe)")
    print("=" * 72)
    for slug, perf, tech in per_shoe:
        print(f"  {slug:26s} perf={','.join(perf) or '-':22s} tech={','.join(tech) or '-'}")
    print(f"\nWrote {out}")
    print(f"  shoes updated: {changed}   (safetyClass NOT touched)")


if __name__ == "__main__":
    main()
