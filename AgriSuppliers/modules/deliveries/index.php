<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('deliveries.view');

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$where = ['1=1'];
$p = [];
if ($q !== '') {
    $where[] = '(d.delivery_ref LIKE ? OR po.po_number LIKE ? OR s.business_name LIKE ?)';
    $l = like_param($q);
    array_push($p, $l, $l, $l);
}
if (in_array($status, ['expected', 'confirmed', 'acknowledged', 'cancelled'], true)) {
    $where[] = 'd.status = ?';
    $p[] = $status;
} elseif ($status === 'awaiting_ack') {
    $where[] = "d.status = 'confirmed'";
}
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM deliveries d JOIN purchase_orders po ON po.id = d.purchase_order_id JOIN suppliers s ON s.id = po.supplier_id WHERE $W", $p));
$rows = db_all("SELECT d.*, po.po_number, po.supplier_id, s.business_name,
                       (SELECT COALESCE(SUM(qty_delivered),0) FROM delivery_items WHERE delivery_id = d.id) AS q_del,
                       (SELECT COALESCE(SUM(qty_rejected),0) FROM delivery_items WHERE delivery_id = d.id) AS q_rej,
                       (SELECT COALESCE(SUM(qty_acknowledged),0) FROM delivery_items WHERE delivery_id = d.id) AS q_ack
                  FROM deliveries d JOIN purchase_orders po ON po.id = d.purchase_order_id JOIN suppliers s ON s.id = po.supplier_id
                 WHERE $W ORDER BY d.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$today = date('Y-m-d');

$pageTitle = 'Deliveries';
$activeMenu = 'deliveries';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <form class="row g-2" method="get">
    <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Reference, PO or supplier"></div>
    <div class="col-auto"><select class="form-select" name="status"><option value="">All statuses</option>
      <option value="awaiting_ack" <?= $status === 'awaiting_ack' ? 'selected' : '' ?>>Awaiting Inventory acknowledgment</option>
      <?php foreach (['expected', 'confirmed', 'acknowledged', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(role_label($s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filter</button></div>
  </form>
  <?php if (can('deliveries.manage')): ?><a class="btn btn-success" href="form.php"><i class="bi bi-plus-lg"></i> Record delivery</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Reference</th><th>PO</th><th>Supplier</th><th>Arrival / expected</th><th class="text-end">Delivered</th><th class="text-end">Rejected</th><th class="text-end">Accepted by Inventory</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $d): $late = $d['status'] === 'expected' && $d['expected_date'] && $d['expected_date'] < $today; ?>
    <tr><td><a href="view.php?id=<?= (int)$d['id'] ?>"><?= e($d['delivery_ref']) ?></a></td>
      <td><a href="<?= e(url('modules/purchase_orders/view.php?id=' . (int)$d['purchase_order_id'])) ?>"><?= e($d['po_number']) ?></a></td><td><?= e($d['business_name']) ?></td>
      <td class="<?= $late ? 'text-danger fw-bold' : '' ?>"><?= $d['arrival_date'] ? e(fmt_dt($d['arrival_date'], 'M j, Y')) : e(fmt_dt($d['expected_date'], 'M j, Y')) . ' <small>(expected)</small>' ?></td>
      <td class="text-end"><?= e(fmt_qty($d['q_del'])) ?></td><td class="text-end"><?= e(fmt_qty($d['q_rej'])) ?></td>
      <td class="text-end"><?= $d['status'] === 'acknowledged' ? e(fmt_qty($d['q_ack'])) : '—' ?></td><td><?= status_badge($d['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted p-3">No deliveries recorded yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
