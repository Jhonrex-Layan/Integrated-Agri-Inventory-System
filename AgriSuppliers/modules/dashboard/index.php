<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('dashboard.view');

$today = date('Y-m-d');
$openStatuses = "('approved','sent','partially_received')";

$stats = [
    'total'       => (int)db_scalar("SELECT COUNT(*) FROM suppliers WHERE status <> 'archived'"),
    'active'      => (int)db_scalar("SELECT COUNT(*) FROM suppliers WHERE status = 'active'"),
    'pending_po'  => (int)db_scalar("SELECT COUNT(*) FROM purchase_orders WHERE status = 'pending_approval'"),
    'open_po'     => (int)db_scalar("SELECT COUNT(*) FROM purchase_orders WHERE status IN $openStatuses"),
    'completed_po'=> (int)db_scalar("SELECT COUNT(*) FROM purchase_orders WHERE status = 'completed'"),
    'pending_del' => (int)db_scalar("SELECT COUNT(*) FROM deliveries WHERE status IN ('expected','confirmed')"),
];

$poByStatus  = db_all('SELECT status, COUNT(*) AS n FROM purchase_orders GROUP BY status ORDER BY n DESC');
$delByStatus = db_all('SELECT status, COUNT(*) AS n FROM deliveries GROUP BY status ORDER BY n DESC');

$activities = db_all(
    'SELECT a.action, a.entity_type, a.entity_id, a.details, a.created_at, u.full_name
       FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
      WHERE a.action NOT IN (\'login_failed\',\'login_blocked\')
      ORDER BY a.id DESC LIMIT 10'
);

$attention = db_all(
    "SELECT s.id, s.supplier_code, s.business_name, s.status,
            SUM(po.status IN $openStatuses AND po.expected_date IS NOT NULL AND po.expected_date < ?) AS overdue,
            SUM(po.status IN $openStatuses) AS open_orders
       FROM suppliers s JOIN purchase_orders po ON po.supplier_id = s.id
      WHERE s.status <> 'archived'
      GROUP BY s.id, s.supplier_code, s.business_name, s.status
     HAVING overdue > 0 OR (s.status = 'inactive' AND open_orders > 0)
      ORDER BY overdue DESC LIMIT 8",
    [$today]
);
$api = integration_status();

$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
$pageScripts = '<script src="' . e(vendor_url('chart.umd.min.js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js')) . '"></script>'
    . '<script>(function(){'
    . 'var po=' . json_for_script($poByStatus) . ', de=' . json_for_script($delByStatus) . ';'
    . 'var colors=["#2e7d32","#66bb6a","#f9a825","#0288d1","#c62828","#78909c","#8d6e63","#5e35b1"];'
    . 'function draw(id,rows,type){var el=document.getElementById(id);if(!el||!rows.length||typeof Chart==="undefined")return;'
    . 'new Chart(el,{type:type,data:{labels:rows.map(function(r){return r.status.replace(/_/g," ");}),'
    . 'datasets:[{data:rows.map(function(r){return +r.n;}),backgroundColor:colors}]},'
    . 'options:{maintainAspectRatio:false,plugins:{legend:{display:type!=="bar",position:"bottom"}},scales:type==="bar"?{y:{beginAtZero:true,ticks:{precision:0}}}:{}}});}'
    . 'draw("poChart",po,"bar");draw("delChart",de,"doughnut");})();</script>';

require APP_ROOT . '/includes/layout_header.php';
?>
<div class="row g-3 mb-3">
  <?php foreach ([
      ['Total suppliers', $stats['total'], 'building', ''],
      ['Active suppliers', $stats['active'], 'check-circle', ''],
      ['POs pending approval', $stats['pending_po'], 'hourglass-split', $stats['pending_po'] ? 'warn' : ''],
      ['Open purchase orders', $stats['open_po'], 'file-earmark-text', ''],
      ['Completed orders', $stats['completed_po'], 'bag-check', ''],
      ['Pending deliveries', $stats['pending_del'], 'truck', $stats['pending_del'] ? 'warn' : ''],
  ] as [$label, $val, $icon, $cls]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card <?= e($cls) ?>"><div class="card-body">
      <div class="stat-label"><i class="bi bi-<?= e($icon) ?>"></i> <?= e($label) ?></div>
      <div class="stat-value"><?= number_format($val) ?></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100"><div class="card-header">Purchase orders by status</div>
      <div class="card-body">
        <?php if ($poByStatus): ?><div class="chart-box"><canvas id="poChart"></canvas></div>
        <?php else: ?><p class="text-muted mb-0">No purchase orders have been created yet.</p><?php endif; ?>
      </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-header">Deliveries by status</div>
      <div class="card-body">
        <?php if ($delByStatus): ?><div class="chart-box"><canvas id="delChart"></canvas></div>
        <?php else: ?><p class="text-muted mb-0">No deliveries have been recorded yet.</p><?php endif; ?>
      </div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100"><div class="card-header">Recent supplier activity</div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th></tr></thead>
        <tbody>
        <?php foreach ($activities as $a): ?>
          <tr><td class="text-nowrap"><?= e(fmt_dt($a['created_at'], 'M j, g:i A')) ?></td>
              <td><?= e($a['full_name'] ?? 'System / API') ?></td>
              <td><?= e(role_label($a['action'])) ?></td>
              <td><?= e(role_label($a['entity_type'])) ?><?= $a['entity_id'] ? ' #' . (int)$a['entity_id'] : '' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$activities): ?><tr><td colspan="4" class="text-muted p-3">No activity has been recorded yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card mb-3"><div class="card-header">Suppliers requiring attention</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($attention as $s): ?>
          <li class="list-group-item d-flex justify-content-between align-items-start">
            <div><strong><?= e($s['business_name']) ?></strong> <small class="text-muted"><?= e($s['supplier_code']) ?></small><br>
              <small>
                <?php if ((int)$s['overdue'] > 0): ?><span class="text-danger"><?= (int)$s['overdue'] ?> overdue order(s)</span><?php endif; ?>
                <?php if ($s['status'] === 'inactive' && (int)$s['open_orders'] > 0): ?> Inactive with <?= (int)$s['open_orders'] ?> open order(s)<?php endif; ?>
              </small></div>
            <?= status_badge($s['status']) ?>
          </li>
        <?php endforeach; ?>
        <?php if (!$attention): ?><li class="list-group-item text-muted">No suppliers need attention right now.</li><?php endif; ?>
      </ul></div>
    <div class="card"><div class="card-header">API integration</div>
      <div class="card-body small">
        <span class="badge text-bg-<?= e($api['class']) ?>"><?= e($api['label']) ?></span>
        <div class="mt-2 text-muted"><?= e($api['detail']) ?></div>
      </div></div>
  </div>
</div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
