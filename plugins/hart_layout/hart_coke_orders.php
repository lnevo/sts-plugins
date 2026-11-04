<?php
/**
 * HART's outbound coke orders: the shipments that count, the Replenish Coke Orders
 * workflow step, and keeping COKE- orders through Cancel Orders and Drain Unfilled
 * Orders. Formerly the separate coke_orders plugin.
 */

/** Outbound coke shipment codes: the USS and CLEV singles and their bulk lanes. */
function hart_coke_outbound_shipments()
{
    return ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'];
}

/** Single-car lanes the replenish step generates, in turn. */
function hart_coke_replenish_shipments()
{
    return ['COKE-USS', 'COKE-CLEV'];
}

/** Shipment-code prefix Cancel Orders and Drain Unfilled Orders keep. */
const HART_COKE_KEEP_PREFIX = 'COKE-';

/** Count unfilled car orders for outbound coke shipments. */
function hart_coke_count_unfilled_outbound($dbc)
{
    $code_list = [];
    foreach (hart_coke_outbound_shipments() as $code) {
        $code_list[] = '"' . mysqli_real_escape_string($dbc, $code) . '"';
    }
    $sql = 'SELECT COUNT(DISTINCT co.waybill_number) AS c
            FROM car_orders co
            INNER JOIN shipments s ON s.id = co.shipment
            WHERE (co.car = "" OR co.car IS NULL OR co.car = "0")
              AND s.code IN (' . implode(', ', $code_list) . ')';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs) {
        return 0;
    }
    $row = mysqli_fetch_array($rs);

    return (int) ($row['c'] ?? 0);
}

/**
 * Top up unfilled outbound coke orders with single-car shipments when the open pool
 * falls below target_min, alternating lanes until target_min is met or target_max
 * would be exceeded.
 */
function hart_coke_replenish($dbc, $target_min = 6, $target_max = 8)
{
    require_once dirname(__DIR__, 2) . '/session_helpers.php';

    $target_min = max(1, (int) $target_min);
    $target_max = max($target_min, (int) $target_max);
    $before = hart_coke_count_unfilled_outbound($dbc);

    if ($before >= $target_min) {
        return [
            'generated' => 0,
            'before' => $before,
            'after' => $before,
            'target_min' => $target_min,
            'target_max' => $target_max,
            'skipped' => true,
            'reason' => 'Already at or above target minimum (' . $before . ' open)',
        ];
    }

    $alternate = hart_coke_replenish_shipments();
    $generated = 0;
    $shipments_used = [];

    while (true) {
        $current = hart_coke_count_unfilled_outbound($dbc);
        if ($current >= $target_min || $current >= $target_max) {
            break;
        }
        $code = $alternate[$generated % count($alternate)];
        $res = session_manual_generate_shipment($dbc, $code);
        $added = (int) ($res['generated'] ?? 0);
        if ($added < 1) {
            break;
        }
        $generated += $added;
        $shipments_used[] = $code;
    }

    return [
        'generated' => $generated,
        'before' => $before,
        'after' => hart_coke_count_unfilled_outbound($dbc),
        'target_min' => $target_min,
        'target_max' => $target_max,
        'shipments' => $shipments_used,
    ];
}

function hart_coke_normalize_replenish_params(array &$params)
{
    $target_min = trim((string) ($params['target_min'] ?? '6'));
    $target_max = trim((string) ($params['target_max'] ?? '8'));
    if ($target_min === '' || !ctype_digit($target_min)) {
        $target_min = '6';
    }
    if ($target_max === '' || !ctype_digit($target_max)) {
        $target_max = '8';
    }
    if ((int) $target_max < (int) $target_min) {
        $target_max = $target_min;
    }

    $params['target_min'] = $target_min;
    $params['target_max'] = $target_max;
}

/**
 * Map legacy keep_coke onto keep_prefixes (1 = add COKE-, 0 = remove it).
 * A recipe with no keep_prefixes key at all (legacy) also gets COKE-.
 */
function hart_coke_normalize_cancel_params(array &$params)
{
    // A present keep_prefixes (even '') is left alone: an operator clearing the field wins.
    $has_prefixes = array_key_exists('keep_prefixes', $params);
    $has_legacy = array_key_exists('keep_coke', $params);
    if ($has_prefixes && !$has_legacy) {
        return;
    }
    $list = [];
    foreach (explode(',', (string) ($params['keep_prefixes'] ?? '')) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $list[] = $item;
        }
    }
    $keep = $has_legacy ? trim((string) $params['keep_coke']) : '1';
    $list = array_values(array_filter($list, function ($item) {
        return strcasecmp($item, HART_COKE_KEEP_PREFIX) !== 0;
    }));
    if ($keep !== '0') {
        $list[] = HART_COKE_KEEP_PREFIX;
    }
    $params['keep_prefixes'] = implode(',', $list);
    unset($params['keep_coke']);
}

function hart_coke_dispatch_replenish($dbc, array $step, array $def, array $params, array $config, array $result)
{
    hart_coke_normalize_replenish_params($params);

    return array_merge($result, hart_coke_replenish($dbc, (int) $params['target_min'], (int) $params['target_max']));
}

function hart_coke_dispatch_log_messages(array $entry, array &$messages)
{
    if (!array_key_exists('after', $entry)) {
        return;
    }
    $messages[] = sprintf(
        'Outbound coke orders: %d → %d (target %d–%d).',
        (int) ($entry['before'] ?? 0),
        (int) $entry['after'],
        (int) ($entry['target_min'] ?? 6),
        (int) ($entry['target_max'] ?? 8)
    );
    if (!empty($entry['shipments']) && is_array($entry['shipments'])) {
        $messages[] = 'Lanes: ' . implode(', ', $entry['shipments']) . '.';
    }
}

function hart_coke_guess_replenish($text)
{
    return stripos((string) $text, 'Replenish Coke') !== false ? 'replenish_coke_orders' : null;
}

function hart_coke_catalog_definitions()
{
    return [
        [
            'id' => 'replenish_coke_orders',
            'category' => 'operations',
            'adder' => false,
            'adder_group' => 'before',
            'label' => 'Replenish Coke Orders',
            'gui_template' => 'Replenish Coke Orders (target {target_min}–{target_max})',
            'description' => 'Count unfilled outbound coke orders (USS/CLEV singles and bulk). When below target minimum, generate single-car COKE-USS / COKE-CLEV orders alternating lanes until the minimum is met (capped at target maximum).',
            'runnable' => true,
            'dispatch' => 'replenish_coke_orders',
            'params' => [
                [
                    'key' => 'target_min',
                    'label' => 'Target minimum',
                    'type' => 'number',
                    'default' => '6',
                    'required' => true,
                    'min' => 1,
                    'step' => 1,
                    'visible_label' => true,
                ],
                [
                    'key' => 'target_max',
                    'label' => 'Target maximum',
                    'type' => 'number',
                    'default' => '8',
                    'required' => true,
                    'min' => 1,
                    'step' => 1,
                    'visible_label' => true,
                ],
            ],
        ],
    ];
}

function hart_coke_catalog_test_sections($dbc, array $context)
{
    return [
        [
            'label' => '[Before Operations — HART coke orders]',
            'steps' => [
                [
                    'function' => 'replenish_coke_orders',
                    'params' => ['target_min' => '6', 'target_max' => '8'],
                    'description' => 'Test: Replenish Coke Orders',
                ],
            ],
        ],
    ];
}
