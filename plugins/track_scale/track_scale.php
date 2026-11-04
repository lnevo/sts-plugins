<?php
require_once __DIR__ . '/track_scale_helpers.php';
require_once dirname(__DIR__, 2) . '/open_db.php';
track_scale_session_init();
$config = track_scale_load_config();
// Sync calibration once on page load so the AJAX read endpoints
// (cars_at_scale / calibration_state) can stay side-effect free.
$track_scale_dbc = open_db();
track_scale_sync_session_calibration($track_scale_dbc);
// Fresh calibration UI: car at the scale spot does not count as placed on a sensor.
if (!track_scale_is_calibration_locked($track_scale_dbc)) {
    $track_scale_any_weighed = false;
    foreach (track_scale_sensor_positions() as $track_scale_pos) {
        if (track_scale_sensor_has_reading($track_scale_pos)) {
            $track_scale_any_weighed = true;
            break;
        }
    }
    if (!$track_scale_any_weighed) {
        track_scale_set_scale_car_position(null);
        track_scale_clear_sensor_locks();
    }
}
$track_scale_ui = [
    'siteLabel' => track_scale_site_label($config),
    'routedTrainsLabel' => track_scale_routed_trains_label($config),
    'scaleLocation' => track_scale_loading_location_code($config),
];
$track_scale_test_car_marks = strtoupper(trim((string) (($config['calibration'] ?? [])['test_car_reporting_marks'] ?? '')));
$track_scale_test_car_options = track_scale_test_car_roster_options($config, $track_scale_dbc);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Track Scale</title>
    <link href="vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .scale-display {
            background: #1a1a1a;
            color: #39ff14;
            font-family: "Courier New", Courier, monospace;
            border: 4px solid #333;
            border-radius: 8px;
            padding: 1.25rem 1.5rem;
            box-shadow: inset 0 0 24px rgba(0, 0, 0, 0.6);
        }
        .scale-display .label {
            color: #6bdc6b;
            font-size: 0.75rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .scale-display .value {
            font-size: 2.4rem;
            font-weight: 700;
            line-height: 1.1;
            letter-spacing: 0.06em;
        }
        .scale-display.is-settling .value {
            transition: none;
            opacity: 0.92;
        }
        .scale-display .unit {
            font-size: 1rem;
            color: #6bdc6b;
        }
        .scale-display.position-relative {
            position: relative;
        }
        .scale-led-wrap {
            position: absolute;
            top: 0.65rem;
            right: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.55rem;
            max-width: min(58%, 22rem);
            z-index: 2;
        }
        .scale-led {
            display: none !important; /* status text only — no indicator lamp */
        }
        .scale-led[data-state="ok"] {
            background: #39ff14;
            border-color: #6bdc6b;
            box-shadow: 0 0 6px #39ff14, 0 0 14px rgba(57, 255, 20, 0.45);
        }
        .scale-led[data-state="fail"] {
            background: #ff3131;
            border-color: #ff6b6b;
            box-shadow: 0 0 6px #ff3131, 0 0 14px rgba(255, 49, 49, 0.5);
        }
        .scale-led[data-state="oos"] {
            background: #ff3131;
            border-color: #ff6b6b;
            box-shadow: 0 0 6px #ff3131, 0 0 14px rgba(255, 49, 49, 0.5);
        }
        .scale-led-label {
            font-family: "Courier New", Courier, monospace;
            font-size: 0.62rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            text-align: right;
            line-height: 1.1;
        }
        /* Status banners share one size (PASS / OOS / FAIL - …) */
        .scale-led-label.ready-label,
        .scale-led-label.oos-label,
        .scale-led-label.fail-label {
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            line-height: 1.1;
        }
        .scale-led-label.oos-label,
        .scale-led-label.fail-label {
            color: #ff6b6b;
        }
        .scale-led-label.ready-label {
            color: #39ff14;
        }
        /* Status banners: full width across top of the LED panel, right-justified */
        .scale-display.out-of-service .scale-led-wrap,
        .scale-display.out-of-range .scale-led-wrap,
        .scale-display.is-ready .scale-led-wrap,
        .scale-display.is-weighing .scale-led-wrap {
            left: 0.65rem;
            right: 0.65rem;
            top: 0.55rem;
            max-width: none;
            width: auto;
            justify-content: flex-end;
        }
        .scale-display.out-of-service .scale-led-label.oos-label,
        .scale-display.out-of-range .scale-led-label.fail-label,
        .scale-display.is-ready .scale-led-label.ready-label,
        .scale-display.is-weighing .scale-led-label.weighing-label {
            display: block;
            width: 100%;
            text-align: right;
            white-space: nowrap;
            letter-spacing: 0.12em;
        }
        .scale-led-label.weighing-label {
            color: #ffd666;
        }
        .scale-display.out-of-range .scale-led-label.fail-label {
            letter-spacing: 0.06em;
            font-size: 1.15rem;
        }
        .scale-display.out-of-range .value,
        .scale-display.out-of-range .unit {
            color: #ff6b6b;
        }
        .scale-display.out-of-range .label {
            color: #ff8888;
        }
        .scale-display.out-of-service .value,
        .scale-display.out-of-service .unit {
            color: #ff3131;
        }
        .scale-display.out-of-service .label {
            color: #ff8888;
        }
        .car-photo {
            background: #eee;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            min-height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .car-photo img {
            max-width: 100%;
            max-height: 220px;
            object-fit: contain;
        }
        .car-panel-header {
            margin-bottom: 0.75rem;
            text-align: center;
        }
        .car-panel-header #carMarks {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        .car-panel-header #carMeta {
            font-size: 1rem;
            line-height: 1.35;
        }
        .car-panel-body {
            display: flex;
            flex-wrap: wrap;
            align-items: stretch;
            gap: 0.75rem;
            container-type: inline-size;
        }
        .car-panel-body .car-photo-col {
            flex: 1 1 calc(100% - 24rem - 0.75rem);
            min-width: 12rem;
            max-width: 100%;
        }
        .car-panel-body .car-stats-col {
            flex: 1 1 24rem;
            min-width: 24rem;
            max-width: 100%;
        }
        .car-panel-body .car-photo {
            width: 100%;
            min-height: 100%;
            height: 100%;
        }
        @container (max-width: 37rem) {
            .car-panel-body .car-photo-col,
            .car-panel-body .car-stats-col {
                flex: 1 1 100%;
                min-width: 100%;
                max-width: 100%;
            }
            .car-panel-body .car-photo {
                aspect-ratio: 16 / 9;
                height: auto;
                min-height: 0;
                max-height: 14rem;
            }
        }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem 0.65rem;
            height: 100%;
        }
        .stat-box {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 0.5rem 0.6rem;
        }
        .stat-box .stat-label {
            font-size: 0.65rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            line-height: 1.15;
            white-space: nowrap;
        }
        .stat-box .stat-value {
            font-size: 1rem;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
        }
        @media (max-width: 767.98px) {
            .car-panel-header #carMarks {
                font-size: 1.25rem;
            }
            .car-panel-header #carMeta {
                font-size: 0.88rem;
            }
            .car-panel-body {
                gap: 0.5rem;
            }
            .car-panel-body .car-stats-col {
                flex: 1 1 100%;
                min-width: 100%;
            }
            .car-panel-body .car-photo-col {
                flex: 1 1 100%;
            }
            .car-photo {
                min-height: 0;
                aspect-ratio: 16 / 9;
                height: auto;
                max-height: 12rem;
            }
            .car-photo img {
                max-height: 100%;
            }
            .stat-grid {
                gap: 0.35rem 0.5rem;
                height: auto;
            }
            .stat-box {
                padding: 0.35rem 0.45rem;
                border-radius: 0.25rem;
            }
            .stat-box .stat-label {
                font-size: 0.58rem;
            }
            .stat-box .stat-value {
                font-size: 0.85rem;
            }
        }
        .routing-reload {
            display: block;
            flex: 1 1 100%;
            width: 100%;
            background-color: #dc3545;
            color: #fff;
            font-weight: 600;
            padding: 0.65rem 0.85rem;
            border-radius: 0.375rem;
            border: 2px solid #a71d2a;
        }
        .routing-reload .bi {
            color: #fff;
        }
        .order-select {
            width: auto;
            min-width: 16rem;
            max-width: 100%;
        }
        .order-empty-msg {
            display: inline-block;
            width: auto;
            margin-top: 0.375rem;
            font-size: 0.75rem;
            background-color: #ffc107;
            color: #212529;
            font-weight: 600;
            padding: 0.375rem 0.5625rem;
            border-radius: 0.28rem;
            border: 1px solid #e0a800;
        }
        .order-empty-msg .bi {
            color: #212529;
        }
        .routing-outbound { color: #198754; }
        .mode-panel { display: none; }
        .mode-panel.active { display: block; }
        .sensor-card {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            background: #fff;
            padding: 0.75rem;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .sensor-card > .scale-display {
            order: 1;
        }
        .sensor-card > .sensor-error-line {
            order: 2;
        }
        .sensor-card > .sensor-adj-line {
            order: 3;
        }
        .sensor-card > .cal-position-btn {
            order: 4;
            margin-top: 0.35rem;
            margin-bottom: 0.65rem !important;
            min-height: 4rem;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
            /* Match LEFT / CENTER / RIGHT title type */
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        /* Left / Center / Right labels sit at the bottom of each panel */
        .sensor-card > .sensor-card-heading {
            order: 5;
            margin-top: auto;
            margin-bottom: 0 !important;
            text-align: center;
        }
        .sensor-card .sensor-card-title {
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .sensor-card .scale-display {
            padding: 0.75rem 1rem;
        }
        .sensor-card .scale-display .value {
            font-size: 1.6rem;
        }
        .sensor-card.calibrated {
            border-color: #2e7d32;
            box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.35);
            background: #e8f5e9;
        }
        .sensor-card.calibrated.car-at-position {
            border-color: #198754;
            box-shadow: 0 0 0 2px rgba(25, 135, 84, 0.45);
            background: #d1e7dd;
        }
        .cal-central-panel.is-ready {
            border-color: #198754;
            background: #e8f8ee;
            box-shadow: 0 0 0 1px rgba(25, 135, 84, 0.2);
        }
        .scale-display.is-ready .value,
        .scale-display.is-ready .unit,
        .scale-display.is-ready .label {
            color: #39ff14;
        }
        /* Ready wins over OOS red if both classes briefly overlap */
        .scale-display.out-of-service.is-ready .value,
        .scale-display.out-of-service.is-ready .unit,
        .scale-display.out-of-service.is-ready .label {
            color: #39ff14;
        }
        .sensor-card.calibrated .scale-display .value,
        .sensor-card.calibrated .scale-display .unit {
            color: #39ff14;
        }
        .sensor-card.car-at-position {
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.25);
            background: #f8fbff;
        }
        .sensor-card.adjustment-locked .cal-adj-group {
            opacity: 0.45;
        }
        .sensor-card .cal-position-btn.active,
        .sensor-card .cal-position-btn.cal-lock-phase {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }
        .sensor-card .cal-position-btn.cal-lock-phase:hover:not(:disabled) {
            background-color: #0b5ed7;
            border-color: #0a58ca;
            color: #fff;
        }
        .sensor-card .cal-position-btn.cal-locked-phase,
        .sensor-card .cal-position-btn.cal-locked-phase:disabled {
            background-color: #198754;
            border-color: #198754;
            color: #fff;
            opacity: 1;
        }
        .sensor-card .sensor-title-short {
            display: none;
        }
        .cal-sensor-row > [class*="col-"] {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
            min-width: 0;
        }
        @media (max-width: 575.98px) {
            .cal-sensor-row {
                --bs-gutter-x: 0.45rem;
                --bs-gutter-y: 0.45rem;
            }
            .cal-sensor-row .sensor-card {
                padding: 0.45rem 0.4rem;
            }
            .cal-sensor-row .sensor-card > .mb-2 {
                margin-bottom: 0.35rem !important;
            }
            .cal-sensor-row .sensor-card .sensor-card-title {
                font-size: 0.78rem;
                line-height: 1.15;
                display: block;
            }
            .cal-sensor-row .sensor-card .sensor-title-full {
                display: none;
            }
            .cal-sensor-row .sensor-card .sensor-title-short {
                display: inline;
            }
            .cal-sensor-row .sensor-card .scale-display {
                padding: 0.4rem 0.35rem;
                margin-bottom: 0.35rem !important;
            }
            .cal-sensor-row .sensor-card .scale-display .label {
                font-size: 0.6rem;
            }
            .cal-sensor-row .sensor-card .scale-display .value {
                font-size: 1.05rem;
            }
            .cal-sensor-row .sensor-card .scale-display .unit {
                font-size: 0.7rem;
            }
            .cal-sensor-row .sensor-card .sensor-error-line {
                font-size: 0.7rem;
                margin-bottom: 0.35rem !important;
            }
            .cal-sensor-row .sensor-card .cal-position-btn {
                font-size: 0.78rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                padding: 0.7rem 0.25rem;
                min-height: 4.3rem;
                margin-bottom: 0 !important;
            }
            .cal-sensor-row .sensor-card .cal-position-btn .bi {
                display: none;
            }
            .cal-central-panel .cal-central-stack {
                column-gap: 0.55rem;
                row-gap: 1.15rem;
            }
            .cal-central-panel .cal-stat-label {
                font-size: 0.62rem;
            }
            .cal-central-panel .cal-stat-value {
                font-size: 1rem;
            }
            .cal-central-panel .cal-stat-error .cal-stat-value {
                font-size: 1.1rem;
            }
            .cal-central-panel .cal-central-adj-wrap {
                max-width: 100%;
            }
        }
        .cal-track-wrap {
            margin-bottom: 1rem;
        }
        .cal-track-rail {
            position: relative;
            background: linear-gradient(180deg, #e9ecef 0%, #ced4da 100%);
            border: 1px solid #adb5bd;
            border-radius: 0.375rem;
            padding: 0.5rem 0.5rem 3.25rem;
            min-height: 4.5rem;
        }
        .cal-track-car {
            position: absolute;
            bottom: 0.35rem;
            width: 33.333%;
            display: flex;
            justify-content: center;
            align-items: flex-end;
            transition: left 0.35s ease;
            pointer-events: none;
        }
        .cal-track-car.position-left { left: 0; }
        .cal-track-car.position-center { left: 33.333%; }
        .cal-track-car.position-right { left: 66.666%; }
        .cal-track-car img {
            max-height: 4.5rem;
            max-width: 92%;
            object-fit: contain;
            filter: drop-shadow(0 2px 5px rgba(0, 0, 0, 0.25));
        }
        .cal-track-car .cal-track-car-placeholder {
            font-size: 0.75rem;
            color: #6c757d;
            padding-bottom: 0.5rem;
        }
        :root {
            --scale-top-row-height: 150px;
        }
        body.track-scale-accessible {
            --scale-top-row-height: 168px;
        }
        /* Shared LCD height for weigh + calibrate top panels */
        #weighPanel > .scale-top-row .scale-display,
        #calibratePanel > .scale-top-row .scale-display {
            flex: 1 1 auto;
            width: 100%;
            height: var(--scale-top-row-height);
            min-height: var(--scale-top-row-height);
            max-height: var(--scale-top-row-height);
            box-sizing: border-box;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        #weighPanel > .scale-top-row > [class*="col-"] {
            display: flex;
        }
        #weighPanel > .scale-top-row {
            /* Row may wrap on narrow screens; each LCD stays fixed height */
            min-height: var(--scale-top-row-height);
            box-sizing: border-box;
        }
        /* Single weigh LCD: gross left, net/status right (same spot as former right panel) */
        #weighPanel .scale-weigh-readings {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            width: 100%;
            min-height: 0;
        }
        #weighPanel .scale-weigh-gross {
            flex: 1 1 auto;
            min-width: 0;
        }
        #weighPanel .scale-weigh-net {
            flex: 0 0 50%;
            max-width: 50%;
            box-sizing: border-box;
            padding-left: 0.75rem;
        }
        @media (max-width: 767.98px) {
            #weighPanel .scale-weigh-readings {
                flex-direction: column;
                align-items: stretch;
                justify-content: center;
                gap: 0.35rem;
            }
            #weighPanel .scale-weigh-net {
                flex: 0 0 auto;
                max-width: none;
                width: 100%;
                padding-left: 0;
            }
        }
        .sensor-average.scale-top-row {
            display: flex;
            height: var(--scale-top-row-height);
            min-height: var(--scale-top-row-height);
            max-height: var(--scale-top-row-height);
            box-sizing: border-box;
        }
        #weighPanel > .scale-top-row .scale-display .value,
        #calibratePanel > .scale-top-row .scale-display .value {
            font-size: 2.4rem;
        }
        #weighPanel .scale-led-label.ready-label,
        #weighPanel .scale-led-label.oos-label,
        #weighPanel .scale-led-label.fail-label,
        #calibratePanel .scale-led-label.ready-label,
        #calibratePanel .scale-led-label.oos-label,
        #calibratePanel .scale-led-label.fail-label {
            font-size: 1.35rem;
        }
        /* Keep train reassign note on its own row so it never collapses beside action buttons. */
        #inTrainAssignNote.in-train-assign-note {
            display: block;
            width: 100%;
            max-width: 100%;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        #inTrainAssignNote.in-train-assign-note.d-none {
            display: none !important;
        }
        .car-list-item {
            cursor: pointer;
            transition: background-color 0.15s;
        }
        .car-list-item:hover { background-color: #f0fff4; }
        .car-list-item.active {
            background-color: #d1e7dd;
            border-color: #2e7d32 !important;
        }
        .car-list-position {
            font-size: 0.75rem;
            color: #6c757d;
            min-width: 2rem;
            text-align: right;
        }
        .car-list-marks {
            font-weight: 400;
        }
        .status-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.45rem;
            border-radius: 0.25rem;
        }
        .access-toggle-wrap {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            color: #fff;
            font-size: 0.9rem;
            white-space: nowrap;
            user-select: none;
        }
        .access-toggle-wrap .form-check-input {
            width: 2.6rem;
            height: 1.35rem;
            margin: 0;
            cursor: pointer;
            background-color: rgba(255, 255, 255, 0.35);
            border-color: rgba(255, 255, 255, 0.65);
        }
        .access-toggle-wrap .form-check-input:checked {
            background-color: #fff;
            border-color: #fff;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23198754'/%3e%3c/svg%3e");
        }
        .access-toggle-wrap .form-check-input:focus {
            box-shadow: 0 0 0 0.2rem rgba(255, 255, 255, 0.35);
        }
        .access-toggle-wrap label {
            cursor: pointer;
            margin: 0;
            line-height: 1.2;
        }

        /* Larger controls mode — same layout, bigger hit targets / type */
        body.track-scale-accessible {
            font-size: 1.2rem;
        }
        body.track-scale-accessible .navbar-brand {
            font-size: 1.45rem;
        }
        body.track-scale-accessible .access-toggle-wrap {
            font-size: 1.1rem;
            gap: 0.7rem;
        }
        body.track-scale-accessible .access-toggle-wrap .form-check-input {
            width: 3.1rem;
            height: 1.65rem;
        }
        body.track-scale-accessible .container {
            max-width: 1100px !important;
        }
        body.track-scale-accessible h5 {
            font-size: 1.55rem;
        }
        body.track-scale-accessible .text-muted.small,
        body.track-scale-accessible .small {
            font-size: 1.05rem !important;
        }
        body.track-scale-accessible .btn-group .btn {
            font-size: 1.2rem;
            padding: 0.7rem 1.15rem;
            min-height: 3rem;
        }
        body.track-scale-accessible .btn-lg {
            font-size: 1.35rem;
            padding: 0.85rem 1.35rem;
            min-height: 3.4rem;
        }
        body.track-scale-accessible .btn:not(.btn-lg):not(.btn-sm) {
            font-size: 1.15rem;
            padding: 0.65rem 1.1rem;
            min-height: 2.9rem;
        }
        body.track-scale-accessible .btn-sm {
            font-size: 1.1rem;
            padding: 0.55rem 0.95rem;
            min-height: 2.75rem;
        }
        body.track-scale-accessible .btn.cal-adj-btn,
        body.track-scale-accessible .btn.cal-adj-reset-btn {
            font-size: 1.15rem;
            min-height: 3rem;
            padding-top: 0.65rem;
            padding-bottom: 0.65rem;
        }
        body.track-scale-accessible .btn.cal-position-btn,
        body.track-scale-accessible .btn.cal-weigh-btn {
            font-size: 1.25rem;
            font-weight: 700;
            min-height: 6rem;
            padding-top: 1.3rem;
            padding-bottom: 1.3rem;
        }
        body.track-scale-accessible .cal-adj-group .btn {
            min-width: 3rem;
        }
        body.track-scale-accessible .cal-adj-group .form-control {
            font-size: 1.35rem;
            min-height: 3rem;
            font-weight: 700;
        }
        body.track-scale-accessible .form-select,
        body.track-scale-accessible .form-control {
            font-size: 1.2rem;
            min-height: 3rem;
            padding: 0.55rem 0.9rem;
        }
        body.track-scale-accessible .form-select-sm {
            font-size: 1.1rem;
            min-height: 2.85rem;
        }
        body.track-scale-accessible .form-check-input {
            width: 1.45rem;
            height: 1.45rem;
            margin-top: 0.15rem;
        }
        body.track-scale-accessible .form-check-label {
            font-size: 1.1rem;
            padding-left: 0.25rem;
        }
        body.track-scale-accessible .form-label {
            font-size: 1.15rem;
            font-weight: 600;
        }
        body.track-scale-accessible #weighPanel > .scale-top-row .scale-display .value,
        body.track-scale-accessible #calibratePanel > .scale-top-row .scale-display .value {
            font-size: 2.6rem;
        }
        body.track-scale-accessible #weighPanel .scale-led-label.ready-label,
        body.track-scale-accessible #weighPanel .scale-led-label.oos-label,
        body.track-scale-accessible #weighPanel .scale-led-label.fail-label,
        body.track-scale-accessible #calibratePanel .scale-led-label.ready-label,
        body.track-scale-accessible #calibratePanel .scale-led-label.oos-label,
        body.track-scale-accessible #calibratePanel .scale-led-label.fail-label {
            font-size: 1.5rem;
        }
        body.track-scale-accessible .sensor-card .scale-display {
            padding: 1rem 1.15rem;
        }
        body.track-scale-accessible .sensor-card .scale-display .value {
            font-size: 2.4rem;
        }
        body.track-scale-accessible .sensor-card .scale-display .unit {
            font-size: 1.15rem;
        }
        body.track-scale-accessible .sensor-card .sensor-error-line {
            font-size: 1.15rem;
            font-weight: 600;
        }
        .cal-instructions,
        .sensor-adj-line,
        .sensor-car-here-badge,
        .cal-sensor-local-controls {
            display: none !important;
        }
        .cal-central-controls {
            display: block;
            margin-bottom: 0;
        }
        .cal-central-panel {
            border: 2px solid #198754;
            border-radius: 0.5rem;
            background: #f8fff9;
            padding: 1rem 1.15rem;
        }
        /* Status band below the adjustment slider (inside the green panel) */
        .cal-central-panel .cal-actions-meta {
            grid-column: 1 / -1;
            width: 100%;
            max-width: none;
            justify-self: stretch;
            text-align: left;
            min-height: 1.35rem;
            margin-top: 0.35rem;
            margin-bottom: 0.15rem;
            padding-top: 0.35rem;
            padding-left: 0;
        }
        .cal-central-panel .cal-central-layout {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.85rem;
            width: 100%;
        }
        .cal-central-panel .cal-central-stack {
            width: 100%;
            max-width: 52rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            column-gap: 1.25rem;
            row-gap: 1.35rem;
            align-items: start;
            justify-items: center;
        }
        .cal-central-panel .cal-central-stats-block {
            display: contents;
        }
        .cal-central-panel .cal-stats-grid {
            display: contents;
        }
        .cal-central-panel .cal-stat {
            text-align: center;
            min-width: 0;
            width: 100%;
            padding: 0.55rem 0.5rem 0.65rem;
            border: 1px solid #c5d4ca;
            border-radius: 0.4rem;
            background: #eef5f0;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.65);
        }
        /* Error stays neutral until a weigh reading exists; then red / green by value */
        .cal-central-panel .cal-stat-error.is-nonzero {
            border-color: #e2b4b8;
            background: #f8ecee;
        }
        .cal-central-panel .cal-stat-error.is-zero {
            border-color: #a6d4b5;
            background: #e4f6ea;
        }
        .cal-central-panel .cal-stat-label {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: 0.3rem;
            line-height: 1.2;
            min-height: 1rem;
        }
        .cal-central-panel .cal-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            line-height: 1.15;
        }
        .cal-central-panel .cal-stat-value .unit {
            font-size: 0.85rem;
            font-weight: 600;
            color: #6c757d;
        }
        .cal-central-panel .cal-stat-error .cal-stat-value {
            font-size: 1.4rem;
        }
        .cal-central-panel .cal-stat-error.is-nonzero .cal-stat-value {
            color: #b02a37;
        }
        .cal-central-panel .cal-stat-error.is-zero .cal-stat-value {
            color: #146c43;
        }
        /* Own grid row so stack row-gap centers it evenly between stats and slider */
        .cal-central-panel .cal-central-adj-value-row {
            grid-column: 1 / -1;
            width: 100%;
            max-width: 44rem;
            justify-self: center;
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: baseline;
            justify-content: center;
            text-align: center;
            gap: 0.45rem;
            margin: 0;
            padding: 0;
        }
        .cal-central-panel .cal-central-adj-wrap {
            grid-column: 1 / -1;
            width: 100%;
            max-width: 44rem;
            justify-self: center;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0;
            margin: 0;
        }
        .cal-central-panel .cal-central-adj-label {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #6c757d;
        }
        .cal-central-panel .cal-central-adj-value {
            font-size: 1.65rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace;
            color: #212529;
            line-height: 1.1;
        }
        .cal-central-panel .cal-central-adj-value .unit {
            font-size: 0.95rem;
            font-weight: 600;
            color: #6c757d;
            margin-left: 0.2rem;
        }
        .cal-central-panel .cal-central-adj {
            width: 100%;
            display: flex;
            flex-wrap: nowrap;
            align-items: stretch;
            gap: 0.7rem;
        }
        /* Scrollbar strip: only the drag track lives here — no number text on top. */
        .cal-central-panel .cal-central-adj-slider-cell {
            flex: 1 1 auto;
            min-width: 0;
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            column-gap: 0.55rem;
            padding: 0.45rem 0.65rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.04);
        }
        .cal-central-panel .cal-central-adj-end {
            flex: 0 0 auto;
            font-size: 0.8rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace;
            color: #495057;
            user-select: none;
            line-height: 1;
        }
        .cal-central-panel .cal-central-adj-slider-track {
            min-width: 0;
            display: flex;
            align-items: center;
            height: 2.4rem;
        }
        .cal-central-panel .cal-central-adj-slider {
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            height: 2.4rem;
            margin: 0;
            background: transparent;
            cursor: pointer;
        }
        .cal-central-panel .cal-central-adj-slider:focus {
            outline: none;
        }
        .cal-central-panel .cal-central-adj-slider:focus-visible::-webkit-slider-thumb {
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.35);
        }
        .cal-central-panel .cal-central-adj-slider::-webkit-slider-runnable-track {
            height: 0.85rem;
            border-radius: 999px;
            background: linear-gradient(180deg, #f1f3f5 0%, #e9ecef 100%);
            border: 1px solid #ced4da;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.08);
        }
        .cal-central-panel .cal-central-adj-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 1.75rem;
            height: 1.75rem;
            margin-top: calc((0.85rem - 1.75rem) / 2);
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid #fff;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
        }
        .cal-central-panel .cal-central-adj-slider::-moz-range-track {
            height: 0.85rem;
            border-radius: 999px;
            background: linear-gradient(180deg, #f1f3f5 0%, #e9ecef 100%);
            border: 1px solid #ced4da;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.08);
        }
        .cal-central-panel .cal-central-adj-slider::-moz-range-thumb {
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid #fff;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
        }
        .cal-central-panel .cal-central-adj-slider:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }
        .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="down"] {
            margin-right: 0.35rem !important;
        }
        .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="up"] {
            margin-left: 0.35rem !important;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj {
            gap: 0.9rem;
        }
        .cal-central-panel .cal-central-adj .btn {
            flex: 0 0 auto;
            min-width: 3.5rem;
            min-height: 3.85rem;
            font-size: 1.55rem;
            font-weight: 700;
            font-family: ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace;
            letter-spacing: -0.04em;
            padding-left: 0.35rem;
            padding-right: 0.35rem;
        }
        /* After base slider styles so phone width can actually override display:grid */
        @media (max-width: 767.98px) {
            .cal-central-panel .cal-central-adj-slider-cell {
                display: none !important;
            }
            .cal-central-panel .cal-central-adj-wrap {
                max-width: 100%;
                width: 100%;
            }
            .cal-central-panel .cal-central-adj {
                width: 100%;
                justify-content: stretch;
                gap: 0.4rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="down"],
            .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="up"] {
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            .cal-central-panel .cal-central-adj .btn {
                flex: 1 1 0;
                /* Keep the large default size; don't collapse as the row narrows */
                min-width: 3.5rem;
                padding-left: 0.15rem;
                padding-right: 0.15rem;
            }
        }
        /*
         * Calibrate layout: Sensors → scale-car track → adjustment panel.
         */
        #calibratePanel .card-body {
            display: flex;
            flex-direction: column;
        }
        #calibratePanel .card-body > .cal-instructions {
            order: 0;
        }
        #calibratePanel .card-body > .cal-sensor-row {
            order: 1;
            margin-bottom: 1rem !important;
        }
        #calibratePanel .card-body > .cal-track-wrap {
            order: 2;
            margin-top: 0.15rem;
            margin-bottom: 1.15rem;
        }
        #calibratePanel .card-body > .cal-central-controls {
            order: 3;
        }
        #calibratePanel .cal-actions-footer {
            margin-top: 0.85rem;
            margin-bottom: 0.5rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-actions-meta {
            max-width: none;
        }
        body.track-scale-accessible #calibratePanel .card-body > .cal-sensor-row {
            margin-bottom: 1.65rem !important;
        }
        body.track-scale-accessible #calibratePanel .card-body > .cal-track-wrap {
            margin-top: 0.85rem;
            margin-bottom: 1.35rem;
        }
        .scale-mode-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem 0.75rem;
            min-height: 2.5rem;
            margin-bottom: 1rem;
        }
        .test-car-select-wrap {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 0.5rem;
            min-width: 12rem;
            max-width: min(28rem, 100%);
            flex: 1 1 auto;
        }
        /* Keep toolbar height stable in Weigh mode (no layout jump into Calibrate) */
        body:not(.mode-calibrate) .test-car-select-wrap {
            visibility: hidden;
            pointer-events: none;
        }
        .test-car-select-wrap .form-label {
            margin-bottom: 0;
            font-size: 0.8rem;
            color: #6c757d;
            white-space: nowrap;
        }
        .test-car-select-wrap .form-select {
            width: auto;
            min-width: 11rem;
            max-width: 22rem;
            flex: 1 1 auto;
        }
        body.track-scale-accessible .test-car-select-wrap .form-label {
            font-size: 0.95rem;
        }
        body.track-scale-accessible .test-car-select-wrap .form-select {
            min-height: 3rem;
            font-size: 1.15rem;
        }
        body.track-scale-accessible .scale-mode-bar {
            min-height: 3.25rem;
        }
        /* Large-controls sizing for the shared calibrate layout */
        body.track-scale-accessible .cal-central-panel {
            padding: 1.25rem 1.35rem;
        }
        /* Stats use display:contents, so hide the cells themselves */
        body.track-scale-accessible .cal-central-panel .cal-stat {
            display: none !important;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-stack {
            max-width: 64rem;
            column-gap: 1.75rem;
            row-gap: 1.55rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-wrap,
        body.track-scale-accessible .cal-central-panel .cal-central-adj-value-row {
            max-width: 58rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-label {
            font-size: 0.95rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-value {
            font-size: 2.45rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-value .unit {
            font-size: 1.25rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj {
            gap: 1.05rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider-cell {
            padding: 0.85rem 1rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider-track,
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider {
            height: 4.25rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider::-webkit-slider-runnable-track,
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider::-moz-range-track {
            height: 1.4rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider::-webkit-slider-thumb {
            width: 2.75rem;
            height: 2.75rem;
            margin-top: calc((1.4rem - 2.75rem) / 2);
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider::-moz-range-thumb {
            width: 2.75rem;
            height: 2.75rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-end {
            font-size: 1.15rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="down"] {
            margin-right: 0.7rem !important;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="up"] {
            margin-left: 0.7rem !important;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj .btn {
            min-width: 5rem;
            min-height: 5.25rem;
            font-size: 2rem;
            padding-left: 0.7rem;
            padding-right: 0.7rem;
        }
        body.track-scale-accessible .sensor-card .cal-position-btn {
            font-size: 1.25rem;
            font-weight: 700;
            min-height: 6.5rem;
            padding: 1.5rem 0.85rem;
        }
        body.track-scale-accessible .sensor-card .sensor-card-title {
            font-size: 1.25rem;
        }
        body.track-scale-accessible #calSaveBtn,
        body.track-scale-accessible #calResetBtn,
        body.track-scale-accessible #calResetAdjBtn {
            font-size: 1.2rem;
            min-height: 3.1rem;
            padding: 0.7rem 1.15rem;
        }
        body.track-scale-accessible .scale-led {
            width: 20px;
            height: 20px;
        }
        body.track-scale-accessible .scale-led-label:not(.ready-label):not(.oos-label):not(.fail-label) {
            font-size: 0.85rem;
        }
        body.track-scale-accessible .car-panel-header #carMarks {
            font-size: 2.1rem;
        }
        body.track-scale-accessible .car-panel-header #carMeta {
            font-size: 1.25rem;
        }
        body.track-scale-accessible .stat-box {
            padding: 0.75rem 0.85rem;
        }
        body.track-scale-accessible .stat-box .stat-label {
            font-size: 0.85rem;
        }
        body.track-scale-accessible .stat-box .stat-value {
            font-size: 1.35rem;
        }
        body.track-scale-accessible .card-header {
            font-size: 1.2rem;
            padding: 0.85rem 1.1rem;
        }
        body.track-scale-accessible .card-body {
            padding: 1.15rem;
        }
        body.track-scale-accessible .list-group-item.car-list-item {
            padding: 1rem 1.15rem;
            min-height: 3.5rem;
            font-size: 1.2rem;
        }
        body.track-scale-accessible .car-list-marks {
            font-size: 1.3rem;
            font-weight: 600;
        }
        body.track-scale-accessible .car-list-position {
            font-size: 1rem;
        }
        body.track-scale-accessible .status-badge {
            font-size: 0.95rem;
            padding: 0.35rem 0.65rem;
        }
        body.track-scale-accessible .badge {
            font-size: 1rem;
            padding: 0.45em 0.7em;
        }
        body.track-scale-accessible .order-empty-msg,
        body.track-scale-accessible .alert {
            font-size: 1.1rem;
        }
        body.track-scale-accessible .sensor-card {
            padding: 1rem;
        }
        body.track-scale-accessible .sensor-card strong {
            font-size: 1.25rem;
        }
        body.track-scale-accessible .cal-track-car img {
            max-height: 5.5rem;
        }
        body.track-scale-accessible #weighResult,
        body.track-scale-accessible #assignResult {
            font-size: 1.15rem !important;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-success mb-4">
    <div class="container-fluid gap-2 flex-wrap">
        <span class="navbar-brand mb-0"><i class="bi bi-speedometer2"></i> Track Scale — <?= htmlspecialchars($track_scale_ui['siteLabel']) ?></span>
        <div class="d-flex align-items-center gap-3 ms-auto flex-wrap">
            <div class="access-toggle-wrap form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="accessibleUiToggle"
                       aria-describedby="accessibleUiHint">
                <label class="form-check-label" for="accessibleUiToggle">
                    Large controls
                </label>
            </div>
            <a href="operations.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left"></i> Operations
            </a>
        </div>
    </div>
</nav>
<span id="accessibleUiHint" class="visually-hidden">
    Larger text and buttons for the weigh and calibrate screens. Preference is saved in this browser.
</span>

<div class="container" style="max-width: 960px;">
    <div class="scale-mode-bar">
        <div class="test-car-select-wrap" id="testCarSelectWrap">
            <label class="form-label" for="testCarSelect">Scale test car</label>
            <select class="form-select form-select-sm" id="testCarSelect" aria-label="Choose scale test car from roster">
                <?php if ($track_scale_test_car_options === []): ?>
                    <option value="">No MS scale test cars</option>
                <?php else: ?>
                    <?php foreach ($track_scale_test_car_options as $opt):
                        $marks = (string) ($opt['reporting_marks'] ?? '');
                        $type = trim((string) ($opt['car_type'] ?? ''));
                        $tare = $opt['tare_tons'] ?? null;
                        $label = $marks;
                        if ($type !== '') {
                            $label .= ' — ' . $type;
                        }
                        if ($tare !== null && $tare !== '') {
                            $label .= ' · ' . number_format((float) $tare, 2) . ' t';
                        }
                        ?>
                        <option value="<?= htmlspecialchars($marks) ?>"<?= strtoupper($marks) === $track_scale_test_car_marks ? ' selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div class="btn-group" role="group" aria-label="Scale mode">
            <input type="radio" class="btn-check" name="scaleMode" id="modeWeigh" autocomplete="off" checked>
            <label class="btn btn-outline-success" for="modeWeigh"><i class="bi bi-truck"></i> Weigh</label>
            <input type="radio" class="btn-check" name="scaleMode" id="modeCalibrate" autocomplete="off">
            <label class="btn btn-outline-success" for="modeCalibrate"><i class="bi bi-sliders"></i> Calibrate</label>
        </div>
    </div>

    <!-- Weigh mode -->
    <div id="weighPanel" class="mode-panel active">
        <div class="row g-3 mb-2 scale-top-row">
            <div class="col-12">
                <div class="scale-display h-100 position-relative" id="netDisplayPanel">
                    <div class="scale-led-wrap">
                        <div class="scale-led" id="weightLed" data-state="off" title="Tolerance indicator"></div>
                        <span class="scale-led-label d-none" id="weightLedLabel"></span>
                    </div>
                    <div class="scale-weigh-readings">
                        <div class="scale-weigh-gross">
                            <div class="label">Adjusted Gross Weight</div>
                            <div><span class="value" id="displayGross">0.00</span> <span class="unit">tons</span></div>
                            <div class="small mt-2" style="color:#6bdc6b;" id="sensorBreakdown"></div>
                        </div>
                        <div class="scale-weigh-net">
                            <div id="netReadingBlock" class="d-none">
                                <div class="label">Net load</div>
                                <div><span class="value" id="displayNet">0.00</span> <span class="unit">tons</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div id="carPanel" class="d-none mb-3">
            <div class="car-panel-header">
                <h4 id="carMarks" class="mb-1"></h4>
                <p class="text-muted mb-0">
                    <span id="carMeta"></span>
                </p>
            </div>
            <div class="car-panel-body">
                <div class="car-photo-col">
                    <div class="car-photo" id="carPhotoWrap">
                        <span class="text-muted" id="carPhotoPlaceholder">No photo</span>
                        <img id="carPhoto" alt="" class="d-none">
                    </div>
                </div>
                <div class="car-stats-col">
                    <div class="stat-grid">
                        <div class="stat-box">
                            <div class="stat-label">Tare (LT WT)</div>
                            <div class="stat-value" id="statTare">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Load limit (LD LMT)</div>
                            <div class="stat-value" id="statLoadLimit">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Capacity (CAPY)</div>
                            <div class="stat-value" id="statCapy">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Target net</div>
                            <div class="stat-value" id="statTarget">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Status</div>
                            <div class="stat-value" id="statStatus">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Location</div>
                            <div class="stat-value" id="statLocation">—</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="btn btn-primary btn-lg" id="weighBtn" disabled>
                            <i class="bi bi-speedometer"></i> Weigh Car
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-lg d-none" id="prevCarBtn" aria-label="Previous car" title="Previous car">
                            <i class="bi bi-skip-backward"></i>
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-lg d-none" id="nextCarBtn" aria-label="Next car" title="Next car">
                            <i class="bi bi-skip-forward"></i>
                        </button>
                    </div>
                    <div id="weighResult" class="small text-muted">Select a car from the list, then weigh.</div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center mt-2" id="weighNavActions">
                    <button type="button" class="btn btn-outline-danger btn-lg d-none" id="reassignBtn">
                        <i class="bi bi-arrow-left-right"></i> Reassign Order
                    </button>
                </div>
            </div>
        </div>

        <div id="orderSection" class="card mb-3 d-none">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam"></i> Assign Car Order</span>
                <span id="routingBadge" class="badge"></span>
            </div>
            <div class="card-body">
                <div id="reassignNote" class="alert alert-warning py-2 px-3 small d-none mb-3">
                    <i class="bi bi-info-circle"></i>
                    Rerouting this car — the current order
                    <strong id="reassignPriorWaybill">—</strong>
                    will return to unfilled when you assign a new order.
                </div>
                <div class="mb-3 d-none" id="orderSelectWrap">
                    <label for="orderSelect" class="form-label">Open coke orders</label>
                    <select class="form-select order-select" id="orderSelect">
                        <option value="">— Select order —</option>
                    </select>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="orderActionRow">
                    <div class="d-flex flex-wrap gap-2" id="generateButtons"></div>
                    <button type="button" class="btn btn-success d-none" id="assignBtn" disabled>
                        <i class="bi bi-check2-circle"></i> <span id="assignBtnLabel">Assign to Order</span>
                    </button>
                </div>
                <div id="inTrainAssignNote" class="alert alert-info py-2 px-3 small d-none mb-3 in-train-assign-note"></div>
                <div id="assignResult" class="mt-2 small"></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-list-ul"></i> Cars at Scale / Inbound Train</span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <select class="form-select form-select-sm" id="trainFilter" style="width: auto; min-width: 10rem;" aria-label="Filter by train">
                        <option value="">All cars</option>
                        <option value="scale">At scale only</option>
                    </select>
                    <button type="button" class="btn btn-outline-success btn-sm" id="refreshCarsBtn">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="carsListEmpty" class="p-3 text-muted d-none">No cars at the scale or in a train right now.</div>
                <div id="carsListError" class="alert alert-danger m-3 d-none" role="alert"></div>
                <div class="list-group list-group-flush" id="carsList"></div>
            </div>
        </div>
    </div>

    <!-- Calibrate mode -->
    <div id="calibratePanel" class="mode-panel">
        <div class="sensor-average mb-2 scale-top-row">
            <div class="scale-display h-100 position-relative" id="calAverageDisplayPanel">
                <div class="scale-led-wrap">
                    <div class="scale-led" id="calAverageLed" data-state="off" title="Calibration alignment"></div>
                    <span class="scale-led-label d-none" id="calAverageLedLabel"></span>
                </div>
                <div class="label">Adjusted weight</div>
                <div><span class="value" id="calAverageDisplay">—</span> <span class="unit">tons</span></div>
                <div class="small mt-2" style="color:#6bdc6b;">
                    Average adjustment: <span id="calAverageAdjustment">—</span> t
                    <span class="text-muted" id="calAverageMeta"></span>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <p class="visually-hidden cal-instructions">
                    Scale test car <strong id="calTestCarMarks">COST1</strong>,
                    <strong id="calTestCarLbs">80,000</strong> lbs /
                    <span id="calTestCarTons">40.00</span> t
                </p>
                <div class="cal-track-wrap" id="calTrackWrap">
                    <div class="cal-track-rail">
                        <div class="cal-track-car d-none" id="calTrackCar">
                            <img id="calTestCarPhoto" alt="Scale test car" class="d-none">
                            <span class="cal-track-car-placeholder d-none" id="calTestCarPhotoPlaceholder">Test car</span>
                        </div>
                    </div>
                </div>
                <div class="row g-2 g-md-3 mb-3 cal-sensor-row">
                    <div class="col-4">
                        <div class="sensor-card" id="sensorCard-left">
                            <div class="mb-2 sensor-card-heading"><strong class="sensor-card-title"><span class="sensor-title-full">LEFT</span><span class="sensor-title-short">LEFT</span></strong></div>
                            <div class="scale-display mb-2">
                                <div class="label">Reading</div>
                                <div><span class="value" id="sensorDisplay-left">—</span> <span class="unit">t</span></div>
                            </div>
                            <div class="small mb-2 sensor-error-line">Error: <span id="sensorError-left">—</span> t</div>
                            <div class="small text-muted mb-2 sensor-adj-line" id="sensorAdj-left">adj 0.00</div>
                            <button type="button" class="btn btn-outline-primary w-100 mb-2 cal-position-btn" data-sensor="left">
                                <i class="bi bi-truck"></i> Place Car Here
                            </button>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="sensor-card" id="sensorCard-center">
                            <div class="mb-2 sensor-card-heading"><strong class="sensor-card-title"><span class="sensor-title-full">CENTER</span><span class="sensor-title-short">CENTER</span></strong></div>
                            <div class="scale-display mb-2">
                                <div class="label">Reading</div>
                                <div><span class="value" id="sensorDisplay-center">—</span> <span class="unit">t</span></div>
                            </div>
                            <div class="small mb-2 sensor-error-line">Error: <span id="sensorError-center">—</span> t</div>
                            <div class="small text-muted mb-2 sensor-adj-line" id="sensorAdj-center">adj 0.00</div>
                            <button type="button" class="btn btn-outline-primary w-100 mb-2 cal-position-btn" data-sensor="center">
                                <i class="bi bi-truck"></i> Place Car Here
                            </button>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="sensor-card" id="sensorCard-right">
                            <div class="mb-2 sensor-card-heading"><strong class="sensor-card-title"><span class="sensor-title-full">RIGHT</span><span class="sensor-title-short">RIGHT</span></strong></div>
                            <div class="scale-display mb-2">
                                <div class="label">Reading</div>
                                <div><span class="value" id="sensorDisplay-right">—</span> <span class="unit">t</span></div>
                            </div>
                            <div class="small mb-2 sensor-error-line">Error: <span id="sensorError-right">—</span> t</div>
                            <div class="small text-muted mb-2 sensor-adj-line" id="sensorAdj-right">adj 0.00</div>
                            <button type="button" class="btn btn-outline-primary w-100 mb-2 cal-position-btn" data-sensor="right">
                                <i class="bi bi-truck"></i> Place Car Here
                            </button>
                        </div>
                    </div>
                </div>

                <div class="cal-central-controls">
                    <div class="cal-central-panel">
                        <div class="cal-central-layout">
                            <div class="cal-central-stack">
                                <div class="cal-central-stats-block">
                                    <div class="cal-stats-grid">
                                        <div class="cal-stat">
                                            <div class="cal-stat-label">Sensor</div>
                                            <div class="cal-stat-value text-uppercase" id="calCentralSensor">—</div>
                                        </div>
                                        <div class="cal-stat">
                                            <div class="cal-stat-label">Reading</div>
                                            <div class="cal-stat-value">
                                                <span id="calCentralReading">—</span> <span class="unit">t</span>
                                            </div>
                                        </div>
                                        <div class="cal-stat cal-stat-error">
                                            <div class="cal-stat-label">Error</div>
                                            <div class="cal-stat-value">
                                                <span id="calCentralError">—</span> <span class="unit">t</span>
                                            </div>
                                        </div>
                                        <div class="cal-stat">
                                            <div class="cal-stat-label">Expected</div>
                                            <div class="cal-stat-value">
                                                <span id="calCentralExpected">—</span> <span class="unit">t</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="cal-central-adj-value-row" aria-live="polite">
                                    <span class="cal-central-adj-label">Adjustment</span>
                                    <span class="cal-central-adj-value">
                                        <span id="calCentralAdjValue">0.00</span>
                                        <span class="unit">t</span>
                                    </span>
                                </div>
                                <div class="cal-central-adj-wrap">
                                    <div class="cal-central-adj">
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjMinBtn" data-step="1" data-direction="down" disabled aria-label="Adjust down 1.00">|&lt;&lt;</button>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjCoarseDown" data-direction="down" data-fine="0" disabled aria-label="Adjust down 0.10">&lt;&lt;</button>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjFineDown" data-direction="down" data-fine="1" disabled aria-label="Adjust down 0.01">&lt;</button>
                                        <div class="cal-central-adj-slider-cell" title="Drag to adjust">
                                            <span class="cal-central-adj-end" id="calCentralAdjMin">-2.50</span>
                                            <div class="cal-central-adj-slider-track">
                                                <input type="range" class="cal-central-adj-slider" id="calCentralAdjSlider" min="-2.5" max="2.5" step="0.01" value="0" disabled aria-label="Adjustment scrollbar tons">
                                            </div>
                                            <span class="cal-central-adj-end" id="calCentralAdjMax">2.50</span>
                                        </div>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjFineUp" data-direction="up" data-fine="1" disabled aria-label="Adjust up 0.01">&gt;</button>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjCoarseUp" data-direction="up" data-fine="0" disabled aria-label="Adjust up 0.10">&gt;&gt;</button>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjMaxBtn" data-step="1" data-direction="up" disabled aria-label="Adjust up 1.00">&gt;&gt;|</button>
                                    </div>
                                    <input type="hidden" id="calCentralAdjInput" value="0.00">
                                </div>
                                <div class="cal-actions-meta">
                                    <span id="calCalibrationMeta" class="small text-danger">Last calibrated: —</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="cal-actions-footer">
            <div class="d-flex flex-wrap gap-2 align-items-center cal-actions-row">
                <button type="button" class="btn btn-success btn-sm" id="calSaveBtn" disabled>
                    <i class="bi bi-lock"></i> Save calibration
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="calResetBtn">Reset calibration</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="calResetAdjBtn" disabled>Reset adjustment</button>
            </div>
        </div>
    </div>
</div>

<script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script type="application/json" id="sts-data-track-scale"><?php echo json_encode([
    'config' => $config,
    'ui' => $track_scale_ui,
    'testCarMarks' => $track_scale_test_car_marks,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="/sts/js/sts-actions.js?v=1"></script>
<script src="/sts/plugins/track_scale/track_scale.js?v=1"></script>
</body>
</html>
