/* Match the WORKWEAR WebP photo drop to product slugs.
 *
 * Source drop:  full-range-25-26-v1/KAPRIOL_FULL_RANGE_25-26_WW/WORKWEAR/WEBP ALL/
 * Filenames are prefixed with the 5-digit article code(s) of the product, e.g.
 *   35480_85_DYNAMIC CARPENTER_GREY_FRONT_result.webp   -> codes 35480..35485
 *   28180-181_RAIN YELLOW_JACKET_result.webp            -> codes 28180..28181
 *   36275_36290_94_Industry Pants_Grey_Front_result.webp-> 36275 + 36290..36294
 * Every product in products.json carries an exhaustive `articleCodes[]` (one per
 * size/colour), so a code -> slug index resolves nearly everything.
 *
 * Usage:  node deploy/match-workwear-images.js [--write]
 *   (no flag) = dry run, prints the report only
 *   --write   = also copy/rename the files into _RENAMED_WORKWEAR/ and write
 *               _img-map.json + _mapping.csv + _unmatched.md
 *
 * Then:  node deploy/merge-host-images.js <host-products.json> \
 *          --payload=full-range-25-26-v1/_RENAMED_WORKWEAR/_img-map.json
 */
const fs = require("fs");
const path = require("path");

const ROOT = path.join(__dirname, "..");
const SRC = path.join(ROOT, "full-range-25-26-v1", "KAPRIOL_FULL_RANGE_25-26_WW", "WORKWEAR", "WEBP ALL");
const OUT = path.join(ROOT, "full-range-25-26-v1", "_RENAMED_WORKWEAR");
const WRITE = process.argv.includes("--write");
const STAMP = new Date().toISOString().slice(0, 10).replace(/-/g, "");

const products = JSON.parse(fs.readFileSync(path.join(ROOT, "assets", "data", "products.json"), "utf8"));

// ---- hand-checked overrides ------------------------------------------------
// The source filenames carry a couple of wrong article codes. Value = slug to
// force, or null to drop the file entirely. Both entries verified by eye.
const OVERRIDES = {
  // filename says 37870-874 (short-sleeve HV tee) but the photo is the LONG-sleeve top
  "37870-874_T-SHIRT_M-LUNGA_HV_FRONTE_result.webp": "hv-tshirt-long-sleeve",
  // sleeveless HV softshell GILET — no such product in the catalog; would be
  // misleading as a gallery shot of hv-softshell (a jacket), so drop it.
  "37820-824_SOFTSHELL_HV_GILET_result.webp": null,
};

// ---- code -> product index -------------------------------------------------
const byCode = new Map();
for (const p of products) {
  for (const c of p.articleCodes || []) {
    const s = String(c.code || c).trim();
    if (!byCode.has(s)) byCode.set(s, p); // first owner wins (3 codes are shared)
  }
}

// ---- filename -> candidate codes -------------------------------------------
// Grab the leading run of numbers joined by - _ or space.
function codesFromName(name) {
  const m = name.match(/^(\d{4,5}(?:\s*[-_]\s*\d{2,5})*)/);
  if (!m) return [];
  const parts = m[1].split(/[-_\s]+/).filter(Boolean);
  const out = [];
  let base = null;
  for (const raw of parts) {
    if (raw.length >= 5) {
      base = raw;
      out.push(raw);
    } else if (base) {
      // short token = end of a range, sharing the base's leading digits
      const end = base.slice(0, base.length - raw.length) + raw;
      const a = parseInt(base, 10), b = parseInt(end, 10);
      if (b > a && b - a < 30) for (let i = a + 1; i <= b; i++) out.push(String(i).padStart(base.length, "0"));
      else out.push(end);
    } else {
      out.push(raw);
    }
  }
  return [...new Set(out)];
}

// ---- role / ordering -------------------------------------------------------
// primary = the plain front shot; details & alternates come after.
function roleRank(name) {
  const n = name.toUpperCase();
  if (/\bDETT|DETAIL|POCKET|_0[2-9]\b/.test(n)) return 3;
  if (/BACK|RETRO/.test(n)) return 2;
  if (/_RESULT_\d/.test(n)) return 1;   // "..._result_1" = alternate render
  if (/FRONT|FRONTE/.test(n)) return 0;
  return 0.5;
}

const files = fs.readdirSync(SRC).filter(f => /\.webp$/i.test(f)).sort();

const hits = new Map();   // slug -> [filenames]
const unmatched = [];
const codeTrace = new Map();

const dropped = [];
for (const f of files) {
  const stem = f.replace(/\.webp$/i, "");
  const codes = codesFromName(stem);
  let product = null, viaCode = null;
  if (Object.prototype.hasOwnProperty.call(OVERRIDES, f)) {
    if (OVERRIDES[f] === null) { dropped.push(f); continue; }
    product = products.find(p => p.slug === OVERRIDES[f]);
    viaCode = "override";
  }
  if (!product) for (const c of codes) {
    if (byCode.has(c)) { product = byCode.get(c); viaCode = c; break; }
  }
  if (!product) { unmatched.push({ file: f, codes }); continue; }
  if (!hits.has(product.slug)) hits.set(product.slug, []);
  hits.get(product.slug).push(f);
  codeTrace.set(f, viaCode);
}

// order each product's files, assign target names
const map = {};
const csv = [["slug", "product", "matched_code", "source_file", "renamed_to", "role"].join(",")];
for (const [slug, list] of [...hits.entries()].sort()) {
  list.sort((a, b) => roleRank(a) - roleRank(b) || a.localeCompare(b));
  const p = products.find(x => x.slug === slug);
  const images = list.map((f, i) => `assets/img/products/${slug}${i ? "-" + (i + 1) : ""}.webp`);
  map[slug] = { img: images[0], images, imgV: STAMP };
  list.forEach((f, i) => {
    csv.push([slug, p.name, codeTrace.get(f), f, path.basename(images[i]), i === 0 ? "PRIMARY" : "EXTRA-" + (i + 1)]
      .map(v => `"${String(v).replace(/"/g, '""')}"`).join(","));
  });
}

// ---- report ----------------------------------------------------------------
const catCount = {};
for (const slug of hits.keys()) {
  const c = products.find(x => x.slug === slug).category;
  catCount[c] = (catCount[c] || 0) + 1;
}
console.log("Source files:        ", files.length);
console.log("Matched files:       ", files.length - unmatched.length - dropped.length);
console.log("Dropped (override):  ", dropped.length, dropped.join(", "));
console.log("Products covered:    ", hits.size, "(of 334)");
console.log("  by category:       ", JSON.stringify(catCount));
console.log("  with >1 photo:     ", [...hits.values()].filter(v => v.length > 1).length);
console.log("Unmatched files:     ", unmatched.length);
for (const u of unmatched) console.log("   ✗", u.file, "  codes:", u.codes.join(",") || "(none)");

if (WRITE) {
  fs.mkdirSync(OUT, { recursive: true });
  // The source renders are ~3000px / ~1.7 MB each. Downscale to the same shape
  // the admin uploader produces (longest side <=1600, WebP q82) — a direct FTP
  // upload bypasses kap_save_upload_image(), so do it here. --no-optimize copies raw.
  const FFMPEG = "C:\\ffmpeg\\bin\\ffmpeg.exe";
  const optimize = !process.argv.includes("--no-optimize") && fs.existsSync(FFMPEG);
  if (!optimize) console.log("(!) copying raw — ffmpeg not found or --no-optimize given");
  const { execFileSync } = require("child_process");
  for (const [slug, list] of hits.entries()) {
    list.forEach((f, i) => {
      const src = path.join(SRC, f);
      const dst = path.join(OUT, `${slug}${i ? "-" + (i + 1) : ""}.webp`);
      if (!optimize) return fs.copyFileSync(src, dst);
      execFileSync(FFMPEG, ["-y", "-loglevel", "error", "-i", src, "-vf",
        "scale='if(gt(iw,ih),min(1600,iw),-2)':'if(gt(iw,ih),-2,min(1600,ih))'",
        "-c:v", "libwebp", "-quality", "82", dst]);
    });
  }
  fs.writeFileSync(path.join(OUT, "_img-map.json"), JSON.stringify(map, null, 2) + "\n");
  fs.writeFileSync(path.join(OUT, "_mapping.csv"), csv.join("\n") + "\n");
  // contact sheet — open _review.html in a browser to eyeball every match
  const cards = [...hits.entries()].sort().map(([slug, list]) => {
    const p = products.find(x => x.slug === slug);
    const thumbs = list.map((f, i) =>
      `<figure><img src="${slug}${i ? "-" + (i + 1) : ""}.webp" loading="lazy"><figcaption>${i ? "extra " + (i + 1) : "MAIN"}<br><span>${f}</span></figcaption></figure>`).join("");
    return `<section><h2>${p.name} <code>${slug}</code> <em>${p.category}</em></h2><div class="row">${thumbs}</div></section>`;
  }).join("\n");
  fs.writeFileSync(path.join(OUT, "_review.html"),
    `<!doctype html><meta charset="utf-8"><title>WORKWEAR photo match review</title>
<style>body{font:14px/1.5 system-ui;margin:24px;background:#f4f5f6;color:#15171a}
h1{margin:0 0 4px}p.lead{color:#54585e;margin:0 0 24px}
section{background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:10px;padding:14px;margin:0 0 14px}
h2{font-size:15px;margin:0 0 10px;font-weight:600}
h2 code{background:#ebedef;padding:1px 6px;border-radius:4px;font-size:12px}
h2 em{color:#6a6f76;font-style:normal;font-size:12px}
.row{display:flex;gap:12px;flex-wrap:wrap}
figure{margin:0;width:150px}img{width:150px;height:150px;object-fit:contain;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:6px}
figcaption{font-size:10px;color:#6a6f76;margin-top:4px;word-break:break-all}figcaption span{color:#9aa0a6}</style>
<h1>WORKWEAR photo match — ${hits.size} products, ${files.length - unmatched.length - dropped.length} photos</h1>
<p class="lead">First tile of each row becomes the product's main image. Generated ${new Date().toISOString().slice(0, 10)}.</p>
${cards}`);

  fs.writeFileSync(path.join(OUT, "_unmatched.md"),
    "# Unmatched WORKWEAR photos\n\n" + (unmatched.length
      ? unmatched.map(u => `- \`${u.file}\` — codes tried: ${u.codes.join(", ") || "(none parsed)"}`).join("\n")
      : "None.") + "\n");
  console.log("\nWrote", OUT);
}
