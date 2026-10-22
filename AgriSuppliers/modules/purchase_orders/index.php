<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('po.view');

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$sid = (int)($_GET['supplier_id'] ?? 0);
$statuses = ['draft', 'pending_approval', 'approved', 'sent', 'partially_received', 'completed', 'cancelled', 'rejected'];
$where = ['1=1'];
$p = [];
if ($q !== '') {
    $where[] = '(po.po_number LIKE ? OR s.business_name LIKE ?)';
    $l = like_param($q);
    array_push($p, $l, $l);
}
if (in_array($status, $statuses, true)) {
    $where[] = 'po.status = ?';
    $p[] = $status;
} elseif ($status === 'open') {
    $where[] = "po.status IN ('pending_approval','approved','sent','partially_received')";
}
if ($sid > 0) {
    $where[] = 'po.supplier_id = ?';
    $p[] = $sid;
}
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE $W", $p));
$rows = db_all("SELECT po.*, s.business_name, u.full_name AS creator,
                       (SELECT COALESCE(SUM(qty_ordered),0) FROM purchase_order_items WHERE purchase_order_id = po.id) AS q_ord,
                       (SELECT COALESCE(SUM(qty_received),0) FROM purchase_order_items WHERE purchase_order_id = po.id) AS q_rec
                  FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN users u ON u.id = po.created_by
                 WHERE $W ORDER BY po.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$suppliers = db_all("SELECT id, business_name FROM suppliers WHERE status <> 'archived' ORDER BY business_name");
$today = date('Y-m-d');

$pageTitle = 'Purchase orders';
$activeMenu = 'po';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <form class="row g-2" method="get">
    <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="PO number or supplier"></div>
    <div class="col-auto"><select class="form-select" name="status"><option value="">All statuses</option><option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open (needs action)</option>
      <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(role_label($s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><select class="form-select" name="supplier_id"><option value="">All suppliers</option>
      <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $sid === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['business_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filter</button></div>
  </form>
  <?php if (can('po.create')): ?><a class="btn btn-success" href="form.php"><i class="bi bi-plus-lg"></i> New purchase order</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>PO number</th><th>Supplier</th><th>Order date</th><th>Expected</th><th class="text-end">Total</th><th class="text-center">Received</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r):
      $late = $r['expected_date'] && $r['expected_date'] < $today && in_array($r['status'], PO_DELIVERABLE, true); ?>
    <tr><td><a href="view.php?id=<?= (int)$r['id'] ?>"><?= e($r['po_number']) ?></a></td><td><?= e($r['business_name']) ?></td>
      <td><?= e(fmt_dt($r['order_date'], 'M j, Y')) ?></td>
      <td class="<?= $late ? 'text-danger fw-bold' : '' ?>"><?= e(fmt_dt($r['expected_date'], 'M j, Y')) ?><?= $late ? ' <i class="bi bi-exclamation-circle" title="Overdue"></i>' : '' ?></td>
      <td class="text-end"><?= e(money($r['total_amount'])) ?></td>
      <td class="text-center"><?= e(fmt_qty($r['q_rec'])) ?> / <?= e(fmt_qty($r['q_ord'])) ?></td><td><?= status_badge($r['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted p-3">No purchase orders match.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
