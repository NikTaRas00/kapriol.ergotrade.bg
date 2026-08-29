-- ===========================================================================
-- db-check.sql — verify the seed landed correctly.
-- ===========================================================================
--
-- Run in phpMyAdmin AFTER importing deploy/db-seed.sql:
--   select the database in the left tree -> SQL tab -> paste this -> Go.
--
-- It returns one table of about 20 rows. Every row carries the number the
-- database actually holds, the number it SHOULD hold (computed from the live
-- JSON these tables were seeded from), and a verdict column.
--
-- READ THE `verdict` COLUMN. If every row says OK, the import is correct and
-- complete. Paste the result back and I will confirm.
--
-- Expected values were derived from the host files downloaded on 2026-08-29:
-- 333 products, 180 with photos, 317 with rich descriptions, 46 performance,
-- 46 technologies, 44 safetyClass, 0 hidden, 54 dictionary codes.
-- ===========================================================================

SELECT metric, actual, expected,
       CASE WHEN actual = expected THEN 'OK' ELSE '*** MISMATCH ***' END AS verdict
FROM (
    -- ---- table row counts -------------------------------------------------
    SELECT 1 AS seq, 'rows: categories'            AS metric, COUNT(*) AS actual,    6 AS expected FROM categories
    UNION ALL SELECT  2, 'rows: products',             COUNT(*),  333 FROM products
    UNION ALL SELECT  3, 'rows: product_list',         COUNT(*), 4877 FROM product_list
    UNION ALL SELECT  4, 'rows: product_spec_code',    COUNT(*),  466 FROM product_spec_code
    UNION ALL SELECT  5, 'rows: product_article_code', COUNT(*), 1877 FROM product_article_code
    UNION ALL SELECT  6, 'rows: spec_code',            COUNT(*),   54 FROM spec_code

    -- ---- products per category (the six fixed ranges) ---------------------
    UNION ALL SELECT  7, 'cat: footwear',             COUNT(*),  46 FROM products WHERE category_id = 'footwear'
    UNION ALL SELECT  8, 'cat: footwear-accessories', COUNT(*),   6 FROM products WHERE category_id = 'footwear-accessories'
    UNION ALL SELECT  9, 'cat: clothing',             COUNT(*), 105 FROM products WHERE category_id = 'clothing'
    UNION ALL SELECT 10, 'cat: clothing-accessories', COUNT(*),  23 FROM products WHERE category_id = 'clothing-accessories'
    UNION ALL SELECT 11, 'cat: ppe',                  COUNT(*), 139 FROM products WHERE category_id = 'ppe'
    UNION ALL SELECT 12, 'cat: ppe-accessories',      COUNT(*),  14 FROM products WHERE category_id = 'ppe-accessories'

    -- ---- content completeness --------------------------------------------
    UNION ALL SELECT 13, 'with photo',        COUNT(*), 180 FROM products WHERE img IS NOT NULL AND img <> ''
    UNION ALL SELECT 14, 'with rich desc',    COUNT(*), 317 FROM products WHERE full_description IS NOT NULL AND full_description <> ''
    UNION ALL SELECT 15, 'hidden',            COUNT(*),   0 FROM products WHERE hidden = 1
    UNION ALL SELECT 16, 'safetyClass raw',   COUNT(*),   1 FROM products WHERE safety_class_raw IS NOT NULL AND safety_class_raw <> ''

    -- ---- spec codes, by group (distinct products carrying each) -----------
    UNION ALL SELECT 17, 'slugs: performance',  COUNT(DISTINCT slug), 46 FROM product_spec_code WHERE spec_group = 'performance'
    UNION ALL SELECT 18, 'slugs: technologies', COUNT(DISTINCT slug), 46 FROM product_spec_code WHERE spec_group = 'technologies'
    UNION ALL SELECT 19, 'slugs: safetyClass',  COUNT(DISTINCT slug), 44 FROM product_spec_code WHERE spec_group = 'safetyClass'

    -- ---- the rescued article-code colours ---------------------------------
    UNION ALL SELECT 20, 'article rows w/ color', COUNT(*), 5 FROM product_article_code WHERE color IS NOT NULL AND color <> ''

    -- ---- integrity --------------------------------------------------------
    -- Orphans are impossible while the foreign keys are in place; these
    -- confirm the constraints actually took, rather than trusting them.
    UNION ALL SELECT 21, 'orphan list rows',    COUNT(*), 0
        FROM product_list l LEFT JOIN products p ON p.slug = l.slug WHERE p.slug IS NULL
    UNION ALL SELECT 22, 'orphan spec rows',    COUNT(*), 0
        FROM product_spec_code s LEFT JOIN products p ON p.slug = s.slug WHERE p.slug IS NULL
    UNION ALL SELECT 23, 'orphan article rows', COUNT(*), 0
        FROM product_article_code a LEFT JOIN products p ON p.slug = a.slug WHERE p.slug IS NULL
    -- Every product must sit in one of the six known categories.
    UNION ALL SELECT 24, 'unknown category',    COUNT(*), 0
        FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE c.id IS NULL
    -- utf8mb4 sanity: apostrophes survived the escaping round-trip. If the
    -- quoting had broken, these rows would have been truncated or dropped.
    UNION ALL SELECT 25, 'names/descs with an apostrophe', COUNT(*), 10
        FROM products WHERE description LIKE '%''%' OR name LIKE '%''%'
) AS checks
ORDER BY seq;
