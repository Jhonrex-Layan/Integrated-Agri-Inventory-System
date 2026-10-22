<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('sales.view');

$q = trim((string)($_GET['q'] ?? ''));
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
$pay = (string)($_GET['payment'] ?? '');
$status = (string)($_GET['status'] ?? '');
$where = ['1=1'];
$p = [];
if ($q !== '') { $where[] = '(s.sale_no LIKE ? OR s.customer_name LIKE ?)'; $l = like_param($q); array_push($p, $l, $l); }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 's.sale_date >= ?'; $p[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 's.sale_date < ?'; $p[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'; }
if (in_array($pay, ['cash', 'card', 'bank_transfer', 'e_wallet', 'credit'], true)) { $where[] = 's.payment_method = ?'; $p[] = $pay; }
if (in_array($status, ['completed', 'voided'], true)) { $where[] = 's.status = ?'; $p[] = $status; }
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM sales s WHERE $W", $p));
$rows = db_all("SELECT s.*, u.full_name, (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) AS line_count FROM sales s JOIN users u ON u.id = s.created_by
                 WHERE $W ORDER BY s.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$sum = db_row("SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS n FROM sales s WHERE $W AND s.status = 'completed'", $p);

$pageTitle = 'Sales';
$activeMenu = 'sales';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <form class="row g-2" method="get">
    <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Sale no. or customer"></div>
    <div class="col-auto"><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="col-auto"><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="col-auto"><select class="form-select" name="payment"><option value="">Any payment</option><?php foreach (['cash', 'card', 'bank_transfer', 'e_wallet', 'credit'] as $m): ?><option value="<?= $m ?>" <?= $pay === $m ? 'selected' : '' ?>><?= e(role_label($m)) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><select class="form-select" name="status"><option value="">Any status</option><option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option><option value="voided" <?= $status === 'voided' ? 'selected' : '' ?>>Voided</option></select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Filter</button></div>
  </form>
  <?php if (can('sales.create')): ?><a class="btn btn-success" href="form.php"><i class="bi bi-cart-plus"></i> New sale</a><?php endif; ?>
</div>
<p class="text-muted">Completed sales in this view: <strong><?= (int)$sum['n'] ?></strong> totalling <strong><?= e(money($sum['total'])) ?></strong></p>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Sale no.</th><th>Date</th><th>Customer</th><th class="text-center">Lines</th><th>Payment</th><th class="text-end">Total</th><th>Status</th><th>By</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $s): ?>
    <tr><td><a href="view.php?id=<?= (int)$s['id'] ?>"><?= e($s['sale_no']) ?></a></td><td class="text-nowrap"><?= e(fmt_dt($s['sale_date'])) ?></td><td><?= e($s['customer_name'] ?: 'Walk-in') ?></td>
      <td class="text-center"><?= (int)$s['line_count'] ?></td><td><?= e(role_label($s['payment_method'])) ?></td><td class="text-end"><?= e(money($s['total_amount'])) ?></td>
      <td><?= $s['status'] === 'voided' ? '<span class="badge text-bg-danger">Voided</span>' : '<span class="badge text-bg-success">Completed</span>' ?></td><td><?= e($s['full_name']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="receipt.php?id=<?= (int)$s['id'] ?>" target="_blank" title="Print receipt"><i class="bi bi-printer"></i></a></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="text-muted p-3">No sales match.</td></tr><?php endif; ?></tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
