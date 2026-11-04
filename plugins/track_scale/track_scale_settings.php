<?php
/**
 * Track scale settings: the track_scale_config.json the plugin reads, editable, and what is in force.
 * Plain forms, no scripts (strict CSP).
 */
require_once 'page_chrome.php';
plugins_require_helper('track_scale');

$path = track_scale_config_path();
$defaults = track_scale_default_config();
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save') {
    $text = (string) ($_POST['config'] ?? '');
    $problems = track_scale_settings_check($text, $defaults);
    if ($problems['errors'] !== []) {
        $errors = $problems['errors'];
    } else {
        $saved = track_scale_settings_write($path, $text);
        if ($saved === '') {
            $messages[] = 'Saved. The previous file is kept as ' . basename($path) . '.bak.';
        } else {
            $errors[] = $saved;
        }
    }
}

$file_text = is_readable($path) ? (string) file_get_contents($path) : '';
$file = $file_text !== '' ? json_decode($file_text, true) : null;
$effective = track_scale_read_json_file($path, $defaults);
$report = track_scale_settings_check($file_text !== '' ? $file_text : '{}', $defaults);
$shown = ($errors !== [] && isset($text)) ? $text
    : ($file_text !== '' ? $file_text : json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

sts_page_start([
    'title' => 'Track Scale Settings',
    'icon' => 'bi-gear',
    'section' => 'ops',
    'back_href' => 'plugin.php?id=track_scale',
    'back_label' => 'Track Scale',
    'container' => 'narrow',
    'head_extra' => '<style>
      .ts-card { border: none; box-shadow: 0 2px 6px rgba(0,0,0,.1); }
      .ts-card .card-header { font-weight: 600; }
      .ts-muted { color: #666; font-size: .9rem; }
      .ts-json { font-family: monospace; font-size: .85rem; min-height: 28rem; }
      .ts-pre { font-size: .8rem; max-height: 28rem; overflow: auto; background: #f8f9fa; padding: .75rem; }
    </style>',
]);
?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?php echo sts_h($e); ?></div><?php endforeach; ?>
<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo sts_h($m); ?></div><?php endforeach; ?>

<div class="card ts-card mb-3">
  <div class="card-header"><i class="bi bi-folder2-open"></i> Where the settings live</div>
  <div class="card-body">
    <p class="mb-1">Folder: <code><?php echo sts_h(track_scale_data_dir()); ?></code><?php echo getenv('TRACK_SCALE_DATA_DIR') ? ' (from TRACK_SCALE_DATA_DIR)' : ''; ?></p>
    <p class="mb-1">Config: <code><?php echo sts_h(basename($path)); ?></code> — <?php echo $file_text === '' ? '<strong>not there</strong>; the built-in defaults are in force' : 'read on every page and step'; ?>.</p>
    <p class="ts-muted mb-0">The same folder holds <code>settings.json</code> (the test car chosen on the scale page), <code>seed.json</code>, <code>car_card_roster.csv</code> and <code>session.log</code>; they are not edited here.</p>
  </div>
</div>

<?php if ($report['warnings'] !== []): ?>
  <div class="card ts-card mb-3">
    <div class="card-header"><i class="bi bi-exclamation-triangle"></i> In the saved file</div>
    <div class="card-body">
      <ul class="mb-0"><?php foreach ($report['warnings'] as $w): ?><li><?php echo sts_h($w); ?></li><?php endforeach; ?></ul>
    </div>
  </div>
<?php endif; ?>

<div class="card ts-card mb-3">
  <div class="card-header"><i class="bi bi-filetype-json"></i> <?php echo sts_h(basename($path)); ?></div>
  <div class="card-body">
    <p class="ts-muted">Each top-level key here replaces the built-in default of the same name, whole: a group such as <code>simulation</code> or <code>calibration</code> must list every key it needs. Keys left out keep their defaults. Saving checks the JSON first and keeps the previous file as <code>.bak</code>.<?php echo $file_text === '' ? ' Shown below are the defaults, as a starting point.' : ''; ?></p>
    <form method="post">
      <textarea class="form-control ts-json" name="config" rows="28" spellcheck="false"><?php echo sts_h($shown); ?></textarea>
      <button class="btn btn-primary mt-2" name="action" value="save"><i class="bi bi-save"></i> Save</button>
    </form>
  </div>
</div>

<div class="card ts-card mb-3">
  <div class="card-header"><i class="bi bi-check2-circle"></i> In force now</div>
  <div class="card-body">
    <p class="ts-muted">The built-in defaults with the file on top: each top-level key in the file replaces its default whole. This is what the scale page and the workflow steps read; a key missing from a replaced group is not here.</p>
    <pre class="ts-pre"><?php echo sts_h(json_encode($effective, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
  </div>
</div>
<?php
sts_page_end();
