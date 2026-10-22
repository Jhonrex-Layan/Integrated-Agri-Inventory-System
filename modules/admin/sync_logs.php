<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('sync.view');

$status = (string)($_GET['status'] ?? '');
$where = ['1=1'];
$p = [];
if (in_array($status, ['success', 'failed', 'pending_retry', 'resolved'], true)) {
    $where[] = 'l.status = ?';
    $p[] = $status;
}
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM api_sync_logs l WHERE $W", $p), 25);
$rows = db_all("SELECT l.*, r.receipt_no FROM api_sync_logs l LEFT JOIN stock_receipts r ON r.id = l.receipt_id WHERE $W ORDER BY l.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$api = integration_status();

$pageTitle = 'Sync logs';
$activeMenu = 'sync';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="alert alert-<?= e($api['class'] === 'danger' ? 'danger' : ($api['class'] === 'success' ? 'success' : 'secondary')) ?> py-2"><strong><?= e($api['label']) ?></strong> — <?= e($api['detail']) ?></div>
<form class="row g-2 mb-3" method="get"><div class="col-auto"><select class="form-select" name="status"><option value="">All results</option>
  <?php foreach (['success', 'failed', 'pending_retry', 'resolved'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(role_label($s)) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Filter</button></div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>When</th><th>Direction</th><th>Request</th><th>HTTP</th><th>Result</th><th>Attempts</th><th>Receipt</th><th>Error</th></tr></thead><tbody>
  <?php foreach ($rows as $l): ?>
    <tr><td class="text-nowrap"><?= e(fmt_dt($l['created_at'], 'M j, g:i:s A')) ?></td><td><?= e($l['direction']) ?></td><td class="small"><?= e($l['method'] . ' ' . $l['endpoint']) ?></td>
      <td><?= $l['http_status'] === null ? '—' : (int)$l['http_status'] ?></td><td><?= status_badge($l['status'] === 'pending_retry' ? 'pending' : $l['status']) ?></td><td><?= (int)$l['attempts'] ?></td>
      <td><?= e($l['receipt_no'] ?? '') ?></td><td class="small"><?= e($l['error_message'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted p-3">No API activity recorded.</td></tr><?php endif; ?></tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
