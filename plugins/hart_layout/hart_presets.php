<?php
/**
 * HART's values for the generic plugins, which name no layout themselves. Each plugin
 * applies its preset under its own config file, so a saved file still wins.
 * track_scale merges top-level keys whole, so a group here lists every key it needs.
 */
return [
    'track_scale' => [
        'loading_location_code' => 'SOUTH-SCALE',
        'outbound_loading_location_code' => 'NORTH',
        'reload_loading_location_code' => 'SOUTH-SCALE',
        'reload_unloading_location_code' => 'SHEN-COKE',
        'commodity_code' => 'COKE',
        'shipments' => [
            'outbound' => ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'],
            'reload' => ['COKE-RELOAD-SHEN'],
        ],
        'calibration' => [
            'adjust_step_tons' => 0.10,
            'fine_adjust_step_tons' => 0.01,
            'adjust_min_tons' => -2.5,
            'adjust_max_tons' => 2.5,
            'zero_offset_random_min_tons' => -1.9,
            'zero_offset_random_max_tons' => 1.9,
            'position_bias_tons' => [
                'left' => -0.50,
                'center' => 0.0,
                'right' => 0.50,
            ],
            // Apparent pad loads when the test car sits on one sensor (not balanced).
            'load_share' => [
                'left' => ['left' => 1.00, 'center' => 0.52, 'right' => 0.18],
                'center' => ['left' => 0.72, 'center' => 1.00, 'right' => 0.72],
                'right' => ['left' => 0.18, 'center' => 0.52, 'right' => 1.00],
            ],
            'test_car_reporting_marks' => 'COST1',
            'test_car_tare_tons' => 40.0,
        ],
    ],
    'coke_orders' => [
        'outbound_shipments' => ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'],
        'replenish_shipments' => ['COKE-USS', 'COKE-CLEV'],
        'keep_prefix' => 'COKE-',
    ],
];
