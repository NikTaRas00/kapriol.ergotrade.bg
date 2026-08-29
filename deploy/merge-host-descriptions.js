/* One-shot merge of the new product descriptions into the LIVE host products.json.
 *
 * Why this exists: the host assets/data/products.json is admin-edited and DIVERGES
 * from the local copy (spec codes, images, etc.), and the FTP deploy deliberately
 * never uploads assets/data. So we cannot push the local file. Instead:
 *   1. Download the live host file (cPanel File Manager / FTP) to a local path.
 *   2. Run this script — it adds/updates ONLY the `fullDescription` field, matched
 *      by slug, leaving every other field on the host file untouched.
 *   3. Upload the produced *.merged.json back over the host products.json.
 *
 * Usage:
 *   node deploy/merge-host-descriptions.js <path-to-host-products.json> [output-path]
 *
 * Defaults output to "<input>.merged.json" next to the input.
 * The merge payload is deploy/descriptions.json (regenerate with
 * `node deploy/resolve-descriptions.js` if matches change).
 */
const fs = require("fs");
const path = require("path");

const inPath = process.argv[2];
if (!inPath) {
  console.error("Usage: node deploy/merge-host-descriptions.js <host-products.json> [out.json]");
  process.exit(1);
}
const outPath = process.argv[3] ||
  inPath.replace(/\.json$/i, "") + ".merged.json";

const payload = JSON.parse(fs.readFileSync(path.join(__dirname, "descriptions.json"), "utf8"));
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
  if (!("fullDescription" in p)) { p.fullDescription = d.html; added++; }
  else if (p.fullDescription !== d.html) { p.fullDescription = d.html; changed++; }
  else unchanged++;
}

// slugs in the payload that the host doesn't have (host renamed/removed → review)
const missingOnHost = Object.keys(payload).filter(s => !hostSlugs.has(s));

fs.writeFileSync(outPath, JSON.stringify(host, null, indent) + "\n");

console.log("Host products:        ", host.length);
console.log("Descriptions in payload:", Object.keys(payload).length);
console.log("  added fullDescription:", added);
console.log("  updated (was different):", changed);
console.log("  already up to date:   ", unchanged);
console.log("  host products untouched:", host.length - added - changed - unchanged);
if (missingOnHost.length) {
  console.log("\nWARNING — payload slugs NOT found on host (skipped, review these):");
  console.log("  " + missingOnHost.join("\n  "));
}
console.log("\nWrote:", outPath);
console.log("Next: upload this file over the host assets/data/products.json.");
