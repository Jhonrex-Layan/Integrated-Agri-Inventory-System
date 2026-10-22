<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('suppliers.view');

$id = (int)($_GET['id'] ?? 0);
$s = db_row('SELECT s.*, u.full_name AS creator FROM suppliers s LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?', [$id]);
if (!$s) {
    abort_page(404, 'Supplier not found.');
}
$products = db_all('SELECT * FROM supplier_products WHERE supplier_id = ? AND is_archived = 0 ORDER BY product_name', [$id]);
$notes = db_all('SELECT n.*, u.full_name FROM supplier_notes n JOIN users u ON u.id = n.created_by WHERE n.supplier_id = ? ORDER BY n.id DESC LIMIT 30', [$id]);
$orders = db_all('SELECT id, po_number, status, order_date, total_amount FROM purchase_orders WHERE supplier_id = ? ORDER BY id DESC LIMIT 5', [$id]);
$poCount = (int)db_scalar('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?', [$id]);

$pageTitle = $s['business_name'];
$activeMenu = 'suppliers';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <a class="btn btn-light" href="index.php"><i class="bi bi-arrow-left"></i> All suppliers</a>
  <?php if (can('suppliers.manage') && $s['status'] !== 'archived'): ?>
    <a class="btn btn-success" href="form.php?id=<?= $id ?>"><i class="bi bi-pencil"></i> Edit supplier</a><?php endif; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Supplier details <span class="float-end"><?= status_badge($s['status']) ?></span></div>
    <div class="card-body"><dl class="row mb-0">
      <dt class="col-sm-4">Code / ID</dt><dd class="col-sm-8"><?= e($s['supplier_code']) ?> <span class="text-muted">(ID <?= (int)$s['id'] ?>)</span></dd>
      <dt class="col-sm-4">Contact</dt><dd class="col-sm-8"><?= e($s['contact_person']) ?></dd>
      <dt class="col-sm-4">Telephone</dt><dd class="col-sm-8"><?= e($s['telephone']) ?></dd>
      <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($s['email'] ?: '—') ?></dd>
      <dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?= e($s['address']) ?></dd>
      <dt class="col-sm-4">Registration</dt><dd class="col-sm-8"><?= e($s['registration_no'] ?: '—') ?></dd>
      <dt class="col-sm-4">Tax ID</dt><dd class="col-sm-8"><?= e($s['tax_id'] ?: '—') ?></dd>
      <dt class="col-sm-4">Registered</dt><dd class="col-sm-8"><?= e(fmt_dt($s['created_at'])) ?><?= $s['creator'] ? ' by ' . e($s['creator']) : '' ?></dd>
    </dl></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Recent purchase orders <span class="float-end text-muted small"><?= $poCount ?> total</span></div>
    <ul class="list-group list-group-flush">
      <?php foreach ($orders as $o): ?>
        <li class="list-group-item d-flex justify-content-between"><span><?= e($o['po_number']) ?> <small class="text-muted"><?= e(fmt_dt($o['order_date'], 'M j, Y')) ?></small></span>
          <span><?= e(money($o['total_amount'])) ?> <?= status_badge($o['status']) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$orders): ?><li class="list-group-item text-muted">No purchase orders for this supplier yet.</li><?php endif; ?>
    </ul></div></div>
</div>

<div class="card mb-3"><div class="card-header">Products offered
  <?php if (can('catalog.manage') && $s['status'] !== 'archived'): ?><a class="btn btn-sm btn-success float-end" href="<?= e(url('modules/catalog/form.php?supplier_id=' . $id)) ?>"><i class="bi bi-plus-lg"></i> Add product</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>SKU</th><th>Product</th><th>Unit</th><th class="text-end">Price</th><th class="text-end">MOQ</th><th>Availability</th><th>Published</th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
      <tr><td><?= e($p['supplier_sku']) ?></td><td><?= e($p['product_name']) ?></td><td><?= e($p['unit']) ?></td>
        <td class="text-end"><?= e(money($p['current_price'])) ?></td><td class="text-end"><?= e(fmt_qty($p['min_order_qty'])) ?></td>
        <td><?= status_badge($p['availability']) ?></td><td><?= $p['is_approved'] ? '<span class="badge text-bg-success">Approved</span>' : '<span class="badge text-bg-warning">Awaiting approval</span>' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$products): ?><tr><td colspan="7" class="text-muted p-3">This supplier has no catalog products yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<div class="card" id="notes"><div class="card-header">Contact history, feedback and notes</div>
  <div class="card-body">
    <?php if (can('feedback.record')): ?>
    <form method="post" action="note_add.php" class="row g-2 mb-3">
      <?= csrf_field() ?><input type="hidden" name="supplier_id" value="<?= $id ?>">
      <div class="col-md-2"><select class="form-select" name="note_type"><option value="contact">Contact</option><option value="feedback">Feedback</option><option value="general">General</option></select></div>
      <div class="col-md-2"><select class="form-select" name="rating"><option value="">No rating</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?></select></div>
      <div class="col-md-6"><input class="form-control" name="note" maxlength="2000" required placeholder="Write a note (ratings apply to feedback only)"></div>
      <div class="col-md-2"><button class="btn btn-success w-100" type="submit">Add note</button></div>
    </form>
    <?php endif; ?>
    <?php foreach ($notes as $n): ?>
      <div class="border-bottom py-2"><span class="badge text-bg-secondary"><?= e(role_label($n['note_type'])) ?></span>
        <?= $n['rating'] ? '<span class="badge text-bg-warning">' . (int)$n['rating'] . '/5</span>' : '' ?>
        <?= e($n['note']) ?><div class="small text-muted"><?= e($n['full_name']) ?> &middot; <?= e(fmt_dt($n['created_at'])) ?></div></div>
    <?php endforeach; ?>
    <?php if (!$notes): ?><p class="text-muted mb-0">No notes recorded yet.</p><?php endif; ?>
  </div></div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
