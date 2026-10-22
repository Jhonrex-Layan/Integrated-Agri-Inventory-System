<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('catalog.manage');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$item = null;
if ($id > 0) {
    $item = db_row('SELECT sp.*, s.business_name, s.status AS supplier_status FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id WHERE sp.id = ?', [$id]);
    if (!$item) {
        abort_page(404, 'Catalog product not found.');
    }
    if ($item['is_archived']) {
        flash('warning', 'Archived products cannot be edited. Restore it from the catalog first.');
        redirect('modules/catalog/index.php?archived=1');
    }
}
$keys = ['supplier_id', 'supplier_sku', 'product_name', 'description', 'category_hint', 'unit', 'current_price', 'min_order_qty', 'availability'];
$f = [];
foreach ($keys as $k) {
    $f[$k] = (string)($item[$k] ?? '');
}
if (!$item) {
    $f['supplier_id'] = (string)(int)($_GET['supplier_id'] ?? 0) ?: '';
    $f['min_order_qty'] = '1';
    $f['availability'] = 'available';
}
$f['reason'] = '';
$errors = [];

if (is_post()) {
    csrf_verify();
    foreach ($keys as $k) {
        if ($k !== 'supplier_id' || !$item) {
            $f[$k] = trim((string)($_POST[$k] ?? ''));
        }
    }
    $f['reason'] = trim((string)($_POST['reason'] ?? ''));
    if ($item) {
        $f['supplier_id'] = (string)$item['supplier_id'];     // a catalog item never moves between suppliers
    }
    $sup = db_row('SELECT id, status FROM suppliers WHERE id = ?', [(int)$f['supplier_id']]);
    if (!$sup || $sup['status'] === 'archived') {
        $errors['supplier_id'] = 'Choose an existing, non-archived supplier.';
    }
    if ($f['supplier_sku'] === '' || mb_strlen($f['supplier_sku']) > 60) {
        $errors['supplier_sku'] = 'Supplier SKU is required (max 60 characters).';
    }
    if ($f['product_name'] === '' || mb_strlen($f['product_name']) > 150) {
        $errors['product_name'] = 'Product name is required (max 150 characters).';
    }
    if (mb_strlen($f['description']) > 2000) {
        $errors['description'] = 'Description is too long (max 2000 characters).';
    }
    if (mb_strlen($f['category_hint']) > 80) {
        $errors['category_hint'] = 'Maximum 80 characters.';
    }
    if ($f['unit'] === '' || mb_strlen($f['unit']) > 20) {
        $errors['unit'] = 'Unit is required (max 20 characters).';
    }
    $price = parse_decimal($f['current_price']);
    if ($price === null) {
        $errors['current_price'] = 'Enter a valid price (0 or more, up to 2 decimals).';
    }
    $moq = parse_decimal($f['min_order_qty']);
    if ($moq === null || (float)$moq <= 0) {
        $errors['min_order_qty'] = 'Minimum order quantity must be greater than 0.';
    }
    if (!in_array($f['availability'], ['available', 'limited', 'unavailable'], true)) {
        $errors['availability'] = 'Choose an availability.';
    }
    if (!$errors && (int)db_scalar('SELECT COUNT(*) FROM supplier_products WHERE supplier_id = ? AND supplier_sku = ? AND id <> ?', [(int)$f['supplier_id'], $f['supplier_sku'], $id]) > 0) {
        $errors['supplier_sku'] = 'This supplier already has a product with that SKU.';
    }
    if (!$errors) {
        $pdo = db();
        $now = date('Y-m-d H:i:s');
        $uid = current_user()['id'];
        try {
            $pdo->beginTransaction();
            if ($item) {
                $priceChanged = (float)$price !== (float)$item['current_price'];
                $publishedFieldsChanged = $priceChanged || $f['supplier_sku'] !== $item['supplier_sku'] || $f['product_name'] !== $item['product_name']
                    || $f['unit'] !== $item['unit'] || (float)$moq !== (float)$item['min_order_qty'];
                $resetApproval = $item['is_approved'] && $publishedFieldsChanged;
                $pdo->prepare('UPDATE supplier_products SET supplier_sku=?, product_name=?, description=?, category_hint=?, unit=?, current_price=?, min_order_qty=?, availability=?, is_approved = ? WHERE id=?')
                    ->execute([$f['supplier_sku'], $f['product_name'], $f['description'] ?: null, $f['category_hint'] ?: null, $f['unit'], $price, $moq, $f['availability'],
                               $resetApproval ? 0 : (int)$item['is_approved'], $id]);
                if ($priceChanged) {
                    $pdo->prepare('INSERT INTO supplier_price_history (supplier_product_id, price, effective_from, changed_by, reason) VALUES (?,?,?,?,?)')
                        ->execute([$id, $price, $now, $uid, $f['reason'] ?: 'Price updated']);
                }
                $pdo->commit();
                audit('catalog_updated', 'supplier_product', $id, changed_fields($item, array_merge($f, ['current_price' => $price]), ['supplier_sku', 'product_name', 'unit', 'current_price', 'min_order_qty', 'availability'])
                    . ($resetApproval ? '; approval reset' : ''));
                flash('success', 'Catalog product updated.' . ($resetApproval ? ' Changes to published fields require approval again before the Inventory system sees them.' : ''));
                redirect('modules/catalog/index.php');
            }
            $pdo->prepare('INSERT INTO supplier_products (supplier_id, supplier_sku, product_name, description, category_hint, unit, current_price, min_order_qty, availability, is_approved)
                           VALUES (?,?,?,?,?,?,?,?,?,0)')
                ->execute([(int)$f['supplier_id'], $f['supplier_sku'], $f['product_name'], $f['description'] ?: null, $f['category_hint'] ?: null, $f['unit'], $price, $moq, $f['availability']]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO supplier_price_history (supplier_product_id, price, effective_from, changed_by, reason) VALUES (?,?,?,?,?)')
                ->execute([$newId, $price, $now, $uid, 'Initial quote']);
            $pdo->commit();
            audit('catalog_created', 'supplier_product', $newId, $f['supplier_sku'] . ' ' . $f['product_name']);
            flash('success', 'Product added. It is published to the Inventory system once an approving officer approves it.');
            redirect('modules/catalog/index.php');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('catalog save failed: ' . $e->getMessage());
            $errors['_form'] = is_duplicate_key($e) ? 'This supplier already has a product with that SKU.' : 'The product could not be saved.';
        }
    }
}

$suppliers = db_all("SELECT id, business_name FROM suppliers WHERE status <> 'archived' ORDER BY business_name");
$supOpts = [];
foreach ($suppliers as $s) {
    $supOpts[$s['id']] = $s['business_name'];
}
$history = $item ? db_all('SELECT h.*, u.full_name FROM supplier_price_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.supplier_product_id = ? ORDER BY h.effective_from DESC, h.id DESC', [$id]) : [];

$pageTitle = $item ? 'Edit catalog product' : 'Add catalog product';
$activeMenu = 'catalog';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="card-body">
<?php if ($item && $item['is_approved']): ?><div class="alert alert-info py-2 small">This product is approved and published. Changing SKU, name, unit, price or minimum quantity will withdraw approval until it is approved again.</div><?php endif; ?>
<form method="post" class="row g-3 needs-validation" novalidate>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <?php if ($item): ?>
    <div class="col-md-6"><label class="form-label">Supplier</label><input class="form-control" value="<?= e($item['business_name']) ?>" disabled></div>
  <?php else: ?>
    <?= form_select('supplier_id', 'Supplier', $supOpts, $f['supplier_id'], $errors, ['required' => true, 'blank' => 'Select a supplier…']) ?>
  <?php endif; ?>
  <?= form_input('supplier_sku', 'Supplier SKU', $f, $errors, ['required' => true, 'maxlength' => 60]) ?>
  <?= form_input('product_name', 'Product name', $f, $errors, ['required' => true, 'maxlength' => 150]) ?>
  <?= form_input('category_hint', 'Category', $f, $errors, ['maxlength' => 80, 'list' => 'cats', 'help' => 'Seeds, Fertilizers, Pesticides, Animal Feeds, Tools, Farming Equipment…']) ?>
  <datalist id="cats"><?php foreach (['Seeds', 'Fertilizers', 'Pesticides', 'Animal Feeds', 'Tools', 'Farming Equipment'] as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <?= form_input('unit', 'Unit', $f, $errors, ['required' => true, 'maxlength' => 20, 'placeholder' => 'bag, sack, bottle, pc…', 'col' => 'col-md-4']) ?>
  <?= form_input('current_price', 'Quoted price (₱)', $f, $errors, ['required' => true, 'inputmode' => 'decimal', 'col' => 'col-md-4']) ?>
  <?= form_input('min_order_qty', 'Minimum order qty', $f, $errors, ['required' => true, 'inputmode' => 'decimal', 'col' => 'col-md-4']) ?>
  <?= form_select('availability', 'Availability', ['available' => 'Available', 'limited' => 'Limited', 'unavailable' => 'Unavailable'], $f['availability'], $errors, ['required' => true]) ?>
  <?php if ($item): ?><?= form_input('reason', 'Reason for price change (if any)', $f, $errors, ['maxlength' => 255]) ?><?php endif; ?>
  <?= form_input('description', 'Description', $f, $errors, ['type' => 'textarea', 'col' => 'col-12', 'maxlength' => 2000]) ?>
  <div class="col-12 d-flex gap-2"><button class="btn btn-success" type="submit"><i class="bi bi-check-lg"></i> Save product</button><a class="btn btn-light" href="index.php">Cancel</a></div>
</form></div></div></div>
<?php if ($item): ?>
<div class="col-lg-4"><div class="card"><div class="card-header">Price history</div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Effective</th><th class="text-end">Price</th></tr></thead><tbody>
    <?php foreach ($history as $h): ?>
      <tr><td><?= e(fmt_dt($h['effective_from'], 'M j, Y')) ?><br><small class="text-muted"><?= e($h['reason'] ?? '') ?><?= $h['full_name'] ? ' · ' . e($h['full_name']) : '' ?></small></td>
          <td class="text-end"><?= e(money($h['price'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div></div>
<?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
