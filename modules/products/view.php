<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/SupplierApiClient.php';
require_permission('products.view');

$id = (int)($_GET['id'] ?? 0);
$p = db_row('SELECT p.*, c.name AS category, u.full_name AS creator FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?', [$id]);
if (!$p) {
    abort_page(404, 'Product not found.');
}
$moves = db_all('SELECT m.*, u.full_name FROM stock_movements m JOIN users u ON u.id = m.created_by WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 15', [$id]);

// Supplier details come live from the Supplier system; nothing is copied locally.
$supplier = null;
$supplierError = null;
if ($p['external_supplier_id']) {
    $client = SupplierApiClient::load();
    if (!$client) {
        $supplierError = 'The Supplier API is not configured.';
    } else {
        $r = $client->request('GET', 'suppliers/' . (int)$p['external_supplier_id'], null, null, false);
        if ($r['ok']) {
            $supplier = $r['data']['data'] ?? null;
        } else {
            $supplierError = $r['error'];
        }
    }
}
$low = $p['status'] === 'active' && (float)$p['quantity'] <= (float)$p['reorder_level'];

$pageTitle = $p['name'];
$activeMenu = 'products';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
  <a class="btn btn-light" href="index.php"><i class="bi bi-arrow-left"></i> All products</a>
  <?php if (can('products.manage') && $p['status'] !== 'archived'): ?><a class="btn btn-success" href="form.php?id=<?= $id ?>"><i class="bi bi-pencil"></i> Edit product</a><?php endif; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Product details <span class="float-end"><?= status_badge($p['status']) ?></span></div>
    <div class="card-body"><dl class="row mb-0">
      <dt class="col-sm-4">Code</dt><dd class="col-sm-8"><?= e($p['product_code']) ?></dd>
      <dt class="col-sm-4">Category</dt><dd class="col-sm-8"><?= e($p['category']) ?></dd>
      <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= e($p['description'] ?: '—') ?></dd>
      <dt class="col-sm-4">Unit</dt><dd class="col-sm-8"><?= e($p['unit']) ?></dd>
      <dt class="col-sm-4">Cost / Price</dt><dd class="col-sm-8"><?= e(money($p['cost_price'])) ?> / <?= e(money($p['selling_price'])) ?></dd>
      <dt class="col-sm-4">On hand</dt><dd class="col-sm-8 <?= $low ? 'text-danger fw-bold' : '' ?>"><?= e(fmt_qty($p['quantity'])) ?> <?= e($p['unit']) ?><?= $low ? ' — at or below reorder level' : '' ?></dd>
      <dt class="col-sm-4">Reorder level</dt><dd class="col-sm-8"><?= e(fmt_qty($p['reorder_level'])) ?></dd>
      <dt class="col-sm-4">Created</dt><dd class="col-sm-8"><?= e(fmt_dt($p['created_at'])) ?><?= $p['creator'] ? ' by ' . e($p['creator']) : '' ?></dd>
    </dl></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Supplier reference</div>
    <div class="card-body">
    <?php if (!$p['external_supplier_id']): ?>
      <p class="text-muted mb-0">No supplier is linked to this product.</p>
    <?php else: ?>
      <p class="small text-muted">Supplier ID <?= (int)$p['external_supplier_id'] ?><?= $p['external_supplier_product_id'] ? ' · supplier product ID ' . (int)$p['external_supplier_product_id'] : '' ?><?= $p['external_supplier_sku'] ? ' · SKU ' . e($p['external_supplier_sku']) : '' ?></p>
      <?php if ($supplier): ?>
        <dl class="row mb-0">
          <dt class="col-sm-4">Supplier</dt><dd class="col-sm-8"><?= e($supplier['business_name'] ?? '') ?> <small class="text-muted"><?= e($supplier['supplier_code'] ?? '') ?></small></dd>
          <dt class="col-sm-4">Contact</dt><dd class="col-sm-8"><?= e($supplier['contact_person'] ?? '—') ?></dd>
          <dt class="col-sm-4">Telephone</dt><dd class="col-sm-8"><?= e($supplier['telephone'] ?? '—') ?></dd>
          <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($supplier['email'] ?? '—') ?></dd>
        </dl>
      <?php else: ?>
        <div class="alert alert-warning mb-0"><i class="bi bi-plug"></i> Supplier details unavailable: <?= e($supplierError) ?></div>
      <?php endif; ?>
    <?php endif; ?>
    </div></div></div>
</div>
<div class="card"><div class="card-header">Recent stock movements</div>
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>When</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Balance</th><th>Reason</th><th>By</th></tr></thead><tbody>
    <?php foreach ($moves as $m): ?>
      <tr><td class="text-nowrap"><?= e(fmt_dt($m['created_at'])) ?></td><td><?= e(role_label($m['movement_type'])) ?></td>
        <td class="text-end"><?= e(fmt_qty($m['quantity'])) ?></td><td class="text-end"><?= e(fmt_qty($m['balance_after'])) ?></td>
        <td><?= e($m['reason'] ?? '') ?></td><td><?= e($m['full_name']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$moves): ?><tr><td colspan="6" class="text-muted p-3">No stock movements yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
