/* One-shot merge of the footwear product IMAGE paths into the LIVE host products.json.
 *
 * Why this exists: the host assets/data/products.json is admin-edited and DIVERGES
 * from the local copy, and the FTP deploy deliberately never uploads assets/data.
 * So we cannot push the local file. Instead:
 *   1. Download the live host file (cPanel File Manager / FTP) to a local path.
 *   2. Run this script — it sets ONLY the `img` (+ `imgV` cache-buster) fields,
 *      matched by slug, leaving every other field on the host file untouched.
 *   3. Upload the produced *.merged.json back over the host products.json.
 *   4. Upload the renamed .webp files (full-range-25-26-v1/_RENAMED_FOR_UPLOAD/)
 *      into the host assets/img/products/ folder.
 *
 * Usage:
 *   node deploy/merge-host-images.js <path-to-host-products.json> [output-path]
 *
 * Defaults output to "<input>.merged.json" next to the input.
 * The merge payload is full-range-25-26-v1/_RENAMED_FOR_UPLOAD/_img-map.json
 * (regenerate it by re-running the rename step if the photo set changes).
 */
const fs = require("fs");
const path = require("path");

const args = process.argv.slice(2);
const payloadArg = args.find(a => a.startsWith("--payload="));
const positional = args.filter(a => !a.startsWith("--"));
const inPath = positional[0];
if (!inPath) {
  console.error("Usage: node deploy/merge-host-images.js <host-products.json> [out.json] [--payload=<_img-map.json>]");
  process.exit(1);
}
const outPath = positional[1] ||
  inPath.replace(/\.json$/i, "") + ".merged.json";

// default payload = the footwear drop; pass --payload= for another set
// (e.g. full-range-25-26-v1/_RENAMED_WORKWEAR/_img-map.json)
const payloadPath = payloadArg
  ? path.resolve(payloadArg.slice("--payload=".length))
  : path.join(__dirname, "..", "full-range-25-26-v1", "_RENAMED_FOR_UPLOAD", "_img-map.json");
const payload = JSON.parse(fs.readFileSync(payloadPath, "utf8"));
const raw = fs.readFileSync(inPath, "utf8");
const host = JSON.parse(raw);
if (!Array.isArray(host)) { console.error("Host file is not a JSON array of products."); process.exit(1); }

// preserve the host file's indentation style (default 2 spaces)
const indentMatch = raw.match(/\n(\s+)"/);
const indent = indentMatch ? indentMatch[1].length : 2;

const hostSlugs = new Set(host.map(p => p.slug));
let added = 0, changed = 0, unchanged = 0;
for (const p of host) {
  const d = payload[p.slug];
  if (!d) continue;
  const isNew = !p.img;
  const imagesChanged = JSON.stringify(p.images || null) !== JSON.stringify(d.images || null);
  if (p.img !== d.img || p.imgV !== d.imgV || imagesChanged) {
    p.img = d.img;              // primary / hero shot (cards, og:image)
    p.images = d.images;        // full gallery (product page thumbnail strip)
    p.imgV = d.imgV;
    if (isNew) added++; else changed++;
  } else unchanged++;
}

// slugs in the payload that the host doesn't have (host renamed/removed → review)
const missingOnHost = Object.keys(payload).filter(s => !hostSlugs.has(s));

fs.writeFileSync(outPath, JSON.stringify(host, null, indent) + "\n");

console.log("Host products:        ", host.length);
console.log("Image paths in payload:", Object.keys(payload).length);
console.log("  set img (was empty): ", added);
console.log("  updated (was different):", changed);
console.log("  already up to date:   ", unchanged);
console.log("  host products untouched:", host.length - added - changed - unchanged);
if (missingOnHost.length) {
  console.log("\nWARNING — payload slugs NOT found on host (skipped, review these):");
  console.log("  " + missingOnHost.join("\n  "));
}
console.log("\nWrote:", outPath);
console.log("Next: (1) upload this file over the host assets/data/products.json,");
console.log("      (2) upload the .webp files into host assets/img/products/.");
