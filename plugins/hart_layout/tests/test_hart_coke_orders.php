<?php
/**
 * php plugins/hart_layout/tests/test_hart_coke_orders.php
 * HART's coke orders, formerly the coke_orders plugin: same lanes, same step, same COKE- keeping.
 */
require_once __DIR__ . '/../hart_coke_orders.php';
$manifest = require __DIR__ . '/../plugin.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

check('all four outbound lanes count', hart_coke_outbound_shipments() === ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK']);
check('the two single-car lanes are generated', hart_coke_replenish_shipments() === ['COKE-USS', 'COKE-CLEV']);

$p = ['keep_coke' => '1', 'keep_prefixes' => 'ABC-'];
hart_coke_normalize_cancel_params($p);
check('keep_coke=1 adds COKE-', $p === ['keep_prefixes' => 'ABC-,COKE-']);
$p = ['keep_coke' => '0', 'keep_prefixes' => 'COKE-,ABC-'];
hart_coke_normalize_cancel_params($p);
check('keep_coke=0 removes it', $p === ['keep_prefixes' => 'ABC-']);
$p = [];
hart_coke_normalize_cancel_params($p);
check('a step naming no prefixes keeps COKE-', $p === ['keep_prefixes' => 'COKE-']);
$p = ['keep_prefixes' => ''];
hart_coke_normalize_cancel_params($p);
check('an operator clearing the field wins', $p === ['keep_prefixes' => '']);

$p = ['target_min' => 'x', 'target_max' => '3'];
hart_coke_normalize_replenish_params($p);
check('bad targets fall back and max is at least min', $p === ['target_min' => '6', 'target_max' => '6']);

$def = hart_coke_catalog_definitions()[0];
check('the step keeps its id, dispatch and hidden adder', $def['id'] === 'replenish_coke_orders' && $def['dispatch'] === 'replenish_coke_orders' && $def['adder'] === false);
check('the plugin registers the step and the cancel/drain mapping', ($manifest['dispatch_handlers']['replenish_coke_orders'] ?? '') === 'hart_coke_dispatch_replenish'
    && ($manifest['normalize_params']['cancel_orders'] ?? '') === 'hart_coke_normalize_cancel_params'
    && ($manifest['normalize_params']['drain_unfilled_orders'] ?? '') === 'hart_coke_normalize_cancel_params');
check('import text still maps to the step', hart_coke_guess_replenish('Replenish Coke Orders (target 6–8)') === 'replenish_coke_orders');
$m = [];
hart_coke_dispatch_log_messages(['before' => 2, 'after' => 6, 'target_min' => 6, 'target_max' => 8, 'shipments' => ['COKE-USS']], $m);
check('the session log line is unchanged', $m === ['Outbound coke orders: 2 → 6 (target 6–8).', 'Lanes: COKE-USS.']);

echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
