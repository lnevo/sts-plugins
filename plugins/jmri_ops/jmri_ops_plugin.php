<?php

function jmri_ops_catalog_definitions()
{
    return [
        [
            'id' => 'send_cars_to_jmri',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Send Cars to JMRI',
            'gui_template' => 'Send Cars to JMRI{preview_suffix}',
            'description' => 'Copy every car\'s STS location, load and final destination into JMRI Operations Pro, so the roster, manifests and CATS match STS. Needs sts_car_sync.py in JMRI\'s Start Up, and the JMRI url and name map on the plugin\'s Settings page. Cars on a built JMRI train are left alone. A missing or unreachable JMRI is reported and does not stop the session. Preview only asks JMRI what would change.',
            'runnable' => true,
            'dispatch' => 'send_cars_to_jmri',
            'client' => [
                'hide_inline_params' => ['preview'],
                'derived' => [
                    ['key' => 'preview_suffix', 'from' => 'preview', 'template' => ' (preview)', 'pattern' => '^yes$'],
                ],
            ],
            'params' => [
                [
                    'key' => 'preview',
                    'label' => 'Preview only',
                    'type' => 'select',
                    'options' => ['no', 'yes'],
                    'default' => 'no',
                    'visible_label' => true,
                ],
            ],
        ],
    ];
}

function jmri_ops_normalize_params(array &$params)
{
    foreach (['preview' => 'no'] as $key => $default) {
        $value = strtolower(trim((string) ($params[$key] ?? '')));
        $params[$key] = in_array($value, ['yes', 'no'], true) ? $value : $default;
    }
}

function jmri_ops_dispatch_send($dbc, array $step, array $def, array $params, array $config, array $result)
{
    jmri_ops_normalize_params($params);
    $sync = jmri_ops_sync($dbc, $params['preview'] === 'yes');
    unset($sync['batch'], $sync['jmri']);
    $result = array_merge($result, ['jmri_sync' => $sync]);
    if (empty($sync['ok'])) {
        // Reported, not raised: JMRI being off must not stop an operating session.
        $result['skipped'] = true;
        $result['reason'] = 'JMRI sync: ' . ($sync['error'] ?? 'failed');
    }

    return $result;
}

function jmri_ops_dispatch_log_messages(array $entry, array &$messages)
{
    $sync = $entry['jmri_sync'] ?? null;
    if (!is_array($sync)) {
        return;
    }
    if (empty($sync['ok'])) {
        $messages[] = 'JMRI sync not done: ' . ($sync['error'] ?? 'failed') . '.';

        return;
    }
    $summary = $sync['summary'] ?? [];
    $messages[] = sprintf(
        'JMRI sync%s: %d sent, %d changed, %d unchanged, %d skipped by JMRI, %d errors, %d not sent%s.',
        !empty($sync['dry_run']) ? ' (preview)' : '',
        (int) ($sync['cars_sent'] ?? 0),
        (int) ($summary['changed'] ?? 0),
        (int) ($summary['unchanged'] ?? 0),
        (int) ($summary['skipped'] ?? 0),
        (int) ($summary['error'] ?? 0),
        count($sync['not_sent'] ?? []),
        !empty($sync['saved']) ? '; Operations saved' : ''
    );
}

function jmri_ops_guess_send($text)
{
    return stripos((string) $text, 'Send Cars to JMRI') !== false ? 'send_cars_to_jmri' : null;
}
