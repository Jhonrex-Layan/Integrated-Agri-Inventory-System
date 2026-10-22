<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('catalog.view');

$q = trim((string)($_GET['q'] ?? ''));
$sid = (int)($_GET['supplier_id'] ?? 0);
$avail = (string)($_GET['availability'] ?? '');
$appr = (string)($_GET['approval'] ?? '');
$showArchived = ($_GET['archived'] ?? '') === '1';
$where = [$showArchived ? 'sp.is_archived = 1' : 'sp.is_archived = 0'];
$p = [];
if ($q !== '') {
    $where[] = '(sp.product_name LIKE ? OR sp.supplier_sku LIKE ? OR s.business_name LIKE ?)';
    $l = like_param($q);
    array_push($p, $l, $l, $l);
}
if ($sid > 0) {
    $where[] = 'sp.supplier_id = ?';
    $p[] = $sid;
}
if (in_array($avail, ['available', 'limited', 'unavailable'], true)) {
    $where[] = 'sp.availability = ?';
    $p[] = $avail;
}
if ($appr === 'approved') {
    $where[] = 'sp.is_approved = 1';
} elseif ($appr === 'pending') {
    $where[] = 'sp.is_approved = 0';
}
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id WHERE $W", $p));
$rows = db_all("SELECT sp.*, s.business_name, s.supplier_code, s.status AS supplier_status
                  FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id
                 WHERE $W ORDER BY s.business_name, sp.product_name LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$suppliers = db_all("SELECT id, business_name FROM suppliers WHERE status <> 'archived' ORDER BY business_name");

$pageTitle = 'Supplier product catalog';
$activeMenu = 'catalog';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <form class="row g-2" method="get">
    <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search product, SKU, supplier"></div>
    <div class="col-auto"><select class="form-select" name="supplier_id"><option value="">All suppliers</option>
      <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $sid === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['business_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><select class="form-select" name="availability"><option value="">Any availability</option>
      <?php foreach (['available', 'limited', 'unavailable'] as $a): ?><option value="<?= $a ?>" <?= $avail === $a ? 'selected' : '' ?>><?= e(role_label($a)) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><select class="form-select" name="approval"><option value="">Any approval</option>
      <option value="approved" <?= $appr === 'approved' ? 'selected' : '' ?>>Approved</option><option value="pending" <?= $appr === 'pending' ? 'selected' : '' ?>>Awaiting approval</option></select></div>
    <div class="col-auto form-check pt-2"><input class="form-check-input" type="checkbox" name="archived" value="1" id="arch" <?= $showArchived ? 'checked' : '' ?>><label class="form-check-label" for="arch">Archived</label></div>
    <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filter</button></div>
  </form>
  <?php if (can('catalog.manage')): ?><a class="btn btn-success" href="form.php"><i class="bi bi-plus-lg"></i> Add product</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Supplier</th><th>SKU</th><th>Product</th><th>Unit</th><th class="text-end">Price</th><th class="text-end">MOQ</th><th>Availability</th><th>Published</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $pid = (int)$r['id']; ?>
    <tr><td><a href="<?= e(url('modules/suppliers/view.php?id=' . (int)$r['supplier_id'])) ?>"><?= e($r['business_name']) ?></a></td>
      <td><?= e($r['supplier_sku']) ?></td><td><?= e($r['product_name']) ?></td><td><?= e($r['unit']) ?></td>
      <td class="text-end"><?= e(money($r['current_price'])) ?></td><td class="text-end"><?= e(fmt_qty($r['min_order_qty'])) ?></td>
      <td><?= status_badge($r['availability']) ?></td>
      <td><?= $r['is_approved'] ? '<span class="badge text-bg-success">Approved</span>' : '<span class="badge text-bg-warning">Awaiting approval</span>' ?></td>
      <td class="text-end text-nowrap">
        <?php if ($r['is_archived']): if (can('catalog.manage')): ?>
          <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="restore">
            <button class="btn btn-sm btn-outline-success" data-confirm="Restore this product to the catalog? It will need approval again."><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form>
        <?php endif; else: ?>
          <?php if (can('catalog.approve')): ?>
          <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $pid ?>">
            <?php if ($r['is_approved']): ?><input type="hidden" name="action" value="unapprove">
              <button class="btn btn-sm btn-outline-warning" data-confirm="Withdraw approval? The product will no longer be published to the Inventory system." title="Withdraw approval"><i class="bi bi-x-circle"></i></button>
            <?php else: ?><input type="hidden" name="action" value="approve">
              <button class="btn btn-sm btn-outline-success" data-confirm="Approve and publish this product to the Inventory system?" title="Approve"><i class="bi bi-check-circle"></i></button><?php endif; ?></form>
          <?php endif; ?>
          <?php if (can('catalog.manage')): ?>
          <a class="btn btn-sm btn-outline-success" href="form.php?id=<?= $pid ?>" title="Edit / price history"><i class="bi bi-pencil"></i></a>
          <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="archive">
            <button class="btn btn-sm btn-outline-danger" data-confirm="Archive this catalog product? It will be removed from the published catalog." title="Archive"><i class="bi bi-archive"></i></button></form>
          <?php endif; ?>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="text-muted p-3">No catalog products match.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
