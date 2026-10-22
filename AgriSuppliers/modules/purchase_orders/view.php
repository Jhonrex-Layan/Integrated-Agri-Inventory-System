<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('po.view');

$id = (int)($_GET['id'] ?? 0);
$po = db_row('SELECT po.*, s.business_name, s.supplier_code, s.contact_person, s.telephone, s.status AS supplier_status,
                     c.full_name AS creator, a.full_name AS approver
                FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN users c ON c.id = po.created_by LEFT JOIN users a ON a.id = po.approved_by
               WHERE po.id = ?', [$id]);
if (!$po) {
    abort_page(404, 'Purchase order not found.');
}
$items = db_all('SELECT * FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id', [$id]);
$deliveries = db_all("SELECT d.*, (SELECT COALESCE(SUM(qty_delivered),0) FROM delivery_items WHERE delivery_id = d.id) AS q_del,
                             (SELECT COALESCE(SUM(qty_rejected),0) FROM delivery_items WHERE delivery_id = d.id) AS q_rej
                        FROM deliveries d WHERE d.purchase_order_id = ? ORDER BY d.id DESC", [$id]);
$st = $po['status'];
$liveDeliveries = (int)db_scalar("SELECT COUNT(*) FROM deliveries WHERE purchase_order_id = ? AND status IN ('confirmed','acknowledged')", [$id]);
$anyDelivery = (int)db_scalar("SELECT COUNT(*) FROM deliveries WHERE purchase_order_id = ? AND status <> 'cancelled'", [$id]);

$pageTitle = $po['po_number'];
$activeMenu = 'po';
require APP_ROOT . '/includes/layout_header.php';

function po_btn(int $id, string $action, string $label, string $cls, ?string $confirm = null, string $extra = ''): string
{
    return '<form class="d-inline" method="post" action="action.php">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . e($action) . '">'
         . $extra . '<button class="btn ' . $cls . '" type="submit"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $label . '</button></form> ';
}
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <a class="btn btn-light" href="index.php"><i class="bi bi-arrow-left"></i> All purchase orders</a>
  <div class="d-flex flex-wrap gap-1">
    <a class="btn btn-outline-secondary" href="print.php?id=<?= $id ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
    <?php if (can('po.create') && in_array($st, ['draft', 'rejected'], true)): ?>
      <a class="btn btn-outline-success" href="form.php?id=<?= $id ?>"><i class="bi bi-pencil"></i> Edit</a>
      <?= po_btn($id, 'submit', '<i class="bi bi-send-check"></i> Submit for approval', 'btn-success', 'Submit ' . $po['po_number'] . ' for approval?') ?>
    <?php endif; ?>
    <?php if (can('po.approve') && $st === 'pending_approval'): ?>
      <?= po_btn($id, 'approve', '<i class="bi bi-check-circle"></i> Approve', 'btn-success', 'Approve ' . $po['po_number'] . '? Its lines and prices will be locked.') ?>
    <?php endif; ?>
    <?php if (can('po.create') && $st === 'approved'): ?>
      <?= po_btn($id, 'send', '<i class="bi bi-envelope-check"></i> Mark as sent to supplier', 'btn-outline-success') ?>
    <?php endif; ?>
    <?php if (can('deliveries.manage') && in_array($st, PO_DELIVERABLE, true)): ?>
      <a class="btn btn-success" href="<?= e(url('modules/deliveries/form.php?po_id=' . $id)) ?>"><i class="bi bi-truck"></i> Record delivery</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($st === 'rejected'): ?><div class="alert alert-danger"><strong>Rejected:</strong> <?= e($po['rejection_reason'] ?? '') ?></div><?php endif; ?>
<?php if ($po['closed_note']): ?><div class="alert alert-secondary"><strong>Note:</strong> <?= e($po['closed_note']) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Order <span class="float-end"><?= status_badge($st) ?></span></div><div class="card-body"><dl class="row mb-0">
    <dt class="col-sm-4">Supplier</dt><dd class="col-sm-8"><a href="<?= e(url('modules/suppliers/view.php?id=' . (int)$po['supplier_id'])) ?>"><?= e($po['business_name']) ?></a> <small class="text-muted"><?= e($po['supplier_code']) ?></small></dd>
    <dt class="col-sm-4">Order date</dt><dd class="col-sm-8"><?= e(fmt_dt($po['order_date'], 'M j, Y')) ?></dd>
    <dt class="col-sm-4">Expected</dt><dd class="col-sm-8"><?= e(fmt_dt($po['expected_date'], 'M j, Y')) ?></dd>
    <dt class="col-sm-4">Total</dt><dd class="col-sm-8 fw-bold"><?= e(money($po['total_amount'])) ?></dd>
    <dt class="col-sm-4">Prepared by</dt><dd class="col-sm-8"><?= e($po['creator']) ?></dd>
    <dt class="col-sm-4">Approved by</dt><dd class="col-sm-8"><?= $po['approver'] ? e($po['approver']) . ' · ' . e(fmt_dt($po['approved_at'])) : '—' ?></dd>
    <dt class="col-sm-4">Notes</dt><dd class="col-sm-8"><?= e($po['notes'] ?: '—') ?></dd>
  </dl></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Actions</div><div class="card-body">
    <?php if (can('po.approve') && $st === 'pending_approval'): ?>
      <form method="post" action="action.php" class="mb-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reject">
        <label class="form-label">Reject with reason</label><div class="input-group"><input class="form-control" name="reason" maxlength="255" required placeholder="Reason shown to the preparer">
        <button class="btn btn-outline-danger" type="submit"><i class="bi bi-x-circle"></i> Reject</button></div></form>
    <?php endif; ?>
    <?php if (in_array($st, ['draft', 'pending_approval', 'approved', 'sent'], true) && $anyDelivery === 0 && (can('po.create') || can('po.approve'))): ?>
      <form method="post" action="action.php" class="mb-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="cancel">
        <div class="input-group"><input class="form-control" name="reason" maxlength="255" placeholder="Reason (optional)">
        <button class="btn btn-outline-danger" type="submit" data-confirm="Cancel <?= e($po['po_number']) ?>? This cannot be undone."><i class="bi bi-slash-circle"></i> Cancel order</button></div></form>
    <?php endif; ?>
    <?php if (can('po.approve') && $st === 'partially_received'): ?>
      <form method="post" action="action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="close">
        <label class="form-label">Close short (supplier will not deliver the remainder)</label>
        <div class="input-group"><input class="form-control" name="reason" maxlength="255" required placeholder="Required reason">
        <button class="btn btn-outline-secondary" type="submit" data-confirm="Close <?= e($po['po_number']) ?> as completed with the quantities received so far?"><i class="bi bi-check2-all"></i> Close order</button></div></form>
    <?php endif; ?>
    <?php if (!in_array($st, ['draft', 'rejected', 'pending_approval', 'approved', 'sent', 'partially_received'], true) || (!can('po.create') && !can('po.approve') && !can('deliveries.manage'))): ?>
      <p class="text-muted mb-0">No actions are available for this order in its current state.</p>
    <?php endif; ?>
  </div></div></div>
</div>

<div class="card mb-3"><div class="card-header">Order lines</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>SKU</th><th>Product</th><th>Unit</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Outstanding</th><th class="text-end">Unit price</th><th class="text-end">Line total</th></tr></thead><tbody>
  <?php foreach ($items as $i): $out = (float)$i['qty_ordered'] - (float)$i['qty_received']; ?>
    <tr><td><?= e($i['supplier_sku']) ?></td><td><?= e($i['product_name']) ?></td><td><?= e($i['unit']) ?></td>
      <td class="text-end"><?= e(fmt_qty($i['qty_ordered'])) ?></td><td class="text-end"><?= e(fmt_qty($i['qty_received'])) ?></td>
      <td class="text-end <?= $out > 0 && in_array($st, PO_DELIVERABLE, true) ? 'fw-bold' : '' ?>"><?= e(fmt_qty($out)) ?></td>
      <td class="text-end"><?= e(money($i['unit_price'])) ?></td><td class="text-end"><?= e(money($i['line_total'])) ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>

<div class="card"><div class="card-header">Deliveries</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Reference</th><th>Arrival</th><th class="text-end">Delivered</th><th class="text-end">Rejected</th><th>Status</th><th>Inventory receipt</th></tr></thead><tbody>
  <?php foreach ($deliveries as $d): ?>
    <tr><td><a href="<?= e(url('modules/deliveries/view.php?id=' . (int)$d['id'])) ?>"><?= e($d['delivery_ref']) ?></a></td>
      <td><?= e(fmt_dt($d['arrival_date'] ?? $d['expected_date'], 'M j, Y')) ?><?= $d['arrival_date'] ? '' : ' <small class="text-muted">(expected)</small>' ?></td>
      <td class="text-end"><?= e(fmt_qty($d['q_del'])) ?></td><td class="text-end"><?= e(fmt_qty($d['q_rej'])) ?></td><td><?= status_badge($d['status']) ?></td>
      <td><?= $d['ack_receipt_ref'] ? e($d['ack_receipt_ref']) . '<br><small class="text-muted">' . e(fmt_dt($d['acknowledged_at'])) . '</small>' : '—' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$deliveries): ?><tr><td colspan="6" class="text-muted p-3">No deliveries recorded.</td></tr><?php endif; ?></tbody></table></div></div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
