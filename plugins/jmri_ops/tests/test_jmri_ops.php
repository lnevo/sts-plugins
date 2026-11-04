<?php
/**
 * php plugins/jmri_ops/tests/test_jmri_ops.php
 *
 * Batch building against the HART map, and the HTTP round trip against a fake
 * JMRI (php -S with tests/fake_jmri.php). Exits non-zero on a failure.
 */
require_once __DIR__ . '/../jmri_ops_helpers.php';
require_once __DIR__ . '/../jmri_ops_plugin.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

$map = jmri_ops_load_map('hart');
$locations = [
    4 => ['code' => 'SOUTH-YARD', 'station' => 'South Yard'],
    5 => ['code' => 'SOUTH-SCALE', 'station' => 'South Yard'],
    1 => ['code' => 'NORTH-YARD', 'station' => 'North Yard'],
    106 => ['code' => 'STUK-TEAM', 'station' => 'Neville Island'],
    108 => ['code' => 'ARIS-PELLETS', 'station' => 'Neville Island'],
    45 => ['code' => 'NOVACOR-PLST', 'station' => 'McKees Rocks-PA'],
    72 => ['code' => 'HEINZ-TINP', 'station' => 'Mckeesport-PA'],
    6 => ['code' => 'SHEN-COKE-SHIPPING', 'station' => 'Shenango Coke Works'],
];
$shipments = [
    1 => ['code' => 'ARIS-PELLETS-IN-NOVACOR', 'commodity' => 'PLASTICPELLETS', 'loading_location' => 45, 'unloading_location' => 108],
    2 => ['code' => 'IX-TINPLATE', 'commodity' => 'TINPLATE', 'loading_location' => 92, 'unloading_location' => 72],
    3 => ['code' => 'NEW-THING', 'commodity' => 'UNOBTAINIUM', 'loading_location' => 45, 'unloading_location' => 999],
];
$car = function ($marks, $status, $loc, $waybill = '', $shipment = 0) {
    return ['id' => 1, 'reporting_marks' => $marks, 'status' => $status, 'current_location_id' => $loc,
        'waybill' => $waybill, 'order_shipment' => $shipment];
};
$batch = jmri_ops_build_batch($map, [
    $car('CO2052', 'Loaded', 45, '001-001', 1),
    $car('BO8300', 'Loaded', 4, '001-006', 2),
    $car('NW1', 'Ordered', 4, '002-001', 1),
    $car('NW2', 'Empty', 106, '003-E01', 4),
    $car('NW3', 'Empty', 108),
    $car('NW4', 'Unloading', 108, '004-001', 1),
    $car('NW5', 'Unavailable', 4),
    $car('NW6', 'Empty', 1),
    $car('NW7', 'Loaded', 4, '005-001', 3),
    $car('NW8', 'Loaded', 6, '', 0),
], $locations, $shipments);
$by = [];
foreach ($batch['cars'] as $row) {
    $by[$row['car']] = $row;
}
$why = [];
foreach ($batch['skipped'] as $row) {
    $why[$row['car']] = $row['reason'];
}
$notes = [];
foreach ($batch['notes'] as $row) {
    $notes[$row['car']] = ($notes[$row['car']] ?? '') . $row['note'] . '; ';
}

check('offline party maps through its station', $by['CO2052']['location'] === 'McKees Rocks' && $by['CO2052']['track'] === 'POHC Offline');
check('loaded car gets the commodity load and its unloading spur', $by['CO2052']['load'] === 'Pellets'
    && $by['CO2052']['final_location'] === 'Aristech Plastics' && $by['CO2052']['final_track'] === 'Pellet Unload');
check('yard has no track, so the track is blank', $by['BO8300']['location'] === 'South Yard' && $by['BO8300']['track'] === '');
check('loaded interchange car heads for the offline side', $by['BO8300']['load'] === 'Tin Plate' && $by['BO8300']['final_location'] === 'McKeesport');
check('ordered empty heads for its loading location', $by['NW1']['load'] === '' && $by['NW1']['final_location'] === 'McKees Rocks');
check('E-waybill empty heads for the location in the order', $by['NW2']['final_location'] === 'South Yard' && $by['NW2']['final_track'] === '');
check('E-waybill shipment column is a location id', jmri_ops_is_e_waybill('003-E01') && !jmri_ops_is_e_waybill('003-001'));
check('empty with no order has no final destination', $by['NW3']['load'] === '' && $by['NW3']['final_location'] === '');
check('unloading car keeps its load and clears the final destination', $by['NW4']['load'] === 'Pellets' && $by['NW4']['final_location'] === '');
check('unavailable cars are not sent', isset($why['NW5']) && !isset($by['NW5']));
check('unmapped STS locations are not sent and say why', isset($why['NW6']) && strpos($why['NW6'], 'NORTH-YARD') !== false);
check('unknown commodity is sent as itself with a note', $by['NW7']['load'] === 'UNOBTAINIUM' && strpos($notes['NW7'], 'UNOBTAINIUM') !== false);
check('unmapped destination is cleared with a note', $by['NW7']['final_location'] === '');
check('loaded with no order goes as empty with a note', $by['NW8']['load'] === '' && isset($notes['NW8']));
$none = jmri_ops_build_batch($map, [$car('NW11', 'Empty', 0)], $locations, $shipments);
check('a car with no STS location says so', $none['skipped'][0]['reason'] === 'no location in STS');
check('scale car goes to S-4 like the 2026-11-02 build', jmri_ops_map_location($map, $locations, 5) === ['location' => 'South Yard', 'track' => 'S-4']);

$e = jmri_ops_build_batch($map, [$car('NW9', 'Empty', 106, '003-E02', 108)], $locations, $shipments);
check('E-waybill final destination is the location id in the order', $e['cars'][0]['final_location'] === 'Aristech Plastics'
    && $e['cars'][0]['final_track'] === 'Pellet Unload');
$same = jmri_ops_build_batch($map, [$car('NW10', 'Empty', 108, '003-E03', 108)], $locations, $shipments);
check('final destination equal to the current spot is cleared', $same['cars'][0]['final_location'] === '');

$p = ['preview' => 'YES', 'save' => 'maybe'];
jmri_ops_normalize_params($p);
check('params normalise to yes/no', $p === ['preview' => 'yes', 'save' => 'yes']);
check('workflow import text is recognised', jmri_ops_guess_send('Send Cars to JMRI (preview)') === 'send_cars_to_jmri');
$m = [];
jmri_ops_dispatch_log_messages(['jmri_sync' => ['ok' => false, 'error' => 'No JMRI web server']], $m);
check('a failed sync logs why', strpos($m[0] ?? '', 'No JMRI web server') !== false);

putenv('STS_CONFIG_DIR=' . sys_get_temp_dir() . '/jmri_ops_none');
putenv('STS_JMRI_URL');
check('no url means off', jmri_ops_config()['url'] === '');
putenv('STS_JMRI_URL=http://example:12080/');
check('STS_JMRI_URL wins and loses its trailing slash', jmri_ops_config()['url'] === 'http://example:12080');

// Round trip against the fake JMRI.
$port = 18000 + random_int(0, 999);
$state = sys_get_temp_dir() . '/fake_jmri_' . $port . '.json';
@unlink($state);
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, __DIR__ . '/fake_jmri.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['FAKE_JMRI_STATE' => $state]);
usleep(400000);
$cfg = ['url' => 'http://127.0.0.1:' . $port, 'timeout' => 5, 'map' => 'hart'];
try {
    jmri_ops_send($cfg, [['car' => 'CO2052']], true);
    check('send refuses when the listener is not running', false);
} catch (RuntimeException $ex) {
    check('send refuses when the listener is not running', strpos($ex->getMessage(), 'not running') !== false);
}
file_put_contents($state, json_encode([JMRI_OPS_READY => '1']));
$reply = jmri_ops_send($cfg, [['car' => 'CO2052'], ['car' => 'BO8300']], true);
check('reply with the matching id comes back', ($reply['summary']['changed'] ?? 0) === 2 && $reply['dry_run'] === true);
$sent = json_decode(json_decode((string) file_get_contents($state), true)[JMRI_OPS_REQUEST], true);
check('the batch reached the request memory', count($sent['cars']) === 2 && $sent['dry_run'] === true && $sent['save'] === true);
proc_terminate($server);
try {
    jmri_ops_send($cfg, [], false);
    check('an unreachable JMRI throws', false);
} catch (RuntimeException $ex) {
    check('an unreachable JMRI throws', strpos($ex->getMessage(), 'No JMRI web server') !== false);
}
@unlink($state);

echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
