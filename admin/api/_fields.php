<?php
/**
 * _fields.php — input sanitising for the product editor.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  Shared by products-save.php and product-create.php, which used to carry
 *  byte-identical copies of these functions. They are the ALLOWLIST: a field
 *  the admin can change has an entry here, and a field that does not have one
 *  cannot be written no matter what the request body says.
 *
 *  Functions are kap_-prefixed on purpose. attributes-save.php declares its own
 *  clean_str() with different rules (newlines collapse to spaces, shorter cap),
 *  and an unprefixed name here would be a fatal redeclare the day someone
 *  includes both.
 *
 *  Never an entry point — denied by the root .htaccess (^_.*\.php).
 * ──────────────────────────────────────────────────────────────────────────
 *
 *  WHAT IS DELIBERATELY NOT EDITABLE
 *
 *    slug      the primary key and the image filename stem. Opaque: it is NOT
 *              derivable from name (dune-jaq vs "Dune Jaq"). Set once at
 *              creation, never rewritten.
 *    category  not part of this allowlist because moving a product also has to
 *              re-sort it to the end of its new range. products-save.php
 *              handles it separately via kap_product_set_category()
 *              (_pricing.php); product-create.php sets it once at creation.
 *    price, unavailable
 *              written only by the price-list upload (pricing-*.php), never by
 *              the product editor.
 *    the ~22 frozen PDF-import fields (toeCap, sole, weightGrams, fit,
 *              materialComposition ...). They live in products.spec_json and
 *              are preserved untouched through every save — the same
 *              pass-through the JSON writer gave them.
 */

if (defined('KAP_FIELDS_LOADED')) { return; }
define('KAP_FIELDS_LOADED', true);

/** Collapse CRLF, strip control characters (keeping \n and \t), trim, cap. */
function kap_clean_str($v, $max = 5000) {
    $v = is_scalar($v) ? (string) $v : '';
    $v = str_replace(array("\r\n", "\r"), "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    $v = trim($v);
    if (mb_strlen($v) > $max) { $v = mb_substr($v, 0, $max); }
    return $v;
}

/** A list of non-empty clean strings, capped in both count and length. */
function kap_clean_str_array($v, $maxItems = 100, $maxLen = 2000) {
    if (!is_array($v)) { return array(); }
    $out = array();
    foreach ($v as $item) {
        $s = kap_clean_str($item, $maxLen);
        if ($s !== '') { $out[] = $s; }
        if (count($out) >= $maxItems) { break; }
    }
    return $out;
}

/**
 * Gallery paths: only root-relative files inside assets/img/products.
 * Anything else — absolute URLs, traversal, other directories — is dropped
 * rather than rejected, so one bad entry cannot fail an otherwise good save.
 */
function kap_clean_image_paths($v, $maxItems = 30) {
    if (!is_array($v)) { return array(); }
    $out = array();
    foreach ($v as $item) {
        $s = kap_clean_str($item, 500);
        if (preg_match('#^assets/img/products/[A-Za-z0-9._-]+\.(webp|jpe?g|png)$#', $s)) { $out[] = $s; }
        if (count($out) >= $maxItems) { break; }
    }
    return $out;
}

/**
 * Build the allowlisted field set from a decoded request body.
 *
 * Only keys PRESENT in $in appear in the result — that is what makes the save
 * a merge rather than a replace, and it is what lets the photo autosave post a
 * four-key patch ({slug, img, images, imgV}) without wiping the rest of the
 * record.
 *
 * @param  array $in decoded JSON body
 * @return array     field name => sanitised value, in catalog.php's vocabulary
 */
function kap_product_fields($in) {
    $f = array();

    if (array_key_exists('name', $in))            { $f['name']            = kap_clean_str($in['name'], 300); }
    if (array_key_exists('description', $in))     { $f['description']     = kap_clean_str($in['description'], 4000); }
    if (array_key_exists('fullDescription', $in)) { $f['fullDescription'] = kap_clean_str($in['fullDescription'], 20000); }
    if (array_key_exists('productLine', $in))     { $f['productLine']     = kap_clean_str($in['productLine'], 300); }
    if (array_key_exists('material', $in))        { $f['material']        = kap_clean_str($in['material'], 500); }
    if (array_key_exists('images', $in))          { $f['images']          = kap_clean_image_paths($in['images']); }
    if (array_key_exists('isNew', $in))           { $f['isNew']           = (bool) $in['isNew']; }
    if (array_key_exists('hidden', $in))          { $f['hidden']          = (bool) $in['hidden']; }

    /* `img` is the lead photo and is path-validated exactly like the gallery.
       The old products-save.php checked only the length here, which let any
       string through into an <img src>; there is no reason for the two to
       differ, so the tighter rule now applies to both. */
    if (array_key_exists('img', $in)) {
        $one = kap_clean_image_paths(array($in['img']), 1);
        $f['img'] = $one ? $one[0] : '';
    }

    /* imgV is the per-upload cache-buster. Stored as a string (the data has
       always held "20260723"); cast through int first to reject anything that
       is not a plain stamp. */
    if (array_key_exists('imgV', $in)) { $f['imgV'] = (string) (int) $in['imgV']; }

    foreach (array('features', 'overview', 'sizes', 'colors', 'standards') as $k) {
        if (array_key_exists($k, $in)) { $f[$k] = kap_clean_str_array($in[$k]); }
    }

    /* Spec codes are short tokens; their labels and pictograms live in the
       spec_code dictionary, not here. */
    foreach (array('performance', 'safetyClass', 'technologies') as $k) {
        if (array_key_exists($k, $in)) { $f[$k] = kap_clean_str_array($in[$k], 100, 100); }
    }

    if (array_key_exists('articleCodes', $in) && is_array($in['articleCodes'])) {
        $codes = array();
        foreach ($in['articleCodes'] as $row) {
            if (!is_array($row)) { continue; }
            $code = kap_clean_str(isset($row['code']) ? $row['code'] : '', 100);
            $size = kap_clean_str(isset($row['size']) ? $row['size'] : '', 100);
            // `color` is import-era residue on 5 rows. It has a column so those
            // values survive, but the editor has no field for it, so a save
            // that omits it must not destroy it — hence it is carried through
            // when present rather than defaulted away.
            $entry = array('code' => $code, 'size' => $size);
            if (isset($row['color'])) {
                $c = kap_clean_str($row['color'], 100);
                if ($c !== '') { $entry['color'] = $c; }
            }
            if ($code !== '' || $size !== '') { $codes[] = $entry; }
            if (count($codes) >= 200) { break; }
        }
        $f['articleCodes'] = $codes;
    }

    return $f;
}
