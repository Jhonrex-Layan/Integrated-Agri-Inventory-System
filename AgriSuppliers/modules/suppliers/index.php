<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('suppliers.view');

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$where = ['1=1'];
$p = [];
if ($q !== '') {
    $where[] = '(s.business_name LIKE ? OR s.supplier_code LIKE ? OR s.contact_person LIKE ? OR s.email LIKE ? OR s.telephone LIKE ?)';
    $l = like_param($q);
    array_push($p, $l, $l, $l, $l, $l);
}
if (in_array($status, ['active', 'inactive', 'archived'], true)) {
    $where[] = 's.status = ?';
    $p[] = $status;
} elseif ($status !== 'all') {
    $where[] = "s.status <> 'archived'";
}
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM suppliers s WHERE $W", $p));
$rows = db_all(
    "SELECT s.*,
        (SELECT COUNT(*) FROM supplier_products sp WHERE sp.supplier_id = s.id AND sp.is_archived = 0) AS products,
        (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id
            AND po.status IN ('pending_approval','approved','sent','partially_received')) AS open_orders
       FROM suppliers s WHERE $W ORDER BY s.business_name LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);

$pageTitle = 'Suppliers';
$activeMenu = 'suppliers';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <form class="row g-2" method="get">
    <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name, code, contact, phone"></div>
    <div class="col-auto"><select class="form-select" name="status">
      <option value="">Active &amp; inactive</option>
      <?php foreach (['active' => 'Active only', 'inactive' => 'Inactive only', 'archived' => 'Archived only', 'all' => 'All'] as $v => $l): ?>
        <option value="<?= e($v) ?>" <?= $status === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Search</button></div>
  </form>
  <?php if (can('suppliers.manage')): ?><a class="btn btn-success" href="form.php"><i class="bi bi-plus-lg"></i> Register supplier</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Code</th><th>Business</th><th>Contact</th><th>Phone</th><th class="text-center">Products</th><th class="text-center">Open POs</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): ?>
    <tr><td class="text-nowrap"><?= e($s['supplier_code']) ?></td>
      <td><a href="view.php?id=<?= (int)$s['id'] ?>"><?= e($s['business_name']) ?></a></td>
      <td><?= e($s['contact_person']) ?><?= $s['email'] ? '<br><small class="text-muted">' . e($s['email']) . '</small>' : '' ?></td>
      <td class="text-nowrap"><?= e($s['telephone']) ?></td>
      <td class="text-center"><?= (int)$s['products'] ?></td><td class="text-center"><?= (int)$s['open_orders'] ?></td>
      <td><?= status_badge($s['status']) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary" href="view.php?id=<?= (int)$s['id'] ?>" title="View"><i class="bi bi-eye"></i></a>
        <?php if (can('suppliers.manage')): ?>
          <?php if ($s['status'] !== 'archived'): ?>
            <a class="btn btn-sm btn-outline-success" href="form.php?id=<?= (int)$s['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
            <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="archive">
              <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Archive <?= e($s['business_name']) ?>? It will no longer appear in active lists or be available for new purchase orders." title="Archive"><i class="bi bi-archive"></i></button></form>
          <?php else: ?>
            <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="restore">
              <button class="btn btn-sm btn-outline-success" type="submit" data-confirm="Restore <?= e($s['business_name']) ?> as an inactive supplier?"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted p-3">No suppliers match. <?= can('suppliers.manage') ? 'Use "Register supplier" to add one.' : '' ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
