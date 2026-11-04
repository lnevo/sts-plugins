<?php
/**
 * Coke Orders settings: which shipments count as outbound orders, which ones the
 * replenish step generates, and the shipment-code prefix that cancel/drain keep.
 *
 * Built-in defaults are empty. A layout plugin can supply them in its manifest
 * ('presets' => ['coke_orders' => [...]]); coke_orders.json in the STS config
 * volume overrides both, key by key.
 */

function coke_orders_default_settings()
{
    return [
        // Shipment codes whose unfilled orders are counted.
        'outbound_shipments' => [],
        // Shipment codes the replenish step generates, in turn. Empty: the outbound list.
        'replenish_shipments' => [],
        // Shipment-code prefix that cancel_orders / drain_unfilled_orders keep when a legacy
        // recipe says keep_coke, or names no keep_prefixes at all.
        'keep_prefix' => '',
    ];
}

function coke_orders_settings_path()
{
    $dir = getenv('STS_CONFIG_DIR') ?: '/var/lib/sts';

    return rtrim($dir, '/') . '/coke_orders.json';
}

/** @return array{0: string|null, 1: array} plugin id and its preset */
function coke_orders_preset()
{
    if (!function_exists('plugins_discover')) {
        return [null, []];
    }
    foreach (plugins_discover() as $manifest) {
        $preset = $manifest['presets']['coke_orders'] ?? null;
        if (is_array($preset)) {
            return [(string) ($manifest['id'] ?? ''), $preset];
        }
    }

    return [null, []];
}

/** Clean one settings array: lists of trimmed codes, a trimmed prefix; unknown keys dropped. */
function coke_orders_clean_settings(array $raw)
{
    $out = [];
    foreach (['outbound_shipments', 'replenish_shipments'] as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $items = is_array($raw[$key]) ? $raw[$key] : explode(',', (string) $raw[$key]);
        $out[$key] = array_values(array_unique(array_filter(array_map(function ($v) {
            return strtoupper(trim((string) $v));
        }, $items), 'strlen')));
    }
    if (array_key_exists('keep_prefix', $raw)) {
        $out['keep_prefix'] = strtoupper(trim((string) $raw['keep_prefix']));
    }

    return $out;
}

function coke_orders_saved_settings()
{
    $path = coke_orders_settings_path();
    $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    return is_array($raw) ? coke_orders_clean_settings($raw) : [];
}

/**
 * The settings in force, and where each came from ('default', the preset's plugin id, or 'saved').
 *
 * @return array{0: array, 1: array<string,string>}
 */
function coke_orders_settings_with_sources()
{
    $settings = coke_orders_default_settings();
    $sources = array_fill_keys(array_keys($settings), 'default');
    [$preset_id, $preset] = coke_orders_preset();
    foreach (coke_orders_clean_settings($preset) as $key => $value) {
        $settings[$key] = $value;
        $sources[$key] = $preset_id;
    }
    foreach (coke_orders_saved_settings() as $key => $value) {
        $settings[$key] = $value;
        $sources[$key] = 'saved';
    }

    return [$settings, $sources];
}

function coke_orders_settings()
{
    static $cache = null;
    if ($cache === null) {
        $cache = coke_orders_settings_with_sources()[0];
    }

    return $cache;
}

/** Save the given keys (others keep coming from the preset or defaults). Returns '' or the problem. */
function coke_orders_write_settings(array $raw)
{
    $path = coke_orders_settings_path();
    $clean = coke_orders_clean_settings($raw);
    if ($clean === []) {
        return is_file($path) && !@unlink($path) ? 'could not remove ' . $path : '';
    }
    if (is_file($path)) {
        @copy($path, $path . '.bak');
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false
        || !@rename($tmp, $path)) {
        @unlink($tmp);

        return 'could not write ' . $path;
    }

    return '';
}
