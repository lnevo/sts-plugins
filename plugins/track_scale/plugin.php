<?php
/**
 * Track Scale addon — coke weighing GUI, workflow steps, and warm-start hooks.
 */
return [
    'id' => 'track_scale',
    'label' => 'Track Scale',
    'version' => '1.0.0',
    'bootstrap' => __DIR__ . '/bootstrap.php',
    'helpers' => __DIR__ . '/track_scale_helpers.php',
    'pages' => [
        'index' => 'track_scale.php',
        'ajax' => 'track_scale_ajax.php',
        'settings' => 'track_scale_settings.php',
    ],

    'operations_buttons' => [
        [
            'cid' => 'during',
            'after' => 'set_out_cars',
            'page' => 'index',
            'icon' => 'bi-speedometer2',
            'title' => 'Track Scale',
            'stats' => [
                ['key' => 'scale_to_weigh', 'label' => 'To Weigh'],
            ],
        ],
    ],

    'stats' => [
        [
            'key' => 'scale_to_weigh',
            'default' => 0,
            'callback' => 'track_scale_count_weighable_cars',
        ],
    ],

    'condition_variables' => [
        ['key' => 'scale_to_weigh', 'label' => 'Cars to weigh'],
    ],

    'catalog_steps_callback' => 'track_scale_catalog_definitions',

    'catalog_adder' => [
        'during' => ['track_scale', 'calibrate_track_scale'],
    ],

    'legacy_function_ids' => [
        'weigh_ck1' => 'track_scale',
    ],

    'import_text_guessers' => [
        'track_scale_plugin_guess_calibrate',
        'track_scale_plugin_guess_weigh_cars',
    ],

    'session_reset_hooks' => ['track_scale_plugin_session_reset'],

    'normalize_params' => [
        'track_scale' => 'track_scale_plugin_normalize_params',
    ],

    'dispatch_handlers' => [
        'track_scale' => 'track_scale_plugin_dispatch_weigh',
        'calibrate_track_scale' => 'track_scale_plugin_dispatch_calibrate',
    ],

    'no_warm_start_dispatch' => ['track_scale'],

    'catalog_test_sections_callback' => 'track_scale_catalog_test_sections',

    'catalog_test_round_trip_skip' => ['track_scale'],
];
