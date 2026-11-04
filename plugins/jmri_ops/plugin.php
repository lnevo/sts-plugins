<?php
/**
 * JMRI Operations sync — send car positions, loads and final destinations from STS to JMRI.
 *
 * Needs sts_car_sync.py running in JMRI (hart repo) and a url in /var/lib/sts/jmri_ops.json.
 */
return [
    'id' => 'jmri_ops',
    'label' => 'JMRI Operations sync',
    'version' => '0.1.0',
    'bootstrap' => __DIR__ . '/bootstrap.php',
    'helpers' => __DIR__ . '/jmri_ops_helpers.php',
    'pages' => [
        'index' => 'jmri_ops.php',
    ],

    'operations_buttons' => [
        [
            'cid' => 'during',
            'after' => 'zz_jmri_ops',
            'page' => 'index',
            'icon' => 'bi-arrow-left-right',
            'title' => 'Send Cars to JMRI',
        ],
    ],

    'catalog_steps_callback' => 'jmri_ops_catalog_definitions',

    'catalog_adder' => [
        'during' => ['send_cars_to_jmri'],
    ],

    'import_text_guessers' => ['jmri_ops_guess_send'],

    'normalize_params' => [
        'send_cars_to_jmri' => 'jmri_ops_normalize_params',
    ],

    'dispatch_handlers' => [
        'send_cars_to_jmri' => 'jmri_ops_dispatch_send',
    ],

    'dispatch_log_hooks' => [
        'send_cars_to_jmri' => 'jmri_ops_dispatch_log_messages',
    ],

    'runtime_without_warm_start' => ['send_cars_to_jmri'],

    'catalog_test_round_trip_skip' => ['send_cars_to_jmri'],
];
