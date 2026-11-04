<?php
/**
 * php plugins/jmri_ops/tests/test_jmri_ops.php
 *
 * Settings, batch building on a made-up layout, and the HTTP round trip against
 * a fake JMRI (php -S with tests/fake_jmri.php). Exits non-zero on a failure.
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

$tmp = sys_get_temp_dir() . '/jmri_ops_test_' . getmypid();
@mkdir($tmp);
putenv('STS_CONFIG_DIR=' . $tmp);
putenv('STS_JMRI_URL');

// Settings.
[$defaults] = jmri_ops_read_settings();
check('no settings file means off with an empty map', $defaults['connection']['url'] === ''
    && $defaults['map']['locations'] === [] && $defaults['map']['loads'] === []);
check('defaults never send Unavailable cars', $defaults['map']['skip_status'] === ['Unavailable']);
[$n, $errors] = jmri_ops_normalize_settings([
    'connection' => ['url' => ' http://jmri:12080/ ', 'timeout' => '2', 'save' => 'no'],
    'map' => [
        'locations' => ['B-YARD' => ['location' => ' Bay Yard ', 'track' => ''], 'GONE' => ['location' => '', 'track' => '']],
        'stations' => ['Far Away' => ['location' => 'Offline', 'track' => 'Out']],
        'loads' => ['GRAIN' => ' Grain ', 'NONE' => ''],
        'skip_status' => ['Unavailable', ' ', 'Unavailable', 'Bad Order'],
    ],
]);
check('url trimmed and its slash dropped', $n['connection']['url'] === 'http://jmri:12080');
check('timeout out of range is clamped and reported', $n['connection']['timeout'] === 5 && count($errors) === 1);
check('save accepts yes/no words', $n['connection']['save'] === false);
check('blank rows are dropped and names trimmed', $n['map']['locations'] === ['B-YARD' => ['location' => 'Bay Yard']]
    && $n['map']['loads'] === ['GRAIN' => 'Grain']);
check('skip statuses are de-duplicated', $n['map']['skip_status'] === ['Unavailable', 'Bad Order']);
[, $errors] = jmri_ops_normalize_settings(['connection' => ['url' => 'jmri:12080']]);
check('a url without http is refused', $errors !== []);
[, $errors] = jmri_ops_normalize_settings(['map' => ['locations' => ['X' => ['location' => '', 'track' => 'T']]]]);
check('a track without a location is refused', $errors !== []);
check('writing bad settings is refused and writes nothing', jmri_ops_write_settings(['connection' => ['url' => 'nope']]) !== []
    && !is_file(jmri_ops_settings_path()));
check('writing good settings works', jmri_ops_write_settings(['connection' => ['url' => 'http://jmri:12080', 'timeout' => 30]]) === []
    && jmri_ops_config()['url'] === 'http://jmri:12080');
jmri_ops_write_settings(['connection' => ['url' => 'http://jmri2:12080']]);
check('saving again keeps the previous file as .bak', is_file(jmri_ops_settings_path() . '.bak')
    && strpos((string) file_get_contents(jmri_ops_settings_path() . '.bak'), 'jmri:12080') !== false);
putenv('STS_JMRI_URL=http://example:12080/');
check('STS_JMRI_URL wins and says so', jmri_ops_config()['url'] === 'http://example:12080' && jmri_ops_config()['url_from_env']);
putenv('STS_JMRI_URL');

$form = jmri_ops_settings_from_form([
    'url' => 'http://jmri:12080', 'timeout' => '40', 'save' => '1',
    'loc_location' => ['B-YARD' => 'Bay Yard', 'MILL' => 'Mill'], 'loc_track' => ['MILL' => 'Dock'],
    'st_location' => ['Far Away' => 'Offline'], 'st_track' => ['Far Away' => ''],
    'load' => ['GRAIN' => 'Grain'], 'skip' => ['Unavailable'],
]);
[$f, $errors] = jmri_ops_normalize_settings($form);
check('the settings form becomes settings', $errors === [] && $f['connection']['timeout'] === 40 && $f['connection']['save'] === true
    && $f['map']['locations']['MILL'] === ['location' => 'Mill', 'track' => 'Dock'] && $f['map']['stations']['Far Away'] === ['location' => 'Offline']);
$f2 = jmri_ops_settings_from_form(['url' => '']);
check('an unchecked save box means no save', $f2['connection']['save'] === false);

$names = ['locations' => ['Bay Yard' => ['Y-1'], 'Mill' => ['Dock'], 'Offline' => ['Out']], 'loads' => ['E', 'Grain']];
$bad = jmri_ops_unknown_names(['locations' => ['B-YARD' => ['location' => 'Bay Yard', 'track' => 'Y-9'], 'X' => ['location' => 'Nowhere']],
    'stations' => ['Far Away' => ['location' => 'Offline']], 'loads' => ['GRAIN' => 'Grain', 'COAL' => 'Coal']], $names);
check('names JMRI lacks are found', array_keys($bad) === ['loc B-YARD', 'loc X', 'load COAL']);

// Batch building on a made-up layout.
$map = jmri_ops_normalize_settings(['map' => [
    'locations' => [
        'BAY-YARD' => ['location' => 'Bay Yard'],
        'BAY-SCALE' => ['location' => 'Bay Yard', 'track' => 'Y-4'],
        'MILL-DOCK' => ['location' => 'Mill', 'track' => 'Dock'],
        'MILL-TEAM' => ['location' => 'Mill', 'track' => 'Team'],
    ],
    'stations' => ['East City' => ['location' => 'East Staging', 'track' => 'E-1'], 'West City' => ['location' => 'West Staging']],
    'loads' => ['GRAIN' => 'Grain', 'STEEL' => 'Steel'],
]])[0]['map'];
$locations = [
    4 => ['code' => 'BAY-YARD', 'station' => 'Bay'],
    5 => ['code' => 'BAY-SCALE', 'station' => 'Bay'],
    1 => ['code' => 'NOWHERE-YARD', 'station' => 'Nowhere'],
    106 => ['code' => 'MILL-TEAM', 'station' => 'Mill'],
    108 => ['code' => 'MILL-DOCK', 'station' => 'Mill'],
    45 => ['code' => 'FARM-CO', 'station' => 'East City'],
    72 => ['code' => 'BAKERY', 'station' => 'West City'],
];
$shipments = [
    1 => ['code' => 'MILL-GRAIN-IN', 'commodity' => 'GRAIN', 'loading_location' => 45, 'unloading_location' => 108],
    2 => ['code' => 'IX-STEEL', 'commodity' => 'STEEL', 'loading_location' => 99, 'unloading_location' => 72],
    3 => ['code' => 'NEW-THING', 'commodity' => 'UNOBTAINIUM', 'loading_location' => 45, 'unloading_location' => 999],
];
$car = function ($marks, $status, $loc, $waybill = '', $shipment = 0) {
    return ['id' => 1, 'reporting_marks' => $marks, 'status' => $status, 'current_location_id' => $loc,
        'waybill' => $waybill, 'order_shipment' => $shipment];
};
$batch = jmri_ops_build_batch($map, [
    $car('AA1', 'Loaded', 45, '001-001', 1),
    $car('AA2', 'Loaded', 4, '001-006', 2),
    $car('AA3', 'Ordered', 4, '002-001', 1),
    $car('AA4', 'Empty', 106, '003-E01', 4),
    $car('AA5', 'Empty', 108),
    $car('AA6', 'Unloading', 108, '004-001', 1),
    $car('AA7', 'Unavailable', 4),
    $car('AA8', 'Empty', 1),
    $car('AA9', 'Loaded', 4, '005-001', 3),
    $car('AA10', 'Loaded', 106, '', 0),
    $car('AA11', 'Empty', 0),
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

check('a location with no row of its own maps through its station', $by['AA1']['location'] === 'East Staging' && $by['AA1']['track'] === 'E-1');
check('a loaded car gets the commodity load and its unloading track', $by['AA1']['load'] === 'Grain'
    && $by['AA1']['final_location'] === 'Mill' && $by['AA1']['final_track'] === 'Dock');
check('a location mapped without a track sends a blank track', $by['AA2']['location'] === 'Bay Yard' && $by['AA2']['track'] === '');
check('a station row without a track sends a blank track', $by['AA2']['final_location'] === 'West Staging' && $by['AA2']['final_track'] === '');
check('an ordered empty heads for its loading location', $by['AA3']['load'] === '' && $by['AA3']['final_location'] === 'East Staging');
check('an E-waybill empty heads for the location in its order', $by['AA4']['final_location'] === 'Bay Yard');
check('E-waybills are told apart by their number', jmri_ops_is_e_waybill('003-E01') && !jmri_ops_is_e_waybill('003-001'));
check('an empty with no order has no final destination', $by['AA5']['load'] === '' && $by['AA5']['final_location'] === '');
check('an unloading car keeps its load and clears the final destination', $by['AA6']['load'] === 'Grain' && $by['AA6']['final_location'] === '');
check('skipped statuses are not sent', isset($why['AA7']) && !isset($by['AA7']));
check('an unmapped STS location is not sent and says why', strpos($why['AA8'] ?? '', 'NOWHERE-YARD') !== false);
check('a car with no STS location says so', ($why['AA11'] ?? '') === 'no location in STS');
check('an unknown commodity goes as itself with a note', $by['AA9']['load'] === 'UNOBTAINIUM' && strpos($notes['AA9'], 'UNOBTAINIUM') !== false);
check('an unmapped destination is cleared with a note', $by['AA9']['final_location'] === '' && strpos($notes['AA9'], 'destination') !== false);
check('loaded with no order goes as empty with a note', $by['AA10']['load'] === '' && isset($notes['AA10']));
$same = jmri_ops_build_batch($map, [$car('AA12', 'Empty', 108, '003-E03', 108)], $locations, $shipments);
check('a final destination equal to the current track is cleared', $same['cars'][0]['final_location'] === '');
$empty = jmri_ops_build_batch(jmri_ops_default_settings()['map'], [$car('AA13', 'Empty', 4)], $locations, $shipments);
check('with the default empty map nothing is sent', $empty['cars'] === [] && count($empty['skipped']) === 1);

// Step plumbing.
$p = ['preview' => 'YES'];
jmri_ops_normalize_params($p);
check('the preview param normalises to yes/no', $p === ['preview' => 'yes']);
check('workflow import text is recognised', jmri_ops_guess_send('Send Cars to JMRI (preview)') === 'send_cars_to_jmri');
$m = [];
jmri_ops_dispatch_log_messages(['jmri_sync' => ['ok' => false, 'error' => 'No JMRI web server']], $m);
check('a failed sync logs why', strpos($m[0] ?? '', 'No JMRI web server') !== false);

// Round trip against the fake JMRI.
$port = 18000 + random_int(0, 999);
$state = $tmp . '/fake_jmri.json';
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, __DIR__ . '/fake_jmri.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['FAKE_JMRI_STATE' => $state]);
usleep(400000);
$cfg = ['url' => 'http://127.0.0.1:' . $port, 'timeout' => 5, 'save' => true];
try {
    jmri_ops_send($cfg, [['car' => 'AA1']], true);
    check('send refuses when the listener is not running', false);
} catch (RuntimeException $ex) {
    check('send refuses when the listener is not running', strpos($ex->getMessage(), 'not running') !== false);
}
file_put_contents($state, json_encode([JMRI_OPS_READY => '1']));
try {
    jmri_ops_jmri_names($cfg);
    check('names needs listener version 2', false);
} catch (RuntimeException $ex) {
    check('names needs listener version 2', strpos($ex->getMessage(), 'version 2') !== false);
}
$reply = jmri_ops_send($cfg, [['car' => 'AA1'], ['car' => 'AA2']], true);
check('the reply with the matching id comes back', ($reply['summary']['changed'] ?? 0) === 2 && $reply['dry_run'] === true);
$sent = json_decode(json_decode((string) file_get_contents($state), true)[JMRI_OPS_REQUEST], true);
check('the batch reached the request memory with the save setting', count($sent['cars']) === 2 && $sent['save'] === true);
$s = json_decode((string) file_get_contents($state), true);
$s[JMRI_OPS_READY] = '2';
file_put_contents($state, json_encode($s));
$got = jmri_ops_jmri_names($cfg);
check('names come back from a version 2 listener', $got['locations'] === ['Bay Yard' => ['Y-1', 'Y-2']] && $got['loads'] === ['E', 'Grain']);
proc_terminate($server);
try {
    jmri_ops_send($cfg, [], false);
    check('an unreachable JMRI throws', false);
} catch (RuntimeException $ex) {
    check('an unreachable JMRI throws', strpos($ex->getMessage(), 'No JMRI web server') !== false);
}
foreach (array_merge(glob($tmp . '/*') ?: [], glob($tmp . '/.*.bak') ?: []) as $file) {
    @unlink($file);
}
@rmdir($tmp);

echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
