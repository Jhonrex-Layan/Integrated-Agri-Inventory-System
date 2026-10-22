<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('products.view');

$q = trim((string)($_GET['q'] ?? ''));
$cat = (int)($_GET['category_id'] ?? 0);
$status = (string)($_GET['status'] ?? '');
$stock = (string)($_GET['stock'] ?? '');
$where = [];
$p = [];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.product_code LIKE ? OR p.description LIKE ? OR p.external_supplier_sku LIKE ?)';
    $l = like_param($q);
    array_push($p, $l, $l, $l, $l);
}
if ($cat > 0) {
    $where[] = 'p.category_id = ?';
    $p[] = $cat;
}
if (in_array($status, ['active', 'inactive', 'archived'], true)) {
    $where[] = 'p.status = ?';
    $p[] = $status;
} elseif ($status !== 'all') {
    $where[] = "p.status <> 'archived'";
}
if ($stock === 'low') {
    $where[] = 'p.quantity <= p.reorder_level';
} elseif ($stock === 'out') {
    $where[] = 'p.quantity = 0';
}
$W = $where ? implode(' AND ', $where) : '1=1';
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM products p WHERE $W", $p));
$rows = db_all("SELECT p.*, c.name AS category FROM products p JOIN categories c ON c.id = p.category_id
                 WHERE $W ORDER BY p.name LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$cats = db_all('SELECT id, name FROM categories WHERE is_archived = 0 ORDER BY name');

$pageTitle = 'Products';
$activeMenu = 'products';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <form class="row g-2" method="get">
    <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name, code, description"></div>
    <div class="col-auto"><select class="form-select" name="category_id"><option value="">All categories</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><select class="form-select" name="status">
      <option value="">Active &amp; inactive</option>
      <?php foreach (['active' => 'Active only', 'inactive' => 'Inactive only', 'archived' => 'Archived only', 'all' => 'All'] as $v => $l): ?>
        <option value="<?= e($v) ?>" <?= $status === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><select class="form-select" name="stock"><option value="">Any stock level</option>
      <option value="low" <?= $stock === 'low' ? 'selected' : '' ?>>Low stock</option><option value="out" <?= $stock === 'out' ? 'selected' : '' ?>>Out of stock</option></select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filter</button></div>
  </form>
  <?php if (can('products.manage')): ?><a class="btn btn-success" href="form.php"><i class="bi bi-plus-lg"></i> Add product</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Code</th><th>Product</th><th>Category</th><th>Unit</th><th class="text-end">Cost</th><th class="text-end">Price</th><th class="text-end">On hand</th><th class="text-end">Reorder</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $pid = (int)$r['id']; $low = (float)$r['quantity'] <= (float)$r['reorder_level'] && $r['status'] === 'active'; ?>
    <tr><td class="text-nowrap"><?= e($r['product_code']) ?></td>
      <td><a href="view.php?id=<?= $pid ?>"><?= e($r['name']) ?></a><?= $r['external_supplier_id'] ? ' <i class="bi bi-link-45deg text-success" title="Linked to a supplier"></i>' : '' ?></td>
      <td><?= e($r['category']) ?></td><td><?= e($r['unit']) ?></td>
      <td class="text-end"><?= e(money($r['cost_price'])) ?></td><td class="text-end"><?= e(money($r['selling_price'])) ?></td>
      <td class="text-end <?= $low ? 'text-danger fw-bold' : '' ?>"><?= e(fmt_qty($r['quantity'])) ?><?= $low ? ' <i class="bi bi-exclamation-triangle-fill" title="At or below reorder level"></i>' : '' ?></td>
      <td class="text-end"><?= e(fmt_qty($r['reorder_level'])) ?></td><td><?= status_badge($r['status']) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary" href="view.php?id=<?= $pid ?>" title="View"><i class="bi bi-eye"></i></a>
        <?php if (can('products.manage')): ?>
          <?php if ($r['status'] !== 'archived'): ?>
            <a class="btn btn-sm btn-outline-success" href="form.php?id=<?= $pid ?>" title="Edit"><i class="bi bi-pencil"></i></a>
            <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="archive">
              <button class="btn btn-sm btn-outline-danger" data-confirm="Archive <?= e($r['name']) ?>? Its history is kept." title="Archive"><i class="bi bi-archive"></i></button></form>
          <?php else: ?>
            <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="restore">
              <button class="btn btn-sm btn-outline-success" data-confirm="Restore this product as inactive?"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="text-muted p-3">No products match. <?= can('products.manage') ? 'Use "Add product" to create one.' : '' ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
