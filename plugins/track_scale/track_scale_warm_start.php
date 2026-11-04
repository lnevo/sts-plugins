<?php
/**
 * Track-scale warm-start hooks: calibrate, and the per-job weigh dispatch
 * (a layout plugin can register its own weigh routine for a job).
 * Loaded by the track_scale plugin; core warm_start calls these via function_exists().
 */

/**
 * Simulate a completed UI calibration: zero residual on all pads, lock them, save.
 * Previously only marked pads "weighed" — save requires locks + ~0 residual, so the
 * workflow calibrate step silently failed and the scale went OOS after 4 sessions.
 *
 * @return array{success:bool,error?:string}
 */
function track_scale_simulate_calibration($dbc)
{
    track_scale_sync_session_calibration($dbc);
    if (track_scale_is_calibration_locked($dbc)) {
        return ['success' => false, 'error' => 'already_locked'];
    }

    track_scale_session_init();
    track_scale_reset_calibration();
    foreach (track_scale_sensor_positions() as $position) {
        // Perfect zero: error cancelled by adjustment (same end-state as the UI).
        $_SESSION['track_scale']['sensor_errors'][$position] = 0.0;
        $_SESSION['track_scale']['sensor_adjustments'][$position] = 0.0;
        track_scale_mark_sensor_locked($position);
    }

    $result = track_scale_save_calibration($dbc);
    if (empty($result['success'])) {
        return [
            'success' => false,
            'error' => (string) ($result['error'] ?? 'save_calibration failed'),
        ];
    }

    return ['success' => true];
}

/**
 * Calibrate when required (first use / OOS) or when sessions since last cal exceed threshold.
 */
function track_scale_maybe_calibrate_scale($dbc, $config = [])
{
    track_scale_sync_session_calibration($dbc);
    if (track_scale_is_calibration_locked($dbc)) {
        return ['calibrated' => false, 'skipped' => true, 'reason' => 'already_locked'];
    }

    $every = (int) ($config['scale_calibrate_every_sessions'] ?? 3);
    $sessions_since = track_scale_sessions_since_calibration($dbc);
    $needs = track_scale_requires_calibration_init($dbc)
        || track_scale_is_out_of_service($dbc)
        || $sessions_since >= $every;

    if (!$needs) {
        return ['calibrated' => false, 'skipped' => true, 'sessions_since' => $sessions_since];
    }

    $sim = track_scale_simulate_calibration($dbc);
    $ok = !empty($sim['success']);

    $out = [
        'calibrated' => $ok,
        'sessions_since' => $sessions_since,
    ];
    if (!$ok && !empty($sim['error'])) {
        $out['error'] = $sim['error'];
    }
    if ($ok) {
        $out['sessions_since_after'] = track_scale_sessions_since_calibration($dbc);
        $out['out_of_service'] = track_scale_is_out_of_service($dbc);
    }

    return $out;
}

/**
 * Weigh routines a layout plugin registers for one of its jobs, in its manifest:
 * 'track_scale_job_weigh' => ['JOB' => callable($dbc, array $ts_config): array].
 * The callable returns the same stats shape as track_scale_run_job_weigh().
 *
 * @return array<string, callable> keyed by upper-case job name
 */
function track_scale_job_weigh_handlers()
{
    $out = [];
    if (!function_exists('plugins_discover')) {
        return $out;
    }
    foreach (plugins_discover() as $manifest) {
        foreach (($manifest['track_scale_job_weigh'] ?? []) as $job => $handler) {
            $job = strtoupper(trim((string) $job));
            if ($job !== '' && !isset($out[$job]) && is_callable($handler)) {
                $out[$job] = $handler;
            }
        }
    }

    return $out;
}

function track_scale_run_job_weigh_dispatch($dbc, $job, array $config, array $ts_config)
{
    $handler = track_scale_job_weigh_handlers()[strtoupper(trim((string) $job))] ?? null;
    if ($handler !== null) {
        return call_user_func($handler, $dbc, $ts_config);
    }

    return track_scale_run_job_weigh($dbc, $job, $ts_config);
}
