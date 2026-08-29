/* Shared library for the product-description matching pipeline.
   Parses deploy/product-descriptions.md into {name, html} entries, cleans the
   HTML, and exposes a token scorer used to match products.
   NB the source .md lives HERE in deploy/ on purpose — it used to be read from
   "Share Folder/", which is a disposable drop-zone the user wipes periodically. */
const fs = require("fs");
const path = require("path");

const ROOT = path.resolve(__dirname, "..");

/* ---- parse the markdown into product sections ---- */
function parseDescriptions() {
  const md = fs.readFileSync(path.join(__dirname, "product-descriptions.md"), "utf8");
  const lines = md.split(/\r?\n/);
  const out = [];
  let cur = null;
  for (const ln of lines) {
    const m = ln.match(/^##\s+(.*)$/);
    if (m) { if (cur) out.push(cur); cur = { name: m[1].trim(), body: [] }; }
    else if (cur) { cur.body.push(ln); }
  }
  if (cur) out.push(cur);
  // first section is the "Name / FullDescription" header
  return out.slice(1)
    .map(e => ({ name: e.name, body: e.body.join("\n").trim() }))
    .filter(e => e.body && e.body !== "None");
}

/* ---- clean the catalog HTML: drop editor cruft, keep semantic tags ---- */
const ALLOWED = new Set(["p", "ul", "ol", "li", "strong", "b", "em", "i", "br", "h3", "h4", "h5", "span"]);
function cleanHtml(html) {
  let h = html;
  // strip data-start / data-end / class / style and other attributes from every tag
  h = h.replace(/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(\/?)>/g, (full, tag, slash) => {
    const t = tag.toLowerCase();
    if (!ALLOWED.has(t)) return "";          // open tag of a disallowed element -> drop
    return "<" + t + (slash ? " /" : "") + ">"; // keep tag, no attributes
  });
  // strip closing tags of disallowed elements
  h = h.replace(/<\/([a-zA-Z][a-zA-Z0-9]*)\s*>/g, (full, tag) => {
    return ALLOWED.has(tag.toLowerCase()) ? "</" + tag.toLowerCase() + ">" : "";
  });
  // Drop the "PROTECTION CLASS" / "SAFETY CLASS(ES)" section (heading + its
  // bullet list of class codes like S3S/FO/SR) — redundant with the footwear
  // Safety-class spec group shown elsewhere. Leaves detailed EN-standard
  // protection sections (e.g. "MECHANICAL RISK PROTECTION - EN 388…") intact.
  h = h.replace(/<(h[345])>\s*(?:protection|safety)\s+class(?:es)?[^<]*<\/\1>\s*(?:<(ul|ol)>[\s\S]*?<\/\2>\s*)?/gi, "");
  // drop empty headings / paragraphs / list items the source leaves behind
  // (&nbsp;- or whitespace-only), then any list emptied by the removals above
  h = h.replace(/<(h[345]|p|li)>\s*(?:&nbsp;|<br\s*\/?>|\s)*<\/\1>/gi, "");
  h = h.replace(/<(ul|ol)>\s*<\/\1>/gi, "");
  // collapse runs of whitespace between tags, trim
  h = h.replace(/[ \t]+\n/g, "\n").replace(/\n{3,}/g, "\n\n").trim();
  return h;
}

/* ---- tokeniser for fuzzy name matching ---- */
const GENERIC = new Set(["work", "safety", "protective", "workwear", "occupational",
  "professional", "set", "kit", "pair", "pairs", "pack", "item", "shoes2", "new"]);
const STOPWORDS = new Set(["the", "a", "of", "for", "and", "with", "in", "di", "a",
  "per", "con", "e", "to", "s"]);
// canonicalise garment/equipment words + Italian->English so the two naming systems meet
const SYN = {
  trousers: "pant", trouser: "pant", pants: "pant", pant: "pant", pantalone: "pant", pantaloni: "pant",
  shorts: "short", short: "short", bermuda: "short",
  glove: "glove", gloves: "glove", guanti: "glove", guanto: "glove",
  shoe: "shoe", shoes: "shoe", trainer: "shoe", trainers: "shoe", sneaker: "shoe", sneakers: "shoe", scarpa: "shoe", scarpe: "shoe",
  boot: "boot", boots: "boot", stivale: "boot", stivali: "boot",
  jacket: "jacket", giacca: "jacket", giubbotto: "jacket",
  gilet: "vest", vest: "vest", bodywarmer: "vest",
  fleece: "fleece", pile: "fleece",
  softshell: "softshell",
  earmuff: "earmuff", earmuffs: "earmuff", cuffie: "earmuff", cuffia: "earmuff",
  glasses: "glasses", occhiali: "glasses", goggles: "goggle", goggle: "goggle",
  harness: "harness", imbracatura: "harness",
  womens: "women", women: "women", woman: "women", ladies: "women", donna: "women",
  tshirt: "tshirt", maglietta: "tshirt", polo: "polo",
  shirt: "shirt", camicia: "shirt",
  mask: "mask", maschera: "mask", mascherina: "mask", maschere: "mask",
  helmet: "helmet", casco: "helmet", elmetto: "helmet",
  cap: "cap", cappellino: "cap", beanie: "beanie", cuffia2: "beanie",
  sock: "sock", socks: "sock", calze: "sock", calza: "sock", calzini: "sock",
  belt: "belt", cintura: "belt", fascia: "belt",
  rucksack: "rucksack", backpack: "rucksack", zaino: "rucksack",
  overall: "overall", overalls: "overall", tuta: "overall", salopette: "overall",
  raincoat: "raincoat", impermeabile: "raincoat", mackintosh: "raincoat",
  // fall-protection italian
  moschettone: "snaphook", snaphook: "snaphook", connector: "snaphook",
  acciaio: "steel", steel: "steel", alluminio: "aluminium", aluminium: "aluminium", aluminum: "aluminium",
  cordino: "lanyard", lanyard: "lanyard", lifeline: "lanyard",
  anticaduta: "fall", fall: "fall", arrest: "fall", arrester: "fall", arrestor: "fall",
  fettuccia: "webbing", webbing: "webbing", sling: "webbing",
  ancoraggio: "anchorage", anchorage: "anchorage", anchor: "anchorage", anchoring: "anchorage",
  visiera: "visor", visor: "visor",
  ginocchiera: "kneepad", ginocchiere: "kneepad", kneepad: "kneepad", kneepads: "kneepad",
  poliuretano: "pu", polyurethane: "pu", pu: "pu", eva: "eva",
  valigetta: "case", case: "case", armadietto: "cabinet", cabinet: "cabinet",
  pronto: "firstaid", soccorso: "firstaid", firstaid: "firstaid",
  corda: "rope", rope: "rope", guaina: "sheath", sheath: "sheath", sheathing: "sheath",
  discensore: "descender", descender: "descender", assicuratore: "belay", belay: "belay",
  maniglia: "handle", handle: "handle", staffa: "ascender", ascender: "ascender",
  filtri: "filter", filtro: "filter", filter: "filter", filters: "filter",
  sovrascarpe: "overshoe", overshoes: "overshoe", overshoe: "overshoe",
  regolabile: "adjustable", adjustable: "adjustable",
  retrattile: "retractable", retractable: "retractable",
  destra: "right", right: "right", sinistra: "left", left: "left",
  resina: "resin", resin: "resin", meccanico: "mechanical", mechanical: "mechanical",
  permanente: "permanent", permanent: "permanent",
  elastico: "elastic", elastic: "elastica", elastica: "elastic",
  doppio: "double", double: "double", winch: "winch", verricello: "winch",
  tripode: "tripod", tripod: "tripod", recupero: "recovery", recovery: "recovery",
  lombare: "lumbar", lumbar: "lumbar",
  positioning: "positioning", posizionamento: "positioning",
  bib: "bib", brace: "bib", pettorina: "bib",
  half: "half", protective2: "protective"
};
function tokens(s) {
  let t = (s || "").toLowerCase()
    .replace(/[‘’]/g, "'").replace(/&/g, " and ")
    .replace(/cat\.?\s*iii/g, "cat3").replace(/cat\.?\s*ii/g, "cat2")
    .replace(/4\s*in\s*1/g, "4in1")
    .replace(/(\d+)\s*(cm|mm|m|l|pcs|pc|db|x)\b/g, "$1$2")
    .replace(/[^a-z0-9]+/g, " ");
  const arr = t.split(/\s+/).filter(Boolean);
  const out = [];
  for (let w of arr) {
    if (SYN[w] !== undefined) w = SYN[w];
    if (!w) continue;
    if (GENERIC.has(w) || STOPWORDS.has(w)) continue;
    out.push(w);
  }
  return out;
}
const isNum = w => /\d/.test(w);

function score(ptoks, mset) {
  const pset = new Set(ptoks);
  let inter = 0, numMis = 0;
  for (const w of pset) { if (mset.has(w)) inter++; else if (isNum(w)) numMis++; }
  for (const w of mset) { if (isNum(w) && !pset.has(w)) numMis++; }
  const uni = new Set([...pset, ...mset]).size;
  let s = inter / Math.max(uni, 1);
  s -= numMis * 0.12;
  return s;
}

function buildIndex(entries) {
  return entries.map(e => ({ name: e.name, body: e.body, set: new Set(tokens(e.name)) }));
}

function topMatches(product, index, n) {
  const cand = [product.name, ...(product.sourceNames || [])].join(" ");
  const pt = tokens(cand);
  const scored = index.map(m => ({ name: m.name, s: score(pt, m.set) }));
  scored.sort((a, b) => b.s - a.s);
  return scored.slice(0, n);
}

module.exports = { ROOT, parseDescriptions, cleanHtml, tokens, score, buildIndex, topMatches };
