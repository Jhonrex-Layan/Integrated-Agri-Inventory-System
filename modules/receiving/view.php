<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('receiving.view');

$id = (int)($_GET['id'] ?? 0);
$r = db_row('SELECT r.*, u.full_name FROM stock_receipts r JOIN users u ON u.id = r.received_by WHERE r.id = ?', [$id]);
if (!$r) {
    abort_page(404, 'Receipt not found.');
}
$items = db_all('SELECT i.*, p.product_code, p.name AS pname, p.unit FROM stock_receipt_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.receipt_id = ? ORDER BY i.id', [$id]);

$pageTitle = 'Receipt ' . $r['receipt_no'];
$activeMenu = 'receiving';
require APP_ROOT . '/includes/layout_header.php';
?>
<a class="btn btn-light mb-3" href="index.php"><i class="bi bi-arrow-left"></i> Receiving</a>
<div class="card mb-3"><div class="card-header">Receipt <span class="float-end"><?= status_badge($r['status']) ?></span></div><div class="card-body"><dl class="row mb-0">
  <dt class="col-sm-3">Receipt / reference</dt><dd class="col-sm-9"><?= e($r['receipt_no']) ?> · <small class="font-monospace"><?= e($r['receipt_ref']) ?></small></dd>
  <dt class="col-sm-3">Supplier delivery</dt><dd class="col-sm-9"><?= e($r['external_delivery_ref']) ?> · <?= e($r['external_po_number']) ?> · supplier ID <?= (int)$r['external_supplier_id'] ?></dd>
  <dt class="col-sm-3">Received</dt><dd class="col-sm-9"><?= e(fmt_dt($r['received_at'])) ?> by <?= e($r['full_name']) ?></dd>
  <dt class="col-sm-3">Supplier acknowledgment</dt><dd class="col-sm-9"><?= status_badge($r['ack_status'] === 'sent' ? 'acknowledged' : $r['ack_status']) ?>
    <?= $r['ack_sent_at'] ? ' ' . e(fmt_dt($r['ack_sent_at'])) : '' ?> <?= (int)$r['ack_attempts'] ? '<small class="text-muted">(' . (int)$r['ack_attempts'] . ' attempt(s))</small>' : '' ?>
    <?= $r['ack_last_error'] ? '<div class="small text-danger">' . e($r['ack_last_error']) . '</div>' : '' ?>
    <?php if (in_array($r['ack_status'], ['pending', 'failed'], true) && can('receiving.accept')): ?>
      <form method="post" action="retry.php" class="mt-2"><?= csrf_field() ?><input type="hidden" name="receipt_id" value="<?= $id ?>"><button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat"></i> Retry acknowledgment</button></form><?php endif; ?></dd>
  <dt class="col-sm-3">Notes</dt><dd class="col-sm-9"><?= e($r['notes'] ?: '—') ?></dd>
</dl></div></div>
<div class="card"><div class="card-header">Lines</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Supplier item</th><th>Local product</th><th class="text-end">Delivered</th><th class="text-end">Rejected by supplier</th><th class="text-end">Accepted into stock</th><th class="text-end">Unit cost</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?>
    <tr><td><?= e($i['item_name']) ?></td><td><?= $i['product_id'] ? e($i['product_code'] . ' — ' . $i['pname']) : '—' ?></td>
      <td class="text-end"><?= e(fmt_qty($i['qty_delivered_reported'])) ?></td><td class="text-end"><?= e(fmt_qty($i['qty_rejected_reported'])) ?></td>
      <td class="text-end fw-bold"><?= e(fmt_qty($i['qty_accepted'])) ?></td><td class="text-end"><?= e(money($i['unit_cost'])) ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
