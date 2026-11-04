<?php
/**
 * A stand-in for JMRI's JSON memory endpoints, for test_jmri_ops.php (php -S router).
 * A POST to the request memory answers at once in the reply memory, as the listener would.
 */
$state_file = getenv('FAKE_JMRI_STATE');
$state = is_file($state_file) ? (json_decode((string) file_get_contents($state_file), true) ?: []) : [];
header('Content-Type: application/json');
if (!preg_match('#^/json/memory/([A-Za-z0-9_]+)$#', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
    http_response_code(404);
    echo '{}';
    return true;
}
$name = $m[1];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $value = json_decode((string) file_get_contents('php://input'), true)['value'] ?? null;
    $state[$name] = $value;
    if ($name === 'IMSTS_CAR_SYNC_REQUEST') {
        $batch = json_decode((string) $value, true);
        if (($batch['command'] ?? '') === 'names') {
            $state['IMSTS_CAR_SYNC_REPLY'] = json_encode(['id' => $batch['id'], 'ok' => true,
                'locations' => ['Bay Yard' => ['Y-1', 'Y-2']], 'loads' => ['E', 'Grain'], 'empty' => 'E']);
            file_put_contents($state_file, json_encode($state));
            echo json_encode(['type' => 'memory', 'data' => ['name' => $name, 'value' => $value]]);
            return true;
        }
        $cars = array_map(function ($c) {
            return ['car' => $c['car'], 'status' => 'changed', 'changes' => ['x'], 'notes' => []];
        }, $batch['cars']);
        $state['IMSTS_CAR_SYNC_REPLY'] = json_encode(['id' => $batch['id'], 'ok' => true, 'dry_run' => $batch['dry_run'],
            'saved' => false, 'summary' => ['changed' => count($cars)], 'cars' => $cars]);
    }
    file_put_contents($state_file, json_encode($state));
}
if (!array_key_exists($name, $state)) {
    http_response_code(404);
    echo '{"type":"error"}';
    return true;
}
echo json_encode(['type' => 'memory', 'data' => ['name' => $name, 'value' => $state[$name]]]);
return true;
