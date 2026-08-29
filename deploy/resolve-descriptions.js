/* Resolve every product to a cleaned HTML description (or none).
   Decision = explicit override (deploy/_overrides.json) when present,
   else the top-1 fuzzy match if its score >= GATE, else none.
   Outputs:
     deploy/descriptions.json   slug -> { name, html }   (the merge payload)
     deploy/_review.md          human review table for spot-checking */
const fs = require("fs");
const path = require("path");
const L = require("./desc-lib.js");

const GATE = 0.45;
const overrides = JSON.parse(fs.readFileSync(path.join(__dirname, "_overrides.json"), "utf8"));
delete overrides._doc;

const entries = L.parseDescriptions();
const index = L.buildIndex(entries);
const bodyByName = new Map(); // first body wins for duplicate names
for (const e of entries) if (!bodyByName.has(e.name)) bodyByName.set(e.name, e.body);

const products = require(path.join(L.ROOT, "assets", "data", "products.json"));

// sanity: every override target name must exist in the md
const badNames = [];
for (const [slug, name] of Object.entries(overrides)) {
  if (name !== null && !bodyByName.has(name)) badNames.push(slug + " -> '" + name + "'");
}
if (badNames.length) {
  console.error("ERROR: override names not found in md:\n  " + badNames.join("\n  "));
  process.exit(1);
}

const payload = {};      // slug -> {name, html}
const rows = [];         // review rows
let matched = 0, skipped = 0;

for (const p of products) {
  let chosen, source, score = "";
  if (Object.prototype.hasOwnProperty.call(overrides, p.slug)) {
    chosen = overrides[p.slug];
    source = chosen === null ? "override(none)" : "override";
  } else {
    const top = L.topMatches(p, index, 1)[0];
    score = top ? top.s.toFixed(2) : "0";
    if (top && top.s >= GATE) { chosen = top.name; source = "auto"; }
    else { chosen = null; source = "gate(none)"; }
  }

  if (chosen) {
    payload[p.slug] = { name: chosen, html: L.cleanHtml(bodyByName.get(chosen)) };
    matched++;
  } else {
    skipped++;
  }
  rows.push({ cat: p.category, slug: p.slug, name: p.name, chosen: chosen || "—", source, score });
}

fs.writeFileSync(path.join(__dirname, "descriptions.json"), JSON.stringify(payload, null, 1));

// review table grouped by category
const cats = [...new Set(rows.map(r => r.cat))];
let md = "# Product description match — review table\n\n";
md += `Matched **${matched}** / ${products.length} products with a description; **${skipped}** left without one.\n\n`;
md += "`override` = hand-assigned · `auto` = fuzzy top-1 (score shown) · `none` = intentionally left blank.\n\n";
for (const c of cats) {
  md += `\n## ${c}\n\n| Product | → Description used | Source |\n|---|---|---|\n`;
  for (const r of rows.filter(x => x.cat === c)) {
    const src = r.source === "auto" ? `auto ${r.score}` : r.source;
    md += `| ${r.name} | ${r.chosen} | ${src} |\n`;
  }
}
fs.writeFileSync(path.join(__dirname, "_review.md"), md);

console.log("matched:", matched, " skipped:", skipped, " of", products.length);
console.log("\nLeft WITHOUT a description:");
console.log(rows.filter(r => r.chosen === "—").map(r => "  " + r.cat + " / " + r.name).join("\n"));
console.log("\nAUTO matches relied on (no override), lowest scores first:");
console.log(rows.filter(r => r.source === "auto").sort((a, b) => a.score - b.score)
  .slice(0, 25).map(r => "  " + r.score + "  " + r.name + "  ->  " + r.chosen).join("\n"));
