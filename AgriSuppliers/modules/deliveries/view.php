<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('deliveries.view');

$id = (int)($_GET['id'] ?? 0);
$d = db_row('SELECT d.*, po.po_number, po.supplier_id, s.business_name, u.full_name AS recorder
               FROM deliveries d JOIN purchase_orders po ON po.id = d.purchase_order_id JOIN suppliers s ON s.id = po.supplier_id JOIN users u ON u.id = d.recorded_by
              WHERE d.id = ?', [$id]);
if (!$d) {
    abort_page(404, 'Delivery not found.');
}
$items = db_all('SELECT di.*, poi.product_name, poi.supplier_sku, poi.unit, poi.unit_price FROM delivery_items di JOIN purchase_order_items poi ON poi.id = di.po_item_id WHERE di.delivery_id = ? ORDER BY di.id', [$id]);
$logs = $d['ack_receipt_ref'] ? db_all("SELECT created_at, method, endpoint, http_status, outcome, message FROM api_sync_logs WHERE endpoint LIKE ? ORDER BY id DESC LIMIT 10", ['%/deliveries/' . $id . '/%']) : [];

$pageTitle = 'Delivery ' . $d['delivery_ref'];
$activeMenu = 'deliveries';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <a class="btn btn-light" href="index.php"><i class="bi bi-arrow-left"></i> All deliveries</a>
  <div class="d-flex gap-1">
  <?php if (can('deliveries.manage') && $d['status'] === 'expected'): ?>
    <a class="btn btn-success" href="form.php?id=<?= $id ?>"><i class="bi bi-pencil"></i> Edit / confirm</a>
  <?php endif; ?>
  <?php if (can('deliveries.manage') && in_array($d['status'], ['expected', 'confirmed'], true)): ?>
    <form method="post" action="action.php" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="cancel">
      <input class="form-control" name="reason" maxlength="200" required placeholder="Cancellation reason">
      <button class="btn btn-outline-danger text-nowrap" type="submit" data-confirm="Cancel delivery <?= e($d['delivery_ref']) ?>?<?= $d['status'] === 'confirmed' ? ' The Inventory system will no longer be able to receive it.' : '' ?>"><i class="bi bi-slash-circle"></i> Cancel</button></form>
  <?php endif; ?>
  </div>
</div>
<div class="card mb-3"><div class="card-header">Delivery <span class="float-end"><?= status_badge($d['status']) ?></span></div><div class="card-body"><dl class="row mb-0">
  <dt class="col-sm-3">Reference</dt><dd class="col-sm-9"><?= e($d['delivery_ref']) ?></dd>
  <dt class="col-sm-3">Purchase order</dt><dd class="col-sm-9"><a href="<?= e(url('modules/purchase_orders/view.php?id=' . (int)$d['purchase_order_id'])) ?>"><?= e($d['po_number']) ?></a> · <?= e($d['business_name']) ?></dd>
  <dt class="col-sm-3">Expected / arrived</dt><dd class="col-sm-9"><?= e(fmt_dt($d['expected_date'], 'M j, Y')) ?> / <?= e(fmt_dt($d['arrival_date'], 'M j, Y')) ?></dd>
  <dt class="col-sm-3">Recorded by</dt><dd class="col-sm-9"><?= e($d['recorder']) ?><?= $d['confirmed_at'] ? ' · confirmed ' . e(fmt_dt($d['confirmed_at'])) : '' ?></dd>
  <dt class="col-sm-3">Inventory receipt</dt><dd class="col-sm-9"><?= $d['ack_receipt_ref'] ? e($d['ack_receipt_ref']) . ' · acknowledged ' . e(fmt_dt($d['acknowledged_at'])) : ($d['status'] === 'confirmed' ? '<span class="text-warning">Waiting for the Inventory system to verify and acknowledge</span>' : '—') ?></dd>
  <dt class="col-sm-3">Notes</dt><dd class="col-sm-9"><?= e($d['notes'] ?: '—') ?></dd>
</dl></div></div>
<div class="card"><div class="card-header">Items</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>SKU</th><th>Product</th><th>Unit</th><th class="text-end">Delivered</th><th class="text-end">Rejected</th><th class="text-end">Accepted by Inventory</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?>
    <tr><td><?= e($i['supplier_sku']) ?></td><td><?= e($i['product_name']) ?></td><td><?= e($i['unit']) ?></td>
      <td class="text-end"><?= e(fmt_qty($i['qty_delivered'])) ?></td><td class="text-end"><?= e(fmt_qty($i['qty_rejected'])) ?></td>
      <td class="text-end"><?= $i['qty_acknowledged'] === null ? '—' : e(fmt_qty($i['qty_acknowledged'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="6" class="text-muted p-3">No quantities recorded yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if ($logs): ?><div class="card mt-3"><div class="card-header">API activity</div><div class="table-responsive"><table class="table table-sm mb-0">
  <tbody><?php foreach ($logs as $l): ?><tr><td><?= e(fmt_dt($l['created_at'], 'M j, g:i:s A')) ?></td><td><?= e($l['method'] . ' ' . $l['endpoint']) ?></td><td><?= (int)$l['http_status'] ?></td><td><?= e($l['outcome']) ?></td><td class="small"><?= e($l['message'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
