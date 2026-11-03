<?php
/**
 * Coke Orders addon — outbound coke order replenishment for HART-style layouts.
 */
return [
    'id' => 'coke_orders',
    'label' => 'Coke Orders',
    'version' => '1.0.0',
    'bootstrap' => __DIR__ . '/bootstrap.php',
    'helpers' => __DIR__ . '/coke_orders_helpers.php',

    'catalog_steps_callback' => 'coke_orders_catalog_definitions',

    'catalog_adder' => [
        // Keep replenish out of the step-adder; recipes that already use it still run.
    ],

    'import_text_guessers' => [
        'coke_orders_plugin_guess_replenish',
    ],

    'param_overrides' => [
        'cancel_orders' => ['keep_prefixes' => ['default' => 'COKE-', 'placeholder' => 'e.g. COKE-']],
        'drain_unfilled_orders' => ['keep_prefixes' => ['default' => 'COKE-', 'placeholder' => 'e.g. COKE-']],
        'generate_orders' => ['exclude_prefixes' => ['placeholder' => 'e.g. COKE-,BULK-']],
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
