<?php
/**
 * Send STS car positions, loads and final destinations to JMRI Operations Pro.
 *
 * STS is the master. The batch goes to JMRI through a Memory that the
 * sts_car_sync.py Jython script (hart repo) listens to, because JMRI's JSON
 * server cannot set a load or a final destination itself.
 */

const JMRI_OPS_REQUEST = 'IMSTS_CAR_SYNC_REQUEST';
const JMRI_OPS_REPLY = 'IMSTS_CAR_SYNC_REPLY';
const JMRI_OPS_READY = 'IMSTS_CAR_SYNC_READY';

/** Where the per-install settings live: the STS config volume (/var/lib/sts). */
function jmri_ops_config_path()
{
    $dir = getenv('STS_CONFIG_DIR') ?: '/var/lib/sts';

    return rtrim($dir, '/') . '/jmri_ops.json';
}

/**
 * Settings: url (JMRI web server, e.g. http://host:12080), map (a file in maps/
 * without .json, or an absolute path), timeout in seconds. STS_JMRI_URL wins
 * over the file. No url means the plugin is switched off.
 */
function jmri_ops_config()
{
    $config = ['url' => '', 'map' => 'hart', 'timeout' => 30];
    $path = jmri_ops_config_path();
    if (is_file($path)) {
        $file = json_decode((string) file_get_contents($path), true);
        if (is_array($file)) {
            $config = array_merge($config, $file);
        }
    }
    $env = getenv('STS_JMRI_URL');
    if ($env !== false && trim($env) !== '') {
        $config['url'] = trim($env);
    }
    $config['url'] = rtrim(trim((string) $config['url']), '/');
    $config['timeout'] = max(5, (int) $config['timeout']);

    return $config;
}

function jmri_ops_map_path($name)
{
    $name = (string) $name;
    if ($name !== '' && $name[0] === '/') {
        return $name;
    }

    return __DIR__ . '/maps/' . basename($name) . '.json';
}

/** The STS-to-JMRI name map, or throws when it cannot be read. */
function jmri_ops_load_map($name)
{
    $path = jmri_ops_map_path($name);
    $map = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($map)) {
        throw new RuntimeException('JMRI name map not readable: ' . $path);
    }
    foreach (['locations', 'stations', 'loads'] as $key) {
        $map[$key] = is_array($map[$key] ?? null) ? $map[$key] : [];
    }
    $map['skip_status'] = is_array($map['skip_status'] ?? null) ? $map['skip_status'] : ['Unavailable'];

    return $map;
}

/** One STS location id as ['location' => JMRI name, 'track' => name or ''], or null. */
function jmri_ops_map_location(array $map, array $locations, $location_id)
{
    $row = $locations[(int) $location_id] ?? null;
    if ($row === null) {
        return null;
    }
    $hit = $map['locations'][$row['code']] ?? $map['stations'][$row['station']] ?? null;
    if (!is_array($hit) || trim((string) ($hit['location'] ?? '')) === '') {
        return null;
    }

    return ['location' => (string) $hit['location'], 'track' => (string) ($hit['track'] ?? '')];
}

/** True when a car order's waybill is an empty-car E-waybill (its shipment column holds a location id). */
function jmri_ops_is_e_waybill($waybill)
{
    return (bool) preg_match('/-E\d+$/i', (string) $waybill);
}

/**
 * Build the batch from plain rows, so it can be tested without a database.
 *
 * $cars:      list of [id, reporting_marks, status, current_location_id, waybill, order_shipment]
 * $locations: id => [code, station]
 * $shipments: id => [code, commodity, loading_location, unloading_location]
 *
 * Returns ['cars' => list for JMRI, 'skipped' => list of [car, reason], 'notes' => list of [car, note]].
 */
function jmri_ops_build_batch(array $map, array $cars, array $locations, array $shipments)
{
    $out = ['cars' => [], 'skipped' => [], 'notes' => []];
    foreach ($cars as $car) {
        $id = trim((string) $car['reporting_marks']);
        $status = (string) $car['status'];
        if (in_array($status, $map['skip_status'], true)) {
            $out['skipped'][] = ['car' => $id, 'reason' => $status . ' in STS'];
            continue;
        }
        $here = jmri_ops_map_location($map, $locations, $car['current_location_id']);
        if ($here === null) {
            $code = $locations[(int) $car['current_location_id']]['code'] ?? null;
            $out['skipped'][] = ['car' => $id, 'reason' => $code === null
                ? 'no location in STS'
                : 'STS location ' . $code . ' has no JMRI location in the map'];
            continue;
        }

        $waybill = (string) ($car['waybill'] ?? '');
        $order = (int) ($car['order_shipment'] ?? 0);
        $shipment = ($waybill !== '' && !jmri_ops_is_e_waybill($waybill)) ? ($shipments[$order] ?? null) : null;
        $load = '';
        $final_id = 0;
        if ($status === 'Loaded' || $status === 'Unloading') {
            if ($shipment === null) {
                $out['notes'][] = ['car' => $id, 'note' => $status . ' with no shipment; sent as empty'];
            } else {
                $commodity = (string) $shipment['commodity'];
                $load = (string) ($map['loads'][$commodity] ?? '');
                if ($load === '') {
                    $load = $commodity;
                    $out['notes'][] = ['car' => $id, 'note' => 'commodity ' . $commodity . ' has no JMRI load in the map'];
                }
                if ($status === 'Loaded') {
                    $final_id = (int) $shipment['unloading_location'];
                }
            }
        } elseif ($status === 'Ordered' && $shipment !== null) {
            $final_id = (int) $shipment['loading_location'];
        } elseif ($status === 'Empty' && $waybill !== '' && jmri_ops_is_e_waybill($waybill)) {
            $final_id = $order;
        }

        $final = ['location' => '', 'track' => ''];
        if ($final_id > 0) {
            $hit = jmri_ops_map_location($map, $locations, $final_id);
            if ($hit === null) {
                $code = $locations[$final_id]['code'] ?? ('#' . $final_id);
                $out['notes'][] = ['car' => $id, 'note' => 'destination ' . $code . ' has no JMRI location; final destination cleared'];
            } elseif ($hit['location'] !== $here['location'] || $hit['track'] !== $here['track']) {
                $final = $hit;
            }
        }

        $out['cars'][] = [
            'car' => $id,
            'location' => $here['location'],
            'track' => $here['track'],
            'load' => $load,
            'final_location' => $final['location'],
            'final_track' => $final['track'],
        ];
    }

    return $out;
}

/** Read the rows jmri_ops_build_batch() needs. */
function jmri_ops_read_rows($dbc)
{
    $locations = [];
    $res = mysqli_query($dbc, 'SELECT l.Id AS id, l.code AS code, r.station AS station FROM locations l LEFT JOIN routing r ON r.id = l.station');
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $locations[(int) $row['id']] = ['code' => (string) $row['code'], 'station' => (string) $row['station']];
    }
    $shipments = [];
    $res = mysqli_query($dbc, 'SELECT s.Id AS id, s.code AS code, c.Code AS commodity, s.loading_location AS loading_location,
            s.unloading_location AS unloading_location
        FROM shipments s LEFT JOIN commodities c ON c.Id = s.consignment');
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $shipments[(int) $row['id']] = [
            'code' => (string) $row['code'],
            'commodity' => (string) $row['commodity'],
            'loading_location' => (int) $row['loading_location'],
            'unloading_location' => (int) $row['unloading_location'],
        ];
    }
    $cars = [];
    $res = mysqli_query($dbc, 'SELECT c.Id AS id, c.reporting_marks AS reporting_marks, c.status AS status,
            c.current_location_id AS current_location_id,
            o.waybill_number AS waybill, o.shipment AS order_shipment
        FROM cars c LEFT JOIN car_orders o ON o.car = c.Id
        ORDER BY c.reporting_marks');
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $cars[] = $row;
    }

    return [$cars, $locations, $shipments];
}

/** One JMRI JSON request. Returns [http status, decoded body or null, error text]. */
function jmri_ops_http($url, $method = 'GET', $body = null, $timeout = 10)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $text = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = $text === false ? curl_error($ch) : '';
    curl_close($ch);

    return [$code, is_string($text) ? json_decode($text, true) : null, $error];
}

/** A Memory's value, null when the Memory does not exist, or throws when JMRI cannot be reached. */
function jmri_ops_memory($base, $name, $timeout = 10)
{
    [$code, $body, $error] = jmri_ops_http($base . '/json/memory/' . rawurlencode($name), 'GET', null, $timeout);
    if ($error !== '' || $code === 0) {
        throw new RuntimeException('No JMRI web server at ' . $base . ($error !== '' ? ' (' . $error . ')' : ''));
    }
    if ($code === 404) {
        return null;
    }
    if ($code >= 400) {
        throw new RuntimeException('JMRI answered ' . $code . ' for memory ' . $name);
    }

    return $body['data']['value'] ?? null;
}

/** The listener's version, or null when sts_car_sync.py is not running in JMRI. */
function jmri_ops_listener_version(array $config)
{
    return jmri_ops_memory($config['url'], JMRI_OPS_READY);
}

/**
 * Send a batch and wait for JMRI's answer. Throws on any transport problem.
 *
 * @return array the listener's reply: ok, dry_run, saved, summary, cars
 */
function jmri_ops_send(array $config, array $cars, $dry_run, $save = true)
{
    if ($config['url'] === '') {
        throw new RuntimeException('No JMRI URL set (jmri_ops.json or STS_JMRI_URL)');
    }
    if (jmri_ops_listener_version($config) === null) {
        throw new RuntimeException('sts_car_sync.py is not running in JMRI at ' . $config['url']
            . '; add it to Start Up and restart JMRI');
    }
    $id = bin2hex(random_bytes(8));
    $batch = ['id' => $id, 'dry_run' => (bool) $dry_run, 'save' => (bool) $save, 'cars' => $cars];
    [$code, , $error] = jmri_ops_http($config['url'] . '/json/memory/' . JMRI_OPS_REQUEST, 'POST',
        ['value' => json_encode($batch)]);
    if ($error !== '' || $code >= 400 || $code === 0) {
        throw new RuntimeException('JMRI refused the batch: ' . ($error !== '' ? $error : 'HTTP ' . $code));
    }
    $deadline = microtime(true) + $config['timeout'];
    while (microtime(true) < $deadline) {
        usleep(250000);
        $value = jmri_ops_memory($config['url'], JMRI_OPS_REPLY);
        $reply = is_string($value) ? json_decode($value, true) : null;
        if (is_array($reply) && ($reply['id'] ?? null) === $id) {
            if (empty($reply['ok'])) {
                throw new RuntimeException('JMRI could not apply the batch: ' . ($reply['error'] ?? 'unknown error'));
            }

            return $reply;
        }
    }
    throw new RuntimeException('JMRI did not answer within ' . $config['timeout'] . ' s');
}

/**
 * Build and send in one go; the shape the workflow step and the page share.
 *
 * Never throws: a missing or unreachable JMRI is reported, so a session is not stopped by it.
 */
function jmri_ops_sync($dbc, $dry_run, $save = true)
{
    $config = jmri_ops_config();
    $result = ['url' => $config['url'], 'dry_run' => (bool) $dry_run];
    try {
        $map = jmri_ops_load_map($config['map']);
        [$cars, $locations, $shipments] = jmri_ops_read_rows($dbc);
        $batch = jmri_ops_build_batch($map, $cars, $locations, $shipments);
    } catch (Throwable $e) {
        return $result + ['ok' => false, 'error' => $e->getMessage()];
    }
    $result += [
        'cars_sent' => count($batch['cars']),
        'not_sent' => $batch['skipped'],
        'notes' => $batch['notes'],
        'batch' => $batch['cars'],
    ];
    if ($config['url'] === '') {
        return $result + ['ok' => false, 'error' => 'JMRI sync is off: no url in ' . jmri_ops_config_path()];
    }
    try {
        $reply = jmri_ops_send($config, $batch['cars'], $dry_run, $save);
    } catch (Throwable $e) {
        return $result + ['ok' => false, 'error' => $e->getMessage()];
    }

    return $result + [
        'ok' => true,
        'saved' => !empty($reply['saved']),
        'summary' => $reply['summary'] ?? [],
        'jmri' => $reply['cars'] ?? [],
    ];
}
