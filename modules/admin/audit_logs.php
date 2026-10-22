<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('audit.view');

$q      = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$entity = (string)($_GET['entity'] ?? '');
$from   = (string)($_GET['from'] ?? '');
$to     = (string)($_GET['to'] ?? '');
$where = ['1=1'];
$p = [];
if ($q !== '') {
    $where[] = '(a.action LIKE ? OR a.details LIKE ?)';
    $l = like_param($q);
    array_push($p, $l, $l);
}
if ($userId > 0) {
    $where[] = 'a.user_id = ?';
    $p[] = $userId;
}
if ($entity !== '') {
    $where[] = 'a.entity_type = ?';
    $p[] = $entity;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'a.created_at >= ?';
    $p[] = $from . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'a.created_at < ?';
    $p[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
}
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM audit_logs a WHERE $W", $p), 25);
$rows = db_all("SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $W ORDER BY a.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$users = db_all('SELECT id, username FROM users ORDER BY username');
$entities = array_column(db_all('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type'), 'entity_type');

$pageTitle = 'Audit logs';
$activeMenu = 'audit';
require APP_ROOT . '/includes/layout_header.php';
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-md-3"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Action or details"></div>
  <div class="col-md-2"><select class="form-select" name="user_id"><option value="">All users</option>
    <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select class="form-select" name="entity"><option value="">All records</option>
    <?php foreach ($entities as $en): ?><option value="<?= e($en) ?>" <?= $entity === $en ? 'selected' : '' ?>><?= e(role_label($en)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-md-2"><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-1"><button class="btn btn-outline-secondary w-100"><i class="bi bi-funnel"></i></button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="text-nowrap"><?= e(fmt_dt($r['created_at'], 'M j, Y g:i:s A')) ?></td><td><?= e($r['username'] ?? 'System / API') ?></td>
      <td><?= e($r['action']) ?></td><td><?= e(role_label($r['entity_type'])) ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td>
      <td class="small"><?= e($r['details'] ?? '') ?></td><td class="small text-muted"><?= e($r['ip_address'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-muted p-3">No audit entries match.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
