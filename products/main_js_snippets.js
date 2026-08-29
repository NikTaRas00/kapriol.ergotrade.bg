// ─────────────────────────────────────────────────────────────────────────────
// KAPRIOL WEBSITE — main.js snippets
// Paste these into main.js in the appropriate places
// ─────────────────────────────────────────────────────────────────────────────


// ── 1. CATEGORIES ARRAY ──────────────────────────────────────────────────────
// Defines the six product categories used throughout the site.
// Place near the top of main.js, alongside other config constants.

var CATEGORIES = [
  { id: "footwear",             titleKey: "catFootwear" },
  { id: "footwear-accessories", titleKey: "catFootwearAccessories" },
  { id: "clothing",             titleKey: "catClothing" },
  { id: "clothing-accessories", titleKey: "catClothingAccessories" },
  { id: "ppe",                  titleKey: "catPPE" },
  { id: "ppe-accessories",      titleKey: "catPPEAccessories" }
];


// ── 2. COPY OBJECT ADDITIONS ─────────────────────────────────────────────────
// Add these keys to your existing COPY / i18n object (English section).
// They map each category titleKey to its display label.

// Inside COPY.en (or wherever your English strings live):
catFootwear:             "Footwear",
catFootwearAccessories:  "Footwear Accessories & Care",
catClothing:             "Workwear & Professional Clothing",
catClothingAccessories:  "Clothing Accessories",
catPPE:                  "PPE & Protection",
catPPEAccessories:       "PPE Accessories",


// ── 3. PRODUCT COUNTS (for reference) ────────────────────────────────────────
// footwear:             47
// footwear-accessories:  6
// clothing:            105
// clothing-accessories: 23
// ppe:                 139
// ppe-accessories:      14
// ─────────────────────────────────────────────────────────────────────────────
// TOTAL:               334 products
// ─────────────────────────────────────────────────────────────────────────────


// ── 4. EXAMPLE: CATEGORY FILTER FUNCTION ─────────────────────────────────────
// Helper to filter products.json by category id.
// Assumes `products` is the parsed array from products.json.

function getProductsByCategory(products, categoryId) {
  return products.filter(function(p) {
    return p.category === categoryId;
  });
}

// Usage:
//   fetch('products.json')
//     .then(r => r.json())
//     .then(products => {
//       var footwear = getProductsByCategory(products, 'footwear');
//       // render footwear...
//     });
