<?php
/**
 * php plugins/track_scale/tests/test_track_scale_preset.php
 * Built-in defaults name no layout; a plugin preset layers under the config file.
 */
$FAKE_MANIFESTS = [];
function plugins_discover()
{
    return $GLOBALS['FAKE_MANIFESTS'];
}
require_once __DIR__ . '/../track_scale_helpers.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

$d = track_scale_default_config();
check('built-in defaults name no scale, commodity or shipments', $d['loading_location_code'] === '' && $d['commodity_code'] === ''
    && $d['outbound_loading_location_code'] === '' && $d['reload_loading_location_code'] === ''
    && $d['reload_unloading_location_code'] === '' && $d['shipments'] === ['outbound' => [], 'reload' => []]
    && $d['calibration']['test_car_reporting_marks'] === '');
check('no preset when no plugin has one', track_scale_preset() === [null, []]);
check('layered defaults are the built-in defaults without a preset', track_scale_layered_defaults() === $d);

$FAKE_MANIFESTS = [
    ['id' => 'a_plugin'],
    ['id' => 'layout_one', 'presets' => ['track_scale' => ['loading_location_code' => 'YARD-SCALE', 'commodity_code' => 'ORE']]],
    ['id' => 'layout_two', 'presets' => ['track_scale' => ['loading_location_code' => 'OTHER']]],
];
check('the first plugin with a preset wins', track_scale_preset()[0] === 'layout_one');
$l = track_scale_layered_defaults();
check('the preset replaces its keys and leaves the rest', $l['loading_location_code'] === 'YARD-SCALE' && $l['commodity_code'] === 'ORE'
    && $l['units'] === 'tons' && $l['shipments'] === ['outbound' => [], 'reload' => []]);
check('fallbacks are blank, not a layout name', track_scale_loading_location_code([]) === ''
    && track_scale_loading_location_for_routing('outbound', []) === '' && track_scale_shipment_codes_for_routing('reload', []) === []);
check('reload starts at the scale when no reload location is set', track_scale_loading_location_for_routing('reload',
    ['loading_location_code' => 'YARD-SCALE', 'reload_loading_location_code' => '']) === 'YARD-SCALE');

echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
