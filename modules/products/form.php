<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/SupplierApiClient.php';
require_permission('products.manage');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$prod = null;
if ($id > 0) {
    $prod = db_row('SELECT * FROM products WHERE id = ?', [$id]);
    if (!$prod) {
        abort_page(404, 'Product not found.');
    }
    if ($prod['status'] === 'archived') {
        flash('warning', 'Archived products cannot be edited. Restore the product first.');
        redirect('modules/products/view.php?id=' . $id);
    }
}
$keys = ['name', 'description', 'category_id', 'unit', 'cost_price', 'selling_price', 'reorder_level', 'status'];
$f = [];
foreach ($keys as $k) {
    $f[$k] = (string)($prod[$k] ?? '');
}
$f['quantity'] = '0';
$f['external_supplier_id'] = (string)($prod['external_supplier_id'] ?? '');
$f['external_supplier_product_id'] = (string)($prod['external_supplier_product_id'] ?? '');
if (!$prod) {
    $f['status'] = 'active';
    $f['reorder_level'] = '0';
    $f['cost_price'] = '0';
    $f['selling_price'] = '0';
}
$errors = [];

if (is_post()) {
    csrf_verify();
    foreach ($keys as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    if (!$prod) {
        $f['quantity'] = trim((string)($_POST['quantity'] ?? '0'));
    }
    $clear = isset($_POST['clear_supplier']);
    $f['external_supplier_id'] = $clear ? '' : trim((string)($_POST['external_supplier_id'] ?? ''));
    $f['external_supplier_product_id'] = $clear ? '' : trim((string)($_POST['external_supplier_product_id'] ?? ''));

    if ($f['name'] === '' || mb_strlen($f['name']) > 150) {
        $errors['name'] = 'Product name is required (max 150 characters).';
    }
    if (mb_strlen($f['description']) > 2000) {
        $errors['description'] = 'Description is too long (max 2000 characters).';
    }
    $category = db_row('SELECT id, name, is_archived FROM categories WHERE id = ?', [(int)$f['category_id']]);
    if (!$category || ($category['is_archived'] && (!$prod || (int)$prod['category_id'] !== (int)$category['id']))) {
        $errors['category_id'] = 'Choose an existing, non-archived category.';
    }
    if ($f['unit'] === '' || mb_strlen($f['unit']) > 20) {
        $errors['unit'] = 'Unit is required (max 20 characters).';
    }
    $cost = parse_decimal($f['cost_price']);
    $sell = parse_decimal($f['selling_price']);
    $reorder = parse_decimal($f['reorder_level']);
    $qty = $prod ? null : parse_decimal($f['quantity']);
    if ($cost === null) { $errors['cost_price'] = 'Enter a valid cost price (0 or more).'; }
    if ($sell === null) { $errors['selling_price'] = 'Enter a valid selling price (0 or more).'; }
    if ($reorder === null) { $errors['reorder_level'] = 'Enter a valid reorder level (0 or more).'; }
    if (!$prod && $qty === null) { $errors['quantity'] = 'Enter a valid opening quantity (0 or more).'; }
    if (!in_array($f['status'], ['active', 'inactive'], true)) {
        $errors['status'] = 'Choose Active or Inactive.';
    }

    // Supplier reference (integration IDs only). A changed reference is verified through the API.
    $extSup = $f['external_supplier_id'] === '' ? null : (ctype_digit($f['external_supplier_id']) ? (int)$f['external_supplier_id'] : -1);
    $extSp  = $f['external_supplier_product_id'] === '' ? null : (ctype_digit($f['external_supplier_product_id']) ? (int)$f['external_supplier_product_id'] : -1);
    $extSku = $prod['external_supplier_sku'] ?? null;
    if ($extSup === -1 || $extSp === -1) {
        $errors['external_supplier_id'] = 'Invalid supplier reference.';
    } elseif ($extSup === null && $extSp !== null) {
        $errors['external_supplier_id'] = 'Select the supplier before selecting a supplier product.';
    } elseif ($extSup === null) {
        $extSku = null;
    } else {
        $oldSup = ($prod['external_supplier_id'] ?? null) !== null ? (int)$prod['external_supplier_id'] : null;
        $oldSp  = ($prod['external_supplier_product_id'] ?? null) !== null ? (int)$prod['external_supplier_product_id'] : null;
        if ($extSup !== $oldSup || $extSp !== $oldSp) {
            $client = SupplierApiClient::load();
            if (!$client) {
                $errors['external_supplier_id'] = 'The Supplier API is not configured, so a new supplier reference cannot be verified. Save without changing the reference, or configure the API.';
            } else {
                $r = $client->request('GET', 'suppliers/' . $extSup, null, null, false);
                if (!$r['ok']) {
                    $errors['external_supplier_id'] = 'Cannot verify the supplier right now: ' . $r['error'];
                } elseif (($r['data']['data']['status'] ?? '') === 'archived') {
                    $errors['external_supplier_id'] = 'That supplier is archived.';
                } elseif ($extSp !== null) {
                    $r2 = $client->request('GET', 'supplier-products/' . $extSp, null, null, false);
                    if (!$r2['ok']) {
                        $errors['external_supplier_product_id'] = 'Cannot verify the supplier product: ' . $r2['error'];
                    } elseif ((int)($r2['data']['data']['supplier_id'] ?? 0) !== $extSup) {
                        $errors['external_supplier_product_id'] = 'That supplier product does not belong to the selected supplier.';
                    } else {
                        $extSku = substr((string)($r2['data']['data']['supplier_sku'] ?? ''), 0, 60) ?: null;
                    }
                } else {
                    $extSku = null;
                }
            }
        }
    }

    if (!$errors) {
        $pdo = db();
        $uid = current_user()['id'];
        try {
            $pdo->beginTransaction();
            if ($prod) {
                $pdo->prepare('UPDATE products SET name=?, description=?, category_id=?, unit=?, cost_price=?, selling_price=?, reorder_level=?, status=?,
                               external_supplier_id=?, external_supplier_product_id=?, external_supplier_sku=? WHERE id=?')
                    ->execute([$f['name'], $f['description'] ?: null, (int)$f['category_id'], $f['unit'], $cost, $sell, $reorder, $f['status'], $extSup, $extSp, $extSku, $id]);
                $pdo->commit();
                $diff = changed_fields($prod, $f, ['name', 'category_id', 'unit', 'cost_price', 'selling_price', 'reorder_level', 'status', 'external_supplier_id', 'external_supplier_product_id']);
                audit('product_updated', 'product', $id, $diff);
                flash('success', 'Product updated.');
                redirect('modules/products/view.php?id=' . $id);
            }
            // Create: unique code such as S01 / F02 / AF01 (prefix from the category); the UNIQUE key + retry guards races.
            $prefix = product_category_prefix($category['name']);
            $newId = 0;
            $code = '';
            for ($try = 0; $try < 5 && !$newId; $try++) {
                $code = format_product_code($prefix, product_max_sequence($prefix) + 1 + $try);
                try {
                    $pdo->prepare('INSERT INTO products (product_code, name, description, category_id, unit, cost_price, selling_price, quantity, reorder_level, status,
                                   external_supplier_id, external_supplier_product_id, external_supplier_sku, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$code, $f['name'], $f['description'] ?: null, (int)$f['category_id'], $f['unit'], $cost, $sell, 0, $reorder, $f['status'], $extSup, $extSp, $extSku, $uid]);
                    $newId = (int)$pdo->lastInsertId();
                } catch (PDOException $dup) {
                    if (!is_duplicate_key($dup)) {
                        throw $dup;
                    }
                }
            }
            if (!$newId) {
                throw new RuntimeException('Could not allocate a unique product code.');
            }
            if ((float)$qty > 0) {
                stock_apply($pdo, $newId, 'stock_in', (float)$qty, 'Opening balance', 'manual', null, (int)$uid);
            }
            $pdo->commit();
            audit('product_created', 'product', $newId, $code . ' ' . $f['name'] . ((float)$qty > 0 ? ' opening qty ' . $qty : ''));
            flash('success', 'Product created with code ' . $code . '.');
            redirect('modules/products/view.php?id=' . $newId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('product save failed: ' . $e->getMessage());
            $errors['_form'] = 'The product could not be saved. Details were written to the log.';
        }
    }
}

$cats = db_all('SELECT id, name, is_archived FROM categories WHERE is_archived = 0 OR id = ? ORDER BY name', [(int)($prod['category_id'] ?? 0)]);
$catOpts = [];
foreach ($cats as $c) {
    $catOpts[$c['id']] = $c['name'] . ($c['is_archived'] ? ' (archived)' : '');
}
$pageTitle = $prod ? 'Edit product' : 'Add product';
$activeMenu = 'products';
$pageScripts = '<script>(function(){'
  . 'var sSel=document.getElementById("supplier_pick"),pSel=document.getElementById("supplier_product_pick"),msg=document.getElementById("lookup_msg"),'
  . 'hS=document.getElementById("external_supplier_id"),hP=document.getElementById("external_supplier_product_id"),clr=document.getElementById("clear_supplier");'
  . 'if(!sSel)return;'
  . 'function say(t){msg.textContent=t;msg.classList.toggle("d-none",!t);}'
  . 'function get(p){return fetch("supplier_lookup.php?"+new URLSearchParams(p),{credentials:"same-origin"}).then(function(r){return r.json();});}'
  . 'function fill(sel,rows,label,cur){sel.innerHTML="<option value=\"\">— none —</option>";rows.forEach(function(r){var o=document.createElement("option");o.value=r.id;o.textContent=label(r);if(String(r.id)===String(cur))o.selected=true;sel.appendChild(o);});}'
  . 'function loadProducts(){pSel.disabled=true;pSel.innerHTML="<option value=\"\">— none —</option>";if(!hS.value)return Promise.resolve();'
  . 'return get({type:"products",supplier_id:hS.value}).then(function(res){if(!res.ok){say(res.error);return;}fill(pSel,res.data,function(r){return r.supplier_sku+" — "+r.product_name+" ("+r.unit+")";},hP.value);pSel.disabled=false;});}'
  . 'get({type:"suppliers"}).then(function(res){if(!res.ok){say(res.error+" You can still save the product; the existing supplier reference is kept.");sSel.disabled=true;pSel.disabled=true;return;}'
  . 'fill(sSel,res.data,function(r){return r.supplier_code+" — "+r.business_name;},hS.value);return loadProducts();}).catch(function(){say("Supplier lookup failed.");sSel.disabled=true;});'
  . 'sSel.addEventListener("change",function(){hS.value=sSel.value;hP.value="";clr.checked=false;loadProducts();});'
  . 'pSel.addEventListener("change",function(){hP.value=pSel.value;});'
  . '})();</script>';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<div class="card" style="max-width:960px"><div class="card-body">
<?php if ($prod): ?><p class="text-muted">Product code <strong><?= e($prod['product_code']) ?></strong> &middot; On hand <strong><?= e(fmt_qty($prod['quantity'])) ?> <?= e($prod['unit']) ?></strong>
  <span class="small">(quantity changes only through stock movements, receipts and sales)</span></p><?php endif; ?>
<form method="post" class="row g-3 needs-validation" novalidate>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <?= form_input('name', 'Product name', $f, $errors, ['required' => true, 'maxlength' => 150]) ?>
  <?= form_select('category_id', 'Category', $catOpts, $f['category_id'], $errors, ['required' => true, 'blank' => 'Select a category…']) ?>
  <?= form_input('unit', 'Unit', $f, $errors, ['required' => true, 'maxlength' => 20, 'list' => 'units', 'col' => 'col-md-3', 'placeholder' => 'bag, sack, pc…']) ?>
  <datalist id="units"><?php foreach (['bag', 'sack', 'kg', 'liter', 'bottle', 'pc', 'unit', 'box'] as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
  <?= form_input('cost_price', 'Cost price (₱)', $f, $errors, ['required' => true, 'inputmode' => 'decimal', 'col' => 'col-md-3']) ?>
  <?= form_input('selling_price', 'Selling price (₱)', $f, $errors, ['required' => true, 'inputmode' => 'decimal', 'col' => 'col-md-3']) ?>
  <?= form_input('reorder_level', 'Reorder level', $f, $errors, ['required' => true, 'inputmode' => 'decimal', 'col' => 'col-md-3']) ?>
  <?php if (!$prod): ?><?= form_input('quantity', 'Opening quantity', $f, $errors, ['required' => true, 'inputmode' => 'decimal', 'col' => 'col-md-3', 'help' => 'Recorded as an opening-balance stock movement.']) ?><?php endif; ?>
  <?= form_select('status', 'Status', ['active' => 'Active', 'inactive' => 'Inactive'], $f['status'], $errors, ['required' => true, 'col' => 'col-md-3']) ?>
  <?= form_input('description', 'Description', $f, $errors, ['type' => 'textarea', 'col' => 'col-12', 'maxlength' => 2000]) ?>

  <div class="col-12"><hr><h6><i class="bi bi-building"></i> Supplier reference <span class="text-muted small">(optional; retrieved from the Supplier Management System)</span></h6>
    <div id="lookup_msg" class="alert alert-warning py-2 d-none"></div>
    <input type="hidden" name="external_supplier_id" id="external_supplier_id" value="<?= e($f['external_supplier_id']) ?>">
    <input type="hidden" name="external_supplier_product_id" id="external_supplier_product_id" value="<?= e($f['external_supplier_product_id']) ?>">
    <?php if ($prod && $prod['external_supplier_id']): ?>
      <p class="small mb-2">Current reference: supplier ID <strong><?= (int)$prod['external_supplier_id'] ?></strong><?= $prod['external_supplier_product_id'] ? ', supplier product ID <strong>' . (int)$prod['external_supplier_product_id'] . '</strong>' : '' ?><?= $prod['external_supplier_sku'] ? ' (SKU ' . e($prod['external_supplier_sku']) . ')' : '' ?></p>
    <?php endif; ?>
    <div class="row g-2">
      <div class="col-md-5"><label class="form-label">Supplier</label><select class="form-select<?= inv($errors, 'external_supplier_id') ?>" id="supplier_pick"><option value="">Loading suppliers…</option></select><?= fb($errors, 'external_supplier_id') ?></div>
      <div class="col-md-5"><label class="form-label">Supplier product</label><select class="form-select<?= inv($errors, 'external_supplier_product_id') ?>" id="supplier_product_pick" disabled><option value="">— none —</option></select><?= fb($errors, 'external_supplier_product_id') ?></div>
      <div class="col-md-2 pt-md-4"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="clear_supplier" id="clear_supplier" value="1"><label class="form-check-label" for="clear_supplier">Remove link</label></div></div>
    </div>
  </div>
  <div class="col-12 d-flex gap-2"><button class="btn btn-success" type="submit"><i class="bi bi-check-lg"></i> Save product</button>
    <a class="btn btn-light" href="<?= $prod ? 'view.php?id=' . $id : 'index.php' ?>">Cancel</a></div>
</form></div></div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
