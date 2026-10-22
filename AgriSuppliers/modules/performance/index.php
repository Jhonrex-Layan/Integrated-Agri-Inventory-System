<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('performance.view');

// Every figure is computed from recorded transactions. With no data a metric shows "n/a" - nothing is estimated.
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
$dateSql = '';
$dp = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $dateSql .= ' AND po.order_date >= ?';
    $dp[] = $from;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $dateSql .= ' AND po.order_date <= ?';
    $dp[] = $to;
}
$rows = db_all(
    "SELECT s.id, s.supplier_code, s.business_name, s.status,
            COUNT(DISTINCT po.id) AS orders,
            COALESCE(SUM(poi.qty_ordered),0) AS q_ordered,
            COALESCE(SUM(poi.qty_received),0) AS q_received
       FROM suppliers s
       LEFT JOIN purchase_orders po ON po.supplier_id = s.id AND po.status IN ('approved','sent','partially_received','completed') $dateSql
       LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
      WHERE s.status <> 'archived' GROUP BY s.id, s.supplier_code, s.business_name, s.status ORDER BY s.business_name", $dp);
$deliv = [];
foreach (db_all("SELECT po.supplier_id,
                        COUNT(DISTINCT d.id) AS n,
                        COALESCE(SUM(di.qty_delivered),0) AS q_del, COALESCE(SUM(di.qty_rejected),0) AS q_rej, COALESCE(SUM(di.qty_acknowledged),0) AS q_acc
                   FROM deliveries d JOIN purchase_orders po ON po.id = d.purchase_order_id JOIN delivery_items di ON di.delivery_id = d.id
                  WHERE d.status = 'acknowledged' $dateSql GROUP BY po.supplier_id", $dp) as $r) {
    $deliv[(int)$r['supplier_id']] = $r;
}
$timely = [];
foreach (db_all("SELECT po.supplier_id, COUNT(*) AS n, SUM(DATE(d.arrival_date) <= po.expected_date) AS on_time
                   FROM deliveries d JOIN purchase_orders po ON po.id = d.purchase_order_id
                  WHERE d.status = 'acknowledged' AND d.arrival_date IS NOT NULL AND po.expected_date IS NOT NULL $dateSql GROUP BY po.supplier_id", $dp) as $r) {
    $timely[(int)$r['supplier_id']] = $r;
}
$rating = [];
foreach (db_all('SELECT supplier_id, AVG(rating) AS avg_r, COUNT(rating) AS n FROM supplier_notes WHERE rating IS NOT NULL GROUP BY supplier_id') as $r) {
    $rating[(int)$r['supplier_id']] = $r;
}
$prices = [];
foreach (db_all('SELECT sp.supplier_id, COUNT(h.id) AS changes FROM supplier_price_history h JOIN supplier_products sp ON sp.id = h.supplier_product_id WHERE h.reason <> ? OR h.reason IS NULL GROUP BY sp.supplier_id', ['Initial quote']) as $r) {
    $prices[(int)$r['supplier_id']] = (int)$r['changes'];
}
$pct = fn($a, $b) => $b > 0 ? number_format($a / $b * 100, 1) . '%' : 'n/a';

$pageTitle = 'Supplier performance';
$activeMenu = 'performance';
require APP_ROOT . '/includes/layout_header.php';
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><label class="form-label small mb-0">Orders from</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0">to</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-auto align-self-end"><button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Apply</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th>Supplier</th><th class="text-center">Orders</th><th class="text-center">Fulfillment rate</th><th class="text-center">On-time deliveries</th>
    <th class="text-end">Accepted</th><th class="text-end">Rejected</th><th class="text-center">Rejection rate</th><th class="text-center">Staff rating</th><th class="text-center">Price changes</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $sid = (int)$r['id']; $d = $deliv[$sid] ?? null; $t = $timely[$sid] ?? null; $rt = $rating[$sid] ?? null; ?>
    <tr><td><a href="<?= e(url('modules/suppliers/view.php?id=' . $sid)) ?>"><?= e($r['business_name']) ?></a> <?= $r['status'] !== 'active' ? status_badge($r['status']) : '' ?></td>
      <td class="text-center"><?= (int)$r['orders'] ?></td>
      <td class="text-center" title="<?= e(fmt_qty($r['q_received'])) ?> received of <?= e(fmt_qty($r['q_ordered'])) ?> ordered"><?= e($pct((float)$r['q_received'], (float)$r['q_ordered'])) ?></td>
      <td class="text-center"><?= $t ? e($pct((float)$t['on_time'], (float)$t['n'])) . ' <small class="text-muted">(' . (int)$t['on_time'] . '/' . (int)$t['n'] . ')</small>' : 'n/a' ?></td>
      <td class="text-end"><?= $d ? e(fmt_qty($d['q_acc'])) : '—' ?></td><td class="text-end"><?= $d ? e(fmt_qty($d['q_rej'])) : '—' ?></td>
      <td class="text-center"><?= $d ? e($pct((float)$d['q_rej'], (float)$d['q_del'])) : 'n/a' ?></td>
      <td class="text-center"><?= $rt ? e(number_format((float)$rt['avg_r'], 1)) . ' / 5 <small class="text-muted">(' . (int)$rt['n'] . ')</small>' : 'n/a' ?></td>
      <td class="text-center"><?= $prices[$sid] ?? 0 ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="text-muted p-3">No suppliers to report on.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<p class="small text-muted mt-2">Fulfillment = quantity acknowledged by Inventory ÷ quantity ordered, on approved/sent/received/completed orders. On-time = arrival on or before the order's expected date, for acknowledged deliveries.
Rejection rate = rejected ÷ delivered. "n/a" means there are no recorded transactions to calculate from.</p>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
