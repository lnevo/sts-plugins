<?php
/**
 * Coke Orders settings: which shipments the replenish step counts and generates, and the
 * prefix cancel/drain keep. Plain forms, no scripts (strict CSP).
 */
require_once 'open_db.php';
require_once 'page_chrome.php';
plugins_require_helper('coke_orders');

$dbc = open_db();
$messages = [];
$errors = [];
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
if ($action === 'save') {
    $problem = coke_orders_write_settings([
        'outbound_shipments' => (string) ($_POST['outbound_shipments'] ?? ''),
        'replenish_shipments' => (string) ($_POST['replenish_shipments'] ?? ''),
        'keep_prefix' => (string) ($_POST['keep_prefix'] ?? ''),
    ]);
    $problem === '' ? $messages[] = 'Saved.' : $errors[] = $problem;
} elseif ($action === 'reset') {
    $problem = coke_orders_write_settings([]);
    $problem === '' ? $messages[] = 'Saved settings removed; the preset and defaults apply.' : $errors[] = $problem;
}

[$settings, $sources] = coke_orders_settings_with_sources();
[$preset_id] = coke_orders_preset();

$known = [];
$res = mysqli_query($dbc, 'SELECT code FROM shipments');
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $known[strtoupper((string) $row['code'])] = true;
}
$unknown = [];
foreach (['outbound_shipments', 'replenish_shipments'] as $key) {
    foreach ($settings[$key] as $code) {
        if (!isset($known[$code])) {
            $unknown[] = $code;
        }
    }
}
$unknown = array_values(array_unique($unknown));

function co_source($source)
{
    if ($source === 'saved') {
        return 'saved here';
    }

    return $source === 'default' ? 'built-in default' : 'preset from ' . $source;
}

// Links to every plugin's Settings page, so each one can be reached from the others.
$settings_links = [];
foreach (plugins_discover() as $settings_manifest) {
    if (isset($settings_manifest['pages']['settings']) && ($settings_manifest['id'] ?? '') !== 'coke_orders') {
        $settings_links[] = ['href' => plugins_page_url($settings_manifest['id'], 'settings'),
            'label' => ($settings_manifest['label'] ?? $settings_manifest['id']) . ' settings', 'icon' => 'bi-gear'];
    }
}

sts_page_start([
    'title' => 'Coke Orders Settings',
    'links' => $settings_links,
    'icon' => 'bi-gear',
    'section' => 'ops',
    'container' => 'narrow',
    'head_extra' => '<style>
      .co-card { border: none; box-shadow: 0 2px 6px rgba(0,0,0,.1); }
      .co-card .card-header { font-weight: 600; }
      .co-muted { color: #666; font-size: .9rem; }
      .co-bar .btn { min-height: 44px; }
    </style>',
]);
?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?php echo sts_h($e); ?></div><?php endforeach; ?>
<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo sts_h($m); ?></div><?php endforeach; ?>
<?php if ($unknown !== []): ?>
  <div class="alert alert-warning py-2">STS has no shipment <?php echo sts_h(implode(', ', $unknown)); ?>.</div>
<?php endif; ?>

<div class="card co-card mb-3">
  <div class="card-header"><i class="bi bi-box-seam"></i> Outbound orders</div>
  <div class="card-body">
    <p class="co-muted">The <strong>Replenish Coke Orders</strong> workflow step counts unfilled orders for the outbound shipments. When fewer are open than its target, it generates orders from the replenish shipments, one after another. Saved settings override the <?php echo $preset_id !== null ? 'preset from <code>' . sts_h($preset_id) . '</code>, which overrides the ' : ''; ?>built-in defaults (all empty) key by key. They are saved to <code><?php echo sts_h(coke_orders_settings_path()); ?></code>.</p>
    <form method="post">
      <div class="mb-3">
        <label class="form-label" for="co-out">Outbound shipments (comma separated)</label>
        <input class="form-control" id="co-out" name="outbound_shipments" value="<?php echo sts_h(implode(', ', $settings['outbound_shipments'])); ?>">
        <div class="form-text">Now: <?php echo sts_h(co_source($sources['outbound_shipments'])); ?>.</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="co-rep">Replenish shipments (comma separated)</label>
        <input class="form-control" id="co-rep" name="replenish_shipments" value="<?php echo sts_h(implode(', ', $settings['replenish_shipments'])); ?>">
        <div class="form-text">Blank uses the outbound shipments. Now: <?php echo sts_h(co_source($sources['replenish_shipments'])); ?>.</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="co-keep">Shipment prefix kept by Cancel Orders and Drain Unfilled Orders</label>
        <input class="form-control" id="co-keep" name="keep_prefix" value="<?php echo sts_h($settings['keep_prefix']); ?>">
        <div class="form-text">Added to a recipe step that still uses the old keep option, or names no prefixes. Now: <?php echo sts_h(co_source($sources['keep_prefix'])); ?>.</div>
      </div>
      <div class="d-flex flex-wrap gap-2 co-bar">
        <button class="btn btn-primary" name="action" value="save"><i class="bi bi-save"></i> Save</button>
        <button class="btn btn-outline-secondary" name="action" value="reset"><i class="bi bi-arrow-counterclockwise"></i> Remove saved settings</button>
      </div>
    </form>
  </div>
</div>
<?php
sts_page_end();
