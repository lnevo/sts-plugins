<?php
/**
 * php plugins/hart_layout/tests/test_hart_warm_start_config.php
 * hart_check_config refuses removed modes and an unfinished secure-locals config
 * before any session is written; supported configs pass.
 */
require_once __DIR__ . '/../hart_warm_start.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

function refused(array $config)
{
    try {
        hart_check_config($config);
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
}

check('default config passes', !refused(hart_default_config()));
check('tracked (secure locals + stop at STG-SCULLY) passes', !refused(['secure_locals_each_session' => true, 'stop_when_stg_scully_ready' => true]));
check('play (secure locals + play session) passes', !refused(['secure_locals_each_session' => true, 'play_operating_session' => true]));
check('the --tracked overrides pass', !refused(hart_tracked_sim_overrides()));
check('the play overrides pass', !refused(array_merge(hart_tracked_sim_overrides(), ['play_operating_session' => true])));
check('secure locals with no stop mode is refused', refused(['secure_locals_each_session' => true]));
foreach (hart_retired_config_keys() as $key) {
    check("retired key {$key} is refused", refused([$key => true]));
    check("retired key {$key} set false passes", !refused([$key => false]));
}
check('hart_run refuses before touching the database', (function () {
    try {
        hart_run(null, ['secure_locals_each_session' => true]);
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
})());
check('hart_advance_session refuses before touching the database', (function () {
    try {
        hart_advance_session(null, [], '', 30, false, ['run_ck1_test' => true]);
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
})());

echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
