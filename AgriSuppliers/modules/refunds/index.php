<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('refunds.view');

$status = (string)($_GET['status'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$states = ['received', 'under_review', 'approved', 'rejected', 'refunded'];
$where = ['1=1'];
$p = [];
if (in_array($status, $states, true)) {
    $where[] = 'r.status = ?';
    $p[] = $status;
} elseif ($status === 'open') {
    $where[] = "r.status IN ('received','under_review','approved')";
}
if ($q !== '') {
    $where[] = '(r.request_ref LIKE ? OR r.product_name LIKE ? OR s.business_name LIKE ?)';
    $l = like_param($q);
    array_push($p, $l, $l, $l);
}
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM refund_requests r JOIN suppliers s ON s.id = r.supplier_id WHERE $W", $p));
$rows = db_all("SELECT r.*, s.business_name, u.full_name AS reviewer FROM refund_requests r JOIN suppliers s ON s.id = r.supplier_id LEFT JOIN users u ON u.id = r.reviewed_by
                 WHERE $W ORDER BY r.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$totals = db_row("SELECT COALESCE(SUM(CASE WHEN status IN ('received','under_review') THEN amount END),0) AS open_amt,
                         COALESCE(SUM(CASE WHEN status = 'approved' THEN amount END),0) AS approved_amt,
                         COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount END),0) AS refunded_amt FROM refund_requests");

$pageTitle = 'Refund requests';
$activeMenu = 'refunds';
require APP_ROOT . '/includes/layout_header.php';
?>
<p class="text-muted">Damaged-goods refund claims sent by the Inventory system through the API. Decisions made here are pulled back by Inventory.</p>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card stat-card warn"><div class="card-body"><div class="stat-label">Awaiting decision</div><div class="stat-value"><?= e(money($totals['open_amt'])) ?></div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="stat-label">Approved, not yet refunded</div><div class="stat-value"><?= e(money($totals['approved_amt'])) ?></div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="stat-label">Refunded</div><div class="stat-value"><?= e(money($totals['refunded_amt'])) ?></div></div></div></div>
</div>
<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Reference, product or supplier"></div>
  <div class="col-auto"><select class="form-select" name="status"><option value="">All statuses</option><option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option>
    <?php foreach ($states as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(role_label($s)) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th>Reference</th><th>Supplier</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Amount</th><th>Reason</th><th>Status</th><th style="min-width:300px">Decision</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
    <tr><td class="small"><?= e($r['request_ref']) ?><br><span class="text-muted"><?= e(fmt_dt($r['created_at'], 'M j, Y')) ?></span></td><td><?= e($r['business_name']) ?></td>
      <td><?= e($r['product_name']) ?></td><td class="text-end"><?= e(fmt_qty($r['quantity'])) ?></td><td class="text-end"><?= e(money($r['amount'])) ?></td>
      <td class="small"><?= e($r['reason']) ?></td>
      <td><?= status_badge($r['status']) ?><?= $r['reviewer'] ? '<div class="small text-muted">' . e($r['reviewer']) . '</div>' : '' ?><?= $r['response_note'] ? '<div class="small">' . e($r['response_note']) . '</div>' : '' ?></td>
      <td>
      <?php if (in_array($r['status'], ['received', 'under_review', 'approved'], true) && can('refunds.manage')): ?>
        <form method="post" action="action.php" class="d-flex flex-wrap gap-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $rid ?>">
          <input class="form-control form-control-sm" name="note" maxlength="255" placeholder="Note to Inventory (required to reject)" style="min-width:160px">
          <?php if ($r['status'] === 'received'): ?><button class="btn btn-sm btn-outline-secondary" name="decision" value="under_review">Start review</button><?php endif; ?>
          <?php if (can('refunds.decide') && $r['status'] !== 'approved'): ?>
            <button class="btn btn-sm btn-success" name="decision" value="approved" data-confirm="Approve this refund claim of <?= e(money($r['amount'])) ?>?">Approve</button>
            <button class="btn btn-sm btn-outline-danger" name="decision" value="rejected" data-confirm="Reject this refund claim?">Reject</button>
          <?php endif; ?>
          <?php if (can('refunds.decide') && $r['status'] === 'approved'): ?>
            <button class="btn btn-sm btn-success" name="decision" value="refunded" data-confirm="Mark this refund as paid / credited?">Mark refunded</button>
          <?php endif; ?>
        </form>
      <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted p-3">No refund requests have been received.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
