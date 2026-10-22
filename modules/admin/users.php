<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('users.manage');

$q = trim((string)($_GET['q'] ?? ''));
$role = (string)($_GET['role'] ?? '');
$roles = enum_values('users', 'role');
$where = ['1=1'];
$p = [];
if ($q !== '') {
    $where[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
    $l = like_param($q);
    array_push($p, $l, $l, $l);
}
if (in_array($role, $roles, true)) {
    $where[] = 'role = ?';
    $p[] = $role;
}
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM users WHERE $W", $p));
$rows = db_all("SELECT * FROM users WHERE $W ORDER BY username LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);

$pageTitle = 'Users';
$activeMenu = 'users';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <form class="row g-2" method="get">
    <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name, username, email"></div>
    <div class="col-auto"><select class="form-select" name="role"><option value="">All roles</option>
      <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Search</button></div>
  </form>
  <a class="btn btn-success" href="user_form.php"><i class="bi bi-person-plus"></i> New user</a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $u): $locked = $u['locked_until'] && strtotime($u['locked_until']) > time(); ?>
    <tr><td><?= e($u['username']) ?></td><td><?= e($u['full_name']) ?></td><td><?= e($u['email']) ?></td>
      <td><?= e(role_label($u['role'])) ?></td>
      <td><?= $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?>
          <?= $locked ? '<span class="badge text-bg-warning">Locked</span>' : '' ?></td>
      <td class="text-nowrap"><?= e(fmt_dt($u['last_login_at'])) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-success" href="user_form.php?id=<?= (int)$u['id'] ?>"><i class="bi bi-pencil"></i> Edit</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted p-3">No users match your search.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
