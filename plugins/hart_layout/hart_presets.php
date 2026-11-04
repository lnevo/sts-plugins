<?php
/**
 * HART's values for the generic plugins, which name no layout themselves. Each plugin
 * applies its preset under its own config file, so a saved file still wins.
 * Groups merge key by key, so a group here lists only what differs from the plugin's defaults.
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
            'test_car_reporting_marks' => 'COST1',
        ],
    ],
    'coke_orders' => [
        'outbound_shipments' => ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'],
        'replenish_shipments' => ['COKE-USS', 'COKE-CLEV'],
        'keep_prefix' => 'COKE-',
    ],
];
