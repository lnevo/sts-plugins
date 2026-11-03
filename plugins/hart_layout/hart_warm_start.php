<?php
/**
 * HART layout warm-start scenario: the CK1 / NVL / D749 / STG-SCULLY / STG-DEMMLER
 * operating-session choreography. Registered through the manifest key
 * 'warm_start_scenario' (see plugin.php); core's warm_start_* wrappers dispatch here.
 * Functions moved from core's warm_start_helpers.php are renamed warm_start_<x> -> hart_<x>.
 */

function hart_coke_stats_reset()
{
    return [
        'reloads' => 0,
        'outbound_assignments' => 0,
        'complete_deliveries' => 0,
        'weighed' => 0,
    ];
}

/** Coke counters live in the generic stats bucket 'coke'. Returned by reference. */
function &hart_coke_stats()
{
    $stats = &warm_start_stats_bucket('coke');
    foreach (hart_coke_stats_reset() as $key => $value) {
        if (!isset($stats[$key])) {
            $stats[$key] = $value;
        }
    }
    return $stats;
}

function hart_coke_stats_init()
{
    $stats = &warm_start_stats_bucket('coke');
    foreach (hart_coke_stats_reset() as $key => $value) {
        $stats[$key] = $value;
    }
}

/** Keys HART adds on top of core's generic defaults. */
function hart_default_config()
{
    return [
        'stop_when_locals_secured' => false,
        'stop_when_stg_scully_ready' => false,
        'run_ck1_test' => false,
        'run_ck1_each_session' => false,
        'secure_locals_each_session' => false,
        'run_phased_locals' => false,
        'staging_after_locals' => false,
        'staging_jobs' => ['STG-SCULLY', 'STG-DEMMLER'],
        'scale_calibrate_every_sessions' => 3,
        'ck1_test' => [
            'weigh_required' => true,
            'min_outbound_on_train' => 1,
        ],
    ];
}

function hart_track_scale_available()
{
    if (!function_exists('plugins_require_helper')) {
        $plugins = __DIR__ . '/plugin_registry.php';
        if (is_readable($plugins)) {
            require_once $plugins;
        }
    }

    return function_exists('plugins_require_helper') && plugins_require_helper('track_scale');
}

/** Station names pinned to the top of per-session station reports (HART layout). */
function hart_station_report_featured()
{
    return ['Shenango Coke Works', 'Demmler Yard', 'Scully Yard', 'Neville Island'];
}

function hart_outbound_coke_shipment_codes()
{
    if (function_exists('coke_orders_outbound_shipment_codes')) {
        return coke_orders_outbound_shipment_codes();
    }

    return [];
}

/** SQL IN (...) fragment for outbound coke shipment codes. */
function hart_outbound_coke_shipment_codes_sql_in($dbc)
{
    $codes = hart_outbound_coke_shipment_codes();
    $parts = [];
    foreach ($codes as $code) {
        $parts[] = '"' . mysqli_real_escape_string($dbc, $code) . '"';
    }
    return implode(', ', $parts);
}

function hart_is_outbound_coke_shipment($shipment_code)
{
    return in_array((string) $shipment_code, hart_outbound_coke_shipment_codes(), true);
}

function hart_count_shenango_pickup_for_ck1($dbc)
{
    $shenango_station = 12;
    $seen = [];
    foreach (['NVL', 'D749'] as $job_name) {
        foreach (warm_start_pending_pickup_ids($dbc, $job_name) as $car_id) {
            if (warm_start_car_order_targets_station($dbc, $car_id, $shenango_station)) {
                $seen[(int) $car_id] = true;
            }
        }
    }
    return count($seen);
}

function hart_count_loaded_coke_at_shenango($dbc)
{
    if (!hart_track_scale_available()) {
        return 0;
    }
    $config = track_scale_load_config();

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = 12
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0
           AND cars.status = "Loaded"
         ORDER BY cars.id'
    );
    $count = 0;
    while ($row = mysqli_fetch_array($rs)) {
        if (track_scale_car_in_coke_fleet($dbc, (int) $row['car_id'], $config)) {
            $count++;
        }
    }
    return $count;
}

function hart_count_ck1_on_train($dbc)
{
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND cars.status = "Loaded"
           AND shipments.code IN (' . hart_outbound_coke_shipment_codes_sql_in($dbc) . ')'
    );
    return (int) mysqli_fetch_array($rs)['c'];
}

function hart_phased_local_job_names()
{
    return ['NVL', 'D749', 'CK1'];
}

function hart_nvl_pickup_scully_from_ck1($dbc)
{
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    $scully_dests = [9, 15];
    $south_station = 8;

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id, cars.handled_by_job_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = "' . $south_station . '"
           AND cars.current_location_id > 0
           AND (cars.handled_by_job_id = 0'
        . ($ck1_id > 0 ? ' OR cars.handled_by_job_id = "' . (int) $ck1_id . '"' : '')
        . ')'
    );

    $car_ids = [];
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!warm_start_car_targets_any_station($dbc, $car_id, $scully_dests)) {
            continue;
        }
        $car_ids[] = $car_id;
    }

    $assigned = warm_start_assign_cars_to_job($dbc, 'NVL', $car_ids);
    $picked_up = warm_start_pickup_job_at_station($dbc, 'NVL', $south_station);
    return compact('assigned', 'picked_up');
}

function hart_d749_pickup_island_demmler_outbound($dbc)
{
    $demmler_dests = [10, 14];
    $island_station = 3;

    $assigned = warm_start_assign_cars_at_station_for_destinations(
        $dbc,
        'D749',
        $island_station,
        $demmler_dests
    );
    $picked_up = warm_start_pickup_job_at_station($dbc, 'D749', $island_station);
    $set_out = warm_start_setout_job_cars_for_destinations($dbc, 'D749', $demmler_dests);
    return compact('assigned', 'picked_up', 'set_out');
}

function hart_run_nvl_pre_ck1($dbc)
{
    $demmler_dests = [10, 14];

    $assigned = warm_start_assign_eligible_at_pickup_station($dbc, 'NVL', 9);
    $picked_up = warm_start_pickup_job_at_station($dbc, 'NVL', 9);
    $demmler_setout = warm_start_setout_job_cars_for_destinations($dbc, 'NVL', $demmler_dests);

    return compact('assigned', 'picked_up', 'demmler_setout');
}

function hart_run_nvl_post_ck1($dbc)
{
    $island_dests = [3, 12];
    $scully_dests = [9, 15];
    $demmler_dests = [10, 14];

    $ck1_handoff = hart_nvl_pickup_scully_from_ck1($dbc);
    $island_setout = warm_start_setout_job_cars_for_destinations($dbc, 'NVL', $island_dests);

    $criterion_steps = [50, 60, 70, 75, 80, 85, 90, 95, 100, 110];
    $criterion_moves = 0;
    foreach ($criterion_steps as $step_nbr) {
        $move = warm_start_run_job_criterion($dbc, 'NVL', $step_nbr);
        $criterion_moves += (int) $move['set_out'];
    }

    $demmler_setout = warm_start_setout_job_cars_for_destinations($dbc, 'NVL', $demmler_dests);
    $scully_setout = warm_start_setout_job_cars_for_destinations($dbc, 'NVL', $scully_dests);
    $remaining = warm_start_setout_all_job_train($dbc, 'NVL');

    return array_merge(
        compact('island_setout', 'criterion_moves', 'demmler_setout', 'scully_setout', 'remaining'),
        ['ck1_handoff' => $ck1_handoff]
    );
}

function hart_run_d749_phased_ops($dbc)
{
    $stats = ['south' => 0, 'demmler' => 0, 'island_demmler' => []];

    foreach ([10, 15, 20] as $step_nbr) {
        $move = warm_start_run_job_criterion($dbc, 'D749', $step_nbr);
        $stats['south'] += (int) $move['set_out'];
    }
    foreach ([30, 35, 40, 50, 60] as $step_nbr) {
        $move = warm_start_run_job_criterion($dbc, 'D749', $step_nbr);
        $stats['demmler'] += (int) $move['set_out'];
    }
    $stats['island_demmler'] = hart_d749_pickup_island_demmler_outbound($dbc);
    $stats['remaining'] = warm_start_setout_all_job_train($dbc, 'D749');

    return $stats;
}

function hart_run_pending_stg_scully($dbc, $config, $load_unload_fraction = 1.0)
{
    if (empty($config['stop_when_stg_scully_ready']) && empty($config['secure_locals_each_session'])) {
        return ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0, 'cleared' => 0];
    }

    $backlog = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
    if (empty($backlog['ready'])) {
        return ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0, 'cleared' => 0];
    }

    $stats = warm_start_complete_staging_jobs($dbc, ['STG-SCULLY'], $config, $load_unload_fraction);
    $stats['cleared'] = (int) ($stats['set_out'] ?? 0);
    return $stats;
}

function hart_secure_locals_at_session_end($dbc, $config = [], $load_unload_fraction = 1.0, $run_stg_scully = true)
{
    $scl_id = warm_start_location_id_by_code($dbc, 'SCL');

    $stg_demmler = warm_start_complete_staging_jobs($dbc, ['STG-DEMMLER'], $config, $load_unload_fraction);

    warm_start_assign_all_ordered_cars_at_station($dbc, 'D749', 10);
    $d749 = warm_start_pickup_job_at_station($dbc, 'D749', 10);

    warm_start_assign_eligible_at_pickup_station($dbc, 'NVL', 9);
    warm_start_pickup_job_at_station($dbc, 'NVL', 9);
    warm_start_setout_all_job_train($dbc, 'NVL');
    $nvl = $scl_id > 0 ? warm_start_setout_job_at_location($dbc, 'NVL', $scl_id) : 0;

    $stg_scully = ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0, 'deferred' => false];
    if ($run_stg_scully) {
        $stg_scully = array_merge(
            warm_start_complete_staging_jobs($dbc, ['STG-SCULLY'], $config, $load_unload_fraction),
            ['deferred' => false]
        );
    } else {
        $stg_scully['deferred'] = true;
    }

    return [
        'd749' => $d749,
        'nvl' => $nvl,
        'stg_demmler' => $stg_demmler,
        'stg_scully' => $stg_scully,
    ];
}

function hart_locals_secured($dbc, $config = [], $session_snapshot = null)
{
    unset($session_snapshot);
    if (warm_start_non_staging_work_remaining($dbc, $config) > 0) {
        return false;
    }

    foreach (['NVL'] as $job_name) {
        $job_id = warm_start_job_id($dbc, $job_name);
        if ($job_id <= 0) {
            continue;
        }
        $rs = mysqli_query(
            $dbc,
            'SELECT COUNT(*) AS c FROM cars WHERE handled_by_job_id = "' . (int) $job_id . '" AND current_location_id = 0'
        );
        if ((int) mysqli_fetch_array($rs)['c'] > 0) {
            return false;
        }
    }

    return true;
}

function hart_ck1_reload_shipment_codes($dbc)
{
    if (!hart_track_scale_available()) {
        return ['COKE-RELOAD-SHEN'];
    }
    $config = track_scale_load_config();
    return track_scale_shipment_codes_for_routing('reload', $config);
}

function hart_ck1_assign_reload_cars_on_train($dbc)
{
    if (!hart_track_scale_available()) {
        return 0;
    }

    $config = track_scale_load_config();
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }

    $reload_codes = hart_ck1_reload_shipment_codes($dbc);
    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $reload_codes));
    if ($quoted === '') {
        return 0;
    }

    $assigned = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND shipments.code IN (' . $quoted . ')'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            continue;
        }
        $assigned++;
    }
    return $assigned;
}

function hart_ck1_setout_train_cars_for_shipments($dbc, $location_id, array $shipment_codes)
{
    if ($location_id <= 0 || count($shipment_codes) === 0) {
        return 0;
    }
    if (!hart_track_scale_available()) {
        return 0;
    }
    $config = track_scale_load_config();
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }

    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $shipment_codes));

    $set_out = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND shipments.code IN (' . $quoted . ')'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            continue;
        }
        if (warm_start_setout_single_car($dbc, $car_id, 'CK1', $location_id)) {
            $set_out++;
        }
    }
    return $set_out;
}

function hart_ck1_assign_reload_at_south($dbc)
{
    if (!hart_track_scale_available()) {
        return 0;
    }

    $config = track_scale_load_config();
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }

    $reload_codes = hart_ck1_reload_shipment_codes($dbc);
    if (count($reload_codes) === 0) {
        return 0;
    }
    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $reload_codes));

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE loc.station = 8
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0
           AND shipments.code IN (' . $quoted . ')'
    );

    $assigned = 0;
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            continue;
        }
        if (mysqli_query(
            $dbc,
            'UPDATE cars SET handled_by_job_id = "' . (int) $ck1_id . '" WHERE id = "' . $car_id . '"'
        )) {
            $assigned++;
        }
    }
    return $assigned;
}

function hart_ck1_assign_outbound_at_south($dbc)
{
    if (!hart_track_scale_available()) {
        return 0;
    }

    $config = track_scale_load_config();
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }

    $outbound_codes = hart_outbound_coke_shipment_codes();
    if (count($outbound_codes) === 0) {
        return 0;
    }
    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $outbound_codes));

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE loc.station = 8
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0
           AND shipments.code IN (' . $quoted . ')'
    );

    $assigned = 0;
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            continue;
        }
        if (mysqli_query(
            $dbc,
            'UPDATE cars SET handled_by_job_id = "' . (int) $ck1_id . '" WHERE id = "' . $car_id . '"'
        )) {
            $assigned++;
        }
    }
    return $assigned;
}

function hart_ck1_setout_train_to_location($dbc, $location_id)
{
    $session_number = warm_start_get_session($dbc);
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0 || $location_id <= 0) {
        return 0;
    }

    $set_out = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!mysqli_query(
            $dbc,
            'UPDATE cars
             SET current_location_id = "' . (int) $location_id . '",
                 handled_by_job_id = 0,
                 position = 0
             WHERE id = "' . $car_id . '"'
        )) {
            continue;
        }
        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Set out by Job CK1",
                     "' . (int) $location_id . '")'
        );
        warm_start_record_job_setout($dbc, 'CK1', $location_id);
        warm_start_apply_setout_transitions($dbc, $car_id, $session_number);
        $set_out++;
    }
    return $set_out;
}

function hart_run_ck1_session_ops($dbc, $config = [])
{
    $shenango_id = warm_start_location_id_by_code($dbc, 'NIL-SHEN-COKE');
    $south_id = warm_start_location_id_by_code($dbc, 'SOUTH');
    $scale_id = warm_start_location_id_by_code($dbc, 'SOUTH-SCALE');
    $spot_id = $scale_id > 0 ? $scale_id : $south_id;
    $stats = [
        'reload_assigned' => 0,
        'reload_picked' => 0,
        'reload_setout' => 0,
        'loaded_assigned' => 0,
        'loaded_picked' => 0,
        'weighed' => 0,
        'reloads' => 0,
        'outbound' => 0,
        'south_setout' => 0,
    ];

    warm_start_load_unload($dbc, 1.0);

    $stats['loaded_assigned'] = warm_start_auto_assign_job_at_station($dbc, 'CK1', 12, 1.0);
    $stats['loaded_picked'] = warm_start_pickup_job_at_station($dbc, 'CK1', 12);

    if ($spot_id > 0) {
        warm_start_pickup_job($dbc, 'CK1');
        hart_ck1_setout_train_to_location($dbc, $spot_id);
    }

    if (function_exists('track_scale_maybe_calibrate_scale')) {
        track_scale_maybe_calibrate_scale($dbc, $config);
    }

    $weigh = ['weighed' => 0, 'reloads' => 0, 'outbound_assignments' => 0];
    if (function_exists('track_scale_run_ck1_scale_ops')) {
        $weigh = track_scale_run_ck1_scale_ops($dbc);
    }
    $stats['weighed'] = (int) ($weigh['weighed'] ?? 0);
    $stats['reloads'] = (int) ($weigh['reloads'] ?? 0);
    $stats['outbound'] = (int) ($weigh['outbound_assignments'] ?? 0);

    $s = &warm_start_session_stats_current();
    $s['CK1']['weighed'] = $stats['weighed'];
    $s['CK1']['reloads'] = $stats['reloads'];
    $s['CK1']['outbound'] = $stats['outbound'];

    $stats['reload_assigned'] = hart_ck1_assign_reload_cars_on_train($dbc);
    $stats['reload_assigned'] += hart_ck1_assign_reload_at_south($dbc);
    $stats['reload_assigned'] += hart_ck1_assign_outbound_at_south($dbc);
    $stats['reload_picked'] = warm_start_pickup_job($dbc, 'CK1');
    if ($shenango_id > 0) {
        $stats['reload_setout'] = hart_ck1_setout_train_cars_for_shipments(
            $dbc,
            $shenango_id,
            hart_ck1_reload_shipment_codes($dbc)
        );
        warm_start_load_unload($dbc, 1.0);
    }

    if ($south_id > 0) {
        warm_start_pickup_job($dbc, 'CK1');
        $stats['south_setout'] = hart_ck1_setout_train_cars_for_shipments(
            $dbc,
            $south_id,
            hart_outbound_coke_shipment_codes()
        );
        $remaining = warm_start_setout_all_job_train($dbc, 'CK1');
        $stats['south_setout'] += $remaining;
    }

    return array_merge($stats, ['weigh' => $weigh]);
}

function hart_tracked_sim_overrides(array $overrides = [])
{
    return array_merge([
        'stop_when_stg_scully_ready' => true,
        'stop_when_locals_secured' => false,
        'stop_when_staging_ready' => false,
        'run_ck1_each_session' => true,
        'secure_locals_each_session' => true,
        'staging_after_locals' => true,
        'run_phased_locals' => true,
        'max_sessions' => 10,
    ], $overrides);
}

/**
 * Run local/staging work for the current operating session (no session increment).
 * Call after begin_session has opened the session (STG-SCULLY, fill, assign).
 * Ends with STG-SCULLY deferred at Scully for the next begin_session.
 */
function hart_play_operating_session($dbc, array $config = [], array $fractions = null, $label = '')
{
    $config = warm_start_merge_config(array_merge(
        hart_tracked_sim_overrides(),
        ['play_operating_session' => true],
        $config
    ));
    $fractions = $fractions ?? warm_start_default_fractions($config);
    $max_unfilled = (int) ($config['max_unfilled_before_generate'] ?? 30);

    return warm_start_run_session_work(
        $dbc,
        $fractions,
        $label,
        $max_unfilled,
        false,
        $config,
        false,
        warm_start_get_session($dbc),
        null
    );
}

/**
 * Advance one operating session: increment session, clear pending STG-SCULLY, run ops.
 * Used by warm-start simulation (prior sessions). Skips generation when too many unfilled orders.
 */
function hart_advance_session($dbc, $fractions, $label, $max_unfilled, $finish_non_staging_only = false, $config = [], $run_staging = true)
{
    $session = warm_start_get_session($dbc) + 1;
    warm_start_set_session($dbc, $session);
    $load_unload_fraction = $fractions['load_unload'] ?? 1.0;
    $pending_stg_scully = null;

    if (!empty($config['stop_when_stg_scully_ready']) || !empty($config['secure_locals_each_session'])) {
        $pending_stg_scully = hart_run_pending_stg_scully($dbc, $config, $load_unload_fraction);
        if (($pending_stg_scully['set_out'] ?? 0) > 0) {
            $suffix = $label !== '' ? " ({$label})" : '';
            warm_start_log(
                "Session {$session}{$suffix} start: pending STG-SCULLY moves="
                . ($pending_stg_scully['set_out'] ?? 0)
            );
        }
    }

    $result = warm_start_run_session_work(
        $dbc,
        $fractions,
        $label,
        $max_unfilled,
        $finish_non_staging_only,
        $config,
        $run_staging,
        $session,
        $pending_stg_scully
    );

    return $result;
}

/**
 * Shared session body: generate orders, locals, CK1, bookend staging.
 */
function hart_run_session_work(
    $dbc,
    $fractions,
    $label,
    $max_unfilled,
    $finish_non_staging_only = false,
    $config = [],
    $run_staging = true,
    $session = null,
    $pending_stg_scully = null
) {
    $coke_before = warm_start_stats_copy_all();
    warm_start_session_stats_reset();
    if ($session === null) {
        $session = warm_start_get_session($dbc);
    }
    $unfilled = warm_start_count_unfilled($dbc);
    $suffix = $label !== '' ? " ({$label})" : '';
    $load_unload_fraction = $fractions['load_unload'] ?? 1.0;

    if ($unfilled > $max_unfilled) {
        warm_start_log(
            "Session {$session}{$suffix}: skipping generate ({$unfilled} unfilled > {$max_unfilled})"
        );
    } else {
        $waybill_counter = warm_start_get_next_auto_waybill_counter($dbc, $session);
        $generated = warm_start_generate_orders($dbc, $session, $waybill_counter);
        warm_start_log("Session {$session}{$suffix}: generated {$generated} orders");
    }

    if (!empty($config['secure_locals_each_session'])) {
        $d749_start = hart_run_d749_session_start($dbc);
        warm_start_log(
            "Session {$session}{$suffix} start: D749 Demmler pu={$d749_start['picked_up']} South so={$d749_start['set_out']}"
        );
    }

    $phased_locals = !empty($config['run_phased_locals']);
    $local_jobs = $phased_locals ? hart_phased_local_job_names() : [];

    if ($phased_locals) {
        $stats = warm_start_run_ops_cycle($dbc, $fractions, false, $config, true, $local_jobs);
        $stats['load_unload'] += warm_start_load_unload($dbc, $fractions['load_unload']);

        $nvl_pre = hart_run_nvl_pre_ck1($dbc);
        $stats['nvl_pre'] = $nvl_pre;
        warm_start_log(
            "Session {$session}{$suffix} NVL pre-CK1: scully_pu={$nvl_pre['picked_up']} demmler_so={$nvl_pre['demmler_setout']}"
        );
    } else {
        $stats = warm_start_run_ops_cycle($dbc, $fractions, false, $config, true);
    }

    warm_start_log(
        "Session {$session}{$suffix}: filled={$stats['filled']} repo={$stats['repositioned']} assigned={$stats['assigned']} "
        . "pickup={$stats['picked_up']} setout={$stats['set_out']} load/unload={$stats['load_unload']}"
    );

    if (!empty($config['run_ck1_each_session'])) {
        $ck1 = hart_run_ck1_session_ops($dbc, $config);
        $stats['ck1'] = $ck1;
        warm_start_log(
            "Session {$session}{$suffix} CK1: reload_pu={$ck1['reload_picked']} shenango_so={$ck1['reload_setout']} "
            . "loaded_pu={$ck1['loaded_picked']} weighed={$ck1['weighed']} reloads={$ck1['reloads']} south_so={$ck1['south_setout']}"
        );
    }

    if ($phased_locals) {
        $nvl_post = hart_run_nvl_post_ck1($dbc);
        $stats['nvl_post'] = $nvl_post;
        warm_start_log(
            "Session {$session}{$suffix} NVL post-CK1: ck1_scully_pu={$nvl_post['ck1_handoff']['picked_up']} "
            . "island_so={$nvl_post['island_setout']} scully_so={$nvl_post['scully_setout']}"
        );

        $d749_ops = hart_run_d749_phased_ops($dbc);
        $stats['d749_phased'] = $d749_ops;
        warm_start_log(
            "Session {$session}{$suffix} D749: island→Demmler pu={$d749_ops['island_demmler']['picked_up']} "
            . "so={$d749_ops['island_demmler']['set_out']} remaining_so={$d749_ops['remaining']}"
        );

        $stats['load_unload'] += warm_start_load_unload($dbc, $fractions['load_unload']);
    }

    warm_start_finish_non_staging_jobs($dbc, $config, $fractions);

    if (!empty($config['secure_locals_each_session'])) {
        $load_unload_fraction = $fractions['load_unload'] ?? 1.0;
        $stats['stg_scully_stop_ready'] = false;
        $stats['stg_scully_backlog'] = ['eligible' => 0, 'on_jobs' => 0, 'ready' => false];

        if (!empty($config['play_operating_session'])) {
            $secured = hart_secure_locals_at_session_end($dbc, $config, $load_unload_fraction, false);
            $stats['stg_scully_backlog'] = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
        } elseif (!empty($config['stop_when_stg_scully_ready'])) {
            $secured = hart_secure_locals_at_session_end($dbc, $config, $load_unload_fraction, false);
            $scully_backlog = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
            $min_sessions = (int) ($config['min_sessions'] ?? 3);
            $stop_from_session = (int) ($config['stg_scully_stop_from_session'] ?? $min_sessions);
            if ($session >= $stop_from_session && !empty($scully_backlog['ready'])) {
                $stats['stg_scully_stop_ready'] = true;
                $stats['stg_scully_backlog'] = $scully_backlog;
            } else {
                $stg_scully = warm_start_complete_staging_jobs(
                    $dbc,
                    ['STG-SCULLY'],
                    $config,
                    $load_unload_fraction
                );
                $secured['stg_scully'] = array_merge($stg_scully, ['deferred' => false]);
            }
        } else {
            $secured = hart_secure_locals_at_session_end($dbc, $config, $load_unload_fraction, true);
        }

        $stats['load_unload'] = ($stats['load_unload'] ?? 0)
            + (int) ($secured['stg_demmler']['load_unload'] ?? 0)
            + (int) ($secured['stg_scully']['load_unload'] ?? 0);
        $stg_scully_note = !empty($secured['stg_scully']['deferred'])
            ? 'STG-SCULLY deferred (pending)'
            : 'STG-SCULLY moves=' . ($secured['stg_scully']['set_out'] ?? 0);
        warm_start_log(
            "Session {$session}{$suffix}: bookend STG-DEMMLER moves="
            . ($secured['stg_demmler']['set_out'] ?? 0)
            . " D749 Demmler pu={$secured['d749']} (on train) NVL→Scully={$secured['nvl']} "
            . $stg_scully_note
        );
        if (!empty($stats['stg_scully_stop_ready'])) {
            warm_start_log(
                "Session {$session}{$suffix}: STG-SCULLY ready — "
                . ($stats['stg_scully_backlog']['eligible'] ?? 0)
                . ' car(s) at Scully awaiting assignment'
            );
        }
    }

    $staging = ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0];
    if ($run_staging && empty($config['secure_locals_each_session'])) {
        $load_unload_fraction = $fractions['load_unload'] ?? 1.0;
        $staging = warm_start_complete_staging($dbc, $config, $load_unload_fraction);
        $stats['load_unload'] = ($stats['load_unload'] ?? 0) + (int) ($staging['load_unload'] ?? 0);
        warm_start_log(
            "Session {$session}{$suffix} staging: assigned={$staging['assigned']} pickup={$staging['picked_up']} "
            . "setout={$staging['set_out']} load/unload={$staging['load_unload']}"
        );
    } elseif (empty($config['secure_locals_each_session']) && empty($config['run_ck1_each_session'])) {
        warm_start_log("Session {$session}{$suffix}: staging deferred");
    }

    $snapshot = warm_start_session_stats_commit($session, $label, $coke_before);
    $n = $snapshot['NVL'];
    $d = $snapshot['D749'];
    $c = $snapshot['CK1'];
    warm_start_log(
        "Session {$session}{$suffix} stats: NVL PuS={$n['pu_scully']} D749 PuDEM={$d['pu_demmler']} "
        . "CK1 PuSh={$c['pu_shenango']} Rld={$c['reloads']} Del={$snapshot['coke_deliveries']}"
    );

    return array_merge($stats, [
        'staging' => $staging,
        'session_stats' => $snapshot,
        'locals_secured' => hart_locals_secured($dbc, $config, $snapshot),
        'stg_scully_stop_ready' => !empty($stats['stg_scully_stop_ready']),
        'stg_scully_backlog' => $stats['stg_scully_backlog'] ?? ['eligible' => 0, 'on_jobs' => 0, 'ready' => false],
        'pending_stg_scully' => $pending_stg_scully,
    ]);
}

function hart_ck1_outbound_cars_detail($dbc)
{
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return [];
    }

    $codes = hart_outbound_coke_shipment_codes();
    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $codes));

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id,
                cars.reporting_marks,
                cars.status,
                cars.current_location_id,
                shipments.code AS shipment_code,
                car_orders.waybill_number
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND shipments.code IN (' . $quoted . ')
         ORDER BY cars.id'
    );

    $cars = [];
    while ($row = mysqli_fetch_array($rs)) {
        $on_train = ((int) $row['current_location_id'] === 0);
        $cars[] = [
            'car_id' => (int) $row['car_id'],
            'reporting_marks' => $row['reporting_marks'],
            'status' => $row['status'],
            'on_train' => $on_train,
            'shipment_code' => $row['shipment_code'],
            'waybill_number' => $row['waybill_number'],
        ];
    }
    return $cars;
}

function hart_log_ck1_outbound_cars($dbc, $session)
{
    $cars = hart_ck1_outbound_cars_detail($dbc);
    if (count($cars) === 0) {
        warm_start_log("Session {$session}: no CK1 outbound coke cars assigned");
        return;
    }

    warm_start_log("Session {$session}: CK1 outbound coke cars");
    foreach ($cars as $car) {
        $where = $car['on_train'] ? 'on train' : 'spotted';
        warm_start_log(sprintf(
            '  %s id=%d %s %s wb=%s (%s)',
            $car['reporting_marks'],
            $car['car_id'],
            $car['status'],
            $car['shipment_code'],
            $car['waybill_number'],
            $where
        ));
    }
}

function hart_staging_job_names($dbc, $config)
{
    $configured = $config['staging_jobs'] ?? ['STG-SCULLY', 'STG-DEMMLER'];
    return array_values(array_filter(array_map('strval', $configured)));
}

function hart_location_code_aliases()
{
    // Keep in sync with generate_hart_seed.py PHYSICAL_BLOCK_MAP + legacy STS codes.
    return [
        'SCL' => 'SCULLY-YARD',
        'DEM' => 'DEMMLER-YARD',
        'SCL-YARD' => 'SCULLY-YARD',
        'DEM-YARD' => 'DEMMLER-YARD',
        'SCL-IN' => 'SCULLY-YARD',
        'DEM-IN' => 'DEMMLER-YARD',
        'SCL-RCV' => 'SCULLY-YARD',
        'DEM-RCV' => 'DEMMLER-YARD',
        'SOUTH' => 'SOUTH-YARD',
        'NIL-SHEN-COKE' => 'SHEN-COKE-SHIPPING',
    ];
}

/**
 * Move interchange-bound cars onto home yards so staging jobs can pick them up.
 */
function hart_prep_staging_yards($dbc, $config)
{
    $moved = 0;
    $scl_id = warm_start_location_id_by_code($dbc, 'SCL');
    $dem_id = warm_start_location_id_by_code($dbc, 'DEM');
    if ($scl_id <= 0 || $dem_id <= 0) {
        return 0;
    }

    $yard_by_offline_station = [
        15 => $scl_id,
        14 => $dem_id,
    ];

    foreach ($yard_by_offline_station as $offline_station => $yard_id) {
        $sql = 'UPDATE cars
                INNER JOIN car_orders ON car_orders.car = cars.id
                INNER JOIN shipments ON shipments.id = car_orders.shipment
                INNER JOIN locations unload_loc ON unload_loc.id = shipments.unloading_location
                INNER JOIN locations cur_loc ON cur_loc.id = cars.current_location_id
                SET cars.current_location_id = "' . (int) $yard_id . '",
                    cars.handled_by_job_id = 0,
                    cars.position = 0
                WHERE unload_loc.station = ' . (int) $offline_station . '
                  AND cur_loc.station != ' . (int) ($offline_station === 15 ? 9 : 10) . '
                  AND cars.current_location_id > 0
                  AND cars.status IN ("Loaded", "Ordered")
                  AND car_orders.waybill_number NOT LIKE "%E%"';
        mysqli_query($dbc, $sql);
        $moved += mysqli_affected_rows($dbc);
    }

    $staging_jobs = warm_start_staging_job_names($dbc, $config);
    foreach ($staging_jobs as $job_name) {
        $yard_code = $job_name === 'STG-SCULLY' ? 'SCL' : 'DEM';
        $yard_id = warm_start_location_id_by_code($dbc, $yard_code);
        $pickup_station = $job_name === 'STG-SCULLY' ? 9 : 10;
        if ($yard_id <= 0) {
            continue;
        }

        // Only move offline-bound interchange cars onto the yard — never pull island industry cars.
        $eligible = array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job_name, false));
        foreach ($eligible as $car_id) {
            $rs = mysqli_query(
                $dbc,
                'SELECT cars.current_location_id, cur.station, unload_loc.station AS unload_station
                 FROM cars
                 LEFT JOIN locations cur ON cur.id = cars.current_location_id
                 LEFT JOIN car_orders ON car_orders.car = cars.id
                 LEFT JOIN shipments ON shipments.id = car_orders.shipment
                 LEFT JOIN locations unload_loc ON unload_loc.id = shipments.unloading_location
                 WHERE cars.id = "' . (int) $car_id . '"
                 LIMIT 1'
            );
            $row = mysqli_fetch_array($rs);
            if (!$row || (int) $row['station'] === $pickup_station) {
                continue;
            }
            if ((int) $row['unload_station'] !== ($job_name === 'STG-SCULLY' ? 15 : 14)) {
                continue;
            }
            if ((int) $row['station'] === 3) {
                continue;
            }
            if (mysqli_query(
                $dbc,
                'UPDATE cars
                 SET current_location_id = "' . (int) $yard_id . '",
                     handled_by_job_id = 0,
                     position = 0
                 WHERE id = "' . (int) $car_id . '"'
            )) {
                $moved++;
            }
        }
    }

    return $moved;
}

function hart_seed_island_spotting($dbc, $target_count)
{
    if ($target_count <= 0) {
        return 0;
    }

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         LEFT JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = 3
           AND cars.current_location_id > 0'
    );
    $have = (int) mysqli_fetch_array($rs)['c'];
    if ($have >= $target_count) {
        return 0;
    }

    $need = $target_count - $have;
    $sql = 'SELECT cars.id AS car_id,
                   cars.status,
                   shipments.loading_location,
                   shipments.unloading_location,
                   load_loc.station AS load_station,
                   unload_loc.station AS unload_station
            FROM cars
            INNER JOIN car_orders ON car_orders.car = cars.id
            INNER JOIN shipments ON shipments.id = car_orders.shipment
            LEFT JOIN locations load_loc ON load_loc.id = shipments.loading_location
            LEFT JOIN locations unload_loc ON unload_loc.id = shipments.unloading_location
            LEFT JOIN locations cur_loc ON cur_loc.id = cars.current_location_id
            WHERE (load_loc.station IN (3, 12) OR unload_loc.station IN (3, 12))
              AND (cur_loc.station IS NULL OR cur_loc.station NOT IN (3, 12))
              AND cars.current_location_id > 0
              AND car_orders.waybill_number NOT LIKE "%E%"
            ORDER BY
              CASE cars.status
                WHEN "Unloading" THEN 0
                WHEN "Loaded" THEN 1
                WHEN "Loading" THEN 2
                WHEN "Ordered" THEN 3
                ELSE 4
              END,
              cars.id';

    $rs = mysqli_query($dbc, $sql);
    $candidates = [];
    while ($row = mysqli_fetch_array($rs)) {
        $candidates[] = $row;
    }
    shuffle($candidates);

    $placed = 0;
    for ($i = 0; $i < min($need, count($candidates)); $i++) {
        $row = $candidates[$i];
        $car_id = (int) $row['car_id'];
        $unload_station = (int) $row['unload_station'];
        $load_station = (int) $row['load_station'];

        if (in_array($row['status'], ['Loaded', 'Unloading'], true) && in_array($unload_station, [3, 12], true)) {
            $spot_id = (int) $row['unloading_location'];
            $new_status = 'Unloading';
        } elseif (in_array($load_station, [3, 12], true)) {
            $spot_id = (int) $row['loading_location'];
            $new_status = $row['status'] === 'Ordered' ? 'Ordered' : $row['status'];
        } else {
            continue;
        }
        if ($spot_id <= 0) {
            continue;
        }
        if (mysqli_query(
            $dbc,
            'UPDATE cars
             SET current_location_id = "' . $spot_id . '",
                 handled_by_job_id = 0,
                 position = 0,
                 status = "' . mysqli_real_escape_string($dbc, $new_status) . '"
             WHERE id = "' . $car_id . '"'
        )) {
            $placed++;
        }
    }

    return $placed;
}

function hart_run_d749_session_start($dbc)
{
    $south_id = warm_start_location_id_by_code($dbc, 'SOUTH');
    $assigned = warm_start_assign_all_ordered_cars_at_station($dbc, 'D749', 10);
    $picked_up = warm_start_pickup_job($dbc, 'D749');
    $set_out = warm_start_setout_job_at_location($dbc, 'D749', $south_id);
    return compact('assigned', 'picked_up', 'set_out');
}

function hart_assign_nvl_scully_block($dbc)
{
    return warm_start_assign_all_ordered_cars_at_station($dbc, 'NVL', 9);
}

/**
 * Final session: CK1 picks up loaded outbound coke, calibrates if needed, weighs — ready for manual follow-up.
 */
function hart_begin_ck1_test_session($dbc, $fractions, $label, $max_unfilled, $config)
{
    $coke_before = warm_start_stats_copy_all();
    warm_start_session_stats_reset();
    $unfilled = warm_start_count_unfilled($dbc);
    $session = warm_start_get_session($dbc) + 1;
    warm_start_set_session($dbc, $session);
    $suffix = $label !== '' ? " ({$label})" : '';

    if ($unfilled > $max_unfilled) {
        warm_start_log(
            "Session {$session}{$suffix}: skipping generate ({$unfilled} unfilled > {$max_unfilled})"
        );
    } else {
        $waybill_counter = warm_start_get_next_auto_waybill_counter($dbc, $session);
        $generated = warm_start_generate_orders($dbc, $session, $waybill_counter);
        warm_start_log("Session {$session}{$suffix}: generated {$generated} orders");
    }

    $reposition_fraction = (float) ($fractions['reposition'] ?? $config['reposition_fraction'] ?? 0.65);
    $filled = warm_start_auto_fill($dbc, $fractions['fill_orders']);
    $repositioned = warm_start_create_reposition_orders($dbc, $reposition_fraction);
    $load_unload = warm_start_load_unload($dbc, $fractions['load_unload']);
    $load_unload += warm_start_load_unload($dbc, $fractions['load_unload']);

    $ck1_assigned = warm_start_auto_assign_job_at_station($dbc, 'CK1', 12, 1.0);
    $ck1_picked = warm_start_pickup_job($dbc, 'CK1');
    $calibration = ['calibrated' => false, 'skipped' => true];
    if (function_exists('track_scale_maybe_calibrate_scale')) {
        $calibration = track_scale_maybe_calibrate_scale($dbc, $config);
    }

    $ck1_id = warm_start_job_id($dbc, 'CK1');
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND cars.status = "Loaded"
           AND shipments.code IN (' . hart_outbound_coke_shipment_codes_sql_in($dbc) . ')'
    );
    $on_train = (int) mysqli_fetch_array($rs)['c'];

    warm_start_log(
        "Session {$session}{$suffix}: filled={$filled} repo={$repositioned} load/unload={$load_unload}; "
        . "CK1 outbound assigned={$ck1_assigned} picked up={$ck1_picked} on train={$on_train}"
    );
    if (!empty($calibration['calibrated'])) {
        warm_start_log("Session {$session}{$suffix}: track scale calibrated");
    }

    hart_log_ck1_outbound_cars($dbc, $session);

    $ck1_test = $config['ck1_test'] ?? [];
    $min_on_train = (int) ($ck1_test['min_outbound_on_train'] ?? 1);
    $weigh_required = (bool) ($ck1_test['weigh_required'] ?? true);
    $weigh = ['success' => true, 'weighed' => 0, 'reloads' => 0, 'outbound_assignments' => 0, 'errors' => []];

    if ($on_train < $min_on_train) {
        $weigh['success'] = false;
        $weigh['errors'][] = "CK1 has {$on_train} outbound coke on train (need >= {$min_on_train})";
    } elseif ($weigh_required && function_exists('track_scale_run_ck1_scale_ops')) {
        $weigh = track_scale_run_ck1_scale_ops($dbc);
        warm_start_log(
            "Session {$session}{$suffix}: weighed={$weigh['weighed']} outbound={$weigh['outbound_assignments']} "
            . "reloads={$weigh['reloads']}"
        );
        if (!empty($weigh['errors'])) {
            foreach ($weigh['errors'] as $error) {
                warm_start_log("Session {$session}{$suffix}: WEIGH ERROR — {$error}");
            }
        }
    }

    warm_start_session_stats_commit($session, $label, $coke_before);
    hart_log_ck1_outbound_cars($dbc, $session);

    return [
        'filled' => $filled,
        'repositioned' => $repositioned,
        'load_unload' => $load_unload,
        'ck1_assigned' => $ck1_assigned,
        'ck1_picked_up' => $ck1_picked,
        'ck1_on_train' => $on_train,
        'scale_calibrated' => !empty($calibration['calibrated']),
        'weigh' => $weigh,
        'weigh_failed' => empty($weigh['success']),
    ];
}

/**
 * Assign and pick up CK1 coke cars at Shenango so they are on the train for weighing.
 */
function hart_ck1_ensure_coke_on_train($dbc, $fraction = 1.0)
{
    if (!hart_track_scale_available()) {
        $assigned = warm_start_auto_assign_job_at_station($dbc, 'CK1', 12, $fraction);
        $picked = warm_start_pickup_job($dbc, 'CK1');
        return ['assigned' => $assigned, 'picked_up' => $picked];
    }

    $config = track_scale_load_config();
    $job_id = warm_start_job_id($dbc, 'CK1');
    if ($job_id <= 0) {
        return ['assigned' => 0, 'picked_up' => 0];
    }

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = 12
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0
           AND cars.status IN ("Loaded", "Loading")
         ORDER BY cars.id'
    );
    $candidates = [];
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            $candidates[] = $car_id;
        }
    }
    shuffle($candidates);
    $limit = (int) ceil(count($candidates) * max(0.0, min(1.0, $fraction)));

    $assigned = 0;
    for ($i = 0; $i < $limit; $i++) {
        $car_id = $candidates[$i];
        if (mysqli_query(
            $dbc,
            'UPDATE cars SET handled_by_job_id = "' . (int) $job_id . '" WHERE id = "' . $car_id . '"'
        )) {
            $assigned++;
        }
    }

    $assigned += warm_start_auto_assign_job_at_station($dbc, 'CK1', 12, $fraction);
    $picked = warm_start_pickup_job($dbc, 'CK1');

    return ['assigned' => $assigned, 'picked_up' => $picked];
}

function hart_car_has_routing_order($dbc, $car_id, $routing, $config)
{
    if (!hart_track_scale_available()) {
        return false;
    }

    $active = track_scale_get_car_active_order($dbc, $car_id);
    if ($active === null) {
        return false;
    }
    $codes = track_scale_shipment_codes_for_routing($routing, $config);
    return in_array($active['shipment_code'], $codes, true);
}

function hart_pick_coke_waybill($dbc, $car_id, $routing, $config)
{
    $orders = track_scale_get_open_orders($dbc, $car_id, $routing, $config);
    if (count($orders) > 0) {
        return $orders[0]['waybill_number'];
    }

    $codes = track_scale_shipment_codes_for_routing($routing, $config);
    shuffle($codes);
    foreach ($codes as $code) {
        if (!track_scale_car_in_pool_for_shipment($dbc, $car_id, $code, $config)) {
            continue;
        }
        $generated = track_scale_generate_order($dbc, $code, $car_id, $config, $routing);
        if (!empty($generated['success'])) {
            return $generated['waybill_number'];
        }
    }

    return null;
}

function hart_run($dbc, $config)
{
    $reseed = $config['reseed'] ?? !($config['continue_from_current'] ?? false);
    if ($reseed) {
        mt_srand((int) $config['seed']);
    }
    $continue = (bool) ($config['continue_from_current'] ?? false);
    if (!$continue) {
        hart_coke_stats_init();
    }
    warm_start_reset_session_stats_log();

    $repo_fraction = (float) ($config['reposition_fraction'] ?? 0.65);
    $full = warm_start_default_fractions($config);

    $max_unfilled = (int) ($config['max_unfilled_before_generate'] ?? 30);
    $min_sessions = (int) ($config['min_sessions'] ?? $config['completed_sessions'] ?? 3);
    $max_sessions = (int) ($config['max_sessions'] ?? 12);
    $stop_at_stg_scully = (bool) ($config['stop_when_stg_scully_ready'] ?? false);
    $stop_at_locals = !$stop_at_stg_scully && (bool) ($config['stop_when_locals_secured'] ?? false);
    $stop_at_staging = !$stop_at_locals && !$stop_at_stg_scully && (bool) ($config['stop_when_staging_ready'] ?? true);
    $run_ck1_test = (bool) ($config['run_ck1_test'] ?? false);
    $weigh_failed = false;
    $stopped_at_staging = false;
    $stopped_at_locals = false;
    $stopped_at_stg_scully = false;
    $staging_backlog = ['eligible' => 0, 'on_jobs' => 0, 'ready' => false];

    if ($stop_at_stg_scully) {
        $start_session = warm_start_get_session($dbc);
        if ($continue) {
            $advance = max(1, (int) ($config['min_sessions'] ?? 1));
            $config['stg_scully_stop_from_session'] = $start_session + $advance;
            warm_start_log(
                'Continuing warm start from session ' . $start_session
                . ' (up to ' . $max_sessions . ' session(s), stop after session '
                . $config['stg_scully_stop_from_session']
                . ', no re-seed)'
            );
        }
        if (!$continue) {
            warm_start_log(
                'Warm start simulation (seed=' . $config['seed']
                . ', min_sessions=' . $min_sessions
                . ', max_sessions=' . $max_sessions
                . ', stop=STG-SCULLY ready at Scully (NVL secured, D749 on train)'
                . ', CK1 each session'
                . ', skip_generate_if_unfilled>' . $max_unfilled
                . ', reposition_fraction=' . $repo_fraction . ')'
            );
        }

        for ($i = 0; $i < $max_sessions; $i++) {
            $result = warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, false);
            $session = warm_start_get_session($dbc);
            $staging_backlog = $result['stg_scully_backlog'] ?? warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
            if (!empty($result['stg_scully_stop_ready'])) {
                $stopped_at_stg_scully = true;
                warm_start_log(
                    "Session {$session}: STOP — STG-SCULLY ready with "
                    . ($staging_backlog['eligible'] ?? 0)
                    . ' car(s) at Scully awaiting assignment.'
                );
                break;
            }
        }

        if (!$stopped_at_stg_scully) {
            warm_start_log('STOP: max_sessions (' . $max_sessions . ') reached without STG-SCULLY-ready state.');
            $staging_backlog = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
        }
    } elseif ($stop_at_locals) {
        warm_start_log(
            'Warm start simulation (seed=' . $config['seed']
            . ', max_sessions=' . $max_sessions
            . ', stop=NVL@Scully + D749 on train (Demmler block)'
            . ', CK1 each session'
            . ', skip_generate_if_unfilled>' . $max_unfilled
            . ', reposition_fraction=' . $repo_fraction . ')'
        );

        for ($i = 0; $i < $max_sessions; $i++) {
            $result = warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, false);
            $session = warm_start_get_session($dbc);
            if (!empty($result['locals_secured'])) {
                $stopped_at_locals = true;
                warm_start_log("Session {$session}: locals secured (NVL@Scully, D749 on train with Demmler load).");
            }
        }

        if ($stopped_at_locals) {
            warm_start_log('Completed ' . $max_sessions . ' session(s); locals secured during run.');
        } else {
            warm_start_log('Completed ' . $max_sessions . ' session(s); locals not secured at end.');
        }
    } elseif ($stop_at_staging) {
        if ($continue) {
            $start_session = warm_start_get_session($dbc);
            warm_start_log(
                'Continuing warm start from session ' . $start_session
                . ' (+' . $min_sessions . ' session(s), staging deferred, no re-seed)'
            );
            for ($i = 0; $i < $min_sessions; $i++) {
                warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, false);
                $staging_backlog = warm_start_staging_backlog($dbc, $config);
                $session = warm_start_get_session($dbc);
                warm_start_log(
                    "Session {$session}: staging backlog eligible={$staging_backlog['eligible']} "
                    . "on_jobs={$staging_backlog['on_jobs']}"
                );
            }
            $stopped_at_staging = !empty($staging_backlog['ready']);
        } else {
            warm_start_log(
                'Warm start simulation (seed=' . $config['seed']
                . ', min_sessions=' . $min_sessions
                . ', max_sessions=' . $max_sessions
                . ', stop=staging ready'
                . ', skip_generate_if_unfilled>' . $max_unfilled
                . ', reposition_fraction=' . $repo_fraction . ')'
            );

            for ($i = 0; $i < $max_sessions; $i++) {
                warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, false);
                $staging_backlog = warm_start_staging_backlog($dbc, $config);
                $session = warm_start_get_session($dbc);
                warm_start_log(
                    "Session {$session}: staging backlog eligible={$staging_backlog['eligible']} "
                    . "on_jobs={$staging_backlog['on_jobs']}"
                );

                if ($session >= $min_sessions && !empty($staging_backlog['ready'])) {
                    warm_start_log('STOP: Staging jobs ready — STG-SCULLY / STG-DEMMLER can run.');
                    $stopped_at_staging = true;
                    break;
                }
            }

            if (!$stopped_at_staging) {
                warm_start_log('STOP: max_sessions (' . $max_sessions . ') reached without staging-ready state.');
            }
        }
    } else {
        $completed = (int) ($config['completed_sessions'] ?? 3);
        warm_start_log(
            'Warm start simulation (seed=' . $config['seed']
            . ', completed_sessions=' . $completed
            . ', skip_generate_if_unfilled>' . $max_unfilled
            . ', reposition_fraction=' . $repo_fraction . ')'
        );
        for ($i = 0; $i < $completed; $i++) {
            warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, true);
        }
        if ($run_ck1_test) {
            $ck1_result = hart_begin_ck1_test_session($dbc, $config['partial'], 'CK1 test', $max_unfilled, $config);
            $weigh_failed = !empty($ck1_result['weigh_failed']);
        }
    }

    warm_start_print_session_stats_report();

    if ($weigh_failed) {
        warm_start_log('');
        warm_start_log('STOP: CK1 weigh failed at session ' . warm_start_get_session($dbc) . ' — troubleshoot before continuing.');
    } elseif ($stopped_at_stg_scully) {
        warm_start_log('');
        warm_start_log(
            'Ready for STG-SCULLY — session ' . warm_start_get_session($dbc)
            . ' with ' . ($staging_backlog['eligible'] ?? 0)
            . ' car(s) at Scully awaiting assignment'
            . ' (NVL secured, D749 on train with Demmler block).'
        );
    } elseif ($stopped_at_locals) {
        warm_start_log('');
        warm_start_log(
            'Locals secured — session ' . warm_start_get_session($dbc)
            . ' (NVL at Scully, D749 on train with Demmler inbound block).'
        );
    } elseif ($stopped_at_staging) {
        warm_start_log('');
        warm_start_log(
            'Ready for staging — session ' . warm_start_get_session($dbc)
            . ' with ' . $staging_backlog['eligible'] . ' car(s) eligible for STG-SCULLY / STG-DEMMLER.'
        );
    } elseif ($continue && $stop_at_staging) {
        warm_start_log('');
        warm_start_log('Advanced to session ' . warm_start_get_session($dbc) . '.');
    }

    $summary = warm_start_summarize($dbc);
    $summary['weigh_failed'] = $weigh_failed;
    $summary['stopped_at_staging'] = $stopped_at_staging;
    $summary['stopped_at_locals'] = $stopped_at_locals;
    $summary['stopped_at_stg_scully'] = $stopped_at_stg_scully;
    $summary['staging_backlog'] = $staging_backlog;
    $summary['stg_scully_backlog'] = $stopped_at_stg_scully
        ? $staging_backlog
        : warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
    $summary['session_stats'] = warm_start_session_stats_log();

    return $summary;
}

function hart_summarize($dbc)
{
    $summary = ['session' => warm_start_get_session($dbc)];

    $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM car_orders');
    $summary['orders_total'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM car_orders WHERE car = "" OR car IS NULL OR car = "0"');
    $summary['orders_unfilled'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         WHERE cars.handled_by_job_id > 0
           AND cars.current_location_id > 0'
    );
    $summary['pending_pickup'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         WHERE cars.handled_by_job_id = 0
           AND cars.current_location_id > 0'
    );
    $summary['awaiting_assignment'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM cars WHERE current_location_id = 0 AND handled_by_job_id > 0');
    $summary['in_train_assigned'] = (int) mysqli_fetch_array($rs)['c'];

    $staging_quoted = implode(', ', array_map(function ($name) {
        return '"' . addslashes($name) . '"';
    }, ['STG-SCULLY', 'STG-DEMMLER']));
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE jobs.name IN (' . $staging_quoted . ')
           AND cars.handled_by_job_id > 0
           AND cars.current_location_id > 0'
    );
    $summary['staging_pending_pickup'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE jobs.name NOT IN (' . $staging_quoted . ')
           AND cars.handled_by_job_id > 0'
    );
    $summary['non_staging_on_jobs'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         LEFT JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = 3
           AND cars.current_location_id > 0'
    );
    $summary['island_cars'] = (int) mysqli_fetch_array($rs)['c'];

    $south_id = warm_start_location_id_by_code($dbc, 'SOUTH');
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c FROM cars WHERE current_location_id = "' . (int) $south_id . '"'
    );
    $summary['south_yard_cars'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station IN (14, 15)
           AND cars.status IN ("Loading", "Unloading", "Ordered")
           AND cars.current_location_id > 0'
    );
    $summary['offline_ready'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c FROM car_orders WHERE waybill_number LIKE "%E%"'
    );
    $summary['reposition_orders'] = (int) mysqli_fetch_array($rs)['c'];

    $coke = hart_coke_stats();
    $summary['coke_reloads'] = (int) ($coke['reloads'] ?? 0);
    $summary['coke_outbound_assignments'] = (int) ($coke['outbound_assignments'] ?? 0);
    $summary['coke_complete_deliveries'] = (int) ($coke['complete_deliveries'] ?? 0);
    $summary['coke_weighed'] = (int) ($coke['weighed'] ?? 0);

    $ck1_id = warm_start_job_id($dbc, 'CK1');
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND cars.status = "Loaded"
           AND shipments.code IN (' . hart_outbound_coke_shipment_codes_sql_in($dbc) . ')'
    );
    $summary['ck1_outbound_on_train'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         WHERE status = "Empty"
           AND current_location_id > 0
           AND home_location > 0
           AND current_location_id != home_location
           AND id NOT IN (SELECT car FROM car_orders WHERE car IS NOT NULL AND car != "" AND car != "0")'
    );
    $summary['empties_off_home'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query($dbc, 'SELECT status, COUNT(*) AS c FROM cars GROUP BY status');
    $summary['cars_by_status'] = [];
    while ($row = mysqli_fetch_array($rs)) {
        $summary['cars_by_status'][$row['status']] = (int) $row['c'];
    }

    return $summary;
}

/**
 * Evaluate unfilled orders, empties, staging backlog, and per-job assign eligibility.
 */
function hart_evaluate_session_prep($dbc, $config = [])
{
    $config = array_merge(warm_start_default_config(), $config);
    $staging_jobs = warm_start_staging_job_names($dbc, $config);
    $scully_backlog = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);

    $unfilled_waybills = fill_order_get_unfilled_waybills($dbc);
    $unfilled_preview = [];
    foreach (array_slice($unfilled_waybills, 0, 12) as $waybill) {
        $detail = fill_order_get_details($dbc, $waybill);
        if (!$detail) {
            continue;
        }
        $unfilled_preview[] = [
            'waybill' => $waybill,
            'shipment' => $detail['shipment_code'] ?? '',
            'commodity' => $detail['commodity'] ?? '',
        ];
    }

    $empties_off_home = [];
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.reporting_marks,
                loc.code AS current_code,
                home.code AS home_code
         FROM cars
         LEFT JOIN locations loc ON loc.id = cars.current_location_id
         LEFT JOIN locations home ON home.id = cars.home_location
         WHERE cars.status = "Empty"
           AND cars.current_location_id > 0
           AND cars.home_location > 0
           AND cars.current_location_id != cars.home_location
           AND cars.id NOT IN (
                 SELECT car FROM car_orders WHERE car IS NOT NULL AND car != "" AND car != "0"
           )
         ORDER BY cars.id
         LIMIT 15'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $empties_off_home[] = $row;
    }

    $job_eligible = [];
    $jobs_rs = mysqli_query($dbc, 'SELECT name FROM jobs ORDER BY name');
    while ($job = mysqli_fetch_array($jobs_rs)) {
        $name = $job['name'];
        if (warm_start_is_staging_job($name, $staging_jobs)) {
            continue;
        }
        $job_eligible[$name] = count(auto_assign_eligible_car_ids_for_job($dbc, $name, true));
    }

    $d749_id = warm_start_job_id($dbc, 'D749');
    $d749_on_train = 0;
    if ($d749_id > 0) {
        $rs = mysqli_query(
            $dbc,
            'SELECT COUNT(*) AS c FROM cars
             WHERE handled_by_job_id = "' . (int) $d749_id . '"
               AND current_location_id = 0'
        );
        $d749_on_train = (int) mysqli_fetch_array($rs)['c'];
    }

    $summary = warm_start_summarize($dbc);

    return [
        'session' => warm_start_get_session($dbc),
        'unfilled_count' => count($unfilled_waybills),
        'unfilled_preview' => $unfilled_preview,
        'empties_off_home_count' => (int) ($summary['empties_off_home'] ?? 0),
        'empties_off_home_preview' => $empties_off_home,
        'reposition_orders' => (int) ($summary['reposition_orders'] ?? 0),
        'stg_scully_backlog' => $scully_backlog,
        'awaiting_assignment' => (int) ($summary['awaiting_assignment'] ?? 0),
        'd749_on_train' => $d749_on_train,
        'offline_ready' => (int) ($summary['offline_ready'] ?? 0),
        'job_eligible' => $job_eligible,
        'summary' => $summary,
    ];
}

/**
 * Begin a live operating session from warm-start end state:
 * load/unload, increment session, fill unfilled orders, reposition empties, auto-assign.
 */
function hart_begin_operating_session($dbc, array $options = [])
{
    $config = warm_start_merge_config($options['config'] ?? []);
    $load_unload = !isset($options['load_unload']) || $options['load_unload'];
    $increment = !isset($options['increment']) || $options['increment'];
    $reposition = !isset($options['reposition']) || $options['reposition'];
    $assign = !isset($options['assign']) || $options['assign'];
    $fill = !isset($options['fill']) || $options['fill'];
    $generate = !empty($options['generate']);
    $run_stg_scully = !empty($options['run_stg_scully']);
    $repo_fraction = (float) ($options['reposition_fraction'] ?? $config['reposition_fraction'] ?? 0.65);
    $fill_fraction = (float) ($options['fill_fraction'] ?? $config['fill_orders'] ?? 1.0);
    $staging_jobs = warm_start_staging_job_names($dbc, $config);

    $result = [
        'previous_session' => warm_start_get_session($dbc),
        'session' => warm_start_get_session($dbc),
        'stg_scully' => ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0, 'skipped' => true],
        'load_unload' => 0,
        'filled' => 0,
        'repositioned' => 0,
        'generated' => 0,
        'assigned' => 0,
        'assigned_by_job' => [],
        'warnings' => [],
        'errors' => [],
        'blocked' => false,
        'evaluation' => null,
    ];

    $before = warm_start_evaluate_session_prep($dbc, $config);
    if ($run_stg_scully) {
        $result['stg_scully'] = array_merge(
            warm_start_complete_staging_jobs($dbc, ['STG-SCULLY'], $config, 1.0),
            ['skipped' => false]
        );
        $before = warm_start_evaluate_session_prep($dbc, $config);
    }

    if (!empty($before['stg_scully_backlog']['ready'])) {
        $eligible = (int) ($before['stg_scully_backlog']['eligible'] ?? 0);
        $on_jobs = (int) ($before['stg_scully_backlog']['on_jobs'] ?? 0);
        if ($run_stg_scully) {
            $result['errors'][] = 'STG-SCULLY still has backlog after automated run ('
                . $eligible . ' eligible at Scully, ' . $on_jobs . ' on job).';
        } else {
            $result['errors'][] = 'STG-SCULLY has not run — '
                . $eligible . ' car(s) eligible at Scully, ' . $on_jobs . ' on job. '
                . 'Complete STG-SCULLY in STS or pass --run-stg-scully.';
        }
        $result['blocked'] = true;
        $result['evaluation'] = $before;
        return $result;
    }

    if ($load_unload) {
        $result['load_unload'] = warm_start_load_unload($dbc, 1.0);
        $result['load_unload'] += warm_start_load_unload($dbc, 1.0);
    }

    if ($increment) {
        $result['previous_session'] = warm_start_get_session($dbc);
        $result['session'] = warm_start_set_session($dbc, $result['previous_session'] + 1);
    }

    if ($generate) {
        $waybill_counter = warm_start_get_next_auto_waybill_counter($dbc, $result['session']);
        $result['generated'] = warm_start_generate_orders($dbc, $result['session'], $waybill_counter);
    }

    if ($fill) {
        $result['filled'] = warm_start_auto_fill($dbc, $fill_fraction);
    }

    if ($reposition) {
        $result['repositioned'] = warm_start_create_reposition_orders($dbc, $repo_fraction);
    }

    if ($assign) {
        $jobs_rs = mysqli_query($dbc, 'SELECT id, name FROM jobs ORDER BY name');
        while ($job = mysqli_fetch_array($jobs_rs)) {
            $job_name = $job['name'];
            if (warm_start_is_staging_job($job_name, $staging_jobs)) {
                continue;
            }
            $eligible = array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
            $assigned = warm_start_assign_cars_to_job($dbc, $job_name, $eligible);
            if ($assigned > 0) {
                $result['assigned_by_job'][$job_name] = $assigned;
                $result['assigned'] += $assigned;
            }
        }
    }

    $result['evaluation'] = warm_start_evaluate_session_prep($dbc, $config);
    return $result;
}

function hart_format_begin_session_report(array $result)
{
    $lines = [];
    $blocked = !empty($result['blocked']);
    $lines[] = $blocked ? '=== Begin operating session (blocked) ===' : '=== Begin operating session ===';

    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $error) {
            $lines[] = 'ERROR: ' . $error;
        }
        $lines[] = '';
    }
    if (!empty($result['warnings'])) {
        foreach ($result['warnings'] as $warning) {
            $lines[] = 'WARNING: ' . $warning;
        }
        $lines[] = '';
    }

    if ($blocked) {
        $eval = $result['evaluation'] ?? [];
        $scully = $eval['stg_scully_backlog'] ?? [];
        $lines[] = 'Session: ' . ($result['session'] ?? '?') . ' (unchanged)';
        $stg = $result['stg_scully'] ?? [];
        if (empty($stg['skipped'])) {
            $lines[] = 'STG-SCULLY attempted: assigned=' . (int) ($stg['assigned'] ?? 0)
                . ' pickup=' . (int) ($stg['picked_up'] ?? 0)
                . ' setout=' . (int) ($stg['set_out'] ?? 0)
                . ' load/unload=' . (int) ($stg['load_unload'] ?? 0);
        }
        $lines[] = 'STG-SCULLY eligible at Scully: ' . (int) ($scully['eligible'] ?? 0);
        $lines[] = 'STG-SCULLY on job: ' . (int) ($scully['on_jobs'] ?? 0);
        $lines[] = '';
        $lines[] = 'No session prep was run. Clear STG-SCULLY, then retry.';
        return implode(PHP_EOL, $lines);
    }

    if (($result['previous_session'] ?? 0) !== ($result['session'] ?? 0)) {
        $lines[] = 'Session: ' . ($result['previous_session'] ?? '?')
            . ' → ' . ($result['session'] ?? '?');
    } else {
        $lines[] = 'Session: ' . ($result['session'] ?? '?') . ' (not incremented)';
    }
    $stg = $result['stg_scully'] ?? [];
    if (empty($stg['skipped'])) {
        $lines[] = 'STG-SCULLY: assigned=' . (int) ($stg['assigned'] ?? 0)
            . ' pickup=' . (int) ($stg['picked_up'] ?? 0)
            . ' setout=' . (int) ($stg['set_out'] ?? 0)
            . ' load/unload=' . (int) ($stg['load_unload'] ?? 0);
    }
    $lines[] = 'Load/unload transitions: ' . (int) ($result['load_unload'] ?? 0);
    $lines[] = 'Orders filled: ' . (int) ($result['filled'] ?? 0);
    $lines[] = 'Reposition orders created: ' . (int) ($result['repositioned'] ?? 0);
    if (!empty($result['generated'])) {
        $lines[] = 'Orders generated: ' . (int) $result['generated'];
    }
    $lines[] = 'Cars assigned to jobs: ' . (int) ($result['assigned'] ?? 0);
    $lines[] = '';

    $eval = $result['evaluation'] ?? [];
    $lines[] = '--- Orders (not filled) ---';
    $lines[] = 'Unfilled waybills: ' . (int) ($eval['unfilled_count'] ?? 0);
    foreach ($eval['unfilled_preview'] ?? [] as $row) {
        $lines[] = '  ' . $row['waybill'] . '  ' . $row['shipment'] . '  ' . $row['commodity'];
    }
    if (($eval['unfilled_count'] ?? 0) > count($eval['unfilled_preview'] ?? [])) {
        $lines[] = '  ...';
    }

    $lines[] = '';
    $lines[] = '--- Empties ---';
    $lines[] = 'Off-home without order: ' . (int) ($eval['empties_off_home_count'] ?? 0);
    $lines[] = 'Open reposition (E) orders: ' . (int) ($eval['reposition_orders'] ?? 0);
    foreach ($eval['empties_off_home_preview'] ?? [] as $row) {
        $lines[] = '  ' . $row['reporting_marks'] . '  at ' . $row['current_code'] . '  home ' . $row['home_code'];
    }
    if (($eval['empties_off_home_count'] ?? 0) > count($eval['empties_off_home_preview'] ?? [])) {
        $lines[] = '  ...';
    }

    $lines[] = '';
    $lines[] = '--- Yard / train ---';
    $lines[] = 'D749 on train: ' . (int) ($eval['d749_on_train'] ?? 0)
        . ' (set out at South Yard to open the session)';
    $lines[] = 'Awaiting job assignment: ' . (int) ($eval['awaiting_assignment'] ?? 0);
    $lines[] = 'Offline ready for load/unload: ' . (int) ($eval['offline_ready'] ?? 0);
    $scully = $eval['stg_scully_backlog'] ?? [];
    $lines[] = 'STG-SCULLY eligible at Scully: ' . (int) ($scully['eligible'] ?? 0);

    $lines[] = '';
    $lines[] = '--- Job assign eligibility (after setup) ---';
    foreach ($eval['job_eligible'] ?? [] as $job => $count) {
        if ($count <= 0) {
            continue;
        }
        $assigned = (int) ($result['assigned_by_job'][$job] ?? 0);
        $suffix = $assigned > 0 ? " (assigned {$assigned})" : '';
        $lines[] = sprintf('  %-12s %3d eligible%s', $job, $count, $suffix);
    }

    $lines[] = '';
    $filled = (int) ($result['filled'] ?? 0);
    $unfilled = (int) ($eval['unfilled_count'] ?? 0);
    $assigned = (int) ($result['assigned'] ?? 0);
    if ($filled > 0 && $unfilled === 0) {
        $lines[] = 'All eligible orders filled and auto-assigned. Begin operating session per switch lists.';
    } elseif ($filled > 0) {
        $lines[] = "Filled {$filled} order(s); {$unfilled} unfilled remain. Review Orders in STS if needed.";
    } elseif ($unfilled > 0) {
        $lines[] = "{$unfilled} unfilled order(s) remain — no eligible cars matched auto-fill.";
    } else {
        $lines[] = 'No unfilled orders. Begin operating session (D749 South setout, then local jobs).';
    }
    if ($assigned > 0) {
        $lines[] = "Auto-assigned {$assigned} car(s) to jobs.";
    }

    return implode(PHP_EOL, $lines);
}
