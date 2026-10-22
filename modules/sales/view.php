<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('sales.view');

$id = (int)($_GET['id'] ?? 0);
$s = db_row('SELECT s.*, u.full_name, v.full_name AS voider FROM sales s JOIN users u ON u.id = s.created_by LEFT JOIN users v ON v.id = s.voided_by WHERE s.id = ?', [$id]);
if (!$s) {
    abort_page(404, 'Sale not found.');
}
$items = db_all('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$id]);
$pageTitle = 'Sale ' . $s['sale_no'];
$activeMenu = 'sales';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between mb-3"><a class="btn btn-light" href="index.php"><i class="bi bi-arrow-left"></i> All sales</a>
  <a class="btn btn-outline-secondary" href="receipt.php?id=<?= $id ?>" target="_blank"><i class="bi bi-printer"></i> Print receipt</a></div>
<?php if ($s['status'] === 'voided'): ?><div class="alert alert-danger"><strong>Voided</strong> by <?= e($s['voider'] ?? '') ?> on <?= e(fmt_dt($s['voided_at'])) ?>: <?= e($s['void_reason'] ?? '') ?>. Stock was returned.</div><?php endif; ?>
<div class="card mb-3"><div class="card-body"><dl class="row mb-0">
  <dt class="col-sm-2">Sale no.</dt><dd class="col-sm-4"><?= e($s['sale_no']) ?></dd><dt class="col-sm-2">Date</dt><dd class="col-sm-4"><?= e(fmt_dt($s['sale_date'])) ?></dd>
  <dt class="col-sm-2">Customer</dt><dd class="col-sm-4"><?= e($s['customer_name'] ?: 'Walk-in') ?></dd><dt class="col-sm-2">Payment</dt><dd class="col-sm-4"><?= e(role_label($s['payment_method'])) ?></dd>
  <dt class="col-sm-2">Cashier</dt><dd class="col-sm-4"><?= e($s['full_name']) ?></dd>
</dl></div></div>
<div class="card mb-3"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th>Code</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Discount</th><th class="text-end">Line total</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?><tr><td><?= e($i['product_code']) ?></td><td><?= e($i['product_name']) ?></td><td class="text-end"><?= e(fmt_qty($i['quantity'])) ?></td>
    <td class="text-end"><?= e(money($i['unit_price'])) ?></td><td class="text-end"><?= e(money($i['discount_amount'])) ?></td><td class="text-end"><?= e(money($i['line_total'])) ?></td></tr><?php endforeach; ?></tbody>
  <tfoot><tr><td colspan="5" class="text-end">Subtotal</td><td class="text-end"><?= e(money($s['subtotal'])) ?></td></tr>
    <tr><td colspan="5" class="text-end">Total discounts</td><td class="text-end">−<?= e(money($s['discount_amount'])) ?></td></tr>
    <tr class="fw-bold"><td colspan="5" class="text-end">Total</td><td class="text-end"><?= e(money($s['total_amount'])) ?></td></tr>
    <?php if ($s['amount_tendered'] !== null): ?><tr><td colspan="5" class="text-end">Received / change</td><td class="text-end"><?= e(money($s['amount_tendered'])) ?> / <?= e(money((float)$s['amount_tendered'] - (float)$s['total_amount'])) ?></td></tr><?php endif; ?></tfoot></table></div></div>
<?php if ($s['status'] === 'completed' && can('sales.void')): ?>
<form method="post" action="action.php" class="card card-body"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="void">
  <label class="form-label">Void this sale (returns all items to stock)</label>
  <div class="input-group"><input class="form-control" name="reason" maxlength="255" required placeholder="Reason (required)"><button class="btn btn-outline-danger" data-confirm="Void sale <?= e($s['sale_no']) ?>? Stock will be restored.">Void sale</button></div></form>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
