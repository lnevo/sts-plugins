<?php
/**
 * HART layout addon: the CK1 / NVL / D749 / STG-SCULLY / STG-DEMMLER warm-start
 * scenario, its stats columns and its switch-list / wheel-report specifics.
 */
return [
    'id' => 'hart_layout',
    'label' => 'HART layout',
    'version' => '1.0.0',
    'bootstrap' => __DIR__ . '/bootstrap.php',
    'helpers' => __DIR__ . '/hart_warm_start.php',

    'warm_start_scenario' => [
        'id' => 'hart',
        'default_config' => 'hart_default_config',
        'staging_jobs' => 'hart_staging_job_names',
        'location_aliases' => 'hart_location_code_aliases',
        'session_work' => 'hart_run_session_work',
        'run' => 'hart_run',
        'advance_session' => 'hart_advance_session',
        'begin_session' => 'hart_begin_operating_session',
        'play_session' => 'hart_play_operating_session',
        'tracked_overrides' => 'hart_tracked_sim_overrides',
        'prep_checks' => 'hart_evaluate_session_prep',
        'summary' => 'hart_summarize',
        'report_lines' => 'hart_format_begin_session_report',
        'featured_stations' => 'hart_station_report_featured',
        'prep_staging' => 'hart_prep_staging_yards',
        'stats_blank' => 'hart_session_stats_blank',
        'stats_record_pickup' => 'hart_record_job_pickup',
        'stats_record_setout' => 'hart_record_job_setout',
        'stats_row_extra' => 'hart_session_stats_row_extra',
        'stats_labels' => 'hart_session_stats_labels',
    ],

    'delivery_complete_hooks' => ['hart_delivery_complete'],

    // HART's values for track_scale and coke_orders (hart_presets.php).
    'presets' => require __DIR__ . '/hart_presets.php',

    // CK1 weighs with HART's own routine (hart_track_scale.php).
    'track_scale_job_weigh' => ['CK1' => 'hart_track_scale_run_ck1_scale_ops'],

    // Step editor defaults for HART's coke shipment codes.
    'param_overrides' => [
        'cancel_orders' => ['keep_prefixes' => ['default' => 'COKE-', 'placeholder' => 'e.g. COKE-']],
        'drain_unfilled_orders' => ['keep_prefixes' => ['default' => 'COKE-', 'placeholder' => 'e.g. COKE-']],
        'generate_orders' => ['exclude_prefixes' => ['placeholder' => 'e.g. COKE-,BULK-']],
    ],

    'switchlist_section_normalize' => 'hart_switchlist_section_normalize',

    'wheel_report_extra_rows' => 'hart_wheel_report_extra_rows',

    // Obsolete HART function ids from older saved recipes; migrated on import.
    'legacy_function_ids' => [
        'assign_ck1_reload' => 'auto_assign_locals',
        'run_stg_scully' => 'text_instruction',
        'run_stg_demmler' => 'text_instruction',
        'composite_nvl_pre_ck1' => 'text_instruction',
        'composite_ck1_session' => 'text_instruction',
        'composite_nvl_post_ck1' => 'text_instruction',
        'composite_d749_session_start' => 'text_instruction',
        'composite_d749_phased' => 'text_instruction',
        'secure_d749_demmler' => 'text_instruction',
        'secure_nvl_scully' => 'text_instruction',
    ],
];
