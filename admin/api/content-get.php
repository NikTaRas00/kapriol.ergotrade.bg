<?php
/**
 * content-get.php — site copy for the "Site content" tab.
 *
 * content.json is still a flat file: site copy (hero, why, rotator, stats,
 * contact rows, footer) was deliberately left out of the database migration.
 *
 * The one exception is `categories`. Range tile photos moved to the catalogue
 * database along with the rest of the category concept, so they are read from
 * there and merged into the response under the key admin.js already expects.
 * That keeps the editor's shape unchanged while the file stops being the
 * source of truth for them.
 */
require __DIR__ . '/_guard.php';
admin_require_auth();

$content = read_json(KAP_CONTENT, array());
if (!is_array($content)) { $content = array(); }

$pdo = kap_db();
$content['categories'] = kap_category_images($pdo);

json_out(array('success' => true, 'content' => $content));
