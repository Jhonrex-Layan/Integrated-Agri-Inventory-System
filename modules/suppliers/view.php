<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/SupplierApiClient.php';
require_permission('suppliers.view');

$id = (int)($_GET['id'] ?? 0);
$supplier = null;
$products = [];
$error = null;
$client = SupplierApiClient::load();
if (!$client) {
    $error = 'The Supplier API is not configured.';
} else {
    $r = $client->request('GET', 'suppliers/' . $id, null, null, false);
    if ($r['ok']) {
        $supplier = $r['data']['data'] ?? null;
        $r2 = $client->request('GET', 'suppliers/' . $id . '/products', null, null, false);
        if ($r2['ok']) {
            $products = $r2['data']['data'] ?? [];
        }
    } else {
        $error = $r['status'] === 404 ? 'That supplier does not exist in the Supplier Management System.' : $r['error'];
    }
}
$local = db_all("SELECT id, product_code, name, quantity, unit, status, external_supplier_sku FROM products WHERE external_supplier_id = ? AND status <> 'archived' ORDER BY name", [$id]);

$pageTitle = $supplier['business_name'] ?? 'Supplier';
$activeMenu = 'suppliers';
require APP_ROOT . '/includes/layout_header.php';
?>
<a class="btn btn-light mb-3" href="index.php"><i class="bi bi-arrow-left"></i> All suppliers</a>
<?php if ($error): ?><div class="alert alert-warning"><i class="bi bi-plug"></i> Supplier details unavailable: <?= e($error) ?></div><?php endif; ?>
<?php if ($supplier): ?>
<div class="row g-3 mb-3">
  <div class="col-lg-5"><div class="card h-100"><div class="card-header">Supplier <span class="float-end"><?= status_badge((string)($supplier['status'] ?? 'active')) ?></span></div>
    <div class="card-body"><dl class="row mb-0">
      <dt class="col-sm-4">Code / ID</dt><dd class="col-sm-8"><?= e($supplier['supplier_code'] ?? '') ?> <span class="text-muted">(ID <?= $id ?>)</span></dd>
      <dt class="col-sm-4">Contact</dt><dd class="col-sm-8"><?= e($supplier['contact_person'] ?? '—') ?></dd>
      <dt class="col-sm-4">Telephone</dt><dd class="col-sm-8"><?= e($supplier['telephone'] ?? '—') ?></dd>
      <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($supplier['email'] ?? '—') ?></dd>
      <dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?= e($supplier['address'] ?? '—') ?></dd>
    </dl></div></div></div>
  <div class="col-lg-7"><div class="card h-100"><div class="card-header">Approved supplier catalog</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>SKU</th><th>Product</th><th>Unit</th><th class="text-end">Quoted price</th><th>Availability</th></tr></thead><tbody>
      <?php foreach ($products as $p): ?>
        <tr><td><?= e($p['supplier_sku'] ?? '') ?></td><td><?= e($p['product_name'] ?? '') ?></td><td><?= e($p['unit'] ?? '') ?></td>
          <td class="text-end"><?= e(money($p['current_price'] ?? 0)) ?></td><td><?= status_badge((string)($p['availability'] ?? 'available')) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$products): ?><tr><td colspan="5" class="text-muted p-3">No approved products published by this supplier.</td></tr><?php endif; ?>
      </tbody></table></div></div></div>
</div>
<?php endif; ?>
<div class="card"><div class="card-header">Local products linked to this supplier</div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Code</th><th>Product</th><th>Supplier SKU</th><th class="text-end">On hand</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($local as $l): ?>
      <tr><td><?= e($l['product_code']) ?></td><td><a href="<?= e(url('modules/products/view.php?id=' . (int)$l['id'])) ?>"><?= e($l['name']) ?></a></td>
        <td><?= e($l['external_supplier_sku'] ?? '—') ?></td><td class="text-end"><?= e(fmt_qty($l['quantity'])) ?> <?= e($l['unit']) ?></td><td><?= status_badge($l['status']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$local): ?><tr><td colspan="5" class="text-muted p-3">No local products reference this supplier.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
