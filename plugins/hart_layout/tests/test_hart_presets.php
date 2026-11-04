<?php
/**
 * php plugins/hart_layout/tests/test_hart_presets.php
 * HART's preset must give the generic plugins the values they used to hard-code,
 * so a HART install with no config file behaves as before.
 */
$FAKE_MANIFESTS = [];
function plugins_discover()
{
    return $GLOBALS['FAKE_MANIFESTS'];
}
require_once __DIR__ . '/../../track_scale/track_scale_helpers.php';
$manifest = require __DIR__ . '/../plugin.php';
$FAKE_MANIFESTS = [$manifest];

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

$ts = track_scale_layered_defaults();
check('track scale location, commodity and routing are HART\'s', $ts['loading_location_code'] === 'SOUTH-SCALE'
    && $ts['outbound_loading_location_code'] === 'NORTH' && $ts['reload_loading_location_code'] === 'SOUTH-SCALE'
    && $ts['reload_unloading_location_code'] === 'SHEN-COKE' && $ts['commodity_code'] === 'COKE');
check('track scale shipments are HART\'s', $ts['shipments'] === ['outbound' => ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'],
    'reload' => ['COKE-RELOAD-SHEN']]);
$cal = track_scale_default_config()['calibration'];
$cal['test_car_reporting_marks'] = 'COST1';
check('the calibration group is the default one with HART\'s test car', $ts['calibration'] === $cal);
check('there is no coke orders preset any more', !isset($manifest['presets']['coke_orders']));
check('CK1 weighs with HART\'s routine', ($manifest['track_scale_job_weigh']['CK1'] ?? '') === 'hart_track_scale_run_ck1_scale_ops');
check('the step editor keeps HART\'s COKE- default', ($manifest['param_overrides']['cancel_orders']['keep_prefixes']['default'] ?? '') === 'COKE-');

echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
