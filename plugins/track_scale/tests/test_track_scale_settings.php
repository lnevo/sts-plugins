<?php
/**
 * php plugins/track_scale/tests/test_track_scale_settings.php
 */
require_once __DIR__ . '/../track_scale_settings_helpers.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

$defaults = ['units' => 'tons', 'precision' => 2, 'shipments' => ['outbound' => [], 'reload' => []],
    'simulation' => ['in_tolerance_percent' => 86, 'min_reloads_per_weigh_batch' => 1], 'site_label' => ''];

$r = track_scale_settings_check('{"units": "tons"', $defaults);
check('broken JSON is an error', count($r['errors']) === 1);
$r = track_scale_settings_check('[1, 2]', $defaults);
check('a list is an error', count($r['errors']) === 1);
$r = track_scale_settings_check('{}', $defaults);
check('an empty object is fine', $r === ['errors' => [], 'warnings' => []]);
$r = track_scale_settings_check('{"units": "tons", "precision": 3, "site_label": "Bay"}', $defaults);
check('matching kinds give no warnings', $r['warnings'] === []);
$r = track_scale_settings_check('{"precsion": 3}', $defaults);
check('an unknown key is a warning', strpos($r['warnings'][0] ?? '', 'precsion') !== false && $r['errors'] === []);
$r = track_scale_settings_check('{"precision": "3"}', $defaults);
check('text where a number belongs is a warning', strpos($r['warnings'][0] ?? '', 'precision is text') !== false);
$r = track_scale_settings_check('{"simulation": {"in_tolerance_percent": 85}}', $defaults);
check('a group that leaves out default keys says which', strpos($r['warnings'][0] ?? '', 'min_reloads_per_weigh_batch') !== false);
$r = track_scale_settings_check('{"shipments": {"outbound": ["A"]}}', $defaults);
check('a partial shipments group is flagged too', strpos($r['warnings'][0] ?? '', 'reload') !== false);

$dir = sys_get_temp_dir() . '/ts_settings_' . getmypid();
$path = $dir . '/track_scale_config.json';
check('writing creates the folder and file', track_scale_settings_write($path, '{"units": "tons"}') === ''
    && trim((string) file_get_contents($path)) === '{"units": "tons"}');
check('writing again keeps the previous file as .bak', track_scale_settings_write($path, '{"units": "kg"}') === ''
    && strpos((string) file_get_contents($path . '.bak'), 'tons') !== false);
@unlink($path);
@unlink($path . '.bak');
@rmdir($dir);

echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
