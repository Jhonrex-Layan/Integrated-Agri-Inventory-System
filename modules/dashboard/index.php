<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('dashboard.view');

$todayStart = date('Y-m-d 00:00:00');
$tomorrow   = date('Y-m-d 00:00:00', strtotime('+1 day'));
$weekStart  = date('Y-m-d 00:00:00', strtotime('-6 days'));

$stats = [
    'products' => (int)db_scalar("SELECT COUNT(*) FROM products WHERE status <> 'archived'"),
    'qty'      => (float)db_scalar("SELECT COALESCE(SUM(quantity),0) FROM products WHERE status <> 'archived'"),
    'value'    => (float)db_scalar("SELECT COALESCE(SUM(quantity * cost_price),0) FROM products WHERE status <> 'archived'"),
    'low'      => (int)db_scalar("SELECT COUNT(*) FROM products WHERE status = 'active' AND quantity <= reorder_level"),
];
$daily = db_row(
    "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS n FROM sales
      WHERE status = 'completed' AND sale_date >= ? AND sale_date < ?",
    [$todayStart, $tomorrow]
);
// confirmed supplier deliveries waiting for verification: last count from the API, with the time it was checked (no live call on the dashboard)
$pendingReceipts = (int)(setting_get('incoming_deliveries_count') ?? 0);
$incomingAt      = setting_get('incoming_checked_at');
$ackProblems     = (int)db_scalar("SELECT COUNT(*) FROM stock_receipts WHERE ack_status IN ('pending','failed')");
$refundsOpen     = (int)db_scalar("SELECT COUNT(*) FROM refund_requests WHERE submit_status <> 'submitted' OR remote_status IN ('approved')");
$pendingReviews  = (int)db_scalar("SELECT COUNT(*) FROM stock_movements WHERE review_status = 'pending'");

$lowStock = db_all(
    "SELECT p.product_code, p.name, p.quantity, p.reorder_level, p.unit
       FROM products p WHERE p.status = 'active' AND p.quantity <= p.reorder_level
      ORDER BY (p.quantity - p.reorder_level) ASC, p.name LIMIT 8"
);
$movements = db_all(
    "SELECT m.created_at, m.movement_type, m.quantity, m.balance_after, p.name, p.unit
       FROM stock_movements m JOIN products p ON p.id = m.product_id ORDER BY m.id DESC LIMIT 8"
);
$recentSales = db_all(
    "SELECT s.sale_no, s.sale_date, s.total_amount, s.payment_method
       FROM sales s WHERE s.status = 'completed' ORDER BY s.id DESC LIMIT 8"
);
$byCategory = db_all(
    "SELECT c.name, COALESCE(SUM(p.quantity * p.cost_price),0) AS value
       FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.status <> 'archived'
      WHERE c.is_archived = 0 GROUP BY c.id, c.name ORDER BY value DESC"
);
$salesRows = db_all(
    "SELECT DATE(sale_date) AS d, SUM(total_amount) AS total FROM sales
      WHERE status = 'completed' AND sale_date >= ? AND sale_date < ? GROUP BY DATE(sale_date)",
    [$weekStart, $tomorrow]
);
$salesByDay = [];
foreach ($salesRows as $r) {
    $salesByDay[$r['d']] = (float)$r['total'];
}
$labels = $values = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('M j', strtotime($d));
    $values[] = $salesByDay[$d] ?? 0;
}
$api = integration_status();

$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
$pageScripts = '<script src="' . e(vendor_url('chart.umd.min.js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js')) . '"></script>'
    . '<script>(function(){if(typeof Chart==="undefined")return;'
    . 'var cat=' . json_for_script($byCategory) . ', lbl=' . json_for_script($labels) . ', val=' . json_for_script($values) . ';'
    . 'var a=document.getElementById("catChart");if(a&&cat.length){new Chart(a,{type:"bar",data:{labels:cat.map(function(r){return r.name;}),'
    . 'datasets:[{label:"Inventory value (cost)",data:cat.map(function(r){return +r.value;}),backgroundColor:"#66bb6a"}]},'
    . 'options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});}'
    . 'var b=document.getElementById("salesChart");if(b){new Chart(b,{type:"line",data:{labels:lbl,'
    . 'datasets:[{label:"Sales",data:val,borderColor:"#2e7d32",backgroundColor:"rgba(46,125,50,.15)",fill:true,tension:.3}]},'
    . 'options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});}'
    . '})();</script>';

require APP_ROOT . '/includes/layout_header.php';
?>
<?php if ($stats['low'] > 0 || $pendingReceipts > 0 || $ackProblems > 0 || $pendingReviews > 0 || $refundsOpen > 0): ?>
<div class="mb-3">
  <?php if ($stats['low'] > 0): ?>
    <div class="alert alert-warning py-2 mb-2"><i class="bi bi-exclamation-triangle"></i> <?= $stats['low'] ?> product(s) at or below their reorder level.</div>
  <?php endif; ?>
  <?php if ($pendingReceipts > 0): ?>
    <div class="alert alert-info py-2 mb-2"><i class="bi bi-truck"></i> <?= $pendingReceipts ?> confirmed supplier delivery(ies) waiting for verification <small>(as of <?= e(fmt_dt($incomingAt)) ?> — <a href="<?= e(url('modules/receiving/index.php')) ?>">open Receiving</a> to refresh)</small>.</div>
  <?php endif; ?>
  <?php if ($ackProblems > 0): ?>
    <div class="alert alert-danger py-2 mb-2"><i class="bi bi-arrow-repeat"></i> <?= $ackProblems ?> accepted receipt(s) have not been acknowledged to the Supplier system yet.</div>
  <?php endif; ?>
  <?php if ($refundsOpen > 0): ?>
    <div class="alert alert-info py-2 mb-2"><i class="bi bi-cash-coin"></i> <?= $refundsOpen ?> supplier refund request(s) need attention (unsent, or approved by the supplier). See Damaged &amp; Refunds.</div>
  <?php endif; ?>
  <?php if ($pendingReviews > 0 && can('adjustments.review')): ?>
    <div class="alert alert-secondary py-2 mb-2"><i class="bi bi-clipboard-check"></i> <?= $pendingReviews ?> stock adjustment(s) awaiting review.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Total products', number_format($stats['products']), 'box-seam', ''],
      ['Total stock quantity', fmt_qty($stats['qty']), 'stack', ''],
      ['Inventory value (cost)', money($stats['value']), 'cash-stack', ''],
      ['Sales today (' . (int)$daily['n'] . ')', money($daily['total']), 'cart-check', ''],
      ['Low-stock products', number_format($stats['low']), 'exclamation-triangle', $stats['low'] ? 'danger' : ''],
  ] as [$label, $val, $icon, $cls]): ?>
  <div class="col-6 col-lg">
    <div class="card stat-card <?= e($cls) ?>"><div class="card-body">
      <div class="stat-label"><i class="bi bi-<?= e($icon) ?>"></i> <?= e($label) ?></div>
      <div class="stat-value"><?= e($val) ?></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7"><div class="card h-100"><div class="card-header">Sales, last 7 days</div>
    <div class="card-body"><div class="chart-box"><canvas id="salesChart"></canvas></div></div></div></div>
  <div class="col-lg-5"><div class="card h-100"><div class="card-header">Inventory value by category</div>
    <div class="card-body">
      <?php if ($byCategory): ?><div class="chart-box"><canvas id="catChart"></canvas></div>
      <?php else: ?><p class="text-muted mb-0">No categories yet.</p><?php endif; ?>
    </div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Low-stock products</div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
      <thead><tr><th>Code</th><th>Product</th><th class="text-end">On hand</th><th class="text-end">Reorder at</th></tr></thead>
      <tbody>
      <?php foreach ($lowStock as $p): ?>
        <tr><td><?= e($p['product_code']) ?></td><td><?= e($p['name']) ?></td>
            <td class="text-end <?= (float)$p['quantity'] <= 0 ? 'text-danger fw-bold' : '' ?>"><?= e(fmt_qty($p['quantity'])) ?> <?= e($p['unit']) ?></td>
            <td class="text-end"><?= e(fmt_qty($p['reorder_level'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$lowStock): ?><tr><td colspan="4" class="text-muted p-3">No products are at or below their reorder level.</td></tr><?php endif; ?>
      </tbody></table></div></div></div>

  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Supplier integration</div>
    <div class="card-body">
      <span class="badge text-bg-<?= e($api['class']) ?>"><?= e($api['label']) ?></span>
      <p class="small text-muted mt-2 mb-3"><?= e($api['detail']) ?> Sales, products and stock adjustments keep working while the Supplier system is offline.</p>
      <?php if (can('sync.check')): ?>
      <form method="post" action="<?= e(url('modules/dashboard/check_connection.php')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-outline-success btn-sm" type="submit"><i class="bi bi-arrow-repeat"></i> Check connection</button>
      </form>
      <?php endif; ?>
    </div></div></div>

  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Recent stock movements</div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
      <thead><tr><th>When</th><th>Product</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Balance</th></tr></thead>
      <tbody>
      <?php foreach ($movements as $m): ?>
        <tr><td class="text-nowrap"><?= e(fmt_dt($m['created_at'], 'M j, g:i A')) ?></td><td><?= e($m['name']) ?></td>
            <td><?= e(role_label($m['movement_type'])) ?></td>
            <td class="text-end"><?= e(fmt_qty($m['quantity'])) ?></td><td class="text-end"><?= e(fmt_qty($m['balance_after'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$movements): ?><tr><td colspan="5" class="text-muted p-3">No stock movements recorded yet.</td></tr><?php endif; ?>
      </tbody></table></div></div></div>

  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Recent sales</div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
      <thead><tr><th>No.</th><th>Date</th><th>Payment</th><th class="text-end">Total</th></tr></thead>
      <tbody>
      <?php foreach ($recentSales as $s): ?>
        <tr><td><?= e($s['sale_no']) ?></td><td class="text-nowrap"><?= e(fmt_dt($s['sale_date'], 'M j, g:i A')) ?></td>
            <td><?= e(role_label($s['payment_method'])) ?></td><td class="text-end"><?= e(money($s['total_amount'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$recentSales): ?><tr><td colspan="4" class="text-muted p-3">No sales recorded yet.</td></tr><?php endif; ?>
      </tbody></table></div></div></div>
</div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
