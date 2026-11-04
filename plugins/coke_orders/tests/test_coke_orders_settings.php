<?php
/**
 * php plugins/coke_orders/tests/test_coke_orders_settings.php
 * Settings layering (defaults, preset, saved file) and the keep-prefix mapping.
 */
$FAKE_MANIFESTS = [];
function plugins_discover()
{
    return $GLOBALS['FAKE_MANIFESTS'];
}
$tmp = sys_get_temp_dir() . '/coke_orders_test_' . getmypid();
@mkdir($tmp);
putenv('STS_CONFIG_DIR=' . $tmp);
require_once __DIR__ . '/../coke_orders_settings.php';
require_once __DIR__ . '/../coke_orders_plugin.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

[$s, $src] = coke_orders_settings_with_sources();
check('defaults are empty', $s === coke_orders_default_settings() && $src['keep_prefix'] === 'default');
$FAKE_MANIFESTS = [['id' => 'layout_one', 'presets' => ['coke_orders' => [
    'outbound_shipments' => ['ore-east', 'ORE-WEST', 'ORE-EAST'], 'replenish_shipments' => 'ORE-EAST', 'keep_prefix' => ' ore- ']]]];
[$s, $src] = coke_orders_settings_with_sources();
check('the preset is cleaned and applied', $s['outbound_shipments'] === ['ORE-EAST', 'ORE-WEST'] && $s['replenish_shipments'] === ['ORE-EAST']
    && $s['keep_prefix'] === 'ORE-' && $src['outbound_shipments'] === 'layout_one');
check('saving writes the file', coke_orders_write_settings(['keep_prefix' => 'MIN-']) === '' && is_file(coke_orders_settings_path()));
[$s, $src] = coke_orders_settings_with_sources();
check('a saved key wins over the preset, the others stay', $s['keep_prefix'] === 'MIN-' && $src['keep_prefix'] === 'saved'
    && $s['outbound_shipments'] === ['ORE-EAST', 'ORE-WEST'] && $src['outbound_shipments'] === 'layout_one');
check('saving nothing removes the file', coke_orders_write_settings([]) === '' && !is_file(coke_orders_settings_path()));

// coke_orders_settings() caches, so the mapping below sees the preset (keep prefix ORE-).
$p = ['keep_coke' => '1', 'keep_prefixes' => 'ABC-'];
coke_orders_normalize_cancel_params($p);
check('keep_coke=1 adds the keep prefix', $p === ['keep_prefixes' => 'ABC-,ORE-']);
$p = ['keep_coke' => '0', 'keep_prefixes' => 'ORE-,ABC-'];
coke_orders_normalize_cancel_params($p);
check('keep_coke=0 removes it', $p === ['keep_prefixes' => 'ABC-']);
$p = [];
coke_orders_normalize_cancel_params($p);
check('a step naming no prefixes gets the keep prefix', $p === ['keep_prefixes' => 'ORE-']);
$p = ['keep_prefixes' => ''];
coke_orders_normalize_cancel_params($p);
check('an operator clearing the field wins', $p === ['keep_prefixes' => '']);

@unlink(coke_orders_settings_path() . '.bak');
@rmdir($tmp);
echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
