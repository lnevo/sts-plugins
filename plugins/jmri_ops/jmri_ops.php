<?php
/**
 * Send Cars to JMRI: what STS would send, a preview of what JMRI would change, and the send.
 * Plain forms, no scripts (strict CSP).
 */
require_once 'open_db.php';
require_once 'page_chrome.php';
plugins_require_helper('jmri_ops');

$dbc = open_db();
$config = jmri_ops_config();
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
$run = null;
if ($action === 'preview' || $action === 'send') {
    $run = jmri_ops_sync($dbc, $action === 'preview');
}

$listener = null;
$listener_error = '';
if ($config['url'] !== '') {
    try {
        $listener = jmri_ops_listener_version($config);
    } catch (Throwable $e) {
        $listener_error = $e->getMessage();
    }
}

$local = null;
$local_error = '';
if ($run === null) {
    try {
        [$cars, $locations, $shipments] = jmri_ops_read_rows($dbc);
        $local = jmri_ops_build_batch(jmri_ops_load_map(), $cars, $locations, $shipments);
    } catch (Throwable $e) {
        $local_error = $e->getMessage();
    }
}

function jmri_ops_page_where($location, $track)
{
    return $location === '' ? '' : $location . ($track !== '' ? ' / ' . $track : '');
}

sts_page_start([
    'title' => 'Send Cars to JMRI',
    'icon' => 'bi-arrow-left-right',
    'section' => 'ops',
    'container' => 'narrow',
    'links' => [['href' => 'plugin.php?id=jmri_ops&page=settings', 'label' => 'Settings', 'icon' => 'bi-gear']],
    'head_extra' => '<style>
      .jo-card { border: none; box-shadow: 0 2px 6px rgba(0,0,0,.1); }
      .jo-card .card-header { font-weight: 600; }
      .jo-muted { color: #666; font-size: .9rem; }
      .jo-table td, .jo-table th { font-size: .9rem; vertical-align: top; }
      .jo-buttons .btn { min-height: 44px; min-width: 140px; }
    </style>',
]);
?>
<div class="card jo-card mb-3">
  <div class="card-header"><i class="bi bi-plug"></i> JMRI</div>
  <div class="card-body">
    <?php if ($config['url'] === ''): ?>
      <div class="alert alert-secondary mb-2">Off: no JMRI url. Set it in <a href="plugin.php?id=jmri_ops&amp;page=settings">Settings</a>.</div>
    <?php elseif ($listener_error !== ''): ?>
      <div class="alert alert-warning mb-2"><?php echo sts_h($listener_error); ?>. Is JMRI running with its web server on? Check the address in <a href="plugin.php?id=jmri_ops&amp;page=settings">Settings</a>.</div>
    <?php elseif ($listener === null): ?>
      <div class="alert alert-warning mb-2">JMRI answers at <code><?php echo sts_h($config['url']); ?></code>, but <code>sts_car_sync.py</code> is not running. Add it to JMRI's Start Up and restart JMRI.</div>
    <?php else: ?>
      <div class="alert alert-success mb-2">Ready: <code><?php echo sts_h($config['url']); ?></code>, sts_car_sync.py version <?php echo sts_h($listener); ?>.</div>
    <?php endif; ?>
    <p class="jo-muted mb-3">STS is the master. Every car's location, load and final destination are copied into JMRI Operations Pro<?php echo $config['save'] ? ', then JMRI saves Operations' : ''; ?>. Cars on a built JMRI train are left alone. The JMRI address and the STS-to-JMRI names are in <a href="plugin.php?id=jmri_ops&amp;page=settings">Settings</a>.</p>
    <form method="post" class="d-flex flex-wrap gap-2 jo-buttons">
      <button class="btn btn-outline-primary" name="action" value="preview"><i class="bi bi-eye"></i> Preview</button>
      <button class="btn btn-primary" name="action" value="send"><i class="bi bi-send"></i> Send to JMRI</button>
    </form>
  </div>
</div>

<?php if ($run !== null): ?>
  <div class="card jo-card mb-3">
    <div class="card-header"><i class="bi bi-list-check"></i> <?php echo $run['dry_run'] ? 'Preview' : 'Sent'; ?></div>
    <div class="card-body">
      <?php if (empty($run['ok'])): ?>
        <div class="alert alert-danger"><?php echo sts_h($run['error'] ?? 'failed'); ?></div>
      <?php else: ?>
        <?php $s = $run['summary']; ?>
        <p><?php echo (int) $run['cars_sent']; ?> cars sent:
          <?php echo (int) ($s['changed'] ?? 0); ?> <?php echo $run['dry_run'] ? 'would change' : 'changed'; ?>,
          <?php echo (int) ($s['unchanged'] ?? 0); ?> already matched,
          <?php echo (int) ($s['skipped'] ?? 0); ?> left alone by JMRI,
          <?php echo (int) ($s['error'] ?? 0); ?> errors.
          <?php echo !empty($run['saved']) ? 'JMRI saved Operations.' : ''; ?></p>
        <?php $rows = array_filter($run['jmri'], function ($r) { return ($r['status'] ?? '') !== 'unchanged' || !empty($r['notes']); }); ?>
        <?php if ($rows !== []): ?>
          <div class="table-responsive"><table class="table table-sm jo-table">
            <thead><tr><th>Car</th><th>Result</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo sts_h($r['car'] ?? ''); ?></td>
                <td><?php echo sts_h($r['status'] ?? ''); ?></td>
                <td><?php
                  $bits = array_merge($r['changes'] ?? [], $r['notes'] ?? []);
                  if (!empty($r['reason'])) {
                      $bits[] = $r['reason'];
                  }
                  echo sts_h(implode('; ', $bits));
                ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php
$not_sent = $run['not_sent'] ?? ($local['skipped'] ?? []);
$notes = $run['notes'] ?? ($local['notes'] ?? []);
?>
<?php if ($not_sent !== [] || $notes !== []): ?>
  <div class="card jo-card mb-3">
    <div class="card-header"><i class="bi bi-exclamation-circle"></i> Not sent, and notes</div>
    <div class="card-body">
      <div class="table-responsive"><table class="table table-sm jo-table">
        <thead><tr><th>Car</th><th>Why</th></tr></thead>
        <tbody>
        <?php foreach ($not_sent as $r): ?>
          <tr><td><?php echo sts_h($r['car']); ?></td><td>Not sent: <?php echo sts_h($r['reason']); ?></td></tr>
        <?php endforeach; ?>
        <?php foreach ($notes as $r): ?>
          <tr><td><?php echo sts_h($r['car']); ?></td><td><?php echo sts_h($r['note']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
<?php endif; ?>

<?php if ($local_error !== ''): ?>
  <div class="alert alert-danger"><?php echo sts_h($local_error); ?></div>
<?php elseif ($local !== null): ?>
  <div class="card jo-card mb-3">
    <div class="card-header"><i class="bi bi-table"></i> What STS would send (<?php echo count($local['cars']); ?> cars)</div>
    <div class="card-body">
      <p class="jo-muted">A blank track keeps the car's JMRI track at that location, or takes the first that accepts it. A blank load is JMRI's empty.</p>
      <div class="table-responsive"><table class="table table-sm table-striped jo-table">
        <thead><tr><th>Car</th><th>Location</th><th>Load</th><th>Final destination</th></tr></thead>
        <tbody>
        <?php foreach ($local['cars'] as $c): ?>
          <tr>
            <td><?php echo sts_h($c['car']); ?></td>
            <td><?php echo sts_h(jmri_ops_page_where($c['location'], $c['track'])); ?></td>
            <td><?php echo sts_h($c['load'] !== '' ? $c['load'] : 'empty'); ?></td>
            <td><?php echo sts_h(jmri_ops_page_where($c['final_location'], $c['final_track'])); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
<?php endif; ?>
<?php
sts_page_end();
