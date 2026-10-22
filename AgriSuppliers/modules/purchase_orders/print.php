<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('po.view');

$id = (int)($_GET['id'] ?? 0);
$po = db_row('SELECT po.*, s.business_name, s.supplier_code, s.contact_person, s.telephone, s.email, s.address, c.full_name AS creator, a.full_name AS approver
                FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN users c ON c.id = po.created_by LEFT JOIN users a ON a.id = po.approved_by WHERE po.id = ?', [$id]);
if (!$po) {
    abort_page(404, 'Purchase order not found.');
}
$items = db_all('SELECT * FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id', [$id]);
$cfg = app_config();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title><?= e($po['po_number']) ?></title>
<link rel="stylesheet" href="<?= e(vendor_url('bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>">
<style>body{padding:2rem;color:#000}.sig{border-top:1px solid #000;margin-top:3rem;padding-top:.25rem;width:220px}</style></head>
<body>
<div class="d-flex justify-content-between align-items-start mb-4">
  <div class="d-flex align-items-center gap-3"><img src="<?= e(url('assets/img/logo-emblem.png')) ?>" alt="" width="64" height="64"><div><h1 class="h3 mb-0">PURCHASE ORDER</h1><div class="text-muted"><?= e($cfg['app_title']) ?></div></div></div>
  <div class="text-end"><div class="h5 mb-0"><?= e($po['po_number']) ?></div><div>Status: <?= e(role_label($po['status'])) ?></div><div>Date: <?= e(fmt_dt($po['order_date'], 'M j, Y')) ?></div>
    <div>Expected: <?= e(fmt_dt($po['expected_date'], 'M j, Y')) ?></div></div>
</div>
<div class="mb-4"><strong>Supplier</strong><br><?= e($po['business_name']) ?> (<?= e($po['supplier_code']) ?>)<br><?= e($po['address']) ?><br>
  Attn: <?= e($po['contact_person']) ?> · <?= e($po['telephone']) ?><?= $po['email'] ? ' · ' . e($po['email']) : '' ?></div>
<table class="table table-bordered table-sm"><thead><tr><th>#</th><th>SKU</th><th>Description</th><th>Unit</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Amount</th></tr></thead><tbody>
<?php foreach ($items as $n => $i): ?>
<tr><td><?= $n + 1 ?></td><td><?= e($i['supplier_sku']) ?></td><td><?= e($i['product_name']) ?></td><td><?= e($i['unit']) ?></td><td class="text-end"><?= e(fmt_qty($i['qty_ordered'])) ?></td>
<td class="text-end"><?= e(money($i['unit_price'])) ?></td><td class="text-end"><?= e(money($i['line_total'])) ?></td></tr>
<?php endforeach; ?></tbody>
<tfoot><tr><th colspan="6" class="text-end">TOTAL</th><th class="text-end"><?= e(money($po['total_amount'])) ?></th></tr></tfoot></table>
<?php if ($po['notes']): ?><p><strong>Notes:</strong> <?= e($po['notes']) ?></p><?php endif; ?>
<div class="d-flex gap-5"><div class="sig">Prepared by: <?= e($po['creator']) ?></div><div class="sig">Approved by: <?= e($po['approver'] ?? '') ?></div></div>
<script>window.addEventListener('load',function(){window.print();});</script>
</body></html>
