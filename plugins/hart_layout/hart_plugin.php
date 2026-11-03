<?php
/**
 * HART layout plugin: manifest callbacks that are not plain moves from core.
 * (The moved warm-start functions live in hart_warm_start.php.)
 */

function hart_session_stats_blank()
{
    return [
        'NVL' => [
            'pu_scully' => 0,
            'so_south_1' => 0,
            'pu_south_1' => 0,
            'deliver_island' => 0,
            'pu_island' => 0,
            'so_south_2' => 0,
            'pu_south_2' => 0,
            'so_scully' => 0,
            '_seen_island' => false,
        ],
        'D749' => [
            'pu_demmler' => 0,
            'pu_south' => 0,
            'so_demmler' => 0,
        ],
        'CK1' => [
            'pu_south' => 0,
            'so_shenango' => 0,
            'pu_shenango' => 0,
            'weighed' => 0,
            'reloads' => 0,
            'outbound' => 0,
            'so_south' => 0,
        ],
    ];
}

function hart_record_job_pickup($dbc, $job_name, $location_id)
{
    if (!in_array($job_name, ['NVL', 'D749', 'CK1'], true)) {
        return;
    }

    $station = warm_start_location_station($dbc, $location_id);
    $s = &warm_start_session_stats_current();

    if ($job_name === 'NVL') {
        if ($station === 9) {
            $s['NVL']['pu_scully']++;
        } elseif ($station === 8) {
            if (!$s['NVL']['_seen_island']) {
                $s['NVL']['pu_south_1']++;
            } else {
                $s['NVL']['pu_south_2']++;
            }
        } elseif ($station === 3) {
            $s['NVL']['pu_island']++;
            $s['NVL']['_seen_island'] = true;
        } elseif ($station === 12) {
            $s['NVL']['_seen_island'] = true;
        }
        return;
    }

    if ($job_name === 'D749') {
        if ($station === 10) {
            $s['D749']['pu_demmler']++;
        } elseif ($station === 8) {
            $s['D749']['pu_south']++;
        }
        return;
    }

    if ($job_name === 'CK1') {
        if ($station === 8) {
            $s['CK1']['pu_south']++;
        } elseif ($station === 12) {
            $s['CK1']['pu_shenango']++;
        }
    }
}

function hart_record_job_setout($dbc, $job_name, $location_id)
{
    if (!in_array($job_name, ['NVL', 'D749', 'CK1'], true)) {
        return;
    }

    $station = warm_start_location_station($dbc, $location_id);
    $s = &warm_start_session_stats_current();

    if ($job_name === 'NVL') {
        if ($station === 9) {
            $s['NVL']['so_scully']++;
        } elseif ($station === 8) {
            if (!$s['NVL']['_seen_island']) {
                $s['NVL']['so_south_1']++;
            } else {
                $s['NVL']['so_south_2']++;
            }
        } elseif ($station === 3 || $station === 12) {
            $s['NVL']['deliver_island']++;
            $s['NVL']['_seen_island'] = true;
        }
        return;
    }

    if ($job_name === 'D749' && $station === 10) {
        $s['D749']['so_demmler']++;
        return;
    }

    if ($job_name === 'CK1') {
        if ($station === 12) {
            $s['CK1']['so_shenango']++;
        } elseif ($station === 8) {
            $s['CK1']['so_south']++;
        }
    }
}

/** Named-stats deltas -> extra per-session stats columns. */
function hart_session_stats_row_extra(array $delta)
{
    return [
        'coke_deliveries' => (int) ($delta['coke']['complete_deliveries'] ?? 0),
        'coke_reload_weigh' => (int) ($delta['coke']['reloads'] ?? 0),
    ];
}

/** Column labels for the per-session stats report. */
function hart_session_stats_labels()
{
    return [
        [
            'title' => 'NVL moves by session',
            'row_key' => 'NVL',
            'rule' => 52,
            'columns' => [
                ['key' => 'pu_scully', 'label' => 'PuS', 'width' => 3],
                ['key' => 'so_south_1', 'label' => 'SoY', 'width' => 3],
                ['key' => 'pu_south_1', 'label' => 'PuY', 'width' => 3],
                ['key' => 'deliver_island', 'label' => 'Del', 'width' => 3],
                ['key' => 'pu_island', 'label' => 'PuI', 'width' => 3],
                ['key' => 'so_south_2', 'label' => 'SoY2', 'width' => 3],
                ['key' => 'pu_south_2', 'label' => 'PuY2', 'width' => 3],
                ['key' => 'so_scully', 'label' => 'SoS', 'width' => 3],
            ],
            'note' => '  PuS=pickup Scully  SoY/PuY=South visit 1  Del=Island/Shen setout  PuI=pickup Island  SoY2/PuY2=South visit 2  SoS=setout Scully',
        ],
        [
            'title' => 'D749 moves by session',
            'row_key' => 'D749',
            'rule' => 28,
            'columns' => [
                ['key' => 'pu_demmler', 'label' => 'PuDEM', 'width' => 5],
                ['key' => 'pu_south', 'label' => 'PuSth', 'width' => 5],
                ['key' => 'so_demmler', 'label' => 'SoDEM', 'width' => 5],
            ],
        ],
        [
            'title' => 'CK1 coke cycle by session',
            'row_key' => 'CK1',
            'rule' => 44,
            'columns' => [
                ['key' => 'pu_south', 'label' => 'PuS', 'width' => 4],
                ['key' => 'so_shenango', 'label' => 'SoSh', 'width' => 4],
                ['key' => 'pu_shenango', 'label' => 'PuSh', 'width' => 4],
                ['key' => 'weighed', 'label' => 'Wgh', 'width' => 4],
                ['key' => 'reloads', 'label' => 'Rld', 'width' => 4],
                ['key' => 'outbound', 'label' => 'Ob', 'width' => 4],
                ['key' => 'so_south', 'label' => 'SoS', 'width' => 4],
            ],
            'note' => '  PuS/SoS=South pickup/setout  SoSh/PuSh=Shenango reload drop / loaded pickup  Wgh/Rld/Ob=weigh stats',
        ],
        [
            'title' => 'Coke customer deliveries (unload) by session',
            'row_key' => null,
            'rule' => 18,
            'columns' => [
                ['key' => 'coke_deliveries', 'label' => 'Del', 'width' => 4],
                ['key' => 'coke_reload_weigh', 'label' => 'Rld', 'width' => 4],
            ],
        ],
    ];
}

/** delivery_complete_hooks: count customer deliveries of outbound coke. */
function hart_delivery_complete($dbc, $car_id, $shipment_code)
{
    if (hart_is_outbound_coke_shipment($shipment_code)) {
        hart_coke_stats()['complete_deliveries']++;
    }
}

/**
 * Switch-list sections: map the legacy show_island_destination /
 * show_scully_destination flags to the generic 'destination_override' config.
 */
function hart_switchlist_section_normalize(array $section)
{
    if (isset($section['destination_override'])) {
        return $section;
    }
    if (!empty($section['show_island_destination'])) {
        $section['destination_override'] = [
            'side' => 'unloading',
            'station_match' => ['Neville', 'Shenango'],
            'location_prefix' => ['NIL-'],
        ];
    } elseif (!empty($section['show_scully_destination'])) {
        $section['destination_override'] = [
            'side' => 'loading',
            'station_match' => ['Scully'],
            'location_prefix' => ['SCL-'],
        ];
    }

    return $section;
}

/**
 * wheel_report_extra_rows hook: STG-DEMMLER inbound archive rows count as D749 cars.
 *
 * @return array<string,list<array>>
 */
function hart_wheel_report_extra_rows($dbc, $session_nbr, $root = null)
{
    $rows = hart_wheel_report_stg_d749_inbound_rows($dbc, $session_nbr, $root);

    return $rows === [] ? [] : ['D749' => $rows];
}

/**
 * Demmler Yard online staging pickup label (for STG-DEMMLER → D749 inbound rows).
 *
 * @return array{0:string,1:string}
 */
function hart_wheel_report_demmler_pickup($dbc)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $station = 'Demmler Yard';
    $code = 'DEMMLER-YARD';
    $rs = mysqli_query(
        $dbc,
        'SELECT r.station, l.code
         FROM locations l
         LEFT JOIN routing r ON r.id = l.station
         WHERE l.code = "DEMMLER-YARD"
         LIMIT 1'
    );
    if ($rs && ($row = mysqli_fetch_assoc($rs))) {
        if (!empty($row['station'])) {
            $station = (string) $row['station'];
        }
        if (!empty($row['code'])) {
            $code = (string) $row['code'];
        }
    }
    $cache = [$station, $code];

    return $cache;
}

/**
 * STG-DEMMLER "D749 · Inbound" archive rows → D749 cars at Demmler after staging.
 *
 * @return list<array>
 */
function hart_wheel_report_stg_d749_inbound_rows($dbc, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    list($dem_station, $dem_loc) = hart_wheel_report_demmler_pickup($dbc);
    $rows = [];
    $seen = [];
    foreach (glob(session_dir_for($session_nbr, $root) . '/phase_*/*STG-DEMMLER*_master.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d) || ($d['job'] ?? '') !== 'STG-DEMMLER') {
            continue;
        }
        if ((string) ($d['title'] ?? '') !== 'D749' || (string) ($d['info'] ?? '') !== 'Inbound') {
            continue;
        }
        foreach (($d['sections'] ?? []) as $sec) {
            foreach (($sec['cars'] ?? []) as $car) {
                $marks = (string) ($car['reporting_marks'] ?? ($car[0] ?? ''));
                if ($marks === '' || isset($seen[$marks])) {
                    continue;
                }
                $seen[$marks] = true;
                $rows[] = session_wheel_report_row_from_master_car($car, $dem_station, $dem_loc);
            }
        }
    }

    return $rows;
}
