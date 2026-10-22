<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('sales.view');
$id = (int)($_GET['id'] ?? 0);
$s = db_row('SELECT s.*, u.full_name FROM sales s JOIN users u ON u.id = s.created_by WHERE s.id = ?', [$id]);
if (!$s) {
    abort_page(404, 'Sale not found.');
}
$items = db_all('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$id]);
$cfg = app_config();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Receipt <?= e($s['sale_no']) ?></title>
<style>body{font-family:monospace;max-width:340px;margin:1rem auto;color:#000}table{width:100%;border-collapse:collapse}td{padding:2px 0;vertical-align:top}.r{text-align:right}hr{border:0;border-top:1px dashed #000}.c{text-align:center}</style></head>
<body>
<div class="c"><img src="<?= e(url('assets/img/logo-emblem.png')) ?>" alt="" width="64" height="64"><br><strong><?= e($cfg['app_title']) ?></strong><br><?= e($s['sale_no']) ?><br><?= e(fmt_dt($s['sale_date'])) ?><br>Cashier: <?= e($s['full_name']) ?><?= $s['customer_name'] ? '<br>Customer: ' . e($s['customer_name']) : '' ?></div>
<?php if ($s['status'] === 'voided'): ?><div class="c"><strong>*** VOIDED ***</strong></div><?php endif; ?>
<hr><table>
<?php foreach ($items as $i): ?>
<tr><td colspan="2"><?= e($i['product_name']) ?></td></tr>
<tr><td><?= e(fmt_qty($i['quantity'])) ?> x <?= e(number_format((float)$i['unit_price'], 2)) ?><?= (float)$i['discount_amount'] > 0 ? ' (−' . e(number_format((float)$i['discount_amount'], 2)) . ')' : '' ?></td><td class="r"><?= e(number_format((float)$i['line_total'], 2)) ?></td></tr>
<?php endforeach; ?>
</table><hr>
<table><tr><td>Subtotal</td><td class="r"><?= e(number_format((float)$s['subtotal'], 2)) ?></td></tr>
<tr><td>Discounts</td><td class="r">−<?= e(number_format((float)$s['discount_amount'], 2)) ?></td></tr>
<tr><td><strong>TOTAL</strong></td><td class="r"><strong><?= e(number_format((float)$s['total_amount'], 2)) ?></strong></td></tr>
<tr><td><?= e(role_label($s['payment_method'])) ?></td><td class="r"><?= $s['amount_tendered'] !== null ? e(number_format((float)$s['amount_tendered'], 2)) : '' ?></td></tr>
<?php if ($s['amount_tendered'] !== null): ?><tr><td>Change</td><td class="r"><?= e(number_format((float)$s['amount_tendered'] - (float)$s['total_amount'], 2)) ?></td></tr><?php endif; ?></table>
<hr><div class="c">Thank you!</div>
<script>window.addEventListener('load',function(){window.print();});</script></body></html>
