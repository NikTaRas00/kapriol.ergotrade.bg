<?php
/**
 * pricing-apply.php — "Upload all pricing", steps two and three.
 *
 * Auth + CSRF protected. Payload: { token, action } where action is
 *
 *   status        just return the pending plan (used when the tab is reopened)
 *   delete        case 2: delete the products missing from the file
 *   unavailable   case 2: keep them, clear the price, show "Unavailable"
 *   keep          case 2: leave them untouched
 *   add           case 3: create the products that are only in the file
 *   skip          case 3: do not create them
 *
 * Each action only works while the plan is on that step, so a double click or a
 * stale browser tab cannot run a step twice. The slug lists come from the plan
 * saved at upload time, never from the request.
 *
 * Returns { success, plan } (or plan: null for `status` with nothing pending).
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_pricing.php';
admin_require_post();
admin_require_auth();
admin_require_csrf();

$in = json_decode(file_get_contents('php://input'), true);
$action = (is_array($in) && isset($in['action'])) ? (string) $in['action'] : '';
$token  = (is_array($in) && isset($in['token']))  ? (string) $in['token']  : '';
if ($token === '' && !empty($_SESSION['price_plan'])) { $token = $_SESSION['price_plan']; }

$plan = kap_price_plan_load($token);
if ($action === 'status') {
    json_out(array('success' => true, 'plan' => $plan ? kap_price_plan_view($plan) : null), 200);
}
if (!$plan) {
    json_out(array('success' => false,
        'error' => 'There is no pending upload (it may have expired after an hour). Upload the file again.'), 410);
}

$byStep = array(
    'case2' => array('delete', 'unavailable', 'keep'),
    'case3' => array('add', 'skip'),
);
if (!isset($byStep[$plan['step']]) || !in_array($action, $byStep[$plan['step']], true)) {
    json_out(array('success' => false,
        'error' => 'That choice does not apply to the current step. Reloading the status…',
        'plan' => kap_price_plan_view($plan)), 409);
}

$pdo = kap_db();
try {
    if ($plan['step'] === 'case2') {
        $plan['stats']['case2Choice'] = $action;
        if ($action === 'delete') {
            $plan['stats']['deleted'] = kap_price_case2_delete($pdo, $plan['case2']);
        } elseif ($action === 'unavailable') {
            $plan['stats']['unavailable'] = kap_price_case2_unavailable($pdo, $plan['case2']);
        }
        $plan['step'] = kap_price_next_step($plan, 'case2');
    } else {
        $plan['stats']['case3Choice'] = $action;
        if ($action === 'add') {
            $r = kap_price_case3_add($pdo, $plan['case3']);
            if (is_string($r)) { json_out(array('success' => false, 'error' => $r), 500); }
            $plan['stats']['added'] = $r[0];
            $plan['stats']['addedExisted'] = $r[1];
        }
        $plan['step'] = 'done';
    }
} catch (Exception $e) {
    error_log('kapriol pricing-apply: ' . $e->getMessage());
    json_out(array('success' => false, 'error' => 'That step failed and was rolled back. Nothing changed.'), 500);
}

kap_price_plan_save($plan);
json_out(array('success' => true, 'plan' => kap_price_plan_view($plan)), 200);
