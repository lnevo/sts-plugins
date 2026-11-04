<?php
/**
 * Coke Orders addon — keep a pool of open outbound orders topped up. Which shipments
 * count and which are generated are settings (page 'settings'); a layout plugin can preset them.
 */
return [
    'id' => 'coke_orders',
    'label' => 'Coke Orders',
    'version' => '1.0.0',
    'bootstrap' => __DIR__ . '/bootstrap.php',
    'helpers' => __DIR__ . '/coke_orders_helpers.php',
    'pages' => [
        'settings' => 'coke_orders_settings_page.php',
    ],

    'catalog_steps_callback' => 'coke_orders_catalog_definitions',

    'catalog_adder' => [
        // Keep replenish out of the step-adder; recipes that already use it still run.
    ],

    'import_text_guessers' => [
        'coke_orders_plugin_guess_replenish',
    ],

    'normalize_params' => [
        'replenish_coke_orders' => 'coke_orders_normalize_params',
        'cancel_orders' => 'coke_orders_normalize_cancel_params',
        'drain_unfilled_orders' => 'coke_orders_normalize_cancel_params',
    ],

    'dispatch_handlers' => [
        'replenish_coke_orders' => 'coke_orders_dispatch_replenish',
    ],

    'dispatch_log_hooks' => [
        'replenish_coke_orders' => 'coke_orders_dispatch_log_messages',
    ],

    'runtime_without_warm_start' => ['replenish_coke_orders'],

    'catalog_test_sections_callback' => 'coke_orders_catalog_test_sections',

    'catalog_test_round_trip_skip' => ['replenish_coke_orders'],
];
