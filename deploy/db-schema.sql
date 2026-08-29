-- ===========================================================================
-- db-schema.sql — kapriol.ergotrade.bg catalogue database
-- ===========================================================================
--
-- Run this ONCE against the database cPanel created for you (phpMyAdmin → SQL,
-- with the database selected in the left-hand tree). There is deliberately no
-- CREATE DATABASE / USE statement here: cPanel owns database creation and
-- naming (it force-prefixes the account name, e.g. ergotrade_kapriol), so this
-- file must run inside whatever database you already selected.
--
-- Re-runnable: the DROPs below are in reverse-dependency order, so executing
-- the whole file again rebuilds a clean, empty schema. THAT WIPES THE DATA —
-- the importer (deploy/db-import.php) re-populates it from the JSON snapshot,
-- so a rebuild is cheap for as long as the JSON files remain the source of
-- truth. Once the admin writes to MySQL directly, stop running the DROPs.
--
-- Requires JSON column support: MySQL 5.7.8+ or MariaDB 10.2+. On MariaDB,
-- JSON is an alias for LONGTEXT with a validity CHECK — that is fine here.
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS product_article_code;
DROP TABLE IF EXISTS product_spec_code;
DROP TABLE IF EXISTS product_list;
DROP TABLE IF EXISTS spec_code;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;

SET FOREIGN_KEY_CHECKS = 1;


-- ---------------------------------------------------------------------------
-- categories — the six fixed ranges.
--
-- These ids are load-bearing: they are the values stored in products.category
-- and the ?cat= deep links that already exist in the wild. The labels are the
-- 2026-redesign display names (note clothing → "Work Wear"). Seeded by the
-- importer from the CATEGORIES constant in assets/js/main.js:19-26.
--
-- img / img_v are the landing-page tile photos, which today live in
-- content.json's `categories` map. They are mirrored here so the whole
-- category concept has one home; content.json stays authoritative until the
-- admin is switched over.
-- ---------------------------------------------------------------------------
CREATE TABLE categories (
  id          VARCHAR(32)  NOT NULL,
  label       VARCHAR(120) NOT NULL,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  img         VARCHAR(500)     NULL,
  img_v       VARCHAR(64)      NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- products — one row per catalogue record.
--
-- `slug` is the natural primary key and is OPAQUE. It is NOT derivable from
-- `name` (dune-jaq ↔ "Dune Jaq", but plenty of others do not correspond at
-- all), it is what every ?p= link and every image filename is keyed on, and
-- the admin cannot edit it. Carry it verbatim; never slugify a name to
-- reconstruct one.
--
-- `sort_order` captures the products.json array position. It is not cosmetic:
-- renderCategoryTiles picks "the first product in this category that has a
-- photo" (main.js:855-859), renderFeatured falls back the same way (:912) and
-- renderRelated takes the first four (:1704). Lose the order and those three
-- surfaces silently change what they show.
--
-- `img` is nullable AND allowed to be empty: 153 of 333 products have no
-- photograph, and that is a permanent, designed-for state, not missing data.
--
-- `img_v` is a STRING cache-buster ("20260723"), not an int — the JSON stores
-- it as a string everywhere even though products-save.php casts it to int.
--
-- `safety_class_raw` holds the intact EN ISO marking ("S1 PL", "S1PL ESD") for
-- the product badge, because that is ONE printed certification and not two
-- separate claims. The whitespace-split codes live in product_spec_code for
-- filtering. See the comment on that table.
--
-- `spec_json` is the frozen PDF-import payload: ~22 keys the admin has never
-- been able to edit and which the current products-save.php preserves blind
-- (toeCap, sole, midsole, maxLoadKg, fabricTechnology, packQuantity,
-- gloveCategory, lining, coat, snrDb, dimensions, paddingGsm, UVProtection,
-- metalFree, newColor, newSizes, weightGrams, fit, season, gender,
-- materialComposition, pageRef). The importer fills it with whatever keys are
-- left over rather than a hardcoded list, so an unrecognised field survives
-- instead of being dropped. Some of these ARE rendered (sole, toeCap,
-- weightGrams, fit, materialComposition — main.js:1536-1542), so any reader
-- must spread spec_json back into the record.
-- ---------------------------------------------------------------------------
CREATE TABLE products (
  slug             VARCHAR(120)  NOT NULL,
  category_id      VARCHAR(32)   NOT NULL,
  sort_order       INT           NOT NULL DEFAULT 0,
  name             VARCHAR(300)  NOT NULL,
  description      VARCHAR(1000)     NULL,
  full_description MEDIUMTEXT        NULL,
  product_line     VARCHAR(300)      NULL,
  material         VARCHAR(500)      NULL,
  img              VARCHAR(500)      NULL,
  img_v            VARCHAR(64)       NULL,
  safety_class_raw VARCHAR(120)      NULL,
  is_new           TINYINT(1)    NOT NULL DEFAULT 0,
  hidden           TINYINT(1)    NOT NULL DEFAULT 0,
  spec_json        JSON              NULL,
  updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (slug),
  KEY idx_cat_sort (category_id, sort_order),
  KEY idx_hidden (hidden),
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id)
    REFERENCES categories (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- product_list — every ordered string array hanging off a product.
--
-- One table rather than eight, because the admin treats them uniformly
-- (clean_str_array in products-save.php) and they are always read as a whole
-- list in document order. `list_key` is one of:
--
--   features   overview   sizes   colors   standards   images
--   sourceNames   features_pictograms
--
-- `sizes` are strings, not numbers ("40", but also "TU", "XL").
-- `images` values are full site-relative paths
-- ("assets/img/products/<slug>.webp"), path-validated by the admin against
-- ^assets/img/products/[A-Za-z0-9._-]+\.(webp|jpe?g|png)$.
-- `features_pictograms` keeps its near-duplicate vocabulary verbatim
-- (Comfort/Comfortable, Water Repellent/Water Resistant, WR/WRU) — that is
-- owner data and normalising it was explicitly declined.
-- ---------------------------------------------------------------------------
CREATE TABLE product_list (
  id        BIGINT        NOT NULL AUTO_INCREMENT,
  slug      VARCHAR(120)  NOT NULL,
  list_key  VARCHAR(40)   NOT NULL,
  ord       SMALLINT      NOT NULL,
  value     VARCHAR(2000) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_list (slug, list_key, ord),
  KEY idx_list_key (list_key),
  CONSTRAINT fk_list_prod FOREIGN KEY (slug)
    REFERENCES products (slug) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- product_spec_code — performance / safetyClass / technologies, normalised.
--
-- These are the filter chips. main.js ANDs every selected code, within and
-- across groups, so this is the table a future server-side filter would join
-- against — hence idx_group_code.
--
-- IMPORTANT on safetyClass: the legacy values are single whitespace-joined
-- marking strings ("S1 PL" → codes S1 + PL; "S1PL ESD" → S1PL + ESD; note the
-- source tokenisation is itself inconsistent). The importer splits them the
-- same way toCodes() does (main.js:277-289) and ALSO stores the untouched
-- string in products.safety_class_raw, because rawSpec() (:292) needs the
-- intact marking for the badge. Both representations are therefore correct at
-- once, and neither is derived from the other at read time.
-- ---------------------------------------------------------------------------
CREATE TABLE product_spec_code (
  id         BIGINT       NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(120) NOT NULL,
  spec_group VARCHAR(20)  NOT NULL,
  code       VARCHAR(100) NOT NULL,
  ord        SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_spec (slug, spec_group, code),
  KEY idx_group_code (spec_group, code),
  CONSTRAINT fk_spec_prod FOREIGN KEY (slug)
    REFERENCES products (slug) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- product_article_code — the SKU table (1,883 rows across 333 products, up to
-- 38 per product).
--
-- Surrogate primary key is REQUIRED: `code` is not globally unique. 32376,
-- 32377 and 32573 each appear on two different products. Do not be tempted to
-- key on (slug, code) either — nothing guarantees uniqueness within a product.
--
-- `color` is a rescue: it exists on 5 of the 1,883 rows as import residue and
-- the current admin writer does not persist it, so those values would be
-- destroyed on the next save of those products. Giving it a column preserves
-- them.
-- ---------------------------------------------------------------------------
CREATE TABLE product_article_code (
  id    BIGINT       NOT NULL AUTO_INCREMENT,
  slug  VARCHAR(120) NOT NULL,
  ord   SMALLINT     NOT NULL DEFAULT 0,
  code  VARCHAR(100) NOT NULL,
  size  VARCHAR(100)     NULL,
  color VARCHAR(100)     NULL,
  PRIMARY KEY (id),
  KEY idx_art_slug_ord (slug, ord),
  KEY idx_art_code (code),
  CONSTRAINT fk_art_prod FOREIGN KEY (slug)
    REFERENCES products (slug) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- spec_code — attributes.json, the code dictionary.
--
-- Supplies the human label, the explanatory disclosure text and the pictogram
-- for a code. The filter-chip VOCABULARY is not taken from here — it is
-- derived from the codes actually present on products (main.js:1143-1151) —
-- so a dictionary entry with no matching product simply never renders, and a
-- product code with no dictionary entry renders as a bare inert chip.
--
-- The legacy file used `name`; the current admin writer uses `title` +
-- `description`. main.js already reads `d.title || d.name` (:1327, :1579). The
-- importer folds name → title so there is exactly one column, per the agreed
-- cleanup.
--
-- safetyClass entries are text-only by design: attributes-save.php refuses to
-- persist img/title/description for that group. img stays NULL there.
-- ---------------------------------------------------------------------------
CREATE TABLE spec_code (
  spec_group  VARCHAR(20)  NOT NULL,
  code        VARCHAR(100) NOT NULL,
  title       VARCHAR(160)     NULL,
  description TEXT             NULL,
  img         VARCHAR(500)     NULL,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (spec_group, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
