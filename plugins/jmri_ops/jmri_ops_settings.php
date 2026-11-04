<?php
/**
 * JMRI Operations sync settings: how STS reaches JMRI, and the STS-to-JMRI name map.
 * Plain forms, no scripts (strict CSP). Saved to jmri_ops.json in the STS config volume.
 */
require_once 'open_db.php';
require_once 'page_chrome.php';
plugins_require_helper('jmri_ops');

$dbc = open_db();
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
[$saved, $read_errors] = jmri_ops_read_settings();
$draft = $saved;
$messages = [];
$errors = $read_errors;
$names = null;
$unknown = [];

if ($action === 'save' || $action === 'check' || $action === 'test') {
    [$draft, $form_errors] = jmri_ops_normalize_settings(jmri_ops_settings_from_form($_POST));
    $errors = array_merge($errors, $form_errors);
}
if ($action === 'save') {
    $problems = jmri_ops_write_settings(jmri_ops_settings_from_form($_POST));
    if ($problems === []) {
        $messages[] = 'Saved.';
        [$saved] = jmri_ops_read_settings();
        $draft = $saved;
    } else {
        $errors = array_merge($errors, $problems);
    }
} elseif ($action === 'save_raw') {
    $raw = json_decode((string) ($_POST['raw'] ?? ''), true);
    $problems = is_array($raw) ? jmri_ops_write_settings($raw) : ['not valid JSON: ' . json_last_error_msg()];
    if ($problems === []) {
        $messages[] = 'Saved from the settings file text.';
        [$saved] = jmri_ops_read_settings();
        $draft = $saved;
    } else {
        $errors = array_merge($errors, $problems);
    }
}

$env_url = getenv('STS_JMRI_URL');
$env_url = ($env_url !== false && trim($env_url) !== '') ? rtrim(trim($env_url), '/') : '';
$effective = $draft['connection'] + ['url_from_env' => false];
if ($env_url !== '') {
    $effective['url'] = $env_url;
}

if ($action === 'test' || $action === 'check') {
    if ($effective['url'] === '') {
        $errors[] = 'No JMRI url to test.';
    } else {
        try {
            $version = jmri_ops_listener_version($effective);
            if ($version === null) {
                $errors[] = 'JMRI answers at ' . $effective['url'] . ', but sts_car_sync.py is not running.';
            } else {
                $messages[] = 'JMRI answers at ' . $effective['url'] . '; sts_car_sync.py version ' . $version . '.';
                $names = jmri_ops_jmri_names($effective);
                $unknown = jmri_ops_unknown_names($draft['map'], $names);
                $messages[] = count($names['locations']) . ' JMRI locations and ' . count($names['loads'])
                    . ' loads loaded as suggestions. ' . ($unknown === [] ? 'Every name in the map is in JMRI.'
                    : count($unknown) . ' names in the map are not in JMRI; they are marked below.');
                if ($action === 'check') {
                    $messages[] = 'Not saved yet.';
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$sts = jmri_ops_read_sts_names($dbc);
$map = $draft['map'];

function jo_field($name, $value, $list, $bad, $placeholder = '')
{
    $class = 'form-control form-control-sm' . ($bad !== '' ? ' is-invalid' : '');
    $html = '<input class="' . $class . '" name="' . sts_h($name) . '" value="' . sts_h($value) . '"'
        . ($list !== '' ? ' list="' . $list . '"' : '') . ($placeholder !== '' ? ' placeholder="' . sts_h($placeholder) . '"' : '') . '>';
    if ($bad !== '') {
        $html .= '<div class="invalid-feedback">' . sts_h($bad) . '</div>';
    }

    return $html;
}

function jo_track_list_id($location)
{
    return 'jo-tracks-' . substr(md5((string) $location), 0, 10);
}

// Links to every plugin's Settings page, so each one can be reached from the others.
$settings_links = [];
foreach (plugins_discover() as $settings_manifest) {
    if (isset($settings_manifest['pages']['settings']) && ($settings_manifest['id'] ?? '') !== 'jmri_ops') {
        $settings_links[] = ['href' => plugins_page_url($settings_manifest['id'], 'settings'),
            'label' => ($settings_manifest['label'] ?? $settings_manifest['id']) . ' settings', 'icon' => 'bi-gear'];
    }
}

sts_page_start([
    'title' => 'JMRI Sync Settings',
    'links' => $settings_links,
    'icon' => 'bi-gear',
    'section' => 'ops',
    'back_href' => 'plugin.php?id=jmri_ops',
    'back_label' => 'Send Cars to JMRI',
    'head_extra' => '<style>
      .jo-card { border: none; box-shadow: 0 2px 6px rgba(0,0,0,.1); }
      .jo-card .card-header { font-weight: 600; }
      .jo-muted { color: #666; font-size: .9rem; }
      .jo-table td, .jo-table th { font-size: .9rem; vertical-align: middle; }
      .jo-table input { min-width: 10rem; }
      .jo-raw { font-family: monospace; font-size: .85rem; min-height: 24rem; }
      .jo-bar { position: sticky; bottom: 0; background: #fff; padding: .75rem 0; border-top: 1px solid #ddd; z-index: 5; }
      .jo-bar .btn { min-height: 44px; }
    </style>',
]);
?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?php echo sts_h($e); ?></div><?php endforeach; ?>
<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo sts_h($m); ?></div><?php endforeach; ?>

<?php if ($names !== null): ?>
  <datalist id="jo-locations"><?php foreach (array_keys($names['locations']) as $n): ?><option value="<?php echo sts_h($n); ?>"><?php endforeach; ?></datalist>
  <?php foreach ($names['locations'] as $loc => $tracks): ?>
    <datalist id="<?php echo jo_track_list_id($loc); ?>"><?php foreach ($tracks as $t): ?><option value="<?php echo sts_h($t); ?>"><?php endforeach; ?></datalist>
  <?php endforeach; ?>
  <datalist id="jo-loads"><?php foreach ($names['loads'] as $n): ?><option value="<?php echo sts_h($n); ?>"><?php endforeach; ?></datalist>
<?php endif; ?>
<?php
$loc_list = $names !== null ? 'jo-locations' : '';
$track_list = function ($location) use ($names) {
    return ($names !== null && isset($names['locations'][$location])) ? jo_track_list_id($location) : '';
};
?>

<form method="post">
<div class="card jo-card mb-3">
  <div class="card-header"><i class="bi bi-plug"></i> Connection to JMRI</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="jo-url">JMRI web server</label>
        <input class="form-control" id="jo-url" name="url" value="<?php echo sts_h($draft['connection']['url']); ?>" placeholder="http://jmri-host:12080">
        <?php if ($env_url !== ''): ?><div class="form-text text-warning">STS_JMRI_URL is set to <code><?php echo sts_h($env_url); ?></code> and is used instead.</div><?php endif; ?>
        <div class="form-text">Blank switches the sync off.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="jo-timeout">Wait for JMRI (seconds)</label>
        <input class="form-control" id="jo-timeout" type="number" min="5" max="300" name="timeout" value="<?php echo (int) $draft['connection']['timeout']; ?>">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="jo-save" name="save" value="1"<?php echo $draft['connection']['save'] ? ' checked' : ''; ?>>
          <label class="form-check-label" for="jo-save">JMRI saves Operations after a send</label>
        </div>
      </div>
    </div>
    <p class="jo-muted mt-3 mb-2">STS writes each batch to the JMRI Memory <code><?php echo JMRI_OPS_REQUEST; ?></code>; <code>sts_car_sync.py</code>, in JMRI's Start Up, applies it and answers in <code><?php echo JMRI_OPS_REPLY; ?></code>. <code><?php echo JMRI_OPS_READY; ?></code> holds its version once it is listening.</p>
    <button class="btn btn-outline-primary btn-sm" name="action" value="test"><i class="bi bi-broadcast"></i> Test connection and load JMRI names</button>
  </div>
</div>

<div class="card jo-card mb-3">
  <div class="card-header"><i class="bi bi-geo-alt"></i> STS locations → JMRI</div>
  <div class="card-body">
    <p class="jo-muted">A blank track lets JMRI keep the car's track at that location, or take the first track that accepts it. A location left blank here uses its station's row below.</p>
    <div class="table-responsive"><table class="table table-sm jo-table">
      <thead><tr><th>STS station</th><th>STS location</th><th>Track / spot</th><th>Cars</th><th>JMRI location</th><th>JMRI track</th></tr></thead>
      <tbody>
      <?php foreach ($sts['locations'] as $row): ?>
        <?php $hit = $map['locations'][$row['code']] ?? ['location' => '', 'track' => '']; ?>
        <tr>
          <td><?php echo sts_h($row['station']); ?></td>
          <td><code><?php echo sts_h($row['code']); ?></code></td>
          <td><?php echo sts_h($row['track']); ?></td>
          <td><?php echo (int) $row['cars']; ?></td>
          <td><?php echo jo_field('loc_location[' . $row['code'] . ']', $hit['location'], $loc_list, $unknown['loc ' . $row['code']] ?? '', 'station row'); ?></td>
          <td><?php echo jo_field('loc_track[' . $row['code'] . ']', $hit['track'] ?? '', $track_list($hit['location']), '', 'any'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="card jo-card mb-3">
  <div class="card-header"><i class="bi bi-signpost-split"></i> STS stations → JMRI</div>
  <div class="card-body">
    <p class="jo-muted">For every location at a station that has no row of its own above, such as many offline parties sharing one JMRI staging track.</p>
    <div class="table-responsive"><table class="table table-sm jo-table">
      <thead><tr><th>STS station</th><th>Cars</th><th>JMRI location</th><th>JMRI track</th></tr></thead>
      <tbody>
      <?php foreach ($sts['stations'] as $station => $count): ?>
        <?php $hit = $map['stations'][$station] ?? ['location' => '', 'track' => '']; ?>
        <tr>
          <td><?php echo sts_h($station); ?></td>
          <td><?php echo (int) $count; ?></td>
          <td><?php echo jo_field('st_location[' . $station . ']', $hit['location'], $loc_list, $unknown['st ' . $station] ?? '', 'not sent'); ?></td>
          <td><?php echo jo_field('st_track[' . $station . ']', $hit['track'] ?? '', $track_list($hit['location']), '', 'any'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="card jo-card mb-3">
  <div class="card-header"><i class="bi bi-box-seam"></i> STS commodities → JMRI loads</div>
  <div class="card-body">
    <p class="jo-muted">A loaded car carries its shipment's commodity. A commodity left blank is sent under its STS code, and JMRI notes the load as unknown. Empty cars get JMRI's empty load<?php echo $names !== null && $names['empty'] !== '' ? ' (' . sts_h($names['empty']) . ')' : ''; ?>.</p>
    <div class="table-responsive"><table class="table table-sm jo-table">
      <thead><tr><th>STS commodity</th><th>Description</th><th>JMRI load</th></tr></thead>
      <tbody>
      <?php foreach ($sts['commodities'] as $code => $description): ?>
        <tr>
          <td><code><?php echo sts_h($code); ?></code></td>
          <td><?php echo sts_h($description); ?></td>
          <td><?php echo jo_field('load[' . $code . ']', $map['loads'][$code] ?? '', $names !== null ? 'jo-loads' : '', $unknown['load ' . $code] ?? ''); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="card jo-card mb-3">
  <div class="card-header"><i class="bi bi-slash-circle"></i> Car statuses never sent</div>
  <div class="card-body">
    <?php foreach ($sts['statuses'] + array_fill_keys($map['skip_status'], 0) as $status => $count): ?>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="skip[]" id="jo-skip-<?php echo sts_h(md5($status)); ?>" value="<?php echo sts_h($status); ?>"<?php echo in_array($status, $map['skip_status'], true) ? ' checked' : ''; ?>>
        <label class="form-check-label" for="jo-skip-<?php echo sts_h(md5($status)); ?>"><?php echo sts_h($status); ?> (<?php echo (int) $count; ?>)</label>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="jo-bar d-flex flex-wrap gap-2">
  <button class="btn btn-primary" name="action" value="save"><i class="bi bi-save"></i> Save settings</button>
  <button class="btn btn-outline-secondary" name="action" value="check"><i class="bi bi-check2-square"></i> Check names against JMRI</button>
</div>
</form>

<div class="card jo-card my-3">
  <div class="card-header"><i class="bi bi-filetype-json"></i> Settings file</div>
  <div class="card-body">
    <p class="jo-muted">The saved settings, <code><?php echo sts_h(jmri_ops_settings_path()); ?></code>. Copy it to keep a layout's settings, or paste one in and save. The previous file is kept as <code>jmri_ops.json.bak</code>.</p>
    <form method="post">
      <textarea class="form-control jo-raw" name="raw" rows="24" spellcheck="false"><?php echo sts_h(json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea>
      <button class="btn btn-outline-primary mt-2" name="action" value="save_raw"><i class="bi bi-save"></i> Save settings file</button>
    </form>
  </div>
</div>
<?php
sts_page_end();
