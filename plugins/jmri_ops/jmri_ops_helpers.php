<?php
/**
 * Send STS car positions, loads and final destinations to JMRI Operations Pro.
 *
 * STS is the master. The batch goes to JMRI through a Memory that the
 * sts_car_sync.py Jython script listens to, because JMRI's JSON server cannot
 * set a load or a final destination itself. Everything layout-specific (the
 * JMRI address and the STS-to-JMRI names) is in the settings, not here.
 */

const JMRI_OPS_REQUEST = 'IMSTS_CAR_SYNC_REQUEST';
const JMRI_OPS_REPLY = 'IMSTS_CAR_SYNC_REPLY';
const JMRI_OPS_READY = 'IMSTS_CAR_SYNC_READY';

/** Where the settings live: the STS config volume (/var/lib/sts), so each install has its own. */
function jmri_ops_settings_path()
{
    $dir = getenv('STS_CONFIG_DIR') ?: '/var/lib/sts';

    return rtrim($dir, '/') . '/jmri_ops.json';
}

/**
 * Nothing layout-specific: an empty map, and no JMRI url, which leaves the plugin off.
 *
 * connection.url      JMRI web server, e.g. http://host:12080
 * connection.timeout  seconds to wait for JMRI's answer
 * connection.save     JMRI saves Operations after a send that changed something
 * map.locations       STS location code => {location, track}; a blank track lets JMRI pick
 * map.stations        STS station name => {location, track}, for locations not listed above
 * map.loads           STS commodity code => JMRI load name
 * map.skip_status     STS car statuses that are never sent
 */
function jmri_ops_default_settings()
{
    return [
        'version' => 1,
        'connection' => ['url' => '', 'timeout' => 30, 'save' => true],
        'map' => ['locations' => [], 'stations' => [], 'loads' => [], 'skip_status' => ['Unavailable']],
    ];
}

/** @return array{0: array, 1: list<string>} settings with defaults filled in, and what was wrong */
function jmri_ops_normalize_settings($raw)
{
    $errors = [];
    $out = jmri_ops_default_settings();
    if (!is_array($raw)) {
        return [$out, ['settings must be a JSON object']];
    }
    $connection = is_array($raw['connection'] ?? null) ? $raw['connection'] : [];
    $url = rtrim(trim((string) ($connection['url'] ?? '')), '/');
    if ($url !== '' && !preg_match('#^https?://[^/\s]+#i', $url)) {
        $errors[] = 'JMRI url must start with http:// or https://';
        $url = '';
    }
    $out['connection']['url'] = $url;
    $timeout = (int) ($connection['timeout'] ?? 30);
    if ($timeout < 5 || $timeout > 300) {
        $errors[] = 'timeout must be 5 to 300 seconds';
        $timeout = max(5, min(300, $timeout));
    }
    $out['connection']['timeout'] = $timeout;
    $out['connection']['save'] = filter_var($connection['save'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

    $map = is_array($raw['map'] ?? null) ? $raw['map'] : [];
    foreach (['locations', 'stations'] as $key) {
        foreach ((is_array($map[$key] ?? null) ? $map[$key] : []) as $sts => $hit) {
            $location = trim((string) (is_array($hit) ? ($hit['location'] ?? '') : $hit));
            $track = trim((string) (is_array($hit) ? ($hit['track'] ?? '') : ''));
            $sts = trim((string) $sts);
            if ($sts === '' || $location === '') {
                if ($sts !== '' && $track !== '') {
                    $errors[] = $key . ' ' . $sts . ': a track needs a location';
                }
                continue;
            }
            $out['map'][$key][$sts] = $track === '' ? ['location' => $location] : ['location' => $location, 'track' => $track];
        }
        ksort($out['map'][$key]);
    }
    foreach ((is_array($map['loads'] ?? null) ? $map['loads'] : []) as $commodity => $load) {
        $commodity = trim((string) $commodity);
        $load = trim((string) $load);
        if ($commodity !== '' && $load !== '') {
            $out['map']['loads'][$commodity] = $load;
        }
    }
    ksort($out['map']['loads']);
    if (array_key_exists('skip_status', $map)) {
        $out['map']['skip_status'] = array_values(array_unique(array_filter(array_map(function ($v) {
            return trim((string) $v);
        }, is_array($map['skip_status']) ? $map['skip_status'] : []), 'strlen')));
    }

    return [$out, $errors];
}

/** The saved settings (defaults when there is no file), and any problem reading them. */
function jmri_ops_read_settings()
{
    $path = jmri_ops_settings_path();
    if (!is_file($path)) {
        return [jmri_ops_default_settings(), []];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        return [jmri_ops_default_settings(), [basename($path) . ' is not valid JSON; using defaults']];
    }

    return jmri_ops_normalize_settings($raw);
}

/**
 * Check and save. The previous file is kept as jmri_ops.json.bak.
 *
 * @return list<string> problems; empty means saved
 */
function jmri_ops_write_settings($raw)
{
    [$settings, $errors] = jmri_ops_normalize_settings($raw);
    if ($errors !== []) {
        return $errors;
    }
    $path = jmri_ops_settings_path();
    $text = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (is_file($path)) {
        @copy($path, $path . '.bak');
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $text) === false || !@rename($tmp, $path)) {
        @unlink($tmp);

        return ['could not write ' . $path];
    }

    return [];
}

/** What the transport needs. STS_JMRI_URL, when set, wins over the saved url. */
function jmri_ops_config()
{
    [$settings] = jmri_ops_read_settings();
    $config = $settings['connection'];
    $config['url_from_env'] = false;
    $env = getenv('STS_JMRI_URL');
    if ($env !== false && trim($env) !== '') {
        $config['url'] = rtrim(trim($env), '/');
        $config['url_from_env'] = true;
    }

    return $config;
}

function jmri_ops_load_map()
{
    [$settings] = jmri_ops_read_settings();

    return $settings['map'];
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

/**
 * STS's names for the settings screen: every location (with how many cars are there),
 * station, commodity and car status.
 */
function jmri_ops_read_sts_names($dbc)
{
    $out = ['locations' => [], 'stations' => [], 'commodities' => [], 'statuses' => []];
    $res = mysqli_query($dbc, 'SELECT l.code AS code, r.station AS station, l.track AS track, l.spot AS spot,
            (SELECT COUNT(*) FROM cars c WHERE c.current_location_id = l.Id) AS cars
        FROM locations l LEFT JOIN routing r ON r.id = l.station
        ORDER BY r.station, l.code');
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $station = (string) $row['station'];
        $out['locations'][] = ['code' => (string) $row['code'], 'station' => $station,
            'track' => trim((string) $row['track'] . ' ' . (string) $row['spot']), 'cars' => (int) $row['cars']];
        $out['stations'][$station] = ($out['stations'][$station] ?? 0) + (int) $row['cars'];
    }
    $res = mysqli_query($dbc, 'SELECT Code AS code, Description AS description FROM commodities ORDER BY Code');
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out['commodities'][(string) $row['code']] = (string) $row['description'];
    }
    $res = mysqli_query($dbc, 'SELECT status, COUNT(*) AS n FROM cars GROUP BY status ORDER BY status');
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out['statuses'][(string) $row['status']] = (int) $row['n'];
    }

    return $out;
}

/** Turn the settings form into raw settings (checked by jmri_ops_normalize_settings). */
function jmri_ops_settings_from_form(array $post)
{
    $pairs = function ($locations, $tracks) {
        $out = [];
        foreach ((is_array($locations) ? $locations : []) as $key => $location) {
            $out[(string) $key] = ['location' => (string) $location, 'track' => (string) (($tracks[$key] ?? '') ?: '')];
        }

        return $out;
    };

    return [
        'version' => 1,
        'connection' => [
            'url' => (string) ($post['url'] ?? ''),
            'timeout' => (int) ($post['timeout'] ?? 30),
            'save' => !empty($post['save']),
        ],
        'map' => [
            'locations' => $pairs($post['loc_location'] ?? [], is_array($post['loc_track'] ?? null) ? $post['loc_track'] : []),
            'stations' => $pairs($post['st_location'] ?? [], is_array($post['st_track'] ?? null) ? $post['st_track'] : []),
            'loads' => is_array($post['load'] ?? null) ? $post['load'] : [],
            'skip_status' => is_array($post['skip'] ?? null) ? array_values($post['skip']) : [],
        ],
    ];
}

/**
 * Names in the map that JMRI does not have, as "kind key" => message.
 *
 * @param array $names jmri_ops_jmri_names()
 */
function jmri_ops_unknown_names(array $map, array $names)
{
    $bad = [];
    foreach (['locations' => 'loc', 'stations' => 'st'] as $key => $prefix) {
        foreach ($map[$key] as $sts => $hit) {
            if (!array_key_exists($hit['location'], $names['locations'])) {
                $bad[$prefix . ' ' . $sts] = 'JMRI has no location ' . $hit['location'];
            } elseif (($hit['track'] ?? '') !== '' && !in_array($hit['track'], $names['locations'][$hit['location']], true)) {
                $bad[$prefix . ' ' . $sts] = $hit['location'] . ' has no track ' . $hit['track'];
            }
        }
    }
    foreach ($map['loads'] as $commodity => $load) {
        if (!in_array($load, $names['loads'], true)) {
            $bad['load ' . $commodity] = 'JMRI has no load ' . $load;
        }
    }

    return $bad;
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
 * Put one request in the listener's Memory and wait for the answer with the same id.
 * Throws on any transport problem, or when the listener says it failed.
 *
 * @param int $min_version lowest listener version that understands this request
 */
function jmri_ops_ask(array $config, array $request, $min_version = 1)
{
    if ($config['url'] === '') {
        throw new RuntimeException('No JMRI url set; see Settings');
    }
    $version = jmri_ops_listener_version($config);
    if ($version === null) {
        throw new RuntimeException('sts_car_sync.py is not running in JMRI at ' . $config['url']
            . '; add it to Start Up and restart JMRI');
    }
    if ((int) $version < $min_version) {
        throw new RuntimeException('sts_car_sync.py in JMRI is version ' . $version . '; version '
            . $min_version . ' or later is needed. Deploy it and restart JMRI');
    }
    $id = bin2hex(random_bytes(8));
    $request = ['id' => $id] + $request;
    [$code, , $error] = jmri_ops_http($config['url'] . '/json/memory/' . JMRI_OPS_REQUEST, 'POST',
        ['value' => json_encode($request)]);
    if ($error !== '' || $code >= 400 || $code === 0) {
        throw new RuntimeException('JMRI refused the request: ' . ($error !== '' ? $error : 'HTTP ' . $code));
    }
    $deadline = microtime(true) + $config['timeout'];
    while (microtime(true) < $deadline) {
        usleep(250000);
        $value = jmri_ops_memory($config['url'], JMRI_OPS_REPLY);
        $reply = is_string($value) ? json_decode($value, true) : null;
        if (is_array($reply) && ($reply['id'] ?? null) === $id) {
            if (empty($reply['ok'])) {
                throw new RuntimeException('JMRI could not do it: ' . ($reply['error'] ?? 'unknown error'));
            }

            return $reply;
        }
    }
    throw new RuntimeException('JMRI did not answer within ' . $config['timeout'] . ' s');
}

/** @return array the listener's reply: ok, dry_run, saved, summary, cars */
function jmri_ops_send(array $config, array $cars, $dry_run)
{
    return jmri_ops_ask($config, ['dry_run' => (bool) $dry_run, 'save' => (bool) $config['save'], 'cars' => $cars]);
}

/** JMRI's names for the settings screen: ['locations' => name => [tracks], 'loads' => [...], 'empty' => 'E']. */
function jmri_ops_jmri_names(array $config)
{
    $reply = jmri_ops_ask($config, ['command' => 'names'], 2);

    return [
        'locations' => is_array($reply['locations'] ?? null) ? $reply['locations'] : [],
        'loads' => is_array($reply['loads'] ?? null) ? $reply['loads'] : [],
        'empty' => (string) ($reply['empty'] ?? ''),
    ];
}

/**
 * Build and send in one go; the shape the workflow step and the page share.
 *
 * Never throws: a missing or unreachable JMRI is reported, so a session is not stopped by it.
 */
function jmri_ops_sync($dbc, $dry_run)
{
    $config = jmri_ops_config();
    $result = ['url' => $config['url'], 'dry_run' => (bool) $dry_run];
    try {
        [$cars, $locations, $shipments] = jmri_ops_read_rows($dbc);
        $batch = jmri_ops_build_batch(jmri_ops_load_map(), $cars, $locations, $shipments);
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
        return $result + ['ok' => false, 'error' => 'JMRI sync is off: no JMRI url in Settings'];
    }
    if ($batch['cars'] === []) {
        return $result + ['ok' => false, 'error' => 'no car has a JMRI location; fill in the map in Settings'];
    }
    try {
        $reply = jmri_ops_send($config, $batch['cars'], $dry_run);
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
